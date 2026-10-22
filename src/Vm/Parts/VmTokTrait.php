<?php
/**
 * VmCompiler 碎片：token 导航与工具碎片：取签名 / 跳过属性块 / detokenize。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性/方法；拆分的只是源码组织，不影响行为。
 */

declare(strict_types=1);

trait VmTokTrait
{

    /**
     * 把一条语句的 token 流还原为 PHP 源码文本（供 EVAL_STMT 运行期 eval）。
     *  - 保留 T_INLINE_HTML / 开闭标签：eval('?>html') 合法且会原样输出；
     *  - 丢弃注释：`//`/`#` 单行注释的 token 文本不含换行，直接拼接会把后续
     *    代码注释掉（多行注释含换行本可保留，统一丢弃更稳）；
     *  - heredoc/nowdoc 的各段 token 文本自带换行，拼接后结构完整；
     *  - 补分号：StatementSplitter 把 `;` 当分隔符消费掉了，需补回。
     *    块语句（if/function/class...）末尾的 `;` 是合法空语句；含 inline HTML
     *    的语句不补（分号会泄漏到输出）。
     * @param array<int,mixed> $stmt
     */
    private function detokenize(array $stmt): string
    {
        $out = '';
        $hasHtml = false;
        foreach ($stmt as $tk) {
            if (is_array($tk)) {
                if (in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($tk[0] === T_INLINE_HTML || $tk[0] === T_CLOSE_TAG) {
                    $hasHtml = true;
                }
                $out .= $tk[1];
            } else {
                $out .= $tk;
            }
        }
        if (!$hasHtml) {
            $out .= ';';
        }
        return $out;
    }

    /** 取 token 类型（数组型返回 $tok[0]，符号型返回原字符） */
    private function tokType($tok)
    {
        return is_array($tok) ? $tok[0] : $tok;
    }

    /**
     * 替代语法（alt syntax）规范化：把 `if (c): ... endif;` 一族改写成等价的
     * 花括号形式 `if (c) { ... }`，使下游各语句编译器的 `{` 查找逻辑无需
     * 感知冒号体。三类局部替换（不依赖配对计数，天然支持嵌套）：
     *   1. 控制头 `:`（if/while/for/foreach/switch 条件括号后的冒号）→ `{`；
     *   2. elseif/else 关键字之前的那个顶层 `;` → `}`（闭合前一子句）；
     *   3. end* 关键字（endif/endwhile/endfor/endforeach/endswitch）→ `}`，
     *      并吞掉其后紧跟的 `;`。
     * 无冒号体的语句原样返回（零成本）。
     *
     * 安全性：替换只在 pdepth=0 的控制头/end 关键字处发生；三元 `?:`、case
     * 标签、数组 `=>`（T_DOUBLE_ARROW 非裸字符）、goto 标签均不受影响。
     * @param array<int,mixed> $stmt
     * @return array<int,mixed>
     */
    private function normalizeAltSyntax(array $stmt): array
    {
        $n = count($stmt);
        $hasAlt = false;
        for ($i = 0; $i < $n; $i++) {
            $t = $stmt[$i];
            if (is_array($t) && in_array($t[0], [T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH], true)) {
                $hasAlt = true;
                break;
            }
        }
        if (!$hasAlt) {
            return $stmt;
        }
        $out = [];
        $pdepth = 0;
        $bdepth = 0;
        $edepth = 0; // 已改写的 '{' 深度（规范化的花括号）
        for ($i = 0; $i < $n; $i++) {
            $t = $stmt[$i];
            if (is_array($t)) {
                // end* 关键字 → '}'，吞掉紧随的 ';'
                if (in_array($t[0], [T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH], true)) {
                    if ($edepth > 0) {
                        $edepth--;
                    }
                    $out[] = '}';
                    $nx = $i + 1;
                    while ($nx < $n && is_array($stmt[$nx])
                        && in_array($stmt[$nx][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $nx++;
                    }
                    if ($nx < $n && $stmt[$nx] === ';') {
                        $i = $nx;
                    }
                    continue;
                }
                // elseif / else：其前一个顶层 ';' 改为 '}'（闭合前一子句）
                if (in_array($t[0], [T_ELSEIF, T_ELSE], true) && $pdepth === 0 && $bdepth === 0) {
                    $last = null;
                    for ($j = count($out) - 1; $j >= 0; $j--) {
                        $cand = $out[$j];
                        if (is_array($cand) && in_array($cand[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        $last = $j;
                        break;
                    }
                    if ($last !== null && $out[$last] === ';') {
                        $out[$last] = '}';
                        if ($edepth > 0) {
                            $edepth--;
                        }
                    }
                }
                $out[] = $t;
                continue;
            }
            if ($t === '(') {
                $pdepth++;
            } elseif ($t === ')') {
                if ($pdepth > 0) {
                    $pdepth--;
                }
            } elseif ($t === '[') {
                $bdepth++;
            } elseif ($t === ']') {
                if ($bdepth > 0) {
                    $bdepth--;
                }
            } elseif ($t === ':' && $pdepth === 0 && $bdepth === 0 && $edepth >= 0
                && $this->prevIsCtrlHead($stmt, $i)) {
                // 控制头冒号 → '{'
                $edepth++;
                $out[] = '{';
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * $i 处的 ':' 前是否是控制结构头（if/elseif/while/for/foreach/switch 的
     * 条件括号）。与 StatementSplitter::isAltSyntaxColon 同规则：':' 前是 ')'，
     * 该 ')' 的匹配 '(' 之前是上述关键字；elseif/else 的 ':' 同样成立
     * （elseif 的条件括号、else 无括号——else 的 ':' 前是 T_ELSE）。
     * @param array<int,mixed> $tokens
     */
    private function prevIsCtrlHead(array $tokens, int $i): bool
    {
        $prev = -1;
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $prev = $j;
            break;
        }
        if ($prev < 0) {
            return false;
        }
        if (is_array($tokens[$prev])) {
            // `else:` 的 ':' 前直接是 T_ELSE
            return $tokens[$prev][0] === T_ELSE;
        }
        if ($tokens[$prev] !== ')') {
            return false;
        }
        $pd = 1;
        $open = -1;
        for ($j = $prev - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === ')') {
                    $pd++;
                } elseif ($t === '(') {
                    $pd--;
                    if ($pd === 0) {
                        $open = $j;
                        break;
                    }
                }
            }
        }
        if ($open < 0) {
            return false;
        }
        for ($j = $open - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($t) && in_array($t[0], [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH], true);
        }
        return false;
    }

    /** 去掉 token 序列首尾的空白/注释 token。 */
    private function trimWs(array $tokens): array
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        while (!empty($tokens) && is_array($tokens[0]) && in_array($tokens[0][0], $skip, true)) {
            array_shift($tokens);
        }
        while (!empty($tokens) && is_array(end($tokens)) && in_array(end($tokens)[0], $skip, true)) {
            array_pop($tokens);
        }
        return $tokens;
    }

    /** @param array<int,mixed> $tokens */
    private function firstSig(array $tokens)
    {
        foreach ($tokens as $t) {
            if (is_array($t) && !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $t[0];
            }
        }
        return null;
    }

    /**
     * 跳过 $attrIdx 处 T_ATTRIBUTE 起始的属性块，返回块后第一个 token 下标。
     * 属性参数里可能出现嵌套括号/数组（#[Attr([1,2])]），按字符串 token 深度配对；
     * 字符串/注释 token 内的括号天然不计（is_array 即跳过）。
     * 注意：T_ATTRIBUTE 的文本是 "#["——开方括号被吞在 token 文本里，
     * 故初始深度为 1，遇到配对的裸 "]" 才算块结束。
     */
    private function skipAttributeBlock(array $tokens, int $attrIdx): int
    {
        $n = count($tokens);
        $i = $attrIdx + 1;
        $depth = 1;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '[' || $t === '(' || $t === '{') {
                    $depth++;
                } elseif ($t === ']' || $t === ')' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }
            $i++;
        }
        return $i; // 异常形态（属性块未闭合）：跳到末尾
    }

    /**
     * 去掉语句最前面的若干属性块（#[...]，可多个叠加）。
     * 属性可出现在类/函数/成员声明之前；VM 的声明分发与成员解析都必须
     * 先剥掉它，否则 `#[A] final class X {}` 会被当作普通表达式语句
     * 走 EVAL_STMT 兜底（见 effectiveDeclSig 的说明）。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function stripLeadingAttributes(array $tokens): array
    {
        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipAttributeBlock($tokens, $i);
                continue;
            }
            break;
        }
        return $i === 0 ? $tokens : array_slice($tokens, $i);
    }

    /**
     * 取语句的"有效声明关键字"：跳过前导属性块与类修饰符
     * （final / abstract / readonly）。
     *
     * 为什么不能直接用 firstSig：`final class Foo {}` 的裸首 token 是
     * T_FINAL、`#[A] class Foo {}` 是 T_ATTRIBUTE——按裸首 token 分发会把
     * 整条类/函数声明错送 compileStatement 的 EVAL_STMT 兜底。类被原生
     * 定义后，其方法体内引用 VM 函数表符号（用户函数已被编译进字节码、
     * 原定义被移除）的调用会直接致命：Call to undefined function。
     */
    private function effectiveDeclSig(array $tokens): ?int
    {
        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipAttributeBlock($tokens, $i);
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                $i++;
                continue;
            }
            if (is_array($t)) {
                return $t[0];
            }
            return null; // 语句以裸字符起始（表达式语句）
        }
        return null;
    }

    /** @param array<int,mixed> $tokens */
    private function firstString(array $tokens)
    {
        foreach ($tokens as $t) {
            if (is_array($t) && !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return is_array($t) && $t[0] === T_STRING ? $t[1] : null;
            }
        }
        return null;
    }

    /** @param array<int,mixed> $stmt */
    private function nextIsBlockEnd(array $stmt): bool
    {
        return false;
    }
}
