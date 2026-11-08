<?php
/**
 * 内建 / 原生函数命名参数回归（v1.2.0-dev）
 *
 * 背景：基线压测发现 tests/fixtures/named_args.php 在 high/max/ultra（含 VM）档
 * 100% 运行致命，与 salt 无关：
 *   PHP Fatal error: Uncaught Error: Unknown named parameter $search
 * 根因：CALL_NATIVE 的实参绑定 $___BINDNATIVE___ 硬编码调用
 *   $___REORDER___($args, null)，而 $___REORDER___ 在 $spec === null 时无法把
 *   ['__vmnamed__', 形参名, 值] 匹配到任何形参，直接抛 Unknown named parameter。
 * 用户函数走 BINDARGS（编译器记录了形参规格 $spec）故一直正常，只有内建函数
 * （拿不到编译期形参表）必然中招。
 *
 * 修复：$spec === null 时不再抛错，而是把命名实参还原为【关联数组键】，交给
 * PHP 8 call_user_func_array 的原生命名参数语义；有 $spec 的 VM 函数路径完全不变。
 * 同时给 BINDNATIVE 的 refMask 位运算加 is_int($i) 守卫（字符串键不能位移）。
 * 生成器运行期模板与静态参考运行时 runtime.php 两处同步。
 *
 * 本测试锁死：用户函数命名参数、内建函数命名参数、位置+命名混排、高阶函数
 * 回调在全部 6 档位下逐字节等价。
 */
declare(strict_types=1);

$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$fxDir = $root . '/tests/fixtures';
$tmp   = sys_get_temp_dir() . '/vm_named_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;
function ok(bool $c, string $m, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  {$m}\n"; }
    else { $fail++; echo "  FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
}
function runCapture(string $cmd): array
{
    exec($cmd . ' 2>&1', $o, $ec);
    return ['out' => implode("\n", $o), 'exit' => (int) $ec];
}

/** 构建 + lint + 运行比对 */
function checkCase(string $php, string $entry, string $fx, string $nativeOut,
                   string $tmp, string $lv, string $salt, string $label): void
{
    $out = $tmp . '/o.php';
    $b = runCapture(sprintf('%s %s --input %s --level=%s --salt=%s --output %s',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fx),
        $lv, escapeshellarg($salt), escapeshellarg($out)));
    if ($b['exit'] !== 0 || !is_file($out)) {
        ok(false, $label, '构建失败: ' . substr($b['out'], -120));
        return;
    }
    $lint = runCapture(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($out)));
    if ($lint['exit'] !== 0) {
        ok(false, $label, 'php -l 失败: ' . substr($lint['out'], -120));
        return;
    }
    $r = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($out)));
    ok($r['exit'] === 0 && $r['out'] === $nativeOut, $label,
        $r['exit'] !== 0 ? substr($r['out'], -160) : ('输出偏差 got=' . substr($r['out'], 0, 80)));
}

echo "===== v1.2.0 内建函数命名参数回归 =====\n\n";

// ---- 1. 既有 fixture：用户函数 + 内建函数命名参数混排 ----
echo "-- tests/fixtures/named_args.php（全档位） --\n";
$f1 = $fxDir . '/named_args.php';
$n1 = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($f1)));
ok($n1['exit'] === 0, 'named_args 原生可运行');
foreach (['min', 'low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    checkCase($php, $entry, $f1, $n1['out'], $tmp, $lv, 'zz', "named_args level=$lv");
}

// ---- 2. 广覆盖 fixture：11 组内建函数命名参数 + 位置/命名混排 + 高阶回调 ----
echo "\n-- 广覆盖内建函数命名参数（全档位） --\n";
$f2 = $tmp . '/native_named.php';
file_put_contents($f2, <<<'PHP'
<?php
echo str_replace(search: "e", replace: "E", subject: "hello"), "\n";
echo str_pad(string: "7", length: 3, pad_string: "0", pad_type: STR_PAD_LEFT), "\n";
echo implode(",", array_slice(array: [1,2,3,4], offset: 1, length: 2)), "\n";
echo substr(string: "abcdef", offset: 2, length: 3), "\n";
echo number_format(num: 1234.5, decimals: 1), "\n";
echo htmlspecialchars(string: "<b>", flags: ENT_QUOTES), "\n";
echo preg_replace(pattern: "/a/", replacement: "X", subject: "banana"), "\n";
echo json_encode(value: ["k" => 1], flags: JSON_UNESCAPED_SLASHES), "\n";
echo (in_array(needle: 2, haystack: [1,2,3]) ? "yes" : "no"), "\n";
echo implode(",", array_map(callback: "strtoupper", array: ["a","b"])), "\n";
echo str_replace("a", replace: "b", subject: "banana"), "\n";
echo "DONE\n";
PHP);
$n2 = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($f2)));
ok($n2['exit'] === 0, '广覆盖夹具原生可运行', substr($n2['out'], -120));
ok(str_contains($n2['out'], 'DONE'), '广覆盖夹具原生输出完整');
foreach (['min', 'low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    checkCase($php, $entry, $f2, $n2['out'], $tmp, $lv, 'naFix', "内建命名参数 level=$lv");
}

// ---- 3. 已知坏 salt 组合不回退 ----
echo "\n-- 多 salt 复核（VM 档） --\n";
foreach (['a', 'dev', '120', 'isa'] as $salt) {
    checkCase($php, $entry, $f1, $n1['out'], $tmp, 'high', $salt, "named_args high salt=$salt");
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
