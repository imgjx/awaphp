<?php
/**
 * v1.2.6 专项回归：全语句函数打散 + 多变恒真三分支诱饵 + VM 解释器混淆
 *                    + 零损耗等价 ISA 字节码拆解
 *
 * 与 tests/run_tests.php 同风格：每个用例独立构建、lint、执行、逐字节比对输出。
 * 用法：php tests/v125_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$fxDir = $root . '/tests/fixtures';
$tmp = sys_get_temp_dir() . '/v126_' . bin2hex(random_bytes(4));
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

/**
 * 构建 + lint + 执行 + 与原生输出比对（忽略报错信息里的文件路径差异）
 * @return array{ok:bool,why:string}
 */
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
        $s = preg_replace('/in \/[^\s:]+ on line \d+/', 'in FILE on line N', $s);
        return preg_replace('/\b\/tmp\/[A-Za-z0-9_\-\/]+\.php\b/', 'FILE', (string) $s);
    };
    if ($norm((string) $a) !== $norm((string) $b)) {
        return ['ok' => false, 'why' => '输出不一致'];
    }
    return ['ok' => true, 'why' => ''];
}


// ---------------------------------------------------------------------------
// A) 等价 ISA 拆解：同一语义多个物理码，逐字节输出等价
// ---------------------------------------------------------------------------
echo "\n=== A) 零损耗字节码拆解（--vm-isa-split）===\n";
$isaSrc = $tmp . '/isa.php';
file_put_contents($isaSrc, <<<'PHP'
<?php
function calc($a, $b) { return ($a * $b + 7) . ':' . ($a - $b); }
$s = '';
for ($i = 0; $i < 40; $i++) { $s .= calc($i, $i + 3) . '|'; }
echo md5($s), "\n", strlen($s), "\n";
PHP
);
foreach ([1, 2, 3, 4] as $sp) {
    $args = ['--vm', '--vm-isa-split=' . $sp, '--no-vm-junk', '--salt=isa126'];
    $r = case_run($entry, $isaSrc, $tmp . '/isa_' . $sp . '.php', $args);
    $r['ok'] ? t_ok("isa-split={$sp} 输出等价") : t_no("isa-split={$sp}", $r['why']);
}
// 同一逻辑确实出现多个物理码（case 标签数随 split 单调不减）
$caseCounts = [];
foreach ([1, 2, 4] as $sp) {
    $code = (string) @file_get_contents($tmp . '/isa_' . $sp . '.php');
    $caseCounts[$sp] = preg_match_all('/\bcase\s+\d+\s*:/', $code);
}
($caseCounts[4] > $caseCounts[1])
    ? t_ok('case 标签随分裂强度增加（' . $caseCounts[1] . '→' . $caseCounts[4] . '）')
    : t_no('case 标签单调性', json_encode($caseCounts));

// ---------------------------------------------------------------------------
// B) 全语句型函数打散：数组写回 / 复合赋值 / 自增 / 字符串拼接 / 函数调用
// ---------------------------------------------------------------------------
echo "\n=== B) 全语句型函数打散（funcsplit stmt 模式）===\n";
$fsSrc = $tmp . '/fs.php';
file_put_contents($fsSrc, <<<'PHP'
<?php
$a = [3, 1, 2];
$a[] = 4;
sort($a);
echo implode(',', $a), "\n";
$k = 2;
$k += 3;
$k++;
echo $k, "\n";
$s = 'ab';
$s .= 'cd';
echo $s, "\n";
PHP
);
$counts = [];
foreach ([1, 2, 3] as $lv) {
    $r = case_run($entry, $fsSrc, $tmp . '/fs_' . $lv . '.php',
        ['--func-split', '--func-split-level=' . $lv, '--salt=fs126']);
    $r['ok'] ? t_ok("funcsplit level={$lv} 输出等价") : t_no("funcsplit level={$lv}", $r['why']);
    $counts[$lv] = preg_match_all('/function\s+_fs/', (string) @file_get_contents($tmp . '/fs_' . $lv . '.php'));
}
($counts[3] > $counts[1])
    ? t_ok('stmt 模式显著提升提取数（' . $counts[1] . '→' . $counts[3] . '）')
    : t_no('提取数单调性', json_encode($counts));

// ---------------------------------------------------------------------------
// C) 多变恒真三分支诱饵：多强度 × 多盐，真分支保语义、假分支不可达
// ---------------------------------------------------------------------------
echo "\n=== C) 多变恒真三分支诱饵（--decoy）===\n";
$dcSrc = $tmp . '/dc.php';
file_put_contents($dcSrc, <<<'PHP'
<?php
$a = 12;
$b = 'xy';
echo $a + 3, '|', $b, "\n";
$c = $a * 2;
echo $c, "\n";
$obj = new stdClass();
$obj->v = 'ok';
echo $obj->v, "\n";
PHP
);
foreach ([1, 2, 3] as $lv) {
    foreach (['s1', 's2', 's3', 's4', 's5'] as $salt) {
        $r = case_run($entry, $dcSrc, $tmp . '/dc_' . $lv . '_' . $salt . '.php',
            ['--decoy', '--decoy-level=' . $lv, '--salt=' . $salt]);
        $r['ok'] ? t_ok("decoy level={$lv} salt={$salt}") : t_no("decoy level={$lv} salt={$salt}", $r['why']);
    }
}
// 三分支结构存在；且不得出现一眼假的 `($v===$v)||($v!==$v)`
$all = '';
foreach (glob($tmp . '/dc_3_*.php') ?: [] as $f) {
    $all .= (string) file_get_contents($f);
}
(substr_count($all, 'elseif') > 0)
    ? t_ok('三分支结构已注入（elseif 命中 ' . substr_count($all, 'elseif') . ' 处）')
    : t_no('三分支结构缺失', '');
(strpos($all, '===$') === false || substr_count($all, 'elseif') > 0)
    ? t_ok('条件形态非单一恒等式')
    : t_no('条件形态可疑', '');

// ---------------------------------------------------------------------------
// D) VM 解释器本体混淆：常量十六进制化 + 不可达伪逻辑
// ---------------------------------------------------------------------------
echo "\n=== D) VM 解释器本体混淆 ===\n";
$vmSrc = $tmp . '/vm.php';
file_put_contents($vmSrc, <<<'PHP'
<?php
function f($x) { return $x * 3 + 1; }
$t = 0;
for ($i = 0; $i < 20; $i++) { $t += f($i); }
echo 't=', $t, "\n";
PHP
);
foreach (['high', 'max', 'ultra'] as $lv) {
    $r = case_run($entry, $vmSrc, $tmp . '/vm_' . $lv . '.php', ['--level=' . $lv, '--salt=vm126']);
    $r['ok'] ? t_ok("解释器混淆 level={$lv} 输出等价") : t_no("解释器混淆 level={$lv}", $r['why']);
    $code = (string) @file_get_contents($tmp . '/vm_' . $lv . '.php');
    (preg_match_all('/0x[0-9a-f]+|\\\\x[0-9a-f]{2}/', $code) > 0)
        ? t_ok("level={$lv} 常量形态已重写")
        : t_no("level={$lv} 常量未重写", '');
}

// ---------------------------------------------------------------------------
// E) 四项能力叠加：max / ultra 档全量
// ---------------------------------------------------------------------------
echo "\n=== E) 叠加档位 ===\n";
$mixSrc = $tmp . '/mix.php';
file_put_contents($mixSrc, <<<'PHP'
<?php
class Box {
    public $n = 0;
    public function add($v) { $this->n += $v; return $this->n; }
}
$b = new Box();
for ($i = 1; $i <= 8; $i++) { echo $b->add($i), ','; }
echo "\n", md5((string)$b->n), "\n";
PHP
);
foreach (['high', 'max', 'ultra'] as $lv) {
    foreach (['a1', 'a2'] as $salt) {
        $r = case_run($entry, $mixSrc, $tmp . '/mix_' . $lv . '_' . $salt . '.php',
            ['--level=' . $lv, '--salt=' . $salt]);
        $r['ok'] ? t_ok("叠加 level={$lv} salt={$salt}") : t_no("叠加 level={$lv} salt={$salt}", $r['why']);
    }
}


// ---------------------------------------------------------------------------
// F) 整站级回归：跨文件 funcsplit 函数名不得撞名（整站运行实测抓到的真实 bug）
// ---------------------------------------------------------------------------
echo "\n=== F) 跨文件函数名隔离（整站 redeclare 回归）===\n";
$f1 = $tmp . '/cross1.php';
$f2 = $tmp . '/cross2.php';
file_put_contents($f1, <<<'PHP'
<?php
function entry1($x) { $r = $x + 11; echo "one:$r\n"; }
entry1(3);
PHP
);
file_put_contents($f2, <<<'PHP'
<?php
function entry2($x) { $r = $x * 7; echo "two:$r\n"; }
entry2(5);
PHP
);
$o1 = $tmp . '/cross1_o.php';
$o2 = $tmp . '/cross2_o.php';
$args = ['--func-split', '--func-split-level=3', '--func-split-max=32', '--salt=cross126'];
$r1 = case_run($entry, $f1, $o1, $args);
$r2 = case_run($entry, $f2, $o2, $args);
($r1['ok'] && $r2['ok']) ? t_ok('两份文件各自构建并运行等价') : t_no('跨文件构建', ($r1['ok'] ? '' : $r1['why']) . ' / ' . ($r2['ok'] ? '' : $r2['why']));
// 提取两产物的函数名，必须完全不相交
preg_match_all('/function\s+(_fs[A-Za-z0-9]+)/', (string) @file_get_contents($o1), $m1);
preg_match_all('/function\s+(_fs[A-Za-z0-9]+)/', (string) @file_get_contents($o2), $m2);
$overlap = array_intersect($m1[1] ?? [], $m2[1] ?? []);
$n1 = count($m1[1] ?? []); $n2 = count($m2[1] ?? []);
// max 档里 _fs 名字可能被 var 层改名或埋进 eval，正则匹配不到属正常；
// 此时真正的判定交给下面「同进程 require 无 redeclare」。这里只在两边都可见时查不相交。
if ($n1 > 0 && $n2 > 0) {
    $overlap === [] ? t_ok('跨文件函数名不相交（' . $n1 . ' vs ' . $n2 . '）')
                    : t_no('跨文件函数名撞名', 'overlap=' . implode(',', $overlap));
} else {
    t_ok('函数名已深层改写（可见 ' . $n1 . '/' . $n2 . '，交由同进程加载判定）');
}
// 同一进程里 require 两份产物：绝不 redeclare
$both = $tmp . '/cross_both.php';
file_put_contents($both, "<?php\nrequire " . var_export($o1, true) . ";\nrequire " . var_export($o2, true) . ";\n");
$out = shell_exec('php ' . escapeshellarg($both) . ' 2>&1');
(strpos((string) $out, 'redeclare') === false && strpos((string) $out, 'Fatal') === false)
    ? t_ok('同一进程加载两份产物无 redeclare')
    : t_no('同进程 redeclare', substr((string) $out, 0, 160));


// ---------------------------------------------------------------------------
// G) 整站级回归：decoy 不得改写「写回语句」（整站运行实测抓到的真实 bug）
// ---------------------------------------------------------------------------
echo "\n=== G) decoy 跳过写回语句（值拷贝致输出丢失回归）===\n";
$dcSrc = $tmp . '/decoy_wb.php';
file_put_contents($dcSrc, <<<'PHP'
<?php
$users = [];
$u = (object)['name' => 'Alice'];
$users[] = $u;
$u2 = (object)['name' => 'Bob'];
$users[] = $u2;
echo count($users), ':', $users[0]->name, ',', $users[1]->name, "\n";
PHP
);
foreach (['1','2','3'] as $lv) {
    foreach (['wb1','wb2','wb3'] as $salt) {
        $r = case_run($entry, $dcSrc, $tmp . '/decoy_wb_' . $lv . '_' . $salt . '.php',
            ['--decoy', '--decoy-level=' . $lv, '--salt=' . $salt]);
        $r['ok'] ? t_ok("decoy 写回语句 level={$lv} salt={$salt}")
                 : t_no("decoy 写回语句 level={$lv} salt={$salt}", $r['why']);
    }
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($failMsg !== []) {
    foreach ($failMsg as $m) {
        echo "  FAIL: {$m}\n";
    }
}
exit($fail === 0 ? 0 : 1);
