<?php
/**
 * 动态 ISA 回归（v1.2.0）
 *
 * 背景：v1.1.x 的 ISA 精简虽把 46 条同构 opcode 收口成 6 个参数化 opcode，
 * 但计划是【静态】的——编号写死 200–205、sub-id 是族内固定下标、族成员与
 * case 顺序固定。识别出「这是 BINOP 族」即可查表还原全部语义，ISA 结构本身
 * 成了跨产物的固定指纹。v1.2.0 改为按 salt 逐构建动态生成计划：
 *   ① 参数化 opcode 逻辑编号（[300,399] 洗牌，避开 VmOp 常量 ≤205）；
 *   ② 族内 sub-id（全族唯一、非连续，从 [1,4095] 洗牌池分配）；
 *   ③ 每族成员顺序（case 排列）按 salt 洗牌。
 * 编译端 rewriteProgram 与运行期 paramFamilyBody 共用同一计划实例。
 *
 * 同时锁死同批修复的两个缺陷：
 *   - rewriteProgram 段覆盖补全：参数默认值 / 类方法参数默认值 / 类属性 /
 *     类常量段里的常量表达式此前以旧 opcode 明文落盘，绕过 ISA 收口。
 *   - 内建函数命名参数（见 tests/vm_native_named_args.php）。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Vm/Op.php';
require_once $root . '/src/Salt.php';
require_once $root . '/src/Vm/VmIsa.php';

$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/isa_dyn_' . getmypid();
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

echo "===== v1.2.0 动态 ISA 回归 =====\n\n";

// ---- 1. 静态默认计划（兼容 / 文档对照） ----
echo "-- 静态默认计划 --\n";
$d = VmIsa::defaultPlan();
ok(!$d->isDynamic(), 'defaultPlan 为静态计划');
ok($d->paramOp('bin') === VmOp::BINOP
    && $d->paramOp('arr') === VmOp::ARRAY_BUILD, '静态计划编号沿用 200–205');
ok($d->subId(VmOp::ADD) === 0 && $d->subId(VmOp::SUB) === 1, '静态计划 sub-id 为族内下标');

// ---- 2. 动态计划：确定性 + 跨 salt 差异 ----
echo "\n-- 计划派生 --\n";
$a  = new VmIsa(new Salt('isaA'));
$a2 = new VmIsa(new Salt('isaA'));
$b  = new VmIsa(new Salt('isaB'));
ok($a->isDynamic(), '带 salt 的计划为动态');
ok($a->describe() === $a2->describe(), '同 salt 计划完全可复现');
ok($a->describe() !== $b->describe(), '不同 salt 计划不同');
ok($a->describe() !== VmIsa::defaultPlan()->describe(), '动态计划不同于静态计划');

// ---- 3. 参数化 opcode 编号约束 ----
echo "\n-- 参数化编号 --\n";
$desc = $a->describe();
$ops = array_values($desc['paramOps']);
ok(count($ops) === 6 && count(array_unique($ops)) === 6, '6 个参数化编号互不相同');
ok(min($ops) >= 300, '参数化编号避开 VmOp 常量区间（≤205）', 'min=' . min($ops));
ok($a->familyOf($a->paramOp('inc')) === 'inc', 'familyOf 反查正确');
ok($a->familyOf(VmOp::ADD) === null, '真实 opcode 不会被误判为参数化码');
$uniqueFams = [];
foreach ($ops as $op) { $uniqueFams[] = $a->familyOf($op); }
ok(count(array_unique($uniqueFams)) === 6, '每个编号映射回唯一语义族');

// ---- 4. sub-id：全局唯一、非连续、不固定 ----
echo "\n-- sub-id --\n";
$subs = array_values($desc['subIds']);
ok(count($subs) === 46, '46 条同构 opcode 全部分配 sub-id', 'count=' . count($subs));
ok(count($subs) === count(array_unique($subs)), 'sub-id 全局唯一（跨族不重复）');
ok(min($subs) >= 1 && max($subs) > 45, 'sub-id 非 0..45 连续区间', 'min=' . min($subs) . ' max=' . max($subs));
ok($a->subId(VmOp::ADD) !== 0, 'ADD 的 sub-id 不再是固定 0');
ok($a->subId(VmOp::ADD) !== $b->subId(VmOp::ADD), 'ADD 的 sub-id 跨 salt 不同');
ok($a->subId(VmOp::ADD) !== $a->subId(VmOp::SUB), '同族不同操作 sub-id 不同');
ok($a->subId(VmOp::PUSH_C) === null, '非族内 opcode 无 sub-id');

// ---- 5. 族成员洗牌 ----
echo "\n-- 成员排列 --\n";
ok($a->members('bin') !== VmIsa::BIN, 'bin 族成员顺序被洗牌');
ok(count($a->members('bin')) === count(VmIsa::BIN), '洗牌不改变成员数量');
$sorted = $a->members('bin'); sort($sorted); $base = VmIsa::BIN; sort($base);
ok($sorted === $base, '洗牌是排列，不丢成员');

// ---- 6. rewriteProgram：段覆盖补全 + 指令条数不变 + 幂等 ----
echo "\n-- 段覆盖与重写 --\n";
$isa = new VmIsa(new Salt('segtest'));
$program = [
    'main'        => [[VmOp::ECHO, 1], [VmOp::ADD, 1, 2]],
    'funcs'       => ['f' => [[VmOp::SUB, 5, 2]]],
    'funcParams'  => ['f' => [['a', [[VmOp::MUL, 2, 3]], 0]]],
    'classParams' => ['C' => ['m' => [['b', [[VmOp::CONCAT, 'x', 'y']], 0]]]],
    'classes'     => ['C' => [
        'methods' => ['m' => [[VmOp::DIV, 8, 2]]],
        'props'   => ['p' => [[VmOp::ADD, 1, 1]]],
        'consts'  => ['K' => [[VmOp::ADD, 5, 6]]],
    ]],
    'embeds'      => [[[VmOp::MOD, 9, 4]]],
];
$countIns = static function (array $p): int {
    $n = 0;
    $walk = function ($v) use (&$n, &$walk): void {
        if (!is_array($v)) { return; }
        if (isset($v[0]) && is_int($v[0])) { $n++; return; }
        foreach ($v as $x) { $walk($x); }
    };
    $walk($p);
    return $n;
};
$before = $countIns($program);
$isa->rewriteProgram($program);
ok($countIns($program) === $before, 'rewriteProgram 不改变指令条数', "before=$before after=" . $countIns($program));

$allSegs = [
    $program['main'], $program['funcs']['f'],
    $program['funcParams']['f'][0][1], $program['classParams']['C']['m'][0][1],
    $program['classes']['C']['methods']['m'], $program['classes']['C']['props']['p'],
    $program['classes']['C']['consts']['K'], $program['embeds'][0],
];
$oldOps = [VmOp::ADD => 'bin', VmOp::SUB => 'bin', VmOp::MUL => 'bin',
           VmOp::DIV => 'bin', VmOp::MOD => 'bin', VmOp::CONCAT => 'bin'];
// 精确断言：所有段里都不再出现旧 opcode，且每条参数化指令的 (码, sub) 匹配计划
$noOld = true;
$aligned = true;
foreach ($allSegs as $seg) {
    foreach ($seg as $ins) {
        if (isset($oldOps[$ins[0]])) { $noOld = false; continue; }
        if ($ins[0] === VmOp::ECHO) { continue; }
        $fam = $isa->familyOf($ins[0]);
        if ($fam === null) { $aligned = false; continue; }
        $hit = false;
        foreach ($oldOps as $op => $f) {
            if ($f === $fam && $ins[0] === $isa->paramOp($f) && $ins[1] === $isa->subId($op)) { $hit = true; break; }
        }
        if (!$hit) { $aligned = false; }
    }
}
ok($noOld, '全部 8 个段（含参数默认值/类属性/类常量）的旧 opcode 均被收口');
ok($aligned, '重写后 (参数化码, sub-id) 与本次计划逐条对齐');
$snap = $program;
$isa->rewriteProgram($program);
ok($program === $snap, 'rewriteProgram 幂等（重复调用不变）');

// ---- 7. 端到端运行等价（含参数默认值 / 类常量 / 命名参数） ----
echo "\n-- 端到端运行等价 --\n";
$fx = $tmp . '/isa_seg.php';
file_put_contents($fx, <<<'PHP'
<?php
function segSum($a = 1 + 2 * 3, $b = "x" . "y") { return $a . $b; }
class SegC {
    const K = 5 + 6;
    public $p = "p" . "q";
    public function mix($x = 1 + 1, $y = "m" . "n") { return $x . $y; }
}
$s = str_replace(search: "e", replace: "E", subject: "hello");
echo segSum(), "|", SegC::K, "|", (new SegC)->p, "|", (new SegC)->mix(), "|", $s, "\n";
PHP);
$native = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($fx)));
ok($native['exit'] === 0, '构造夹具原生可运行');
foreach (['low', 'mid', 'high', 'max'] as $lv) {
    foreach (['zz', 'isaB'] as $salt) {
        $out = $tmp . '/o.php';
        runCapture(sprintf('%s %s --input %s --level=%s --salt=%s --output %s',
            escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fx),
            $lv, escapeshellarg($salt), escapeshellarg($out)));
        $lint = runCapture(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($out)));
        $run  = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($out)));
        ok($lint['exit'] === 0 && $run['exit'] === 0 && $run['out'] === $native['out'],
            "端到端等价 level=$lv salt=$salt",
            $run['exit'] !== 0 ? substr($run['out'], -120) : ('got=' . substr($run['out'], 0, 60)));
    }
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
