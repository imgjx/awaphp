<?php
/**
 * 函数型代码打散（FunctionSplitter / funcsplit 层）
 *
 * 把「一节 PHP 代码」提取成函数封装，再以函数调用替换原位置：
 *     echo $i + $k . $str;
 *   →
 *     function _fs3f8a1c92($i, $k, $str){echo $i + $k . $str;}     // 定义集中追加到文件末尾
 *     _fs3f8a1c92($i, $k, $str);                                   // 调用留在原位
 *
 * 语义等价的第一原则（宁可不混淆，也不改炸）：
 *   1. **未定义变量一律值传参**。引用传参会把未定义变量静默创建成 null，从而吞掉
 *      原本的 `Warning: Undefined variable`；值传参的行为与原表达式读取完全一致。
 *      只有「在本语句之前已明确赋值」的变量才允许引用传参写回。
 *   2. **两种提取模式**：
 *      - value 模式：简单赋值 / return，把右侧纯表达式变成 `return EXPR;`，调用点
 *        写成 `$v = fn(...)` 或 `return fn(...);`；表达式必须无副作用（沿用 v1.2.5
 *        的严格白名单）。
 *      - stmt 模式（v1.2.6 新增）：整条语句原样搬进函数体，用引用传参承接写回。
 *        于是函数调用、方法调用、`new`、数组/属性赋值、复合赋值、`unset`、`++`
 *        这些"有副作用但整条搬走后顺序不变"的语句也能被打散。
 *   3. **无论哪种模式都禁止跨栈帧会变义的结构**：控制流（break/continue/return 以外的
 *      goto/yield/throw）、闭包与箭头函数、`use`/`global`/`static`、魔术常量、
 *      插值字符串/heredoc、`$this`、超全局、引用赋值、`@` 抑制符。
 *   4. 函数定义插到第一个顶层 `<?php` 之后（跳过 declare/namespace），避开混写模板。
 *      多命名空间 / bracket 命名空间 / `__halt_compiler` 整文件跳过。
 *
 * 等级 func_split_level：
 *   1 = 只提取 `echo` / `print` 语句（默认）
 *   2 = 再加简单赋值 `$v = EXPR;` 与 `return EXPR;`，以及**无写回的纯表达式语句**
 *       （函数调用 / 方法调用 / new）
 *   3 = 再加**有写回的语句**（数组/属性/复合赋值、unset、自增自减）与
 *       「连续 2~4 条 echo/print 合并成一个函数体」
 *
 * 覆盖率相关的保守开关：
 *   func_split_max = 单文件最多提取多少个函数（默认 64，防止小文件被撑爆 / 变慢）
 */

declare(strict_types=1);

final class FunctionSplitter
{
    private Salt $salt;
    private Logger $logger;
    private int $level = 1;
    private int $max = 64;
    private int $ctr = 0;
    /** @var array<int,string> 收集的函数定义文本 */
    private array $defs = [];
    /**
     * 文件级命名空间（v1.2.6 修复整站 redeclare）。
     * Salt::identifier/derive 的设计是「与文件无关，以便跨文件对齐」——那对
     * 【被重命名后跨文件引用的符号】是对的，但 funcsplit 提取的是【本文件私有】
     * 的顶层函数：若只用全局 salt + 每文件从 0 重置的 ctr 命名，全站每个文件的
     * 第一个提取函数都会撞成同一个名字，整站运行必然 `Cannot redeclare function`。
     * 这里用源文件 token 内容的摘要做命名空间：不同文件天然隔离；同一文件重复
     * 构建仍逐字节可复现（内容相同 ⇒ 摘要相同）。
     */
    private string $fileNs = '';

    /** 表达式里允许出现的数组 token（全部是"只读、无副作用"的） */
    private const ALLOW_TOKENS = [
        T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_VARIABLE,
        T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
        T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
        T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
        T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
    ];

    /** 只要出现就必须整条语句放弃的 token */
    private const FORBID_TOKENS = [
        T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CLONE, T_FUNCTION, T_FN,
        T_YIELD, T_YIELD_FROM, T_RETURN, T_BREAK, T_CONTINUE, T_GOTO, T_EXIT,
        T_THROW, T_GLOBAL, T_STATIC, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE,
        T_REQUIRE_ONCE, T_EVAL, T_UNSET, T_ISSET, T_EMPTY, T_LIST, T_MATCH,
        T_LINE, T_FILE, T_DIR, T_CLASS_C, T_TRAIT_C, T_METHOD_C, T_FUNC_C, T_NS_C,
        T_START_HEREDOC, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML,
        T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE,
        T_DECLARE, T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
        T_INC, T_DEC, T_POW, T_COALESCE, T_COALESCE_EQUAL,
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
        T_CONCAT_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
        T_POW_EQUAL, T_SL, T_SR, T_PRINT, T_ECHO, T_OPEN_TAG, T_CLOSE_TAG,
    ];

    /** 表达式里允许的单字符 token */
    private const ALLOW_CHARS = '+-*/%.()[]<>&|^~?:,!';

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
        $this->level = max(1, min(3, (int) $cfg->get('func_split_level', 1)));
        $this->max = max(0, (int) $cfg->get('func_split_max', 64));
        $this->ctr = 0;
        $this->defs = [];
        // 文件级摘要：内容相同 ⇒ 命名空间相同 ⇒ 产物可复现；内容不同 ⇒ 名字隔离。
        // 只看「有意义的 token 文本」，忽略空白/注释差异（同一份源码换注释不该改名）。
        $sig = '';
        foreach ($tokens as $t) {
            if (Parser::isWhitespaceOrComment($t)) {
                continue;
            }
            $sig .= Parser::text($t);
        }
        $this->fileNs = substr(md5($sig), 0, 12);
        $n = count($tokens);
        if ($n === 0 || $this->max === 0) {
            return $tokens;
        }
        if (!$this->fileIsSafe($tokens)) {
            return $tokens;
        }

        // 1) 切"分号结尾的简单语句"。只按【圆括号深度】判定语句结束：
        //    for(...;...;...); declare(...); 里的分号 paren>0，不会误切。
        //    不再要求花括号深度为 0 —— 函数/方法体内的语句同样可提取（v1.2.6）。
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
        if ($stmts === []) {
            return $tokens;
        }

        // 2) 判定每条语句是否可提取
        $assignAt = $this->assignMap($tokens);
        $cand = [];
        foreach ($stmts as [$s, $e]) {
            $info = $this->analyze($tokens, $s, $e, $assignAt);
            if ($info !== null) {
                $info['s'] = $s;
                $info['e'] = $e;
                $cand[] = $info;
            }
        }
        if ($cand === []) {
            return $tokens;
        }

        // 3) 分组：level 3 把相邻的纯 echo/print 合并（最多 4 条）。
        //    合并会让所有实参在函数体执行前一次性求值，从而改变「未定义变量 Warning」
        //    的出现顺序。因此只在组内变量都明确"在本组之前已赋值"时才合并。
        $groups = [];
        if ($this->level >= 3) {
            $cur = null;
            foreach ($cand as $c) {
                $canMerge = $cur !== null
                    && $cur['kind'] === $c['kind']
                    && in_array($c['kind'], ['echo', 'print'], true)
                    && count($cur['items']) < 4
                    && $this->adjacent($tokens, $cur['items'][count($cur['items']) - 1]['e'], $c['s'])
                    && $this->allDefinedBefore($c['vars'], $assignAt, $cur['items'][0]['s']);
                if ($canMerge) {
                    $cur['items'][] = $c;
                    foreach ($c['vars'] as $v) {
                        $cur['vars'][$v] = $v;
                    }
                    foreach ($c['refs'] as $v) {
                        $cur['refs'][$v] = $v;
                    }
                } else {
                    if ($cur !== null) {
                        $groups[] = $cur;
                    }
                    $cur = [
                        'kind' => $c['kind'],
                        'mode' => $c['mode'],
                        'target' => $c['target'],
                        'items' => [$c],
                        'vars' => $this->varSet($c['vars']),
                        'refs' => $this->varSet($c['refs']),
                    ];
                }
            }
            if ($cur !== null) {
                $groups[] = $cur;
            }
        } else {
            foreach ($cand as $c) {
                $groups[] = [
                    'kind' => $c['kind'],
                    'mode' => $c['mode'],
                    'target' => $c['target'],
                    'items' => [$c],
                    'vars' => $this->varSet($c['vars']),
                    'refs' => $this->varSet($c['refs']),
                ];
            }
        }

        // 4) 生成定义 + 原位替换成调用
        $replace = [];
        foreach ($groups as $g) {
            if (count($this->defs) >= $this->max) {
                break;
            }
            // 同一变量不得既值传又引用传：引用优先（stmt 模式）。
            $refs = array_values($g['refs']);
            $vals = array_diff(array_values($g['vars']), $refs);
            $fn = $this->newName();
            $params = [];
            foreach ($vals as $v) {
                $params[] = $v;
            }
            foreach ($refs as $v) {
                $params[] = '&' . $v;
            }
            $body = '';
            foreach ($g['items'] as $it) {
                $body .= $it['body'];
            }
            // v1.2.7：具名函数若所在文件被 include 多次（框架 config/provider/service
            // 在初始化中可能重复 require），第二次会 `Cannot redeclare function`。
            // 原生 PHP 文件无这些抽取函数故不炸；混淆引入的函数必须自带幂等守卫。
            // 用 __NAMESPACE__ 拼接全名以兼容命名空间文件（function_exists 同时接受
            // 带/不带前导反斜杠的名字，见实测）。
            $this->defs[] = 'if(!function_exists(__NAMESPACE__ . "\\' . $fn . '")){'
                . 'function ' . $fn . '(' . implode(', ', $params) . '){' . $body . '}}';
            $callArgs = array_merge($vals, $refs);
            $args = $callArgs === [] ? '' : implode(', ', $callArgs);
            $first = $g['items'][0];
            $last = $g['items'][count($g['items']) - 1];
            if ($g['kind'] === 'assign') {
                $call = $g['target'] . ' = ' . $fn . '(' . $args . ');';
            } elseif ($g['kind'] === 'return') {
                $call = 'return ' . $fn . '(' . $args . ');';
            } else {
                $call = $fn . '(' . $args . ');';
            }
            $replace[$first['s']] = ['end' => $last['e'], 'text' => $call];
        }
        if ($replace === []) {
            return $tokens;
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset($replace[$i])) {
                $out[] = $replace[$i]['text'];
                $i = $replace[$i]['end'];
                continue;
            }
            $out[] = $tokens[$i];
        }

        // 5) 定义插到文件顶层的合法位置（`<?php` 之后，跳过 declare / namespace 声明）。
        $defText = "\n" . implode("\n", $this->defs) . "\n";
        $insertAt = $this->topLevelInsertPos($out);
        if ($insertAt < 0) {
            return $tokens; // 找不到安全插入点：整文件放弃（宁可不混淆）
        }
        array_splice($out, $insertAt, 0, [$defText]);
        $this->logger->debug(sprintf('funcsplit: 提取 %d 个函数（等级 %d，语句型 %d）',
            count($this->defs), $this->level,
            count(array_filter($this->defs, static fn($d) => strpos($d, 'function ') === 0 || strpos($d, 'if(!function_exists') === 0))));
        return $out;
    }

    /**
     * 计算"文件顶层"的安全插入位置：第一个 `<?php` 之后，跳过 `declare(...);`
     * 与 `namespace X;`（这两种必须是文件最前面的语句）。
     * @param array<int,mixed> $tokens
     */
    private function topLevelInsertPos(array $tokens): int
    {
        $n = count($tokens);
        $open = -1;
        for ($i = 0; $i < $n; $i++) {
            if (Parser::isToken($tokens[$i]) && $tokens[$i][0] === T_OPEN_TAG) {
                $open = $i;
                break;
            }
        }
        if ($open < 0) {
            return -1;
        }
        $pos = $open + 1;
        // 最多跳过 declare + namespace 两条前置声明
        for ($round = 0; $round < 3; $round++) {
            $j = $pos;
            while ($j < $n && Parser::isWhitespaceOrComment($tokens[$j])) {
                $j++;
            }
            if ($j >= $n || !Parser::isToken($tokens[$j])) {
                break;
            }
            $id = $tokens[$j][0];
            if ($id !== T_DECLARE && $id !== T_NAMESPACE) {
                break;
            }
            // 跳到该声明结尾的分号
            $k = $j;
            $found = -1;
            while ($k < $n) {
                $txt = Parser::text($tokens[$k]);
                if ($txt === '{') {
                    return -1; // bracket 形式，无法安全插在其前
                }
                if ($txt === ';') {
                    $found = $k;
                    break;
                }
                if ($txt === '?>') {
                    break;
                }
                $k++;
            }
            if ($found < 0) {
                return -1;
            }
            $pos = $found + 1;
        }
        return $pos;
    }

    /**
     * 整文件级安全检查：多种命名空间/停止编译/声明式语法一律跳过。
     * @param array<int,mixed> $tokens
     */
    private function fileIsSafe(array $tokens): bool
    {
        $nsCount = 0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!Parser::isToken($t)) {
                continue;
            }
            if ($t[0] === T_HALT_COMPILER) {
                return false;
            }
            if ($t[0] === T_NAMESPACE) {
                $nsCount++;
                if ($nsCount > 1) {
                    return false; // 多命名空间：定义追加位置无法保证落在正确的 ns 内
                }
                // bracket 形式 namespace X { ... } 也跳过（末尾追加会落到全局作用域）
                $j = Parser::nextSignificant($tokens, $i);
                while ($j > 0 && $j < $n) {
                    $tj = $tokens[$j];
                    if (Parser::isToken($tj) && $tj[0] === T_STRING) {
                        $j = Parser::nextSignificant($tokens, $j);
                        continue;
                    }
                    if (Parser::text($tj) === '{') {
                        return false;
                    }
                    break;
                }
            }
        }
        return true;
    }

    /**
     * 语句模式绝不允许的 token：搬进另一个栈帧后语义会变，或会把函数定义切坏。
     * 注意 T_UNSET / T_PRINT / T_ECHO / 自增自减【不在】这里 —— 它们整条搬走后
     * 顺序不变，正是 v1.2.6 要新覆盖的目标。
     */
    private const HARD_FORBID = [
        T_FUNCTION, T_FN, T_YIELD, T_YIELD_FROM, T_GOTO, T_EXIT, T_THROW,
        T_GLOBAL, T_STATIC, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
        T_IF, T_ELSE, T_ELSEIF, T_WHILE, T_DO, T_FOR, T_FOREACH, T_SWITCH,
        T_TRY, T_CATCH, T_FINALLY, T_CASE, T_DEFAULT,
        T_DECLARE, T_NAMESPACE, T_BREAK, T_CONTINUE, T_RETURN,
        T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL,
        T_ISSET, T_EMPTY, T_LIST, T_MATCH,
        T_LINE, T_FILE, T_DIR, T_CLASS_C, T_TRAIT_C, T_METHOD_C, T_FUNC_C, T_NS_C,
        T_START_HEREDOC, T_END_HEREDOC, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML,
        T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE,
        T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_HALT_COMPILER,
    ];

    /** 调用后依赖"当前函数帧"的内建函数：搬走后结果会变，整条放弃。 */
    private const FRAME_SENSITIVE_FNS = [
        'func_get_args' => true, 'func_get_arg' => true, 'func_num_args' => true,
        'get_defined_vars' => true, 'debug_backtrace' => true, 'debug_print_backtrace' => true,
        'extract' => true, 'compact' => true, 'assert' => true,
        'eval' => true, 'exit' => true, 'die' => true,
    ];

    /**
     * 分析一条语句，返回内部描述或 null（放弃提取）。
     * @param array<int,mixed> $tokens
     * @param array<string,int> $assignAt
     */
    private function analyze(array $tokens, int $s, int $e, array $assignAt): ?array
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
        $head = $tokens[$k];
        $id = $head[0];

        // return / 简单赋值 / echo/print：先走严格 value 模式（旧行为，零风险）
        if ($id === T_RETURN && $this->level >= 2) {
            return $this->valueAnalyze($tokens, $s, $e, $k, $assignAt);
        }
        if ($id === T_ECHO || $id === T_PRINT) {
            $v = $this->valueAnalyze($tokens, $s, $e, $k, $assignAt);
            if ($v !== null) {
                return $v;
            }
            // 复杂 echo/print 不回退 stmt 模式：echo 后接函数调用时，把语句整段
            // 塞进函数体再经后续空白压缩层处理，曾出现 `echo` 与函数名粘连成
            // `echostr_rot13()` 的回归（S5 max）。echo/print 保持 v1.2.5 的严格路径。
            return null;
        }
        if ($id === T_VARIABLE && $this->level >= 2) {
            $eq = Parser::nextSignificant($tokens, $k);
            if ($eq > 0 && $eq < $e && $tokens[$eq] === '=') {
                $afterEq = Parser::nextSignificant($tokens, $eq);
                if (!($afterEq > 0 && Parser::text($tokens[$afterEq]) === '&')) {
                    $v = $this->valueAnalyze($tokens, $s, $e, $k, $assignAt);
                    if ($v !== null) {
                        return $v;
                    }
                }
            }
        }
        // v1.2.6：其余语句（函数调用、方法调用、数组/属性赋值、复合赋值、unset、++…）
        // 【必须限定语句头】—— 否则 while/if/switch 这类结构的整段会被当成一条
        // "语句"提取，函数体缺少闭合括号，生成的代码直接语法错（max 档实测踩过）。
        if ($this->level >= 2 && in_array($id, [T_ECHO, T_PRINT, T_VARIABLE, T_STRING], true)) {
            // goto 标签 `label: stmt;` 的首 token 也是 T_STRING：把标签一起搬走会让
            // goto 找不到目标标签（max/ultra 实测踩到），整条跳过。
            $nx = Parser::nextSignificant($tokens, $k);
            if ($nx > 0 && Parser::text($tokens[$nx]) === ':') {
                return null;
            }
            return $this->stmtAnalyze($tokens, $s, $e, $k, $assignAt);
        }
        return null;
    }

    /**
     * value 模式：右侧必须是严格无副作用的纯表达式；调用点用返回值接住。
     */
    private function valueAnalyze(array $tokens, int $s, int $e, int $k, array $assignAt): ?array
    {
        $head = $tokens[$k];
        $id = $head[0];
        $isEcho = $id === T_ECHO || $id === T_PRINT;
        $isReturn = $id === T_RETURN;
        $isAssign = false;
        $eq = -1;
        if (!$isEcho && !$isReturn && $id === T_VARIABLE) {
            $eq = Parser::nextSignificant($tokens, $k);
            if ($eq > 0 && $eq < $e && $tokens[$eq] === '=') {
                $isAssign = true;
            }
        }
        if (!$isEcho && !$isReturn && !$isAssign) {
            return null;
        }
        $exprStart = $isAssign ? $eq + 1 : $k + 1;
        $vars = [];
        for ($i = $exprStart; $i < $e; $i++) {
            if (!$this->safeExprToken($tokens[$i], $vars)) {
                return null;
            }
        }
        if ($vars === []) {
            return null;
        }
        $expr = trim($this->sliceText($tokens, $exprStart, $e - 1));
        if ($expr === '') {
            return null;
        }
        if ($isAssign) {
            $body = 'return ' . $expr . ';';
            $kind = 'assign';
            $target = Parser::text($head);
        } elseif ($isReturn) {
            $body = 'return ' . $expr . ';';
            $kind = 'return';
            $target = '';
        } else {
            $body = $this->sliceText($tokens, $s, $e);
            $kind = 'echo';
            $target = '';
        }
        return [
            'kind' => $kind, 'mode' => 'value', 'body' => $body,
            'vars' => array_values($vars), 'refs' => [], 'target' => $target,
        ];
    }

    /**
     * stmt 模式：整条语句原样搬进函数体，所有变量【引用传参】。
     * 引用只在变量已明确赋值后才使用 —— 未定义变量改引用会吞掉 Warning，
     * 那条路在 value 模式里用值传参，这里宁可整条放弃。
     */
    private function stmtAnalyze(array $tokens, int $s, int $e, int $k, array $assignAt): ?array
    {
        $vars = [];
        $prev = -1;
        for ($i = $k; $i < $e; $i++) {
            $t = $tokens[$i];
            if (Parser::isToken($t)) {
                $id = $t[0];
                if ($id === T_VARIABLE) {
                    $name = (string) $t[1];
                    if ($name === '$this' || strncmp($name, '$GLOBALS', 8) === 0
                        || preg_match('/^\$_(SERVER|GET|POST|FILES|COOKIE|SESSION|ENV|REQUEST)$/', $name)) {
                        return null;
                    }
                    $vars[$name] = $name;
                    $prev = $i;
                    continue;
                }
                if (in_array($id, self::HARD_FORBID, true)) {
                    return null;
                }
                if ($id === T_STRING && $i + 1 < $e
                    && Parser::text($tokens[Parser::nextSignificant($tokens, $i)]) === ':') {
                    return null; // goto 标签
                }
                if ($id === T_STRING && isset(self::FRAME_SENSITIVE_FNS[strtolower((string) $t[1])])
                    && Parser::nextSignificant($tokens, $i) >= 0
                    && Parser::text($tokens[Parser::nextSignificant($tokens, $i)]) === '(') {
                    return null;
                }
                continue;
            }
            $txt = (string) $t;
            if ($txt === '@') {
                return null;
            }
            // `= &$x` 引用赋值：函数形参再引用会形成引用链，保守跳过
            if ($txt === '&' && $prev >= 0 && Parser::text($tokens[$prev]) === '=') {
                return null;
            }
            $prev = $i;
        }
        if ($vars === []) {
            return null;
        }
        foreach ($vars as $v) {
            if (!isset($assignAt[$v]) || $assignAt[$v] >= $s) {
                return null; // 未定义变量：引用会吞 Warning，放弃
            }
        }
        return [
            'kind' => 'stmt', 'mode' => 'stmt',
            'body' => $this->sliceText($tokens, $s, $e),
            'vars' => [], 'refs' => array_values($vars), 'target' => '',
        ];
    }

    /**
     * 表达式 token 白名单检查，同时收集变量名。
     * @param array<int,string> $vars
     */
    private function safeExprToken($t, array &$vars): bool
    {
        if (Parser::isToken($t)) {
            $id = $t[0];
            if ($id === T_VARIABLE) {
                $name = $t[1];
                if ($name === '$this'
                    || strncmp($name, '$GLOBALS', 8) === 0
                    || preg_match('/^\$_(SERVER|GET|POST|FILES|COOKIE|SESSION|ENV|REQUEST)$/', $name)) {
                    return false;
                }
                $vars[$name] = $name;
                return true;
            }
            if ($id === T_STRING) {
                // 只允许 true / false / null 这类字面量常量
                return in_array(strtolower($t[1]), ['true', 'false', 'null'], true);
            }
            if (in_array($id, self::FORBID_TOKENS, true)) {
                return false;
            }
            return in_array($id, self::ALLOW_TOKENS, true);
        }
        $s = (string) $t;
        if (strlen($s) !== 1) {
            return false;
        }
        return strpos(self::ALLOW_CHARS, $s) !== false;
    }

    /** @param array<int,mixed> $tokens */
    private function sliceText(array $tokens, int $from, int $to): string
    {
        $s = '';
        for ($i = $from; $i <= $to; $i++) {
            $s .= Parser::text($tokens[$i]);
        }
        return $s;
    }

    /** 向前找到最近一个"非空白非注释"的 token 索引（找不到返回 -1）。 */
    private function prevSig(array $tokens, int $i): int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!Parser::isWhitespaceOrComment($tokens[$j])) {
                return $j;
            }
        }
        return -1;
    }

    /**
     * 扫描全文件，记录每个变量"最早一次明确赋值"的 token 位置。
     * 只认最保守的形态：`$v =`、`$v +=`、`$v++`、`++$v`、`foreach … as $v`、
     * `catch (T $v)`、`global/static $v`。函数参数不登记（避免跨函数误判）。
     * @param array<int,mixed> $tokens
     * @return array<string,int>
     */
    private function assignMap(array $tokens): array
    {
        $map = [];
        $n = count($tokens);
        $compound = [
            T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
            T_CONCAT_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL,
            T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
        ];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!Parser::isToken($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $name = $t[1];
            if (isset($map[$name])) {
                continue;
            }
            $j = Parser::nextSignificant($tokens, $i);
            $p = $this->prevSig($tokens, $i);
            $hit = false;
            if ($j > 0) {
                $tj = $tokens[$j];
                if ($tj === '=') {
                    $hit = true;
                } elseif (Parser::isToken($tj) && (in_array($tj[0], $compound, true) || $tj[0] === T_INC || $tj[0] === T_DEC)) {
                    $hit = true;
                }
            }
            if (!$hit && $p > 0) {
                $tp = $tokens[$p];
                if (Parser::isToken($tp) && ($tp[0] === T_INC || $tp[0] === T_DEC)) {
                    $hit = true;
                }
                if (Parser::isToken($tp) && $tp[0] === T_AS) {
                    $hit = true;
                }
                if (Parser::isToken($tp) && ($tp[0] === T_GLOBAL || $tp[0] === T_STATIC)) {
                    $hit = true;
                }
            }
            // foreach (... as $k => $v) / catch (T $v)
            if (!$hit && $p > 0 && Parser::isToken($tokens[$p])) {
                $tp = $tokens[$p];
                if ($tp[0] === T_DOUBLE_ARROW || $tp[0] === T_CATCH) {
                    $hit = true;
                }
            }
            if ($hit) {
                $map[$name] = $i;
            }
        }
        return $map;
    }

    /**
     * 这些变量是否都在 $beforePos 之前被明确赋值过。
     * @param array<int,string> $vars
     * @param array<string,int> $assignAt
     */
    private function allDefinedBefore(array $vars, array $assignAt, int $beforePos): bool
    {
        foreach ($vars as $v) {
            if (!isset($assignAt[$v]) || $assignAt[$v] >= $beforePos) {
                return false;
            }
        }
        return true;
    }

    /** 把变量名列表转成「名 => 名」的集合（用于合并去重）。 */
    private function varSet(array $list): array
    {
        $set = [];
        foreach ($list as $v) {
            $set[$v] = $v;
        }
        return $set;
    }

    /** 两条语句之间是否只有空白/注释。 */
    private function adjacent(array $tokens, int $endPrev, int $startNext): bool
    {
        for ($i = $endPrev + 1; $i < $startNext; $i++) {
            if (!Parser::isWhitespaceOrComment($tokens[$i])) {
                return false;
            }
        }
        return true;
    }

    private function newName(): string
    {
        // fileNs 参与派生：同一文件同一 salt 可复现，不同文件不撞名（整站 redeclare 修复）。
        $h = substr(bin2hex($this->salt->derive('funcsplit|' . $this->fileNs . '|' . $this->ctr, 6)), 0, 10);
        $this->ctr++;
        return '_fs' . $h . $this->ctr;
    }
}
