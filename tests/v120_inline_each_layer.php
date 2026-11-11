<?php
declare(strict_types=1);
/**
 * v1.2.0 逐层内联（inline_each_layer）回归
 *
 * 背景：v1.1.x 的 shell:module_link 只在【最内层源码阶段】执行一次，多层 eval/gz 壳
 * 的每一层解码逻辑仍以原生 base64_decode()/gzinflate()/strlen() 明文落地，攻击者
 * 脱掉最内层后即可直接读到干净的解码器。v1.2.0 增加 --inline-each-layer：每个
 * shell 层（含多层 EVAL 的每个子层）包裹后都单独做一次模块链接，并为每层分配
 * 独立前缀，避免同一模块函数跨层重复定义。
 *
 * 断言：
 *   [1] 多层 EVAL（1/2/3/4/6 层）构建 + php -l + 运行输出逐字节一致；
 *   [2] 产物最外层内联前缀序号随层数严格递增 —— 证明每层都独立链接，而非只有最内层；
 *   [3] 关闭开关时产物不含内联前缀（对照组）；
 *   [4] CLI --inline-each-layer 叠加 legacy --eval 管线同样生效；
 *   [5] --level=high/ultra 叠加开关不破坏可运行性；
 *   [6] 多 salt 运行一致 + 同 salt 构建可复现（剔除头部生成时间注释）。
 */
$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/v120_il_' . getmypid();
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
function build(string $php, string $entry, string $in, string $out, string $opts, string &$log = ''): bool
{
    $r = run(sprintf('%s %s --input=%s --output=%s %s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($in), escapeshellarg($out), $opts));
    $log = $r['out'];
    return $r['exit'] === 0 && is_file($out) && filesize($out) > 0;
}
function lintOk(string $php, string $f): bool
{
    return str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($f))['out'], 'No syntax errors');
}
function cleanRun(string $php, string $f, string $native): bool
{
    $r = run(escapeshellarg($php) . ' ' . escapeshellarg($f));
    return $r['exit'] === 0 && $r['out'] === $native
        && !preg_match('/(Fatal error|Parse error|Warning|Deprecated)/i', $r['out']);
}

// 源码刻意调用可被模块链接命中的内建函数，保证壳层解码逻辑也有可内联种子
$src = <<<'PHP'
<?php
$a = base64_decode('YWJj');
$b = md5('x');
$c = sha1('y');
$d = gzinflate(gzdeflate('z'));
echo strlen($a), ':', strlen($b), ':', strlen($c), ':', $d, "\n";
PHP;
$srcFile = $tmp . '/src.php';
file_put_contents($srcFile, $src);
$native = run(escapeshellarg($php) . ' ' . escapeshellarg($srcFile));
ok($native['exit'] === 0 && $native['out'] !== '', '原生源码可运行', $native['out']);

echo "===== v1.2.0 逐层内联回归 =====\n\n";

echo "-- [1][2] 多层 EVAL：构建 / 语法 / 运行 / 每层独立前缀 --\n";
$seqs = [];
foreach ([1, 2, 3, 4, 6] as $L) {
    $cfg = [
        'layers'           => [['name' => 'shell:eval', 'params' => ['layers' => $L]]],
        'shell_shield'     => false,
        'inline_each_layer' => true,
    ];
    $cfgFile = "{$tmp}/L{$L}.json";
    file_put_contents($cfgFile, json_encode($cfg));
    $out = "{$tmp}/L{$L}.php";
    $log = '';
    $b = build($php, $entry, $srcFile, $out,
        '--config=' . escapeshellarg($cfgFile) . ' --salt=il' . $L, $log);
    ok($b, "构建 layers={$L}", substr($log, -200));
    if (!$b) { continue; }
    ok(lintOk($php, $out), "layers={$L} php -l 通过");
    ok(cleanRun($php, $out, $native['out']), "layers={$L} 运行输出逐字节一致",
        substr(run(escapeshellarg($php) . ' ' . escapeshellarg($out))['out'], 0, 120));

    $code = (string) file_get_contents($out);
    preg_match_all('/__ml([0-9a-f]+)_/', $code, $mm);
    $tags = array_values(array_unique($mm[1] ?? []));
    ok($tags !== [], "layers={$L} 产物含逐层内联前缀", 'tags=' . implode(',', $tags));
    if ($tags !== []) {
        // tag = dechex(层序号) . salt派生4位；层数 < 10 时首字符即层序号
        $seqs[$L] = hexdec(substr($tags[0], 0, 1));
    }
}
$vals = array_values($seqs);
$increasing = count($vals) === 5;
for ($i = 1; $i < count($vals); $i++) {
    if ($vals[$i] <= $vals[$i - 1]) { $increasing = false; }
}
ok($increasing, '内联前缀序号随层数严格递增（每层独立链接）', 'seqs=' . json_encode($seqs));

echo "\n-- [3] 关闭对照 --\n";
$cfgOff = ['layers' => [['name' => 'shell:eval', 'params' => ['layers' => 4]]], 'shell_shield' => false];
$offCfg = "{$tmp}/off.json";
file_put_contents($offCfg, json_encode($cfgOff));
$offOut = "{$tmp}/off.php";
$log = '';
$b = build($php, $entry, $srcFile, $offOut,
    '--config=' . escapeshellarg($offCfg) . ' --salt=iloff', $log);
ok($b, '关闭开关构建通过', substr($log, -160));
if ($b) {
    ok(!str_contains((string) file_get_contents($offOut), '__ml'), '关闭时产物无逐层内联前缀');
    ok(cleanRun($php, $offOut, $native['out']), '关闭时运行一致');
}

echo "\n-- [4] CLI 开关 + legacy EVAL 管线 --\n";
$cliOut = "{$tmp}/cli.php";
$log = '';
$b = build($php, $entry, $srcFile, $cliOut,
    '--inline-each-layer --eval --eval-layers=3 --salt=ilcli', $log);
ok($b, 'CLI 开关构建通过', substr($log, -160));
if ($b) {
    ok(lintOk($php, $cliOut), 'CLI 产物 php -l 通过');
    ok(str_contains((string) file_get_contents($cliOut), '__ml'), 'CLI 产物含逐层内联');
    ok(cleanRun($php, $cliOut, $native['out']), 'CLI 产物运行一致');
}

echo "\n-- [5] 等级预设叠加逐层内联 --\n";
foreach (['high', 'ultra'] as $lv) {
    $out = "{$tmp}/preset_{$lv}.php";
    $log = '';
    $b = build($php, $entry, $srcFile, $out,
        "--level={$lv} --inline-each-layer --salt=ilp{$lv}", $log);
    ok($b, "预设 {$lv} + 逐层内联 构建通过", substr($log, -160));
    if (!$b) { continue; }
    ok(lintOk($php, $out), "预设 {$lv} php -l 通过");
    ok(cleanRun($php, $out, $native['out']), "预设 {$lv} + 逐层内联 运行一致");
}

echo "\n-- [6] 多 salt + 可复现性 --\n";
$l3Cfg = "{$tmp}/L3.json";
foreach (['sa', 'sb', 'sc'] as $s) {
    $out = "{$tmp}/s_{$s}.php";
    $log = '';
    $b = build($php, $entry, $srcFile, $out,
        '--config=' . escapeshellarg($l3Cfg) . ' --salt=' . $s, $log);
    ok($b && lintOk($php, $out) && cleanRun($php, $out, $native['out']),
        "salt={$s} 构建 / 语法 / 运行一致", substr($log, -120));
}
$repA = "{$tmp}/rep_a.php";
$repB = "{$tmp}/rep_b.php";
$log = '';
$b1 = build($php, $entry, $srcFile, $repA, '--config=' . escapeshellarg($l3Cfg) . ' --salt=rep', $log);
$b2 = build($php, $entry, $srcFile, $repB, '--config=' . escapeshellarg($l3Cfg) . ' --salt=rep', $log);
$stripTime = static fn(string $f): string => (string) preg_replace('/生成时间[^\n]*/u', '', (string) file_get_contents($f));
ok($b1 && $b2 && $stripTime($repA) === $stripTime($repB),
    '同 salt 构建可复现（剔除头部生成时间注释）');

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) { echo "  FAIL: {$f}\n"; }
exit($fail === 0 ? 0 : 1);
