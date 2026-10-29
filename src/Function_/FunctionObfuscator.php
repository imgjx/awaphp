<?php
/**
 * 函数名混淆 / 函数调用混淆
 *
 * - 收集「顶层」用户自定义函数名（跳过类内方法），按盐重命名为统一标识符。
 * - 重命名函数声明点与其直接调用点（T_STRING 后紧跟 '('）。
 * - 跳过方法调用（-> / :: 之后）以避免破坏类方法。
 *
 * 结果写入 $ctx['func']（原始名 => 混淆名），供 FunctionCallEncoder 使用。
 */

declare(strict_types=1);

final class FunctionObfuscator
{
    public function __construct(
        private Salt $salt,
        private Logger $logger,
        private ?SymbolTable $symbols = null
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,mixed> $ctx
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config, array &$ctx): array
    {
        $len = (int) $config->get('func_length');
        if ($len <= 0) {
            return $tokens;
        }

        // 1) 收集顶层函数声明（排除"名字以字符串字面量出现过"的符号：
        //    call_user_func('f') / $f='f';$f() 等动态调用一旦改名必断链）
        $declared = DynamicSymbols::exclude(
            $this->collectDeclared($tokens),
            DynamicSymbols::fromTokens($tokens)
        );

        // 2) 生成映射：优先使用全局符号表（整套系统模式，跨文件一致 + 修复跨文件调用），
        //    否则按本文件声明构建（单文件模式，行为与旧版一致）
        if ($this->symbols !== null && $this->symbols->isBuilt()) {
            $map = $this->symbols->funcMap();
            // 本文件声明也记录进表（供增量扫描/manifest 沉淀）
            foreach (array_keys($declared) as $name) {
                $this->symbols->addFunction($name);
            }
        } else {
            if (empty($declared)) {
                return $tokens;
            }
            $used = $this->existingIdentifiers($tokens);
            $map = []; // origin => obfuscated
            foreach (array_keys($declared) as $name) {
                $candidate = $this->salt->identifier($name, $len);
                $n = 0;
                while (isset($used[$candidate])) {
                    $candidate = $this->salt->identifier($name . '#' . (++$n), $len);
                }
                $used[$candidate] = true;
                $map[$name] = $candidate;
            }
        }

        // 白名单函数：保留指定函数名（跨文件模式由 SymbolTable::preserve 处理，
        // 单文件模式在此剔除本地映射，声明点与调用点都保持原名）。
        $wlFuncs = (array) ($config->get('whitelist')['functions'] ?? []);
        if ($wlFuncs !== []) {
            foreach ($wlFuncs as $fn) {
                unset($map[(string) $fn]);
            }
        }

        // 3) 重命名声明点 + 调用点 + 裸常量引用（B6）
        $ranges = $this->classRanges($tokens);
        $definedConsts = $this->definedConstants($tokens);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING) {
                continue;
            }
            $name = $tok[1];
            if (!isset($map[$name])) {
                continue;
            }
            $next = Parser::nextSignificant($tokens, $i);
            $isCallOrDecl = $next >= 0 && is_string($tokens[$next]) && $tokens[$next] === '(';
            if (!$isCallOrDecl) {
                // B6：未加引号的常量名引用（array_map(doub, ...) 依赖 PHP 7
                // 「未定义常量回退成字符串」的老写法）。doub 无括号，此前不改名，
                // 与已改名的声明失配 → 运行期 Undefined constant。仅在确认是
                // 常量引用位且该常量未在本文件/构建期定义时一并改名。
                if (!$this->isBareConstRef($tokens, $i, $next)) {
                    continue;
                }
                if (isset($definedConsts[$name]) || defined($name)) {
                    continue; // 真实常量（本文件声明或内置 PI/E_ALL/...）不碰
                }
            }
            // 跳过方法调用（-> / :: 之后）
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev >= 0 && is_array($tokens[$prev]) &&
                in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            // 类体内且紧跟 function 的 T_STRING 是方法声明：即使与顶层函数撞名
            // 也不能改（$obj->m() 调用点由 -> 规则保护，声明一改必断链）。
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION
                && $this->inRanges($ranges, $i)) {
                continue;
            }
            $tokens[$i][1] = $map[$name];
        }

        $ctx['func'] = $map; // 供 FunctionCallEncoder 使用
        $this->logger->debug('函数名混淆：' . count($map) . ' 个函数被重命名');
        return $tokens;
    }

    /** @return array<string,true> */
    public function collectDeclared(array $tokens): array
    {
        $declared = [];
        // 预标记类体区间：类/接口/trait/enum 内的方法是成员而非顶层函数，
        // 不能用运行计数器维护（类体 '{' 会被自身重复 +1 导致区间归不了零，
        // 首个类之后的顶层函数全部漏收集——BUG B2）。
        $ranges = $this->classRanges($tokens);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            if ($this->inRanges($ranges, $i)) {
                continue; // 类/接口/trait/enum 内方法（闭包无名字段，天然落空）
            }
            $nameIdx = Parser::nextSignificant($tokens, $i);
            // function &foo() 返回引用形式：跳过 '&' 再取名
            // （PHP 8.1+ 该 & 词法为 T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG）
            if ($nameIdx >= 0 && is_array($tokens[$nameIdx])
                && defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
                && $tokens[$nameIdx][0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                $nameIdx = Parser::nextSignificant($tokens, $nameIdx);
            } elseif ($nameIdx >= 0 && is_string($tokens[$nameIdx]) && $tokens[$nameIdx] === '&') {
                $nameIdx = Parser::nextSignificant($tokens, $nameIdx);
            }
            if ($nameIdx >= 0 && is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_STRING) {
                $declared[$tokens[$nameIdx][1]] = true;
            }
        }
        return $declared;
    }

    /**
     * 收集本文件中声明的常量名（const NAME = ... 与 define('NAME', ...)）。
     * 用于 B6 护栏：真实常量引用不随函数一并改名。
     * @param array<int,mixed> $tokens
     * @return array<string,true>
     */
    private function definedConstants(array $tokens): array
    {
        $set = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            if ($tok[0] === T_CONST) {
                $nx = Parser::nextSignificant($tokens, $i);
                if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_STRING) {
                    $set[$tokens[$nx][1]] = true;
                }
            } elseif ($tok[0] === T_STRING && strtolower($tok[1]) === 'define') {
                $nx = Parser::nextSignificant($tokens, $i);
                if ($nx >= 0 && is_string($tokens[$nx]) && $tokens[$nx] === '(') {
                    $arg = Parser::nextSignificant($tokens, $nx);
                    if ($arg >= 0 && is_array($tokens[$arg]) && $tokens[$arg][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $set[trim($tokens[$arg][1], "\"'")] = true;
                    }
                }
            }
        }
        return $set;
    }

    /**
     * B6：$i 处（无括号跟随）的 T_STRING 是否为「裸常量引用」位。
     * 排除所有非常量引用位置：命名参数/标签（后随 ':'）、类型提示（后随
     * T_VARIABLE）、类常量/成员（:: / ->）、match/switch 臂（=>）、
     * 声明/实例化/继承/捕获/属性/导入/命名空间（前趋关键字）等。
     * @param array<int,mixed> $tokens
     */
    private function isBareConstRef(array $tokens, int $i, int $next): bool
    {
        if ($next >= 0) {
            if (is_string($tokens[$next])) {
                if (in_array($tokens[$next], [':', '=>', '[', '(', '{'], true)) {
                    return false;
                }
            } elseif (is_array($tokens[$next])) {
                if (in_array($tokens[$next][0], [
                    T_VARIABLE, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_ELLIPSIS,
                    T_NULLSAFE_OBJECT_OPERATOR, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES,
                    T_NS_SEPARATOR,
                ], true)) {
                    return false;
                }
            }
        }
        $prev = Parser::prevSignificant($tokens, $i);
        if ($prev >= 0 && is_array($tokens[$prev])) {
            if (in_array($tokens[$prev][0], [
                T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NEW, T_INSTANCEOF,
                T_EXTENDS, T_IMPLEMENTS, T_USE, T_NS_SEPARATOR, T_ATTRIBUTE,
                T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR,
                T_CONST, T_CASE, T_GOTO,
            ], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 预标记所有类体区间 [开'{'下标, 闭'}'下标]（class/interface/trait/enum）。
     * 用括号配对而非运行计数器，避免类体开括号被重复计数导致区间永不归零。
     * @param array<int,mixed> $tokens
     * @return array<int,array{int,int}>
     */
    private function classRanges(array $tokens): array
    {
        $T_ENUM = defined('T_ENUM') ? T_ENUM : -1;
        $ranges = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, $T_ENUM], true)) {
                continue;
            }
            $brace = $this->findOpenBrace($tokens, $i);
            if ($brace < 0) {
                continue; // 无类体（前向声明/异常 token 流）
            }
            $close = $this->matchAnyBrace($tokens, $brace);
            if ($close > $brace) {
                $ranges[] = [$brace, $close];
            }
        }
        return $ranges;
    }

    /** @param array<int,array{int,int}> $ranges */
    private function inRanges(array $ranges, int $i): bool
    {
        foreach ($ranges as $r) {
            if ($i >= $r[0] && $i <= $r[1]) {
                return true;
            }
        }
        return false;
    }

    /** 从 $from 之后向前扫描，找到类体开括号 '{' 的索引 */
    private function findOpenBrace(array $tokens, int $from): int
    {
        for ($j = $from + 1; $j < count($tokens); $j++) {
            if (is_string($tokens[$j]) && $tokens[$j] === '{') {
                return $j;
            }
        }
        return -1;
    }

    /**
     * 从开括号下标找配对闭括号（字符串/注释/heredoc 等数组型 token 天然跳过）。
     * 插值修正：T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES（"{$expr}" 起始）
     * 是数组 token 但其闭合是裸 '}' 字符——不计入深度会让类体范围在方法内
     * 第一个插值串处提前结束。
     */
    private function matchAnyBrace(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $tok = $tokens[$j];
            if (is_array($tok)) {
                if (in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                continue;
            }
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return -1;
    }

    /** @return array<string,true> */
    private function existingIdentifiers(array $tokens): array
    {
        $set = [];
        foreach ($tokens as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_VARIABLE, T_STRING, T_FUNCTION, T_CLASS], true)) {
                $set[$tok[1]] = true;
            }
        }
        return $set;
    }
}
