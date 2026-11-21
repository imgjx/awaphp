<?php
/**
 * 静态符号执行求值器（ConstEval，v1.2.8）
 *
 * 设计动机：在线混淆服务器场景下，对用户上传代码的 token 用 eval() 求值 =
 * 免费 webshell 注入器。本类彻底弃用 eval，改为【自解析 token + 调用 PHP 原生
 * 运算符】完成求值。危险的是 `eval(任意字符串)`，不是"用 % / intdiv 算数"——
 * 故本类所有运算都直接落在 PHP 原生运算符上，语义（优先级/溢出/负数取模/
 * intdiv 负数/类型比较）天然与 PHP 逐字节一致。
 *
 * 两个入口：
 *   - evalExpr($tokens, $env)：求一个表达式的值（字面量/变量/算术/位/比较/
 *     逻辑/括号/白名单纯函数）。用于 constfold 的条件求值与 logic 的恒等式折叠。
 *   - execStatements($tokens, $env, $maxIter)：执行一段受限语句（赋值/
 *     += -= 等复合赋值/++ --/if-elseif-else/for/while），更新 $env。
 *     用于 ConstFolder 的纯计算循环折叠。
 *
 * 安全铁律（绝不改炸）：
 *   - 任何无法解析的 token、非白名单运算符/函数、除零、溢出、越界迭代、
 *     未初始化变量 → 立即返回 null/false，调用方整段原样保留；
 *   - 只接受标量字面量（int/float/string），数组/对象/资源一律拒绝；
 *   - 不调用任何用户可控函数（函数名白名单 + 参数个数白名单）；
 *   - 永不 eval、永不 include、永不触碰超全局、无副作用。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Parser.php';

final class ConstEval
{
    /** 运算符优先级 [数值越大越优先, 是否右结合]。遵循 PHP 8 优先级。 */
    private const PREC = [
        '**' => [200, true],
        '*'  => [190, false], '/' => [190, false], '%' => [190, false],
        '+'  => [180, false], '-' => [180, false],
        '<<' => [170, false], '>>' => [170, false],
        '.'  => [160, false],
        '<'  => [150, false], '<=' => [150, false], '>' => [150, false], '>=' => [150, false],
        '==' => [140, false], '!=' => [140, false], '===' => [140, false],
        '!==' => [140, false], '<=>' => [140, false], '<>' => [140, false],
        '&'  => [130, false],
        '^'  => [120, false],
        '|'  => [110, false],
        '&&' => [100, false],
        '||' => [90, false],
        '??' => [80, false],
    ];

    /** 白名单纯函数 => [最少参数, 最多参数]。全部无副作用、结果只由参数决定。 */
    private const FN_SPEC = [
        'strlen' => [1, 1], 'md5' => [1, 2], 'sha1' => [1, 2], 'crc32' => [1, 1],
        'base64_decode' => [1, 2], 'hex2bin' => [1, 1], 'str_rot13' => [1, 1],
        'strrev' => [1, 1], 'strtoupper' => [1, 1], 'strtolower' => [1, 1],
        'ord' => [1, 1], 'hexdec' => [1, 1], 'bindec' => [1, 1],
        'dechex' => [1, 1], 'decbin' => [1, 1], 'abs' => [1, 1],
        'str_repeat' => [2, 2], 'substr' => [2, 3], 'intdiv' => [2, 2],
        'intval' => [1, 2], 'floatval' => [1, 1], 'strval' => [1, 1],
        'trim' => [1, 2], 'ltrim' => [1, 2], 'rtrim' => [1, 2], 'str_pad' => [2, 4],
    ];

    /** 复合赋值运算符 => 对应的二元运算文本（'' 表示纯赋值 '='）。 */
    private const ASSIGN_OPS = [
        '=' => '', '+=' => '+', '-=' => '-', '*=' => '*', '/=' => '/', '%=' => '%',
        '.=' => '.', '<<=' => '<<', '>>=' => '>>', '&=' => '&', '^=' => '^', '|=' => '|',
        '**=' => '**', '??=' => '??',
    ];

    /** 数组 token id => 运算符文本 */
    private const TOK_OP = [
        T_SL => '<<', T_SR => '>>', T_POW => '**',
        T_IS_EQUAL => '==', T_IS_NOT_EQUAL => '!=', T_IS_IDENTICAL => '===',
        T_IS_NOT_IDENTICAL => '!==', T_IS_SMALLER_OR_EQUAL => '<=',
        T_IS_GREATER_OR_EQUAL => '>=', T_SPACESHIP => '<=>',
        T_BOOLEAN_AND => '&&', T_BOOLEAN_OR => '||', T_COALESCE => '??',
        T_LOGICAL_AND => '&&', T_LOGICAL_OR => '||',
    ];

    /** PHP 8.1+ 单独的 '&' 在不跟变量时会成为特殊数组 token，统一映射为 '&'。 */
    private const AMP_TOKEN = PHP_VERSION_ID >= 80100
        ? T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG : 0;

    private const MAX_INT = 9007199254740991; // 2^53-1，超过则转 float 会丢精度
    private const MAX_STR = 8192;

    // =====================================================================
    // 公开入口
    // =====================================================================

    /**
     * 求一个表达式的值。$env 为变量名(不带$)到标量值的映射（可为空数组）。
     * 返回 null 表示无法安全求值（调用方应整段放弃）。
     *
     * @param array<int,mixed> $tokens
     * @param array<string,int|float|string> $env
     * @return int|float|string|bool|null
     */
    public static function evalExpr(array $tokens, array &$env): mixed
    {
        $p = 0;
        $v = self::parseExpr($tokens, $p, $env, 0);
        if ($v === null) {
            return null;
        }
        $p = self::skipWs($tokens, $p);
        if ($p !== count($tokens)) {
            return null; // 表达式没吃到结尾 → 有无法解析的残留，放弃
        }
        return $v;
    }

    /**
     * 执行一段受限语句序列，就地更新 $env。返回 false 表示无法安全执行
     * （调用方必须丢弃本次 $env、整段原样保留）。
     *
     * @param array<int,mixed> $tokens
     * @param array<string,int|float|string> $env
     */
    public static function execStatements(array $tokens, array &$env, int $maxIter): bool
    {
        $p = 0;
        $steps = 0;
        return self::execBlock($tokens, $p, $env, $maxIter, $steps);
    }

    // =====================================================================
    // 表达式解析（Pratt）
    // =====================================================================

    /** @return int|float|string|bool|null */
    private static function parseExpr(array $t, int &$p, array &$env, int $minPrec): mixed
    {
        $left = self::parsePrimary($t, $p, $env);
        if ($left === null) {
            return null;
        }
        while (true) {
            $q = self::skipWs($t, $p);
            $op = self::opAt($t, $q);
            if ($op === null) {
                break;
            }
            [$txt, $len, $prec, $rightAssoc] = $op;
            if ($prec < $minPrec) {
                break;
            }
            $p = $q + $len;
            $right = self::parseExpr($t, $p, $env, $rightAssoc ? $prec : $prec + 1);
            if ($right === null) {
                return null;
            }
            $left = self::apply($txt, $left, $right);
            if ($left === null) {
                return null;
            }
        }
        return $left;
    }

    /** @return int|float|string|bool|null */
    private static function parsePrimary(array $t, int &$p, array &$env): mixed
    {
        $p = self::skipWs($t, $p);
        if ($p >= count($t)) {
            return null;
        }
        $tok = $t[$p];
        $txt = Parser::text($tok);

        // 括号子表达式
        if ($txt === '(') {
            $p++;
            $v = self::parseExpr($t, $p, $env, 0);
            if ($v === null) {
                return null;
            }
            $p = self::skipWs($t, $p);
            if (($t[$p] ?? null) === null || Parser::text($t[$p]) !== ')') {
                return null;
            }
            $p++;
            return $v;
        }

        // 一元运算符：! ~ - +。操作数 minPrec=190（吃掉 ** 等更高优先级，
        // 与 PHP 中 -2**2 === -(2**2) 一致），但 `*`/`/` 同级会被吃入，
        // 结果与 PHP 的 ((-a)*b) 等价，安全。
        if ($txt === '!' || $txt === '~' || $txt === '-' || $txt === '+') {
            $p++;
            $v = self::parseExpr($t, $p, $env, 190);
            if ($v === null) {
                return null;
            }
            if ($txt === '!') {
                return !$v;
            }
            if (!is_int($v) && !is_float($v)) {
                return null;
            }
            if ($txt === '-') {
                $v = -$v;
            } elseif ($txt === '~') {
                if (!is_int($v)) {
                    return null;
                }
                $v = ~$v;
            }
            return self::safeScalar($v);
        }

        // 变量：从 $env 读取（必须已初始化）
        if (Parser::isToken($tok) && $tok[0] === T_VARIABLE) {
            $name = substr((string) $tok[1], 1);
            $p++;
            if (!array_key_exists($name, $env)) {
                return null; // 未初始化 → 放弃
            }
            return $env[$name];
        }

        // 数字字面量
        if (Parser::isToken($tok) && ($tok[0] === T_LNUMBER || $tok[0] === T_DNUMBER)) {
            $p++;
            $v = self::numVal($txt);
            return $v === null ? null : self::safeScalar($v);
        }

        // 字符串字面量
        if (Parser::isToken($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $p++;
            return self::strVal($txt);
        }

        // 白名单纯函数调用
        if (Parser::isToken($tok) && $tok[0] === T_STRING && isset(self::FN_SPEC[(string) $tok[1]])) {
            return self::parseCall($t, $p, $env, (string) $tok[1]);
        }

        // 'true' / 'false' / 'null' 常量（T_STRING）
        if (Parser::isToken($tok) && $tok[0] === T_STRING) {
            $low = strtolower($txt);
            if ($low === 'true') { $p++; return true; }
            if ($low === 'false') { $p++; return false; }
            // null 在此求值器里与普通失败同值，直接放弃以避免歧义
        }

        return null;
    }

    /** 解析白名单函数调用 */
    private static function parseCall(array $t, int &$p, array &$env, string $fn): mixed
    {
        [$min, $max] = self::FN_SPEC[$fn];
        $p++; // skip name
        $p = self::skipWs($t, $p);
        if (($t[$p] ?? null) === null || Parser::text($t[$p]) !== '(') {
            return null;
        }
        $p++;
        $args = [];
        $p = self::skipWs($t, $p);
        if (($t[$p] ?? null) !== null && Parser::text($t[$p]) === ')') {
            $p++;
        } else {
            while (true) {
                $v = self::parseExpr($t, $p, $env, 0);
                if ($v === null) {
                    return null;
                }
                $args[] = $v;
                $p = self::skipWs($t, $p);
                $sep = $t[$p] ?? null;
                if ($sep === null) {
                    return null;
                }
                if (Parser::text($sep) === ',') {
                    $p++;
                    continue;
                }
                if (Parser::text($sep) === ')') {
                    $p++;
                    break;
                }
                return null;
            }
        }
        if (count($args) < $min || count($args) > $max) {
            return null;
        }
        try {
            $v = call_user_func_array($fn, $args);
        } catch (Throwable) {
            return null;
        }
        if (!(is_int($v) || is_float($v) || is_string($v) || is_bool($v))) {
            return null;
        }
        return self::safeScalar($v);
    }

    /**
     * 识别位置 $p 处的运算符，返回 [文本, 占 token 数, 优先级, 右结合]，否则 null。
     * 同时处理"被 token_get_all 合成数组 token"与"拆成多个字符 token"两种情况。
     */
    private static function opAt(array $t, int $p): ?array
    {
        $n = count($t);
        if ($p >= $n) {
            return null;
        }
        // 数组 token 映射
        if (Parser::isToken($t[$p])) {
            $id = $t[$p][0];
            if (isset(self::TOK_OP[$id])) {
                $txt = self::TOK_OP[$id];
                [$prec, $right] = self::PREC[$txt];
                return [$txt, 1, $prec, $right];
            }
            if (self::AMP_TOKEN !== 0 && $id === self::AMP_TOKEN) {
                return ['&', 1, self::PREC['&'][0], false];
            }
            return null;
        }
        // 字符 token：先试双字符拼接（如 '<' + '=' => '<='）
        if ($p + 1 < $n && !Parser::isToken($t[$p + 1])) {
            $two = Parser::text($t[$p]) . Parser::text($t[$p + 1]);
            if (isset(self::PREC[$two])) {
                [$prec, $right] = self::PREC[$two];
                return [$two, 2, $prec, $right];
            }
        }
        $one = Parser::text($t[$p]);
        if (isset(self::PREC[$one])) {
            [$prec, $right] = self::PREC[$one];
            return [$one, 1, $prec, $right];
        }
        return null;
    }

    /** 用 PHP 原生运算符应用二元运算；返回 null 表示不安全。 */
    private static function apply(string $op, $a, $b): mixed
    {
        // 逻辑/比较/合并运算：交给 PHP 原生，支持任意标量
        switch ($op) {
            case '&&': return $a && $b;
            case '||': return $a || $b;
            case '??': return $a ?? $b;
            case '<':  return $a < $b;
            case '<=': return $a <= $b;
            case '>':  return $a > $b;
            case '>=': return $a >= $b;
            case '==': return $a == $b;
            case '!=': return $a != $b;
            case '<>': return $a <> $b;
            case '===': return $a === $b;
            case '!==': return $a !== $b;
            case '<=>': return $a <=> $b;
        }
        // 字符串拼接
        if ($op === '.') {
            if (!is_string($a) || !is_string($b)) {
                return null;
            }
            $r = $a . $b;
            return strlen($r) <= self::MAX_STR ? $r : null;
        }
        // 其余算术/位运算只接受数字
        if ((!is_int($a) && !is_float($a)) || (!is_int($b) && !is_float($b))) {
            return null;
        }
        switch ($op) {
            case '+':  $r = $a + $b; break;
            case '-':  $r = $a - $b; break;
            case '*':  $r = $a * $b; break;
            case '/':  if ($b == 0) { return null; } $r = $a / $b; break;
            case '%':  if ($b == 0) { return null; } $r = (int) $a % (int) $b; break;
            case '**': $r = $a ** $b; break;
            case '<<': if (!is_int($a) || $b < 0 || $b > 62) { return null; } $r = $a << $b; break;
            case '>>': if (!is_int($a) || $b < 0 || $b > 62) { return null; } $r = $a >> $b; break;
            case '&':  $r = (int) $a & (int) $b; break;
            case '|':  $r = (int) $a | (int) $b; break;
            case '^':  $r = (int) $a ^ (int) $b; break;
            default:   return null;
        }
        if (is_float($r) && !is_finite($r)) {
            return null;
        }
        if (is_int($r) && abs($r) > self::MAX_INT) {
            return null;
        }
        if (is_float($r) && abs($r) > 1.0e15) {
            return null;
        }
        return $r;
    }

    // =====================================================================
    // 语句执行
    // =====================================================================

    private static function execBlock(array $t, int &$p, array &$env, int $maxIter, int &$steps): bool
    {
        $n = count($t);
        while ($p < $n) {
            $p = self::skipWs($t, $p);
            if ($p >= $n) {
                break;
            }
            $txt = Parser::text($t[$p]);
            if ($txt === ';') { $p++; continue; } // 空语句
            if ($txt === '{') {                  // 嵌套块：执行到匹配的 }
                $close = self::matchChar($t, $p, '{', '}');
                if ($close === null) { return false; }
                $inner = array_values(array_slice($t, $p + 1, $close - $p - 1));
                $ip = 0;
                if (!self::execBlock($inner, $ip, $env, $maxIter, $steps)) { return false; }
                $p = $close + 1;
                continue;
            }
            if (Parser::isToken($t[$p])) {
                $id = $t[$p][0];
                if ($id === T_FOR)  { if (!self::execFor($t, $p, $env, $maxIter, $steps)) { return false; } continue; }
                if ($id === T_WHILE) { if (!self::execWhile($t, $p, $env, $maxIter, $steps)) { return false; } continue; }
                if ($id === T_IF)   { if (!self::execIf($t, $p, $env, $maxIter, $steps)) { return false; } continue; }
                if ($id === T_ELSE || $id === T_ELSEIF || $id === T_BREAK || $id === T_CONTINUE
                    || $id === T_RETURN || $id === T_ECHO || $id === T_FUNCTION || $id === T_CLASS) {
                    return false; // 控制流/声明/输出：放弃整段
                }
            }
            if (!self::execSimple($t, $p, $env)) {
                return false;
            }
        }
        return true;
    }

    /** for(init;cond;step){body} */
    private static function execFor(array $t, int &$p, array &$env, int $maxIter, int &$steps): bool
    {
        $p++; // T_FOR
        $p = self::skipWs($t, $p);
        if (Parser::text($t[$p] ?? ' ') !== '(') { return false; }
        $p++;
        $c1 = self::readUntilChar($t, $p, ';');
        if ($c1 === null) { return false; }
        $p++; // ';'
        $c2 = self::readUntilChar($t, $p, ';');
        if ($c2 === null) { return false; }
        $p++;
        $c3 = self::readUntilCloseParen($t, $p);
        if ($c3 === null) { return false; }
        $p++; // ')'
        [$bodyToks, $p] = self::readBraceBlock($t, $p);
        if ($bodyToks === null) { return false; }

        // init
        if ($c1 !== []) {
            $ip = 0;
            if (!self::execSimple($c1, $ip, $env)) { return false; }
        }
        while (true) {
            if ($steps++ > $maxIter) { return false; }
            $cond = $c2 === [] ? true : self::evalExpr($c2, $env);
            if ($cond === null) { return false; }
            if (!$cond) { break; }
            $bp = 0;
            if (!self::execBlock($bodyToks, $bp, $env, $maxIter, $steps)) { return false; }
            if ($c3 !== []) {
                $sp = 0;
                if (!self::execSimple($c3, $sp, $env)) { return false; }
            }
        }
        return true;
    }

    /** while(cond){body} */
    private static function execWhile(array $t, int &$p, array &$env, int $maxIter, int &$steps): bool
    {
        $p++; // T_WHILE
        $p = self::skipWs($t, $p);
        if (Parser::text($t[$p] ?? ' ') !== '(') { return false; }
        $p++;
        $cond = self::readUntilCloseParen($t, $p);
        if ($cond === null) { return false; }
        $p++;
        [$bodyToks, $p] = self::readBraceBlock($t, $p);
        if ($bodyToks === null) { return false; }
        while (true) {
            if ($steps++ > $maxIter) { return false; }
            $v = self::evalExpr($cond, $env);
            if ($v === null) { return false; }
            if (!$v) { break; }
            $bp = 0;
            if (!self::execBlock($bodyToks, $bp, $env, $maxIter, $steps)) { return false; }
        }
        return true;
    }

    /** if(cond){...}elseif(cond){...}else{...}：只执行命中的分支 */
    private static function execIf(array $t, int &$p, array &$env, int $maxIter, int &$steps): bool
    {
        $taken = false;
        while (true) {
            $id = Parser::isToken($t[$p] ?? null) ? $t[$p][0] : 0;
            if ($id !== T_IF && $id !== T_ELSEIF) { return false; }
            $p++;
            $p = self::skipWs($t, $p);
            if (Parser::text($t[$p] ?? ' ') !== '(') { return false; }
            $p++;
            $cond = self::readUntilCloseParen($t, $p);
            if ($cond === null) { return false; }
            $p++;
            [$block, $p] = self::readBraceBlock($t, $p);
            if ($block === null) { return false; }
            if (!$taken) {
                $v = self::evalExpr($cond, $env);
                if ($v === null) { return false; }
                if ($v) {
                    $bp = 0;
                    if (!self::execBlock($block, $bp, $env, $maxIter, $steps)) { return false; }
                    $taken = true;
                }
            }
            // 看是否还有 elseif / else
            $q = self::skipWs($t, $p);
            $qid = Parser::isToken($t[$q] ?? null) ? $t[$q][0] : 0;
            if ($qid === T_ELSEIF) { $p = $q; continue; }
            if ($qid === T_ELSE) {
                $p = self::skipWs($t, $q + 1);
                [$elseBlock, $p] = self::readBraceBlock($t, $p);
                if ($elseBlock === null) { return false; }
                if (!$taken) {
                    $bp = 0;
                    if (!self::execBlock($elseBlock, $bp, $env, $maxIter, $steps)) { return false; }
                }
            }
            return true;
        }
        return true;
    }

    /** 单条简单语句：$v = expr; / $v += expr; / $v++; / ++$v; */
    private static function execSimple(array $t, int &$p, array &$env): bool
    {
        $p = self::skipWs($t, $p);
        if ($p >= count($t)) { return false; }

        // 前缀 ++/--
        $txt = Parser::text($t[$p]);
        if ($txt === '++' || $txt === '--' || (Parser::isToken($t[$p]) && ($t[$p][0] === T_INC || $t[$p][0] === T_DEC))) {
            $delta = ($txt === '++' || (Parser::isToken($t[$p]) && $t[$p][0] === T_INC)) ? 1 : -1;
            $p = self::skipWs($t, $p + 1);
            $name = self::readVarName($t, $p);
            if ($name === null || !array_key_exists($name, $env)) { return false; }
            $v = self::incDec($env[$name], $delta);
            if ($v === null) { return false; }
            $env[$name] = $v;
            return true;
        }

        $name = self::readVarName($t, $p);
        if ($name === null) { return false; }
        $q = self::skipWs($t, $p);

        // 后缀 ++/--
        $qtxt = Parser::text($t[$q] ?? ' ');
        if ($qtxt === '++' || $qtxt === '--'
            || (Parser::isToken($t[$q] ?? null) && ($t[$q][0] === T_INC || $t[$q][0] === T_DEC))) {
            if (!array_key_exists($name, $env)) { return false; }
            $delta = ($qtxt === '++' || (Parser::isToken($t[$q]) && $t[$q][0] === T_INC)) ? 1 : -1;
            $v = self::incDec($env[$name], $delta);
            if ($v === null) { return false; }
            $env[$name] = $v;
            $p = $q + 1;
            return true;
        }

        // 赋值 / 复合赋值
        $assign = self::assignOpAt($t, $q, $opLen);
        if ($assign === null) { return false; }
        $p = $q + $opLen;
        $val = self::parseExpr($t, $p, $env, 0);
        if ($val === null) { return false; }
        if ($assign === '') {
            $new = $val; // 纯赋值允许创建新变量
        } else {
            if (!array_key_exists($name, $env)) { return false; } // 复合赋值要求已初始化
            $new = self::apply($assign, $env[$name], $val);
        }
        if ($new === null) { return false; }
        $env[$name] = self::safeScalar($new);
        return true;
    }

    /** $v ++ / -- 的标量增减 */
    private static function incDec($v, int $delta): int|float|null
    {
        if (!is_int($v) && !is_float($v)) {
            return null;
        }
        return self::safeScalar($v + $delta);
    }

    /** 识别 $q 处的赋值运算符，返回 [二元运算文本, 占token数]；'' 为纯赋值。 */
    private static function assignOpAt(array $t, int $q, ?int &$len): ?string
    {
        if (($t[$q] ?? null) === null) { return null; }
        // 数组 token（T_PLUS_EQUAL 等）或字符 '='
        if (Parser::isToken($t[$q])) {
            $txt = Parser::text($t[$q]);
            if (isset(self::ASSIGN_OPS[$txt])) { $len = 1; return self::ASSIGN_OPS[$txt]; }
            return null;
        }
        // 字符拼接（'=' 可能是单字符；'+=' 一般被合成数组 token，但兜底处理）
        if (($t[$q + 1] ?? null) !== null && !Parser::isToken($t[$q + 1])) {
            $two = Parser::text($t[$q]) . Parser::text($t[$q + 1]);
            if (isset(self::ASSIGN_OPS[$two])) { $len = 2; return self::ASSIGN_OPS[$two]; }
        }
        $one = Parser::text($t[$q]);
        if (isset(self::ASSIGN_OPS[$one])) { $len = 1; return self::ASSIGN_OPS[$one]; }
        return null;
    }

    // =====================================================================
    // token 辅助
    // =====================================================================

    private static function readVarName(array $t, int &$p): ?string
    {
        if (($t[$p] ?? null) === null || !Parser::isToken($t[$p]) || $t[$p][0] !== T_VARIABLE) {
            return null;
        }
        $name = substr((string) $t[$p][1], 1);
        $p++;
        return $name;
    }

    /** 从 $p 起读到字符 $stop（不跨括号），返回 token 数组（不含 stop）。 */
    private static function readUntilChar(array $t, int &$p, string $stop): ?array
    {
        $depth = 0;
        $out = [];
        $n = count($t);
        while ($p < $n) {
            $tok = $t[$p];
            if (!Parser::isToken($tok) && Parser::text($tok) === $stop && $depth === 0) {
                return array_values($out);
            }
            if (!Parser::isToken($tok)) {
                $x = Parser::text($tok);
                if ($x === '(' || $x === '[') { $depth++; }
                elseif ($x === ')' || $x === ']') { $depth--; }
            }
            $out[] = $tok;
            $p++;
        }
        return null;
    }

    /** 从 $p 起读到与当前 '(' 匹配的 ')'，返回括号内 token（不含括号）。 */
    private static function readUntilCloseParen(array $t, int &$p): ?array
    {
        $depth = 0;
        $out = [];
        $n = count($t);
        while ($p < $n) {
            $tok = $t[$p];
            if (!Parser::isToken($tok)) {
                $x = Parser::text($tok);
                if ($x === '(') { $depth++; $out[] = $tok; $p++; continue; }
                if ($x === ')') {
                    if ($depth === 0) { return array_values($out); }
                    $depth--; $out[] = $tok; $p++; continue;
                }
            }
            $out[] = $tok;
            $p++;
        }
        return null;
    }

    /** 读 { ... } 块，返回 [块内 token, 新的 $p（指向 } 之后）]。 */
    private static function readBraceBlock(array $t, int $p): ?array
    {
        $p = self::skipWs($t, $p);
        if (($t[$p] ?? null) === null || Parser::text($t[$p]) !== '{') {
            return null;
        }
        $close = self::matchChar($t, $p, '{', '}');
        if ($close === null) {
            return null;
        }
        return [array_values(array_slice($t, $p + 1, $close - $p - 1)), $close + 1];
    }

    private static function matchChar(array $t, int $open, string $l, string $r): ?int
    {
        $depth = 0;
        $n = count($t);
        for ($k = $open; $k < $n; $k++) {
            $tok = $t[$k];
            if (Parser::isToken($tok)) { continue; }
            $x = Parser::text($tok);
            if ($x === $l) { $depth++; }
            elseif ($x === $r) { $depth--; if ($depth === 0) { return $k; } }
        }
        return null;
    }

    private static function skipWs(array $t, int $p): int
    {
        $n = count($t);
        while ($p < $n && Parser::isWhitespaceOrComment($t[$p])) {
            $p++;
        }
        return $p;
    }

    /** 收窄为安全标量：整数不超 2^53、浮点有限、字符串不超上限。 */
    private static function safeScalar($v): int|float|string|bool|null
    {
        if (is_bool($v)) { return $v; }
        if (is_int($v)) { return abs($v) <= self::MAX_INT ? $v : null; }
        if (is_float($v)) { return is_finite($v) && abs($v) <= 1.0e15 ? $v : null; }
        if (is_string($v)) { return strlen($v) <= self::MAX_STR ? $v : null; }
        return null;
    }

    private static function numVal(string $txt): int|float|null
    {
        $txt = str_replace('_', '', $txt);
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $txt)) { return intval($txt, 16); }
        if (preg_match('/^0[bB][01]+$/', $txt)) { return intval(substr($txt, 2), 2); }
        if (preg_match('/^0[oO][0-7]+$/', $txt)) { return intval(substr($txt, 2), 8); }
        if (is_numeric($txt)) {
            $v = strpos($txt, '.') !== false || stripos($txt, 'e') !== false ? (float) $txt : (int) $txt;
            return self::safeScalar($v);
        }
        return null;
    }

    private static function strVal(string $raw): ?string
    {
        $q = $raw[0] ?? '';
        $body = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }
        if ($q === '"') {
            // 只接受无插值的简单双引号串（无 $、无 {）；否则放弃
            if (str_contains($body, '$') || str_contains($body, '{')) {
                return null;
            }
            return stripcslashes($body);
        }
        return null;
    }
}
