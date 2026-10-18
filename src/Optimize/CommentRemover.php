<?php
/**
 * 移除注释（CommentRemover）
 *
 * 当 --no-comments 开启时，删除 T_COMMENT / T_DOC_COMMENT 标记。
 * 注意：开标签/闭标签、行内 HTML 等不在移除范围。
 */

declare(strict_types=1);

final class CommentRemover
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        if (!$config->get('no_comments')) {
            return $tokens;
        }
        $out = [];
        foreach ($tokens as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $tok;
        }
        $this->logger->debug('已移除注释');
        return $out;
    }
}
