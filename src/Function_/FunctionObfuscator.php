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

        // 3) 重命名声明点 + 调用点
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
            if ($next < 0 || !(is_string($tokens[$next]) && $tokens[$next] === '(')) {
                continue; // 必须紧跟 '(' 才是声明或调用
            }
            // 跳过方法调用（-> / :: 之后）
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev >= 0 && is_array($tokens[$prev]) &&
                in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
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
        $classDepth = 0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                if ($classDepth > 0) {
                    if ($tok === '{') {
                        $classDepth++;
                    } elseif ($tok === '}') {
                        $classDepth--;
                    }
                }
                continue;
            }
            if (in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                // 跳过类名 / extends / implements，找到真正的类体 '{'
                $brace = $this->findOpenBrace($tokens, $i);
                if ($brace >= 0) {
                    $classDepth = 1;
                }
                continue;
            }
            if ($classDepth === 0 && $tok[0] === T_FUNCTION) {
                $nameIdx = Parser::nextSignificant($tokens, $i);
                if ($nameIdx >= 0 && is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_STRING) {
                    $declared[$tokens[$nameIdx][1]] = true;
                }
            }
        }
        return $declared;
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
