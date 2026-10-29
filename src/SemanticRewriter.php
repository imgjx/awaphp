<?php
/**
 * 语义等价变换层（SemanticRewriter / 主意 1）
 *
 * 在"编译为字节码之前"（token 级）对源码做**结构骨架**改写：不改写标识符、不改编码、
 * 不套外壳，而是把表达式 / 分支 / 循环的形态重写成等价但完全不同的样子。逆向者即便把
 * VM 字节码还原成 opcode 序列，也读不到接近原文的结构——必须先做符号级语义恢复。
 *
 * 设计约束（与"反外壳"原则一致）：
 *  - 只用**类型安全、恒等保真**的变换，绝不引入浮点 / 溢出歧义（故刻意排除
 *    `$a*2→$a<<1`、`$a+1→-~$a` 这类对 float/大整数不保真的"小聪明"改写）；
 *  - 每层变换后整体过 php -l，语法失败则回滚到原始 token 流；
 *  - 变换为单次收集 + 单次重建，不循环重扫，避免振荡与无限改写。
 *
 * 强度分级（semantic_strength）：
 *  1 = if/else 分支逆置交换 + 德摩根（!(A&&B) ↔ !A||!B）
 *  2 = 上述 + 三元式逆置（C?T:E ↔ !C?E:T）
 *  3 = 上述 + for→while（仅当循环体无 continue，等价保真）
 */

declare(strict_types=1);

final class SemanticRewriter
{
    private Salt $salt;
    private Logger $logger;

    public function __construct(Salt $salt, Logger $logger)
    {
        $this->salt = $salt;
        $this->logger = $logger;
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        $strength = max(1, min(3, (int) $cfg->get('semantic_strength')));
        if ((bool) $cfg->get('semantic') === false) {
            return $tokens;
        }

        $out = $tokens;
        // 依次施加各档变换（单次收集 + 单次重建，不重扫）
        $out = $this->applyIfElseSwap($out);
        if ($strength >= 1) {
            $out = $this->applyDeMorgan($out);
        }
        if ($strength >= 2) {
            $out = $this->applyTernaryReorder($out);
        }
        if ($strength >= 3) {
            $out = $this->applyForToWhile($out);
        }
        // 数字字面量同义展开（echo 5 → echo strlen("P=po1") 一类）：
        // 恒等替换，且刻意区分"常量表达式上下文"与"运行期上下文"两种形态。
        $out = $this->applyNumberObfuscation($out, $strength);

        // php -l 兜底：任何一次重建若破坏语法，整体回滚到原始 token 流
        if ($out !== $tokens && !$this->syntaxOk($out)) {
            $this->logger->debug('SemanticRewriter: 产物未通过 php -l，回滚到原始 token 流');
            return $tokens;
        }
        return $out;
    }

    // ------------------------------------------------------------------------
    // 变换 1：if/else 分支逆置 + 交换（if(C) T else E  →  if(!(C)) E else T）
    // 恒等保真：新条件 !(C) 为真 ⟺ 原 C 为假，故分支语义完全一致。
    // ------------------------------------------------------------------------
    /**
     * @param array<int,mixed> $tk
     * @return array<int,mixed>
     */
    private function applyIfElseSwap(array $tk): array
    {
        $matches = [];
        $depth = 0;
        $n = count($tk);
        for ($i = 0; $i < $n; $i++) {
            $t = $tk[$i];
            if (is_array($t) && $t[0] === T_IF) {
                $m = $this->parseIfElse($tk, $i);
                if ($m !== null) {
                    $matches[] = $m;
                    $i = $m['end']; // 跳过已处理区段，避免重叠
                    continue;
                }
            }
            $depth = $this->trackDepth($t, $depth);
        }
        return $this->rebuild($tk, $matches);
    }

    /**
     * @param array<int,mixed> $tk
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function parseIfElse(array $tk, int $i): ?array
    {
        $n = count($tk);
        $open = $this->sig($tk, $i + 1);
        if ($open < 0 || !$this->isChar($tk[$open], '(')) {
            return null;
        }
        $close = $this->matchBracket($tk, $open);
        if ($close < 0) {
            return null;
        }
        $condInner = array_slice($tk, $open + 1, $close - $open - 1);

        // THEN 主体
        $after = $this->sig($tk, $close + 1);
        if ($after < 0) {
            return null;
        }
        if ($this->isChar($tk[$after], '{')) {
            $thenEnd = $this->matchBracket($tk, $after);
            if ($thenEnd < 0) {
                return null;
            }
            $thenBody = array_slice($tk, $after, $thenEnd - $after + 1);
            $next = $thenEnd + 1;
        } else {
            // 单语句：到 depth 0 的 ';'
            $thenEnd = $this->stmtEnd($tk, $after);
            if ($thenEnd < 0) {
                return null;
            }
            $thenBody = array_slice($tk, $after, $thenEnd - $after + 1);
            $next = $thenEnd + 1;
        }

        // else 关键字（排除 elseif）
        $elseK = $this->sig($tk, $next);
        if ($elseK < 0 || !is_array($tk[$elseK]) || $tk[$elseK][0] !== T_ELSE) {
            return null;
        }
        $elseBodyStart = $this->sig($tk, $elseK + 1);
        if ($elseBodyStart < 0) {
            return null;
        }
        if ($this->isChar($tk[$elseBodyStart], '{')) {
            $elseEnd = $this->matchBracket($tk, $elseBodyStart);
            if ($elseEnd < 0) {
                return null;
            }
            $elseBody = array_slice($tk, $elseBodyStart, $elseEnd - $elseBodyStart + 1);
            $end = $elseEnd;
        } else {
            $elseEnd = $this->stmtEnd($tk, $elseBodyStart);
            if ($elseEnd < 0) {
                return null;
            }
            $elseBody = array_slice($tk, $elseBodyStart, $elseEnd - $elseBodyStart + 1);
            $end = $elseEnd;
        }

        // 重建：if ( !(condInner) ) ELSE_BODY else THEN_BODY
        $repl = [];
        $repl[] = $tk[$i];            // T_IF
        $repl[] = '(';
        $repl[] = '!';
        $repl[] = '(';
        foreach ($condInner as $c) {
            $repl[] = $c;
        }
        $repl[] = ')';
        $repl[] = ')';
        foreach ($elseBody as $b) {
            $repl[] = $b;
        }
        $repl[] = $tk[$elseK];        // T_ELSE
        foreach ($thenBody as $b) {
            $repl[] = $b;
        }

        return ['start' => $i, 'end' => $end, 'repl' => $repl];
    }

    // ------------------------------------------------------------------------
    // 变换 2：德摩根（!(A && B) ↔ !A || !B，!(A || B) ↔ !A && !B）
    // 类型安全：纯逻辑等价，不触碰操作数类型。
    // ------------------------------------------------------------------------
    /**
     * @param array<int,mixed> $tk
     * @return array<int,mixed>
     */
    private function applyDeMorgan(array $tk): array
    {
        $matches = [];
        $n = count($tk);
        for ($i = 0; $i < $n; $i++) {
            if (!$this->isChar($tk[$i], '!')) {
                continue;
            }
            $m = $this->parseDeMorgan($tk, $i);
            if ($m !== null) {
                $matches[] = $m;
                $i = $m['end'];
                continue;
            }
        }
        return $this->rebuild($tk, $matches);
    }

    /**
     * @param array<int,mixed> $tk
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function parseDeMorgan(array $tk, int $i): ?array
    {
        $open = $this->sig($tk, $i + 1);
        if ($open < 0 || !$this->isChar($tk[$open], '(')) {
            return null;
        }
        $close = $this->matchBracket($tk, $open);
        if ($close < 0) {
            return null;
        }
        // 在 ( ... ) 内部找顶层 && / ||
        $op = -1;
        $d = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            $t = $tk[$j];
            if (is_string($t)) {
                if ($this->isOpen($t)) {
                    $d++;
                } elseif ($this->isClose($t)) {
                    $d--;
                }
            } elseif (is_array($t) && $this->isOpenTok($t)) {
                $d++;
            }
            if ($d === 0 && is_array($t) && ($t[0] === T_BOOLEAN_AND || $t[0] === T_BOOLEAN_OR)) {
                $op = $j;
                break;
            }
        }
        if ($op < 0) {
            return null;
        }
        $a = array_slice($tk, $open + 1, $op - $open - 1);
        $b = array_slice($tk, $op + 1, $close - $op - 1);
        $newOp = $tk[$op][0] === T_BOOLEAN_AND ? T_BOOLEAN_OR : T_BOOLEAN_AND;

        $repl = [];
        $repl[] = '!';
        $repl[] = '(';
        foreach ($a as $x) {
            $repl[] = $x;
        }
        $repl[] = ')';
        $repl[] = [0 => $newOp, 1 => ($newOp === T_BOOLEAN_AND ? '&&' : '||'), 2 => $tk[$op][2] ?? 0];
        $repl[] = '!';
        $repl[] = '(';
        foreach ($b as $x) {
            $repl[] = $x;
        }
        $repl[] = ')';

        return ['start' => $i, 'end' => $close, 'repl' => $repl];
    }

    // ------------------------------------------------------------------------
    // 变换 3：三元式逆置（C ? T : E  ↔  !C ? E : T），类型安全。
    // ------------------------------------------------------------------------
    /**
     * @param array<int,mixed> $tk
     * @return array<int,mixed>
     */
    private function applyTernaryReorder(array $tk): array
    {
        $matches = [];
        $depth = 0;
        $n = count($tk);
        for ($i = 0; $i < $n; $i++) {
            $t = $tk[$i];
            if ($this->isChar($t, '?')) {
                $m = $this->parseTernary($tk, $i);
                if ($m !== null) {
                    $matches[] = $m;
                    $i = $m['end'];
                    continue;
                }
            }
            $depth = $this->trackDepth($t, $depth);
        }
        return $this->rebuild($tk, $matches);
    }

    /**
     * @param array<int,mixed> $tk
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function parseTernary(array $tk, int $i): ?array
    {
        $n = count($tk);
        // 条件 C 起点：向前找到 depth 0 的表达式起点
        $cStart = $this->exprStart($tk, $i - 1);
        if ($cStart < 0) {
            return null;
        }
        // 匹配冒号（与 ? 同深度的第一个 :），期间不得出现嵌套 ?（否则歧义，跳过）
        $colon = -1;
        $d = 0;
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tk[$j];
            if (is_string($t)) {
                if ($this->isOpen($t)) {
                    $d++;
                } elseif ($this->isClose($t)) {
                    $d--;
                } elseif ($t === '?') {
                    return null; // 嵌套三元，跳过
                } elseif ($t === ':' && $d === 0) {
                    $colon = $j;
                    break;
                }
            } elseif (is_array($t) && $this->isOpenTok($t)) {
                $d++;
            }
        }
        if ($colon < 0) {
            return null;
        }
        // E 终点：冒号之后第一个 depth 0 的 ; ) , ] }
        $eEnd = $this->exprEnd($tk, $colon + 1);
        if ($eEnd < 0) {
            return null;
        }

        $c = array_slice($tk, $cStart, $i - $cStart);
        $tPart = array_slice($tk, $i + 1, $colon - $i - 1);
        $ePart = array_slice($tk, $colon + 1, $eEnd - $colon);

        // 重建：!(C) ? E : T
        $repl = [];
        $repl[] = '!';
        $repl[] = '(';
        foreach ($c as $x) {
            $repl[] = $x;
        }
        $repl[] = ')';
        $repl[] = '?';
        foreach ($ePart as $x) {
            $repl[] = $x;
        }
        $repl[] = ':';
        foreach ($tPart as $x) {
            $repl[] = $x;
        }

        return ['start' => $cStart, 'end' => $eEnd, 'repl' => $repl];
    }

    // ------------------------------------------------------------------------
    // 变换 4：for → while（仅强度 3，且循环体无 continue，等价保真）
    //   for(A;B;C) body  ≡  A; while(B){ body; C; }
    // ------------------------------------------------------------------------
    /**
     * @param array<int,mixed> $tk
     * @return array<int,mixed>
     */
    private function applyForToWhile(array $tk): array
    {
        $matches = [];
        $depth = 0;
        $n = count($tk);
        for ($i = 0; $i < $n; $i++) {
            $t = $tk[$i];
            if (is_array($t) && $t[0] === T_FOR) {
                $m = $this->parseFor($tk, $i);
                if ($m !== null) {
                    $matches[] = $m;
                    $i = $m['end'];
                    continue;
                }
            }
            $depth = $this->trackDepth($t, $depth);
        }
        return $this->rebuild($tk, $matches);
    }

    /**
     * @param array<int,mixed> $tk
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function parseFor(array $tk, int $i): ?array
    {
        $n = count($tk);
        $open = $this->sig($tk, $i + 1);
        if ($open < 0 || !$this->isChar($tk[$open], '(')) {
            return null;
        }
        $close = $this->matchBracket($tk, $open);
        if ($close < 0) {
            return null;
        }
        // 在 for 圆括号内找两个顶层 ';'
        $semis = [];
        $d = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            $t = $tk[$j];
            if (is_string($t)) {
                if ($this->isOpen($t)) {
                    $d++;
                } elseif ($this->isClose($t)) {
                    $d--;
                } elseif ($t === ';' && $d === 0) {
                    $semis[] = $j;
                }
            } elseif (is_array($t) && $this->isOpenTok($t)) {
                $d++;
            }
        }
        if (count($semis) !== 2) {
            return null;
        }
        [$s1, $s2] = $semis;
        $a = array_slice($tk, $open + 1, $s1 - $open - 1);
        $b = array_slice($tk, $s1 + 1, $s2 - $s1 - 1);
        $c = array_slice($tk, $s2 + 1, $close - $s2 - 1);

        // 循环体
        $after = $this->sig($tk, $close + 1);
        if ($after < 0) {
            return null;
        }
        if ($this->isChar($tk[$after], '{')) {
            $bodyEnd = $this->matchBracket($tk, $after);
            if ($bodyEnd < 0) {
                return null;
            }
            $inner = array_slice($tk, $after + 1, $bodyEnd - $after - 1);
            $end = $bodyEnd;
        } else {
            $bodyEnd = $this->stmtEnd($tk, $after);
            if ($bodyEnd < 0) {
                return null;
            }
            $inner = array_slice($tk, $after, $bodyEnd - $after + 1);
            $end = $bodyEnd;
        }

        // 体必须无 continue（否则 while 下语义不等价）
        foreach ($inner as $x) {
            if (is_array($x) && $x[0] === T_CONTINUE) {
                return null;
            }
        }

        $repl = [];
        foreach ($a as $x) {
            $repl[] = $x;
        }
        if ($a !== []) {
            $repl[] = ';';
        }
        $repl[] = [0 => T_WHILE, 1 => 'while', 2 => $tk[$i][2] ?? 0];
        $repl[] = '(';
        if ($b === []) {
            $repl[] = [0 => T_STRING, 1 => 'true', 2 => $tk[$i][2] ?? 0];
        } else {
            foreach ($b as $x) {
                $repl[] = $x;
            }
        }
        $repl[] = ')';
        $repl[] = '{';
        foreach ($inner as $x) {
            $repl[] = $x;
        }
        if ($c !== []) {
            foreach ($c as $x) {
                $repl[] = $x;
            }
            $repl[] = ';';
        }
        $repl[] = '}';

        return ['start' => $i, 'end' => $end, 'repl' => $repl];
    }

    // ------------------------------------------------------------------------
    // 通用工具
    // ------------------------------------------------------------------------

    /**
     * @param array<int,mixed> $tk
     * @param array<int,array{start:int,end:int,repl:array<int,mixed>}> $matches
     * @return array<int,mixed>
     */
    // ------------------------------------------------------------------------
    // 变换 5：数字字面量同义展开
    //   echo 5;  →  echo strlen("P=po1");
    //   常量位（const / 属性默认值 / case 标签 / 参数默认值 / static 初始化）只允许
    //   常量表达式，函数调用会致命错误，故这些位置一律用【算术/位运算】等价式；
    //   只有 echo / print / return 语句内（必为运行期求值）才用函数调用形态。
    // 两种形态都与原值严格相等，不改变任何语义。
    // ------------------------------------------------------------------------
    /**
     * @param array<int,mixed> $tk
     * @return array<int,mixed>
     */
    private function applyNumberObfuscation(array $tk, int $strength): array
    {
        $out = [];
        $n = count($tk);
        // 语句头：echo/print/return 之后到 ';' 之前视为运行期上下文
        $runtimeStmt = false;
        // declare(...) 里的值必须是【字面量常量】（strict_types/ticks 等），任何表达式都会被
        // 内核判为非法（"strict_types declaration must have 0 or 1 as its value"），故整条跳过。
        $inDeclare = false;
        for ($i = 0; $i < $n; $i++) {
            $t = $tk[$i];
            if (is_string($t)) {
                if ($t === ';') {
                    $runtimeStmt = false;
                    $inDeclare = false;
                }
                $out[] = $t;
                continue;
            }
            $id = $t[0];
            if ($id === T_DECLARE) {
                $inDeclare = true;
                $out[] = $t;
                continue;
            }
            if ($inDeclare) {
                $out[] = $t;
                continue;
            }
            if (($id === T_ECHO || $id === T_PRINT || $id === T_RETURN) && !$runtimeStmt) {
                $runtimeStmt = true;
                $out[] = $t;
                continue;
            }
            if ($id === T_LNUMBER && ($r = $this->numberReplacement((string) $t[1], $runtimeStmt, $strength)) !== null) {
                foreach ($r as $rt) {
                    $out[] = $rt;
                }
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /** 生成与字面量等值的表达式 token 序列；无法安全展开时返回 null（保持原样） */
    private function numberReplacement(string $raw, bool $runtime, int $strength): ?array
    {
        $digits = str_replace('_', '', $raw);
        if ($digits === '' || !ctype_digit($digits)) {
            return null;   // 十六/二进制等已由 Number 层处理，这里只认十进制
        }
        $v = (int) $digits;
        $ln = static fn (int $x): array => [T_LNUMBER, (string) $x];

        // —— 运行期：函数调用形态（更"像人写的"，最耗 AI 注意力）——
        if ($runtime && $strength >= 2) {
            $pick = $this->salt->deriveInt('num-rich-' . $raw . '-' . $v, 0, 5);
            if ($v <= 64 && $pick <= 1) {
                // strlen("...")：字符串长度恰为 $v，字符取自安全池（不含引号/反斜杠/$）
                $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789=+-_.,:;!?#%&*()[]{}<>|~^@';
                $len = strlen($pool);
                $str = '';
                for ($k = 0; $k < $v; $k++) {
                    $str .= $pool[$this->salt->deriveInt('num-ch-' . $raw . '-' . $k, 0, $len - 1)];
                }
                return [[T_STRING, 'strlen'], '(', [T_CONSTANT_ENCAPSED_STRING, '"' . $str . '"'], ')'];
            }
            if ($pick === 2 && $v <= 64) {
                // count([...])：元素个数恰为 $v
                $r = [[T_STRING, 'count'], '(', '['];
                for ($k = 0; $k < $v; $k++) {
                    if ($k > 0) {
                        $r[] = ',';
                    }
                    $r[] = [T_LNUMBER, (string) (($k % 7) + 1)];
                }
                $r[] = ']';
                $r[] = ')';
                return $r;
            }
            if ($pick === 3 && $v > 0) {
                // array_sum([a,b])：元素和恰为 $v
                $parts = [$v];
                if ($v > 1) {
                    $a = $this->salt->deriveInt('num-as-' . $raw, 1, $v - 1);
                    $parts = [$a, $v - $a];
                }
                $r = [[T_STRING, 'array_sum'], '(', '['];
                foreach ($parts as $k => $pv) {
                    if ($k > 0) {
                        $r[] = ',';
                    }
                    $r[] = [T_LNUMBER, (string) $pv];
                }
                $r[] = ']';
                $r[] = ')';
                return $r;
            }
            if ($pick === 4 && $v > 0) {
                // intdiv(B*V, B)：商恰为 $v
                $b = $this->salt->deriveInt('num-id-' . $raw, 2, 13);
                return [[T_STRING, 'intdiv'], '(', $ln($b * $v), ',', $ln($b), ')'];
            }
        }

        // —— 常量安全形态：算术 / 位运算（常量表达式与运行期都合法）——
        if ($strength < 1) {
            return null;
        }
        $mode = $this->salt->deriveInt('num-mode-' . $raw . '-' . $v, 0, 4);
        switch ($mode) {
            case 0: // a + b
                $a = $this->salt->deriveInt('num-a-' . $raw, 0, $v);
                return ['(', $ln($a), '+', $ln($v - $a), ')'];
            case 1: // (v+b) - b
                $b = $this->salt->deriveInt('num-b-' . $raw, 1, 97);
                return ['(', $ln($v + $b), '-', $ln($b), ')'];
            case 2: // (v>>b) << b
                for ($b = 3; $b >= 1; $b--) {
                    $f = 1 << $b;
                    if ($v % $f === 0) {
                        return ['(', $ln(intdiv($v, $f)), '<<', $ln($b), ')'];
                    }
                }
                $a = $this->salt->deriveInt('num-a2-' . $raw, 0, $v);
                return ['(', $ln($a), '+', $ln($v - $a), ')'];
            case 3: // a | b（a & b == 0 时 a|b == a+b）
                $a = $this->salt->deriveInt('num-or-' . $raw, 0, $v);
                $b = $v - $a;
                if (($a & $b) === 0 && ($a | $b) === $v) {
                    return ['(', $ln($a), '|', $ln($b), ')'];
                }
                return ['(', $ln($v + 1), '-', $ln(1), ')'];
            default: // a ^ b（a ^ b == v）
                $a = $this->salt->deriveInt('num-xor-' . $raw, 0, $v + 64);
                return ['(', $ln($a ^ $v), '^', $ln($a), ')'];
        }
    }

    private function rebuild(array $tk, array $matches): array
    {
        if ($matches === []) {
            return $tk;
        }
        usort($matches, fn ($x, $y) => $x['start'] <=> $y['start']);
        $out = [];
        $bi = 0;
        foreach ($matches as $m) {
            while ($bi < $m['start']) {
                $out[] = $tk[$bi++];
            }
            foreach ($m['repl'] as $r) {
                $out[] = $r;
            }
            $bi = $m['end'] + 1;
        }
        while ($bi < count($tk)) {
            $out[] = $tk[$bi++];
        }
        return $out;
    }

    /** 匹配括号：返回与 $i 处开括号配对的下标；失败返回 -1 */
    private function matchBracket(array $tk, int $i): int
    {
        $depth = 0;
        $n = count($tk);
        for ($j = $i; $j < $n; $j++) {
            $t = $tk[$j];
            if (is_string($t)) {
                if ($this->isOpen($t)) {
                    $depth++;
                } elseif ($this->isClose($t)) {
                    $depth--;
                    if ($depth === 0) {
                        return $j;
                    }
                }
            } elseif (is_array($t) && $this->isOpenTok($t)) {
                $depth++;
            }
        }
        return -1;
    }

    /** 跳过空白/注释找下一个有效 token 下标；越界返回 -1 */
    private function sig(array $tk, int $i): int
    {
        $n = count($tk);
        for ($j = $i; $j < $n; $j++) {
            $t = $tk[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return -1;
    }

    /** 向前找 depth 0 表达式起点（上一个边界 token 之后） */
    private function exprStart(array $tk, int $i): int
    {
        $bounds = [';', ')', ',', ']', '}', '('];
        for ($j = $i; $j >= 0; $j--) {
            $t = $tk[$j];
            if (is_string($t) && in_array($t, $bounds, true)) {
                return $j + 1;
            }
            if (is_array($t) && in_array($t[0], [T_RETURN, T_ECHO, T_PRINT, T_EXIT, T_THROW, T_ELSE, T_IF, T_FOR, T_FOREACH, T_WHILE, T_CASE, ':'], true)) {
                return $j + 1;
            }
        }
        return 0;
    }

    /** 向后找 depth 0 表达式终点（; ) , ] } 之一） */
    private function exprEnd(array $tk, int $i): int
    {
        $n = count($tk);
        $d = 0;
        for ($j = $i; $j < $n; $j++) {
            $t = $tk[$j];
            if (is_string($t)) {
                if ($this->isOpen($t)) {
                    $d++;
                } elseif ($this->isClose($t)) {
                    $d--;
                } elseif ($d === 0 && in_array($t, [';', ')', ',', ']', '}'], true)) {
                    return $j;
                }
            } elseif (is_array($t) && $this->isOpenTok($t)) {
                $d++;
            }
        }
        return -1;
    }

    /** 找 depth 0 单语句终点（';'） */
    private function stmtEnd(array $tk, int $i): int
    {
        $n = count($tk);
        $d = 0;
        for ($j = $i; $j < $n; $j++) {
            $t = $tk[$j];
            if (is_string($t)) {
                if ($this->isOpen($t)) {
                    $d++;
                } elseif ($this->isClose($t)) {
                    $d--;
                } elseif ($t === ';' && $d === 0) {
                    return $j;
                }
            } elseif (is_array($t) && $this->isOpenTok($t)) {
                $d++;
            }
        }
        return -1;
    }

    private function trackDepth($t, int $depth): int
    {
        if (is_string($t)) {
            if ($this->isOpen($t)) {
                return $depth + 1;
            }
            if ($this->isClose($t)) {
                return max(0, $depth - 1);
            }
        } elseif (is_array($t) && $this->isOpenTok($t)) {
            return $depth + 1;
        }
        return $depth;
    }

    private function isChar($t, string $c): bool
    {
        return is_string($t) && $t === $c;
    }

    private function isOpen(string $t): bool
    {
        return $t === '(' || $t === '[' || $t === '{';
    }

    private function isClose(string $t): bool
    {
        return $t === ')' || $t === ']' || $t === '}';
    }

    private function isOpenTok(array $t): bool
    {
        return $t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES;
    }

    /** 把 token 流重建为源码，过 php -l 校验语法（token 流已含 T_OPEN_TAG，勿重复加前缀） */
    private function syntaxOk(array $tk): bool
    {
        $src = $this->toSource($tk);
        $f = tempnam(sys_get_temp_dir(), 'sem') ?: '/tmp/sem_check.php';
        file_put_contents($f, $src);
        $out = shell_exec('php -l ' . escapeshellarg($f) . ' 2>&1') ?: '';
        @unlink($f);
        return str_contains($out, 'No syntax errors');
    }

    /**
     * @param array<int,mixed> $tk
     */
    private function toSource(array $tk): string
    {
        $s = '';
        foreach ($tk as $t) {
            $s .= is_array($t) ? $t[1] : $t;
        }
        return $s;
    }
}
