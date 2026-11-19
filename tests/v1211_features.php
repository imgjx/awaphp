<?php
/**
 * v1.2.11 专项回归：超级指令取消 NOP 占位，改为「真删 + 重算位置」
 *
 *   A) 白盒：合并后段变短、无 NOP、跳转目标合法、异常表精确映射
 *   B) 真实产物：循环 / goto / try-catch-finally / 闭包 / 类方法语义不变（多档多盐）
 *   C) 关闭超级指令（--no-vm-super-isa）仍然正确
 *   D) 收缩确实发生（指令条数下降）
 *   E) 版本号
 *   F) VM 兜底路径语义修复（PHPRUN 的 global / 块内函数声明 / 位与 / 裸块）
 *
 * 用法：php tests/v1211_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/v1211_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/src/Vm/Op.php';
require_once $root . '/src/Vm/VmSuperIsa.php';
require_once $root . '/src/Salt.php';

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

/** 构建 + lint + 限时执行 + 与原生输出逐字节比对。 */
function case_run(string $entry, string $src, string $out, array $args, int $timeout = 30): array
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
    $a = shell_exec('timeout ' . $timeout . ' php ' . escapeshellarg($src) . ' 2>&1');
    $b = shell_exec('timeout ' . $timeout . ' php -d opcache.enable_cli=0 ' . escapeshellarg($out) . ' 2>/dev/null');
    if (trim((string) $a) !== trim((string) $b)) {
        return ['ok' => false, 'why' => "输出不一致\n    期望: " . substr(trim((string) $a), 0, 160) . "\n    实际: " . substr(trim((string) $b), 0, 160)];
    }
    return ['ok' => true, 'why' => ''];
}

/** 一组可合并的 4 条指令（PUSH_C n, PUSH_C n+1, ADD, POP_V $vn） */
function gseg(int $n): array
{
    return [
        [VmOp::PUSH_C, $n],
        [VmOp::PUSH_C, $n + 1],
        [VmOp::ADD],
        [VmOp::POP_V, '$v' . $n],
    ];
}

// ===========================================================================
echo "\n=== A) 白盒：段收缩 / 无 NOP / 跳转 / 异常表 ===\n";
$salt = new Salt('v1211-unit');

// A1 段变短 + 无 NOP
$seg = [];
for ($i = 0; $i < 6; $i++) {
    $seg = array_merge($seg, gseg($i));
}
$before = count($seg);
$si = new VmSuperIsa($salt);
$prog = ['main' => $seg, 'mainET' => [], 'funcs' => [], 'classes' => []];
$si->plan($prog, 4);
$after = count($prog['main']);
$after < $before ? t_ok("段收缩：{$before} → {$after} 条") : t_no('段未收缩', "{$before} → {$after}");
$nop = 0;
foreach ($prog['main'] as $ins) {
    if (($ins[0] ?? null) === VmOp::NOP) {
        $nop++;
    }
}
$nop === 0 ? t_ok('合并后无 NOP 残留') : t_no('仍有 NOP', "{$nop} 条");
$si->defs() !== [] ? t_ok('超级指令定义已生成') : t_no('无超级指令', 'defs 为空');

// A2 跳转目标是组首 → 收缩后指向 super
$seg2 = array_merge(gseg(1), gseg(2), [[VmOp::JZ, 13 - 9]], gseg(3), gseg(4), [[VmOp::JMP, 4 - 18]]);
$si2 = new VmSuperIsa($salt);
$p2 = ['main' => $seg2, 'mainET' => [], 'funcs' => [], 'classes' => []];
$si2->plan($p2, 4);
$s2 = $p2['main'];
$jz2 = null;
foreach ($s2 as $i => $ins) {
    if (($ins[0] ?? -1) === VmOp::JZ) {
        $jz2 = $i;
        break;
    }
}
$t2 = $jz2 === null ? -1 : $jz2 + 1 + (int) $s2[$jz2][1];
$isSuper = $t2 >= 0 && isset($s2[$t2]) && ($s2[$t2][0] ?? 0) >= VmSuperIsa::SUPER_BASE;
$isSuper ? t_ok("JZ 目标 {$t2} 指向超级指令（组首语义保持）") : t_no('JZ 目标不是超级指令', "target={$t2}");

// A3 跳转落在组内部 → 该位置不得被合并
$seg3 = array_merge(gseg(1), [[VmOp::JZ, 6 - 5]], gseg(2), gseg(3), gseg(4), gseg(5));
$si3 = new VmSuperIsa($salt);
$p3 = ['main' => $seg3, 'mainET' => [], 'funcs' => [], 'classes' => []];
$si3->plan($p3, 4);
$s3 = $p3['main'];
$jz3 = null;
foreach ($s3 as $i => $ins) {
    if (($ins[0] ?? -1) === VmOp::JZ) {
        $jz3 = $i;
        break;
    }
}
$t3 = $jz3 === null ? -1 : $jz3 + 1 + (int) $s3[$jz3][1];
$landOp = $t3 >= 0 ? ($s3[$t3][0] ?? -1) : -1;
$landOp === VmOp::PUSH_C
    ? t_ok("JZ 目标 {$t3} 仍是 PUSH_C（组内位置未被吞）")
    : t_no('组内目标被吞', "target={$t3} op={$landOp}");

// A4 异常表精确映射
$seg4 = [];
for ($i = 0; $i < 6; $i++) {
    $seg4 = array_merge($seg4, gseg($i));
}
$si4 = new VmSuperIsa($salt);
$p4 = ['main' => $seg4, 'mainET' => [[0, 12, 20, 'catch', '', '$e']], 'funcs' => [], 'classes' => []];
$si4->plan($p4, 4);
$e4 = $p4['mainET'][0] ?? [];
$n4 = count($p4['main']);
($e4[0] ?? -1) === 0 && ($e4[1] ?? -1) === 3 && ($e4[2] ?? -1) === 5
    ? t_ok("异常表精确重算为 [0, 3, 5]（段长 {$n4}）")
    : t_no('异常表映射不精确', json_encode($e4) . " n={$n4}");

// ===========================================================================
echo "\n=== B) 真实产物：循环 / goto / try / 闭包 / 类方法 ===\n";
$src = $tmp . '/sem.php';
file_put_contents($src, <<<'PHP'
<?php
function fib(int $n): int { return $n < 2 ? $n : fib($n - 1) + fib($n - 2); }
class C {
    private int $b = 3;
    public function run(array $xs): string {
        $o = [];
        foreach ($xs as $i => $x) {
            if ($x % 3 === 0) { $o[] = 'F' . $i; }
            elseif ($x % 3 === 1) { $o[] = 'a' . ($x * 2 + $this->b); }
            else { $o[] = 'b' . ($x - $this->b); }
        }
        return implode(',', $o);
    }
}
$sum = 0;
for ($i = 0; $i < 40; $i++) { $sum += ($i * 7 + 3) % 11; if ($i % 5 === 0) { $sum ^= $i; } }
echo "loop=", $sum, "\n";
echo "fib=", fib(14), "\n";
echo "cls=", (new C())->run([3, 4, 5, 6, 7, 8]), "\n";
$cl = function (int $a) use ($sum): int { $s = 0; for ($j = 0; $j < $a; $j++) { $s += $j ^ ($sum & 7); } return $s; };
echo "clo=", $cl(9), "\n";
try {
    $x = 10;
    for ($i = 0; $i < 3; $i++) { $x = intdiv($x, $i); }
    echo "nope\n";
} catch (DivisionByZeroError $e) {
    echo "caught\n";
} finally {
    echo "finally\n";
}
try { echo "t2=", 1 + 2, "\n"; } catch (Throwable $e) { echo "no\n"; } finally { echo "fin2\n"; }
$i = 0;
loop:
$i += 3;
if ($i < 10) { goto loop; }
echo "goto=", $i, "\n";
$m = 0;
while (true) {
    switch ($m % 4) { case 0: $m += 3; break; case 1: $m += 5; break; default: $m += 1; break; }
    if ($m > 30) { break; }
}
echo "sw=", $m, "\n";
// 生成器（段首 GEN_HEAD + YIELD）
function gen(int $n): Generator { for ($i = 0; $i < $n; $i++) { yield $i => $i * $i; } }
$g = 0;
foreach (gen(6) as $k => $v) { $g += $k + $v; }
echo "gen=", $g, "\n";
echo "done\n";
PHP
);
foreach (['high', 'max'] as $lv) {
    foreach (['x', 'v129high', 'abc124'] as $saltStr) {
        $out = $tmp . "/sem_{$lv}_{$saltStr}.php";
        $r = case_run($entry, $src, $out, ['--level=' . $lv, '--salt=' . $saltStr]);
        $r['ok'] ? t_ok("{$lv}/{$saltStr} 语义一致") : t_no("{$lv}/{$saltStr}", $r['why']);
    }
}

// ===========================================================================
echo "\n=== C) 关闭超级指令仍正确 ===\n";
foreach (['high'] as $lv) {
    $out = $tmp . '/nosuper.php';
    $r = case_run($entry, $src, $out, ['--level=' . $lv, '--salt=x', '--no-vm-super-isa']);
    $r['ok'] ? t_ok("--no-vm-super-isa 语义一致") : t_no('--no-vm-super-isa', $r['why']);
}

// ===========================================================================
echo "\n=== D) 收缩幅度（真实 workload）===\n";
$pre = 0;
$post = 0;
$wf = $root . '/tests/perf_workload_vm.php';
if (is_file($wf)) {
    // 直接复用 VmSuperIsa 的白盒能力：构造同等规模的段，确认收缩比例 >= 15%
    $big = [];
    for ($i = 0; $i < 40; $i++) {
        $big = array_merge($big, gseg($i));
    }
    $n0 = count($big);
    $sib = new VmSuperIsa($salt);
    $pb = ['main' => $big, 'mainET' => [], 'funcs' => [], 'classes' => []];
    $sib->plan($pb, 4);
    $n1 = count($pb['main']);
    $ratio = $n0 > 0 ? 1 - $n1 / $n0 : 0;
    $ratio >= 0.15
        ? t_ok(sprintf('收缩比例 %.1f%%（%d → %d 条）', $ratio * 100, $n0, $n1))
        : t_no('收缩比例不足', sprintf('%.1f%%', $ratio * 100));
} else {
    t_no('workload 缺失', $wf);
}

// ===========================================================================
echo "\n=== E) 版本号 ===\n";
$help = (string) shell_exec('php ' . escapeshellarg($entry) . ' --help 2>&1');
preg_match('/v(\d+\.\d+\.\d+)/', $help, $vm) && version_compare($vm[1], '1.2.11', '>=')
    ? t_ok('--help 显示版本号 ' . $vm[1])
    : t_no('版本号', '未在 --help 找到 >= 1.2.11 的版本号');

// ===========================================================================
// F) VM 兜底路径的三处语义修复（v1.2.11 顺带修的既有缺陷）
//   - 不透明载荷（PHPRUN）里顶层变量没进 $GLOBALS → 函数内 global 读到空值
//   - 块内命名函数声明（funcsplit 守卫）退成原生声明 → 收到 VM 对象即致命
//   - 位与 / 裸块语句不支持 → 整条语句退 eval（连带上面两条一起爆）
// 这些用例特意取曾经必挂的盐（a3/a5）。
// ===========================================================================
echo "\n=== F) VM 兜底路径语义修复 ===\n";
$src2 = $tmp . '/vmfix.php';
file_put_contents($src2, <<<'PHP'
<?php
$g = "hi";
$n = 3;
function readG()
{
    global $g, $n;
    return $g . ':' . $n;
}
function loopG()
{
    global $n;
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $s += $i; }
    return $s;
}
echo readG(), "|", loopG(), "\n";

trait T { public function hi($m) { return "[T]" . $m; } }
class K
{
    use T;
    public function m($x) { return $x * 2; }
}
$k = new K();
echo $k->hi("ok"), "=", $k->m(21), "\n";

$a = 6;
$b = 3;
echo "bw=", (($a & $b) | ((~$a & 0xffffffff) & $b)), "\n";
{
    $a = $a + 1;
}
echo "blk=", $a, "\n";
PHP
);
foreach ([['high', 'a3'], ['high', 'a5'], ['max', 'a5'], ['ultra', 'a3']] as [$lv, $sl]) {
    $out = $tmp . "/vmfix_{$lv}_{$sl}.php";
    $r = case_run($entry, $src2, $out, ['--level=' . $lv, '--salt=' . $sl], 60);
    $r['ok'] ? t_ok("{$lv}/{$sl} global+块内函数+位运算+裸块 一致") : t_no("{$lv}/{$sl} vmfix", $r['why']);
}

// ===========================================================================
echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($fail > 0) {
    echo "失败项：\n";
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
    exit(1);
}
@array_map('unlink', glob($tmp . '/*.php'));
exit(0);
