<?php
/**
 * 循环结构解析（LoopScanTrait）
 *
 * 把源码里的 for / foreach / while / do-while 识别出来，并**静态估算迭代数**：
 *   - for：初值（`$i = <int>`）+ 边界（`$i < <int>`）+ 步长（`$i++` / `$i += <int>`）；
 *   - while / do-while：条件里的比较 + 循环之前最近一次对循环变量的赋值；
 *   - foreach：数组字面量、`range(a, b)` 这类能数清的形态。
 * 估不出来（`while (true)`、`while ($row = f())`、边界是运行时变量）一律按「大循环」处理
 * ——对静态分析而言，无法证明它小，就只能当作大。
 *
 * 两个调用方共用同一套判定，保证口径一致：
 *   1. LogicRewriter：大循环里的字面量只注入运算符级恒等（编译期折叠、运行期零指令），
 *      不用函数调用积木——否则每轮重算一次，N 轮就是 N 倍开销；
 *   2. StringEncoder：大循环里的字符串不做 XOR + base64 运行时解码，理由同上。
 * 两边若各写一套判定，迟早出现「logic 认为是大循环、string 认为是小循环」的错位。
 */

declare(strict_types=1);

trait LoopScanTrait
{

    private function matchBracket(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $j;
                    }
                }
            } elseif (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            }
        }
        return -1;
    }



    /** 提取语句主体：{...} 块或单条到深度0 ';' 的语句。返回 [tokens, end]，失败 [[], -1]。 */
    private function extractBody(array $tokens, int $start): array
    {
        $t = $tokens[$start];
        if ($this->isChar($t, '{')) {
            $close = $this->matchBracket($tokens, $start);
            if ($close < 0) {
                return [[], -1];
            }
            return [array_slice($tokens, $start + 1, $close - $start - 1), $close];
        }
        $depth = 0;
        $pdepth = 0;
        $bdepth = 0;
        $n = count($tokens);
        for ($i = $start; $i < $n; $i++) {
            $tk = $tokens[$i];
            if (is_string($tk)) {
                if ($tk === '(') {
                    $pdepth++;
                } elseif ($tk === ')') {
                    if ($pdepth > 0) {
                        $pdepth--;
                    }
                } elseif ($tk === '[') {
                    $bdepth++;
                } elseif ($tk === ']') {
                    if ($bdepth > 0) {
                        $bdepth--;
                    }
                } elseif ($tk === '{') {
                    $depth++;
                } elseif ($tk === '}') {
                    if ($depth > 0) {
                        $depth--;
                    }
                } elseif ($tk === ';' && $depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    return [array_slice($tokens, $start, $i - $start + 1), $i];
                }
            } elseif (is_array($tk) && ($tk[0] === T_CURLY_OPEN || $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            }
        }
        return [[], -1];
    }



    private function nextSignificant(array $tokens, int $i, int $dir): int
    {
        return Parser::nextSignificant($tokens, $i, $dir);
    }



    private function isWs($t): bool
    {
        return is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }



    private function isChar($t, string $c): bool
    {
        return is_string($t) && $t === $c;
    }



    // --------------------------------------------------------------------
    // 循环结构解析
    // --------------------------------------------------------------------

    /**
     * 识别 $i 处的循环（for / foreach / while / do-while）。
     * @return array{kind:string,headStart:int,end:int,parenStart:int,parenEnd:int,
     *               condStart:int,condEnd:int,stepStart:int,stepEnd:int,
     *               bodyStart:int,bodyEnd:int}|null
     */
    private function parseLoop(array $tokens, int $i): ?array
    {
        $t = $tokens[$i] ?? null;
        if (!is_array($t)) {
            return null;
        }
        if ($t[0] === T_DO) {
            $bs = $this->nextSignificant($tokens, $i, 1);
            if ($bs < 0) {
                return null;
            }
            $body = $this->extractBody($tokens, $bs);
            if ($body[1] < 0) {
                return null;
            }
            $be = $body[1];
            $w = $this->nextSignificant($tokens, $be, 1);
            if ($w < 0 || !is_array($tokens[$w] ?? null) || $tokens[$w][0] !== T_WHILE) {
                return null;
            }
            $p = $this->nextSignificant($tokens, $w, 1);
            if ($p < 0 || !$this->isChar($tokens[$p] ?? null, '(')) {
                return null;
            }
            $q = $this->matchBracket($tokens, $p);
            if ($q < 0) {
                return null;
            }
            $end = $q;
            $semi = $this->nextSignificant($tokens, $q, 1);
            if ($semi > 0 && $this->isChar($tokens[$semi] ?? null, ';')) {
                $end = $semi;
            }
            return [
                'kind' => 'do', 'headStart' => $i, 'end' => $end,
                'parenStart' => $p, 'parenEnd' => $q,
                'condStart' => $p + 1, 'condEnd' => $q - 1,
                'stepStart' => -1, 'stepEnd' => -1,
                'bodyStart' => $bs, 'bodyEnd' => $be,
            ];
        }
        if (!in_array($t[0], [T_FOR, T_FOREACH, T_WHILE], true)) {
            return null;
        }
        $p = $this->nextSignificant($tokens, $i, 1);
        if ($p < 0 || !$this->isChar($tokens[$p] ?? null, '(')) {
            return null;
        }
        $q = $this->matchBracket($tokens, $p);
        if ($q < 0) {
            return null;
        }
        $bs = $this->nextSignificant($tokens, $q, 1);
        if ($bs < 0) {
            return null;
        }
        // `for (…): … endfor;` 老式替代语法 / 空 body：都不碰
        if ($this->isChar($tokens[$bs] ?? null, ':') || $this->isChar($tokens[$bs] ?? null, ';')) {
            return null;
        }
        $body = $this->extractBody($tokens, $bs);
        if ($body[1] < 0) {
            return null;
        }
        $be = $body[1];
        $kind = $t[0] === T_FOR ? 'for' : ($t[0] === T_FOREACH ? 'foreach' : 'while');
        $cs = $p + 1;
        $ce = $q - 1;
        $ss = -1;
        $se = -1;
        if ($kind === 'for') {
            $sec = $this->forSections($tokens, $p, $q);
            if ($sec !== null) {
                [$cs, $ce] = $sec[1];
                [$ss, $se] = $sec[2];
            }
        }
        return [
            'kind' => $kind, 'headStart' => $i, 'end' => $be,
            'parenStart' => $p, 'parenEnd' => $q,
            'condStart' => $cs, 'condEnd' => $ce,
            'stepStart' => $ss, 'stepEnd' => $se,
            'bodyStart' => $bs, 'bodyEnd' => $be,
        ];
    }



    /**
     * 切出 for 头里的三段 [init, cond, step]，返回各段的 [start, end]（含首尾）。
     * @return array<int,array{0:int,1:int}>|null
     */
    private function forSections(array $tokens, int $p, int $q): ?array
    {
        $parts = [];
        $start = $p + 1;
        $depth = 0;
        for ($i = $p + 1; $i < $q; $i++) {
            $t = $tokens[$i];
            if (!is_string($t)) {
                continue;
            }
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $depth--;
            } elseif ($t === ';' && $depth === 0) {
                $parts[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        if (count($parts) !== 2) {
            return null;
        }
        $parts[] = [$start, $q - 1];
        return $parts;
    }



    /**
     * 静态估算迭代次数；null = 估不出（按大循环处理）。
     * @param array<int,mixed> $tokens
     */
    private function estimateIterations(array $tokens, array $loop): ?int
    {
        $kind = $loop['kind'];
        if ($kind === 'foreach') {
            return $this->estimateForeach($tokens, $loop);
        }
        if ($loop['condStart'] < 0 || $loop['condEnd'] < $loop['condStart']) {
            return null;
        }
        $cond = array_slice($tokens, $loop['condStart'], $loop['condEnd'] - $loop['condStart'] + 1);
        $b = $this->parseCondBound($cond);
        if ($b === null) {
            return null;
        }
        [$var, $op, $bound] = $b;
        $start = null;
        $step = 1;
        if ($kind === 'for') {
            $sec = $this->forSections($tokens, $loop['parenStart'], $loop['parenEnd']);
            if ($sec === null) {
                return null;
            }
            $init = array_slice($tokens, $sec[0][0], $sec[0][1] - $sec[0][0] + 1);
            $stepT = array_slice($tokens, $sec[2][0], $sec[2][1] - $sec[2][0] + 1);
            $start = $this->parseInitAssign($init, $var);
            $step = $this->parseStepDelta($stepT, $var) ?? 0;
            if ($step <= 0) {
                return null;
            }
        } else {
            $start = $this->findPrevAssign($tokens, $loop['headStart'], $var);
        }
        if ($start === null) {
            return null;
        }
        return $this->iterCount($start, $op, $bound, $step);
    }



    /** foreach 只看「数组字面量 / range(a,b)」这两种能数清的形态 */
    private function estimateForeach(array $tokens, array $loop): ?int
    {
        $p = $loop['parenStart'];
        $q = $loop['parenEnd'];
        // 找顶层 `as`
        $depth = 0;
        $as = -1;
        for ($i = $p + 1; $i < $q; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                }
                continue;
            }
            if ($depth === 0 && $t[0] === T_AS) {
                $as = $i;
                break;
            }
        }
        if ($as < 0) {
            return null;
        }
        $s = $this->nextSignificant($tokens, $p, 1);
        $e = Parser::prevSignificant($tokens, $as);
        if ($s < 0 || $e < 0 || $e < $s) {
            return null;
        }
        $expr = array_slice($tokens, $s, $e - $s + 1);
        $sig = [];
        foreach ($expr as $t) {
            if (!$this->isWs($t)) {
                $sig[] = $t;
            }
        }
        if ($sig === []) {
            return null;
        }
        // [...]
        if ($this->isChar($sig[0], '[') && $this->isChar($sig[count($sig) - 1], ']')) {
            return $this->countArrayItems($sig);
        }
        // array(...)
        if (is_array($sig[0]) && $sig[0][0] === T_ARRAY) {
            $k = 1;
            while ($k < count($sig) && $this->isWs($sig[$k])) {
                $k++;
            }
            if (isset($sig[$k]) && $this->isChar($sig[$k], '(')) {
                return $this->countArrayItems($sig);
            }
        }
        // range(a, b)
        if (is_array($sig[0]) && $sig[0][0] === T_STRING && strtolower($sig[0][1]) === 'range'
            && isset($sig[1]) && $this->isChar($sig[1], '(')) {
            $nums = [];
            foreach ($sig as $t) {
                if (is_array($t) && $t[0] === T_LNUMBER) {
                    $v = $this->intLiteralValue($t[1]);
                    if ($v !== null) {
                        $nums[] = $v;
                    }
                }
            }
            if (count($nums) === 2) {
                return abs($nums[1] - $nums[0]) + 1;
            }
        }
        return null;
    }



    /** 数数组字面量的顶层元素个数（已确认首尾是 [ ] 或 array( )） */
    private function countArrayItems(array $sig): ?int
    {
        $n = count($sig);
        $depth = 0;
        $commas = 0;
        $any = false;
        for ($i = 0; $i < $n; $i++) {
            $t = $sig[$i];
            if (is_string($t)) {
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                    continue;
                }
                if ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    continue;
                }
                if ($t === ',' && $depth === 1) {
                    $commas++;
                    continue;
                }
                if ($depth >= 1) {
                    $any = true;
                }
                continue;
            }
            if ($depth >= 1) {
                $any = true;
            }
        }
        if (!$any) {
            return 0;
        }
        return $commas + 1;
    }



    /**
     * 从条件里提取 `$var <op> <int>`（或反向），返回 [变量名, op, 边界]。
     * @return array{0:string,1:string,2:int}|null
     */
    private function parseCondBound(array $c): ?array
    {
        $n = count($c);
        for ($i = 0; $i < $n; $i++) {
            $op = $this->compareKind($c[$i]);
            if ($op === null) {
                $t = $c[$i];
                if (is_array($t) && $t[0] === T_IS_NOT_EQUAL) {
                    $op = 'ne';
                } elseif (is_array($t) && $t[0] === T_IS_EQUAL) {
                    $op = 'eq';
                } else {
                    continue;
                }
            }
            $l = Parser::prevSignificant($c, $i);
            $r = $this->nextSignificant($c, $i, 1);
            if ($l < 0 || $r < 0) {
                continue;
            }
            $lt = $c[$l] ?? null;
            $rt = $c[$r] ?? null;
            if (is_array($lt) && $lt[0] === T_VARIABLE && is_array($rt) && $rt[0] === T_LNUMBER) {
                $v = $this->intLiteralValue($rt[1]);
                if ($v !== null) {
                    return [$lt[1], $op, $v];
                }
            }
            if (is_array($rt) && $rt[0] === T_VARIABLE && is_array($lt) && $lt[0] === T_LNUMBER) {
                $v = $this->intLiteralValue($lt[1]);
                if ($v !== null) {
                    return [$rt[1], $this->flipOp($op), $v];
                }
            }
        }
        return null;
    }



    private function flipOp(string $op): string
    {
        return ['lt' => 'gt', 'gt' => 'lt', 'lte' => 'gte', 'gte' => 'lte', 'ne' => 'ne', 'eq' => 'eq'][$op] ?? $op;
    }



    /** init 段里的 `$var = <int>` */
    private function parseInitAssign(array $init, string $var): ?int
    {
        $sig = [];
        foreach ($init as $t) {
            if (!$this->isWs($t)) {
                $sig[] = $t;
            }
        }
        $c = count($sig);
        for ($i = 0; $i + 2 < $c + 1; $i++) {
            if (!isset($sig[$i + 1], $sig[$i + 2])) {
                break;
            }
            $a = $sig[$i];
            if (!is_array($a) || $a[0] !== T_VARIABLE || $a[1] !== $var) {
                continue;
            }
            if (!is_string($sig[$i + 1]) || $sig[$i + 1] !== '=') {
                continue;
            }
            $v = $sig[$i + 2];
            if (is_array($v) && $v[0] === T_LNUMBER) {
                return $this->intLiteralValue($v[1]);
            }
        }
        return null;
    }



    /** step 段里的 `$var++` / `++$var` / `$var += N` */
    private function parseStepDelta(array $step, string $var): ?int
    {
        $sig = [];
        foreach ($step as $t) {
            if (!$this->isWs($t)) {
                $sig[] = $t;
            }
        }
        $c = count($sig);
        for ($i = 0; $i < $c; $i++) {
            $t = $sig[$i];
            // ++$var / --$var
            if (is_array($t) && ($t[0] === T_INC || $t[0] === T_DEC) && isset($sig[$i + 1])
                && is_array($sig[$i + 1]) && $sig[$i + 1][0] === T_VARIABLE && $sig[$i + 1][1] === $var) {
                return $t[0] === T_INC ? 1 : -1;
            }
            // $var++ / $var--
            if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === $var && isset($sig[$i + 1])
                && is_array($sig[$i + 1]) && in_array($sig[$i + 1][0], [T_INC, T_DEC], true)) {
                return $sig[$i + 1][0] === T_INC ? 1 : -1;
            }
            // $var += N
            if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === $var && isset($sig[$i + 2])
                && is_array($sig[$i + 1]) && in_array($sig[$i + 1][0], [T_PLUS_EQUAL, T_MINUS_EQUAL], true)
                && is_array($sig[$i + 2]) && $sig[$i + 2][0] === T_LNUMBER) {
                $v = $this->intLiteralValue($sig[$i + 2][1]);
                if ($v !== null) {
                    return $sig[$i + 1][0] === T_PLUS_EQUAL ? $v : -$v;
                }
            }
        }
        return null;
    }



    /** 循环前紧邻的上一条语句若是 `$var = <int>;`，返回该整数 */
    private function findPrevAssign(array $tokens, int $i, string $var): ?int
    {
        $p = Parser::prevSignificant($tokens, $i);
        if ($p < 0 || !$this->isChar($tokens[$p] ?? null, ';')) {
            return null;
        }
        $s = $p - 1;
        $depth = 0;
        while ($s >= 0) {
            $t = $tokens[$s];
            $boundary = is_array($t)
                ? in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)
                : in_array($t, [';', '{', '}', ':'], true);
            if ($boundary && $depth === 0) {
                break;
            }
            if (is_string($t)) {
                if ($t === ')' || $t === ']' || $t === '}') {
                    $depth++;
                } elseif ($t === '(' || $t === '[' || $t === '{') {
                    $depth--;
                }
            }
            $s--;
        }
        $stmt = array_slice($tokens, $s + 1, $p - $s);
        $sig = [];
        foreach ($stmt as $t) {
            if (!$this->isWs($t)) {
                $sig[] = $t;
            }
        }
        if (count($sig) < 3) {
            return null;
        }
        if (is_array($sig[0]) && $sig[0][0] === T_VARIABLE && $sig[0][1] === $var
            && is_string($sig[1]) && $sig[1] === '='
            && is_array($sig[2]) && $sig[2][0] === T_LNUMBER) {
            return $this->intLiteralValue($sig[2][1]);
        }
        return null;
    }



    /** 由起点 / 比较符 / 边界 / 步长算出迭代数 */
    private function iterCount(int $start, string $op, int $bound, int $step): ?int
    {
        if ($step <= 0) {
            return null;
        }
        $n = null;
        switch ($op) {
            case 'lt':
                $n = (int) ceil(($bound - $start) / $step);
                break;
            case 'lte':
                $n = (int) floor(($bound - $start) / $step) + 1;
                break;
            case 'gt':
                $n = (int) ceil(($start - $bound) / $step);
                break;
            case 'gte':
                $n = (int) floor(($start - $bound) / $step) + 1;
                break;
            case 'ne':
                $d = abs($bound - $start);
                if ($d % $step !== 0) {
                    return null;
                }
                $n = (int) ($d / $step);
                break;
            default:
                return null;
        }
        return $n < 0 ? null : $n;
    }

    /**
     * 列出「每轮都会求值」且迭代数不可忽略（估不出，或 > 阈值）的区间。
     *
     * 只返回 cond / step / body 三段（foreach 只有 body）：这三段之外的部分
     * （for 的 init、foreach 的数组表达式）每次循环只求值一次，不需要降级。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,array{0:int,1:int}>
     */
    public function bigLoopRanges(array $tokens, int $threshold): array
    {
        $ranges = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $loop = $this->parseLoop($tokens, $i);
            if ($loop === null) {
                continue;
            }
            $est = $this->estimateIterations($tokens, $loop);
            if ($est !== null && $est <= $threshold) {
                continue;   // 小循环：保持最强形态
            }
            if ($loop['kind'] !== 'foreach' && $loop['condStart'] >= 0) {
                $ranges[] = [$loop['condStart'], $loop['condEnd']];
            }
            if ($loop['kind'] === 'for' && $loop['stepStart'] >= 0) {
                $ranges[] = [$loop['stepStart'], $loop['stepEnd']];
            }
            $ranges[] = [$loop['bodyStart'], $loop['bodyEnd']];
            $i = $loop['end'];   // 整个循环已覆盖（body 内含嵌套循环），跳过避免重复
        }
        return $ranges;
    }



    /**
     * 比较运算符归一化：token_get_all 对单字符 < > 返回裸字符串（无对应常量），
     * 仅 <= >= == != === !== <=> 是多字符才对应 T_* 常量。
     * 这里只接受 < > <= >=（==/!=/===/!== 不改写，因 <=> 对对象抛异常会破坏 == 语义）。
     * @param mixed $tok
     */
    private function compareKind($tok): ?string
    {
        if (is_string($tok)) {
            if ($tok === '<') {
                return 'lt';
            }
            if ($tok === '>') {
                return 'gt';
            }
            return null;
        }
        if (is_array($tok)) {
            return match ($tok[0]) {
                T_IS_SMALLER_OR_EQUAL => 'lte',
                T_IS_GREATER_OR_EQUAL => 'gte',
                default => null,
            };
        }
        return null;
    }



    /** 字面量原始写法 → int；超长（可能溢出）与非十进制前缀写法外一律 null */
    private function intLiteralValue(string $raw): ?int
    {
        $s = str_replace('_', '', $raw);
        if ($s === '' || strlen($s) > 18) {
            return null;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $s, $m)) {
            return (int) hexdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/', $s, $m)) {
            return (int) bindec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/', $s, $m)) {
            return (int) octdec($m[1]);
        }
        if (preg_match('/^0([0-7]+)$/', $s, $m)) {
            return (int) octdec($m[1]);
        }
        if (preg_match('/^[0-9]+$/', $s)) {
            return (int) $s;
        }
        return null;
    }

    /**
     * 下标是否落在任一区间内（闭区间，按 token 下标）。
     * @param array<int,array{0:int,1:int}> $ranges
     */
    protected function inRanges(int $i, array $ranges): bool
    {
        foreach ($ranges as $r) {
            if ($i >= $r[0] && $i <= $r[1]) {
                return true;
            }
        }
        return false;
    }
}
