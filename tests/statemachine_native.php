<?php
/**
 * 原生状态机层（statemachine）回归 —— 验证「从 VM 拆出的通用控制流混淆」：
 *   A. 预设覆盖：mid / low 预设开启 statemachine 层后，产物运行输出与原生逐字节一致，
 *      且产物不含 VM 产物标记（区别于 shell:vm）。
 *   B. 隔离覆盖：用「仅 token 层、无 eval/gz 壳」的分层 JSON 构建，使状态机分发器以明文
 *      呈现，断言其含分发循环头 + 不透明谓词转移 + 诱饵死状态（自锁）。
 *
 * 注意：预设档位带 eval/gz 壳，状态机被裹进密文载荷，明文不可见，故结构断言只在隔离构建做。
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$php    = PHP_BINARY;
$entry  = $root . '/entry.php';
$tmp    = sys_get_temp_dir() . '/sm_test_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;
$fails = [];

function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $fails;
    if ($cond) {
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $fail++;
        $fails[] = $name . ' :: ' . $detail;
        echo "  \033[31m✗\033[0m {$name}\n     {$detail}\n";
    }
}

function run(string $cmd): array
{
    exec($cmd . ' 2>&1', $out, $exit);
    return ['out' => implode("\n", $out), 'exit' => (int) $exit];
}

$fixtures = ['sample.php', 'strings.php', 'classes.php', 'demo.php', 'perf_heavy.php'];

echo "===== 原生状态机层（statemachine）回归 =====\n";
echo 'PHP: ' . PHP_VERSION . "\n\n";

// ---------- A. 预设覆盖（输出一致 + 非 VM） ----------
echo "-- A. 预设 low / mid --\n";
foreach (['low', 'mid'] as $preset) {
    foreach ($fixtures as $fixture) {
        $in  = $root . '/tests/fixtures/' . $fixture;
        $out = $tmp . '/' . $preset . '_' . $fixture;
        $native = run(escapeshellarg($php) . ' ' . escapeshellarg($in));
        $built  = run(sprintf(
            '%s %s --input=%s --output=%s -%s --format=text',
            escapeshellarg($php), escapeshellarg($entry),
            escapeshellarg($in), escapeshellarg($out), $preset
        ));
        $label = "{$preset}/{$fixture}";
        if ($built['exit'] !== 0 || !is_file($out) || filesize($out) === 0) {
            ok(false, "{$label} 构建", 'exit=' . $built['exit'] . ' ' . substr($built['out'], 0, 200));
            continue;
        }
        $code = (string) file_get_contents($out);
        $run  = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
        ok($run['exit'] === 0, "{$label} 运行退出码 0", 'exit=' . $run['exit'] . ' ' . substr($run['out'], 0, 120));
        ok($run['out'] === $native['out'], "{$label} 输出与原生一致",
            'native=' . substr($native['out'], 0, 80) . ' | obf=' . substr($run['out'], 0, 80));
        ok(strpos($code, 'VM_ARTIFACT_MARK') === false, "{$label} 非 VM 产物", '意外包含 VM_ARTIFACT_MARK');
    }
}

// ---------- B. 隔离覆盖（明文结构断言） ----------
echo "-- B. 仅 token 层分层（无壳，明文可见）--\n";
$layered = [
    'layers' => [
        ['name' => 'var',          'params' => ['length' => 10]],
        ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 3, 'goto_cpx' => 8]],
        ['name' => 'statemachine', 'params' => ['complexity' => 8, 'passes' => 2, 'traps' => 2, 'opaque' => true]],
        ['name' => 'ws',           'params' => []],
    ],
    'shell_shield' => false,
];
$layerFile = $tmp . '/layered_sm.json';
file_put_contents($layerFile, json_encode($layered));

foreach ($fixtures as $fixture) {
    $in  = $root . '/tests/fixtures/' . $fixture;
    $out = $tmp . '/iso_' . $fixture;
    $native = run(escapeshellarg($php) . ' ' . escapeshellarg($in));
    $built  = run(sprintf(
        '%s %s --config=%s --input=%s --output=%s --format=text',
        escapeshellarg($php), escapeshellarg($entry),
        escapeshellarg($layerFile), escapeshellarg($in), escapeshellarg($out)
    ));
    $label = "iso/{$fixture}";
    if ($built['exit'] !== 0 || !is_file($out) || filesize($out) === 0) {
        ok(false, "{$label} 构建", 'exit=' . $built['exit'] . ' ' . substr($built['out'], 0, 200));
        continue;
    }
    $code = (string) file_get_contents($out);
    $run  = run(escapeshellarg($php) . ' ' . escapeshellarg($out));

    ok($run['exit'] === 0, "{$label} 运行退出码 0", 'exit=' . $run['exit']);
    ok($run['out'] === $native['out'], "{$label} 输出与原生一致",
        'native=' . substr($native['out'], 0, 80) . ' | obf=' . substr($run['out'], 0, 80));

    // 分发循环头：for(;;) / while(1) / while(true)，关键词大小写随机化故忽略大小写
    $hasLoop = (bool) preg_match('/for\(\s*;?\s*;?\s*\)|while\(\s*(1|true)\s*\)/i', $code);
    ok($hasLoop, "{$label} 含状态机循环头", '未找到 for(;;)/while(1)');

    // 不透明谓词：($x ^ $x) === 0 形态（xor 自比较恒真）
    // 形如 if(($a^$a)===0){...}else{...}；^ 与 === 之间隔着右括号，故不能排除 ')'
    $hasOpaque = (bool) preg_match('/\^[^;]{0,20}===?\s*0\b/', $code);
    ok($hasOpaque, "{$label} 含不透明谓词转移", '未找到 (x^x)===0 形态');

    // 诱饵死状态：形如  $d = <id> * 3; $s = <id>; 的自锁片段（至少存在一次 *3 赋值）
    $hasTrap = (bool) preg_match('/\*\s*3\s*;/', $code);
    ok($hasTrap, "{$label} 含诱饵死状态（自锁 *3）", '未找到诱饵 *3 片段');

    ok(strpos($code, 'VM_ARTIFACT_MARK') === false, "{$label} 非 VM 产物", '意外包含 VM_ARTIFACT_MARK');
}

echo "\n===== 结果：{$pass} 通过 / {$fail} 失败 =====\n";
if ($fail > 0) {
    echo "失败项：\n - " . implode("\n - ", $fails) . "\n";
    exit(1);
}
