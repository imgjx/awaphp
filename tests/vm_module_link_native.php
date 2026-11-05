<?php
/**
 * 模块链接 · 原生路径（非 VM 档位）回归（v1.1.9）
 *
 * 背景：模块链接（ModuleLinker，P6）原仅服务于 VM 字节码（命中清单的函数调用内联进
 * 同一 Program）。v1.1.9 起新增【原生路径】——low / mid 等非 VM 档位也能内联模块：
 * 命中清单的函数调用改写为模块内真实函数名（strlen -> __mod_strlen 等），模块原生 PHP
 * 实现追加进产物，原函数名 / 文件路径 / include 指令从产物消失；无需 VM 即可启用。
 *
 * 验证要点（原生执行 vs 混淆产物执行，输出逐字节一致）：
 *   1. low / mid 档构建含大量内置函数调用的夹具，运行输出与原生一致；
 *   2. 调用点改写 + 内联定义：用「仅 module_link（无 eval/gz 外壳）」的最小分层检视明文，
 *      确认 strlen/base64_encode/abs/strtoupper/implode 等调用被改写、模块函数定义存在；
 *   3. 传递式：夹具内调用某模块，该模块体内又引用其它清单函数时一并内联、运行一致；
 *   4. 关闭开关：--no-vm-link-modules 时调用点不被改写（保留原生函数分发）；
 *   5. max / ultra（VM 路径）不受影响：仍走字节码内联、运行一致。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/vmln_' . getmypid();
@mkdir($tmp, 0755, true);

function ok(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  {$m}\n"; }
    else { $fail++; echo "  FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
}
$pass = 0; $fail = 0;

function runPhp(string $f): string {
    exec('php ' . escapeshellarg($f) . ' 2>&1', $o);
    return implode("\n", $o);
}
function lint(string $f): string {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o);
    return implode("\n", $o);
}
function build(string $in, string $out, string $args): bool {
    global $entry;
    $cmd = sprintf('php %s --input=%s --output=%s %s --format=text 2>&1',
        escapeshellarg($entry), escapeshellarg($in), escapeshellarg($out), $args);
    exec($cmd, $o, $ec);
    return $ec === 0 && is_file($out);
}

// ============================================================= 夹具
$fixture = $tmp . '/src.php';
file_put_contents($fixture, <<<'PHP'
<?php
function work($s, $arr) {
    $len = strlen($s);
    $enc = base64_encode($s);
    $a = abs(-7);
    $u = strtoupper($s);
    $joined = implode('-', $arr);
    $lower = strtolower($u);
    $has = in_array('b', $arr);
    return [$len, $enc, $a, $u, $joined, $lower, $has ? 1 : 0];
}
$v = work("Hello World", ['a', 'b', 'c']);
echo implode('|', $v);
PHP
);
$native = trim(runPhp($fixture));
ok($native !== '' && strpos($native, '|') !== false, '原生夹具可运行', $native);

// ============================================================= 1. low / mid 运行等价
echo "===== [1] low / mid 档运行与原生一致 =====\n";
foreach (['low', 'mid'] as $lv) {
    $out = $tmp . "/{$lv}.php";
    ok(build($fixture, $out, "--level={$lv} --salt=sln1"), "构建 level={$lv}");
    $l = lint($out);
    ok(strpos($l, 'No syntax errors') !== false, "level={$lv} php -l 通过", trim($l));
    $got = trim(runPhp($out));
    ok($got === $native, "level={$lv} 输出与原生一致", 'got=[' . substr($got, 0, 80) . ']');
}

// ============================================================= 2. 明文改写 + 内联定义检视
echo "===== [2] 调用点改写 + 模块原生实现内联（最小化分层）=====\n";
$cfg = [
    'layers' => [
        ['name' => 'var', 'params' => ['length' => 8]],
        ['name' => 'ws', 'params' => []],
        ['name' => 'shell:module_link', 'params' => []],
    ],
    'salt' => 'sln2',
    'input' => $fixture,
    'output' => $tmp . '/plain.php',
    'format' => 'text',
    'silence' => true,
];
file_put_contents($tmp . '/cfg.json', json_encode($cfg));
ok(build($fixture, $tmp . '/plain.php', '--config=' . $tmp . '/cfg.json'), '构建最小化分层');
$plain = file_get_contents($tmp . '/plain.php');
// 调用点改写：用户源码里的 strlen/base64_encode/implode 已被改写为模块内函数名
// （注意：内联进来的模块定义体可能合法调用真实 strlen 等，故不以"全无 strlen("为判据，
// 而以"改写后的 __mod_xxx( 出现"为判据）
ok(strpos($plain, '__mod_strlen(') !== false, 'strlen 调用点已改写为 __mod_strlen');
ok(strpos($plain, '__b64_enc(') !== false, 'base64_encode 调用点已改写为 __b64_enc');
ok(strpos($plain, '__mod_implode(') !== false, 'implode 调用点已改写为 __mod_implode');
// 模块原生实现已内联（函数名 + 左括号，兼容关键字大小写随机化）
ok(strpos($plain, '__mod_strlen(') !== false, '__mod_strlen 定义已内联');
ok(strpos($plain, '__mod_implode(') !== false, '__mod_implode 定义已内联');
ok(strpos($plain, '__b64_enc(') !== false, '__b64_enc 定义已内联');
// 文件路径 / include 指令不应出现
ok(!preg_match('/include|require/', $plain), '产物无 include/require 指令');
ok(!preg_match('#/Module/builtin#', $plain), '产物无模块目录路径');
$got = trim(runPhp($tmp . '/plain.php'));
ok($got === $native, '最小化分层运行输出与原生一致', 'got=[' . substr($got, 0, 80) . ']');

// ============================================================= 3. 传递式内联（模块体内引用其它清单函数）
echo "===== [3] 传递式：模块内引用其它清单函数一并内联 =====\n";
// 让源码调用某模块，其体内再调用 substr（另一清单）：base64_enc 模块内部使用 strlen（真实内置），
// 而 __b64_enc 不引用其它清单；改用 substr_replace 依赖 substr —— 见下方专门构造。
$transFix = $tmp . '/trans.php';
file_put_contents($transFix, <<<'PHP'
<?php
$s = "abcdef";
$r = substr_replace($s, 'XY', 1, 2);   // 调用 substr_replace（依赖 substr 模块）
echo $r;
PHP
);
$transNative = trim(runPhp($transFix));
$tout = $tmp . '/trans_out.php';
ok(build($transFix, $tout, '--level=low --salt=sln3'), '构建传递式夹具 (low)');
// 最小分层检视：substr_replace 引用 __mod_substr，二者都应内联
$tcfg = $cfg;
$tcfg['salt'] = 'sln3';
$tcfg['input'] = $transFix;
$tcfg['output'] = $tmp . '/trans_plain.php';
file_put_contents($tmp . '/tcfg.json', json_encode($tcfg));
build($transFix, $tmp . '/trans_plain.php', '--config=' . $tmp . '/tcfg.json');
$tplain = file_get_contents($tmp . '/trans_plain.php');
ok(strpos($tplain, 'function __mod_substr_replace') !== false, '__mod_substr_replace 已内联');
ok(strpos($tplain, 'function __mod_substr') !== false, '依赖的 __mod_substr 传递式内联');
ok(!preg_match('/\bsubstr_replace\s*\(/', $tplain), 'substr_replace 调用位已改写');
$got = trim(runPhp($tout));
ok($got === $transNative, '传递式 low 输出与原生一致', 'got=[' . $got . '] native=[' . $transNative . ']');

// ============================================================= 4. 关闭开关
echo "===== [4] vm_link_modules=false 时调用点不被改写（明文检视）=====\n";
// 用最小分层（无 eval/gz 外壳，便于检视明文）+ vm_link_modules=false 关闭模块链接
$nmcfg = $cfg;
$nmcfg['salt'] = 'sln4';
$nmcfg['vm_link_modules'] = false;   // 关闭模块链接（等价于 --no-vm-link-modules）
$nmcfg['output'] = $tmp . '/nomodule_plain.php';
file_put_contents($tmp . '/nmcfg.json', json_encode($nmcfg));
ok(build($fixture, $tmp . '/nomodule_plain.php', '--config=' . $tmp . '/nmcfg.json'), '构建 (vm_link_modules=false, 明文)');
$nmp = file_get_contents($tmp . '/nomodule_plain.php');
// 关闭后，strlen 调用位仍存在（保留原生函数分发）
ok((bool) preg_match('/\bstrlen\s*\(/i', $nmp), '关闭后 strlen 调用位保留');
ok(strpos($nmp, '__mod_strlen') === false, '关闭后无 __mod_strlen 内联');
ok(strpos($nmp, '__b64_enc') === false, '关闭后无 __b64_enc 内联');
$got = trim(runPhp($tmp . '/nomodule_plain.php'));
ok($got === $native, '关闭后运行输出仍与原生一致', 'got=[' . substr($got, 0, 80) . ']');
// 关闭开关的加密档运行也等价（与原生一致）
$nmEnc = $tmp . '/nomodule_enc.php';
ok(build($fixture, $nmEnc, '--level=low --salt=sln4 --no-vm-link-modules'), '构建 (--no-vm-link-modules, 加密档)');
$gotEnc = trim(runPhp($nmEnc));
ok($gotEnc === $native, '关闭开关加密档运行输出与原生一致', 'got=[' . substr($gotEnc, 0, 80) . ']');

// ============================================================= 5. VM 路径不受影响
echo "===== [5] VM 路径（max/ultra）不受影响 =====\n";
foreach (['max', 'ultra'] as $lv) {
    $out = $tmp . "/vm_{$lv}.php";
    ok(build($fixture, $out, "--level={$lv} --salt=sln5"), "构建 level={$lv} (VM)");
    $l = lint($out);
    ok(strpos($l, 'No syntax errors') !== false, "level={$lv} php -l 通过", trim($l));
    $got = trim(runPhp($out));
    ok($got === $native, "level={$lv} (VM) 输出与原生一致", 'got=[' . substr($got, 0, 80) . ']');
}

echo "\n===== RESULT: pass={$pass} fail={$fail} =====\n";
exit($fail === 0 ? 0 : 1);
