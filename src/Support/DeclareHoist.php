<?php
/**
 * 文件头语句提升（DeclareHoist）
 *
 * 背景：PHP 对"文件头部"有两条硬性约束——
 *   1. `declare(strict_types=1);` 必须是【脚本的第一条语句】（注释与空白不算语句）；
 *   2. `namespace X;` / `namespace X {` 必须是第一条语句，或紧跟在 declare 之后。
 *
 * 反调试 / 防 hook / 防篡改等壳的形态是"在载荷前面插入一段守卫代码"，于是原本位于
 * 文件首行的 declare / namespace 就被挤到了守卫之后 → 产物直接致命错误：
 *   "strict_types declaration must be the very first statement"
 *   "Namespace declaration statement has to be the very first statement or after any declare call"
 * 这些壳又不能把载荷塞进 eval（守卫与载荷就不在同一作用域了），所以正确做法是：
 * 把文件头部这些"必须在最前"的语句留在开标签之后、守卫之前——即"提升"到真正的最前面。
 *
 * 本类只做一件事：把源码切成 [前缀(开标签 + 前导 declare / namespace), 余下正文]。
 * 守卫插在前缀之后，既满足 PHP 的头部约束，又仍在任何业务语句执行之前运行。
 */

declare(strict_types=1);

final class DeclareHoist
{
    /**
     * 切分源码：前缀含 PHP 开标签与前导的 declare / namespace 语句头，正文为其余部分。
     * 无开标签时前缀为空。括号式命名空间 `namespace X {` 只把"到 `{` 为止"的部分并入
     * 前缀（守卫因此落在该命名空间块内部的第一条语句位置，语义仍正确）。
     *
     * @return array{prefix:string, body:string}
     */
    public static function split(string $code): array
    {
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            return ['prefix' => '', 'body' => $code];
        }
        $openPos = (int) $m[0][1];
        $openEnd = $openPos + strlen((string) $m[0][0]);
        $prefix  = substr($code, 0, $openEnd);
        $body    = substr($code, $openEnd);

        $ws = '(?:[ \t\r\n]|\/\*.*?\*\/|\/\/[^\n]*(?:\n|\z)|#[^\n]*(?:\n|\z))*';
        // declare(...) ; —— 值必须是字面量，故用 [^;{}]* 限制在同一条语句内
        $declare = 'declare\s*\([^;{}]*\)\s*;';
        // namespace X; / namespace X {（`namespace\foo()` 运算符形式没有空白，不会误配）
        $ns = 'namespace\s+[A-Za-z_\x80-\xff\\\\][A-Za-z0-9_\x80-\xff\\\\]*\s*[;{]';

        while (true) {
            if (!preg_match('/\A(' . $ws . ')(' . $declare . '|' . $ns . ')/is', $body, $mm)) {
                break;
            }
            $prefix .= "\n" . $mm[2];
            $body = substr($body, strlen($mm[0]));
        }

        return ['prefix' => $prefix, 'body' => $body];
    }

    /**
     * token 流版本：返回"文件头可插入语句"的位置下标——即第一个 T_OPEN_TAG 之后、
     * 越过前导 declare(...) ; 与 namespace X; / namespace X { 之后的那个 token 下标。
     *
     * 供 token 层注入器（字符串密钥安装、废代码、统计钩子等）使用：
     * 在返回的下标处 splice 一条语句，既不会把 declare/namespace 挤成"非首语句"，
     * 括号式 namespace 时注入点又落在块内（语义仍正确）。
     * 找不到开标签时返回 -1（调用方自行兜底追加）。
     */
    public static function headInsertIndex(array $tokens): int
    {
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_OPEN_TAG) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n) {
                $t2 = $tokens[$j];
                if (is_array($t2)
                    && in_array($t2[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                    $j++;
                    continue;
                }
                if (is_array($t2) && $t2[0] === T_DECLARE) {
                    // 跳过 declare(...) ;（括号配对后遇 ';'' 结束）
                    $depth = 0;
                    while ($j < $n) {
                        $t3 = $tokens[$j];
                        if ($t3 === '(') {
                            $depth++;
                        } elseif ($t3 === ')') {
                            $depth--;
                        } elseif ($t3 === ';' && $depth === 0) {
                            $j++;
                            break;
                        }
                        $j++;
                    }
                    continue;
                }
                if (is_array($t2) && $t2[0] === T_NAMESPACE) {
                    // 跳过 namespace X; / namespace X {（插入点在 ';'/'{' 之后）
                    while ($j < $n) {
                        $t3 = $tokens[$j];
                        if ($t3 === ';' || $t3 === '{') {
                            $j++;
                            break;
                        }
                        $j++;
                    }
                    continue;
                }
                break;
            }
            return $j;
        }
        return -1;
    }

    /**
     * 序言归一（安全网）：强制 declare / namespace 序言位于文件头部。
     *
     * token 层注入器（动态垃圾、统计钩子、字符串密钥安装等）若把语句插进
     * declare 与 namespace 之间（或 declare 之前），产物直接致命：
     *   "Namespace declaration statement has to be the very first statement
     *    or after any declare call in the script"
     * 这类错位无法用 headInsertIndex 单点预防（先犯错的注入器会把后续注入器
     * 一起堵在错位点），故在 token 流出口做一次归一：把序言区里的"错位语句"
     * 整体搬到序言（declare / namespace）之后，头部约束恒成立。
     *
     * 幂等；无开标签的片段流原样返回。括号式 namespace X { 时错位语句搬进块内。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public static function normalizeTokens(array $tokens): array
    {
        $n = count($tokens);
        $ot = -1;
        for ($i = 0; $i < $n; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_OPEN_TAG) {
                $ot = $i;
                break;
            }
        }
        if ($ot < 0) {
            return $tokens;
        }

        $isWs = static fn ($t): bool => is_array($t)
            && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true);

        // namespace 语句 vs namespace\X 运算符（PHP 7.4 形态；PHP8 里后者是 T_NAME_RELATIVE）
        $isNsStmt = static function (int $i) use ($tokens, $n): bool {
            $t = $tokens[$i];
            if (!is_array($t)) {
                return false;
            }
            if ($t[0] === T_DECLARE) {
                return true;
            }
            if ($t[0] !== T_NAMESPACE) {
                return false;
            }
            if ($i + 1 < $n && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_NS_SEPARATOR) {
                return false;
            }
            return true;
        };

        // 消费一条 declare(...) ; 或 namespace X ;/namespace X { 语句，返回语句后的下标
        $consumeStmt = static function (int $i, bool $braceOk) use ($tokens, $n): int {
            $depth = 0;
            for (; $i < $n; $i++) {
                $t = $tokens[$i];
                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    $depth--;
                } elseif ($t === '{' && $braceOk && $depth === 0) {
                    return $i + 1;
                } elseif ($t === ';' && $depth === 0) {
                    return $i + 1;
                }
            }
            return $n;
        };

        // 反复搬运，直到序言区干净（每轮至少解决一个错位段，轮数受序言关键字数上界约束）
        for ($pass = 0; $pass < 32; $pass++) {
            $i = $ot + 1;
            $prologueEnd = null;   // namespace 语句结束点（一旦出现即终止序言）
            $d = null;             // 首个错位 token 下标
            while ($i < $n) {
                if ($isWs($tokens[$i])) {
                    $i++;
                    continue;
                }
                if ($isNsStmt($i)) {
                    $isNs = is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE;
                    $i = $consumeStmt($i, $isNs);
                    if ($isNs) {
                        $prologueEnd = $i;
                        break;
                    }
                    continue;
                }
                $d = $i;
                break;
            }
            if ($d === null || $prologueEnd !== null && $d >= $prologueEnd) {
                return $tokens; // 序言区无错位（或 namespace 之后的正文）——完成
            }
            // 找错位段之后的下一个序言关键字；找不到则无需搬动
            $k = null;
            for ($j = $d + 1; $j < $n; $j++) {
                if ($isNsStmt($j)) {
                    $k = $j;
                    break;
                }
            }
            if ($k === null) {
                return $tokens;
            }
            // 搬运区间 [d, k) → 下一条序言语句结束点之后
            $span = array_slice($tokens, $d, $k - $d);
            $isNs = is_array($tokens[$k]) && $tokens[$k][0] === T_NAMESPACE;
            $end = $consumeStmt($k, $isNs); // 原始下标：语句结束点
            array_splice($tokens, $d, $k - $d);
            $insertAt = $end - ($k - $d);
            if ($insertAt < 0) {
                $insertAt = 0;
            }
            array_splice($tokens, $insertAt, 0, $span);
            $n = count($tokens);
        }
        return $tokens;
    }
}
