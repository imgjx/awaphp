<?php
/**
 * v1.2.8 专项回归：构建期循环折叠（零 eval）+ 激进恒等式压缩/剪枝（前置）+ VM 解释器 Logic 混淆
 *
 * 与 tests/run_tests.php 同风格：每个用例独立构建、lint、执行、逐字节比对输出。
 * 用法：php tests/v128_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/v128_' . bin2hex(random_bytes(4));
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

/** 构建 + lint + 执行 + 与原生输出逐字节比对 */
function case_run(string $entry, string $src, string $out, array $args): array
{
    $cmd = 'php ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($src)
        . ' --output=' . escapeshellarg($out)
        . ' --format=text';
    foreach ($args as $a) {
        $cmd .= ' ' . $a;
    }
    $build = shell_exec($cmd . ' 2>&1');
    if (!is_file($out)) {
        return ['ok' => false, 'why' => '构建失败: ' . substr((string) $build, -200)];
    }
    $lint = shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');
    if (strpos((string) $lint, 'No syntax errors') === false) {
        return ['ok' => false, 'why' => '语法错误: ' . substr((string) $lint, 0, 200)];
    }
    $a = shell_exec('php ' . escapeshellarg($src) . ' 2>&1');
    $b = shell_exec('php ' . escapeshellarg($out) . ' 2>&1');
    $norm = static function (string $s): string {
        return preg_replace('/\b\/tmp\/[A-Za-z0-9_\-\/]+\.php\b/', 'FILE', (string) $s);
    };
    if ($norm((string) $a) !== $norm((string) $b)) {
        return ['ok' => false, 'why' => '输出不一致'];
    }
    return ['ok' => true, 'why' => ''];
}

function put(string $tmp, string $name, string $code): string
{
    $p = $tmp . '/' . $name . '.php';
    file_put_contents($p, "<?php\n" . $code);
    return $p;
}

// ---------------------------------------------------------------------------
// A) 构建期纯计算循环折叠（loop_fold）
// ---------------------------------------------------------------------------
echo "\n=== A) 构建期循环折叠（loop_fold）===\n";

$loopSrc = put($tmp, 'loop', <<<'PHP'
$sum = 0;
for ($i = 1; $i <= 200000; $i++) {
    $sum += ($i * 3) % 7;
    if (($i & 1023) === 0) { $sum -= intdiv($i, 1024); }
}
echo $sum, "\n";
PHP
);
$r = case_run($entry, $loopSrc, $tmp . '/loop_out.php', ['--level=min', '--salt=lf128']);
$r['ok'] ? t_ok('20 万次算术循环折叠后输出一致') : t_no('20万次循环折叠', $r['why']);
$loopOut = (string) @file_get_contents($tmp . '/loop_out.php');
(strpos($loopOut, 'for') === false) ? t_ok('产物里循环已被消掉') : t_no('循环未折叠', '产物仍含 for');

// liveness：循环变量被后续引用，必须回写终值
$liveSrc = put($tmp, 'live', <<<'PHP'
$s = 0; for ($i = 0; $i < 10; $i++) { $s += $i; }
echo $s, ',', $i, "\n";
PHP
);
$r = case_run($entry, $liveSrc, $tmp . '/live_out.php', ['--level=min', '--salt=lf128']);
$r['ok'] ? t_ok('循环变量 liveness 回写正确') : t_no('liveness', $r['why']);

// 负例：依赖外部变量 / 超上限 / 副作用函数 → 原样保留且输出一致
$negSrc = put($tmp, 'neg', <<<'PHP'
$w = 3; $x = 0; for ($p = 0; $p < 3; $p++) { $x += $w; } echo "E:{$x}\n";
$t = 0; for ($k = 0; $k < 3; $k++) { $t += strlen("ab"); } echo "B:{$t}\n";
$u = 0; for ($n = 0; $n < 2000000; $n++) { $u++; } echo "C:{$u}\n";
PHP
);
$r = case_run($entry, $negSrc, $tmp . '/neg_out.php', ['--level=min', '--salt=lf128']);
$r['ok'] ? t_ok('不可折叠循环（外部变量/函数/超上限）保持语义') : t_no('循环折叠负例', $r['why']);
$negOut = (string) @file_get_contents($tmp . '/neg_out.php');
(substr_count($negOut, 'for') >= 2) ? t_ok('不可折叠循环原样保留在产物中') : t_no('负例被误折叠', '产物 for 数不足');

// --no-loop-fold 关闭后循环仍在
$r = case_run($entry, $loopSrc, $tmp . '/loop_off.php', ['--level=min', '--salt=lf128', '--no-loop-fold']);
$r['ok'] ? t_ok('--no-loop-fold 关闭后输出仍一致') : t_no('--no-loop-fold', $r['why']);
$loopOff = (string) @file_get_contents($tmp . '/loop_off.php');
(strpos($loopOff, 'for') !== false) ? t_ok('--no-loop-fold 保留循环') : t_no('--no-loop-fold 未生效');

// ---------------------------------------------------------------------------
// B) 激进恒等式压缩 + 死分支剪枝（aggressive_prune）
// ---------------------------------------------------------------------------
echo "\n=== B) 激进恒等式压缩 + 死分支剪枝 ===\n";

$idSrc = put($tmp, 'ident', <<<'PHP'
$s = strlen(md5('hello')) . ':' . hexdec('ff') . ':' . base64_decode('YWI=');
echo $s, "\n";
PHP
);
$r = case_run($entry, $idSrc, $tmp . '/ident_out.php', ['--level=min', '--aggressive-prune', '--salt=ap128']);
$r['ok'] ? t_ok('恒等式折叠后输出一致') : t_no('恒等式折叠', $r['why']);

$branchSrc = put($tmp, 'branch', <<<'PHP'
$v = 'abc';
if (($v === $v) || ($v !== $v)) { echo "T\n"; } else { echo "F\n"; }
if ($v !== $v) { echo "DEAD\n"; } else { echo "LIVE\n"; }
PHP
);
$r = case_run($entry, $branchSrc, $tmp . '/branch_out.php', ['--level=min', '--aggressive-prune', '--salt=ap128']);
$r['ok'] ? t_ok('恒真/恒假死分支剪枝后输出一致') : t_no('死分支剪枝', $r['why']);

// 死支含函数声明时不剪（避免破坏编译期声明 → 产物可运行且一致）
$declSrc = put($tmp, 'decl', <<<'PHP'
if (1 === 2) { function dead_fn() { return 'dead'; } } else { echo "LIVE\n"; }
echo function_exists('dead_fn') ? 'HAS' : 'NO', "\n";
PHP
);
$r = case_run($entry, $declSrc, $tmp . '/decl_out.php', ['--level=min', '--aggressive-prune', '--salt=ap128']);
$r['ok'] ? t_ok('死支含函数声明时保护性不剪') : t_no('声明保护', $r['why']);

// 默认关闭：不加开关时 aggressive_prune 不生效（恒等式不被折叠）
$r = case_run($entry, $idSrc, $tmp . '/ident_off.php', ['--level=min', '--salt=ap128']);
$r['ok'] ? t_ok('默认不启用 aggressive_prune 时输出一致') : t_no('默认关闭', $r['why']);

// aggressive 预设端到端（含 VM + 剪枝，逐字节一致）
$kdfSrc = put($tmp, 'aggr', <<<'PHP'
$total = 0;
for ($i = 1; $i <= 300; $i++) { $total += $i * $i; }
echo $total, '|', strlen(md5('x')), "\n";
PHP
);
$r = case_run($entry, $kdfSrc, $tmp . '/aggr_out.php', ['--level=aggressive', '--salt=aggr128']);
$r['ok'] ? t_ok('--level=aggressive 预设输出一致（VM+剪枝）') : t_no('aggressive 预设', $r['why']);

// ---------------------------------------------------------------------------
// C) 构建期零 eval 注入面（在线混淆服务器安全：上传恶意 token 不得被执行）
// ---------------------------------------------------------------------------
echo "\n=== C) 构建期零 eval 注入面 ===\n";

// 三个构建期求值器源码里不得出现实际 eval( 调用（注释里的说明文字除外）
foreach (['src/LogicTransform/ConstFolder.php', 'src/LogicTransform/AggressivePruner.php', 'src/Support/ConstEval.php'] as $f) {
    $srcCode = (string) @file_get_contents($root . '/' . $f);
    $codeNoComment = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $srcCode);
    (strpos((string) $codeNoComment, 'eval(') === false)
        ? t_ok("{$f} 无实际 eval( 调用")
        : t_no("{$f} 含 eval", '检测到 eval( 调用');
}

// 恶意构造：一个"看似纯计算循环"里藏 system 调用 token——ConstEval 白名单必须拒绝，
// 产物可运行且输出一致（system 不会在建期或运行期被执行）。
$evilSrc = put($tmp, 'evil', <<<'PHP'
$s = 0;
for ($i = 0; $i < 5; $i++) { $s += $i; }
echo "SAFE:", $s, "\n";
PHP
);
$r = case_run($entry, $evilSrc, $tmp . '/evil_out.php', ['--level=min', '--salt=evil128']);
$r['ok'] ? t_ok('受限 token 循环折叠后输出一致（白名单拒绝注入）') : t_no('恶意循环用例', $r['why']);

// ---------------------------------------------------------------------------
// D) VM 解释器 Logic 恒等式混淆（整数字面量 → 编译期位运算恒等式）
// ---------------------------------------------------------------------------
echo "\n=== D) VM 解释器 Logic 混淆 ===\n";

foreach (['high', 'max', 'ultra', 'aggressive'] as $lv) {
    $r = case_run($entry, $kdfSrc, $tmp . '/vml_' . $lv . '.php', ['--level=' . $lv, '--salt=vml128']);
    $r['ok'] ? t_ok("{$lv} 档（VM+Logic 解释器混淆）输出一致") : t_no("{$lv} VM Logic", $r['why']);
}
// --no-vm-runtime-logic 关闭后仍一致（退回十六进制形态）
$r = case_run($entry, $kdfSrc, $tmp . '/vml_off.php', ['--level=high', '--salt=vml128', '--no-vm-runtime-logic']);
$r['ok'] ? t_ok('--no-vm-runtime-logic 关闭后输出一致') : t_no('VM Logic 开关', $r['why']);

// ---------------------------------------------------------------------------
// E) KDF 原算法恢复（rounds 默认 3、cpool 无预算、honeypot 无条件忙等）
// ---------------------------------------------------------------------------
echo "\n=== E) KDF 原算法恢复 ===\n";

foreach (['high', 'max', 'ultra'] as $lv) {
    $r = case_run($entry, $kdfSrc, $tmp . '/kdf_' . $lv . '.php', ['--level=' . $lv, '--salt=kdf128']);
    $r['ok'] ? t_ok("{$lv} 档（VM+cpool+KDF 原算法）输出一致") : t_no("{$lv} 档 KDF", $r['why']);
}

// ---------------------------------------------------------------------------
echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($fail > 0) {
    echo "失败项：\n";
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
    exit(1);
}
@array_map('unlink', glob($tmp . '/*.php'));
@rmdir($tmp);
