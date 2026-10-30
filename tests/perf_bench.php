<?php
/**
 * 性能基准（perf bench）—— 生成 docs/PERFORMANCE.md 的数据源
 * ============================================================
 * 用法：
 *   php tests/perf_bench.php                 # 默认 5 次，输出 Markdown 表格
 *   php tests/perf_bench.php --runs=7        # 指定重复次数（取中位数）
 *   php tests/perf_bench.php --levels=low,mid,high
 *   php tests/perf_bench.php --opcache       # 开启 CLI OPcache 再测（默认关）
 *
 * 测量口径：
 *   - 冷启动总耗时：`php <产物>` 的墙钟时间中位数（含进程启动 + 编译 + 解码 + 计算）
 *   - 纯计算耗时  ：工作量脚本内部用 hrtime 自测（不受启动/解码噪声影响）
 *   - 解码/编译开销 = 冷启动总耗时 − 纯计算耗时 − 空脚本启动基线
 *   - 峰值内存    ：memory_get_peak_usage(true)（含解码期分配）
 *   - 构建耗时    ：entry.php 混淆该文件一次的墙钟时间
 *
 * 说明：所有变体与原生必须输出同一校验和，否则该项标记为 FAIL（混淆破坏语义时，
 *       性能数字没有意义）。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$opts = getopt('', ['runs::', 'levels::', 'opcache', 'salt::', 'workload::']);
$runs = max(3, (int) ($opts['runs'] ?? 5));
$levels = array_filter(array_map('trim', explode(',', (string) ($opts['levels'] ?? 'low,mid,high,max'))));
$useOpcache = isset($opts['opcache']);
$salt = (string) ($opts['salt'] ?? 'perf-bench-v1');

$php = PHP_BINARY;
$workload = (string) ($opts['workload'] ?? __DIR__ . '/perf_workload.php');
if (!is_file($workload)) { fwrite(STDERR, "工作量文件不存在: {$workload}\n"); exit(1); }
$tmp = sys_get_temp_dir() . '/awaperf_' . getmypid();
@mkdir($tmp, 0777, true);

$iniArgs = $useOpcache
    ? ['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=off']
    : ['-d', 'opcache.enable_cli=0'];

/** 跑一次子进程，返回 [墙钟 ms, stdout, stderr, exit] */
function runPhp(array $cmd, string $tmpDir, int $timeoutSec = 180): array
{
    $errFile = $tmpDir . '/stderr.txt';
    $t0 = hrtime(true);
    $out = [];
    $code = 0;
    $full = 'timeout ' . $timeoutSec . ' ' . implode(' ', array_map('escapeshellarg', $cmd))
        . ' 2>' . escapeshellarg($errFile);
    exec($full, $out, $code);
    $t1 = hrtime(true);
    $stderr = (string) @file_get_contents($errFile);
    return [($t1 - $t0) / 1e6, implode("\n", $out), $stderr, $code];
}

function median(array $xs): float
{
    sort($xs);
    $n = count($xs);
    if ($n === 0) {
        return 0.0;
    }
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $xs[$mid] : ((float) $xs[$mid - 1] + (float) $xs[$mid]) / 2;
}

/** 从 stderr 里抽 COMPUTE / PEAK */
function parseMetrics(string $stderr): array
{
    $compute = null;
    $peak = null;
    if (preg_match('/COMPUTE\s+([0-9.]+)/', $stderr, $m)) {
        $compute = (float) $m[1];
    }
    if (preg_match('/PEAK\s+(\d+)/', $stderr, $m)) {
        $peak = (int) $m[1];
    }
    return [$compute, $peak];
}

// ---------- 0) 空脚本启动基线 ----------
$empty = $tmp . '/empty.php';
file_put_contents($empty, "<?php\n");
$baseWalls = [];
for ($i = 0; $i < $runs; $i++) {
    [$w] = runPhp(array_merge([$php], $iniArgs, [$empty]), $tmp);
    $baseWalls[] = $w;
}
$startup = median($baseWalls);

// ---------- 1) 原生基准 ----------
$native = ['label' => '原生（未混淆）', 'file' => $workload, 'bytes' => filesize($workload), 'build' => 0.0];

$variants = [$native];
foreach ($levels as $lv) {
    $outFile = $tmp . '/obf_' . $lv . '.php';
    $outLog = $tmp . '/build_' . $lv . '.log';
    $cmd = array_merge(
        [$php], $iniArgs,
        [$root . '/entry.php',
            '--input=' . $workload,
            '--output=' . $outFile,
            '--level=' . $lv,
            '--salt=' . $salt]
    );
    $t0 = hrtime(true);
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' >' . escapeshellarg($outLog) . ' 2>&1', $o, $code);
    $t1 = hrtime(true);
    if ($code !== 0 || !is_file($outFile)) {
        fwrite(STDERR, "[FAIL] 构建 $lv 失败，见 $outLog\n");
        continue;
    }
    $variants[] = [
        'label' => $lv,
        'file' => $outFile,
        'bytes' => filesize($outFile),
        'build' => ($t1 - $t0) / 1e6,
    ];
}

// ---------- 2) 逐变体测量 ----------
$rows = [];
$nativeChecksum = null;
$nativeCompute = null;

foreach ($variants as $v) {
    $walls = [];
    $computes = [];
    $peaks = [];
    $checksum = null;
    $ok = true;

    for ($i = 0; $i < $runs; $i++) {
        [$wall, $stdout, $stderr, $code] = runPhp(array_merge([$php], $iniArgs, [$v['file']]), $tmp);
        [$compute, $peak] = parseMetrics($stderr);
        $walls[] = $wall;
        if ($compute !== null) {
            $computes[] = $compute;
        }
        if ($peak !== null) {
            $peaks[] = $peak;
        }
        $cs = trim($stdout);
        if ($checksum === null) {
            $checksum = $cs;
        } elseif ($cs !== $checksum) {
            $ok = false;
        }
        if ($code !== 0) {
            $ok = false;
        }
    }

    if ($v['label'] === '原生（未混淆）') {
        $nativeChecksum = $checksum;
        $nativeCompute = median($computes);
    } elseif ($checksum !== $nativeChecksum) {
        $ok = false;
    }

    $rows[] = [
        'label'   => $v['label'],
        'bytes'   => $v['bytes'],
        'build'   => $v['build'],
        'wall'    => median($walls),
        'compute' => median($computes),
        'peak'    => median($peaks),
        'ok'      => $ok,
        'checksum' => $checksum,
    ];
}

// ---------- 3) 输出 Markdown ----------
$fmt = static fn (float $x, int $d = 1): string => number_format($x, $d);
$kb = static fn (int $b): string => $b >= 1048576
    ? number_format($b / 1048576, 2) . ' MB'
    : number_format($b / 1024, 1) . ' KB';

echo "## 基准数据（自动生成）\n\n";
echo "- PHP: " . PHP_VERSION . "（" . ($useOpcache ? 'CLI OPcache 开' : 'CLI OPcache 关') . "）\n";
echo "- 重复次数: {$runs}（取中位数）　空脚本启动基线: " . $fmt($startup, 2) . " ms\n";
echo "- salt: `{$salt}`\n\n";
echo "| 变体 | 产物体积 | 构建耗时 | 冷启动总耗时 | 纯计算耗时 | 解码/编译开销 | 峰值内存 | 计算相对原生 | 语义一致 |\n";
echo "|---|---:|---:|---:|---:|---:|---:|---:|:---:|\n";

foreach ($rows as $r) {
    $decode = $r['wall'] - $r['compute'] - $startup;
    $ratio = ($nativeCompute > 0 && $r['compute'] > 0) ? $r['compute'] / $nativeCompute : 1.0;
    printf(
        "| %s | %s | %s | %s ms | %s ms | %s ms | %s | %s× | %s |\n",
        $r['label'],
        $kb($r['bytes']),
        $r['build'] > 0 ? $fmt($r['build'], 0) . ' ms' : '—',
        $fmt($r['wall'], 1),
        $fmt($r['compute'], 1),
        $fmt(max(0.0, $decode), 1),
        $kb((int) $r['peak']),
        $fmt($ratio, 2),
        $r['ok'] ? '✓' : '✗'
    );
}

echo "\n> 原生校验和: `" . (string) $nativeChecksum . "`\n";

// 清理（保留产物方便人工复核时可去掉该行）
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);
