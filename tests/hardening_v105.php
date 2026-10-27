<?php
declare(strict_types=1);
/**
 * v1.0.5 反分析专项回归：
 *   [1] 结构指纹毒化壳三态：未篡改 = 原生输出；美化 / 单字节篡改 = 解出乱码（空输出）
 *   [2] 蜜罐函数：正常输出不受影响；被调用时返回伪密钥并拖住 ≥0.5s
 *   [3] GOTO 诱饵死状态：输出与原生一致，且产物中确实存在不可达分支
 */
$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$sample = __DIR__ . '/fixtures/sample.php';
if (!is_file($sample)) {
    $sample = $root . '/tests/fixtures/sample.php';
}
$tmp = sys_get_temp_dir() . '/hv105_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;
function ok(bool $c, string $name, string $detail = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}
function build(string $entry, string $in, string $out, string $args): bool
{
    $cmd = sprintf('php %s --input=%s --output=%s %s --format=text 2>&1',
        escapeshellarg($entry), escapeshellarg($in), escapeshellarg($out), $args);
    exec($cmd, $o, $ec);
    return $ec === 0 && is_file($out);
}
function run(string $file): string
{
    return (string) shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
}

$native = run($sample);
echo "===== [1] 结构指纹毒化壳 =====\n";
foreach (['hvp1', 'hvp2'] as $salt) {
    $o = "$tmp/p_$salt.php";
    $okBuild = build($entry, $sample, $o, '--struct-poison --salt=' . $salt);
    ok($okBuild, "构建 salt={$salt}");
    if (!$okBuild) { continue; }
    $got = run($o);
    ok($got === $native, "未篡改输出一致 salt={$salt}", 'got=' . substr($got, 0, 60));

    // 美化：每个分号后插换行（等价于常规格式化工具）
    $s = (string) file_get_contents($o);
    $b = "$tmp/b_$salt.php";
    file_put_contents($b, str_replace(';', ";\n    ", $s));
    ok(run($b) !== $native, "美化后毒化解出乱码 salt={$salt}");

    // 单字节篡改（受护区内）
    $t = "$tmp/t_$salt.php";
    $pos = strpos($s, 'hash(');
    if ($pos === false) { $pos = (int) (strlen($s) / 2); }
    $s2 = $s;
    $s2[$pos] = $s2[$pos] === 'h' ? 'H' : 'h';
    file_put_contents($t, $s2);
    ok(run($t) !== $native, "单字节篡改后毒化 salt={$salt}");
}

echo "===== [2] 蜜罐函数 =====\n";
$hp = "$tmp/hp.php";
if (build($entry, $sample, $hp, '--honeypot=4 --salt=hvhp')) {
    ok(run($hp) === $native, '蜜罐不改变正常输出');
    $code = (string) file_get_contents($hp);
    preg_match_all("/function_exists\\('([A-Za-z_][A-Za-z0-9_]*)'\\)/", $code, $m);
    $names = $m[1] ?? [];
    ok(count($names) >= 3, '蜜罐函数已注入', 'n=' . count($names));
    if ($names !== []) {
        $fn = $names[0];
        $probe = "$tmp/hp_probe.php";
        // 产物本体也是可执行脚本：先缓冲吞掉它的正常输出，再单独计时调用蜜罐
        file_put_contents($probe, "<?php\nob_start();\ninclude " . var_export($hp, true) . ";\nob_end_clean();\n"
            . "\$t=microtime(true);\$k=$fn('seed');\$d=microtime(true)-\$t;\n"
            . "printf('%d|%.2f',strlen(\$k),\$d);\n");
        $out = run($probe);
        [$klen, $cost] = array_pad(explode('|', trim($out)), 2, '0');
        ok((int) $klen >= 40, '蜜罐返回拟真密钥长度', 'len=' . $klen);
        ok((float) $cost >= 0.5, '蜜罐首调用进入深循环', 'cost=' . $cost . 's');
    }
} else {
    ok(false, '蜜罐构建失败');
}

echo "===== [3] GOTO 诱饵死状态 =====\n";
$g = "$tmp/g.php";
if (build($entry, $sample, $g, '--goto=2 --goto-cpx=4 --goto-trap=3 --salt=hvg')) {
    ok(run($g) === $native, '诱饵死状态不改变输出');
    $code = (string) file_get_contents($g);
    // 诱饵分支特征：<state> = <label> * 3; 随后把同一 state 写回自身
    $n = preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*(?:0x[0-9a-f]+|\d+)\s*\*\s*3\s*;\s*'
        . '\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*(?:0x[0-9a-f]+|\d+)\s*;/', $code, $m);
    ok((int) $n > 0, '产物含诱饵死状态分支', 'n=' . (int) $n);
} else {
    ok(false, 'GOTO 构建失败');
}

echo "\n==============================================\n";
echo "v1.0.5 加固回归：通过 {$pass} / 失败 {$fail}\n";
exit($fail === 0 ? 0 : 1);
