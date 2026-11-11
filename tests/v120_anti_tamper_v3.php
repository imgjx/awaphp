<?php
declare(strict_types=1);
/**
 * v1.2.0 反篡改 v3 回归
 *
 * v2 的盲区：改坏密文 blob 后 gzinflate 静默失败，@eval 里的自校验根本没机会执行，
 * 程序仍能"正常"输出。v3 在守卫行 eval 之外增加【明文 blob 完整性闸】
 * sha1($gb) !== '<gzHash>' 立即反制；并把自校验升级为 salt 派生不等长分段 +
 * 分段链式哈希 X[i]=sha1(seg[i] . prev . i) + 链整体指纹 + P1/P2/P3 多点触发。
 *
 * 断言：
 *   [1] 参数矩阵（segments/chain/multipoint/shutdown）构建 + php -l + 运行一致 + 无未知参数 WARN；
 *   [2] 篡改拦截：尾部追加 / 中段插入 / 改密文 blob / legacy 产物追加；
 *       头部版权注释区含生成时间，守卫按设计只校验 marker 行之后的正文，故不纳入；
 *   [3] v2 兼容：legacy --anti-tamper 正常执行且仍能拦截篡改；
 *   [4] 多 salt 运行一致。
 */
$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/v120_at_' . getmypid();
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
function buildAt(string $php, string $entry, string $in, string $out, string $opts, string &$log = ''): bool
{
    $r = run(sprintf('%s %s --input=%s --output=%s %s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($in), escapeshellarg($out), $opts));
    $log = $r['out'];
    return $r['exit'] === 0 && is_file($out) && filesize($out) > 0;
}

$fixture = $root . '/tests/fixtures/demo.php';
$native  = run(escapeshellarg($php) . ' ' . escapeshellarg($fixture))['out'];

echo "===== v1.2.0 反篡改 v3 回归 =====\n\n";

echo "-- [1] 参数矩阵 --\n";
$combos = [];
foreach ([2, 8] as $seg) {
    foreach ([true, false] as $chain) {
        foreach ([true, false] as $mp) {
            $combos[] = ['segments' => $seg, 'chain' => $chain, 'multipoint' => $mp, 'shutdown' => true];
        }
    }
}
$combos[] = ['segments' => 4, 'chain' => true, 'multipoint' => true, 'shutdown' => false];
foreach ($combos as $i => $p) {
    $cfg = ['layers' => [['name' => 'shell:anti_tamper', 'params' => $p]], 'shell_shield' => false];
    $cfgFile = "{$tmp}/at_{$i}.json";
    file_put_contents($cfgFile, json_encode($cfg));
    $out = "{$tmp}/at_{$i}.php";
    $log = '';
    $b = buildAt($php, $entry, $fixture, $out,
        '--config=' . escapeshellarg($cfgFile) . ' --salt=at' . $i, $log);
    $label = 'seg=' . $p['segments'] . ' chain=' . (int) $p['chain']
        . ' mp=' . (int) $p['multipoint'] . ' sd=' . (int) $p['shutdown'];
    ok($b, "构建 [{$label}]", substr($log, -160));
    if (!$b) { continue; }
    ok(!str_contains($log, '[WARN]'), "[{$label}] 无未知参数告警", substr($log, -160));
    ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($out))['out'], 'No syntax errors'),
        "[{$label}] php -l 通过");
    $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
    ok($rr['exit'] === 0 && $rr['out'] === $native, "[{$label}] 正常运行未误杀", substr($rr['out'], 0, 100));
}

echo "\n-- [2] 篡改拦截（chain/multipoint 全开）--\n";
$cfg = ['layers' => [['name' => 'shell:anti_tamper', 'params' => [
    'segments' => 8, 'chain' => true, 'multipoint' => true, 'shutdown' => true,
]]], 'shell_shield' => false];
$cfgFile = "{$tmp}/at_main.json";
file_put_contents($cfgFile, json_encode($cfg));
$mainOut = "{$tmp}/at_main.php";
$log = '';
$b = buildAt($php, $entry, $fixture, $mainOut,
    '--config=' . escapeshellarg($cfgFile) . ' --salt=atmain', $log);
ok($b, '主样本构建', substr($log, -160));
if ($b) {
    $code = (string) file_get_contents($mainOut);
    $clean = run(escapeshellarg($php) . ' ' . escapeshellarg($mainOut));
    ok($clean['exit'] === 0 && $clean['out'] === $native, '主样本未篡改时正常执行');

    $tampered = [];
    $tampered['尾部追加空格'] = $code . ' ';
    // 头部版权注释区含生成时间，守卫按设计只校验 marker 行之后的正文（$b），
    // 故篡改点取正文中段；头部插入属于设计内豁免，不作为拦截用例。
    $mid = (int) (strlen($code) / 2);
    $tampered['中段插入注释'] = substr($code, 0, $mid) . "\n/*tamper-mid*/\n" . substr($code, $mid);
    $blobBad = preg_replace_callback(
        "/(base64_decode\(')([A-Za-z0-9+\/=]{20,})('\))/",
        static function (array $m): string {
            $s = $m[2];
            return $m[1] . ($s[0] === 'A' ? 'B' : 'A') . substr($s, 1) . $m[3];
        },
        $code, 1
    );
    $tampered['改密文 blob 首字节'] = $blobBad ?? $code;
    ok($blobBad !== null && $blobBad !== $code, '成功定位并改写密文 blob');

    foreach ($tampered as $kind => $bad) {
        $f = "{$tmp}/bad_" . md5($kind) . '.php';
        file_put_contents($f, $bad);
        $rb = run(escapeshellarg($php) . ' ' . escapeshellarg($f));
        ok($rb['exit'] !== 0 || trim($rb['out']) === '',
            "篡改拦截：{$kind}", 'exit=' . $rb['exit'] . ' out=' . substr($rb['out'], 0, 90));
    }
}

echo "\n-- [3] v2 兼容：legacy --anti-tamper --\n";
$legacy = "{$tmp}/legacy.php";
$log = '';
$b = buildAt($php, $entry, $fixture, $legacy, '--anti-tamper --salt=atlegacy', $log);
ok($b, 'legacy 构建', substr($log, -160));
if ($b) {
    $rl = run(escapeshellarg($php) . ' ' . escapeshellarg($legacy));
    ok($rl['exit'] === 0 && $rl['out'] === $native, 'legacy 未篡改时正常执行');
    $badFile = "{$tmp}/legacy_bad.php";
    file_put_contents($badFile, file_get_contents($legacy) . ' ');
    $rbad = run(escapeshellarg($php) . ' ' . escapeshellarg($badFile));
    ok($rbad['exit'] !== 0 || trim($rbad['out']) === '', 'legacy 篡改拦截');
}

echo "\n-- [4] 多 salt --\n";
foreach (['atS1', 'atS2', 'atS3'] as $s) {
    $out = "{$tmp}/salt_{$s}.php";
    $log = '';
    $b = buildAt($php, $entry, $fixture, $out,
        '--config=' . escapeshellarg($cfgFile) . ' --salt=' . $s, $log);
    $rr = $b ? run(escapeshellarg($php) . ' ' . escapeshellarg($out)) : ['out' => '', 'exit' => 1];
    ok($b && $rr['exit'] === 0 && $rr['out'] === $native, "salt={$s} 运行一致");
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) { echo "  FAIL: {$f}\n"; }
exit($fail === 0 ? 0 : 1);
