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
 *   1. **值传参（不用引用）**。引用传参会把未定义变量静默创建成 null，从而吞掉
 *      原本的 `Warning: Undefined variable`；值传参的行为与原表达式读取完全一致。
 *   2. **只提取无副作用的表达式**：变量、数字/字符串字面量、数组下标读、算术/位/
 *      比较/逻辑运算、三元、数组字面量。任何函数调用、成员访问、`new`、引用、
 *      自增自减、赋值嵌套、`isset/empty`、`@`、插值字符串、heredoc、魔术常量
 *      一律拒绝（这些会因"搬到另一个栈帧"而改变行为）。
 *   3. **只处理三类语句**：`echo …;`、`print …;`、`$v = EXPR;`。
 *   4. **不做跨语句的变量写回**，因此提取后的代码不依赖调用者作用域。
 *   5. 函数定义统一追加到文件末尾（PHP 顶层函数在编译期注册，先调用后定义合法）。
 *      仅处理「单命名空间 / 无命名空间」文件；bracket 命名空间、多命名空间、
 *      `__halt_compiler` 一律整文件跳过。
 *
 * 等级 func_split_level：
 *   1 = 只提取 `echo` / `print` 语句（默认）
 *   2 = 再加简单赋值 `$v = EXPR;`
 *   3 = 再加「连续 2~4 条 echo/print 合并成一个函数体」（节更大，打散更彻底）
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
        $n = count($tokens);
        if ($n === 0 || $this->max === 0) {
            return $tokens;
        }
        // 整文件跳过条件（宁可不动）
        if (!$this->fileIsSafe($tokens)) {
            return $tokens;
        }

        // 1) 切出"分号结尾的简单语句"区间 [s, e]（e 含分号）。深度 0 才成立。
        $stmts = [];
        $depth = 0;
        $start = -1;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $txt = Parser::text($t);
            if ($start < 0 && !Parser::isWhitespaceOrComment($t)) {
                $start = $i;
            }
            if ($txt === '(' || $txt === '[' || $txt === '{') {
                $depth++;
                continue;
            }
            if ($txt === ')' || $txt === ']' || $txt === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && $txt === ';') {
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
        $cand = [];
        foreach ($stmts as [$s, $e]) {
            $info = $this->analyze($tokens, $s, $e);
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
        //    合并会让所有实参在函数体执行前一次性求值，从而改变「未定义变量 Warning」的
        //    出现顺序。因此只在组内变量都明确"在本组之前已赋值"时才合并。
        $assignAt = $this->assignMap($tokens);
        $groups = [];
        if ($this->level >= 3) {
            $cur = null;
            foreach ($cand as $c) {
                $canMerge = $cur !== null
                    && $cur['kind'] === 'echo'
                    && $c['kind'] === 'echo'
                    && count($cur['items']) < 4
                    && $this->adjacent($tokens, $cur['items'][count($cur['items']) - 1]['e'], $c['s'])
                    && $this->allDefinedBefore($c['vars'], $assignAt, $cur['items'][0]['s']);
                if ($canMerge) {
                    $cur['items'][] = $c;
                    foreach ($c['vars'] as $v) {
                        $cur['vars'][$v] = $v;
                    }
                } else {
                    if ($cur !== null) {
                        $groups[] = $cur;
                    }
                    $cur = ['kind' => $c['kind'], 'items' => [$c], 'vars' => $this->varSet($c['vars'])];
                }
            }
            if ($cur !== null) {
                $groups[] = $cur;
            }
        } else {
            foreach ($cand as $c) {
                $groups[] = ['kind' => $c['kind'], 'items' => [$c], 'vars' => $this->varSet($c['vars'])];
            }
        }

        // 4) 生成定义 + 原位替换成调用
        $replace = [];
        foreach ($groups as $g) {
            if (count($this->defs) >= $this->max) {
                break;
            }
            $fn = $this->newName();
            $vars = array_values($g['vars']);
            $body = '';
            foreach ($g['items'] as $it) {
                $body .= $it['text'];
            }
            if ($g['kind'] === 'assign') {
                // 赋值语句整体替换成 return，调用点写成 $v = fn(...)
                $body = 'return ' . $g['items'][0]['expr'] . ';';
            }
            $params = $vars === [] ? '' : implode(', ', $vars);
            $this->defs[] = 'function ' . $fn . '(' . $params . '){' . $body . '}';
            $args = $vars === [] ? '' : implode(', ', $vars);
            $first = $g['items'][0];
            $last = $g['items'][count($g['items']) - 1];
            if ($g['kind'] === 'assign') {
                $call = $first['target'] . ' = ' . $fn . '(' . $args . ');';
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
        //    放在开头而不是末尾，可以彻底避开"多段 PHP/HTML 混写"时插到
        //    短输出标签或花括号中间导致语法错、或被当 HTML 输出的问题。
        $defText = "\n" . implode("\n", $this->defs) . "\n";
        $insertAt = $this->topLevelInsertPos($out);
        if ($insertAt < 0) {
            return $tokens; // 找不到安全插入点：整文件放弃（宁可不混淆）
        }
        array_splice($out, $insertAt, 0, [$defText]);
        $this->logger->debug(sprintf('funcsplit: 提取 %d 个函数（等级 %d）', count($this->defs), $this->level));
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
     * 分析一条语句。返回 ['kind'=>'echo'|'assign', 'text'=>原文, 'vars'=>[..],
     * 'expr'=>右侧表达式文本, 'target'=>赋值目标] 或 null。
     * @param array<int,mixed> $tokens
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
        $head = $tokens[$k];
        $isEcho = $head[0] === T_ECHO;
        $isPrint = $head[0] === T_PRINT;
        $isAssign = false;
        $eq = -1;
        if (!$isEcho && !$isPrint && $head[0] === T_VARIABLE && $this->level >= 2) {
            $eq = Parser::nextSignificant($tokens, $k);
            if ($eq > 0 && $eq < $e && $tokens[$eq] === '=') {
                // 排除 `= &$x`（引用赋值）与 `= &` 换行
                $afterEq = Parser::nextSignificant($tokens, $eq);
                if (!($afterEq > 0 && Parser::text($tokens[$afterEq]) === '&')) {
                    $isAssign = true;
                }
            }
        }
        if (!$isEcho && !$isPrint && !$isAssign) {
            return null;
        }

        // 表达式区间
        $exprStart = $isAssign ? $eq + 1 : $k + 1;
        // echo/print 的 `print` 本身在 forbid 列表里，这里从 head 之后开始检查
        $vars = [];
        for ($i = $exprStart; $i < $e; $i++) {
            if (!$this->safeExprToken($tokens[$i], $vars)) {
                return null;
            }
        }
        if ($vars === []) {
            return null; // 没有变量可传参：提取意义不大，且会引入无谓调用开销
        }

        $expr = $this->sliceText($tokens, $exprStart, $e - 1);
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        $out = [
            'kind' => $isAssign ? 'assign' : 'echo',
            'text' => $this->sliceText($tokens, $s, $e),
            'vars' => array_values($vars),
            'expr' => $expr,
            'target' => $isAssign ? Parser::text($head) : '',
        ];
        return $out;
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
        $h = substr(bin2hex($this->salt->derive('funcsplit|' . $this->ctr, 5)), 0, 8);
        $this->ctr++;
        return '_fs' . $h . $this->ctr;
    }
}
