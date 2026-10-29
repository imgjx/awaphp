<?php
/**
 * 动态符号扫描器（DynamicSymbols）
 *
 * 背景（兼容性头号杀手）：
 *   代码里若以「字符串字面量」形式引用某个函数/类名，那么该名字就是**运行期动态调用**的入口：
 *       $f = 'handler'; $f();                 // 可变函数
 *       call_user_func('handler', $x);        // 回调
 *       $cls = 'App\Box'; new $cls();         // 可变类名
 *       method_exists($obj, 'run');           // 方法名字符串
 *   这类引用在 token 流里只是普通字符串，重命名器看不见；一旦把声明改了名，
 *   运行期就变成 "Call to undefined function/class"，属于**静默致命**破坏。
 *
 * 策略：保守排除。凡名字以字符串字面量形式出现过的符号，一律不参与重命名。
 *   代价：少量符号保留原名；收益：绝不产生"能过 php -l 但一运行就崩"的产物。
 *
 * 适用范围：单文件模式与整套系统（ProjectBuilder）模式统一生效，
 * 避免"目录模式安全、单文件模式炸"的行为分叉。
 */

declare(strict_types=1);

final class DynamicSymbols
{
    /**
     * 从 token 流中收集全部字符串字面量内容（单引号 / 双引号 / heredoc 主体）。
     *
     * @param array<int,mixed> $tokens
     * @return array<string,true> 字面量内容集合
     */
    public static function fromTokens(array $tokens): array
    {
        $lits = [];
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $v = trim($t[1], "\"'");
                if ($v !== '') {
                    $lits[$v] = true;
                }
            } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                // 双引号插值串里的纯文本片段（"call_$x" 之类）
                $v = trim($t[1]);
                if ($v !== '') {
                    $lits[$v] = true;
                }
            }
        }
        return $lits;
    }

    /**
     * 过滤掉"可能被动态引用"的声明符号。
     *
     * @param array<string,true> $declared 收集到的声明符号
     * @param array<string,true> $literals fromTokens() 的结果
     * @return array<string,true> 可安全重命名的符号
     */
    public static function exclude(array $declared, array $literals): array
    {
        $safe = [];
        foreach (array_keys($declared) as $name) {
            if (isset($literals[$name])) {
                continue; // 名字以字符串形式出现过 -> 保守保留原名
            }
            // FQCN 键（类名混淆 v2）：短名以字面量出现同样视为动态引用
            // （`$c='Request'` 与 `$c='app\Request'` 都可能拼出 FQCN）
            $short = $name;
            if (str_contains($name, '\\')) {
                $short = substr($name, (int) strrpos($name, '\\') + 1);
                if (isset($literals[$short])) {
                    continue;
                }
            }
            $safe[$name] = true;
        }
        return $safe;
    }
}
