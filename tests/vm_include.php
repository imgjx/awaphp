<?php
declare(strict_types=1);
/**
 * VM include/require 专项测试（P4）
 *
 * 覆盖场景（原生执行 vs VM 产物执行，输出必须一致）：
 *   1. embed_literal   构建期内嵌：字面路径 include、函数/新变量跨文件可见
 *   2. embed_return    include 表达式的值 = 被包含文件 return 值
 *   3. once_dedup      include_once / require_once / require / include 混合去重
 *   4. nested_scope    多级嵌套 + 跨级作用域（includer 变量可见、新变量写回）
 *   5. func_scope      函数内 include（共享函数局部作用域）
 *   6. cycle           循环包含（回指主文件，once 语义去重）
 *   7. dyn_var         运行期动态路径（$var 拼接，INCLUDE_RT）
 *   8. dyn_abs         运行期路径来自变量但值为绝对路径（__DIR__ 折值）
 *   9. suppress_miss   @include 抑制缺失文件告警、返回 false
 *   10. sm_embed       状态机混淆层下内嵌 include 仍正确
 *   11. dyn_cwd        动态 include 的 srcBase 回退（产物 cwd 非源目录）
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';

$fx = __DIR__ . '/fixtures/vminc';

/** 原生执行：在 fixture 目录下运行（保证相对路径按源码目录解析） */
function runNative(string $mainFile): string
{
    $dir = dirname($mainFile);
    return (string) shell_exec('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg(basename($mainFile)) . ' 2>&1');
}

/** VM 产物执行：baseDir/curFile 传真实路径；cwd 可指定（验证 srcBase 回退） */
function runVm(string $mainFile, array $opts = [], string $cwd = ''): string
{
    $src = file_get_contents($mainFile);
    // 与 Obfuscator::shellVm 同一约定：compile() 自行注入 <?php，须先剥离开标签
    $src = preg_replace('/^\s*<\?php/i', '', $src, 1);
    $src = preg_replace('/\?>\s*$/', '', $src);
    $art = (new VmRuntimeGenerator())->buildArtifact($src, array_merge([
        'baseDir' => dirname($mainFile),
        'curFile' => $mainFile,
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vminc') . '.php';
    file_put_contents($tmp, $art['php']);
    $cwd = $cwd !== '' ? $cwd : dirname($mainFile);
    $out = (string) shell_exec('cd ' . escapeshellarg($cwd) . ' && php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

$cases = [
    // name              => [mainFile, vmOpts, cwd]
    'embed_literal'  => ['main_embed.php', [], ''],
    'embed_return'   => ['main_ret.php', [], ''],
    'once_dedup'     => ['main_once.php', [], ''],
    'nested_scope'   => ['main_nested.php', [], ''],
    'func_scope'     => ['main_func.php', [], ''],
    'cycle'          => ['main_cycle.php', [], ''],
    'dyn_var'        => ['main_dyn.php', [], ''],
    'dyn_abs'        => ['main_dyn2.php', [], ''],
    'suppress_miss'  => ['main_miss.php', [], ''],
    // 状态机混淆层：嵌入段一并状态机化，验证混淆后 include 语义不变
    'sm_embed'       => ['main_embed.php', ['stateMachine' => true], ''],
    'sm_nested'      => ['main_nested.php', ['stateMachine' => true], ''],
    // 平坦化层
    'flatten_embed'  => ['main_embed.php', ['flatten' => true], ''],
    // 运行期动态 include 的 srcBase 回退：产物在别的 cwd 下运行（原生解析失败 → 回退源目录）
    'dyn_cwd'        => ['main_dyn.php', [], sys_get_temp_dir()],
    'dyn_abs_cwd'    => ['main_dyn2.php', [], sys_get_temp_dir()],
];

$fail = 0;
foreach ($cases as $name => [$main, $opts, $cwd]) {
    $mainFile = $fx . '/' . $main;
    $native = runNative($mainFile);
    try {
        $vmOut = runVm($mainFile, $opts, $cwd);
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: VM 编译/运行异常 " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }
    if ($vmOut === $native) {
        echo "[ OK ] {$name} => " . trim($vmOut) . "\n";
    } else {
        echo "[FAIL] {$name}\n  native: " . var_export($native, true) . "\n  vm    : " . var_export($vmOut, true) . "\n";
        $fail++;
    }
}

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
