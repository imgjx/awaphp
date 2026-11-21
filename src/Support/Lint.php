<?php
/**
 * 进程内语法检查（Lint）
 *
 * 与 `php -l` 等价的一次性语法门禁，但不 fork 子进程：
 * `token_get_all($code, TOKEN_PARSE)` 走的就是 PHP 自己的 parser，
 * 语法错误直接抛 `ParseError`。单次耗时是微秒级，而 `php -l` 每次
 * 都要起一个进程（30~80 ms），逐层校验时占到构建时间的绝大部分。
 *
 * 定位：
 *   - **快路径**（默认）：进程内解析，只判「语法是否成立」，失败时不带细节；
 *   - **慢路径**（仅失败时）：交给 `php -l` 落盘复验，拿到可读的错误文本。
 * 两者用的是同一个 parser，正常产物上结论一致；只有真出错时才付出进程开销。
 *
 * 注意：`php -l` 多做一层编译期检查（重复函数名、strict_types 位置等），
 * 这里不做——那种错误在产物运行时会立刻暴露，且构建期无法可靠复现
 * （同一份 token 流在不同 php 版本下结论可能不同）。语法门禁的职责是
 * 「别产出无法解析的文件」，这一层由本类承担。
 *
 * 唯一例外：命名空间形状检查（见 {@see self::namespaceShapeOk()}）。
 * 它也是编译期错误，但**不会**在运行期暴露——壳层把载荷塞进 eval 后，
 * `php -l` 只看到密文，而运行期一旦命中就是 fatal
 * `Namespace declarations cannot be nested`，整份产物直接白屏。
 * 这类错误必须在构建期拦住，否则「自保护回滚」根本没机会触发。
 */

declare(strict_types=1);

final class Lint
{
    /** 进程内语法是否成立（不落盘、不起进程） */
    public static function ok(string $code): bool
    {
        if ($code === '') {
            return true;
        }
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError) {
            return false;
        } catch (Throwable) {
            // 极端输入（超长嵌套等）下 parser 可能抛别的异常：一律按不通过处理
            return false;
        }
        // 语法成立不等于 compile 成立：命名空间形状是唯一一类"parser 放行、
        // 编译期拒绝、运行期才炸"的错误，必须在这里补判（见类注释）。
        return self::namespaceShapeOk($code);
    }

    /**
     * 命名空间声明形状是否合法（编译期规则，parser 不查）：
     *
     *   - 只能有一种写法：全部 `namespace X;` 或全部 `namespace X { … }`，混用非法；
     *   - 括号写法里不允许嵌套命名空间（`namespace A { namespace B { … } }`）；
     *   - 括号写法里除了 declare 之外不允许有命名空间块之外的语句
     *     （"No code may exist outside of namespace {}"）。
     *
     * 这三种形态在壳层 eval 之后都是**致命错误**，而 `php -l` 在载荷被压缩/编码
     * 之后再也看不见它们——所以必须在明文阶段判掉，让上层走回滚。
     */
    private static function namespaceShapeOk(string $code): bool
    {
        if (stripos($code, 'namespace') === false) {
            return true; // 绝大多数文件走这条：一次 substring 扫描就退出
        }
        $tokens = @token_get_all($code);
        $semicolonMode = false;
        $bracketMode = false;
        $depth = 0;            // 当前花括号深度
        $nsDepth = -1;         // 当前所处括号命名空间块的深度（-1 = 不在块内）
        $outsideCode = false;  // 括号写法下，"命名空间块之外出现了语句"
        $skipToSemi = false;   // declare(...) 期间不累计块外语句
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    continue;
                }
                if ($t[0] === T_DECLARE) {
                    $skipToSemi = true;
                    continue;
                }
                if ($t[0] !== T_NAMESPACE) {
                    if ($bracketMode && $nsDepth === -1 && $depth === 0) {
                        $outsideCode = true;
                    }
                    continue;
                }
                // ---- 命名空间声明（`namespace\foo()` 运算符形态不算，PHP 8 里它是
                //      T_NAME_RELATIVE，7.4 里是 T_NAMESPACE + '\'，这里兜一层） ----
                $j = $i + 1;
                $next = self::nextSig($tokens, $j);
                if ($next !== null && ($next[1] === '\\'
                    || $next[1] === T_NS_SEPARATOR || $next[1] === T_NAME_RELATIVE)) {
                    continue; // `namespace\foo()`：运算符，不是声明
                }
                // 跳过命名空间名（T_STRING / 带反斜杠的限定名 / `namespace {` 无名形态），
                // 再找终止符：'{' 括号写法 / ';' 语句写法
                $terminator = '';
                while ($j < $n) {
                    $nx = self::nextSig($tokens, $j);
                    if ($nx === null) {
                        break;
                    }
                    $tok = $tokens[$nx[0]];
                    if (is_array($tok) && in_array($tok[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED,
                        T_NAME_FULLY_QUALIFIED], true)) {
                        $j = $nx[0] + 1;
                        continue;
                    }
                    $terminator = is_string($tok) ? $tok : (string) $tok[1];
                    break;
                }
                if ($bracketMode && $depth > ($nsDepth < 0 ? 0 : $nsDepth)) {
                    return false; // 嵌套命名空间块（括号写法里再出现 namespace）
                }
                if ($terminator === '{' && $semicolonMode) {
                    return false; // 两种写法混用（先 ; 后 {）
                }
                if ($terminator !== '{' && $bracketMode) {
                    return false; // 两种写法混用（先 { 后 ;）
                }
                if ($terminator === '{') {
                    $bracketMode = true;
                    $nsDepth = $depth;   // 块内的 '{' 由后续深度计数消费
                    $outsideCode = false; // 进入块内，之前的"块外"计数作废
                } else {
                    $semicolonMode = true;
                }
                continue;
            }
            if ($t === '{') {
                $depth++;
                continue;
            }
            if ($t === '}') {
                $depth--;
                if ($nsDepth >= 0 && $depth <= $nsDepth) {
                    $nsDepth = -1; // 命名空间块收尾
                }
                continue;
            }
            if ($t === ';') {
                $skipToSemi = false;
                continue;
            }
            if ($skipToSemi) {
                continue;
            }
            if ($bracketMode && $nsDepth === -1 && $depth === 0 && trim($t) !== '') {
                $outsideCode = true;
            }
        }
        if ($bracketMode && $outsideCode) {
            return false;
        }
        return true;
    }

    /**
     * 下一个"有效" token（跳过空白与注释）。
     *
     * @param array<int,mixed> $tokens
     * @return array{0:int,1:mixed}|null [下标, token 类型或单字符]
     */
    private static function nextSig(array $tokens, int $from): ?array
    {
        $n = count($tokens);
        for ($k = $from; $k < $n; $k++) {
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return [$k, is_array($t) ? $t[0] : $t];
        }
        return null;
    }

    /**
     * 语法检查 + 失败时的可读诊断。
     * 只有 `$detail` 为真且快路径失败时才落盘跑 `php -l`。
     *
     * @return array{ok:bool, output:string}
     */
    public static function check(string $code, bool $detail = true): array
    {
        if (self::ok($code)) {
            return ['ok' => true, 'output' => ''];
        }
        if (!$detail) {
            return ['ok' => false, 'output' => ''];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'obf_');
        if ($tmp === false) {
            return ['ok' => false, 'output' => 'syntax error (detail unavailable)'];
        }
        file_put_contents($tmp, $code);
        $output = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1') ?: '';
        @unlink($tmp);
        return ['ok' => false, 'output' => $output];
    }
}
