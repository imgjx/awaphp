<?php
/**
 * 保留/去除换行符、代码压缩（Whitespace）
 *
 * - 普通模式：保留原样。
 * - --strip-newline：将所有空白 token 折叠为单个空格（去换行，保留单行结构）。
 * - --compress：删除所有空白 token（配合 Obfuscator::emit 的"按需补空格"规则，
 *   保证相邻词素不会粘连），实现紧凑单行输出。
 *
 * 行内 HTML（T_INLINE_HTML）与字符串内部空白不受影响。
 */

declare(strict_types=1);

final class Whitespace
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
        $compress = (bool) $config->get('compress');
        $strip = (bool) $config->get('strip_newline');

        if (!$compress && !$strip) {
            return $tokens;
        }

        $out = [];
        foreach ($tokens as $tok) {
            if (!is_array($tok)) {
                $out[] = $tok;
                continue;
            }
            if ($tok[0] === T_WHITESPACE) {
                if ($compress) {
                    continue; // 丢弃
                }
                // strip_newline：折叠为单个空格
                $out[] = [T_WHITESPACE, ' ', $tok[2] ?? 0];
                continue;
            }
            if ($tok[0] === T_OPEN_TAG) {
                // 去掉开标签尾部换行，避免压缩后粘连
                $text = rtrim($tok[1]) . ' ';
                $out[] = [$tok[0], $text, $tok[2] ?? 0];
                continue;
            }
            $out[] = $tok;
        }
        if ($compress) {
            $this->logger->debug('已执行代码压缩');
        } elseif ($strip) {
            $this->logger->debug('已去除换行符');
        }
        return $out;
    }
}
