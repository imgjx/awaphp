<?php
/**
 * v1.2.12 专项回归：整站混淆的函数名唯一化（多文件不再 `Cannot redeclare`）
 *
 *   A) 三文件整站（low / mid / high）：require 两个都含 `logic` 积木的文件，
 *      与原生逐字节一致 —— 修复前第二个 require 直接 fatal（HTTP 500）
 *   B) 机制：同一段源码、不同源文件路径构建两次，内联模块函数名不同
 *   C) 可复现：同源码同 salt 同路径连续构建两次，产物逐字节相同
 *   D) 单文件不受影响（low 档产物仍可运行且输出正确）
 *   E) 版本号
 *
 * 用法：php tests/v1212_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/v1212_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

$pass = 0;
$fail = 0;
$failMsg = [];

function t_ok(string $name): void
{
    global $pass;
    $pass++;
    echo "  \033[32m✓\033[0m {$name}\n";
}

function t_no(string $name, string $why): void
{
    global $fail, $failMsg;
    $fail++;
    $failMsg[] = "{$name}: {$why}";
    echo "  \033[31m✗\033[0m {$name} — {$why}\n";
}

function sh(string $cmd): string
{
    return (string) shell_exec($cmd . ' 2>&1');
}

echo "===== v1.2.12 回归：整站混淆不再撞名 =====\n\n";

// ---------------------------------------------------------------------------
// 夹层：三个文件，a/b 各自带一个循环（logic 会把恒等式积木塞进循环条件）
// ---------------------------------------------------------------------------
$srcDir = $tmp . '/src';
@mkdir($srcDir, 0777, true);
file_put_contents($srcDir . '/a.php', <<<'PHP'
<?php
function a_sum(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += ($i * 3) % 7;
    }
    return $s;
}
echo 'A=', a_sum(200), "\n";
PHP);
file_put_contents($srcDir . '/b.php', <<<'PHP'
<?php
function b_mul(int $n): int
{
    $p = 1;
    for ($i = 1; $i <= $n; $i++) {
        $p = ($p * $i) % 997;
    }
    return $p;
}
echo 'B=', b_mul(20), "\n";
PHP);
file_put_contents($srcDir . '/main.php', <<<'PHP'
<?php
require __DIR__ . '/a.php';
require __DIR__ . '/b.php';
echo 'M=', a_sum(100) + b_mul(10), "\n";
PHP);

$native = trim(sh('php ' . escapeshellarg($srcDir . '/main.php')));
echo "原生输出: " . str_replace("\n", ' | ', $native) . "\n";

// ---------------------------------------------------------------------------
// A) 三档整站
// ---------------------------------------------------------------------------
echo "\n=== A) 三文件整站逐字节一致 ===\n";
foreach (['low', 'mid', 'high'] as $lv) {
    $obf = $tmp . '/obf_' . $lv;
    $build = sh('php ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($srcDir)
        . ' --output=' . escapeshellarg($obf)
        . ' --level=' . $lv
        . ' --format=text --salt=v1212site');
    if (!is_file($obf . '/main.php')) {
        t_no("{$lv} 整站构建", substr($build, -200));
        continue;
    }
    $got = trim(sh('timeout 120 php -d opcache.enable_cli=0 ' . escapeshellarg($obf . '/main.php')));
    if ($got === $native) {
        t_ok("{$lv} 整站输出与原生一致");
    } else {
        $why = str_contains($got, 'Cannot redeclare') ? '仍撞名: ' . substr($got, 0, 160) : '输出不一致';
        t_no("{$lv} 整站输出", $why . ' → ' . str_replace("\n", ' | ', substr($got, 0, 200)));
    }
}

// ---------------------------------------------------------------------------
// B) 机制：不同源文件 → 不同的内联函数名
// ---------------------------------------------------------------------------
echo "\n=== B) 函数名带文件派生段 ===\n";
$cfg = $tmp . '/plain.php';
file_put_contents($cfg, '<?php return ' . var_export([
    'layers' => [
        ['name' => 'logic', 'params' => ['intensity' => 1]],
        ['name' => 'shell:module_link', 'params' => []],
    ],
    'shell_shield' => false,
    // 关闭文件头 banner：它含生成时间戳，跨秒构建必然不同，会让「可复现」测试假失败
    'no_banner'    => true,
], true) . ';');

$names = [];
$plainBody = $bodies = '';
foreach (['a.php', 'b.php'] as $f) {
    $out = $tmp . '/plain_' . $f;
    sh('php ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($srcDir . '/' . $f)
        . ' --output=' . escapeshellarg($out)
        . ' --format=text --salt=v1212site --config=' . escapeshellarg($cfg));
    $body = is_file($out) ? (string) file_get_contents($out) : '';
    $plainBody .= $body;
    if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $m)) {
        foreach ($m[1] as $fn) {
            $names[$f][] = $fn;
        }
    }
}
$setA = array_values(array_unique($names['a.php'] ?? []));
$setB = array_values(array_unique($names['b.php'] ?? []));
$shared = array_intersect($setA, $setB);
$modA = array_values(array_filter($setA, fn ($x) => str_contains($x, '__mod_')));
if ($setA === [] || $setB === []) {
    t_no('内联函数名提取', '未在产物中找到 function 声明（产物可能是纯壳）');
} elseif ($shared === []) {
    t_ok(sprintf('两文件函数名无交集（%s vs %s）', $modA[0] ?? $setA[0], array_values(array_filter($setB, fn ($x) => str_contains($x, '__mod_')))[0] ?? $setB[0]));
} else {
    t_no('两文件函数名仍有交集', implode(', ', $shared));
}
str_contains($plainBody, '__mod_') && $modA !== []
    ? t_ok('内联模块函数名带文件段: ' . $modA[0])
    : t_no('内联模块函数名', '未找到带前缀的 __mod_ 声明');

// ---------------------------------------------------------------------------
// C) 可复现
// ---------------------------------------------------------------------------
echo "\n=== C) 同 salt 同路径可复现 ===\n";
$r1 = $tmp . '/rep1.php';
$r2 = $tmp . '/rep2.php';
foreach ([$r1, $r2] as $out) {
    sh('php ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($srcDir . '/a.php')
        . ' --output=' . escapeshellarg($out)
        . ' --format=text --salt=v1212site --config=' . escapeshellarg($cfg));
}
(is_file($r1) && is_file($r2) && file_get_contents($r1) === file_get_contents($r2))
    ? t_ok('两次构建逐字节相同')
    : t_no('可复现性', '两次构建产物不同');

// ---------------------------------------------------------------------------
// D) 单文件仍正常
// ---------------------------------------------------------------------------
echo "\n=== D) 单文件 low 档 ===\n";
$one = $tmp . '/one.php';
sh('php ' . escapeshellarg($entry)
    . ' --input=' . escapeshellarg($srcDir . '/a.php')
    . ' --output=' . escapeshellarg($one)
    . ' --level=low --format=text --salt=v1212one');
$oneOut = trim(sh('timeout 60 php -d opcache.enable_cli=0 ' . escapeshellarg($one)));
$expect = trim(sh('php ' . escapeshellarg($srcDir . '/a.php')));
$oneOut === $expect ? t_ok('单文件输出一致') : t_no('单文件输出', substr($oneOut, 0, 120));

// ---------------------------------------------------------------------------
// E) 版本号
// ---------------------------------------------------------------------------
echo "\n=== E) 版本号 ===\n";
$help = sh('php ' . escapeshellarg($entry) . ' --help');
preg_match('/v(\d+\.\d+\.\d+)/', $help, $vm) && version_compare($vm[1], '1.2.12', '>=')
    ? t_ok('--help 版本 >= 1.2.12（实际 ' . ($vm[1] ?? '?') . '）')
    : t_no('版本号', '未在 --help 找到 >= 1.2.12');

// ---------------------------------------------------------------------------
echo "\n===== 结果: {$pass} 通过 / {$fail} 失败 =====\n";
if ($fail > 0) {
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
    exit(1);
}
