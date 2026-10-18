<?php
/**
 * 数字编码（NumberEncoder）
 *
 * 将数字常量改写为等价的"看起来不一样"的表达式：
 *  - 非负整数：0x 十六进制 / (N+0) / (N-0) / (N*1) / ((N)) 随机选取
 *  - 负数（由单独的 '-' + 正数构成）：对正数部分编码，符号保留
 *  - 浮点：((N)) / (N*1.0) 等形式
 *
 * 数字表达式依旧是常量表达式，故在常量上下文中也安全（如数组键、for 初值、下标）。
 */

declare(strict_types=1);

final class NumberEncoder
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        if (!$config->get('num_enc')) {
            return $tokens;
        }
        $rand = new Random($this->salt->getSalt() . ':num');
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            if ($tok[0] === T_LNUMBER) {
                $tokens[$i][1] = $this->encodeInt($this->parseInt($tok[1]), $rand);
            } elseif ($tok[0] === T_DNUMBER) {
                $tokens[$i][1] = $this->encodeFloat($this->parseFloat($tok[1]), $rand);
            }
        }
        $this->logger->debug('数字编码：数字常量已改写');
        return $tokens;
    }

    private function encodeInt(int $v, Random $rand): string
    {
        // 注意：单独的 '-' 由上游 token 处理，这里只编码非负幅度
        $abs = (string) abs($v);
        if (!ctype_digit($abs) || (int) $abs > PHP_INT_MAX) {
            return '(' . $v . ')';
        }
        $n = (int) $abs;
        $forms = [
            '(0x' . dechex($n) . ')',
            '(0X' . strtoupper(dechex($n)) . ')',
            '(0b' . decbin($n) . ')',
            '(0' . decoct($n) . ')',
            '(' . $abs . '+0)',
            '(' . $abs . '-0)',
            '(' . $abs . '*1)',
            '((' . $abs . '))',
            '(' . $abs . '^0)',
            '(' . $abs . '|0)',
            '(' . $abs . '&' . $abs . ')',
        ];
        $chosen = $forms[$rand->int(count($forms))];
        return $v < 0 ? '-' . $chosen : $chosen;
    }

    private function encodeFloat(float $v, Random $rand): string
    {
        $repr = var_export($v, true);
        $forms = [
            '((' . $repr . '))',
            '(' . $repr . '*1.0)',
            '(' . $repr . '+0.0)',
            '(' . $repr . '-0.0)',
            '(1.0*' . $repr . ')',
            '(' . $repr . '/1.0)',
        ];
        return $forms[$rand->int(count($forms))];
    }

    /** 正确解析整数字面量（支持十进制、0x 十六进制、0b 二进制、0 前缀八进制） */
    private function parseInt(string $text): int
    {
        $text = trim($text);
        if (strlen($text) > 2 && (str_starts_with($text, '0x') || str_starts_with($text, '0X'))) {
            return (int) hexdec(substr($text, 2));
        }
        if (strlen($text) > 2 && (str_starts_with($text, '0b') || str_starts_with($text, '0B'))) {
            return (int) bindec(substr($text, 2));
        }
        if (strlen($text) > 1 && $text[0] === '0' && ctype_digit($text)) {
            return (int) octdec($text);
        }
        return (int) $text;
    }

    private function parseFloat(string $text): float
    {
        return (float) $text;
    }
}
