<?php
/**
 * Token 形态混淆（TokenObfuscator）
 *
 * 在 token 流末端做"词法形态"变换——不改语义、不改结构，只改"长得不像 PHP"：
 *  1. 关键字大小写随机化：PHP 关键字大小写不敏感，ecHo / WhIlE / fUnCtIoN / (InTeGeR)
 *     均合法。直接杀死针对关键字的 grep / 正则特征匹配（现有 AST 级工具反而会把
 *     大小写规范化回去，暴露关键词）。
 *  2. 字面量等价形态：true→!0、false→!1；类型转换别名互换 (int)↔(integer)、
 *     (bool)↔(boolean)、(float)↔(double)（PHP 8 已移除 (real)/(unset)，不用）；
 *     array(...)→[...]（只走安全方向：array() 解构在 PHP 里非法，反向是语义雷区）。
 *  3. 引号形态安全翻转：'abc'↔"abc"，仅在没有转义/插值/冲突引号时翻转，
 *     否则原样保留（保守优先）。
 *  4. heredoc/nowdoc 变换（--token-heredoc，默认关）：纯字面量串改写为
 *     <<<'ID' ... ID 形态，且闭合标识后补换行，保证任意后续 token 位置合法。
 *  5. 顶层诱饵注释（--token-decoy=N，默认 3）：在顶层语句分号后插入误导性注释
 *     （假许可证缓存、假完整性探针、假调试钩子），仅当输出保留注释时注入。
 *
 * 安全边界（本模块绝不触碰）：
 *  - 字符串 / heredoc / nowdoc 字面量内部（T_CONSTANT_ENCAPSED_STRING、T_ENCAPSED_*）
 *  - 注释内部（整串不匹配关键字表，天然安全）
 *  - T_STRING 等标识符（->if / ::if / 命名参数等上下文敏感关键字靠类型区分保护）
 *  - heredoc 起止标识（T_START_HEREDOC / T_END_HEREDOC，大小写必须逐字节一致）
 *  - T_UNSET_CAST（(unset) 转换在 PHP 8 已移除）
 *  - 双引号插值区间内的字符串片（"pre $x['a'] post" 的 'a' 若被翻转/heredoc 化
 *    会直接破坏字符串）——quote/heredoc 两个 pass 均带插值区间状态机
 *
 * 位置约定：在 token 管线末端（stat 之后、ws 之前）执行，使上游所有模块
 * （含死代码注入）产出的关键字一并获得形态变换。
 */

declare(strict_types=1);

final class TokenObfuscator
{
    /**
     * PHP 关键字全集（小写）。仅当 token 类型本身是关键字类型时才允许改写——
     * 关键字在 PHP 中永远大小写不敏感，故改写大小写不改变语义。
     */
    private const KEYWORDS = [
        'abstract' => 1, 'and' => 1, 'array' => 1, 'as' => 1, 'break' => 1,
        'callable' => 1, 'case' => 1, 'catch' => 1, 'class' => 1, 'clone' => 1,
        'const' => 1, 'continue' => 1, 'declare' => 1, 'default' => 1, 'die' => 1,
        'do' => 1, 'echo' => 1, 'else' => 1, 'elseif' => 1, 'empty' => 1,
        'enddeclare' => 1, 'endfor' => 1, 'endforeach' => 1, 'endif' => 1,
        'endswitch' => 1, 'endwhile' => 1, 'enum' => 1, 'eval' => 1, 'exit' => 1,
        'extends' => 1, 'final' => 1, 'finally' => 1, 'fn' => 1, 'for' => 1,
        'foreach' => 1, 'function' => 1, 'global' => 1, 'goto' => 1, 'if' => 1,
        'implements' => 1, 'include' => 1, 'include_once' => 1, 'instanceof' => 1,
        'insteadof' => 1, 'interface' => 1, 'isset' => 1, 'list' => 1, 'match' => 1,
        'namespace' => 1, 'new' => 1, 'or' => 1, 'print' => 1, 'private' => 1,
        'protected' => 1, 'public' => 1, 'readonly' => 1, 'require' => 1,
        'require_once' => 1, 'return' => 1, 'static' => 1, 'switch' => 1,
        'throw' => 1, 'trait' => 1, 'try' => 1, 'unset' => 1, 'use' => 1,
        'var' => 1, 'while' => 1, 'xor' => 1, 'yield' => 1,
        'true' => 1, 'false' => 1, 'null' => 1,
        // 类型转换（关键字形态）
        'int' => 1, 'integer' => 1, 'float' => 1, 'double' => 1, 'string' => 1,
        'bool' => 1, 'boolean' => 1, 'object' => 1,
        '__halt_compiler' => 1,
    ];

    /** 大小写随机化要跳过的 token 类型（字面量内容 / 标识符 / heredoc 标识） */
    private const CASE_SKIP = [
        T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML,
        T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
        T_START_HEREDOC, T_END_HEREDOC, T_CURLY_OPEN,
        T_STRING, T_VARIABLE, T_LINE, T_FILE, T_DIR, T_CLASS_C, T_METHOD_C,
        T_FUNC_C, T_NS_C, T_ATTRIBUTE,
    ];

    /** 转换别名表：PHP 8 仍合法的 cast 同义词（(real)/(unset)/(binary) 已移除，不收） */
    private const CAST_ALIAS = [
        '(int)'   => ['(int)', '(integer)'],
        '(bool)'  => ['(bool)', '(boolean)'],
        '(float)' => ['(float)', '(double)'],
    ];

    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        if (!$config->get('token_obf')) {
            return $tokens;
        }
        $rand = new Random($this->salt->getSalt() . ':token-obf');
        $st = ['case' => 0, 'literal' => 0, 'quote' => 0, 'heredoc' => 0, 'decoy' => 0];

        if ($config->get('token_case')) {
            $tokens = $this->casePass($tokens, $rand, $st);
        }
        if ($config->get('token_literal')) {
            $tokens = $this->literalPass($tokens, $rand, $st);
        }
        if ($config->get('token_quote') || $config->get('token_heredoc')) {
            // 插值区间集合：quote/heredoc 两个 pass 共用（区间内的字符串片绝不能动）
            $interp = $this->interpolationMap($tokens);
            if ($config->get('token_quote')) {
                $tokens = $this->quotePass($tokens, $rand, $st, $interp);
            }
            if ($config->get('token_heredoc')) {
                $tokens = $this->heredocPass($tokens, $rand, $st, $interp);
            }
        }
        $decoy = (int) $config->get('token_decoy');
        if ($decoy > 0 && !$config->get('no_comments')) {
            $tokens = $this->decoyPass($tokens, $rand, $decoy, $st);
        }

        $this->logger->debug(sprintf(
            'Token 形态混淆：大小写 %d / 字面量 %d / 引号 %d / heredoc %d / 诱饵注释 %d',
            $st['case'], $st['literal'], $st['quote'], $st['heredoc'], $st['decoy']
        ));
        return $tokens;
    }

    /* ======================= 1. 关键字大小写随机化 ======================= */

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,int> $st
     * @return array<int,mixed>
     */
    private function casePass(array $tokens, Random $rand, array &$st): array
    {
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok)) {
                continue;
            }
            $type = $tok[0];
            $text = $tok[1];

            if ($type === T_OPEN_TAG) {
                // <?php -> <?PHP（仅随机化 php 三字母，保留尾部空白）
                $new = $this->randomCase('<?php', $rand) . substr($text, 5);
                if ($new !== $text) {
                    $tokens[$i][1] = $new;
                    $st['case']++;
                }
                continue;
            }
            if (in_array($type, self::CASE_SKIP, true)) {
                continue;
            }
            // `::class` 伪常量中的 class 必须保持小写：大小写化会变成 Foo::CLASS，
            // 在常量表达式上下文（属性默认值 / const / 参数默认值）触发
            // "Constant expression contains invalid operations"。类声明处的 class 关键字本就
            // 大小写不敏感，此处针对 `T_DOUBLE_COLON` 之后的 T_CLASS 跳过即可，其它位置照常随机化。
            if ($type === T_CLASS) {
                $p = $i - 1;
                while ($p >= 0 && is_array($tokens[$p])
                    && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $p--;
                }
                if ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_DOUBLE_COLON) {
                    continue;
                }
            }
            // 整串必须是关键字本身（注释/字符串内容天然不匹配）
            $low = strtolower($text);
            if (!isset(self::KEYWORDS[$low])) {
                continue;
            }
            $new = $this->randomCase($text, $rand);
            if ($new !== $text) {
                $tokens[$i][1] = $new;
                $st['case']++;
            }
        }
        return $tokens;
    }

    /** 全大写 / 全小写 / 逐字符随机混合 */
    private function randomCase(string $word, Random $rand): string
    {
        $style = $rand->int(3);
        if ($style === 0) {
            return strtoupper($word);
        }
        if ($style === 1) {
            return strtolower($word);
        }
        $out = '';
        $n = strlen($word);
        for ($i = 0; $i < $n; $i++) {
            $c = $word[$i];
            $out .= ctype_alpha($c) ? ($rand->int(2) ? strtoupper($c) : strtolower($c)) : $c;
        }
        return $out;
    }

    /* ======================= 2. 字面量等价形态 ======================= */

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,int> $st
     * @return array<int,mixed>
     */
    private function literalPass(array $tokens, Random $rand, array &$st): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                $out[] = $tok;
                continue;
            }
            $type = $tok[0];

            // true -> !0 / false -> !1（概率 1/2，保留一半原形增加形态多样性）
            // 注意：PHP tokenizer 从不产出 T_TRUE/T_FALSE 常量——true/false/null
            // 始终是 T_STRING。且关键字可合法用作成员/方法名（->true、::true、
            // function true()），必须做上下文排除，只改真正的布尔字面量。
            if ($type === T_STRING) {
                $low = strtolower($tok[1]);
                if (($low === 'true' || $low === 'false') && $this->isBoolLiteral($tokens, $i) && $rand->int(2)) {
                    $out[] = '!';
                    $out[] = [T_LNUMBER, $low === 'true' ? '0' : '1', $tok[2] ?? 0];
                    $st['literal']++;
                    continue;
                }
            }

            // 类型转换别名互换（(unset) 在 PHP 8 已移除，T_UNSET_CAST 不碰）
            if ($type === T_INT_CAST || $type === T_BOOL_CAST || $type === T_DOUBLE_CAST) {
                $low = strtolower($tok[1]);
                if (isset(self::CAST_ALIAS[$low])) {
                    $forms = self::CAST_ALIAS[$low];
                    $new = $forms[$rand->int(count($forms))];
                    if ($new !== $low) {
                        $out[] = [$type, $new, $tok[2] ?? 0];
                        $st['literal']++;
                        continue;
                    }
                }
            }

            // array(...) -> [...]：唯一安全方向（array() 解构非法，故不存在
            // "把解构目标改写坏"的风险；[] 在所有 array() 合法处均合法）。
            // 实现：T_ARRAY 本身不产字符，开/闭括号原位替换为 [ / ] 承接——
            // 三者（array/(/)）必须恰好映射为一对 []，多产一个 [ 即括号失衡。
            if ($type === T_ARRAY) {
                $open = $this->nextIdx($tokens, $i);
                if ($open >= 0 && $tokens[$open] === '(') {
                    $close = $this->matchPair($tokens, $open, '(', ')');
                    if ($close >= 0) {
                        $tokens[$open] = '[';
                        $tokens[$close] = ']';
                        $st['literal']++;
                        continue;
                    }
                }
            }

            $out[] = $tok;
        }
        return $out;
    }

    /**
     * 判断 $i 处的 T_STRING('true'/'false') 是否为布尔字面量。
     *
     * PHP 允许关键字作标识符的合法位置只有成员访问一类，逐个排除：
     *  - 前一个有效 token 是 -> / ?-> / :: / function（方法名、属性名、函数名）
     *  - 后一个有效 token 是 ':'（命名参数名 / goto 标签 / case 标签，保守排除）
     * 其余位置（= 右值、参数、返回、数组元素、条件表达式……）均为字面量，
     * 可安全替换为 !0 / !1（! 优先级高于所有二元运算符，语义逐位等价）。
     *
     * B4 追加排除：PHP 8.2+ 起 true/false 是合法类型（ standalone type ），
     * 以下位置是类型声明而非字面量，替换成 !0/!1 会直接语法错误：
     *  - 参数/属性类型：true 后紧跟 T_VARIABLE / T_ELLIPSIS（true $x / true ...$r）
     *  - 属性修饰符后：public/private/protected/static/var/readonly true $x
     *  - 返回类型及其联合/交集续段：): true { / ); / => / , / ) / |
     */
    private function isBoolLiteral(array $tokens, int $i): bool
    {
        $prev = Parser::prevSignificant($tokens, $i);
        if ($prev >= 0 && is_array($tokens[$prev])) {
            $pt = $tokens[$prev][0];
            if ($pt === T_OBJECT_OPERATOR || $pt === T_DOUBLE_COLON || $pt === T_FUNCTION
                || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $pt === T_NULLSAFE_OBJECT_OPERATOR)) {
                return false;
            }
            // B4：属性/提升属性类型声明（public true $x; static readonly false $y;）
            $modifiers = [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_VAR, T_STATIC];
            if (defined('T_READONLY')) {
                $modifiers[] = T_READONLY;
            }
            if (in_array($pt, $modifiers, true)) {
                return false;
            }
        }
        $next = Parser::nextSignificant($tokens, $i);
        if ($next >= 0 && $tokens[$next] === ':') {
            return false; // 命名参数名 / case 标签
        }
        // B4：true/false 后紧跟变量或 ... = 参数/属性类型声明
        if ($next >= 0 && is_array($tokens[$next])
            && in_array($tokens[$next][0], [T_VARIABLE, T_ELLIPSIS], true)) {
            return false;
        }
        // B4：返回类型（): true { / ); / =>）与联合/交集续段（int|true）。
        // prev 为 ':'（返回类型/三元 else/命名参数，保守排除）或 '?' / '|' / '&'
        // （可空/联合/交集成员），next 为语句/列表边界字符。
        if ($prev >= 0) {
            $prevIsTypeCtx = (is_string($tokens[$prev]) && $tokens[$prev] === ':')
                || (is_string($tokens[$prev]) && $tokens[$prev] === '?')
                || Parser::isTypeSeparator($tokens[$prev]);
            $nextIsBoundary = $next >= 0 && is_string($tokens[$next])
                && in_array($tokens[$next], ['{', ';', '=>', ',', ')'], true);
            if ($prevIsTypeCtx && $nextIsBoundary) {
                return false;
            }
        }
        return true;
    }

    /* ======================= 3. 引号形态安全翻转 ======================= */

    /**
     * 计算 token 集合中"处于双引号插值区间内"的下标。
     *
     * token_get_all 对 "pre {$arr['key']} post" 的实际切分（注意：含插值的
     * 双引号串【不是】单个 T_CONSTANT_ENCAPSED_STRING，其定界引号是裸字符
     * token '"'，正文是 T_ENCAPSED_AND_WHITESPACE 片）：
     *   '"' T_ENCAPSED_AND_WHITESPACE("pre ") T_CURLY_OPEN T_VARIABLE
     *   '[' T_CONSTANT_ENCAPSED_STRING('key') ']' '}' T_ENCAPSED_AND_WHITESPACE(" post") '"'
     * 非插值双引号串整体是单个 T_CONSTANT_ENCAPSED_STRING，不会出现裸 '"'，
     * 故裸 '"' 必为插值串定界符：遇之翻转区间状态。
     * 区间内的嵌套串（'key'、"a"…）若被引号翻转或 heredoc 化会改变插值区
     * 形态（nowdoc 进插值直接语法错误），必须整体跳过。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,bool> 下标 => 是否在插值区间内
     */
    private function interpolationMap(array $tokens): array
    {
        $map = [];
        $inInterp = false;
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok)) {
                if ($tok === '"') {
                    $inInterp = !$inInterp; // 插值串定界符（转义引号在 T_ENCAPSED_AND_WHITESPACE 内，不会单独成 token）
                }
                continue;
            }
            if ($inInterp && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $map[$i] = true;
            }
        }
        return $map;
    }

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,int> $st
     * @param array<int,bool> $interp
     * @return array<int,mixed>
     */
    private function quotePass(array $tokens, Random $rand, array &$st, array $interp): array
    {
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (isset($interp[$i])) {
                continue; // 插值区间内的片：可能是起始/中间/结尾片或嵌套串，一律不动
            }
            $text = $tok[1];
            if (strlen($text) < 2) {
                continue;
            }
            $q = $text[0];
            $body = substr($text, 1, -1);
            $new = null;
            if ($q === "'") {
                // 单引号仅有的转义是 \' 与 \\；无其二者即无转义，无 $ 即无插值
                if ($body !== '' && strpbrk($body, "\\$'") === false) {
                    $new = '"' . str_replace('"', '\\"', $body) . '"';
                }
            } elseif ($q === '"') {
                // 双引号：无转义符、无 $ 才是纯字面量
                if ($body !== '' && strpbrk($body, "\\$") === false) {
                    $new = "'" . str_replace("'", "\\'", $body) . "'";
                }
            }
            if ($new !== null && $rand->int(2)) {
                $tokens[$i][1] = $new;
                $st['quote']++;
            }
        }
        return $tokens;
    }

    /* ======================= 4. heredoc / nowdoc 变换 ======================= */

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,int> $st
     * @param array<int,bool> $interp
     * @return array<int,mixed>
     */
    private function heredocPass(array $tokens, Random $rand, array &$st, array $interp): array
    {
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (isset($interp[$i])) {
                continue; // 插值区间内的串不能 heredoc 化
            }
            $text = $tok[1];
            if (strlen($text) < 2) {
                continue;
            }
            $q = $text[0];
            if ($q !== "'" && $q !== '"') {
                // 非引号开头的"字符串 token"：StringEncoder 等上游把表达式文本
                // （如 (chr(107).chr(101))."\x79"）存进 T_CONSTANT_ENCAPSED_STRING，
                // token 类型是字面量但文本是代码。绝不可 heredoc 化——否则表达式
                // 文本被包进 nowdoc 变成字面量，语义静默破坏。
                continue;
            }
            if ($text[strlen($text) - 1] !== $q) {
                continue; // 收尾引号不配对：同上，非真实字面量
            }
            $body = substr($text, 1, -1);
            if ($body === '') {
                continue; // 空串跳过（空 heredoc 边界无收益）
            }
            if ($q === '"') {
                // 双引号仅在没有转义/插值时与 nowdoc 等价
                if (strpbrk($body, "\\$") !== false) {
                    continue;
                }
                $decoded = $body;
            } else {
                // 单引号：手工反转义（\\ 与 \' 是仅有的两个转义）
                $decoded = $this->decodeSingleQuoted($body);
            }
            // 闭合标识不能与正文行冲突（nowdoc 要求行首精确匹配才终止）
            $id = null;
            for ($try = 0; $try < 6; $try++) {
                $cand = '_' . $rand->string(6, '0123456789abcdef');
                if (preg_match('/^[ \t]*' . preg_quote($cand, '/') . '(?![a-zA-Z0-9_\x80-\xff])/m', $decoded) !== 1) {
                    $id = $cand;
                    break;
                }
            }
            if ($id === null) {
                continue;
            }
            if (!str_ends_with($decoded, "\n")) {
                $decoded .= "\n";
            }
            // 闭合标识后补换行：无论后续 token 是什么（; ) , . => ...）都合法
            $tokens[$i][1] = "<<<'" . $id . "'\n" . $decoded . $id . "\n";
            $st['heredoc']++;
        }
        return $tokens;
    }

    /** 反转义单引号字符串体（仅 \\ 与 \' 是转义，其余反斜杠按字面保留） */
    private function decodeSingleQuoted(string $body): string
    {
        $out = '';
        $n = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            $c = $body[$i];
            if ($c === '\\' && $i + 1 < $n && ($body[$i + 1] === '\\' || $body[$i + 1] === "'")) {
                $out .= $body[++$i];
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /* ======================= 5. 顶层诱饵注释 ======================= */

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,int> $st
     * @return array<int,mixed>
     */
    private function decoyPass(array $tokens, Random $rand, int $count, array &$st): array
    {
        // 只找"括号/花括号深度 0 的分号"之后的位置——顶层语句边界，
        // 绝不会插进表达式 / 函数体 / 数组内部
        $positions = [];
        $depth = 0;
        foreach ($tokens as $i => $tok) {
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
            } elseif ($tok === ';' && $depth <= 0) {
                $positions[] = $i;
            }
        }
        if ($positions === []) {
            return $tokens;
        }
        $count = min($count, count($positions));
        $picked = [];
        $guard = 0;
        while (count($picked) < $count && $guard++ < 64) {
            $p = $positions[$rand->int(count($positions))];
            if (!in_array($p, $picked, true)) {
                $picked[] = $p;
            }
        }
        $inserts = [];
        foreach ($picked as $pos) {
            $line = 0;
            for ($j = $pos; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && isset($tokens[$j][2])) {
                    $line = (int) $tokens[$j][2];
                    break;
                }
            }
            $inserts[$pos] = [T_COMMENT, $this->decoyText($rand), $line];
        }
        $out = [];
        foreach ($tokens as $i => $tok) {
            $out[] = $tok;
            if (isset($inserts[$i])) {
                $out[] = $inserts[$i];
                $st['decoy']++;
            }
        }
        return $out;
    }

    /** 误导性注释模板：假许可证缓存 / 假完整性探针 / 假调试钩子（统一补尾换行） */
    private function decoyText(Random $rand): string
    {
        $hex = fn (int $len) => $rand->string($len, '0123456789abcdef');
        $forms = [
            '// [' . $hex(8) . '] integrity probe v2 — do not remove',
            '/* license cache: ' . $hex(24) . ' */',
            '// TODO(awaken): strip debug hook before ship',
            '/* crc32 baseline ' . $hex(8) . ' — auto-generated */',
            '// if(!$__srv_ok){http_response_code(403);exit;}',
            '/* key material rotated at build ' . $rand->string(6, '0123456789') . ' */',
        ];
        // 尾换行是安全硬要求：--compress 会删除注释后的空白 token，
        // `//` 行注释若不以换行结尾会把同一物理行上的后续代码全部吞掉。
        return $forms[$rand->int(count($forms))] . "\n";
    }

    /* ======================= token 流工具 ======================= */

    /** 从 $i 向后找第一个非空白非注释 token 下标 */
    private function nextIdx(array $tokens, int $i): int
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            if (Parser::isWhitespaceOrComment($tokens[$j])) {
                continue;
            }
            return $j;
        }
        return -1;
    }

    /** 从开括号下标出发找配对闭括号（字符串/注释/heredoc 内部的括号不算） */
    private function matchPair(array $tokens, int $open, string $o, string $c): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $tok = $tokens[$j];
            if (is_array($tok)) {
                continue; // 数组型 token 不可能是单字符括号
            }
            if ($tok === $o) {
                $depth++;
            } elseif ($tok === $c) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return -1;
    }
}
