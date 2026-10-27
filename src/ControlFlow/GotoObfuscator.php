<?php
/**
 * GOTO 控制流混淆（GotoObfuscator）
 *
 * 将每个命名函数/方法体中的"顶层语句序列"改写为"状态机分发器"：
 *   $__s = L0;
 *   while(1){ switch($__s){ case Lk: <语句k>; $__s=Lnext; break; ... default: break 2; } }
 * 语句的真实执行顺序由 $__s 的转移指针决定（与原顺序一致），而 case 标签采用
 * 打乱后的数值，使得代码"看起来是乱序的 spaghetti"，但运行结果不变。
 *
 * 复杂度：
 *  - --goto-cpx=M：每个分发器最多容纳 M 条语句，超出则拆分为多个分发器（分块）。
 *  - --goto=N：嵌套层数（把上一层每个分发器作为一个"语句"再次分发），形成分发树。
 *
 * 跳过规则：函数体内含匿名函数(T_FUNCTION 无名下紧跟 ( )、箭头函数 T_FN、match、
 * 动态变量 ${ 时，整体跳过该函数的 GOTO 改写，保证正确性。
 *
 * @note 仅在管线最后一次重复（isLast）时执行，避免重复加密破坏结构。
 */

declare(strict_types=1);

final class GotoObfuscator
{
    private NameStyle $style;
    private Salt $salt;
    private int $seq = 0;
    /** 每个分派器注入的诱饵状态数（0=关闭）：不可达的死状态，被调试器强行跳入即自锁 */
    private int $traps = 2;

    public function __construct(private Logger $logger, ?Salt $salt = null)
    {
        // 提供 salt 时命名与结构选择可复现；缺省用随机盐保持原有随机行为
        $this->salt = $salt ?? new Salt(bin2hex(random_bytes(8)));
        $this->style = new NameStyle($this->salt);
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config, bool $isLast = false): array
    {
        if (!$isLast) {
            return $tokens;
        }
        $count = (int) $config->get('goto');
        $cpx = (int) $config->get('goto_cpx');
        if ($count <= 0 && $cpx <= 0) {
            return $tokens; // 未开启
        }
        $passes = max(1, $count);
        $maxBlock = max(2, $cpx);
        $this->traps = max(0, min(4, (int) $config->get('goto_trap')));

        $n = count($tokens);
        $result = [];
        $i = 0;
        $applied = 0;
        while ($i < $n) {
            $tok = $tokens[$i];
            // 命名函数/方法：T_FUNCTION 后紧跟 T_STRING（函数名）
            if (is_array($tok) && $tok[0] === T_FUNCTION) {
                $nx = Parser::nextSignificant($tokens, $i, 1);
                if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_STRING) {
                    $brace = $this->findBodyBrace($tokens, $nx);
                    if ($brace >= 0) {
                        $bodyStart = $brace + 1;
                        $bodyEnd = $this->matchBrace($tokens, $brace);
                        if ($bodyEnd > $bodyStart) {
                            $inner = array_slice($tokens, $bodyStart, $bodyEnd - $bodyStart);
                            if (!$this->containsUnsafe($inner)) {
                            $stmts = StatementSplitter::split($inner);
                            if (count($stmts) >= 2) {
                                $wrapped = $this->wrapList($stmts, $maxBlock, $passes);
                                // 拼回：保留完整的函数签名（T_FUNCTION … 形参列表 … 开标签 {），
                                // 再拼接 GOTO 改写后的函数体，最后闭合 }。
                                // 注意：若只输出开标签 { 而丢弃 function 签名，函数会变成顶层代码块，
                                // 其内部的 return 将提前结束整个脚本而非返回函数值。
                                for ($k = $i; $k <= $brace; $k++) {
                                    $result[] = $tokens[$k];
                                }
                                foreach ($wrapped as $w) {
                                    $result[] = $w;
                                }
                                $result[] = $tokens[$bodyEnd];
                                $i = $bodyEnd + 1;
                                $applied++;
                                continue;
                            }
                            }
                        }
                    }
                }
            }
            $result[] = $tok;
            $i++;
        }

        if ($applied > 0) {
            $this->logger->debug("GOTO 混淆：改写 {$applied} 个函数体");
        }
        return $result;
    }

    /**
     * 从函数名 token 之后找参数列表后的 '{'（函数体起始）。
     */
    private function findBodyBrace(array $tokens, int $nameIdx): int
    {
        $pdepth = 0;
        $n = count($tokens);
        for ($j = $nameIdx + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if ($t === '(') {
                $pdepth++;
            } elseif ($t === ')') {
                if ($pdepth === 1) {
                    // 参数列表结束，跳过返回类型声明（: type / ?type / type|type / type&type）
                    // 再找函数体起始 '{'；其余（如 abstract 的 ;）视为放弃。
                    // 注：':'/'?'/'|' 为裸字符；'&' 在 PHP 8.1+ 词法为
                    // T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG 数组 token。
                    for ($k = $j + 1; $k < $n; $k++) {
                        $tk = $tokens[$k];
                        if ($tk === '{') {
                            return $k;
                        }
                        if (is_array($tk)) {
                            $allowed = [
                                T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                                T_STRING, T_NS_SEPARATOR,
                            ];
                            if (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')) {
                                $allowed[] = T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
                            }
                            if (!in_array($tk[0], $allowed, true)) {
                                return -1; // 非返回类型成分，放弃
                            }
                        } else {
                            // 裸字符：仅允许 ':'（返回类型）、'?'（可空）、'|'（联合）
                            if ($tk !== ':' && $tk !== '?' && $tk !== '|') {
                                return -1;
                            }
                        }
                    }
                    return -1;
                }
                $pdepth--;
            }
        }
        return -1;
    }

    /**
     * 配对花括号。插值修正：T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES
     * （"{$expr}" 起始）是数组 token，但其闭合是裸 '}' 字符——不计入深度
     * 会让块范围在第一个插值串处提前结束。
     */
    private function matchBrace(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $t = $tokens[$j];
            if ($t === '{') {
                $depth++;
            } elseif (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return -1;
    }

    private function containsUnsafe(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (in_array($t[0], [T_FN, T_MATCH, T_CURLY_OPEN, T_TRY, T_CATCH, T_FINALLY, T_THROW], true)) {
                return true;
            }
            // 匿名函数：T_FUNCTION 后紧跟 '('
            if ($t[0] === T_FUNCTION) {
                // 已在 process 中仅对"命名函数"进入；此处 inner 内若出现 T_FUNCTION 即视为闭包
                return true;
            }
        }
        return false;
    }

    /**
     * 递归包装语句列表，形成分发树。
     * @param array<int,array<int,mixed>> $stmts
     * @return array<int,mixed>
     */
    private function wrapList(array $stmts, int $maxBlock, int $passes): array
    {
        if ($passes <= 0 || count($stmts) <= 1) {
            return $this->concat($stmts);
        }
        $chunks = array_chunk($stmts, $maxBlock);
        if (count($chunks) <= 1) {
            return $this->buildDispatch($stmts);
        }
        $chunkStmts = [];
        foreach ($chunks as $chunk) {
            $chunkStmts[] = $this->buildDispatch($chunk);
        }
        return $this->wrapList($chunkStmts, $maxBlock, $passes - 1);
    }

    /**
     * 将多条语句拼接为 token 序列（保留原始结构）。
     * 注意：StatementSplitter::split() 会剔除每条语句的终结符 ';'，
     * 因此在重新拼接 / 嵌入分发器时必须补齐 ';'，否则 `return x$s=..` 之类会语法错误。
     * @param array<int,array<int,mixed>> $stmts
     * @return array<int,mixed>
     */
    private function concat(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            foreach ($this->withTerminator($stmt) as $t) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 给一条语句补齐终结符 ';'（除非其本身已以 ';' 或 '}' 结尾的块结构结束）。
     * @param array<int,mixed> $stmt
     * @return array<int,mixed>
     */
    private function withTerminator(array $stmt): array
    {
        $last = end($stmt);
        $lastCh = is_string($last) ? $last : (is_array($last) ? ($last[1] ?? '') : '');
        if ($lastCh === ';' || $lastCh === '}') {
            return $stmt;
        }
        $stmt[] = ';';
        return $stmt;
    }

    /**
     * 构建单级分发器 token 序列。
     *
     * 随机化（按 salt 确定性选择，可复现但每个分发器形态互不相同）：
     *   - 状态变量名：拟真命名（$cache_handler 风格），不再用 $__gXXXX_s 指纹；
     *   - 分派结构：switch-case 或 if/elseif 链，二选一；
     *   - 循环形态：while(1) / while(true) / for(;;)；
     *   - 比较算子：=== / ==；
     *   - case 标签：值随机，十进制或十六进制展示随机；
     *   - case 输出顺序：打乱。
     * 攻击者无法再用"固定 switch-case + while(1)"模板批量识别并还原。
     *
     * @param array<int,array<int,mixed>> $stmts
     * @return array<int,mixed>
     */
    private function buildDispatch(array $stmts): array
    {
        $this->seq++;
        $seq = $this->seq;
        $s = $this->style->varName('goto-state-' . $seq);
        $k = count($stmts);

        // 生成打乱的 case 标签
        $labels = [];
        $used = [];
        for ($x = 0; $x < $k; $x++) {
            $guard = 0;
            do {
                // 确定性派生（同 salt 可复现）；空 salt 时 NameStyle 一样走随机，这里同理
                $lv = $this->salt->isEmpty()
                    ? random_int(2, 0x7fffffff)
                    : $this->salt->deriveInt('goto-label-' . $seq . '-' . $x . '-' . $guard, 2, 0x7fffffff);
                $guard++;
            } while (isset($used[$lv]) && $guard < 32);
            while (isset($used[$lv])) {
                $lv++; // 兜底：极端碰撞时线性探测，保证标签唯一
            }
            $used[$lv] = true;
            $labels[] = $lv;
        }
        $sentinel = $this->salt->isEmpty()
            ? max($labels) + random_int(1, 999)
            : max($labels) + $this->salt->deriveInt('goto-sentinel-' . $seq, 1, 999);
        // 标签展示形式：十进制 / 十六进制（0x…）随机
        $fmt = function (int $v) use ($seq): string {
            return $this->salt->deriveInt('goto-hex-' . $seq . '-' . $v, 0, 1) === 1
                ? '0x' . dechex($v)
                : (string) $v;
        };
        // 打乱输出顺序（scramble）：同 salt 复现同一顺序，保证可复现构建
        $order = $this->salt->shuffle(range(0, $k - 1), 'goto-order-' . $seq);

        // ---- 诱饵状态（死状态）----
        // 这些标签【从不被任何赋值语句产生】，正常执行永不可达；一旦分析者用调试器
        // 强改状态变量跳进来，分支体把状态写回自身 → while(true) 自锁死循环。
        // 静态看只是一批普通 case 分支，无法从"是否存在跳入边"直接判定死代码。
        $trapLabels = [];
        for ($d = 0; $d < $this->traps; $d++) {
            $guard = 0;
            do {
                $lv = $this->salt->isEmpty()
                    ? random_int(2, 0x7fffffff)
                    : $this->salt->deriveInt('goto-trap-' . $seq . '-' . $d . '-' . $guard, 2, 0x7fffffff);
                $guard++;
            } while (isset($used[$lv]) && $guard < 32);
            while (isset($used[$lv])) { $lv++; }
            $used[$lv] = true;
            $trapLabels[] = $lv;
        }
        $trapBody = function (int $tl) use ($s, $fmt, $seq): array {
            $tmp = $this->style->varName('goto-d-' . $seq . '-' . $tl);
            return [
                [T_VARIABLE, $tmp, 0], '=', [T_LNUMBER, $fmt($tl), 0], '*', [T_LNUMBER, '3', 0], ';',
                [T_VARIABLE, $s, 0], '=', [T_LNUMBER, $fmt($tl), 0], ';',
            ];
        };
        $trapIdx = 0;

        $form   = $this->salt->deriveInt('goto-form-' . $seq, 0, 1);      // 0=switch 1=if 链
        $loop   = $this->salt->deriveInt('goto-loop-' . $seq, 0, 2);      // 0/1/2 三种循环头
        $strict = $this->salt->deriveInt('goto-cmp-' . $seq, 0, 1) === 1; // === / ==
        $cmp    = $strict ? '===' : '==';

        $out = [];
        $out[] = [T_VARIABLE, $s, 0];
        $out[] = '=';
        $out[] = [T_LNUMBER, $fmt($labels[0]), 0];
        $out[] = ';';
        if ($loop === 0) {
            array_push($out, 'while', '(', '1', ')', '{');
        } elseif ($loop === 1) {
            array_push($out, 'while', '(', 'true', ')', '{');
        } else {
            array_push($out, 'for', '(', ';', ';', ')', '{');
        }

        if ($form === 0) {
            // ---- switch 分派 ----
            array_push($out, 'switch', '(');
            $out[] = [T_VARIABLE, $s, 0];
            array_push($out, ')', '{');
            foreach ($order as $idx) {
                array_push($out, 'case');
                $out[] = [T_LNUMBER, $fmt($labels[$idx]), 0];
                array_push($out, ':');
                foreach ($this->withTerminator($stmts[$idx]) as $t) {
                    $out[] = $t;
                }
                $out[] = [T_VARIABLE, $s, 0];
                array_push($out, '=');
                $out[] = [T_LNUMBER, $fmt($idx + 1 < $k ? $labels[$idx + 1] : $sentinel), 0];
                array_push($out, ';', 'break', ';');
                // 诱饵分支就地插入（概率性，salt 决定），与真实分支外观完全一致
                if ($trapIdx < count($trapLabels)
                    && $this->salt->deriveInt('goto-trap-at-' . $seq . '-' . $idx, 0, 2) === 0) {
                    $tl = $trapLabels[$trapIdx++];
                    array_push($out, 'case');
                    $out[] = [T_LNUMBER, $fmt($tl), 0];
                    array_push($out, ':');
                    foreach ($trapBody($tl) as $t) { $out[] = $t; }
                    array_push($out, 'break', ';');
                }
            }
            // 剩余诱饵分支补在 default 之前
            while ($trapIdx < count($trapLabels)) {
                $tl = $trapLabels[$trapIdx++];
                array_push($out, 'case');
                $out[] = [T_LNUMBER, $fmt($tl), 0];
                array_push($out, ':');
                foreach ($trapBody($tl) as $t) { $out[] = $t; }
                array_push($out, 'break', ';');
            }
            array_push($out, 'default', ':', 'break', '2', ';', '}', '}');
        } else {
            // ---- if / elseif 链分派 ----
            $first = true;
            foreach ($order as $idx) {
                array_push($out, $first ? 'if' : 'elseif', '(');
                $out[] = [T_VARIABLE, $s, 0];
                array_push($out, $cmp);
                $out[] = [T_LNUMBER, $fmt($labels[$idx]), 0];
                array_push($out, ')', '{');
                foreach ($this->withTerminator($stmts[$idx]) as $t) {
                    $out[] = $t;
                }
                $out[] = [T_VARIABLE, $s, 0];
                array_push($out, '=');
                $out[] = [T_LNUMBER, $fmt($idx + 1 < $k ? $labels[$idx + 1] : $sentinel), 0];
                array_push($out, ';', '}');
                $first = false;
            }
            // 诱饵分支：与真实分支同形，状态写回自身 → 被强行跳入即自锁
            while ($trapIdx < count($trapLabels)) {
                $tl = $trapLabels[$trapIdx++];
                array_push($out, 'elseif', '(');
                $out[] = [T_VARIABLE, $s, 0];
                array_push($out, '===');
                $out[] = [T_LNUMBER, $fmt($tl), 0];
                array_push($out, ')', '{');
                foreach ($trapBody($tl) as $t) { $out[] = $t; }
                array_push($out, '}');
            }
            // 哨兵：落到不存在的状态 -> 退出循环（if 链只有一层可 break 结构，用 break 1）
            array_push($out, 'else', '{', 'break', ';', '}', '}');
        }
        return $out;
    }
}
