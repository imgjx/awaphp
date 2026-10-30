<?php
/**
 * 性能基准工作量（perf workload）
 * ============================================================
 * 用途：为 docs/PERFORMANCE.md 提供**可复现**的运行时开销数据。
 * 该文件既是「原生基准」，也是被混淆的输入——混淆产物与原生必须输出一致。
 *
 * 设计原则：
 *   - 纯计算、无 IO / 无网络 / 无随机（相同输入永远相同输出）；
 *   - 覆盖 PHP 常见热路径：算术循环、函数调用、类方法调用、数组操作、
 *     字符串拼接与哈希、递归、闭包；
 *   - 计算量刻意做大（原生约数百毫秒），使「解释器/解码开销」不被进程启动噪声淹没。
 *
 * 输出：STDOUT = 校验和（用于断言等价），STDERR = COMPUTE/PEAK 指标行（供基准脚本采集）。
 */

$__perf_t0 = hrtime(true);

// ---------- 1) 算术循环 ----------
$sum = 0;
for ($i = 1; $i <= 200000; $i++) {
    $sum += ($i * 3) % 7;
    if (($i & 1023) === 0) {
        $sum -= intdiv($i, 1024);
    }
}

// ---------- 2) 函数调用 ----------
function perf_mix(int $a, int $b): int
{
    return (($a << 3) ^ ($b >> 2)) + ($a % ($b | 1));
}
$acc = 0;
for ($i = 0; $i < 20000; $i++) {
    $acc = perf_mix($acc + $i, $i | 1);
}

// ---------- 3) 类 + 方法调用 ----------
class PerfCounter
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
$counter = new PerfCounter();
for ($i = 0; $i < 20000; $i++) {
    $counter->add($i % 97);
}

// ---------- 4) 数组：构造 / 遍历 / 排序 / 关联 ----------
$nums = [];
for ($i = 0; $i < 12000; $i++) {
    $nums[] = ($i * 2654435761) % 100003;
}
sort($nums);
$assoc = [];
foreach ($nums as $k => $v) {
    $assoc[$v % 512] = ($assoc[$v % 512] ?? 0) + 1;
}
ksort($assoc);
$bucketSum = 0;
foreach ($assoc as $k => $v) {
    $bucketSum += $k * $v;
}

// ---------- 5) 字符串：拼接 / 哈希 / 处理 ----------
$buf = '';
for ($i = 0; $i < 6000; $i++) {
    $buf .= chr(65 + ($i % 26));
    if (strlen($buf) > 4096) {
        $buf = substr($buf, 2048);
    }
}
$hashes = 0;
for ($i = 0; $i < 400; $i++) {
    $hashes += (int) hexdec(substr(md5($buf . $i), 0, 6));
}
$upper = strtoupper(substr($buf, 0, 512));

// ---------- 6) 递归 ----------
function perf_fib(int $n): int
{
    return $n < 2 ? $n : perf_fib($n - 1) + perf_fib($n - 2);
}
$fib = perf_fib(20);

// ---------- 7) 闭包 ----------
$mul = static fn (int $x): int => $x * 3;
$closureSum = 0;
for ($i = 0; $i < 15000; $i++) {
    $closureSum += $mul($i % 31);
}

// ---------- 8) 生成器 ----------
function perf_gen(int $n): Generator
{
    for ($i = 0; $i < $n; $i++) {
        yield $i * 2;
    }
}
$genSum = 0;
foreach (perf_gen(20000) as $v) {
    $genSum += $v;
}

$__perf_t1 = hrtime(true);

$checksum = sprintf(
    '%d|%d|%d|%d|%d|%d|%d|%s',
    $sum,
    $acc,
    $counter->value(),
    $bucketSum,
    $hashes,
    $fib + $closureSum + $genSum,
    count($nums),
    substr($upper, 0, 16)
);

fwrite(STDERR, sprintf(
    "COMPUTE %.3f\nPEAK %d\n",
    ($__perf_t1 - $__perf_t0) / 1e6,
    memory_get_peak_usage(true)
));

echo $checksum, "\n";
