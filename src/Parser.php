<?php
/**
 * PHP 源码解析封装
 *
 * 基于 token_get_all 的源码解析，保留 token 位置与结构，并提供"相邻有效 token"
 * 的辅助查询，供各混淆模块做上下文判断（避免脆弱的字符串替换）。
 */

declare(strict_types=1);

final class Parser
{
    /**
     * 将源码切分为 token 数组。
     * 每个元素：单字符符号为 string；词法符号为 [type, text, line]。
     * @return array<int, mixed>
     */
    public static function tokenize(string $code): array
    {
        $tokens = @token_get_all($code);
        // token_get_all 对未闭合的 heredoc 等会返回 false，做兜底
        if (!is_array($tokens)) {
            throw new RuntimeException('源码无法被 tokenizer 解析（可能存在语法错误）');
        }
        return $tokens;
    }

    /** 是否为数组型 token（即带类型的词法符号） */
    public static function isToken($tok): bool
    {
        return is_array($tok);
    }

    /** 取 token 类型（单字符返回其 ASCII 值，数组返回 token 类型常量） */
    public static function type($tok)
    {
        return is_array($tok) ? $tok[0] : $tok;
    }

    /** 取 token 文本 */
    public static function text($tok): string
    {
        return is_array($tok) ? $tok[1] : $tok;
    }

    /**
     * 从 $i 向【前】找到第 $step 个"非空白非注释"的 token 索引。
     * 找不到返回 -1。
     */
    public static function prevSignificant(array $tokens, int $i, int $step = 1): int
    {
        $count = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (self::isWhitespaceOrComment($tokens[$j])) {
                continue;
            }
            if (++$count === $step) {
                return $j;
            }
        }
        return -1;
    }

    /**
     * 从 $i 向【后】找到第 $step 个"非空白非注释"的 token 索引。
     */
    public static function nextSignificant(array $tokens, int $i, int $step = 1): int
    {
        $count = 0;
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            if (self::isWhitespaceOrComment($tokens[$j])) {
                continue;
            }
            if (++$count === $step) {
                return $j;
            }
        }
        return -1;
    }

    public static function isWhitespaceOrComment($tok): bool
    {
        if (!is_array($tok)) {
            return false;
        }
        return in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true);
    }

    /** 判断 token 是否处于 heredoc / nowdoc 区域内（用于跳过危险重命名） */
    public static function isInsideHeredoc(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = 0; $j <= $i; $j++) {
            $t = $tokens[$j];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_START_HEREDOC) {
                $depth++;
            } elseif ($t[0] === T_END_HEREDOC) {
                $depth--;
            }
        }
        return $depth > 0;
    }
}
