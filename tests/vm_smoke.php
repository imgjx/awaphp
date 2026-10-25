<?php
/**
 * VM 冒烟测试：把源码编译为自定义字节码，再用 runtime 解释器执行，
 * 校验输出与原始 PHP 执行结果一致。
 */
declare(strict_types=1);

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

function stripPhpTag(string $code): string
{
    $code = preg_replace('/^\s*<\?php/', '', $code);
    $code = preg_replace('/\?>\s*$/', '', $code);
    return $code;
}

/**
 * 原生执行：独立子进程（函数内 require 会破坏 global 语义——
 * 被 require 文件的顶层变量落在函数作用域，与 VM 的顶层执行不可比）。
 */
function runOriginal(string $file): string
{
    return (string) shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
}

$fixtures = [
    'demo'  => __DIR__ . '/fixtures/demo.php',
    'sample'=> __DIR__ . '/fixtures/sample.php',
    'classes'=> __DIR__ . '/fixtures/classes.php',
    'strings'=> __DIR__ . '/fixtures/strings.php',
];

$ok = 0; $fail = 0;
foreach ($fixtures as $name => $file) {
    $src = file_get_contents($file);
    $orig = runOriginal($file);
    try {
        $prog = (new VmCompiler())->compile(stripPhpTag($src));
    } catch (VmUnsupported $e) {
        echo sprintf("[%-8s] SKIP (不支持) %s\n", $name, $e->getMessage());
        continue;
    } catch (Throwable $e) {
        echo sprintf("[%-8s] FAIL compile: %s\n", $name, $e->getMessage());
        $fail++;
        continue;
    }
    ob_start();
    try {
        $ret = __VMRUN__($prog);
    } catch (Throwable $e) {
        ob_end_clean();
        echo sprintf("[%-8s] FAIL run: %s\n", $name, $e->getMessage());
        $fail++;
        continue;
    }
    $got = (string) ob_get_clean();
    if ($got === $orig) {
        echo sprintf("[%-8s] PASS (len=%d)\n", $name, strlen($got));
        $ok++;
    } else {
        echo sprintf("[%-8s] MISMATCH\n  orig: %s\n  vm  : %s\n", $name, json_encode($orig), json_encode($got));
        $fail++;
    }
}
echo "\nRESULT: ok=$ok fail=$fail\n";
