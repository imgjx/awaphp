<?php
/**
 * 多变恒真三分支诱饵（DecoyBranches / decoy 层，v1.2.6）
 *
 * 把一条真语句改写成「三选一」的控制流：
 *
 *   $d = strlen((string)($v ?? ''));
 *   if ($d >= 0) {                       // 恒真，但看起来像长度校验
 *       $t = $v; $s = 'abc'; echo $t . $s;   // 真分支：同理替换（变量替变量、串替串）
 *   } elseif (strlen((string)($v ?? '')) === -1) {
 *       echo $t2 . $s2;                  // 诱饵 1：形近假值，永不可达
 *   } else {
 *       echo $t3 . $s3;                  // 诱饵 2
 *   }
 *
 * 设计要点（宁可不混淆，也不改炸）：
 *   1. **条件绝不是一眼假的 `($v===$v)||($v!==$v)`**。先用一个从上下文派生的
 *      临时变量 $d 承载判断，模板按 salt 从 12 种「看似业务校验」的恒真式中选，
 *      两种结构（if/elseif/else 与 switch 双 case 命中）再随机。
 *   2. **恒真性由构造保证**：$d 的派生表达式只使用 `??` 兜底的读取 + 纯函数，
 *      其结果类型/范围在源码里就是确定的（strlen>=0、crc32&1∈{0,1}、
 *      substr 返回 string、(array) 恒为 array…），条件在这些范围上必然成立。
 *   3. **诱饵分支恒不可达**：假条件用类型/数值上不可能成立的关系（长度 = -1、
 *      count < 0、in_array 于空数组…），且不读取未定义变量、不写任何状态。
 *   4. **真分支保语义**：同理替换只做"值复制"——`$v` → `$t=$v; …$t`，
 *      `'abc'` → `$s='abc'; …$s`；写回目标、函数调用顺序、语句本身一字不改。
 *      含写回、控制流、闭包、`$this`、超全局、插值串的语句整条跳过。
 *   5. 只处理 echo/print、简单赋值、纯表达式语句（可含函数调用/方法调用）。
 *      多命名空间 / bracket 命名空间 / `__halt_compiler` 整文件跳过。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Confrontation/ConfrontRng.php';

final class DecoyBranches
{
    private Salt $salt;
    private Logger $logger;
    private int $level = 1;
    private int $ctr = 0;

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
        $this->level = max(1, min(3, (int) $cfg->get('decoy_level', 2)));
        $this->ctr = 0;
        $n = count($tokens);
        if ($n === 0 || !$this->fileIsSafe($tokens)) {
            return $tokens;
        }

        // 语句切分：只按圆括号深度定界（排除 for/declare 头里的分号）
        $stmts = [];
        $paren = 0;
        $start = -1;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $txt = Parser::text($t);
            if ($start < 0 && !Parser::isWhitespaceOrComment($t)) {
                $start = $i;
            }
            if ($txt === '(') {
                $paren++;
                continue;
            }
            if ($txt === ')') {
                $paren = max(0, $paren - 1);
                continue;
            }
            if ($paren === 0 && $txt === ';') {
                if ($start >= 0) {
                    $stmts[] = [$start, $i];
                }
                $start = -1;
            }
        }

        // 密度：等级 1/2/3 分别命中约 12% / 25% / 45% 的可选语句。
        $p = [1 => 0.12, 2 => 0.25, 3 => 0.45][$this->level];
        $replace = [];
        foreach ($stmts as [$s, $e]) {
            $rng = $this->rngFor($s);
            if ($rng->nextFloat() > $p) {
                continue;
            }
            $info = $this->analyze($tokens, $s, $e);
            if ($info === null) {
                continue;
            }
            $replace[$s] = ['end' => $e, 'tokens' => $this->tokenizeFragment($this->build($info, $rng))];
        }
        if ($replace === []) {
            return $tokens;
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset($replace[$i])) {
                // 必须是【真 token】而非整段字符串：后续 var/number/string 层要能
                // 看到并一致地改写里面的变量与字面量，否则会出现"定义改名、引用没改"
                // 的静默语义破坏（v1.2.6 实测踩过）。
                foreach ($replace[$i]['tokens'] as $tk) {
                    $out[] = $tk;
                }
                $i = $replace[$i]['end'];
                continue;
            }
            $out[] = $tokens[$i];
        }
        $this->logger->debug(sprintf('decoy: 注入 %d 个三分支诱饵（等级 %d）', count($replace), $this->level));
        return $out;
    }

    private function rngFor(int $seed): ConfrontRng
    {
        return new ConfrontRng($this->salt->derive('decoy|' . $seed . '|' . $this->ctr++, 16));
    }

    private function fileIsSafe(array $tokens): bool
    {
        $ns = 0;
        foreach ($tokens as $t) {
            if (!Parser::isToken($t)) {
                continue;
            }
            if ($t[0] === T_HALT_COMPILER) {
                return false;
            }
            if ($t[0] === T_NAMESPACE && ++$ns > 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * 只认无写回、无控制流的单语句。返回：
     * ['head'=>'echo'|'assign'|'expr', 'body'=>语句原文(不含分号),
     *  'expr'=>表达式原文, 'vars'=>[变量名], 'strs'=>[字面量原文]]
     */
    private function analyze(array $tokens, int $s, int $e): ?array
    {
        $k = -1;
        for ($i = $s; $i <= $e; $i++) {
            if (!Parser::isWhitespaceOrComment($tokens[$i])) {
                $k = $i;
                break;
            }
        }
        if ($k < 0 || !Parser::isToken($tokens[$k])) {
            return null;
        }
        $id = $tokens[$k][0];
        // 【v1.2.6 整站运行实测修复】只对 echo/print 做诱饵。
        // 真分支用「同理替换」（变量拷进新局部变量再用），这对【纯读】的 echo 永远安全；
        // 但对任何【写回】语句都是灾难：`$users[] = $u` 拷成 `$tmp=$users; $tmp[]=$u2`
        // 只改了数组副本，原数组不变 —— complex_app 里 Alice/Bob 就是这样丢的。
        // 赋值 / expr 类语句曾因「首 token 是 T_VARIABLE 但下一 token 是 `[`」漏进 expr
        // 分支被改写。这里一律拒绝：宁可少打散，绝不改炸。
        if ($id !== T_ECHO && $id !== T_PRINT) {
            return null;
        }
        $head = 'echo';
        $exprStart = $k + 1;

        $vars = [];
        $strs = [];
        for ($i = $exprStart; $i < $e; $i++) {
            $t = $tokens[$i];
            if (Parser::isToken($t)) {
                $tid = $t[0];
                if ($tid === T_VARIABLE) {
                    $nm = (string) $t[1];
                    if ($nm === '$this' || strncmp($nm, '$GLOBALS', 8) === 0
                        || preg_match('/^\$_(SERVER|GET|POST|FILES|COOKIE|SESSION|ENV|REQUEST)$/', $nm)) {
                        return null;
                    }
                    $vars[$nm] = $nm;
                    continue;
                }
                if ($tid === T_CONSTANT_ENCAPSED_STRING) {
                    if (strlen((string) $t[1]) > 48) {
                        return null; // 超长串不做形近派生
                    }
                    $strs[] = (string) $t[1];
                    continue;
                }
                if ($tid === T_STRING && in_array(strtolower((string) $t[1]),
                    ['isset', 'empty', 'eval', 'exit', 'die', 'unset', 'clone', 'new', 'yield', 'match'], true)) {
                    return null;
                }
                // 赋值 / 引用 / 控制流 / 闭包 / 插值 / 魔术常量 一律放弃
                if (in_array($tid, [
                    T_INC, T_DEC, T_FUNCTION, T_FN, T_YIELD, T_YIELD_FROM, T_RETURN,
                    T_BREAK, T_CONTINUE, T_GOTO, T_EXIT, T_THROW, T_GLOBAL, T_STATIC,
                    T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL,
                    T_UNSET, T_ISSET, T_EMPTY, T_LIST, T_MATCH, T_CLONE, T_NEW,
                    T_LINE, T_FILE, T_DIR, T_CLASS_C, T_TRAIT_C, T_METHOD_C, T_FUNC_C, T_NS_C,
                    T_START_HEREDOC, T_END_HEREDOC, T_ENCAPSED_AND_WHITESPACE,
                    T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE,
                    T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
                ], true)) {
                    return null;
                }
                // 复合赋值 token
                if (in_array($tid, [
                    T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
                    T_CONCAT_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL,
                    T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
                ], true)) {
                    return null;
                }
                continue;
            }
            $txt = (string) $t;
            if ($txt === '&' || $txt === '@') {
                return null;
            }
        }
        $expr = trim($this->slice($tokens, $exprStart, $e - 1));
        if ($expr === '' || ($vars === [] && $strs === [])) {
            return null; // 没有任何可同理替换的元素，不值得打散
        }
        return [
            'head' => $head,
            'body' => $this->slice($tokens, $s, $e),
            'expr' => $expr,
            'vars' => array_values($vars),
            'strs' => $strs,
            'target' => $head === 'assign' ? Parser::text($tokens[$k]) : '',
        ];
    }

    /** 同理替换：变量→新变量，字符串→新字符串变量；两者都在真分支内先赋值。 */
    private function realBranch(array $info, ConfrontRng $rng, array &$names): string
    {
        $expr = $info['expr'];
        $prefix = '';
        $map = [];
        foreach ($info['vars'] as $v) {
            $nv = $this->newName('v', $names);
            $map[$v] = $nv;
            $prefix .= $nv . '=' . $v . ';';
        }
        foreach ($info['strs'] as $lit) {
            $nv = $this->newName('s', $names);
            // 用原字面量精确替换（只替换第一次出现，避免同串多处误伤）
            $pos = strpos($expr, $lit);
            if ($pos !== false) {
                $expr = substr($expr, 0, $pos) . $nv . substr($expr, $pos + strlen($lit));
            }
            $prefix .= $nv . '=' . $lit . ';';
        }
        foreach ($map as $v => $nv) {
            $expr = preg_replace('/\Q' . $v . '\E\b/', $nv, $expr) ?? $expr;
        }
        if ($info['head'] === 'assign') {
            return $prefix . $info['target'] . '=' . $expr . ';';
        }
        if ($info['head'] === 'echo') {
            return $prefix . 'echo ' . $expr . ';';
        }
        return $prefix . $expr . ';';
    }

    /** 诱饵分支：形近变量 + 形近字符串，永不执行。 */
    private function decoyBranch(array $info, array $names, int $variant): string
    {
        $expr = $info['expr'];
        foreach ($info['vars'] as $i => $v) {
            $fake = ($names['v' . ($i + 1)] ?? $v) . (($variant & 1) ? 'q' : 'z');
            $expr = preg_replace('/\Q' . $v . '\E\b/', $fake, $expr) ?? $expr;
        }
        foreach ($info['strs'] as $lit) {
            $expr = preg_replace('/' . preg_quote($lit, '/') . '/', $this->mutateLiteral($lit, $variant), $expr, 1) ?? $expr;
        }
        if ($info['head'] === 'assign') {
            return $info['target'] . '=' . $expr . ';';
        }
        if ($info['head'] === 'echo') {
            return 'echo ' . $expr . ';';
        }
        return $expr . ';';
    }

    /** 字符串形近变形：数字末位 +1，其它加一个 ASCII 后缀。 */
    private function mutateLiteral(string $lit, int $variant): string
    {
        if (strlen($lit) < 2) {
            return "'" . ($variant === 1 ? 'x' : 'y') . "'";
        }
        $q = $lit[0];
        $inner = substr($lit, 1, -1);
        if ($inner !== '' && ctype_digit($inner)) {
            $inner = (string) (((int) $inner) + ($variant === 1 ? 1 : 2));
        } else {
            $inner .= ($variant === 1 ? '0' : 'x');
        }
        return $q . $inner . $q;
    }

    /**
     * 构造一段「看似业务校验」的恒真前置 + 条件。
     * 返回 ['pre'=>前置语句, 'cond'=>恒真条件表达式, 'false'=>恒假条件表达式]。
     */
    private function conditions(ConfrontRng $rng, array &$names, string $probeVar): array
    {
        $d = $names['d'];
        // 【关键】不能把任意用户变量直接塞进 (string) 转换：对象 / Closure 没有
        // __toString 时会抛 `Object of class X could not be converted to string`，
        // 恒真条件本身就成了炸弹。先归一成标量：非标量一律取 0，恒真性不变。
        $names['p'] = $this->newName('p', $names);
        $p = $names['p'];
        $safePre = $p . '=is_scalar(' . $probeVar . '??null)?(' . $probeVar . '??null):0;';
        $probeVar = $p;
        $forms = [
            // $d 派生                          恒真条件                    恒假条件
            ['strlen((string)(' . $probeVar . ' ?? \'\'))', $d . '>=0', $d . '===-1'],
            ['crc32((string)(' . $probeVar . ' ?? \'\'))&1', 'in_array(' . $d . ',[0,1],true)', $d . '===2'],
            ['(array)(' . $probeVar . ' ?? null)', 'count(' . $d . ')>=0', 'count(' . $d . ')<0'],
            ['is_scalar(' . $probeVar . ' ?? null)?1:0', $d . '>=0', $d . '<0'],
            ['substr((string)(' . $probeVar . ' ?? \'\'),0,1)', 'is_string(' . $d . ')', 'is_int(' . $d . ')'],
            ['(int)(' . $probeVar . ' ?? 0)', 'is_int(' . $d . ')', 'is_string(' . $d . ')'],
            ['[' . $probeVar . ' ?? null]', 'count(' . $d . ')===1', 'count(' . $d . ')===0'],
            ['trim((string)(' . $probeVar . ' ?? \'\'))', 'is_string(' . $d . ')', $d . '===false'],
            ['max(0,strlen((string)(' . $probeVar . ' ?? \'\')))', $d . '>=0', $d . '<0'],
            ['abs(crc32((string)(' . $probeVar . ' ?? \'\')))', $d . '>=0', $d . '<0'],
            ['str_repeat(\'x\',0).(string)(' . $probeVar . ' ?? \'\')', 'is_string(' . $d . ')', 'is_array(' . $d . ')'],
            ['(' . $d . '=strlen((string)(' . $probeVar . ' ?? \'\')))', $d . '>=0', $d . '===-1'],
        ];
        $pick = $forms[$rng->nextInt(0, count($forms) - 1)];
        $pre = $safePre . $d . '=' . $pick[0] . ';';
        // 第 12 式里派生表达式自身已带赋值，去掉重复前缀
        if (str_starts_with($pick[0], '(' . $d . '=')) {
            $pre = $pick[0] . ';';
        }
        return ['pre' => $pre, 'true' => $pick[1], 'false' => $pick[2]];
    }

    private function build(array $info, ConfrontRng $rng): string
    {
        $names = [];
        $names['d'] = $this->newName('d', $names);
        $probe = $info['vars'][0] ?? "''";
        $cond = $this->conditions($rng, $names, $probe);
        $real = $this->realBranch($info, $rng, $names);
        $d1 = $this->decoyBranch($info, $names, 1);
        $d2 = $this->decoyBranch($info, $names, 2);
        $pre = $cond['pre'];

        // 结构 B：真分支落在 else，前面两个 elseif 都是恒假条件。
        // 与结构 A 组合后，静态上既有"真条件在前"也有"真条件在最后"的多分支形态；
        // 全程只有 if —— 不使用 switch，避免与 token 层的 case 标签改写互相破坏。
        if ($rng->nextInt(0, 1) === 1) {
            $f2 = $this->otherFalse($rng, $names['p'], $names);
            return '{' . $pre . 'if(' . $cond['false'] . '){' . $d1 . '}'
                . 'elseif(' . $f2 . '){' . $d2 . '}else{' . $real . '}}';
        }
        return '{' . $pre . 'if(' . $cond['true'] . '){' . $real . '}'
            . 'elseif(' . $cond['false'] . '){' . $d1 . '}else{' . $d2 . '}}';
    }

    /** 从另一套恒假模板取条件，让结构 B 的两个 elseif 条件不重复。 */
    private function otherFalse(ConfrontRng $rng, string $probeVar, array $names): string
    {
        $d = $names['d'];
        $pool = [
            'strlen((string)(' . $probeVar . ' ?? \'\'))===-1',
            $d . '<0',
            'count((array)(' . $probeVar . ' ?? null))<0',
            $d . '===false',
            $d . '===2',
            'in_array(' . $d . ',[],true)',
            'is_array(trim((string)(' . $probeVar . ' ?? \'\')))',
        ];
        return $pool[$rng->nextInt(0, count($pool) - 1)];
    }

    private function newName(string $tag, array &$names): string
    {
        $key = $tag . count($names);
        $h = substr(bin2hex($this->salt->derive('decoy-name|' . $key . '|' . $this->ctr, 6)), 0, 7);
        $names[$key] = 'd' . $h;
        return '$' . $names[$key];
    }

    /** 把一段 PHP 代码解析成 token（去掉临时加的 `<?php ` 与紧随的空格）。 */
    private function tokenizeFragment(string $code): array
    {
        $t = Parser::tokenize('<?php ' . $code);
        if ($t !== [] && Parser::isToken($t[0]) && $t[0][0] === T_OPEN_TAG) {
            array_shift($t);
        }
        if ($t !== [] && $t[0] === ' ') {
            array_shift($t);
        }
        return $t;
    }

    private function slice(array $tokens, int $from, int $to): string
    {
        $s = '';
        for ($i = $from; $i <= $to; $i++) {
            $s .= Parser::text($tokens[$i]);
        }
        return $s;
    }
}
