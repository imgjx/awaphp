<?php
declare(strict_types=1);
/**
 * VM 下标键方括号嵌套专项测试（parsePostfix / 插值串 [] 嵌套缺陷回归）
 *
 * 背景：parsePostfix 的 `[` 下标分支与插值串内的简单下标分支，收集键 token 时
 * 原先不跟踪 `[`/`]` 嵌套深度——键表达式内含 `$GLOBALS['x']`、`$a[$b['k']]`
 * 之类的内层下标时，会在内层 `]` 处提前截断，导致括号失衡抛
 * 「VM 编译期望 ) 但已到末尾」，整条语句被迫 EVAL_STMT 回退；回退段在真实 PHP
 * eval 中执行时若调用 VM 函数（func-call 层包装的 call_user_func），直接致命。
 *
 * 本测试锁定：此类语句必须【原生编译】（expectEval=false）且输出与原生逐字节一致。
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';

/** 原生执行 */
function runNative(string $code): string
{
    return (string) shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
}

/** VM 产物执行；opts 透传 buildArtifact（stateMachine/flatten 等） */
function runVm(string $code, array $opts = []): string
{
    $art = (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
        'baseDir' => sys_get_temp_dir(),
        'curFile' => sys_get_temp_dir() . '/p5_sub.php',
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vmsub') . '.php';
    file_put_contents($tmp, $art['php']);
    $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

/** 递归扫描程序段内是否含指定操作码（flatten/stateMachine 会嵌套段结构） */
function hasOp(array $node, int $op): bool
{
    foreach ($node as $ins) {
        if (is_array($ins)) {
            if (isset($ins[0]) && $ins[0] === $op && count($ins) >= 1 && is_int($ins[0])) {
                return true;
            }
            if (hasOp($ins, $op)) {
                return true;
            }
        }
    }
    return false;
}

$cases = [
    // name                       => [code, opts, expectEval]
    // —— 下标键内含 $GLOBALS['x']（内层方括号） ——
    'globals_key'        => ['$GLOBALS["k"] = "k"; $a = ["k"=>"v"]; echo $a[$GLOBALS["k"]] . "|";', [], false],
    'globals_key_var'    => ['$m = ["k"=>9]; $GLOBALS["i"] = "k"; echo $m[$GLOBALS["i"]] . "|";', [], false],
    // —— 下标键内含变量下标（$a[$b['k']]） ——
    'nested_var_sub'     => ['$b = ["k"=>1]; $a = [10, 20]; echo $a[$b["k"]] . "|";', [], false],
    'nested_deep_sub'    => ['$b = ["x"=>["y"=>2]]; $a = [10, 20, 30]; echo $a[$b["x"]["y"]] . "|";', [], false],
    // —— 下标键为带括号的复合表达式（内含 substr/strlen 调用） ——
    'paren_key_calls'    => ['$a = ["ab"=>"hit"]; echo $a[substr("xxab", 2)] . "|";', [], false],
    // —— 插值串内的简单下标（键含变量下标） ——
    'interp_nested_sub'  => ['$b = ["k"=>0]; $a = ["zero", "one"]; echo "v={$a[$b["k"]]}|";', [], false],
    // —— 写侧：下标键含 $GLOBALS ——
    'globals_key_write'  => ['$a = []; $GLOBALS["k"] = "kk"; $a[$GLOBALS["k"]] = 7; echo $a["kk"] . "|";', [], false],
    // —— 与字符串层/平坦化/状态机组合 ——
    'sm_globals_key'     => ['$GLOBALS["k"] = "k"; $a = ["k"=>"v"]; echo $a[$GLOBALS["k"]] . "|";', ['stateMachine' => true], false],
    'flatten_nested_sub' => ['$b = ["k"=>1]; $a = [10, 20]; echo $a[$b["k"]] . "|";', ['flatten' => true], false],
    // —— 回归：普通（无嵌套）下标仍正常原生编译 ——
    'plain_sub'          => ['$a = [5, 6]; echo $a[1] . "|";', [], false],
    'plain_str_key'      => ['$a = ["k"=>"v"]; echo $a["k"] . "|";', [], false],
];

$fail = 0;
foreach ($cases as $name => [$code, $opts, $expectEval]) {
    $native = runNative($code);
    try {
        $art = (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
            'baseDir' => sys_get_temp_dir(),
            'curFile' => sys_get_temp_dir() . '/p5_sub.php',
        ], $opts));
        $tmp = tempnam(sys_get_temp_dir(), 'vmsub') . '.php';
        file_put_contents($tmp, $art['php']);
        $vmOut = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        unlink($tmp);
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: VM 编译/运行异常 " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }

    // 验证 EVAL_STMT 是否出现在产物中（经 mapping 反查生成码；递归遍历各段）
    $prog = $art['program'];
    $evalCode = $art['mapping'][VmOp::EVAL_STMT] ?? null;
    $found = $evalCode !== null && hasOp($prog, $evalCode);

    $ok = ($vmOut === $native) && ($found === $expectEval);
    if ($ok) {
        echo "[ OK ] {$name} => " . trim($vmOut) . ($expectEval ? ' (EVAL_STMT 已确认)' : '') . "\n";
    } else {
        echo "[FAIL] {$name}\n  native: " . var_export($native, true) . "\n  vm    : " . var_export($vmOut, true)
            . "\n  expectEval=" . var_export($expectEval, true) . " found=" . var_export($found, true) . "\n";
        $fail++;
    }
}

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
