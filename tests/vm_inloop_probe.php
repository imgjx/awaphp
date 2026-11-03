<?php
/**
 * VM 内反调试（in-loop probe）回归
 *
 * 验证要点：
 *   1. 结构：probe>0 时默认注入 dispatch 循环内探针；inloop=false 时不注入。
 *   2. 正常环境：探针恒不命中，产物输出与原生逐字节一致（零语义影响）。
 *   3. 命中路径：注入一个假的 xdebug 调试器探针后，产物指令流被污染、输出被破坏，
 *      且不是显式 exit（进程正常结束，只是结果错乱）。
 *   4. 仅关闭 in-loop 时（序章探针仍在），假调试器不影响输出 —— 证明这是 in-loop
 *      独有能力，而非既有序章探针。
 */
declare(strict_types=1);
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$msg}\n";
    } else {
        $fail++;
        echo "  ✗ {$msg}\n";
    }
}

function buildArtifact(string $src, array $opts): string
{
    $g = new VmRuntimeGenerator(new Salt('inloop-seed'));
    return $g->buildArtifact($src, $opts)['php'];
}

function runFile(string $file): string
{
    return (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($file) . ' 2>&1');
}

function runArtifact(string $php, bool $fakeDebug): string
{
    $f = tempnam(sys_get_temp_dir(), 'ipl') . '.php';
    if ($fakeDebug) {
        // 产物以 "<?php" 开头（5 字节）；在其后插入假调试器探针
        $body = substr($php, 5);
        $php = "<?php\nfunction xdebug_is_debugger_active(){return true;}\n" . $body;
    }
    file_put_contents($f, $php);
    $out = runFile($f);
    @unlink($f);
    return $out;
}

function runNative(string $src): string
{
    $f = tempnam(sys_get_temp_dir(), 'nat') . '.php';
    file_put_contents($f, $src);
    $out = runFile($f);
    @unlink($f);
    return $out;
}

// 足够长的循环：确保执行指令数 > 探针最大间隔（8192），必然触发检测点
// 注意：buildArtifact 接收【纯语句】（不含 <?php）；原生执行时才补开标签。
$body = '$s=0; for($i=0;$i<20000;$i++){ $s+=$i; } echo $s;';
$native = runNative("<?php\n" . $body);

$on  = buildArtifact($body, ['probe' => 4]);                     // probe>0 → 默认开
$off = buildArtifact($body, ['probe' => 4, 'inloop' => false]);   // 显式关

echo "-- 1) 结构注入 --\n";
ok(strpos($on, 'xdebug_is_debugger_active') !== false, 'probe>0 默认注入 in-loop 探针');
ok(strpos($off, 'xdebug_is_debugger_active') === false, 'inloop=false 不注入 in-loop 探针');

echo "-- 2) 正常环境语义一致 --\n";
$rOn = runArtifact($on, false);
ok(trim($rOn) === trim($native), '开启 in-loop 后正常输出与原生一致（' . trim($rOn) . '）');

echo "-- 3) 命中路径（假调试器） --\n";
$rHit = runArtifact($on, true);
ok(trim($rHit) !== trim($native), '检测到调试器后输出被污染（got=' . trim($rHit) . '）');
ok(strpos($rHit, 'Fatal error') === false && strpos($rHit, 'Parse error') === false, '污染路径不产生 Fatal/Parse error（静默错乱）');

echo "-- 4) 关闭 in-loop 后假调试器不生效 --\n";
$rOffHit = runArtifact($off, true);
ok(trim($rOffHit) === trim($native), '关闭 in-loop 后假调试器不影响输出（' . trim($rOffHit) . '）');

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
