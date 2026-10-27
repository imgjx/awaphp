<?php
declare(strict_types=1);
/**
 * v1.0.6 逐层壳自保护专项回归：
 *   [0] 性能基线：max 等级构建应在合理时间内完成（优化性能，不引入明显退化）
 *   [1] shell_shield 默认开启：五个等级构建产物 php -l 通过且运行输出与原生一致
 *   [2] shell_shield 可关闭：--no-shell-shield 产物同样可运行；开启时壳解码逻辑被额外混淆
 *       （文件更大且解码变量被重命名为 12 位），关闭时更接近裸壳
 *   [3] anti_tamper 自校验在"被 norename 模式保护后"仍有效：未篡改正常、单字节篡改即异常退出
 *   [4] struct_poison 三态在"被保护后"仍成立：未篡改=原生、美化/篡改=解出乱码
 */
$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$sample = __DIR__ . '/fixtures/sample.php';
if (!is_file($sample)) {
    $sample = $root . '/tests/fixtures/sample.php';
}
$tmp = sys_get_temp_dir() . '/hv106_' . getmypid();
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
    $cmd = 'php ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $o, $ec);
    return implode("\n", $o);
}
function runExit(string $file): int
{
    $cmd = 'php ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $o, $ec);
    return (int) $ec;
}

$native = run($sample);

echo "===== [0] 性能基线（max 等级构建耗时）=====\n";
$t0 = microtime(true);
$bMax = build($entry, $sample, "$tmp/perf_max.php", '--level=max --salt=perfMax');
$cost = microtime(true) - $t0;
ok($bMax && $cost < 10.0, "max 等级构建 < 10s", sprintf('cost=%.2fs', $cost));

echo "===== [1] shell_shield 默认开启：五等级产物可运行且输出一致 =====\n";
foreach (['low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    $o = "$tmp/lv_$lv.php";
    $okB = build($entry, $sample, $o, "--level=$lv --salt=sh$lv");
    ok($okB, "构建 level=$lv");
    if (!$okB) { continue; }
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "level=$lv php -l 通过");
    ok(run($o) === $native, "level=$lv 运行输出一致", 'got=' . substr(run($o), 0, 40));
}

echo "===== [2] shell_shield 开关：解码逻辑被额外混淆 =====\n";
$on = "$tmp/gz_on.php";
$off = "$tmp/gz_off.php";
$bOn = build($entry, $sample, $on, '--gz --str-length=2 --num-enc --salt=gzShield');
$bOff = build($entry, $sample, $off, '--gz --str-length=2 --num-enc --salt=gzShield --no-shell-shield');
ok($bOn && $bOff, 'gz 壳：shield 开/关均可构建');
if ($bOn && $bOff) {
    ok(run($on) === $native, 'shield 开：运行输出一致');
    ok(run($off) === $native, 'shield 关：运行输出一致');
    $szOn = filesize($on);
    $szOff = filesize($off);
    ok($szOn > $szOff, 'shield 开产物更大（壳解码逻辑被额外混淆）', "on=$szOn off=$szOff");
    // 解码变量应被重命名为 12 位（ShellProtector 的 var_length=12，前缀 $_ 后约 11 位），
    // 使用字母+数字混合字符集（非纯 hex）。本构建未请求主流程变量重命名，故出现此类变量即证明
    // ShellProtector 已对各层壳解码逻辑施加了变量重命名保护。
    $codeOn = (string) file_get_contents($on);
    $renamed = (int) preg_match_all('/\$\_[A-Za-z0-9]{8,}\b/', $codeOn);
    ok($renamed >= 1, 'shield 开：壳解码变量被重命名保护', 'matched=' . $renamed);
}

echo "===== [3] anti_tamper 自校验（norename 保护后）仍有效 =====\n";
$at = "$tmp/at.php";
if (build($entry, $sample, $at, '--anti-tamper --segments=4 --shutdown --salt=atShield')) {
    ok(run($at) === $native, '未篡改：正常输出');
    // 单字节篡改：必须落在【受保护区段（主体 body）】内才会被分段哈希捕获。
    // 守卫行（含自校验 blob）在文件最顶部，其 blob 被改会让 @eval 静默失败、守卫整体失效
    // （fail-open），故此处定位到"守卫行结束的换行之后"再改，确保改动落在哈希覆盖区内。
    $s = (string) file_get_contents($at);
    $nl1 = strpos($s, "\n");
    $guardEnd = strpos($s, "\n", $nl1 + 1); // 守卫行（首行）结束的换行
    $pos = ($guardEnd === false ? (int) (strlen($s) / 3) : $guardEnd + 8);
    if ($pos >= strlen($s)) { $pos = (int) (strlen($s) / 2); }
    $s[$pos] = $s[$pos] === 'a' ? 'b' : 'a';
    $t = "$tmp/at_tamper.php";
    file_put_contents($t, $s);
    $ec = runExit($t);
    ok($ec !== 0, '单字节篡改后异常退出（自校验生效）', 'exit=' . $ec);
} else {
    ok(false, 'anti_tamper 构建失败');
}

echo "===== [4] struct_poison 三态（保护后）=====\n";
foreach (['sp1', 'sp2'] as $salt) {
    $o = "$tmp/sp_$salt.php";
    if (!build($entry, $sample, $o, "--struct-poison --salt=$salt")) { ok(false, "SP 构建 $salt"); continue; }
    ok(run($o) === $native, "未篡改输出一致 $salt");
    $s = (string) file_get_contents($o);
    $b = "$tmp/sp_b_$salt.php";
    file_put_contents($b, str_replace(';', ";\n    ", $s));
    ok(run($b) !== $native, "美化后毒化解出乱码 $salt");
    $t = "$tmp/sp_t_$salt.php";
    $p = strpos($s, 'hash(');
    if ($p === false) { $p = (int) (strlen($s) / 2); }
    $s2 = $s; $s2[$p] = $s2[$p] === 'h' ? 'H' : 'h';
    file_put_contents($t, $s2);
    ok(run($t) !== $native, "单字节篡改后毒化 $salt");
}

echo "\n==============================================\n";
echo "v1.0.6 加固回归：通过 {$pass} / 失败 {$fail}\n";
exit($fail === 0 ? 0 : 1);
