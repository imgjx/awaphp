<?php
/**
 * 反调试探针语法自检回归 —— 验证「手写探针块写错时不产出坏产物」。
 *
 * 背景（v1.1.6 修的真实事故）：
 *   `ini` 探针组里 `in_array(...,true)` 少写一个右括号 →
 *   构建期零告警、六档产物全部生成成功 → 只有跑产物才 Parse error。
 *   反调试探针是手写 PHP 源码拼接，这类括号错位无法靠静态检查拦住，
 *   只能在构建期做一次 `php -l` 自检。
 *
 * 本测试覆盖：
 *   A. 现有全部探针组语法自检通过（防止后续新增组写错）
 *   B. 人为注入坏探针 → 该组被剔除、告警明确、产物仍可运行
 *   C. 坏探针不影响其它组（覆盖度损失被限制在出错的那一组）
 *   D. 探针组白名单与 LayerDescriptor 登记一致（分层档位不静默丢组）
 */

declare(strict_types=1);

$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/adchk_test_' . getmypid();
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

$sample = $root . '/tests/fixtures/sample.php';

echo "===== 反调试探针语法自检回归 =====\n\n";

// ---------- A. 现有全部探针组语法有效 ----------
echo "-- A. 全部探针组语法自检 --\n";
$groups = ['ext', 'func', 'ini', 'env', 'sapi', 'proc', 'trace'];
foreach ($groups as $g) {
    $out = $tmp . '/probe_' . $g . '.php';
    $built = run(sprintf(
        '%s %s --input=%s --output=%s --anti-debug --ad-probes=%s --format=text',
        escapeshellarg($php), escapeshellarg($entry),
        escapeshellarg($sample), escapeshellarg($out), $g
    ));
    $builtOk = $built['exit'] === 0 && is_file($out) && filesize($out) > 0;
    ok($builtOk, "探针组 {$g} 构建成功", 'exit=' . $built['exit'] . ' ' . substr($built['out'], 0, 200));
    if (!$builtOk) {
        continue;
    }
    ok(strpos($built['out'], '语法自检失败') === false, "探针组 {$g} 语法自检通过",
        '被误判为语法错误：' . substr($built['out'], 0, 200));
    $r = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
    ok($r['exit'] === 0, "探针组 {$g} 产物可运行", 'exit=' . $r['exit'] . ' ' . substr($r['out'], 0, 160));
}

// 'shell' 组依赖「外壳绑定标记」，该标记只在 VM 自检开启时注入。
// 未开 VM 时显式指定 shell → 必须被剔除（否则产物一运行就 exit(1) 误杀）。
echo "\n-- A2. shell 组与绑定标记的耦合 --\n";
$outShell = $tmp . '/probe_shell_novm.php';
$builtShell = run(sprintf(
    '%s %s --input=%s --output=%s --anti-debug --ad-probes=shell --format=text',
    escapeshellarg($php), escapeshellarg($entry),
    escapeshellarg($sample), escapeshellarg($outShell)
));
ok(strpos($builtShell['out'], "'shell' 探针已剔除") !== false,
    '未开 VM 时 shell 探针被剔除并告警', $builtShell['out']);
if (is_file($outShell)) {
    $r = run(escapeshellarg($php) . ' ' . escapeshellarg($outShell));
    $native = run(escapeshellarg($php) . ' ' . escapeshellarg($sample));
    ok($r['exit'] === 0, '未开 VM + shell 探针：产物不误杀退出',
        'exit=' . $r['exit'] . ' ' . substr($r['out'], 0, 200));
    ok($r['out'] === $native['out'], '未开 VM + shell 探针：输出与原生一致', '');
}

// 开了 VM（标记存在）→ shell 组必须生效
$outShellVm = $tmp . '/probe_shell_vm.php';
$builtShellVm = run(sprintf(
    '%s %s --input=%s --output=%s --vm --anti-debug --ad-probes=ext,shell --format=text',
    escapeshellarg($php), escapeshellarg($entry),
    escapeshellarg($sample), escapeshellarg($outShellVm)
));
ok(strpos($builtShellVm['out'], "'shell' 探针已剔除") === false,
    '开 VM 时 shell 探针不被剔除', $builtShellVm['out']);
if (is_file($outShellVm)) {
    $r = run(escapeshellarg($php) . ' ' . escapeshellarg($outShellVm));
    ok($r['exit'] === 0, '开 VM + shell 探针：产物正常运行',
        'exit=' . $r['exit'] . ' ' . substr($r['out'], 0, 200));
}

// ---------- B/C. 人为注入坏探针 → 该组被剔除，产物仍可运行 ----------
echo "\n-- B. 坏探针被剔除（不产出坏产物）--\n";
$adFile = $root . '/src/ControlFlow/AntiDebug.php';
$adSrc  = (string) file_get_contents($adFile);
$orig   = $adSrc;

// 在 ini 组里注入一个少右括号的表达式（模拟真实事故）。
// 注意：源文件里守卫代码写在双引号字符串中，$r 前有转义反斜杠。
$brokenNeedle = "array('1','on','true','yes'),true))\\\$r=true;";
$brokenInsert = "array('1','on','true','yes'),true)\\\$r=true;";
$pos = strpos($adSrc, $brokenNeedle);
if ($pos === false) {
    ok(false, '注入坏探针（构造失败）', '未找到待注入的探针代码，可能已被改写');
} else {
    $broken = substr_replace($adSrc, $brokenInsert, $pos, strlen($brokenNeedle));
    file_put_contents($adFile, $broken);
    try {
        $out = $tmp . '/broken.php';
        $built = run(sprintf(
            '%s %s --input=%s --output=%s --anti-debug --ad-probes=ext,func,ini --format=text',
            escapeshellarg($php), escapeshellarg($entry),
            escapeshellarg($sample), escapeshellarg($out)
        ));
        ok($built['exit'] === 0, '坏探针下构建仍成功', 'exit=' . $built['exit']);
        ok(strpos($built['out'], "探针组 'ini' 语法自检失败") !== false,
            '明确告警指出出错的是 ini 组', $built['out']);
        ok(is_file($out) && filesize($out) > 0, '坏探针下仍产出文件', '');

        if (is_file($out)) {
            $r = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
            $native = run(escapeshellarg($php) . ' ' . escapeshellarg($sample));
            ok($r['exit'] === 0, '坏探针下产物不 Parse error', 'exit=' . $r['exit'] . ' ' . substr($r['out'], 0, 200));
            ok($r['out'] === $native['out'], '坏探针下产物输出与原生一致',
                'native=' . substr($native['out'], 0, 60) . ' | obf=' . substr($r['out'], 0, 60));
        }
    } finally {
        file_put_contents($adFile, $orig);
    }
    ok(str_contains((string) file_get_contents($adFile), $brokenNeedle),
        '坏探针注入文件已还原', '');
}

// ---------- D. 探针组白名单与 LayerDescriptor 登记一致 ----------
echo "\n-- D. 探针组登记一致性 --\n";
$adSrcNow = (string) file_get_contents($adFile);
preg_match("/\\\$valid\s*=\s*\[(.*?)\];/s", $adSrcNow, $m1);

// 定位 shell:anti_debug 层描述符里的 options（而非其它层的 options）
$descSrc = (string) file_get_contents($root . '/src/Layer/LayerDescriptor.php');
$descPos = strpos($descSrc, "'shell:anti_debug'");
$optPos  = $descPos === false ? false : strpos($descSrc, "'options' => [", $descPos);
// 取到该 options 数组的闭合 ']'（数组项形如 'k' => 'v'，内部不含 ']'）
$optEnd  = $optPos === false ? false : strpos($descSrc, ']', $optPos);
$optsRaw = ($optPos !== false && $optEnd !== false) ? substr($descSrc, $optPos, $optEnd - $optPos) : '';

$valid = array_map(fn($s) => trim($s, " \t'"), explode(',', $m1[1] ?? ''));
// options 形如 ['ext' => '扩展加载', 'func' => '函数存在', ...]，取每项的 key
preg_match_all("/'([a-z_]+)'\s*=>/", $optsRaw, $m3);
$opts = $m3[1] ?? [];
foreach ($valid as $g) {
    if ($g === '') {
        continue;
    }
    ok(in_array($g, $opts, true), "探针组 '{$g}' 已在 LayerDescriptor options 登记",
        '未登记 → 分层档位会静默丢弃该组（options=' . substr($optsRaw, 0, 200) . '）');
}

echo "\n===== 结果：{$pass} 通过 / {$fail} 失败 =====\n";
if ($fail > 0) {
    echo "失败项：\n - " . implode("\n - ", $fails) . "\n";
    exit(1);
}
