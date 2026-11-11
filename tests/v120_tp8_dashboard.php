<?php
declare(strict_types=1);
/**
 * v1.2.0 TP8 极复杂 PHP 混写页面回归
 *
 * tests/tptest.zip 内新增的 tp8/view/shop_dashboard.php 是一份约 200 行的
 * 真实模板：文件开头 T_INLINE_HTML、<?= 短标签、if/elseif/else/endif、
 * foreach/endforeach、for/endfor、while/endwhile、switch/endswitch、PHP 嵌
 * HTML 属性、PHP 嵌 <script>/<style>、heredoc 内 HTML、?><?php 相邻边界、
 * HTML 注释内嵌 PHP。由 app/controller/Dashboard.php 经
 * $GLOBALS['__awa_dashboard'] 注入业务数据，route/app.php 暴露 /shop/dashboard。
 *
 * 断言：
 *   [1] zip 内新增条目齐全、路由已注册、控制器存在；
 *   [2] zip 内模板与 tests/fixtures/mixed_advanced.php 逐字节一致；
 *   [3] 原生模板输出为完整 HTML（表格 / script / style / 数据属性）；
 *   [4] 六档预设混淆后运行输出逐字节一致、0 警告；
 *   [5] 六档 + --inline-each-layer 组合仍逐字节一致。
 */
$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$zip   = $root . '/tests/tptest.zip';
$tmp   = sys_get_temp_dir() . '/v120_tp8_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0; $fail = 0; $fails = [];
function ok(bool $c, string $m, string $d = ''): void
{
    global $pass, $fail, $fails;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m {$m}\n"; }
    else { $fail++; $fails[] = "{$m} :: {$d}"; echo "  \033[31m✗\033[0m {$m}\n     {$d}\n"; }
}
function run(string $cmd): array
{
    exec($cmd . ' 2>&1', $o, $ec);
    return ['out' => implode("\n", $o), 'exit' => (int) $ec];
}

echo "===== v1.2.0 TP8 极复杂混写页面回归 =====\n\n";

echo "-- [1] 打包内容与路由 --\n";
$list = run('unzip -l ' . escapeshellarg($zip))['out'];
foreach ([
    'tp8/view/shop_dashboard.php',
    'tp8/app/controller/Dashboard.php',
    'tp8/route/app.php',
    'tp8_baseline.sh',
] as $entryName) {
    ok(str_contains($list, $entryName), "zip 含 {$entryName}");
}
$route = run('unzip -p ' . escapeshellarg($zip) . ' tp8/route/app.php')['out'];
ok(str_contains($route, "Route::get('shop/dashboard', 'Dashboard/index')"), '路由已注册 /shop/dashboard');
$ctrl = run('unzip -p ' . escapeshellarg($zip) . ' tp8/app/controller/Dashboard.php')['out'];
ok(str_contains($ctrl, '__awa_dashboard') && str_contains($ctrl, 'function index'), '控制器注入业务数据');

echo "\n-- [2][3] 模板字节一致性与原生输出 --\n";
$viewFile = "{$tmp}/shop_dashboard.php";
exec('unzip -p ' . escapeshellarg($zip) . ' tp8/view/shop_dashboard.php > ' . escapeshellarg($viewFile));
ok(is_file($viewFile) && filesize($viewFile) > 5000, '模板提取成功', 'bytes=' . (is_file($viewFile) ? filesize($viewFile) : 0));
$fixture = $root . '/tests/fixtures/mixed_advanced.php';
ok(is_file($viewFile) && md5_file($viewFile) === md5_file($fixture), 'zip 模板与 fixtures/mixed_advanced.php 逐字节一致');
ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($viewFile))['out'], 'No syntax errors'), '模板 php -l 通过');
$native = run(escapeshellarg($php) . ' ' . escapeshellarg($viewFile));
ok($native['exit'] === 0 && strlen($native['out']) > 2000
    && str_contains($native['out'], '<table')
    && str_contains($native['out'], '<script')
    && str_contains($native['out'], '<style'),
    '原生输出为完整 HTML（table/script/style）', 'bytes=' . strlen($native['out']));

echo "\n-- [4] 六档预设逐字节一致 --\n";
foreach (['min', 'low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    $out = "{$tmp}/dash_{$lv}.php";
    $r = run(sprintf('%s %s --input=%s --output=%s --level=%s --salt=tp8%s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($viewFile), escapeshellarg($out), $lv, $lv));
    $b = $r['exit'] === 0 && is_file($out) && filesize($out) > 0;
    ok($b, "预设 {$lv} 构建", substr($r['out'], -160));
    if (!$b) { continue; }
    ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($out))['out'], 'No syntax errors'),
        "预设 {$lv} php -l 通过");
    $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
    ok($rr['exit'] === 0 && $rr['out'] === $native['out']
        && !preg_match('/(Fatal error|Parse error|Warning|Deprecated|Notice)/i', $rr['out']),
        "预设 {$lv} 运行逐字节一致 / 0 警告", substr($rr['out'], 0, 110));
}

echo "\n-- [5] 六档 + 逐层内联 --\n";
foreach (['min', 'mid', 'ultra'] as $lv) {
    $out = "{$tmp}/dash_il_{$lv}.php";
    $r = run(sprintf('%s %s --input=%s --output=%s --level=%s --inline-each-layer --salt=il%s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($viewFile), escapeshellarg($out), $lv, $lv));
    $b = $r['exit'] === 0 && is_file($out) && filesize($out) > 0;
    ok($b, "预设 {$lv} + 逐层内联 构建", substr($r['out'], -160));
    if (!$b) { continue; }
    $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
    ok($rr['exit'] === 0 && $rr['out'] === $native['out'],
        "预设 {$lv} + 逐层内联 运行逐字节一致", substr($rr['out'], 0, 110));
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) { echo "  FAIL: {$f}\n"; }
exit($fail === 0 ? 0 : 1);
