<?php
declare(strict_types=1);
/**
 * v1.0.9 加固回归：生成器真编译 / 闭包语法 / 引用形参写回 / 栈纪律 / 壳层头部约束 /
 * 换行配额 / 互斥注释内层性。
 *
 *   [0] 特性保真：fixtures/v109_features.php 在各等级构建后 php -l 通过，运行输出与
 *       原生逐字节一致（生成器方法 / yield from / 闭包生成器 / by-ref 写回 / heredoc
 *       插值尾部 / f()() / 箭头函数 / const 数字 / 自定义异常）。
 *   [1] 生成器语义探针：自增键、显式键、重复键、send 双向传值、getReturn、yield from
 *       的返回值、try/finally 中的 yield、无限生成器 + break —— 与原生逐一比对。
 *   [2] 帧栈纪律：for 的 init/step 是"求值即丢弃"位置，帧结束时栈必须为空，隐式返回
 *       必须是 null（否则生成器 getReturn() 会返回最后自增的旧值）。
 *   [3] 壳层头部约束：载荷带 declare(strict_types=1) / namespace 时，注入守卫的壳
 *       （anti_debug / anti_hook / anti_tamper）不得把 declare/namespace 挤到后面。
 *   [4] 换行缠绕配额：换行数 ≈ 输入字节数 / 5（允许 ±8%），且 php -l 通过、运行一致。
 *   [5] 互斥注释：成对矛盾断言确实落在【壳内部】（明文产物里 grep 不到），且注入后语法合法。
 */
$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$fixture = __DIR__ . '/fixtures/v109_features.php';
$tmp = sys_get_temp_dir() . '/hv109_' . getmypid();
@mkdir($tmp, 0755, true);

// 直接调用内部类所需的最小加载集（构建走 CLI，不受影响）
foreach ([
    'Parser', 'Salt', 'Logger', 'Support/Random', 'Support/NameStyle',
    'Support/TokenScan', 'Confrontation/ConfrontRng', 'Confrontation/MutualComments',
    'Encode/NewlinePoison',
] as $dep) {
    require_once $root . '/src/' . $dep . '.php';
}

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
    exec($cmd, $o);
    return implode("\n", $o);
}
function lintOk(string $file): bool
{
    return str_contains((string) shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1'), 'No syntax errors');
}

$native = trim(run($fixture));
echo "  native: {$native}\n";

echo "===== [0] 特性保真（v1.0.9 夹具）=====\n";
foreach (['low', 'mid', 'high', 'max', 'ultra'] as $lv) {
    $o = "$tmp/lv_$lv.php";
    $okB = build($entry, $fixture, $o, "--level=$lv --salt=v109$lv");
    ok($okB, "构建 level=$lv");
    if (!$okB) { continue; }
    ok(lintOk($o), "level=$lv php -l 通过");
    $got = trim((string) run($o));
    ok($got === $native, "level=$lv 运行输出一致", 'got=' . substr($got, 0, 70));
}

echo "===== [1] 生成器语义探针（--vm）=====\n";
$genSrc = <<<'PHP'
<?php
function g_auto($n) { for ($i = 0; $i < $n; $i++) { yield $i; } }
function g_keys() { yield 1 => 'a'; yield 1 => 'b'; yield 0 => 'c'; }
function g_ret() { yield 1; return 42; }
function g_send() { $x = yield 1; $y = yield $x * 2; return $x + $y; }
function g_from() {
    $inner = g_ret();
    $r = yield from $inner;
    yield 'r' => $r;
}
function g_finally() {
    try { yield 'F'; } finally { echo "fin;"; }
}
function g_inf() { $i = 0; while (true) { yield $i++; } }
$o = [];
foreach (g_auto(3) as $k => $v) { $o[] = "a{$k}={$v}"; }
foreach (g_keys() as $k => $v) { $o[] = "k{$k}={$v}"; }
$g = g_ret();
foreach ($g as $v) { $o[] = "r{$v}"; }
$o[] = 'ret=' . var_export($g->getReturn(), true);
$g2 = g_send();
$g2->rewind();
$o[] = 's1=' . $g2->current();
$o[] = 's2=' . $g2->send(10);
$g2->next();
$o[] = 's3=' . var_export($g2->getReturn(), true);
foreach (g_from() as $k => $v) { $o[] = "f{$k}={$v}"; }
foreach (g_finally() as $v) { $o[] = "y{$v}"; }
$c = 0;
foreach (g_inf() as $v) { $o[] = "i{$v}"; if (++$c >= 3) { break; } }
echo implode(',', $o), "\n";
PHP;
$genFile = "$tmp/gen_src.php";
file_put_contents($genFile, $genSrc);
$genOut = "$tmp/gen_obf.php";
$okG = build($entry, $genFile, $genOut, "--vm --salt=v109gen");
ok($okG, '构建生成器探针');
if ($okG) {
    ok(lintOk($genOut), '生成器探针 php -l 通过');
    $a = trim((string) run($genFile));
    $b = trim((string) run($genOut));
    ok($a === $b, '生成器语义与原生一致', "native={$a} got={$b}");
}

echo "===== [2] 帧栈纪律（隐式返回 null）=====\n";
$stkSrc = <<<'PHP'
<?php
function s1() { $i = 0; for (; $i < 3; $i++) { $x = $i; } }
function s2() { $i = 0; while ($i < 3) { $i++; } }
function s3() { for ($i = 0; $i < 3; $i++) { } }
function s4() { $a = [1, 2, 3]; foreach ($a as $v) { } }
echo json_encode([s1(), s2(), s3(), s4()]), "\n";
PHP;
$stkFile = "$tmp/stack_src.php";
file_put_contents($stkFile, $stkSrc);
$stkOut = "$tmp/stack_obf.php";
$okS = build($entry, $stkFile, $stkOut, "--vm --salt=v109stk");
ok($okS, '构建栈纪律探针');
if ($okS) {
    $a = trim((string) run($stkFile));
    $b = trim((string) run($stkOut));
    ok($a === $b && $a === '[null,null,null,null]', '隐式返回全为 null', "native={$a} got={$b}");
}

echo "===== [3] 壳层头部约束（declare / namespace 必须在最前）=====\n";
$hdrSrc = <<<'PHP'
<?php
declare(strict_types=1);
namespace app\probe;

use RuntimeException as Boom;

final class Guarded
{
    private int $n = 3;

    public function seq(): \Generator
    {
        for ($i = 0; $i < $this->n; $i++) {
            yield $i;
        }
    }
}

$g = new Guarded();
$sum = 0;
foreach ($g->seq() as $v) { $sum += $v; }
try {
    throw new Boom('boom');
} catch (\RuntimeException $e) {
    echo 'sum=', $sum, ';', $e->getMessage(), "\n";
}
PHP;
$hdrFile = "$tmp/hdr_src.php";
file_put_contents($hdrFile, $hdrSrc);
$hdrNative = trim((string) run($hdrFile));
foreach ([
    'anti_debug'  => '--vm --salt=v109hdr --anti-debug',
    'anti_hook'   => '--vm --salt=v109hdr --anti-hook',
    'anti_tamper' => '--vm --salt=v109hdr --anti-tamper',
] as $tag => $args) {
    $o = "$tmp/hdr_$tag.php";
    $okH = build($entry, $hdrFile, $o, $args);
    ok($okH, "构建 {$tag}");
    if (!$okH) { continue; }
    ok(lintOk($o), "{$tag} php -l 通过");
    $src = (string) file_get_contents($o);
    // declare 必须在 <?php 之后的第一条语句位（前面只允许注释/空白）
    $firstStmt = preg_match('/<\?php\b(?:[^;{]*?)(declare\s*\(|namespace\s+[A-Za-z_\\\\])/s', $src, $m) === 1;
    ok($firstStmt, "{$tag} 头部语句顺序合法");
    $got = trim((string) run($o));
    ok($got === $hdrNative, "{$tag} 运行输出一致", 'got=' . substr($got, 0, 60));
}

echo "===== [4] 换行缠绕配额（1/5 字节）=====\n";
// [4.1] 单元级：配额公式与"实际落地数"必须精确相等
ok(NewlinePoison::targetFor(2394) === 479, '配额公式 ceil(bytes/5)', 'got=' . NewlinePoison::targetFor(2394));
ok(NewlinePoison::targetFor(1000, ['divisor' => 10]) === 100, '配额公式可用 divisor 覆盖');
$np = new NewlinePoison(new Logger(Logger::LEVEL_ERROR), new Salt('v109nl'));
$plainSrc = "<?php\n" . str_repeat("\$x = 1;\n", 40);
$npOut = $np->wrap($plainSrc, ['count' => 120, 'string_ratio' => 0.0, 'seed_tag' => 'unit']);
ok($np->lastCount === 120, '单元：实际注入数 = 请求数', 'got=' . $np->lastCount);
$npFile = "$tmp/np_unit.php";
file_put_contents($npFile, $npOut);
ok(lintOk($npFile), '单元：注入后 php -l 通过');
ok(trim((string) run($npFile)) === '', '单元：注入不改变语义（无输出）');

// [4.2] 集成级：产物换行数不少于配额，且 php -l 通过、运行一致
$nlOut = "$tmp/nl.php";
$okN = build($entry, $fixture, $nlOut, "--newline-poison --salt=v109nl");
ok($okN, '构建换行缠绕产物');
if ($okN) {
    ok(lintOk($nlOut), '换行缠绕产物 php -l 通过');
    $bytes = (int) filesize($fixture);
    $want = (int) ceil($bytes / 5);
    $got = substr_count((string) file_get_contents($nlOut), "\n");
    ok($got >= $want, '换行数不少于配额（1/5 字节）', "bytes={$bytes} want={$want} got={$got}");
    ok($got <= (int) ($want * 1.6), '换行数未异常膨胀', "want={$want} got={$got}");
    ok(trim((string) run($nlOut)) === $native, '换行缠绕后运行输出一致');
}

echo "===== [5] 互斥注释内层性（壳内部，明文不可见）=====\n";
$mcOut = "$tmp/mc.php";
$okM = build($entry, $fixture, $mcOut, "--level=low --salt=v109mc --mutual-comments --mutual-intensity=3");
ok($okM, '构建互斥注释产物');
if ($okM) {
    ok(lintOk($mcOut), '互斥注释产物 php -l 通过');
    $plain = (string) file_get_contents($mcOut);
    ok(!str_contains($plain, '【已下线】') && !str_contains($plain, '唯一生效路径'),
        '明文产物不含互斥注释（已进壳内部）');
    ok(trim((string) run($mcOut)) === $native, '互斥注释产物运行输出一致');
}
// 直接注入路径：注释成对矛盾 + 语法合法
$mc = new MutualComments(new Logger(Logger::LEVEL_ERROR), new Salt('v109mc'));
$sample = "<?php\n\$a = 1;\n\$b = \$a + 2;\n\$c = \$b * 3;\n\$d = \$c - 4;\n\$e = \$d / 5;\n"
    . "\$f = \$e + 6;\n\$g = \$f * 7;\n\$h = \$g - 8;\n\$i = \$h + 9;\n\$j = \$i * 10;\necho \$j;\n";
$inj = $mc->inject($sample, 3, 't');
ok($inj !== $sample, '直接注入产生注释');
ok(substr_count($inj, '//') >= 6, '成套矛盾断言已注入', 'count=' . substr_count($inj, '//'));
$tmpMc = "$tmp/mc_direct.php";
$tmpPlain = "$tmp/mc_plain.php";
file_put_contents($tmpMc, $inj);
file_put_contents($tmpPlain, $sample);
ok(lintOk($tmpMc), '注入后语法合法');
ok(trim((string) run($tmpMc)) === trim((string) run($tmpPlain)), '注释放置不改变语义');

echo "===== [6] 字符串偏移 / 静态局部 isset / 大值栈保真 =====\n";
// 这三类都是 v1.0.9 修掉的真实边界：
//   - isset($s[$i]) 在 VM 里恒为 false（键路径求值只认数组/对象，不认字符串偏移）；
//   - 负下标 $s[-1] 取不到末字符（IDXGET 只接受非负）；
//   - 静态局部变量被映射到共享帧槽位 \x01sl:…，而 isset/empty/unset 仍按原名查表，
//     于是 isset($static) 恒 false、empty($static) 恒 true；
//   - 栈内存加密逐字节 XOR，长串/大数组每次入栈都 O(n)，拼接循环退化成 O(n²)。
$probe = $tmp . '/bounds.php';
file_put_contents($probe, <<<'PHP'
<?php
$s = 'abc';
$o = '';
for ($i = 0; $i < 5; $i++) { $o .= isset($s[$i]) ? '1' : '0'; }
$o .= '|' . (isset($s[-1]) ? '1' : '0') . (isset($s[-3]) ? '1' : '0') . (isset($s[-4]) ? '1' : '0');
$e = '';
$o .= '|' . (isset($e[0]) ? '1' : '0');
$o .= '|' . (empty($s[0]) ? '1' : '0') . (empty($s[3]) ? '1' : '0');
$o .= '|' . $s[-1] . $s[-2] . $s[-3];
function st_cnt() {
    static $m = null;
    if ($m === null) { $m = ['A' => 0, 'B' => 2]; }
    return (isset($m['A']) ? 'A' : '-') . (isset($m['B']) ? 'B' : '-')
        . (isset($m['C']) ? 'C' : '-') . (empty($m['B']) ? 'e' : 'n');
}
$o .= '|' . st_cnt() . st_cnt();
function st_cnt2() {
    static $n = 0;
    $n++;
    if ($n === 1) { unset($n); }
    return isset($n) ? 'y' : 'n';
}
$o .= '|' . st_cnt2() . st_cnt2();
$big = str_repeat('x', 5000);
$arr = [];
for ($i = 0; $i < 200; $i++) { $arr[] = $i; }
$o .= '|' . strlen($big) . ':' . count($arr) . ':' . $big[4999] . ':' . $arr[199];
$sum = 0;
foreach ($arr as $v) { $sum += $v; }
$o .= ':' . $sum;
echo $o, "\n";
PHP);
$wantBounds = trim((string) run($probe));
foreach ([
    ['--vm --salt=v109bd1', '单层 VM'],
    ['--vm --salt=v109bd2 --vm-cpool --vm-keychain', 'VM+常量池+哈希链'],
    ['--vm --salt=v109bd3 --vm-layers=2', '嵌套 VM'],
] as [$args, $label]) {
    $out = "$tmp/bounds_" . md5($args) . '.php';
    $okB = build($entry, $probe, $out, $args);
    ok($okB, "构建 {$label}");
    if (!$okB) { continue; }
    ok(lintOk($out), "{$label} php -l 通过");
    $got = trim((string) run($out));
    ok($got === $wantBounds, "{$label} 边界语义一致", 'got=' . substr($got, 0, 80) . ' want=' . substr($wantBounds, 0, 80));
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
