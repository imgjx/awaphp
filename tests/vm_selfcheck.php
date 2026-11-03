<?php
/**
 * VM 高级环境自检（脱壳独立执行检测）回归
 *
 * 验证要点：
 *   1. 结构：selfcheck=1 时注入「外壳绑定标记」校验，=0 时不注入；
 *      且内层只持 sha256 摘要，**不含标记原文**（抠出内层也无法伪造常量值）。
 *   2. 双点注入：初始化一处 + dispatch 循环内周期复检一处 —— 单点 patch 不足以恢复执行。
 *   3. 正常路径：标记就位时输出与原生逐字节一致（零语义影响）。
 *   4. 脱壳路径：把 VM 内层（runtime 闭包 + 字节码）抠出来单独跑（无外层 define）→
 *      指令流被污染，输出为空/错乱，且【没有】Fatal / Parse error（静默，无可下断点）。
 *   5. 归因：同样的裸产物在 selfcheck=0 时输出与原生一致 —— 证明错乱确由自检引起。
 */
declare(strict_types=1);
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/ShellBinding.php';
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

function runPhp(string $php): string
{
    $f = tempnam(sys_get_temp_dir(), 'scv') . '.php';
    file_put_contents($f, $php);
    $out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return trim($out);
}

$salt = new Salt('vm-selfcheck-seed');
$gen  = new VmRuntimeGenerator($salt);

// 循环足够长（> 8192 条指令），确保 dispatch 循环内的周期复检点必然被执行到
$body = '$s=0; for($i=0;$i<20000;$i++){ $s+=$i; } echo "R=".$s;';
$native = runPhp("<?php\n" . $body);

$phpOn  = $gen->buildArtifact($body, ['probe' => 4, 'selfcheck' => true])['php'];
$phpOff = $gen->buildArtifact($body, ['probe' => 4, 'selfcheck' => false])['php'];

$token  = ShellBinding::markerToken($salt);
$digest = ShellBinding::markerDigest($salt);
$define = ShellBinding::defineSource($salt);

// 把外层 define 补回产物头部（=「完整混淆文件」形态：标记由外壳层先定义）
$withDefine = "<?php\n" . $define . "\n" . substr($phpOn, 5);

echo "-- 1) 结构注入与信息泄露面 --\n";
ok(strpos($phpOn, $digest) !== false, 'selfcheck=1 注入绑定标记校验');
ok(strpos($phpOff, $digest) === false, 'selfcheck=0 不注入绑定标记校验');
ok(strpos($phpOn, $token) === false, '内层不含标记原文（只持 sha256 摘要）');
ok(strpos($phpOn, 'hash_equals(') !== false, '校验使用 hash_equals 定时安全比较');
ok(substr_count($phpOn, $digest) === 2, '双点注入：初始化 + dispatch 循环内周期复检');

echo "-- 2) 正常路径（标记就位）语义一致 --\n";
$rOn = runPhp($withDefine);
ok($rOn === $native, '标记就位时输出与原生一致（' . $rOn . '）');

echo "-- 3) 脱壳路径（抠出内层独立运行） --\n";
$rExtract = runPhp($phpOn);
ok($rExtract !== $native, '无外层标记时输出被污染（got=' . ($rExtract === '' ? '(空)' : $rExtract) . '）');
ok(strpos($rExtract, 'Fatal error') === false && strpos($rExtract, 'Parse error') === false,
    '污染路径不产生 Fatal/Parse error（静默错乱，无可下断点）');
ok(strpos($rExtract, 'shell binding') === false, '污染路径不暴露任何检测提示串');

echo "-- 4) 归因：关闭自检后裸产物正常 --\n";
$rOff = runPhp($phpOff);
ok($rOff === $native, 'selfcheck=0 时裸产物输出与原生一致（' . $rOff . '）');

echo "-- 5) 守卫：标记被删即显式终止（外壳层反调试） --\n";
$guard = "<?php\n" . ShellBinding::guardSource($salt) . "\necho 'LEAK';\n";
$rGuard = runPhp($guard);
ok(strpos($rGuard, 'LEAK') === false, '缺标记时守卫先于业务代码终止（无输出）');
ok(stripos($rGuard, 'fatal') !== false, '守卫走显式终止路径');

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
