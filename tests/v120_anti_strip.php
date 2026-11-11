<?php
declare(strict_types=1);
/**
 * v1.2.0 反剥离互锁壳（shell:anti_strip）回归
 *
 * AntiStrip 构造 K 层互锁 eval 链：每层解码前 define 一个 salt 派生锚点常量，
 * 最内层 eval 之前校验全部 K 个锚点「已定义且值逐一匹配」。正常层层解码时网格
 * 完整放行；攻击者抠出任意内层另存独立文件运行时，缺失的外层锚点不会被 define，
 * 网格校验失败 → silent 静默拒绝 / exit 直接终止。
 *
 * 断言：
 *   [1] 分层 JSON 各 depth(2/3/4/6) × response(silent/exit) 构建 + php -l + 运行一致；
 *   [2] 顶层 return 透传：include 返回值与原生逐字节一致；
 *   [3] 剥离攻击：解出最外层载荷单独运行 → silent 无业务输出 / exit 非零；
 *   [4] 预置同名假锚点常量仍被拒绝（值比对，而非仅 defined 检查）；
 *   [5] 完整产物自身正常运行、无 Fatal/Warning。
 */
$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/v120_as_' . getmypid();
@mkdir($tmp, 0755, true);

// 直接实例化 AntiStrip 所需的最小加载集（构建仍走 CLI）
foreach ([
    'Support/Random', 'Support/NameGenerator', 'Logger', 'Salt', 'Parser',
    'ShellBinding', 'Support/NameStyle', 'Support/TokenScan', 'ControlFlow/AntiStrip',
] as $dep) {
    require_once $root . '/src/' . $dep . '.php';
}

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

/** 模拟攻击者：从最外层产物里解出一层载荷（base64 → 拆 8 字节密钥 → XOR → gzinflate） */
function peelOne(string $wrapped): ?string
{
    if (!preg_match("/base64_decode\('([A-Za-z0-9+\/=]+)'\)/", $wrapped, $m)) {
        return null;
    }
    $raw = base64_decode($m[1]);
    $key = substr($raw, 0, 8);
    $x   = substr($raw, 8);
    $x   = $x ^ substr(str_repeat($key, (int) (strlen($x) / 8) + 2), 0, strlen($x));
    $inner = @gzinflate($x);
    return $inner === false ? null : $inner;
}

echo "===== v1.2.0 反剥离互锁壳回归 =====\n\n";

echo "-- [1] 分层构建矩阵 --\n";
$fixture = $root . '/tests/fixtures/demo.php';
$native  = run(escapeshellarg($php) . ' ' . escapeshellarg($fixture))['out'];
foreach ([2, 3, 4, 6] as $depth) {
    foreach (['silent', 'exit'] as $resp) {
        $cfg = [
            'layers'       => [['name' => 'shell:anti_strip', 'params' => ['depth' => $depth, 'response' => $resp]]],
            'shell_shield' => true,
        ];
        $cfgFile = "{$tmp}/as_{$depth}_{$resp}.json";
        file_put_contents($cfgFile, json_encode($cfg));
        $out = "{$tmp}/as_{$depth}_{$resp}.php";
        $r = run(sprintf('%s %s --input=%s --output=%s --config=%s --salt=as%d%s --format=text',
            escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fixture), escapeshellarg($out),
            escapeshellarg($cfgFile), $depth, $resp));
        $name = "depth={$depth} response={$resp}";
        ok($r['exit'] === 0 && is_file($out) && filesize($out) > 0, "{$name} 构建", substr($r['out'], -160));
        if (!is_file($out) || filesize($out) === 0) { continue; }
        ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($out))['out'], 'No syntax errors'),
            "{$name} php -l 通过");
        $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
        ok($rr['exit'] === 0 && $rr['out'] === $native
            && !preg_match('/(Fatal error|Parse error|Warning|Deprecated)/i', $rr['out']),
            "{$name} 运行输出一致", substr($rr['out'], 0, 120));
    }
}

echo "\n-- [2] 顶层 return 透传 --\n";
$retSrc = "<?php return ['k' => 42, 's' => 'ok', 'a' => [1, 2, 3]];\n";
$retOrig = "{$tmp}/ret_orig.php";
$retObf  = "{$tmp}/ret_obf.php";
file_put_contents($retOrig, $retSrc);
$wrappedRet = (new AntiStrip(new Salt('asRet'), new Logger()))->wrap($retSrc, ['depth' => 3]);
file_put_contents($retObf, $wrappedRet);
$includer = "{$tmp}/includer.php";
file_put_contents($includer, '<?php var_export(include $argv[1]);' . "\n");
$rOrig = run(escapeshellarg($php) . ' ' . escapeshellarg($includer) . ' ' . escapeshellarg($retOrig));
$rObf  = run(escapeshellarg($php) . ' ' . escapeshellarg($includer) . ' ' . escapeshellarg($retObf));
ok($rOrig['exit'] === 0 && $rObf['exit'] === 0 && $rOrig['out'] === $rObf['out']
    && str_contains($rOrig['out'], '42'),
    'include 返回值与原生逐字节一致', 'orig=' . substr($rOrig['out'], 0, 60) . ' obf=' . substr($rObf['out'], 0, 60));

echo "\n-- [3][4][5] 剥离攻击 / 假锚点 / 完整产物 --\n";
$bizSrc = "<?php echo 'BUSINESS-OK';\n";
foreach (['silent', 'exit'] as $resp) {
    $wrapped = (new AntiStrip(new Salt('asPeel'), new Logger()))->wrap($bizSrc, ['depth' => 3, 'response' => $resp]);
    $full = "{$tmp}/peel_full_{$resp}.php";
    file_put_contents($full, $wrapped);
    $rf = run(escapeshellarg($php) . ' ' . escapeshellarg($full));
    ok($rf['exit'] === 0 && trim($rf['out']) === 'BUSINESS-OK', "response={$resp} 完整产物正常执行", $rf['out']);

    $inner = peelOne($wrapped);
    ok(is_string($inner) && $inner !== '', "response={$resp} 成功解出最外层载荷");
    if (!is_string($inner) || $inner === '') { continue; }

    $peeled = "{$tmp}/peel_only_{$resp}.php";
    file_put_contents($peeled, '<?php ' . $inner);
    $rp = run(escapeshellarg($php) . ' ' . escapeshellarg($peeled));
    if ($resp === 'silent') {
        ok($rp['exit'] === 0 && trim($rp['out']) === '',
            'silent：剥离内层单独运行静默拒绝（无业务输出）', 'out=' . substr($rp['out'], 0, 100));
    } else {
        ok($rp['exit'] !== 0,
            'exit：剥离内层单独运行终止', 'exit=' . $rp['exit'] . ' out=' . substr($rp['out'], 0, 100));
    }

    // 预置同名假锚点：defined() 为真但值不匹配，网格校验仍须拒绝
    if (preg_match("/define\('([A-Za-z_][A-Za-z0-9_]*)'/", $wrapped, $dm)) {
        $fake = "<?php if(!defined('{$dm[1]}'))define('{$dm[1]}','FAKE-VALUE');\n" . $inner;
        $fakeFile = "{$tmp}/peel_fake_{$resp}.php";
        file_put_contents($fakeFile, $fake);
        $rk = run(escapeshellarg($php) . ' ' . escapeshellarg($fakeFile));
        ok(trim($rk['out']) === '' || $rk['exit'] !== 0,
            "response={$resp} 预置假锚点仍被拒绝", 'exit=' . $rk['exit'] . ' out=' . substr($rk['out'], 0, 80));
    } else {
        ok(false, "response={$resp} 提取锚点常量名", '未在产物中找到 define()');
    }
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) { echo "  FAIL: {$f}\n"; }
exit($fail === 0 ? 0 : 1);
