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

if (!class_exists('ConstEval', false)) {
    require_once __DIR__ . '/../Support/ConstEval.php';
}

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
        // v1.2.8：先把"纯计算循环"在构建期真实求值并折叠成结果字面量，直接消掉运行时的
        // 20 万次迭代。必须最先做——此时 token 仍是原始形态；且在表达式折叠之前，避免
        // 折叠后的字面量再被本类的表达式 pass 二次处理（无副作用但无意义）。
        $tokens = $this->foldLoops($tokens, $cfg);
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

    // =====================================================================
    // v1.2.8：构建期纯计算循环折叠（loop_fold）
    //
    // 目标：把 for/while 里"只用字面量 + 已初始化局部变量 + 纯算术/位运算/比较 +
    // if/else + intdiv"的确定性循环，在构建期用【静态符号执行 ConstEval】求值一遍，
    // 读取各受影响变量的终值，把整个循环替换成 `变量 = 终值;` 序列。运行时不再跑
    // 那 20 万次迭代。
    //
    // v1.2.8 安全升级：彻底弃用 eval——在线混淆服务器场景下，eval(用户上传代码的
    // token) = 免费 webshell 注入器。改由 ConstEval 自解析 token + 调用 PHP 原生运算
    // 运算符求值：既杜绝任意代码执行，又保留 PHP 的优先级/溢出/负数取模/intdiv 语义。
    // 安全性靠四道闸：①严格 token 白名单（任何可疑构造整段放弃）；②循环变量必须在
    // 循环体之外以字面量初始化；③ConstEval 全程只认白名单运算，任一处无法安全求值
    // 即整段原样保留；④迭代次数 <= 上限（防构建期卡死）+ 两次执行严格比对（=== 含类型）。
    // =====================================================================

    /** 构建期循环折叠的最大迭代次数（超过即放弃，避免构建期被超大循环拖死） */
    private const LOOP_FOLD_MAX_ITER = 1000000;

    /** 循环体允许出现的纯函数（用真实 eval 执行，但白名单只放无副作用的确定性函数） */
    private const LOOP_FOLD_PURE_FNS = ['intdiv' => true];

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function foldLoops(array $tokens, Config $cfg): array
    {
        if (!$cfg->get('loop_fold', true)) {
            return $tokens;
        }
        $n = count($tokens);
        $out = [];
        $folded = 0;
        $i = 0;
        while ($i < $n) {
            $hit = null;
            if (Parser::isToken($tokens[$i]) && $tokens[$i][0] === T_FOR) {
                $hit = $this->tryFoldLoop($tokens, $i, 'for');
            } elseif (Parser::isToken($tokens[$i]) && $tokens[$i][0] === T_WHILE) {
                $hit = $this->tryFoldLoop($tokens, $i, 'while');
            }
            if ($hit !== null) {
                // $hit = ['end'=>int, 'assign'=>[name=>value]]，用字面量赋值替换整个循环
                foreach ($hit['assign'] as $name => $value) {
                    $line = 1;
                    $src = $tokens[$i];
                    if (Parser::isToken($src) && isset($src[2])) {
                        $line = (int) $src[2];
                    }
                    $out[] = [T_VARIABLE, '$' . $name, $line];
                    $out[] = '=';
                    $out[] = $this->literalToken($value, $src);
                    $out[] = ';';
                }
                $i = $hit['end'] + 1; // 跳过循环体末尾 '}' 及其后的空白由下一轮自然处理
                $folded++;
                continue;
            }
            $out[] = $tokens[$i];
            $i++;
        }
        if ($folded > 0) {
            $this->logger->debug(sprintf('loopfold: 构建期折叠 %d 处纯计算循环', $folded));
        }
        return $out;
    }

    /**
     * 尝试折叠从 $i 起始的一个 for/while 循环。
     * 返回 null 表示放弃（整段原样保留）。
     *
     * @param array<int,mixed> $tokens
     * @return array{end:int,assign:array<string,int|float|string>}|null
     */
    private function tryFoldLoop(array $tokens, int $i, string $kind): ?array
    {
        $n = count($tokens);
        // 1) 收集循环前的字面量赋值 $v = <literal>;（作为初始状态），并定位循环头部
        $init = [];
        $j = $i - 1;
        while ($j >= 0) {
            $prev = $this->skipTriviaBack($tokens, $j);
            if ($prev < 0 || !$this->isCharTok($tokens[$prev], ';')) {
                break;
            }
            // 形态：$var = literal ;
            $litIdx = $this->skipTriviaBack($tokens, $prev - 1);
            $eqIdx = $this->skipTriviaBack($tokens, $litIdx - 1);
            $varIdx = $this->skipTriviaBack($tokens, $eqIdx - 1);
            if ($litIdx < 0 || $eqIdx < 0 || $varIdx < 0) {
                break;
            }
            if (!$this->isCharTok($tokens[$eqIdx], '=')
                || !Parser::isToken($tokens[$varIdx]) || $tokens[$varIdx][0] !== T_VARIABLE
                || !$this->isLiteralTok($tokens[$litIdx])) {
                break;
            }
            $name = substr((string) $tokens[$varIdx][1], 1);
            if (array_key_exists($name, $init)) {
                break; // 重复初始化，放弃以保证可判定
            }
            $val = $this->literalValue($tokens[$litIdx]);
            if ($val === null) {
                break;
            }
            $init[$name] = $val;
            $j = $varIdx - 1;
        }

        // 2) 定位循环体 '{ ... }' 与循环头
        $open = $this->findNextChar($tokens, $i, '{');
        if ($open === null || $open >= $n) {
            return null;
        }
        $close = $this->matchBrace($tokens, $open);
        if ($close === null || $close >= $n) {
            return null;
        }
        // 循环头 = ($i .. open-1)，必须是 for(...) / while(...) 的括号包裹条件
        $headStart = $this->skipTrivia($tokens, $i + 1);
        if ($headStart >= $n || !$this->isCharTok($tokens[$headStart], '(')) {
            return null;
        }
        $headEnd = $this->matchParen($tokens, $headStart);
        if ($headEnd === null || $headEnd >= $open) {
            return null;
        }

        // 3) token 白名单：语句段（init 赋值之后到循环结束）内不允许任何可疑构造
        $scanFrom = $j + 1; // 最后一条被纳入的初始化之后
        if (!$this->loopTokensAllowed($tokens, $scanFrom, $close)) {
            return null;
        }

        // 4) v1.2.8：改用【静态符号执行】——绝不 eval 用户上传代码的 token。
        //    初始 env 取循环前的字面量赋值 $init；执行片段为循环语句本身（$i..$close），
        //    ConstEval 用 PHP 原生运算符完成求值，语义与真实执行逐字节一致。
        $names = array_keys($init);
        if ($names === []) {
            return null; // 没有任何字面量初始化的循环不折叠（无法安全求值）
        }
        $loopTokens = [];
        for ($k = $i; $k <= $close; $k++) {
            $loopTokens[] = $tokens[$k];
        }

        // 5) 静态迭代上界判定 + 找出循环变量名。循环变量（for 头 c1 段的 $i）在循环
        //    结束后仍可见，必须连同外部 init 变量一起回写，否则破坏 liveness。
        $loopVar = $this->loopVariable($tokens, $headStart, $headEnd, $kind);
        $iter = $this->estimateIterations($tokens, $headStart, $headEnd, $kind, $init, $loopVar);
        if ($iter === null || $iter < 0 || $iter > self::LOOP_FOLD_MAX_ITER) {
            return null;
        }
        // 待回写变量 = 外部 init 变量 + 循环控制变量（去重）
        $writeNames = $names;
        if ($loopVar !== null && !in_array($loopVar, $writeNames, true)) {
            $writeNames[] = $loopVar;
        }

        // 6) 静态执行两次并严格比对（含类型），杜绝偶发/副作用不一致。
        //    任一处无法安全求值 → ConstEval 返回 false → 整段原样保留（绝不改炸）。
        $env1 = $init;
        if (!ConstEval::execStatements($loopTokens, $env1, self::LOOP_FOLD_MAX_ITER)) {
            return null;
        }
        $env2 = $init;
        if (!ConstEval::execStatements($loopTokens, $env2, self::LOOP_FOLD_MAX_ITER)) {
            return null;
        }
        $r1 = [];
        $r2 = [];
        foreach ($writeNames as $nm) {
            if (!array_key_exists($nm, $env1) || !array_key_exists($nm, $env2)) {
                return null;
            }
            $r1[$nm] = $env1[$nm];
            $r2[$nm] = $env2[$nm];
        }
        if ($r1 !== $r2) {
            return null;
        }

        // 7) 只回写"执行后值仍为标量字面量"的变量；出现数组/对象等一律放弃整段
        $assign = [];
        foreach ($r1 as $nm => $val) {
            if (!(is_int($val) || is_float($val) || is_string($val))) {
                return null;
            }
            if (is_float($val) && !is_finite($val)) {
                return null;
            }
            $assign[$nm] = $val;
        }
        if ($assign === []) {
            return null;
        }
        return ['end' => $close, 'assign' => $assign];
    }

    /**
     * 估算循环迭代次数（仅字面量边界 + 简单自增/自减）。无法判定返回 null。
     * @param array<int,mixed> $tokens
     * @param array<string,int|float|string> $init
     */
    /**
     * 找出循环控制变量名：for 取 c1 段 `$v=...` 的变量；while 取条件左侧 `$v`。
     * @param array<int,mixed> $tokens
     */
    private function loopVariable(array $tokens, int $open, int $close, string $kind): ?string
    {
        $txt = [];
        for ($k = $open + 1; $k < $close; $k++) {
            if (Parser::isWhitespaceOrComment($tokens[$k])) {
                continue;
            }
            $txt[] = Parser::text($tokens[$k]);
        }
        if ($kind === 'while') {
            if (count($txt) >= 1 && $txt[0][0] === '$') {
                return substr($txt[0], 1);
            }
            return null;
        }
        // for: 第一段形如 $v = literal (, ...)?，取第一个变量
        $first = [];
        foreach ($txt as $t) {
            if ($t === ';') {
                break;
            }
            $first[] = $t;
        }
        if (count($first) >= 1 && $first[0][0] === '$') {
            return substr($first[0], 1);
        }
        return null;
    }

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,int|float|string> $init
     */
    private function estimateIterations(array $tokens, int $open, int $close, string $kind, array $init, ?string $loopVar): ?int
    {
        // 收集头部内有效 token 文本
        $txt = [];
        for ($k = $open + 1; $k < $close; $k++) {
            if (Parser::isWhitespaceOrComment($tokens[$k])) {
                continue;
            }
            $txt[] = Parser::text($tokens[$k]);
        }
        if ($kind === 'while') {
            // while 无显式 step，无法安全估算终止，一律放弃 while 折叠（保守）。
            // 保留该分支仅为将来扩展；当前返回 null 表示原样保留。
            return null;
        }
        // for(init;cond;step)：按 ';' 切成三段
        $segs = [];
        $cur = [];
        foreach ($txt as $t) {
            if ($t === ';') {
                $segs[] = $cur;
                $cur = [];
            } else {
                $cur[] = $t;
            }
        }
        $segs[] = $cur;
        if (count($segs) !== 3) {
            return null;
        }
        [$c1, $c2, $c3] = $segs;
        // cond: $v <op> literal
        if (count($c2) !== 3 || $c2[0][0] !== '$' || !in_array($c2[1], ['<', '<=', '>', '>=', '!=', '!=='], true)) {
            return null;
        }
        $name = substr($c2[0], 1);
        if ($loopVar === null || $name !== $loopVar) {
            return null;
        }
        // 起始值必须从 for 头 c1 段 `$i = literal` 取（循环变量在 for 内部初始化），
        // 外部 init 只含被累加变量（如 $sum），不能拿来当循环变量起点。
        if (count($c1) < 3 || $c1[0] !== '$' . $name || $c1[1] !== '=') {
            return null;
        }
        $start = $this->numVal($c1[2]);
        if ($start === null) {
            return null;
        }
        $bound = $this->numVal($c2[2]);
        if ($bound === null) {
            return null;
        }
        // step: $v++ / $v-- / $v += literal / $v -= literal / $v = $v + literal
        $step = null;
        if (count($c3) === 2 && $c3[0] === '$' . $name && ($c3[1] === '++' || $c3[1] === '--')) {
            $step = $c3[1] === '++' ? 1 : -1;
        } elseif (count($c3) === 3 && $c3[0] === '$' . $name && in_array($c3[1], ['+=', '-='], true) && is_numeric($c3[2])) {
            $d = $this->numVal($c3[2]);
            if ($d === null) {
                return null;
            }
            $step = $c3[1] === '+=' ? $d : -$d;
        } elseif (count($c3) === 5 && $c3[0] === '$' . $name && $c3[1] === '=' && $c3[2] === '$' . $name
            && in_array($c3[3], ['+', '-'], true) && is_numeric($c3[4])) {
            $d = $this->numVal($c3[4]);
            if ($d === null) {
                return null;
            }
            $step = $c3[3] === '+' ? $d : -$d;
        }
        if ($step === null || $step == 0 || !is_int($step)) {
            return null;
        }
        if (!is_int($start) || !is_int($bound)) {
            return null;
        }
        $op = $c2[1];
        $iter = $this->countSteps($start, $bound, $step, $op);
        return $iter;
    }

    /** 计算从 $start 按 $step 递增、满足 $start $op $bound 的迭代次数；不可判定返回 null */
    private function countSteps(int $start, int $bound, int $step, string $op): ?int
    {
        if ($op === '!=' || $op === '!==') {
            return null; // 边界不可静态确定，放弃
        }
        if ($step > 0) {
            if ($op === '<') {
                $last = $bound - 1;
            } elseif ($op === '<=') {
                $last = $bound;
            } else {
                return null; // 正步长却用 > >=，可能永不终止
            }
            if ($start > $last) {
                return 0;
            }
            return intdiv($last - $start, $step) + 1;
        }
        // step < 0
        if ($op === '>') {
            $last = $bound + 1;
        } elseif ($op === '>=') {
            $last = $bound;
        } else {
            return null;
        }
        if ($start < $last) {
            return 0;
        }
        return intdiv($start - $last, -$step) + 1;
    }

    /** token 白名单扫描：循环头之后的语句段内出现任何可疑构造即返回 false */
    private function loopTokensAllowed(array $tokens, int $from, int $to): bool
    {
        $n = count($tokens);
        for ($k = $from; $k <= $to && $k < $n; $k++) {
            $t = $tokens[$k];
            if (Parser::isWhitespaceOrComment($t)) {
                continue;
            }
            if (!Parser::isToken($t)) {
                // 单字符：只放行安全的算术/比较/括号/分隔
                if (!in_array($t, ['+', '-', '*', '/', '%', '&', '|', '^', '~', '!', '<', '>',
                    '(', ')', '{', '}', ';', ',', '='], true)) {
                    return false;
                }
                continue;
            }
            $id = $t[0];
            // 比较/逻辑/赋值/自增/纯函数/关键字
            $ok = [
                T_VARIABLE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING,
                T_INC, T_DEC, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
                T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
                T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
                T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP,
                T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR,
                T_IF, T_ELSE, T_ELSEIF,
                T_SL, T_SR, T_POW, T_COALESCE,
                // 循环自身的关键字（扫描范围含循环头）：放行 for/while，其余语句体仍受白名单约束
                T_FOR, T_WHILE,
            ];
            if (in_array($id, $ok, true)) {
                continue;
            }
            // PHP 8.1+ 把表达式里的位与 `&` 词法化为数组 token（非引用位置）。
            // 此处已排除 T_FUNCTION/T_CLASS 及 `&` 引用赋值上下文，按位与放行安全。
            if (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
                && $id === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                continue;
            }
            // 函数：只有白名单纯函数允许，且必须是调用形态 name(
            if ($id === T_STRING) {
                $name = (string) $t[1];
                if (!isset(self::LOOP_FOLD_PURE_FNS[$name])) {
                    return false;
                }
                continue;
            }
            return false; // T_ECHO/RETURN/NEW/ARRAY/成员访问/引用/全局/闭包/heredoc 等一律放弃
        }
        return true;
    }

    private function skipTriviaBack(array $tokens, int $j): int
    {
        while ($j >= 0 && Parser::isWhitespaceOrComment($tokens[$j])) {
            $j--;
        }
        return $j;
    }

    private function isCharTok($t, string $c): bool
    {
        return is_string($t) && $t === $c;
    }

    private function isLiteralTok($t): bool
    {
        return Parser::isToken($t) && in_array($t[0], [T_LNUMBER, T_DNUMBER], true);
    }

    /** @return int|float|string|null */
    private function literalValue($t)
    {
        if (!Parser::isToken($t)) {
            return null;
        }
        if ($t[0] === T_LNUMBER || $t[0] === T_DNUMBER) {
            return $this->numVal((string) $t[1]);
        }
        return null;
    }

    private function phpLit($v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return $this->floatToLit($v);
        }
        return "'" . addcslashes((string) $v, "\\'") . "'";
    }

    private function findNextChar(array $tokens, int $from, string $ch): ?int
    {
        $n = count($tokens);
        for ($k = $from; $k < $n; $k++) {
            if ($this->isCharTok($tokens[$k], $ch)) {
                return $k;
            }
        }
        return null;
    }

    /** 匹配括号，返回配对右括号下标（$open 为左括号） */
    private function matchParen(array $tokens, int $open): ?int
    {
        return $this->matchPair($tokens, $open, '(', ')');
    }

    private function matchBrace(array $tokens, int $open): ?int
    {
        return $this->matchPair($tokens, $open, '{', '}');
    }

    private function matchPair(array $tokens, int $open, string $l, string $r): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($k = $open; $k < $n; $k++) {
            $t = $tokens[$k];
            if (!is_string($t)) {
                continue;
            }
            if ($t === $l) {
                $depth++;
            } elseif ($t === $r) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }
        return null;
    }
}
