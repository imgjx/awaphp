<?php
declare(strict_types=1);
/**
 * v1.0.7 加固回归：VM 运行时内存加密 + 复杂语法兼容。
 *   [0] 功能保真：夹具（多类型值往返 / 对象旁路 / clone / ?-> / ??= / 解构）
 *       在五个等级构建后 php -l 通过、运行输出与原生一致。
 *   [1] 加密接入：VmRuntimeGenerator 产出的运行期确实内联了内存加密 helper
 *       （serialize / unserialize / sha256 随机 XOR 密钥），而非明文操作数栈。
 *   [2] 旁路存储：不可序列化值（含对象的数组）经加密层往返仍取回原对象。
 *   [3] 生产管线：entry.php 全量构建（含模块内联 / 壳保护）后输出一致。
 */
$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$fixture = __DIR__ . '/fixtures/v107_syntax.php';
$tmp = sys_get_temp_dir() . '/hv107_' . getmypid();
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

echo "===== [0] 功能保真（五等级）=====\n";
foreach (['low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    $o = "$tmp/lv_$lv.php";
    $okB = build($entry, $fixture, $o, "--level=$lv --salt=v107$lv");
    ok($okB, "构建 level=$lv");
    if (!$okB) { continue; }
    $lint = (string) shell_exec('php -l ' . escapeshellarg($o) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), "level=$lv php -l 通过");
    ok(trim(run($o)) === $native, "level=$lv 运行输出一致", 'got=' . substr(run($o), 0, 60));
}

echo "===== [1] 加密接入：运行期内联内存加密 helper =====\n";
require $root . '/src/Parser.php';
require $root . '/src/Support/StatementSplitter.php';
require $root . '/src/Vm/Op.php';
require $root . '/src/Vm/Compiler.php';
require $root . '/src/Vm/IncludeResolver.php';
require $root . '/src/Vm/RuntimeGenerator.php';
require $root . '/src/Salt.php';
require $root . '/src/Support/NameStyle.php';
$gen = new VmRuntimeGenerator(new Salt('v107enc'));
$art = '';
try {
    $art = $gen->buildArtifact('$a=[1,2,3];echo $a[1];', ['flatten' => false, 'stateMachine' => false])['php'];
} catch (Throwable $e) {
    $art = '';
}
ok($art !== '', 'buildArtifact 产出运行期');
$nSer = substr_count($art, 'serialize(');
$nUnser = substr_count($art, 'unserialize(');
$nKey = substr_count($art, 'sha256');
ok($nSer >= 1 && $nUnser >= 1, '运行期含 serialize/unserialize（栈值加解密）', "ser=$nSer unser=$nUnser");
ok($nKey >= 1, '运行期含 sha256 随机 XOR 密钥派生', "sha256=$nKey");
ok(substr_count($art, 'memory_get_usage') >= 1, '密钥掺入内存指纹（每进程随机）');
// 并行标记栈：加密/旁路双通路（MCFLAGS 或 mcFlags 命名族）
$hasFlags = (bool) preg_match('/mc_?flags/i', $art);
ok($hasFlags, '运行期含旁路标记栈（可序列化/不可序列化双通路）');

echo "===== [2] 旁路存储：含对象数组往返 =====\n";
$bypassSrc = 'class Bx{public $v=42;} $o=new Bx(); $w=[$o,1,[$o]]; echo $w[0]->v."|".$w[2][0]->v;';
$bp = "$tmp/bypass.php";
file_put_contents($bp, "<?php\n" . $bypassSrc);
$bpOut = "$tmp/bypass_built.php";
if (build($entry, $bp, $bpOut, '--level=max --salt=v107bypass')) {
    ok(trim(run($bpOut)) === trim(run($bp)), '含对象数组经加密层往返一致', 'got=' . trim(run($bpOut)));
} else {
    ok(false, '旁路夹具构建失败');
}

echo "===== [3] 生产管线（模块内联 / 壳保护）=====\n";
$prod = "$tmp/prod.php";
if (build($entry, $fixture, $prod, '--level=max --salt=v107prod --gz --str-length=2 --num-enc')) {
    $lint = (string) shell_exec('php -l ' . escapeshellarg($prod) . ' 2>&1');
    ok(str_contains($lint, 'No syntax errors'), '生产管线 php -l 通过');
    ok(trim(run($prod)) === $native, '生产管线输出一致', 'got=' . substr(run($prod), 0, 60));
} else {
    ok(false, '生产管线构建失败');
}

echo "\n==============================================\n";
echo "v1.0.7 加固回归：通过 {$pass} / 失败 {$fail}\n";
exit($fail === 0 ? 0 : 1);
