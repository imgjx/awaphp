<?php
/**
 * 坏 salt 回归（v1.1.9）
 *
 * 背景：诊断扫描发现 salt=[zz] 在 high 档 + complex_app 夹具下，产物输出丢失
 * 首条记录（User Scores 段只剩 Bob，Alice 整条消失）。逐层二分定位：
 *   shell:vm 之后的外层壳 → shell_shield（逐层壳自保护）→ ShellProtector
 *   → TokenObfuscator 的 token_literal → true/false 改写为【裸 !0 / !1】。
 * 裸 !0/!1 是表达式而非原子字面量，壳层自保护会对整段 VM 产物反复 token 化
 * 并压缩去空白，裸 ! 与相邻运算符重新结合（! 优先级低于 ** / instanceof），
 * 特定 salt 下改变结合方式 → 语义被破坏。v1.1.9 改为带括号 (!0)/(!1)，
 * 固定表达式边界，与 true/false 100% 等价。
 *
 * 本测试锁死：历史坏 salt（zz / bs47）在多档位、多夹具、多形态 salt 下，
 * 产物 php -l 通过且运行输出与原生逐字节一致。字面量括号形态的单元断言
 * 见 tests/token_obf.php 的 2b 段。
 */
declare(strict_types=1);

$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$fxDir = $root . '/tests/fixtures';
$tmp   = sys_get_temp_dir() . '/salt_reg_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;

function ok(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  {$m}\n"; }
    else { $fail++; echo "  FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
}
function runCapture(string $cmd): array {
    exec($cmd . ' 2>&1', $o, $ec);
    return ['out' => implode("\n", $o), 'exit' => (int) $ec];
}

/** 构建 + lint + 原生/产物运行比对，返回 [是否通过, 详情] */
function verify(string $php, string $entry, string $fx, string $tmp, string $tag, string $level, string $salt): array {
    $out = $tmp . '/' . $tag . '.php';
    $cmd = sprintf('%s %s --input=%s --output=%s --level=%s --salt=%s --format=text 2>&1',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fx),
        escapeshellarg($out), escapeshellarg($level), escapeshellarg($salt));
    $b = runCapture($cmd);
    if ($b['exit'] !== 0 || !is_file($out)) {
        return [false, '构建失败 exit=' . $b['exit'] . ' ' . substr($b['out'], 0, 160)];
    }
    $l = runCapture(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($out)));
    if ($l['exit'] !== 0) {
        return [false, 'php -l 未通过: ' . substr($l['out'], 0, 160)];
    }
    $orig = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($fx)));
    $obf  = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($out)));
    if ($orig['exit'] !== 0) {
        return [false, '原生运行失败 exit=' . $orig['exit']];
    }
    if ($obf['exit'] !== 0) {
        return [false, '产物运行失败 exit=' . $obf['exit'] . ' ' . substr($obf['out'], 0, 160)];
    }
    if ($orig['out'] !== $obf['out']) {
        return [false, '输出不一致: 原=' . substr($orig['out'], 0, 100) . ' | 混=' . substr($obf['out'], 0, 100)];
    }
    return [true, ''];
}

$complex = $fxDir . '/complex_app.php';
if (!is_file($complex)) {
    fwrite(STDERR, "缺少夹具 complex_app.php\n");
    exit(1);
}

echo "===== 坏 salt 回归（v1.1.9）=====\n";
echo "PHP: " . PHP_VERSION . "\n\n";

// [1] 历史坏 salt：zz（v1.1.9 修复）/ bs47（v1.1.8 修复）× 全档位
echo "-- [1] 历史坏 salt × 全档位 --\n";
foreach (['zz', 'bs47'] as $salt) {
    foreach (['low', 'mid', 'high', 'max', 'ultra'] as $lv) {
        [$ok, $d] = verify($php, $entry, $complex, $tmp, "h_{$salt}_{$lv}", $lv, $salt);
        ok($ok, "salt=[{$salt}] level={$lv} complex_app 输出一致", $d);
    }
}

// [2] 多形态 salt × high 档（短/长/数字/大小写/非 ASCII/特殊字符）
echo "\n-- [2] 多形态 salt × high --\n";
$salts = ['a', 'Zz', '42', '0000', 'longsalt_value_2024', '中文盐', 'salt!#%', 'MiXeD'];
foreach ($salts as $salt) {
    $tag = 's_' . md5($salt);
    [$ok, $d] = verify($php, $entry, $complex, $tmp, $tag, 'high', $salt);
    ok($ok, "salt=[{$salt}] level=high complex_app 输出一致", $d);
}

// [3] 坏 salt zz × high × 多夹具（形态最丰富的几个）
echo "\n-- [3] 坏 salt zz × high × 多夹具 --\n";
foreach (['complex_app.php', 'token_rich.php', 'classes.php', 'v109_features.php'] as $fx) {
    $p = $fxDir . '/' . $fx;
    if (!is_file($p)) { continue; }
    [$ok, $d] = verify($php, $entry, $p, $tmp, 'f_' . md5($fx), 'high', 'zz');
    ok($ok, "salt=[zz] level=high {$fx} 输出一致", $d);
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
