<?php
/**
 * v1.1.8 回归：状态机行注释吞代码 + 注入层变量名撞名
 *
 * 两个真实事故（均为「产物完全不可运行且无任何报错」型）：
 *
 * ① 行注释吞代码：StateMachine 把顶层语句切分并重组进分发器，而
 *    `StatementSplitter::splitRanges()` 会把尾随注释切成独立区间——注释因此可能落到
 *    某个 case 体开头，其后紧跟状态机生成的转移代码（**同一行**）。行注释一路吞掉
 *    后续代码 → 花括号不配对 → `PHP Parse error: Unclosed '{'`。
 *    修复：改写前把行注释统一块化（NameStyle/Whitespace 同思路）。
 *
 * ② 注入层变量名撞名：拟真变量名来自固定词池（HEADS × TAILS），而状态机层 /
 *    死代码层 / 诱饵层**共用同一词池**。状态机先生成状态变量 `$connCursor`，
 *    运行时垃圾层随后插入同名垃圾变量并赋值 → **覆盖状态变量初值** → 分派器
 *    switch(0) 落 default 直接退出 → 产物零输出、无任何报错。
 *    修复：NameStyle 增加保留名机制，能看到全代码的注入层在入口登记已用变量名。
 *
 * 二者都只在**特定 salt** 下出现（词池取值 / 语句切分随 salt 变化），
 * 且「多档 + 多 fixture」组合才会叠加，故本测试用已知坏 salt 直接覆盖。
 */
declare(strict_types=1);

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Logger.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$msg}\n";
    } else {
        $fail++;
        echo "  FAIL  {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/vmjc_' . getmypid();
@mkdir($tmp, 0755, true);

function runPhp(string $file): string
{
    exec('php ' . escapeshellarg($file) . ' 2>&1', $o);
    return implode("\n", $o);
}

function buildOut(string $entry, string $in, string $out, string $args): bool
{
    exec(sprintf(
        'php %s --input=%s --output=%s %s --format=text 2>&1',
        escapeshellarg($entry),
        escapeshellarg($in),
        escapeshellarg($out),
        $args
    ), $o, $ec);
    return $ec === 0 && is_file($out);
}

// ---------------------------------------------------------------- ① 已知坏 salt
echo "===== [1] 已知坏 salt 回归（修复前必现零输出 / Parse error）=====\n";

$fixture = $root . '/tests/fixtures/strings.php';
$native = trim(runPhp($fixture));
ok($native !== '', '原生夹具可运行', $native);

// bs47 是修复前实测的坏 salt：strings 夹具 + max/ultra 档 → 产物零输出
foreach (['max', 'ultra'] as $lv) {
    $out = "$tmp/bs47_$lv.php";
    $okB = buildOut($entry, $fixture, $out, "--level={$lv} --salt=bs47");
    if (!$okB) {
        ok(false, "构建 salt=bs47 level={$lv}");
        continue;
    }
    $lint = (string) shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');
    ok(strpos($lint, 'No syntax errors') !== false, "salt=bs47 level={$lv} php -l 通过", trim($lint));
    $got = trim(runPhp($out));
    ok($got === $native, "salt=bs47 level={$lv} 输出与原生一致", 'got=[' . substr($got, 0, 60) . ']');
}

// ---------------------------------------------------------------- ② 行注释块化
echo "===== [2] 行注释块化：尾随注释不得吞掉后续代码 =====\n";

$cmt = <<<'PHP'
<?php
// 顶部注释
$alpha = 1;                        // 尾随注释 alpha
$beta = 2;                         // 尾随注释 beta
$gamma = $alpha + $beta;            // 尾随注释 gamma
echo "sum=", $gamma, "\n";           // 尾随注释 echo
PHP;
$cmtFile = "$tmp/cmt.php";
file_put_contents($cmtFile, $cmt);
$cmtNative = trim(runPhp($cmtFile));

// 用「仅 statemachine」的层集构建（最小复现面）
$presets = require $root . '/src/config.presets.php';
$smOnly = array_values(array_filter($presets['max']['layers'], fn($l) => $l['name'] === 'statemachine'));
$cfg = [
    'layers'  => $smOnly,
    'salt'    => 'bs47',
    'input'   => $cmtFile,
    'output'  => "$tmp/cmt_out.php",
    'format'  => 'text',
    'silence' => true,
];
file_put_contents("$tmp/cmt_cfg.json", json_encode($cfg, JSON_UNESCAPED_SLASHES));
exec('php ' . escapeshellarg($entry) . ' --config=' . escapeshellarg("$tmp/cmt_cfg.json") . ' --format=text 2>&1', $o, $ec);
ok($ec === 0 && is_file("$tmp/cmt_out.php"), '构建（仅 statemachine 层）');
if (is_file("$tmp/cmt_out.php")) {
    $lint = (string) shell_exec('php -l ' . escapeshellarg("$tmp/cmt_out.php") . ' 2>&1');
    ok(strpos($lint, 'No syntax errors') !== false, '尾随行注释场景 php -l 通过', trim($lint));
    ok(trim(runPhp("$tmp/cmt_out.php")) === $cmtNative, '尾随行注释场景输出与原生一致');
}

// ---------------------------------------------------------------- ③ 变量名避让
echo "===== [3] 注入层变量名避让 =====\n";

$style = new NameStyle(new Salt('reserve-test'));
$style->reserve('$connCursor');
$style->reserve('$rowtmp');
$hits = 0;
for ($i = 0; $i < 40; $i++) {
    $n = $style->varName('junk-' . $i);
    if ($n === '$connCursor' || $n === '$rowtmp') {
        $hits++;
    }
}
ok($hits === 0, 'varName 不返回已登记的保留名', 'hits=' . $hits);

// 保留名机制不得破坏确定性：同 salt 同 label → 同结果
$s1 = new NameStyle(new Salt('det-test'));
$s2 = new NameStyle(new Salt('det-test'));
$s1->reserve('$zzz');
ok($s1->varName('x') !== '' && $s2->varName('x') !== '', 'varName 仍可正常产出');
$s3 = new NameStyle(new Salt('det-test'));
$s3->reserve('$zzz');
ok($s1->varName('x') === $s3->varName('x'), '同 salt + 同保留集 → 同名（可复现）');

// ---------------------------------------------------------------- ④ 多 salt 扫描
echo "===== [4] 多 salt 扫描：产物不得零输出 / 语法错误 =====\n";

$salts = ['bs47', 'bs01', 'bs02', 'bs03', 'bs04', 'bs05', 'bs06', 'bs07'];
$bad = [];
foreach ($salts as $s) {
    foreach (['max', 'ultra'] as $lv) {
        $out = "$tmp/scan_{$s}_{$lv}.php";
        if (!buildOut($entry, $fixture, $out, "--level={$lv} --salt={$s}")) {
            $bad[] = "{$s}/{$lv}: 构建失败";
            continue;
        }
        $lint = (string) shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');
        if (strpos($lint, 'No syntax errors') === false) {
            $bad[] = "{$s}/{$lv}: 语法错误";
            continue;
        }
        $got = trim(runPhp($out));
        if ($got !== $native) {
            $bad[] = "{$s}/{$lv}: 输出不一致 [" . substr($got, 0, 30) . "]";
        }
    }
}
ok($bad === [], '多 salt × 多档 全部正常', implode(' | ', array_slice($bad, 0, 4)));

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
