<?php
/**
 * 原生状态机混淆（StateMachineObfuscator）—— 与 VM 解耦的通用控制流混淆层。
 *
 * 背景：原「状态机」只存在于 shell:vm 的字节码层（RuntimeGenerator::stateMachineProgram，
 * 随机状态 ID + 诱饵状态）。本类把它"拆出来"，做成普通的 token 级层，使 mid 等无 VM 档位
 * 也能用上同款强度的控制流平坦化。
 *
 * 与 GotoObfuscator（语句分发器：随机标签 + 诱饵死状态）相比，本层额外引入两项 VM 同款加固：
 *   1) 不透明谓词（opaque predicate）：每条状态转移都包一层恒真谓词
 *        if (($__o ^ $__o) === 0) { $__s = $Lnext; } else { $__s = $trap; }
 *      谓词恒真、无副作用；else 分支是死路，指向自锁诱饵状态，使控制流图无法被简单线性还原。
 *   2) 诱饵状态（trap）：不可达标签，被强行跳入即 $__s = $trap 自锁死循环。
 *
 * 随机化（按 salt 确定性）：状态变量名、不透明变量名、循环头形态、比较算子、标签值
 * （非连续随机）、标签展示（十进制/十六进制）、输出顺序、诱饵插入位置 —— 静态模板批量识别失效。
 *
 * @note 仅在管线最后一次重复（isLast）时执行，避免重复加密破坏结构。
 */

declare(strict_types=1);

final class StateMachineObfuscator
{
    private NameStyle $style;
    private Salt $salt;
    private int $seq = 0;

    /** 本次 process() 生成的状态变量名（用于顶层处理后的清理与冲突检查） */
    private array $genVars = [];

    /** 用户代码中已出现的变量名（生成状态变量时避碰） */
    private array $usedVars = [];

    private int $complexity = 8;   // 每个分派器最多容纳的语句数（超出分块）
    private int $passes = 2;       // 嵌套层数（把上一层每个分派器再分发，形成分发树）
    private int $traps = 2;        // 诱饵死状态数（0=关闭）
    private bool $opaque = true;   // 转移包不透明谓词（恒真，else 指向自锁诱饵）

    public function __construct(private Logger $logger, ?Salt $salt = null)
    {
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
        $this->complexity = max(2, (int) $config->get('sm_complexity'));
        $this->passes = max(1, (int) $config->get('sm_passes'));
        $this->traps = max(0, min(4, (int) $config->get('sm_traps')));
        $this->opaque = (bool) $config->get('sm_opaque');
        if ($this->complexity <= 1 || $this->passes <= 0) {
            return $tokens; // 未开启
        }

        $this->collectUsedVars($tokens);
        $tokens = $this->processFunctionBodies($tokens);
        $tokens = $this->processTopLevel($tokens);
        return $tokens;
    }

    /**
     * 函数体改写：把每个命名函数/方法体内 ≥2 条语句改写为状态机分发器。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function processFunctionBodies(array $tokens): array
    {
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
                                    $wrapped = $this->wrapList($stmts, $this->complexity, $this->passes);
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
            $this->logger->debug("状态机混淆：改写 {$applied} 个函数体");
        }
        return $result;
    }

    /**
     * 顶层语句段改写：把文件顶层的**连续 ≥2 条**可包裹语句改写为状态机分发器。
     *
     * 覆盖场景：只有顶层逻辑（无函数定义）的脚本 —— 如纯脚本、配置文件式代码。
     * 此时若只处理函数体，状态机层等于完全不生效（mid 档会白付覆盖成本）。
     *
     * 安全边界（不包裹的顶层语句）：
     *  - 含开标签 / 闭标签 / 内联 HTML（会截断或污染输出）
     *  - class/interface/trait/enum/function 声明（条件声明语义变化，且会破坏前置提升）
     *  - declare/namespace（必须位于文件最前）
     *  - goto（跨分发器跳转非法）
     *  - 其它结构性 token（cURLY_OPEN 等插值上下文）
     */
    private function processTopLevel(array $tokens): array
    {
        $ranges = StatementSplitter::splitRanges($tokens);
        if (count($ranges) < 2) {
            return $tokens;
        }

        $total = count($ranges);
        $safe = [];
        foreach ($ranges as $idx => $r) {
            $safe[$idx] = $this->isTopLevelSafe(array_slice($tokens, $r[0], $r[1] - $r[0]));
        }

        $out = [];
        $pos = 0;      // 已消费的 token 下标
        $i = 0;
        $groups = 0;

        while ($i < $total) {
            if (!$safe[$i]) {
                // 屏障语句：原样保留
                $out = array_merge($out, array_slice($tokens, $pos, $ranges[$i][1] - $pos));
                $pos = $ranges[$i][1];
                $i++;
                continue;
            }
            // 收集连续安全区间
            $grp = [$i];
            $j = $i + 1;
            while ($j < $total && $safe[$j]) {
                $grp[] = $j;
                $j++;
            }
            if (count($grp) < 2) {
                // 单条语句无分发意义，原样保留
                $out = array_merge($out, array_slice($tokens, $pos, $ranges[$i][1] - $pos));
                $pos = $ranges[$i][1];
                $i++;
                continue;
            }
            $stmts = [];
            foreach ($grp as $k) {
                $stmts[] = array_slice($tokens, $ranges[$k][0], $ranges[$k][1] - $ranges[$k][0]);
            }
            $base = count($this->genVars);
            $wrapped = $this->wrapList($stmts, $this->complexity, $this->passes);
            $out = array_merge($out, $wrapped);
            // 顶层作用域会残留本组引入的状态变量 → 立即 unset，避免污染全局
            $newVars = array_slice($this->genVars, $base);
            if ($newVars) {
                $out = array_merge($out, $this->unsetTokens($newVars));
            }
            $pos = $ranges[$grp[count($grp) - 1]][1];
            $i = $j;
            $groups++;
        }
        $out = array_merge($out, array_slice($tokens, $pos));

        if ($groups > 0) {
            $this->logger->debug("状态机混淆：改写 {$groups} 段顶层语句");
        }
        return $out;
    }

    /**
     * 生成 unset(...) 语句 token（清理顶层引入的状态变量）。
     * @param array<int,string> $vars
     * @return array<int,mixed>
     */
    private function unsetTokens(array $vars): array
    {
        $out = ['unset', '('];
        $first = true;
        foreach ($vars as $v) {
            if (!$first) {
                $out[] = ',';
            }
            $first = false;
            $out[] = [T_VARIABLE, $v, 0];
        }
        $out[] = ')';
        $out[] = ';';
        return $out;
    }

    /**
     * 判断一条顶层语句是否可安全纳入状态机分发器。
     *
     * 不可纳入的（作为屏障原样保留）：
     *  - 开标签 / 闭标签 / 内联 HTML（截断或污染输出）
     *  - class/interface/trait/enum/function 声明（条件声明语义变化）
     *  - declare/namespace（必须位于文件最前）
     *  - goto（跨分发器跳转非法）
     *  - 插值上下文（"{$x}" / "${x}"）
     *
     * @param array<int,mixed> $stmt
     */
    private function isTopLevelSafe(array $stmt): bool
    {
        foreach ($stmt as $t) {
            if (!is_array($t)) {
                continue;
            }
            $type = $t[0];
            if (in_array($type, [
                T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML,
                T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION, T_DECLARE, T_NAMESPACE, T_GOTO,
                T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES,
                // const 声明只允许出现在文件/命名空间顶层，嵌进 switch case 会 Parse error
                T_CONST,
            ], true)) {
                return false;
            }
            if (defined('T_ENUM') && $type === T_ENUM) {
                return false;
            }
        }
        return true;
    }

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
                    for ($k = $j + 1; $k < $n; $k++) {
                        $tk = $tokens[$k];
                        if ($tk === '{') {
                            return $k;
                        }
                        if (is_array($tk)) {
                            $allowed = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NS_SEPARATOR];
                            if (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')) {
                                $allowed[] = T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
                            }
                            if (!in_array($tk[0], $allowed, true)) {
                                return -1;
                            }
                        } else {
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
            if ($t[0] === T_FUNCTION) {
                return true; // 闭包
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
     * 构建单级状态机分发器 token 序列（随机状态 ID + 诱饵死状态 + 不透明谓词转移）。
     * @param array<int,array<int,mixed>> $stmts
     * @return array<int,mixed>
     */
    private function buildDispatch(array $stmts): array
    {
        $this->seq++;
        $seq = $this->seq;
        $s = $this->pickVar('sm-state-' . $seq);
        $k = count($stmts);

        // 生成打乱的随机状态 ID（非连续）
        $labels = [];
        $used = [];
        for ($x = 0; $x < $k; $x++) {
            $guard = 0;
            do {
                $lv = $this->salt->isEmpty()
                    ? random_int(2, 0x7fffffff)
                    : $this->salt->deriveInt('sm-label-' . $seq . '-' . $x . '-' . $guard, 2, 0x7fffffff);
                $guard++;
            } while (isset($used[$lv]) && $guard < 32);
            while (isset($used[$lv])) {
                $lv++;
            }
            $used[$lv] = true;
            $labels[] = $lv;
        }
        $sentinel = $this->salt->isEmpty()
            ? max($labels) + random_int(1, 999)
            : max($labels) + $this->salt->deriveInt('sm-sentinel-' . $seq, 1, 999);
        $fmt = function (int $v) use ($seq): string {
            return $this->salt->deriveInt('sm-hex-' . $seq . '-' . $v, 0, 1) === 1
                ? '0x' . dechex($v)
                : (string) $v;
        };
        $order = $this->salt->shuffle(range(0, $k - 1), 'sm-order-' . $seq);

        // 诱饵死状态：不透明谓词开启时至少保留 1 个（else 分支需要跳转目标）
        $trapCount = $this->opaque ? max(1, $this->traps) : $this->traps;
        $trapLabels = [];
        for ($d = 0; $d < $trapCount; $d++) {
            $guard = 0;
            do {
                $lv = $this->salt->isEmpty()
                    ? random_int(2, 0x7fffffff)
                    : $this->salt->deriveInt('sm-trap-' . $seq . '-' . $d . '-' . $guard, 2, 0x7fffffff);
                $guard++;
            } while ((isset($used[$lv]) || $lv === $sentinel) && $guard < 32);
            while (isset($used[$lv]) || $lv === $sentinel) {
                $lv++;
            }
            $used[$lv] = true;
            $trapLabels[] = $lv;
        }
        // 诱饵分支体：把状态变量写回自身 → while(true) 自锁死循环
        $trapBody = function (int $tl) use ($s, $fmt, $seq): array {
            $tmp = $this->pickVar('sm-d-' . $seq . '-' . $tl);
            return [
                [T_VARIABLE, $tmp, 0], '=', [T_LNUMBER, $fmt($tl), 0], '*', [T_LNUMBER, '3', 0], ';',
                [T_VARIABLE, $s, 0], '=', [T_LNUMBER, $fmt($tl), 0], ';',
            ];
        };
        $trapIdx = 0;

        // 不透明谓词用的局部整型变量（恒真：$o ^ $o === 0）
        $o = $this->pickVar('sm-op-' . $seq);
        $oInit = $this->salt->isEmpty()
            ? random_int(1, 9999)
            : $this->salt->deriveInt('sm-opinit-' . $seq, 1, 9999);

        $form   = $this->salt->deriveInt('sm-form-' . $seq, 0, 1);   // 0=switch 1=if 链
        $loop   = $this->salt->deriveInt('sm-loop-' . $seq, 0, 2);   // 0/1/2 循环头
        $strict = $this->salt->deriveInt('sm-cmp-' . $seq, 0, 1) === 1;
        $cmp    = $strict ? '===' : '==';

        $out = [];
        $out[] = [T_VARIABLE, $s, 0];
        $out[] = '=';
        $out[] = [T_LNUMBER, $fmt($labels[0]), 0];
        $out[] = ';';
        // 不透明变量声明
        $out[] = [T_VARIABLE, $o, 0];
        $out[] = '=';
        $out[] = [T_LNUMBER, (string) $oInit, 0];
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
                $next = $idx + 1 < $k ? $labels[$idx + 1] : $sentinel;
                $out = array_merge($out, $this->transition($s, $o, $next, $fmt, $trapLabels));
                array_push($out, 'break', ';');
                if ($trapIdx < count($trapLabels)
                    && $this->salt->deriveInt('sm-trap-at-' . $seq . '-' . $idx, 0, 2) === 0) {
                    $tl = $trapLabels[$trapIdx++];
                    array_push($out, 'case');
                    $out[] = [T_LNUMBER, $fmt($tl), 0];
                    array_push($out, ':');
                    foreach ($trapBody($tl) as $t) {
                        $out[] = $t;
                    }
                    array_push($out, 'break', ';');
                }
            }
            while ($trapIdx < count($trapLabels)) {
                $tl = $trapLabels[$trapIdx++];
                array_push($out, 'case');
                $out[] = [T_LNUMBER, $fmt($tl), 0];
                array_push($out, ':');
                foreach ($trapBody($tl) as $t) {
                    $out[] = $t;
                }
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
                $next = $idx + 1 < $k ? $labels[$idx + 1] : $sentinel;
                $out = array_merge($out, $this->transition($s, $o, $next, $fmt, $trapLabels));
                array_push($out, '}');
                $first = false;
            }
            while ($trapIdx < count($trapLabels)) {
                $tl = $trapLabels[$trapIdx++];
                array_push($out, 'elseif', '(');
                $out[] = [T_VARIABLE, $s, 0];
                array_push($out, '===');
                $out[] = [T_LNUMBER, $fmt($tl), 0];
                array_push($out, ')', '{');
                foreach ($trapBody($tl) as $t) {
                    $out[] = $t;
                }
                array_push($out, '}');
            }
            array_push($out, 'else', '{', 'break', ';', '}', '}');
        }
        return $out;
    }

    /**
     * 生成一个不与用户代码冲突的变量名。
     * 顶层分发会把状态变量写入**全局作用域**，若与用户变量同名会覆盖用户数据；
     * 函数体内同名只是局部遮蔽，但仍需避免误判（用户在函数内可能引用同名全局变量）。
     *
     * @param string $label 用途标签（决定名字风格）
     * @return string 形如 '$cache_handler'
     */
    private function pickVar(string $label): string
    {
        $base = $this->style->varName($label);
        $name = $base;
        $n = 0;
        while (isset($this->usedVars[$name])) {
            $n++;
            $name = $base . '_' . $this->salt->deriveHex('sm-var-' . $label . '-' . $n, 4);
            if (isset($this->usedVars[$name])) {
                $name = $base . '_' . $n . '_' . $this->salt->deriveHex('sm-var2-' . $label . '-' . $n, 4);
            }
        }
        $this->usedVars[$name] = true;
        $this->genVars[] = $name;
        return $name;
    }

    /**
     * 收集用户代码中已使用的变量名（供 pickVar 避碰）。
     * @param array<int,mixed> $tokens
     */
    private function collectUsedVars(array $tokens): void
    {
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_VARIABLE) {
                $this->usedVars[$t[1]] = true;
            }
        }
    }

    /**
     * 生成一条状态转移指令序列。
     * - 不透明关闭：直接 $s = $next;
     * - 不透明开启：if (($o ^ $o) === 0) { $s = $next; } else { $s = $trap; }
     *   谓词恒真、无副作用；else 死路指向自锁诱饵状态。
     * @param callable(int):string $fmt
     * @param array<int> $trapLabels
     * @return array<int,mixed>
     */
    private function transition(string $s, string $o, int $next, callable $fmt, array $trapLabels): array
    {
        if (!$this->opaque || count($trapLabels) === 0) {
            return [
                [T_VARIABLE, $s, 0], '=', [T_LNUMBER, $fmt($next), 0], ';',
            ];
        }
        $trap = $trapLabels[0];
        return [
            'if', '(', '(', [T_VARIABLE, $o, 0], '^', [T_VARIABLE, $o, 0], ')', '===', [T_LNUMBER, '0', 0], ')', '{',
                [T_VARIABLE, $s, 0], '=', [T_LNUMBER, $fmt($next), 0], ';',
            '}', 'else', '{',
                [T_VARIABLE, $s, 0], '=', [T_LNUMBER, $fmt($trap), 0], ';',
            '}',
        ];
    }
}
