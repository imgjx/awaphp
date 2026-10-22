<?php
/**
 * 类 / 接口 / 命名空间混淆
 *
 * 收集用户声明的 class / interface / trait 名称，按盐重命名：
 *  - 声明点（class / interface / trait 之后）
 *  - 实例化 `new X(`
 *  - 静态访问 `X::`
 *  - 继承 `extends X`、实现 `implements X, Y`
 *  - 类型提示 / catch `X $var`
 *  - 类内 trait 引用 `use X`（顶层 use 因不在声明集合内自动跳过）
 *
 * 安全：跳过带命名空间分隔符（\）的限定名引用，避免破坏跨命名空间引用。
 */

declare(strict_types=1);

final class ClassObfuscator
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
        if (!$config->get('class_obf')) {
            return $tokens;
        }

        // 排除"名字以字符串字面量出现过"的类名：$c='Box'; new $c(); 一旦改名必断链
        $declared = DynamicSymbols::exclude(
            $this->collectDeclared($tokens),
            DynamicSymbols::fromTokens($tokens)
        );

        // 优先使用全局符号表（整套系统模式）；否则按本文件声明构建（单文件模式）
        if ($this->symbols !== null && $this->symbols->isBuilt()) {
            $map = $this->symbols->classMap();
            foreach (array_keys($declared) as $name) {
                $this->symbols->addClass($name);
            }
        } else {
            if (empty($declared)) {
                return $tokens;
            }
            $used = $this->existingIdentifiers($tokens);
            $map = [];
            foreach (array_keys($declared) as $name) {
                $candidate = $this->salt->identifier($name, 10);
                $n = 0;
                while (isset($used[$candidate])) {
                    $candidate = $this->salt->identifier($name . '#' . (++$n), 10);
                }
                $used[$candidate] = true;
                $map[$name] = $candidate;
            }
        }

        // B3a/B3b：预标记参数列表区间（T_FUNCTION/T_FN 后的首个括号）与 catch 子句区间。
        // 通用 prev/next 规则覆盖不到两类位置：
        //  - 联合/交叉类型的首段：function f(A|B $x) 的 A，prev 是 '('；
        //  - 无变量 catch：catch (A|B)，类型后直接 ')'。
        $paramRanges = $this->parenRangesAfter($tokens, [T_FUNCTION, T_FN]);
        $catchRanges = $this->parenRangesAfter($tokens, [T_CATCH]);

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
            // 跳过带命名空间分隔符的限定名引用
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NS_SEPARATOR) {
                continue;
            }
            $prevType = $prev >= 0 ? Parser::type($tokens[$prev]) : -1;
            $next = Parser::nextSignificant($tokens, $i);
            $nextType = $next >= 0 ? Parser::type($tokens[$next]) : -1;

            $rename = false;
            if (in_array($prevType, [T_CLASS, T_INTERFACE, T_TRAIT, T_NEW, T_EXTENDS, T_IMPLEMENTS, T_USE, T_DOUBLE_COLON, T_INSTANCEOF, T_ATTRIBUTE], true)) {
                $rename = true;
            } elseif ($nextType === T_VARIABLE) {
                $rename = true; // 类型提示 / catch / 属性类型
            } elseif ($nextType === T_DOUBLE_COLON) {
                // 静态访问 / 类常量 / 静态属性 / 静态方法：Box::SIDE / Box::$c / Box::make()
                // 类名在 :: 之前——T_DOUBLE_COLON 在 prev 列表只能覆盖 :: 之后的名称。
                // PHP 中除 parent/self/static（关键字，非 T_STRING）外只有类名可位于 :: 前，安全。
                $rename = true;
            } elseif ($prev >= 0
                && ((is_string($tokens[$prev]) && ($tokens[$prev] === ':' || $tokens[$prev] === '?'))
                    || Parser::isTypeSeparator($tokens[$prev]))
                && !($next >= 0 && is_string($tokens[$next]) && $tokens[$next] === '(')) {
                // 返回类型（): Box { / ); / =>）、可空/联合/交集类型续段。
                // 类名不是合法表达式，故这些运算符后跟已声明类名必为类型位置；
                // '(' 后续排除函数调用形态。标签/命名参数的类名在运算符之前，不受影响。
                // '?'（可空前缀）、':'（返回类型）为裸字符；'|'/'&' 经 isTypeSeparator
                // 统一处理 PHP 8.1+ 的 T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG。
                $rename = true;
            }

            // B3a：参数列表内联合/交叉类型首段（prev 是 '('，不在通用规则内）
            if (!$rename && $this->inRanges($paramRanges, $i)
                && $next >= 0 && Parser::isTypeSeparator($tokens[$next])) {
                $rename = true;
            }
            // B3b：catch (A|B) 无变量形式——类型后直接 ')' 或类型分隔符
            if (!$rename && $this->inRanges($catchRanges, $i)
                && $next >= 0 && (Parser::isTypeSeparator($tokens[$next])
                    || (is_string($tokens[$next]) && $tokens[$next] === ')'))) {
                $rename = true;
            }

            if ($rename) {
                $tokens[$i][1] = $map[$name];
            }
        }

        // 处理 implements 列表中的后续项（逗号分隔）
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_IMPLEMENTS) {
                continue;
            }
            $j = $i;
            while (true) {
                $nameIdx = Parser::nextSignificant($tokens, $j);
                if ($nameIdx < 0 || !(is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_STRING)) {
                    break;
                }
                if (isset($map[$tokens[$nameIdx][1]])) {
                    $tokens[$nameIdx][1] = $map[$tokens[$nameIdx][1]];
                }
                // 后移：找逗号或结束
                $comma = Parser::nextSignificant($tokens, $nameIdx);
                if ($comma < 0 || !(is_string($tokens[$comma]) && $tokens[$comma] === ',')) {
                    break;
                }
                $j = $comma;
            }
        }

        $ctx['class'] = $map;
        $this->logger->debug('类名混淆：' . count($map) . ' 个类/接口被重命名');
        return $tokens;
    }

    /** @return array<string,true> */
    public function collectDeclared(array $tokens): array
    {
        $declared = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            if (in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $nameIdx = Parser::nextSignificant($tokens, $i);
                if ($nameIdx >= 0 && is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_STRING) {
                    $declared[$tokens[$nameIdx][1]] = true;
                }
            }
        }
        return $declared;
    }

    /**
     * 收集指定关键字之后第一个 '(' 的配对区间（参数列表 / catch 子句）。
     * 用于覆盖联合/交叉类型首段与无变量 catch 等通用规则盲区（BUG B3）。
     * @param array<int,mixed> $tokens
     * @param array<int,int> $keywords
     * @return array<int,array{int,int}>
     */
    private function parenRangesAfter(array $tokens, array $keywords): array
    {
        $ranges = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], $keywords, true)) {
                continue;
            }
            // 关键字后找第一个 '('（跳过函数名 / & 返回引用形式）
            $open = -1;
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_string($tokens[$j])) {
                    if ($tokens[$j] === '(') {
                        $open = $j;
                        break;
                    }
                    // 参数列表开始前不可能遇到语句边界
                    if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                        break;
                    }
                }
            }
            if ($open < 0) {
                continue;
            }
            $close = $this->matchAnyBrace($tokens, $open);
            if ($close > $open) {
                $ranges[] = [$open, $close];
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

    /**
     * 从开括号下标找配对闭括号（字符串/注释/heredoc 等数组型 token 天然跳过）。
     * 插值修正：T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES（"{$expr}" 起始）
     * 是数组 token 但其闭合是裸 '}' 字符——不计入深度会让区间提前结束。
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
