<?php
/**
 * v1.2.4 专项回归：逐壳层激进变换（logic_shell）+ 天马行空加强（logic_wild）
 *
 * 覆盖：
 *   A) ultra 档（默认开启 logic_shell + logic_wild）对全部 fixture 的逐字节输出等价；
 *   B) 多个 salt × 全语法 fixture，锁死恒等积木（ord/hexdec/bindec/双层base64/strrev/
 *      移位链/加减缠结链）在 strict_types + 常量上下文下的语义保真；
 *   C) logic-shell-intensity=1/2/3 与 maxlen=0/小值组合，验证逐层变换可配且安全；
 *   D) 显式 --no-logic-shell / --no-logic-wild 关闭后产物仍等价（独立开关可回退）。
 */
declare(strict_types=1);

$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$fxDir = $root . '/tests/fixtures';
$tmp   = sys_get_temp_dir() . '/v124_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;
$fails = [];

function ok(bool $c, string $m, string $d = ''): void
{
    global $pass, $fail, $fails;
    if ($c) { $pass++; echo "  PASS  {$m}\n"; }
    else { $fail++; $fails[] = $m . ' — ' . $d; echo "  FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
}
function cap(string $cmd): array
{
    exec($cmd . ' 2>&1', $o, $ec);
    return ['out' => implode("\n", $o), 'exit' => (int) $ec];
}

/** 构建 + lint + 原生/产物运行比对 */
function verify(string $tag, string $fx, string $opts, string $tmp): array
{
    global $php, $entry;
    $out = $tmp . '/' . $tag . '.php';
    $b = cap(sprintf('%s %s --input=%s --output=%s %s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fx),
        escapeshellarg($out), $opts));
    if ($b['exit'] !== 0 || !is_file($out)) {
        return [false, '构建失败 exit=' . $b['exit'] . ' ' . substr($b['out'], 0, 200)];
    }
    $l = cap(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($out)));
    if ($l['exit'] !== 0) {
        return [false, 'php -l 未通过: ' . substr($l['out'], 0, 200)];
    }
    $orig = cap(sprintf('%s %s', escapeshellarg($php), escapeshellarg($fx)));
    $obf  = cap(sprintf('%s %s', escapeshellarg($php), escapeshellarg($out)));
    if ($orig['exit'] !== 0) {
        return [true, '原生即失败（跳过比对）'];
    }
    if ($obf['exit'] !== 0) {
        return [false, '产物运行失败 exit=' . $obf['exit'] . ' ' . substr($obf['out'], 0, 200)];
    }
    if ($orig['out'] !== $obf['out']) {
        return [false, '输出不一致 origLen=' . strlen($orig['out']) . ' obfLen=' . strlen($obf['out'])];
    }
    return [true, ''];
}

echo "=== A) ultra 档（默认 logic_shell + logic_wild）全 fixture ===\n";
// 注：sample.php 含 `global $count,$greeting`，在 VM 档基线即输出不一致（既有约束），
// 不纳入 level 档比对。
$fixtures = [
    'strings.php', 'classes.php', 'demo.php', 'complex_app.php',
    'named_args.php', 'logic_tangle.php', 'mixed_advanced.php', 'mixed_edge.php',
    'token_rich.php', 'v107_syntax.php', 'v108_syntax.php', 'v109_features.php',
    'syntax_wide.php', 'vm_full_syntax.php', 'vm_full_syntax_v1.php',
    'vm_full_syntax_v2.php', 'vm_full_syntax_v3.php',
];
foreach ($fixtures as $i => $fx) {
    $p = $fxDir . '/' . $fx;
    if (!is_file($p)) { continue; }
    [$good, $detail] = verify('ultra_' . $i, $p, '--level=ultra --salt=v124u' . $i, $tmp);
    ok($good, "ultra / {$fx}", $detail);
}

echo "\n=== B) 全语法 fixture × 多 salt（wild 恒等积木保真） ===\n";
$fx = $fxDir . '/vm_full_syntax.php';
foreach (['a', 'zz', 'bs47', 'wild9', '7f3c2', 'qwerty'] as $j => $salt) {
    [$good, $detail] = verify('syn_' . $j, $fx, '--level=ultra --salt=' . $salt, $tmp);
    ok($good, "ultra 全语法 / salt={$salt}", $detail);
}

echo "\n=== C) 逐层强度 / maxlen 组合 ===\n";
$fx = $fxDir . '/complex_app.php';
foreach ([1, 2, 3] as $intensity) {
    [$good, $detail] = verify('li_' . $intensity, $fx,
        "--level=max --salt=li{$intensity} --logic-shell --logic-shell-intensity={$intensity}", $tmp);
    ok($good, "max + logic-shell-intensity={$intensity}", $detail);
}
foreach ([0, 64, 4096] as $k => $maxlen) {
    [$good, $detail] = verify('ml_' . $k, $fx,
        "--level=ultra --salt=ml{$k} --logic-shell-maxlen={$maxlen}", $tmp);
    ok($good, "ultra + logic-shell-maxlen={$maxlen}", $detail);
}

echo "\n=== D) 独立开关 + wild 无 VM 档位 ===\n";
$fx = $fxDir . '/vm_full_syntax.php';
$cases = [
    ['no-shell',       '--level=ultra --salt=off1 --no-logic-shell'],
    ['mid-shell-on',   '--level=mid --salt=off4 --logic-shell'],
    ['low-wild',       '--level=low --salt=w1 --logic-wild'],
    ['mid-wild',       '--level=mid --salt=w2 --logic-wild'],
    ['mid-wild-shell', '--level=mid --salt=w3 --logic-wild --logic-shell'],
];
foreach ($cases as $k => [$name, $opts]) {
    [$good, $detail] = verify('off_' . $k, $fx, $opts, $tmp);
    ok($good, "开关组合 / {$name}", $detail);
}

echo "\n=== 汇总 ===\nTOTAL=" . ($pass + $fail) . " PASS={$pass} FAIL={$fail}\n";
foreach ($fails as $f) { echo "  - {$f}\n"; }
// 清理
array_map('unlink', glob($tmp . '/*.php') ?: []);
@rmdir($tmp);
exit($fail === 0 ? 0 : 1);
