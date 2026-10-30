<?php
/**
 * 轻量性能工作量（perf workload, VM 档用）
 * ============================================================
 * 为什么还要一份"轻"的：VM 档（high / max）把每条语句交给解释器执行，
 * 在 `perf_workload.php` 那种「函数调用 + 字符串」密集负载上会跑上几分钟到几十分钟，
 * 无法在基准中稳定采样。本文件用同一类计算、但规模缩到 VM 档 2~5 秒可跑完，
 * 用于给出 high / max 的**可复现**倍数（同一份负载下对比原生）。
 *
 * 输出：STDOUT = 校验和，STDERR = COMPUTE / PEAK。
 */

$__perf_t0 = hrtime(true);

// 算术循环
$sum = 0;
for ($i = 1; $i <= 20000; $i++) {
    $sum += ($i * 3) % 7;
}

// 用户函数调用
function perf_light_mix(int $a, int $b): int
{
    return (($a << 3) ^ ($b >> 2)) + ($a % ($b | 1));
}
$acc = 0;
for ($i = 0; $i < 6000; $i++) {
    $acc = perf_light_mix($acc + $i, $i | 1);
}

// 类方法调用
class PerfLightCounter
{
    private int $n = 0;

    public function add(int $v): self
    {
        $this->n = ($this->n + $v) & 0xFFFFFF;
        return $this;
    }

    public function value(): int
    {
        return $this->n;
    }
}
$counter = new PerfLightCounter();
for ($i = 0; $i < 6000; $i++) {
    $counter->add($i % 97);
}

// 数组与递归
$nums = [];
for ($i = 0; $i < 3000; $i++) {
    $nums[] = ($i * 2654435761) % 100003;
}
sort($nums);
function perf_light_fib(int $n): int
{
    return $n < 2 ? $n : perf_light_fib($n - 1) + perf_light_fib($n - 2);
}
$fib = perf_light_fib(18);

$__perf_t1 = hrtime(true);

fwrite(STDERR, sprintf(
    "COMPUTE %.3f\nPEAK %d\n",
    ($__perf_t1 - $__perf_t0) / 1e6,
    memory_get_peak_usage(true)
));

echo sprintf('%d|%d|%d|%d|%d', $sum, $acc, $counter->value(), count($nums), $fib), "\n";
