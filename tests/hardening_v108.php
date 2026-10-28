<?php
declare(strict_types=1);
/**
 * v1.0.8 加固回归：VM 语法兼容性 + opcode 组合执行 + Confrontation-LLM 反分析。
 *   [0] 功能保真：新增语法（heredoc 插值 / 数组展开 / empty(任意表达式) /
 *       动态属性 / ?-> / ??= / list 解构 / clone）在 VM 各等级构建后 php -l 通过、
 *       运行输出与原生一致。
 *   [1] opcode 组合执行（--vm-combo=3）：单 opcode 拆等价多指令序列，
 *       运行输出仍与原生一致（等价性保真）。
 *   [2] Confrontation-LLM（--confrontation-llm）：产物 php -l 通过、内联伪造注释
 *       （@deprecated/@fixme/CHANGELOG 等）与生僻 CJK 注释，且运行输出仍一致。
 *   [3] 生产管线：entry.php 全量构建（--level=ultra，含 combo + confrontation）后
 *       输出与原生一致。
 */
$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$fixture = __DIR__ . '/fixtures/v108_syntax.php';
$tmp = sys_get_temp_dir() . '/hv108_' . getmypid();
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

$native = trim(run($fixture));
echo "  native: {$native}\n";

echo "===== [0] 功能保真（VM 各等级）=====\n";
foreach (['low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    $o = "$tmp/lv_$lv.php";
    $okB = build($entry, $fixture, $o, "--level=$lv --salt=v108$lv");
    ok($okB, "构建 level=$lv");
    if (!$okB) { continue; }
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "level=$lv php -l 通过");
    $got = trim(run($o));
    ok($got === $native, "level=$lv 运行输出一致", 'got=' . substr($got, 0, 60));
}

echo "===== [1] opcode 组合执行（--vm-combo=3）=====\n";
$o1 = "$tmp/combo.php";
$okC = build($entry, $fixture, $o1, "--vm --salt=combo110 --vm-combo=3");
ok($okC, "构建 --vm-combo=3");
if ($okC) {
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o1) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "combo php -l 通过");
    ok(trim(run($o1)) === $native, "combo 运行输出一致", 'got=' . substr(trim(run($o1)), 0, 60));
}

echo "===== [2] Confrontation-LLM（--confrontation-llm）=====\n";
$o2 = "$tmp/confront.php";
$okL = build($entry, $fixture, $o2, "--vm --salt=conf110 --confrontation-llm --confrontation-intensity=3");
ok($okL, "构建 --confrontation-llm");
if ($okL) {
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o2) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "confront php -l 通过");
    ok(trim(run($o2)) === $native, "confront 运行输出一致", 'got=' . substr(trim(run($o2)), 0, 60));
    $src = (string) file_get_contents($o2);
    $fake = preg_match('/@deprecated|@fixme|@todo|@see|CHANGELOG|已被移除|已被上游逻辑取代/', $src);
    ok($fake === 1, "confront 含伪造误导注释");
    $cjk = preg_match('/^\s*\/\/\s*[\x{3000}-\x{9fff}\x{3400}-\x{4dbf}\x{20000}-\x{2ffff}]/um', $src);
    ok($cjk === 1, "confront 含生僻 CJK 注释行");
}

echo "===== [3] 生产管线（--level=ultra：combo + confrontation 全开）=====\n";
$o3 = "$tmp/ultra.php";
$okU = build($entry, $fixture, $o3, "--level=ultra --salt=ultra110");
ok($okU, "构建 --level=ultra");
if ($okU) {
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o3) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "ultra php -l 通过");
    ok(trim(run($o3)) === $native, "ultra 运行输出一致", 'got=' . substr(trim(run($o3)), 0, 60));
}

echo "===== [4] 换行缠绕壳（--newline-poison，配合结构毒化壳）=====\n";
$npSrc = (string) file_get_contents($fixture);
$npNeed = max((int) ceil((substr_count($npSrc, "\n") + 1) * 0.1), 2000);

// [4a] 独立模式（非分层）：换行在最终文件中可见
$o4 = "$tmp/newline.php";
$okN = build($entry, $fixture, $o4, "--newline-poison --salt=np110");
ok($okN, "构建 --newline-poison（独立）");
if ($okN) {
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o4) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "newline php -l 通过");
    ok(trim(run($o4)) === $native, "newline 运行输出一致", 'got=' . substr(trim(run($o4)), 0, 60));
    $nl = substr_count((string) file_get_contents($o4), "\n");
    ok($nl >= $npNeed, "newline 数量达标（{$nl} >= {$npNeed}）");
}

// [4b] 分层 mid + 换行缠绕：换行落在编码壳之后 → 最终文件可见
$o5 = "$tmp/newline_mid.php";
$okN2 = build($entry, $fixture, $o5, "--level=mid --newline-poison --salt=np110m");
ok($okN2, "构建 --level=mid --newline-poison");
if ($okN2) {
    ok(trim(run($o5)) === $native, "newline+mid 运行输出一致", 'got=' . substr(trim(run($o5)), 0, 60));
    $nl2 = substr_count((string) file_get_contents($o5), "\n");
    ok($nl2 >= $npNeed, "newline+mid 数量达标（{$nl2} >= {$npNeed}）");
}

// [4c] 分层 max + 换行缠绕：换行随内层一同被结构毒化壳加密进密文（仍须正确运行）
$o6 = "$tmp/newline_max.php";
$okN3 = build($entry, $fixture, $o6, "--level=max --newline-poison --salt=np110x");
ok($okN3, "构建 --level=max --newline-poison（配合结构毒化壳）");
if ($okN3) {
    ok(trim(run($o6)) === $native, "newline+max 运行输出一致", 'got=' . substr(trim(run($o6)), 0, 60));
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
