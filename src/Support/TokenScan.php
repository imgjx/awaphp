<?php
/**
 * Token 边界扫描（TokenScan）
 *
 * 供"只插空白/注释、不改 token 结构"的注入器（NewlinePoison / MutualComments）共用：
 * 找出所有可以安全插入 `\n` 或 `// 注释` 的位置，避开会改变运行行为的区域。
 *
 * 安全性规则（宁可少插，不可插错）：
 *   - 双引号插值串 / heredoc / nowdoc / 反引号内部：插入会改变字符串内容 → 跳过；
 *   - `?>` 之后与 T_INLINE_HTML 段：那里是输出流，插空白会污染响应体 → 跳过；
 *   - `__halt_compiler()` 之后：属二进制/数据区 → 跳过；
 *   - 其余任意 token 之间都只是空白，插入对语义零影响。
 */

declare(strict_types=1);

final class TokenScan
{
    /**
     * @param array<int,mixed> $tokens token_get_all() 的结果
     * @param bool $skipHeaders 是否跳过"声明头部"（`function f(...): T` / `class X extends Y` 等
     *        `$kw` 到 `{`/`;` 之间的区段）。默认 true：在签名中间插注释/换行虽然语法合法，
     *        但会让所有"按文本扫描签名"的下游（含本工具的 void 返回类型判定、IDE/审计脚本）
     *        误判，且视觉上明显是机器插入 → 故一律避开。
     * @return array{bounds:array<int,int>, open:int} bounds=可插入的 token 下标（升序），open=首个 <?php 的下标（无则 -1）
     */
    public static function safeBoundaries(array $tokens, bool $skipHeaders = true): array
    {
        $n = count($tokens);
        $bounds = [];
        $open = -1;
        $inHeredoc = 0;
        $inDq = 0;
        $inBt = 0;
        $halt = false;
        $inHeader = false;
        $prevSig = '';

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            $tx = is_array($t) ? (string) $t[1] : (string) $t;

            if ($id === T_START_HEREDOC) {
                $inHeredoc++;
            } elseif ($id === T_END_HEREDOC) {
                if ($inHeredoc > 0) {
                    $inHeredoc--;
                }
            } elseif ($id === T_HALT_COMPILER) {
                $halt = true;
            }
            if ($open < 0 && $id === T_OPEN_TAG) {
                $open = $i;
            }
            if ($id === null) {
                if ($tx === '"') {
                    $inDq = 1 - $inDq;
                } elseif ($tx === '`') {
                    $inBt = 1 - $inBt;
                }
            }
            // 声明头部跟踪：T_FUNCTION/T_CLASS/... 之后到 `{` 或 `;` 之前不放插入点。
            // 注意 `Foo::class` 的 `class` 也是 T_CLASS，但它不是声明 → 用前一个有效 token
            // 是否为 `::` 来排除，否则会"永不开闭"地把后续所有插入点全部吞掉。
            if ($skipHeaders) {
                if ($id === T_CLASS && $prevSig === '::') {
                    // ::class：不是声明，忽略
                } elseif ($id === T_FUNCTION || $id === T_CLASS || $id === T_INTERFACE
                    || $id === T_TRAIT || (defined('T_ENUM') && $id === T_ENUM)) {
                    $inHeader = true;
                } elseif ($inHeader && $id === null && ($tx === '{' || $tx === ';')) {
                    $inHeader = false;
                }
            }
            // 记录"上一个非空白 token"（用于识别 ::class / ->class 之类）
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                $prevSig = is_array($t) ? ($id === T_DOUBLE_COLON ? '::' : '#t') : $tx;
            }

            if ($halt || $inHeredoc > 0 || $inDq > 0 || $inBt > 0) {
                continue;
            }
            if ($id === T_CLOSE_TAG || $id === T_INLINE_HTML || $id === T_END_HEREDOC) {
                continue;
            }
            if ($skipHeaders && $inHeader) {
                continue; // 签名中间：不插
            }
            $bounds[] = $i;
        }

        return ['bounds' => $bounds, 'open' => $open];
    }

    /**
     * 判断代码是否存在【顶层 return】（不在任何函数/闭包/类方法体内）。
     *
     * 配置 / provider / route 等"return 数组"文件靠顶层 return 把数组交给 `include` 的
     * 调用方（如 ThinkPHP `App::bind(include provider.php)`）。任何把整份文件包进
     * IIFE / eval() 的壳都会吞掉该返回值（include 退化为返回 1），故这类文件要么跳过壳、
     * 要么把 eval() 的返回值透传回文件顶层（EvalShell 的 propagate 模式）。
     *
     * 用 token 深度跟踪：`{`/`}` 与字符串插值开括号共同维护深度，depth===0 处的 T_RETURN
     * 即顶层。传入无开标签的片段（EvalShell 的 $inner）时自动补 `<?php ` 再分析。
     */
    public static function hasTopLevelReturn(string $code): bool
    {
        $toks = @token_get_all($code);
        $hasOpen = false;
        foreach ($toks as $t) {
            if (is_array($t) && $t[0] === T_OPEN_TAG) {
                $hasOpen = true;
                break;
            }
        }
        if (!$hasOpen) {
            // 纯 PHP 片段（无开标签）：补标签后按脚本解析，避免整段被当成 T_INLINE_HTML
            $toks = @token_get_all('<?php ' . $code);
        }
        $depth = 0;
        foreach ($toks as $t) {
            if (is_array($t)) {
                $tt = $t[0];
                if ($tt === T_CURLY_OPEN
                    || (defined('T_DOLLAR_OPEN_CURLY_BRACES') && $tt === T_DOLLAR_OPEN_CURLY_BRACES)) {
                    $depth++;
                    continue;
                }
                if ($tt === T_RETURN && $depth === 0) {
                    return true;
                }
            } else {
                if ($t === '{') {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                }
            }
        }
        return false;
    }
}
