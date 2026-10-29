<?php
/**
 * 小型确定性 PRNG（mulberry32），种子来自 Salt 派生字节。
 * 仅用于对抗性注释/换行的随机分布——相同盐 => 相同产物（可复现构建）。
 */

declare(strict_types=1);

final class ConfrontRng
{
    private int $state;

    public function __construct(string $seed)
    {
        $h = 0x9e3779b9;
        $n = strlen($seed);
        for ($i = 0; $i < $n; $i++) {
            $h ^= ord($seed[$i]);
            $h = (int) (($h + (($h << 6) + ($h >> 2))) & 0x7fffffff);
        }
        $this->state = $h ?: 1;
    }

    public function nextFloat(): float
    {
        $this->state = (int) ((($this->state + 0x6d2b79f5) & 0x7fffffff) ?: 1);
        $t = $this->state;
        $t = (int) (((($t ^ ($t >> 15)) * 0x2c1b3c6d) & 0x7fffffff) ?: 1);
        $t = (int) (($t ^ ($t >> 12)) & 0x7fffffff);
        return $t / 0x7fffffff;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        return $min + (int) ($this->nextFloat() * ($max - $min + 1));
    }
}
