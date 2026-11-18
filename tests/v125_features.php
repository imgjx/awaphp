<?php
/**
 * v1.2.5 专项回归：指令集加速（superinstruction）+ 编译时优化（constfold）
 *                    + 函数型代码打散（funcsplit）+ min 档改造
 *
 * 与 tests/run_tests.php 同风格：每个用例独立构建、lint、执行、逐字节比对输出。
 * 用法：php tests/v125_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$fxDir = $root . '/tests/fixtures';
$tmp = sys_get_temp_dir() . '/v125_' . bin2hex(random_bytes(4));
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

echo "\n=== A) 指令集加速（--vm-super-isa）===\n";
$vmCases = [
    ['super=0（关闭）',       ['--vm', '--vm-super-isa=0', '--salt=va125']],
    ['super=3（默认）',       ['--vm', '--vm-super-isa=3', '--salt=va125']],
    ['super=8',               ['--vm', '--vm-super-isa=8', '--salt=va125']],
    ['super=8 + 无常量池',    ['--vm', '--vm-super-isa=8', '--no-vm-cpool', '--salt=va125']],
    ['super=8 + 平坦化',      ['--vm', '--vm-super-isa=8', '--vm-flatten', '--salt=va125']],
    ['super=8 + 状态机',      ['--vm', '--vm-super-isa=8', '--vm-state-machine', '--salt=va125']],
    ['super=16 + combo3',     ['--vm', '--vm-super-isa=16', '--vm-combo=3', '--salt=va125']],
];
foreach (['mixed_advanced.php', 'demo.php', 'token_rich.php'] as $fx) {
    foreach ($vmCases as [$label, $args]) {
        $name = "super {$fx} {$label}";
        $r = case_run($entry, $fxDir . '/' . $fx, $tmp . '/s_' . md5($name) . '.php', $args);
        $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
    }
}

echo "\n=== B) 编译时优化（constfold 层）===\n";
$cfSrc = $tmp . '/cf_src.php';
file_put_contents($cfSrc, <<<'PHP'
<?php
$a = 60 * 60 * 24;
$b = 1024 * 1024 + 512;
$c = 'hello' . '_' . 'world';
$d = (1 << 8) | 0x0F;
$e = 2 + 3 * 4;
$f = 2 ** 3 ** 2;
$g = -10 ** 2;
$h = 10 / 4;
$i = 7 % 3;
$j = 'a' . 1;
echo $a, '|', $b, '|', $c, '|', $d, '|', $e, '|', $f, '|', $g, '|', $h, '|', $i, '|', $j, "\n";
PHP
);
foreach ([1, 2, 3] as $lv) {
    $name = "constfold level={$lv}";
    $cfgJson = $tmp . "/cf_l{$lv}.json";
    file_put_contents($cfgJson, json_encode([
        'layers' => [['name' => 'constfold', 'params' => ['level' => $lv]]],
    ], JSON_UNESCAPED_UNICODE));
    $r = case_run($entry, $cfSrc, $tmp . "/cf_l{$lv}.php", ['--config=' . $cfgJson, '--salt=cf125']);
    $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
}

echo "\n=== C) 函数型代码打散（funcsplit 层）===\n";
foreach ([1, 2, 3] as $lv) {
    foreach (['strings.php', 'demo.php', 'complex_app.php', 'token_rich.php', 'classes.php'] as $fx) {
        $name = "funcsplit level={$lv} {$fx}";
        $cfgJson = $tmp . "/fs_l{$lv}.json";
        file_put_contents($cfgJson, json_encode([
            'layers' => [['name' => 'funcsplit', 'params' => ['level' => $lv]]],
        ], JSON_UNESCAPED_UNICODE));
        $r = case_run($entry, $fxDir . '/' . $fx, $tmp . '/f_' . md5($name) . '.php', ['--config=' . $cfgJson, '--salt=fs125']);
        $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
    }
}

echo "\n=== D) min 档改造（constfold + 高效多编码字符串）===\n";
foreach (['strings.php', 'demo.php', 'complex_app.php', 'mixed_advanced.php', 'token_rich.php'] as $fx) {
    $name = "min {$fx}";
    $r = case_run($entry, $fxDir . '/' . $fx, $tmp . '/m_' . md5($name) . '.php', ['--level=min', '--salt=min125']);
    $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
}

echo "\n=== E) 各档位端到端（含新层叠加）===\n";
foreach (['min', 'low', 'mid'] as $lv) {
    foreach (['complex_app.php', 'vm_full_syntax.php'] as $fx) {
        $name = "level={$lv} {$fx}";
        $r = case_run($entry, $fxDir . '/' . $fx, $tmp . '/lv_' . md5($name) . '.php', ['--level=' . $lv, '--salt=lv125']);
        $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
    }
}

echo "\n=== F) 新层开关关闭后仍等价 ===\n";
foreach ([
    ['--no-vm-super-isa', ['--vm', '--no-vm-super-isa', '--salt=off125']],
] as [$label, $args]) {
    $name = "off {$label}";
    $r = case_run($entry, $fxDir . '/mixed_advanced.php', $tmp . '/o_' . md5($name) . '.php', $args);
    $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
}

echo "\n=== G) break/continue 层数不被数字编码改坏（整站测试回归）===\n";
$bcSrc = $tmp . '/bc_src.php';
file_put_contents($bcSrc, <<<'PHP'
<?php
$out = [];
for ($i = 0; $i < 4; $i++) {
    for ($j = 0; $j < 4; $j++) {
        if ($j === 1) { continue 2; }
        if ($i === 3) { break 2; }
        $out[] = $i . ':' . $j;
    }
}
$k = 0;
while (true) {
    $k++;
    switch ($k) {
        case 1:
            continue 2;
        case 2:
            $out[] = 'two';
            break 2;
        default:
            $out[] = 'def';
            break;
    }
    $out[] = 'unreachable';
}
echo implode(',', $out), "\n";
PHP
);
foreach (['min', 'low', 'mid'] as $lv) {
    $name = "break/continue level={$lv}";
    $r = case_run($entry, $bcSrc, $tmp . '/bc_' . $lv . '.php', ['--level=' . $lv, '--salt=bc125']);
    $r['ok'] ? t_ok($name) : t_no($name, $r['why']);
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($fail > 0) {
    echo "\n失败详情：\n";
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
}
// 清理
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);
exit($fail === 0 ? 0 : 1);
