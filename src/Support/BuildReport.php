<?php
/**
 * 构建报告（BuildReport）
 *
 * 记录每层耗时与产物指纹，供 `--report` / `--report-json` 输出。
 * 只做统计，绝不参与改写：开关关闭时对象仍存在但一次都不计时，
 * 对产物字节与构建结果零影响。
 *
 * 设计要点：
 *   - 计时用 `hrtime(true)`（单调时钟，不受系统时间调整影响），纳秒精度；
 *   - 逐层记录，最后统一打印/序列化，避免中途 IO 打乱构建流程；
 *   - 整站（fork 并行）模式下子进程各写一张分表，父进程合并，
 *     因为 fork 后的内存改动不会回流到父进程。
 */

declare(strict_types=1);

final class BuildReport
{
    /** @var array<int,array{name:string,ms:float,in:int,out:int}> */
    private array $rows = [];

    private float $startNs = 0.0;

    public function __construct(private bool $enabled = false)
    {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function enable(bool $on = true): void
    {
        $this->enabled = $on;
    }

    /** 开始整体计时（幂等：重复调用只重置起点） */
    public function start(): void
    {
        $this->startNs = $this->enabled ? (float) hrtime(true) : 0.0;
    }

    /**
     * 记录一层；未启用时立即返回，调用方无需判断。
     * 同名层累加到同一行（--repeat 多轮、整站多文件都会走这里）。
     */
    public function add(string $name, float $ms, int $in = 0, int $out = 0): void
    {
        if (!$this->enabled) {
            return;
        }
        foreach ($this->rows as $i => $r) {
            if ($r['name'] === $name) {
                $this->rows[$i]['ms'] += $ms;
                $this->rows[$i]['in'] += $in;
                $this->rows[$i]['out'] += $out;
                return;
            }
        }
        $this->rows[] = ['name' => $name, 'ms' => $ms, 'in' => $in, 'out' => $out];
    }

    /** @return array<int,array{name:string,ms:float,in:int,out:int}> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function totalMs(): float
    {
        $sum = 0.0;
        foreach ($this->rows as $r) {
            $sum += $r['ms'];
        }
        return $sum;
    }

    /**
     * 把另一份报告（通常是 fork 子进程写出的分表）并入本表。
     * 同名层合并为一行：耗时相加，字节取最后一次非零值。
     */
    public function merge(array $rows): void
    {
        if (!$this->enabled) {
            return;
        }
        $index = [];
        foreach ($this->rows as $i => $r) {
            $index[$r['name']] = $i;
        }
        foreach ($rows as $r) {
            $name = (string) ($r['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $ms = (float) ($r['ms'] ?? 0.0);
            if (isset($index[$name])) {
                $i = $index[$name];
                $this->rows[$i]['ms'] += $ms;
                if (!empty($r['in'])) {
                    $this->rows[$i]['in'] += (int) $r['in'];
                }
                if (!empty($r['out'])) {
                    $this->rows[$i]['out'] += (int) $r['out'];
                }
            } else {
                $index[$name] = count($this->rows);
                $this->rows[] = [
                    'name' => $name,
                    'ms'   => $ms,
                    'in'   => (int) ($r['in'] ?? 0),
                    'out'  => (int) ($r['out'] ?? 0),
                ];
            }
        }
    }

    /**
     * 渲染成文本表（供 stderr 输出）。列宽按实际内容对齐，终端不换行。
     *
     * @param array<string,mixed> $meta preset / salt / in / out / sha256 / files 等
     */
    public function render(array $meta = []): string
    {
        $total = $this->totalMs();
        $lines = [];
        $ver = (string) ($meta['tool'] ?? '');
        $preset = (string) ($meta['preset'] ?? '');
        $lines[] = sprintf('%s build report — preset=%s', $ver === '' ? 'awaphp' : $ver, $preset === '' ? 'custom' : $preset);

        if ($this->rows !== []) {
            $nameW = 4;
            foreach ($this->rows as $r) {
                $nameW = max($nameW, strlen($r['name']));
            }
            $nameW = min(30, $nameW);
            $lines[] = sprintf("%-{$nameW}s %9s %7s", 'layer', 'ms', 'share');
            $lines[] = str_repeat('-', $nameW) . ' ' . str_repeat('-', 9) . ' ' . str_repeat('-', 7);
            foreach ($this->rows as $r) {
                $share = $total > 0 ? $r['ms'] / $total * 100 : 0.0;
                $lines[] = sprintf(
                    "%-{$nameW}s %9.2f %6.1f%%",
                    substr($r['name'], 0, $nameW),
                    $r['ms'],
                    $share
                );
            }
            $lines[] = str_repeat('-', $nameW) . ' ' . str_repeat('-', 9) . ' ' . str_repeat('-', 7);
            $lines[] = sprintf("%-{$nameW}s %9.2f %6.1f%%", 'total', $total, $total > 0 ? 100.0 : 0.0);
        } else {
            $lines[] = '(no layer timings collected)';
        }

        $in = (int) ($meta['in'] ?? 0);
        $out = (int) ($meta['out'] ?? 0);
        if ($in > 0 || $out > 0) {
            $lines[] = sprintf(
                'bytes  in=%s  out=%s  ratio=%.2fx%s',
                self::human($in),
                self::human($out),
                $in > 0 ? $out / $in : 0.0,
                isset($meta['files']) ? '  files=' . (int) $meta['files'] : ''
            );
        }
        if (!empty($meta['sha256'])) {
            $lines[] = 'sha256 ' . $meta['sha256'];
        }
        return implode("\n", $lines);
    }

    /** 序列化为可写入 .json 的结构 */
    public function toArray(array $meta = []): array
    {
        return [
            'tool'      => (string) ($meta['tool'] ?? ''),
            'preset'    => (string) ($meta['preset'] ?? ''),
            'salt'      => (string) ($meta['salt'] ?? ''),
            'files'     => isset($meta['files']) ? (int) $meta['files'] : null,
            'in'        => (int) ($meta['in'] ?? 0),
            'out'       => (int) ($meta['out'] ?? 0),
            'sha256'    => (string) ($meta['sha256'] ?? ''),
            'total_ms'  => round($this->totalMs(), 3),
            'layers'    => array_map(static fn (array $r): array => [
                'name' => $r['name'],
                'ms'   => round($r['ms'], 3),
                'in'   => $r['in'],
                'out'  => $r['out'],
            ], $this->rows),
        ];
    }

    private static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%.2f MB', $bytes / 1048576);
    }
}
