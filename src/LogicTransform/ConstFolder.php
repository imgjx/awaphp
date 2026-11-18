<?php
/**
 * 编译时优化（ConstFolder / constfold 层）
 *
 * 在激进逻辑变换【之前】把"简单但费力"的常量表达式提前算完，就地折叠成结果字面量：
 *     60 * 60 * 24            → 86400
 *     1024 * 1024 + 512       → 1049088
 *     'hello' . '_' . 'world' → 'hello_world'
 *     (1 << 8) | 0x0F         → 271
 *
 * 双重收益：
 *   1. 运行时不再做这些计算（省指令），且折叠结果随后仍会被 number/logic 层继续混淆；
 *   2. 干扰人工阅读——源码里看不到原始的"计算过程"，只剩一个孤立数字/字符串。
 *
 * 安全性（绝不改变语义）：
 *   - 只对【纯字面量】操作数求值；任何变量/函数调用/成员访问都会终止表达式，不折叠；
 *   - 遵循 PHP 8 运算符优先级（Pratt 解析），**右结合 ** 正确；字符串只参与 '.'，数字只参与算术/位运算，
 *     杜绝隐式类型转换的歧义；
 *   - 除零 / 取模零 / 负极位数 / 过大结果 / 过长字符串一律跳过（原样保留）；
 *   - 折叠结果始终是字面量 token，故在 const / case / 参数默认值 / 属性默认值等
 *     常量表达式上下文中同样合法，不引入任何函数调用。
 *
 * 等级 const_fold_level：
 *   1 = 仅整数 + - * 与字符串拼接
 *   2 = 增加 / % ** 与位运算 & | ^ << >>
 *   3 = 全量（同上，另允许更长的表达式链）
 */

declare(strict_types=1);

final class ConstFolder
{
    private Logger $logger;
    private int $level = 2;
    private int $folded = 0;
    /** primary() 遇到"运算符但解析不出主表达式"时置位，用于把失败向上传播（避免部分折叠破坏分组） */
    private bool $badPrim = false;
    /** 优先级：数字越大越优先；标记右结合 */
    private const OPS = [
        '**' => [200, true],
        '*'  => [190, false], '/' => [190, false], '%' => [190, false],
        '+'  => [180, false], '-' => [180, false],
        '<<' => [170, false], '>>' => [170, false],
        '.'  => [160, false],
        '&'  => [150, false], '^' => [140, false], '|' => [130, false],
    ];
    /** 一级允许的简单运算（level 1 保守集） */
    private const OPS_L1 = ['+' => true, '-' => true, '*' => true, '.' => true];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        $this->level = max(1, min(3, (int) $cfg->get('const_fold_level', 2)));
        $this->folded = 0;
        $n = count($tokens);
        if ($n === 0) {
            return $tokens;
        }
        $out = $tokens;
        // 多遍：每遍把最内层的二元折叠掉，直到无变化（如 2*3+4 → 6+4 → 10）。
        for ($pass = 0; $pass < 8; $pass++) {
            $changed = false;
            $next = [];
            for ($i = 0; $i < count($out); $i++) {
                $r = $this->tryExpr($out, $i);
                if ($r !== null && $r['last'] > $i && $r['didOp']) {
                    $next[] = $this->literalToken($r['value'], $out[$i]);
                    $i = $r['last'];
                    $changed = true;
                    $this->folded++;
                } else {
                    $next[] = $out[$i];
                }
            }
            $out = $next;
            if (!$changed) {
                break;
            }
        }
        if ($this->folded > 0) {
            $this->logger->debug(sprintf('constfold: 折叠 %d 处常量表达式', $this->folded));
        }
        return $out;
    }

    /**
     * 从位置 $i 起按优先级解析一个纯字面量表达式。
     *
     * 关键安全约束：$i 处可能位于某个更大表达式的**中间**（例如 `A . B | C`，
     * 我们从 B 的起点开始）。此时本次折叠的最小优先级必须 = 左侧运算符优先级 + 1，
     * 否则会把 `B | C` 一起吃掉，导致外层分组被破坏（`(A.B)|C` 变成 `A.(B|C)`）。
     *
     * @return array{end:int,last:int,value:mixed,didOp:bool}|null
     */
    private function tryExpr(array $tokens, int $i): ?array
    {
        if (!$this->isExprStart($tokens, $i)) {
            return null;
        }
        $p = $i;
        $this->badPrim = false;
        $prim = $this->primary($tokens, $p);
        if ($prim === null) {
            $this->badPrim = false;
            return null;
        }
        [$value, $p] = $prim;
        $r = $this->parseRest($tokens, $p, $value, $this->minPrecAt($tokens, $i), $p - 1);
        $this->badPrim = false;
        return $r['bad'] ? null : $r;
    }

    /** 该位置是否可能是"操作数起点"（排除二元运算符位置，如 `A - B` 里的 `-`）。 */
    private function isExprStart(array $tokens, int $i): bool
    {
        $t = $tokens[$i] ?? null;
        if ($t === null || Parser::isWhitespaceOrComment($t)) {
            return false;
        }
        $txt = Parser::text($t);
        // 前一个有效 token 是否为「操作数结尾」
        $j = $i - 1;
        while ($j >= 0 && Parser::isWhitespaceOrComment($tokens[$j])) {
            $j--;
        }
        $prev = $j >= 0 ? $tokens[$j] : null;
        $prevIsOperand = false;
        if ($prev !== null) {
            if (Parser::isToken($prev)) {
                $prevIsOperand = in_array($prev[0], [T_VARIABLE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_STRING], true);
            } else {
                $prevIsOperand = ($prev === ')' || $prev === ']');
            }
        }
        if ($prevIsOperand) {
            // `A - B` / `A + B` 中的符号是二元运算符，不是表达式起点
            return false;
        }
        // 只有可能作为一元/主表达式的记号才值得尝试
        if ($txt === '-' || $txt === '+' || $txt === '~' || $txt === '!') {
            return true;
        }
        if (Parser::isToken($t)) {
            return in_array($t[0], [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING], true);
        }
        return false;
    }

    /**
     * 计算位置 $i 处允许折叠的最小运算符优先级。
     * 左侧是运算符时取「其优先级 + 1」（左结合），保证不跨越外层分组。
     */
    private function minPrecAt(array $tokens, int $i): int
    {
        $j = $i - 1;
        while ($j >= 0 && Parser::isWhitespaceOrComment($tokens[$j])) {
            $j--;
        }
        if ($j < 0) {
            return 0;
        }
        $t = $tokens[$j];
        $txt = Parser::text($t);
        // 一元 + - ~ 的绑定力高于所有二元算术运算符：其右操作数不能再被更低的运算符"截走"。
        // 例如 `2 / 4 * -100 % 3`，`-100` 是一个完整的一元式，`%` 不属于它。
        if ($txt === '-' || $txt === '+' || $txt === '~') {
            $k = $j - 1;
            while ($k >= 0 && Parser::isWhitespaceOrComment($tokens[$k])) {
                $k--;
            }
            $pv = $k >= 0 ? $tokens[$k] : null;
            $pvOperand = false;
            if ($pv !== null) {
                if (Parser::isToken($pv)) {
                    $pvOperand = in_array($pv[0], [T_VARIABLE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_STRING], true);
                } else {
                    $pvOperand = ($pv === ')' || $pv === ']');
                }
            }
            if (!$pvOperand) {
                return 201; // 一元：右侧只允许 `**` 以外的一切（`**` 已被 primary 直接拒绝）
            }
            return self::OPS['-'][0] + 1;
        }
        // 数组 token 的双字符运算符（T_SL/T_SR/T_POW）统一按文本查表
        if (isset(self::OPS[$txt])) {
            return self::OPS[$txt][0] + 1;
        }
        // 其它低优先级/非算术运算符（比较、逻辑、三元、赋值、`,`、`(`、`return` 等）
        // 都视为"表达式边界"，从其右侧开始的子表达式是完整子树。
        return 0;
    }

    /** 跳过空白与注释 */
    private function skipTrivia(array $tokens, int $p): int
    {
        $n = count($tokens);
        while ($p < $n && Parser::isWhitespaceOrComment($tokens[$p])) {
            $p++;
        }
        return $p;
    }

    /** @return array{mixed,int}|null */
    private function primary(array $tokens, int $p): ?array
    {
        $n = count($tokens);
        if ($p >= $n) {
            return null;
        }
        $t = $tokens[$p];
        $id = Parser::isToken($t) ? $t[0] : 0;
        $txt = Parser::text($t);
        // 一元符号：只接受「符号 + 数字字面量」这一种最保守形态。
        // 注意 '-'/'+'/~' 都是单字符 token（非数组），必须用文本判断。
        if ($txt === '-' || $txt === '+' || $txt === '~') {
            $np = $this->skipTrivia($tokens, $p + 1);
            $t2 = $tokens[$np] ?? null;
            if (!is_array($t2) || ($t2[0] !== T_LNUMBER && $t2[0] !== T_DNUMBER)) {
                // 一元符号后不是数字字面量：说明这里还有运算（如 -$x / -'a'），
                // 我们解析不出这个操作数，必须让上层整段放弃，否则会把外层右操作数截断。
                $this->badPrim = true;
                return null;
            }
            $v = $this->numVal($t2[1]);
            if ($v === null) {
                $this->badPrim = true;
                return null;
            }
            if ($txt === '-') {
                $v = -$v;
            } elseif ($txt === '~') {
                if (!is_int($v)) {
                    $this->badPrim = true;
                    return null;
                }
                $v = ~$v;
            }
            // PHP 中 `**` 的优先级高于一元负号（`-10 ** 2` === `-(10 ** 2)`），
            // 若一元符号后紧跟 `**`，说明该一元式的真实结合方式与我们的字面量化不同，直接放弃。
            $np2 = $this->skipTrivia($tokens, $np + 1);
            if (isset($tokens[$np2]) && Parser::text($tokens[$np2]) === '**') {
                $this->badPrim = true;
                return null;
            }
            return [$v, $np + 1];
        }
        if ($id === T_LNUMBER || $id === T_DNUMBER) {
            $v = $this->numVal($txt);
            return $v === null ? null : [$v, $p + 1];
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            return [$this->strVal($txt), $p + 1];
        }
        return null;
    }

    /** @return array{end:int,last:int,value:mixed,didOp:bool,bad:bool,dangling:bool} */
    private function parseRest(array $tokens, int $p, $left, int $minPrec, int $last): array
    {
        $didOp = false;
        $n = count($tokens);
        while (true) {
            $q = $this->skipTrivia($tokens, $p);
            if ($q >= $n) {
                break;
            }
            $op = $this->opAt($tokens, $q);
            if ($op === null) {
                break;
            }
            [$opText, $prec, $rightAssoc] = $op;
            if ($prec < $minPrec) {
                break;
            }
            // 右操作数必须是一个"主字面量"，随后递归吃下更高优先级的运算符
            // （左结合用 prec+1，右结合用 prec——这样 2+3*4 得 14、2**3**2 得 512）。
            $rp = $this->skipTrivia($tokens, $q + 1);
            $prim = $this->primary($tokens, $rp);
            if ($prim === null) {
                if ($this->badPrim) {
                    // 该运算符右侧还有解析不了的运算（如 `- $x`、`- 'a'`），整段放弃。
                    return ['end' => $p, 'last' => $last, 'value' => $left, 'didOp' => $didOp, 'bad' => true, 'dangling' => true];
                }
                // 右操作数是一个不可折叠的主表达式（变量/函数/括号……）。
                // 本层运算符无法应用，因此 $left 不能当作"完整表达式"交给上层
                //（否则 `1024 >> 7 * (…)` 会被错折成 `1024 >> 7`）。
                return ['end' => $p, 'last' => $last, 'value' => $left, 'didOp' => $didOp, 'bad' => false, 'dangling' => true];
            }
            [$right, $after] = $prim;
            $sub = $this->parseRest($tokens, $after, $right, $rightAssoc ? $prec : $prec + 1, $after - 1);
            if ($sub['bad']) {
                return ['end' => $p, 'last' => $last, 'value' => $left, 'didOp' => $didOp, 'bad' => true, 'dangling' => true];
            }
            if ($sub['dangling']) {
                // 右侧子表达式没算完（例如 `7 * (…)`），本层同样不能应用
                return ['end' => $p, 'last' => $last, 'value' => $left, 'didOp' => $didOp, 'bad' => false, 'dangling' => true];
            }
            $val = $this->apply($opText, $left, $sub['value']);
            if ($val === null) {
                // 类型不符/除零/溢出。此处绝不能"部分折叠"后交给外层：
                // 该运算符右侧可能还连着更高优先级的同级运算，属于外层操作数的一部分。
                return ['end' => $p, 'last' => $last, 'value' => $left, 'didOp' => $didOp, 'bad' => true, 'dangling' => true];
            }
            $left = $val;
            $didOp = true;
            $p = $sub['end'];
            $last = $sub['last'];
        }
        return ['end' => $p, 'last' => $last, 'value' => $left, 'didOp' => $didOp, 'bad' => false, 'dangling' => false];
    }

    /** @return array{0:string,1:int,2:bool}|null */
    private function opAt(array $tokens, int $p): ?array
    {
        $n = count($tokens);
        // 先查双字符运算符
        if ($p + 1 < $n) {
            $two = Parser::text($tokens[$p]) . Parser::text($tokens[$p + 1]);
            if ($two === '<<' || $two === '>>' || $two === '**') {
                if ($this->opAllowed($two)) {
                    return [$two, self::OPS[$two][0], self::OPS[$two][1]];
                }
            }
            // token_get_all 可能把 << >> ** 合成单个数组 token
        }
        $t = $tokens[$p];
        if (Parser::isToken($t)) {
            $map = [
                T_SL => '<<', T_SR => '>>', T_POW => '**',
                T_BOOLEAN_AND => null, // 非算术，不折叠
            ];
            if (isset($map[$t[0]]) && $map[$t[0]] !== null && $this->opAllowed($map[$t[0]])) {
                $txt = $map[$t[0]];
                return [$txt, self::OPS[$txt][0], self::OPS[$txt][1]];
            }
        }
        $txt = Parser::text($t);
        if (isset(self::OPS[$txt]) && $this->opAllowed($txt)) {
            return [$txt, self::OPS[$txt][0], self::OPS[$txt][1]];
        }
        return null;
    }

    private function opAllowed(string $op): bool
    {
        if ($this->level <= 1) {
            return isset(self::OPS_L1[$op]);
        }
        return isset(self::OPS[$op]);
    }

    /** 应用二元运算；返回 null 表示不安全、放弃本次折叠。 */
    private function apply(string $op, $a, $b)
    {
        $isStrA = is_string($a);
        $isStrB = is_string($b);
        if ($op === '.') {
            if (!$isStrA || !$isStrB) {
                return null;
            }
            $r = $a . $b;
            return strlen($r) <= 8192 ? $r : null;
        }
        // 其余运算只允许数字
        if ($isStrA || $isStrB || !is_int($a) && !is_float($a) || !is_int($b) && !is_float($b)) {
            return null;
        }
        switch ($op) {
            case '+': $r = $a + $b; break;
            case '-': $r = $a - $b; break;
            case '*': $r = $a * $b; break;
            case '/':
                if ($b == 0) { return null; }
                $r = $a / $b;
                break;
            case '%':
                if ($b == 0) { return null; }
                $r = (int) $a % (int) $b;
                break;
            case '**':
                $r = $a ** $b;
                break;
            case '<<':
                if (!is_int($a) || $b < 0 || $b > 62) { return null; }
                $r = $a << $b;
                break;
            case '>>':
                if (!is_int($a) || $b < 0 || $b > 62) { return null; }
                $r = $a >> $b;
                break;
            case '&': $r = (int) $a & (int) $b; break;
            case '|': $r = (int) $a | (int) $b; break;
            case '^': $r = (int) $a ^ (int) $b; break;
            default: return null;
        }
        // 拒绝溢出 / 非有限数，避免折叠成精度丢失的字面量
        if (is_float($r) && !is_finite($r)) {
            return null;
        }
        if (is_int($r) && abs($r) > 9007199254740991) {
            return null; // 超过 2^53，转 float 会丢精度
        }
        if (is_float($r) && abs($r) > 1.0e15) {
            return null;
        }
        if ($op === '**' && (is_float($r)) && abs($r) > 1.0e15) {
            return null;
        }
        return $r;
    }

    /** @return int|float|null */
    private function numVal(string $txt)
    {
        $txt = str_replace('_', '', $txt);
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $txt)) {
            return intval($txt, 16);
        }
        if (preg_match('/^0[bB][01]+$/', $txt)) {
            return intval(substr($txt, 2), 2);
        }
        if (preg_match('/^0[oO][0-7]+$/', $txt)) {
            return intval(substr($txt, 2), 8);
        }
        if (is_numeric($txt)) {
            return strpos($txt, '.') !== false || stripos($txt, 'e') !== false ? (float) $txt : (int) $txt;
        }
        return null;
    }

    private function strVal(string $raw): string
    {
        $q = $raw[0];
        $body = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }
        // 双引号：只接受无插值的简单串（含 \n \t \\ \" 等基本转义）
        return stripcslashes($body);
    }

    /** 由结果值构造字面量 token（继承被替换区间的行号）。 */
    private function literalToken($v, $srcToken): array
    {
        $line = 1;
        if (is_array($srcToken) && isset($srcToken[2]) && is_int($srcToken[2])) {
            $line = $srcToken[2];
        }
        if (is_int($v)) {
            return [T_LNUMBER, (string) $v, $line];
        }
        if (is_float($v)) {
            return [T_DNUMBER, $this->floatToLit($v), $line];
        }
        return [T_CONSTANT_ENCAPSED_STRING, "'" . addcslashes($v, "\\'") . "'", $line];
    }

    private function floatToLit(float $v): string
    {
        $s = var_export($v, true);
        if (strpos($s, '.') === false && stripos($s, 'e') === false) {
            $s .= '.0';
        }
        return $s;
    }
}
