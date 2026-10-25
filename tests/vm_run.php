<?php
declare(strict_types=1);
/**
 * 自定义 VM 模式测试驱动
 * - 用 VmCompiler 把 PHP 源码编译为自定义字节码
 * - 打包成"自包含产物"（内联 runtime + base64/gzip 字节码），可直接分发运行
 * - 运行产物，与"原生 require"输出做对比，验证正确性
 * - 多次运行取均值，度量时间 / 峰值内存 / 产物体积，得到性能损耗
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';

$srcPath = $argv[1] ?? __DIR__ . '/fixtures/complex_app.php';
$iterations = isset($argv[2]) ? (int) $argv[2] : 7;
$source = @file_get_contents($srcPath);
if ($source === false) {
    fwrite(STDERR, "无法读取源文件: $srcPath\n");
    exit(2);
}

// ---------- 1) 编译 ----------
// VmCompiler::compile() 会自行补 <?php 标签，故传入的源码需先去掉开/闭标签
// （与 VirtualMachine::wrap 调用方式一致）。
$compileSrc = preg_replace('/^\s*\<\?php/i', '', $source, 1);
$compileSrc = preg_replace('/\?\>\s*$/', '', $compileSrc);

$compiler = new VmCompiler();
try {
    $t0 = microtime(true);
    $program = $compiler->compile($compileSrc);
    $compileMs = (microtime(true) - $t0) * 1000;
} catch (VmUnsupported $e) {
    echo "【VM 模式：不支持，已回退】\n";
    echo "原因: " . $e->getMessage() . "\n";
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    fwrite(STDERR, "UNSUPPORTED\n");
    exit(3);
}

$insCount = 0;
foreach (['main', 'funcs', 'classes'] as $seg) {
    if ($seg === 'main') { $insCount += count($program['main']); continue; }
    foreach ($program[$seg] as $k => $v) {
        if ($seg === 'funcs') { $insCount += count($v); }
        else { foreach ($v['methods'] ?? [] as $m => $mc) { $insCount += count($mc); } }
    }
}

// ---------- 2) 打包为自包含产物 ----------
$packed = base64_encode(gzencode(json_encode($program), 9));
$runtime = file_get_contents(__DIR__ . '/../src/Vm/runtime.php');
$runtime = preg_replace('/^\<\?php/', '', $runtime, 1); // 去掉开标签，便于内联
$artifact = "<?php\n"
    . '$__P = json_decode(gzdecode(base64_decode(' . var_export($packed, true) . ')), true);' . "\n"
    . $runtime . "\n"
    . "__VMRUN__(\$__P);\n";

// 源文件去掉 php 开/闭标签，便于包进测速壳
$body = preg_replace('/^\s*\<\?php/i', '', $source, 1);
$body = preg_replace('/\?\>\s*$/', '', $body);

$metricsHook = '$__t0=microtime(true);'
    . 'register_shutdown_function(function()use(&$__t0){fwrite(STDERR,sprintf("METRICS %d %.6f\n",memory_get_peak_usage(true),microtime(true)-$__t0));});';

$nativeFile = tempnam(sys_get_temp_dir(), 'vm_native_') . '.php';
$vmFile    = tempnam(sys_get_temp_dir(), 'vm_artifact_') . '.php';
file_put_contents($nativeFile, "<?php\n" . $metricsHook . "\n" . $body);
// artifact 自身已含 <?php 开头，去掉后并入测速壳，避免重复开标签导致 runtime 变纯文本
$artifactBody = preg_replace('/^\<\?php\n?/', '', $artifact);
file_put_contents($vmFile, "<?php\n" . $metricsHook . "\n" . $artifactBody);

// ---------- 3) 运行 + 采集 ----------
function runPhp(string $file): array {
    $descs = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($file), $descs, $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $mem = 0; $time = 0.0;
    if (preg_match('/METRICS\s+(\d+)\s+([\d.]+)/', $err, $m)) {
        $mem = (int) $m[1]; $time = (float) $m[2];
    }
    return ['out' => $out, 'mem' => $mem, 'time' => $time];
}

$nativeRun = runPhp($nativeFile);
$vmRun    = runPhp($vmFile);

// 多轮取均值
$nTimes = []; $nMems = [];
$vTimes = []; $vMems = [];
for ($i = 0; $i < $iterations; $i++) {
    $r = runPhp($nativeFile); $nTimes[] = $r['time']; $nMems[] = $r['mem'];
    $r = runPhp($vmFile);     $vTimes[] = $r['time']; $vMems[] = $r['mem'];
}
sort($nTimes); sort($vTimes); sort($nMems); sort($vMems);
$median = fn (array $a) => $a[(int) (count($a) / 2)];
$nTime = $median($nTimes); $vTime = $median($vTimes);
$nMem  = max($nMems);     $vMem  = max($vMems);

// ---------- 4) 校验正确性 ----------
$ok = $nativeRun['out'] === $vmRun['out'];
$srcBytes = strlen($source);
$artBytes = strlen($artifact);

// ---------- 5) 输出报告 ----------
echo "================ VM 模式测试报告 ================\n";
echo "源文件        : $srcPath\n";
echo "源码字节      : " . number_format($srcBytes) . " B\n";
echo "编译耗时      : " . sprintf('%.2f', $compileMs) . " ms\n";
echo "字节码段      : main=" . count($program['main'])
    . " funcs=" . count($program['funcs'])
    . " classes=" . count($program['classes']) . "\n";
echo "总指令数      : " . $insCount . "\n";
echo "自包含产物    : " . number_format($artBytes) . " B"
    . " (含 runtime + " . number_format(strlen($packed)) . " B 编码字节码)\n";
echo "膨胀倍数      : " . sprintf('%.1fx', $artBytes / max(1, $srcBytes)) . "\n";
echo "------------------------------------------------\n";
echo "正确性        : " . ($ok ? "PASS ✓（VM 输出与原生完全一致）" : "FAIL ✗") . "\n";
if (!$ok) {
    echo "--- 原生输出 ---\n" . $nativeRun['out'] . "\n";
    echo "--- VM 输出 ---\n" . $vmRun['out'] . "\n";
}
echo "------------------------------------------------\n";
echo "性能度量（" . $iterations . " 轮取中位数/最大峰值）：\n";
echo "                 原生           VM 模式        损耗\n";
echo "  执行时间      : " . sprintf('%-12s', sprintf('%.3f ms', $nTime * 1000))
    . sprintf('%-14s', sprintf('%.3f ms', $vTime * 1000))
    . sprintf('%.1fx', $nTime > 0 ? $vTime / $nTime : 0) . "\n";
echo "  峰值内存      : " . sprintf('%-12s', fmtMem($nMem))
    . sprintf('%-14s', fmtMem($vMem))
    . sprintf('%.1fx', $nMem > 0 ? $vMem / $nMem : 0) . "\n";
echo "================ 结论 ================\n";
echo $ok
    ? "结论：该复杂 PHP 可在自定义 VM 模式下正确运行。"
    : "结论：VM 模式运行结果与原生不一致，需排查。";
echo "\n";

unlink($nativeFile); unlink($vmFile);

function fmtMem(int $b): string {
    if ($b >= 1048576) return sprintf('%.2f MB', $b / 1048576);
    if ($b >= 1024) return sprintf('%.1f KB', $b / 1024);
    return $b . ' B';
}
