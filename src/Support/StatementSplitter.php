<?php
/**
 * 语句切分器（StatementSplitter）
 *
 * 将一个 token 序列（函数体内部 / 整个文件顶层）切分为若干"顶层语句"单元。
 * 每个单元要么是顺序表达式语句（以 ; 结尾），要么是含括号的控制结构（if/for/while/...）。
 *
 * 关键正确性保障：
 *  - 只在"函数体深度 0"的 ; 或 } 处切分，避免切断括号内的语句。
 *  - 区分【语句块花括号】（if/for/while/函数声明/类体，pdepth=0）与【表达式内
 *    花括号】（闭包体/match 体/匿名类体，出现在括号内或表达式内）：后者只累计
 *    表达式括号深度 eDepth，绝不触发语句终结——否则 usort($a, function(){...;})
 *    会在闭包体的 } 处被误切成两半，detokenize 产出非法 PHP。
 *  - 识别 elseif / else / catch / finally 续接：这些关键字前的 } 不算语句终结。
 *  - 支持替代语法 endif/endfor/... 的 ; 终结。
 *
 * 历史注记：早期版本不识别表达式内花括号，调用方须自行跳过匿名函数/match 等
 * 作用域；现版本按 pdepth/eDepth 自动区分语句块与表达式括号，无需调用方规避。
 */

declare(strict_types=1);

final class StatementSplitter
{
    /**
     * 将 token 数组切分为顶层语句列表，每个元素为 token 数组。
     * @param array<int,mixed> $tokens
     * @return array<int,array<int,mixed>>
     */
    public static function split(array $tokens): array
    {
        $out = [];
        $cur = [];
        $depth = 0;   // 大括号深度（语句块）
        $pdepth = 0;  // 圆括号深度
        $bdepth = 0;  // 方括号深度
        $eDepth = 0;  // 表达式内花括号深度（闭包体/match 体/匿名类体，非语句边界）
        $iDepth = 0;  // 插值串深度（"{$expr}" / "${expr}" 的成对花括号，不计为语句块边界）
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $isArr = is_array($t);
            $type = $isArr ? $t[0] : null;
            $char = is_string($t) ? $t : null;

            $cur[] = $t;

            if ($char === '(') {
                $pdepth++;
            } elseif ($char === ')') {
                if ($pdepth > 0) {
                    $pdepth--;
                }
            } elseif ($char === '[') {
                $bdepth++;
            } elseif ($char === ']') {
                if ($bdepth > 0) {
                    $bdepth--;
                }
            } elseif ($type === T_CURLY_OPEN) {
                // 插值串 "{$expr}" 起始：成对花括号，不计为语句块边界
                $iDepth++;
            } elseif ($type === T_DOLLAR_OPEN_CURLY_BRACES) {
                // 插值串 "${expr}" 起始（token 形如 "${"）
                $iDepth++;
            } elseif ($char === '{') {
                // 语句块花括号只出现在 pdepth=0 的控制结构/声明之后；出现在括号内
                // （闭包/match/匿名类体）或已处于表达式括号上下文中的，一律按表达式
                // 括号处理——它们的 } 不是语句终结符。
                if ($pdepth > 0 || $eDepth > 0) {
                    $eDepth++;
                } else {
                    $depth++;
                }
            } elseif ($char === '}') {
                if ($iDepth > 0) {
                    // 插值串闭合：只减插值深度，绝不触发语句结束
                    $iDepth--;
                } elseif ($eDepth > 0) {
                    // 表达式括号闭合（闭包/match/匿名类体）：同样不终结语句
                    $eDepth--;
                } else {
                    if ($depth > 0) {
                        $depth--;
                    }
                    if ($depth === 0 && !self::nextIsContinuation($tokens, $i)) {
                        self::flush($out, $cur);
                    }
                }
            } elseif ($char === ';') {
                if ($depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    self::flush($out, $cur);
                }
            } elseif ($type !== null && in_array($type, [T_ENDIF, T_ENDFOR, T_ENDWHILE, T_ENDFOREACH, T_ENDSWITCH], true)) {
                if ($depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    self::flush($out, $cur);
                }
            }
        }

        if (!empty($cur)) {
            self::flush($out, $cur);
        }
        return $out;
    }

    /**
     * 下一个有效 token 是否为 else / elseif / catch / finally / while
     * （即当前 } 不终结语句）。while 是 do-while 的续体从句：
     * `do { ... } while (cond);` 的 } 之后必须继续吃到 while(...) 与终结分号，
     * 否则语句被拦腰切断，编译/回退都会产出缺 while 的残缺语句。
     * @param array<int,mixed> $tokens
     */
    private static function nextIsContinuation(array $tokens, int $i): bool
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if (!is_array($t)) {
                continue;
            }
            $type = $t[0];
            if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return in_array($type, [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY, T_WHILE], true);
        }
        return false;
    }

    /**
     * 将当前累积的 token 收尾为一个语句（去掉首尾空白/注释），非空则入列。
     * @param array<int,array<int,mixed>> $out
     * @param array<int,mixed> $cur
     */
    private static function flush(array &$out, array &$cur): void
    {
        // 去掉前导空白/注释
        while (!empty($cur) && is_array($cur[0]) && in_array($cur[0][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            array_shift($cur);
        }
        // 去掉尾随空白/注释
        while (!empty($cur)) {
            $last = end($cur);
            if (is_array($last) && in_array($last[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                array_pop($cur);
            } else {
                break;
            }
        }
        // 剔除语句终结符 ';'（顶层 ; 永远是终结符，不应成为独立空语句）。
        // 若剔除后语句为空（如裸 ";" 空语句），则直接丢弃，避免下游 parsePrimary 误解析。
        while (!empty($cur) && is_string($cur[0]) && $cur[0] === ';') {
            array_shift($cur);
        }
        while (!empty($cur)) {
            $last = end($cur);
            if (is_string($last) && $last === ';') {
                array_pop($cur);
            } else {
                break;
            }
        }
        if (!empty($cur)) {
            $out[] = $cur;
        }
        $cur = [];
    }
}
