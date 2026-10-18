<?php
/**
 * 随机源（含盐分熵）
 *
 * 提供带盐的确定性伪随机，便于在不依赖 Salt::identifier 的场景（如死代码注入）
 * 生成可复现的内容。注意：PHP 的随机数与平台实现相关，这里只用于"非关键命名"的填充。
 */

declare(strict_types=1);

final class Random
{
    private int $seed;

    public function __construct(private string $salt = '')
    {
        $this->seed = $this->salt === '' ? 0x9e3779b9 : unpack('N', substr(md5($this->salt), 0, 4))[1];
    }

    /** 返回 [0, $max) 的整数（线性同余，确定性） */
    public function int(int $max): int
    {
        $this->seed = (int) ((($this->seed * 1103515245) + 12345) & 0x7fffffff);
        return $this->seed % $max;
    }

    /** 从字符集里取长度为 $len 的随机串 */
    public function string(int $len, string $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string
    {
        $out = '';
        $n = strlen($chars);
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[$this->int($n)];
        }
        return $out;
    }
}
