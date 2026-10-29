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

        // 关键安全步骤：去换行后，行注释（// 与 #）会把【紧随其后的同一行代码】整段
        // 变成注释内容（"$a=1; // note" 折成一行时 "// note" 之后若有语句就会被吞掉，
        // 甚至把 heredoc 起始符 <<<EOT 一起注释掉，后续 EOT 体变成游离语句）。
        // 故在压缩/去换行之前统一把它们转成块注释：块注释不依赖换行，语义不变。
        $tokens = self::normalizeLineComments($tokens);

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

    /**
     * 把行注释（// 与 #）改写为块注释（/* ... *\/）。
     * 行注释的结束依赖换行，一旦换行被删就会吞掉后续代码；块注释不依赖换行。
     * 注释体里若出现 *\/ 或 ?> 会破坏解析状态，先做无害化替换。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private static function normalizeLineComments(array $tokens): array
    {
        foreach ($tokens as &$tok) {
            if (!is_array($tok) || $tok[0] !== T_COMMENT) {
                continue;
            }
            $t = (string) $tok[1];
            if ($t === '' || ($t[0] !== '#' && !str_starts_with($t, '//'))) {
                continue; // 已经是 /* */ 块注释
            }
            $body = str_starts_with($t, '//') ? substr($t, 2) : substr($t, 1);
            $body = str_replace(['*/', '?>'], ['* /', '? >'], $body);
            $tok[1] = '/*' . $body . '*/';
        }
        unset($tok);
        return $tokens;
    }
}
