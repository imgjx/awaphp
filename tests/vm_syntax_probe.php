<?php
/**
 * VM 语法支持探测（子进程隔离版）：逐条对比原生执行 vs VM 编译执行。
 * 区分三态：
 *   PASS      —— 原生与 VM 输出一致，且编译产物零回退（真 VM 化）
 *   FALLBACK  —— 输出一致，但产物含 EVAL_STMT/INCLUDE_RT（语句/表达式级回退）
 *   FAIL      —— VM-ERR / MISMATCH / ORIG-ERR
 */
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

const RUNNER = __DIR__ . '/../src/Vm/runtime.php';
const COMPILER = __DIR__ . '/../src/Vm/Compiler.php';
const PARSER = __DIR__ . '/../src/Parser.php';
const SPLITTER = __DIR__ . '/../src/Support/StatementSplitter.php';
const OP = __DIR__ . '/../src/Vm/Op.php';

function runOrig(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'vmo') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return (string) $out;
}

/** 编译并统计回退指令（EVAL_STMT=113 / INCLUDE_RT=112）数量 */
function compileStats(string $codeFile): array {
    $probe = tempnam(sys_get_temp_dir(), 'vmp') . '.php';
    file_put_contents($probe, "<?php\n"
        . "require " . var_export(PARSER, true) . ";\n"
        . "require " . var_export(SPLITTER, true) . ";\n"
        . "require " . var_export(OP, true) . ";\n"
        . "require " . var_export(COMPILER, true) . ";\n"
        . "require " . var_export(RUNNER, true) . ";\n"
        . '$src = file_get_contents(' . var_export($codeFile, true) . ");\n"
        . '$p = (new VmCompiler())->compile($src);' . "\n"
        . '$n = 0; $tot = 0;' . "\n"
        . '$walk = function ($ins) use (&$walk, &$n, &$tot) { $tot++; if ((int)($ins[0] ?? 0) === 113 || (int)($ins[0] ?? 0) === 112) { $n++; } };' . "\n"
        . 'foreach (($p["main"] ?? []) as $i) { $walk($i); }' . "\n"
        . 'foreach (($p["funcs"] ?? []) as $f) { foreach (($f["code"] ?? $f) as $i) { $walk($i); } }' . "\n"
        . 'foreach (($p["classes"] ?? []) as $c) { foreach (($c["methods"] ?? []) as $m) { foreach (($m["code"] ?? $m) as $i) { $walk($i); } } }' . "\n"
        . 'echo $n . " " . $tot;' . "\n");
    $out = shell_exec('php ' . escapeshellarg($probe) . ' 2>&1');
    @unlink($probe);
    return array_map('intval', explode(' ', trim((string) $out)));
}
function runVm(string $code): string {
    $runner = tempnam(sys_get_temp_dir(), 'vmr') . '.php';
    $codeFile = tempnam(sys_get_temp_dir(), 'vmc') . '.php';
    file_put_contents($codeFile, $code);
    file_put_contents($runner, "<?php\n"
        . "require " . var_export(PARSER, true) . ";\n"
        . "require " . var_export(SPLITTER, true) . ";\n"
        . "require " . var_export(OP, true) . ";\n"
        . "require " . var_export(COMPILER, true) . ";\n"
        . "require " . var_export(RUNNER, true) . ";\n"
        . '$src = file_get_contents(' . var_export($codeFile, true) . ");\n"
        . '$c = new VmCompiler(); $c->setSourceContext(' . var_export($codeFile, true)
        . ', dirname(' . var_export($codeFile, true) . '));' . "\n"
        . '$p = $c->compile($src);' . "\n"
        . '__VMRUN__($p);' . "\n");
    $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    @unlink($codeFile);
    return (string) $out;
}

/**
 * 归一化执行输出后再比较：原生与 VM 的**值输出**应一致，但诊断信息天然不同——
 *   - 报错文件名：原生是临时脚本，VM 是 runtime.php（或 eval'd code）；
 *   - 行号：VM 的解释器行号与源码行号无关；
 *   - 调用栈：VM 多出解释器/桥接帧。
 * 若直接逐字节比较，`unary_plus` / `preg_match_fn` / 除零等几十条都会因"路径不同"
 * 假失败，掩盖真正的值差异。故这里只保留：①用户输出（非 PHP 诊断行）；
 * ②错误严重级别（NONFATAL / FATAL）。同级别 + 同用户输出即视为等价。
 */
function normOut(string $s): string {
    $body = [];
    $sev = '';
    foreach (preg_split('/\r?\n/', $s) as $ln) {
        if (preg_match('/PHP (Warning|Notice|Deprecated)/i', $ln)) {
            if ($sev === '') { $sev = 'NONFATAL'; }
            continue;
        }
        if (preg_match('/PHP (Fatal error|Parse error)|^PHP Stack trace:|^(Stack trace:|#\d+ )|^\s+thrown /i', $ln)) {
            $sev = 'FATAL';
            continue;
        }
        // 用户输出里可能夹带 __FILE__ 之类的临时路径，统一抹掉以消除环境噪声
        $ln = preg_replace('#/[^\s:()]+\.php#', '<FILE>', $ln);
        $ln = preg_replace('/\bvm[orcp][A-Za-z0-9]+\.php/', '<FILE>', $ln);
        $body[] = $ln;
    }
    return ($sev === 'FATAL' ? 'FATAL' : '') . '|' . implode("\n", $body);
}

$cases = json_decode(file_get_contents(__DIR__ . '/vm_probe_cases.json'), true);

$pass = 0; $fb = 0; $fail = 0;
$fallbackCases = []; $failCases = []; $failDetail = [];
foreach ($cases as [$name, $code]) {
    $codeFile = tempnam(sys_get_temp_dir(), 'vmp') . '.php';
    file_put_contents($codeFile, $code);
    try {
        $orig = runOrig($code);
        $vm = runVm($code);
        [$fbN] = compileStats($codeFile);
    } catch (Throwable $e) {
        echo "[$name] ORIG-ERR " . $e->getMessage() . "\n";
        @unlink($codeFile);
        $fail++; $failCases[] = $name;
        continue;
    }
    @unlink($codeFile);
    if (normOut($vm) !== normOut($orig)) {
        echo "[$name] MISMATCH\n  orig: " . json_encode($orig) . "\n  vm  : " . json_encode($vm) . "\n";
        $fail++; $failCases[] = $name; $failDetail[$name] = 'MISMATCH';
        continue;
    }
    if ($fbN > 0) {
        $fb++; $fallbackCases[] = $name;
    } else {
        $pass++;
    }
}
// 注意：必须用 {$var} 定界——中文全角字符（如"共"）首字节 >= 0x80，属于 PHP
// 变量名的合法字符集，"$fail（共" 会被整体解析成一个未定义变量名而输出为空，
// 导致统计数字被静默吞掉（曾使失败数显示为 0 / 显示为总数）。
echo "\n===== 探测结果：真编译 {$pass} / 回退 {$fb} / 失败 {$fail}（共 " . count($cases) . "）=====\n";
if ($failCases) {
    echo "\n-- 失败（" . count($failCases) . "）--\n";
    foreach ($failCases as $f) { echo "  - $f\n"; }
}
if ($fallbackCases) {
    echo "\n-- 回退（" . count($fallbackCases) . "）--\n";
    foreach ($fallbackCases as $f) { echo "  - $f\n"; }
}
