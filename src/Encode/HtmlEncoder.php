<?php
/**
 * HTML 编码辅助（HtmlEncoder）
 *
 * 仅作为 StringEncoder 的辅助：对"看起来像 HTML"的字符串先进行实体编码，
 * 再由 StringEncoder 统一做 base64 分段，运行时通过
 *   htmlspecialchars_decode(base64_decode(...), ENT_QUOTES|ENT_HTML5)
 * 还原。
 */

declare(strict_types=1);

final class HtmlEncoder
{
    public const FLAGS = ENT_QUOTES | ENT_HTML5;

    public function isHtmlLike(string $s): bool
    {
        return str_contains($s, '<') || str_contains($s, '&');
    }

    /** 对字符串做 HTML 实体编码（用于可被 HTML 解码还原的场景） */
    public function encode(string $s): string
    {
        return htmlspecialchars($s, self::FLAGS);
    }

    /** 运行时还原表达式包裹（接收已拼接好的 base64 解码表达式） */
    public function wrapDecode(string $base64ConcatExpr): string
    {
        return 'htmlspecialchars_decode(' . $base64ConcatExpr . ', ENT_QUOTES|ENT_HTML5)';
    }
}
