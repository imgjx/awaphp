<?php
declare(strict_types=1);
/**
 * VM 语句级回退（EVAL_STMT）专项测试（P5）
 *
 * 背景：compileStatement 对单条语句编译失败（VmUnsupported）时不再整文件回退，
 * 而是发射 PUSH_C(detokenize(stmt)) + EVAL_STMT，运行期 eval 执行并通过
 * 「帧变量表 ⇄ 真实局部变量」快照差分桥接回 VM 帧。
 *
 * 覆盖场景（原生执行 vs VM 产物执行，输出必须逐字节一致）：
 *   1. compound_arr     数组元素复合赋值（VM 不支持的左值形式）
 *   2. compound_prop    对象属性复合赋值
 *   3. compound_nested  多维数组嵌套复合赋值
 *   4. in_block         块内回退：if 块中回退语句前后的语句仍正常编译执行（语句级粒度证明）
 *   5. in_func          函数体内回退：回退语句读写的局部变量与函数帧互通
 *   6. list_dest        list()/[] 解构赋值
 *   7. heredoc          heredoc 插值（含已定义变量）
 *   8. mixed            回退语句与原生编译语句、函数调用混合
 *   9. writeback         回退语句写入的变量，后续 VM 编译语句可读（快照差分桥接）
 *  10. sm_eval          状态机混淆层下回退段语义不变
 *  11. flatten_eval     平坦化层下回退段语义不变
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
        'curFile' => sys_get_temp_dir() . '/p5_eval.php',
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vmeval') . '.php';
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

/** 告警/错误的位置信息（文件路径、行号）在原生与 VM 产物间必然不同，归一化后比较 */
function normalizeWarn(string $s): string
{
    return (string) preg_replace('/ in \S.*$/m', ' in <loc>', $s);
}

/** 剥离 PHP Warning/Notice 行（VM 辅助函数按既定策略静默，容告警差异用例用） */
function stripDiag(string $s): string
{
    return (string) preg_replace('/PHP (?:Warning|Notice):.*\n/m', '', $s);
}

$cases = [
    // name              => [code, opts, expectEval, noWarn]
    // Task #82 后：属性/下标复合赋值与下标自增已可直接编译（LVRead 双容器 +
    // INDEX_SET/PROPSET 写回），以下用例不再走 EVAL_STMT 回退；其中读未定义
    // 数组 key 的场景 VM 辅助函数按既定策略静默（不产 PHP Warning），
    // 以 noWarn 标记容忍该差异（native 告警行剥离后比较）。
    'compound_arr'    => ['$a = ["k"=>"v"]; $a["k"] .= "y"; echo $a["k"] . "|";', [], false, false],
    'compound_prop'   => ['$o = new stdClass(); $o->p = "a"; $o->p .= "b"; echo $o->p . "|";', [], false, false],
    'compound_nested' => ['$a = ["x"=>["y"=>"1"]]; $a["x"]["y"] .= "z"; echo $a["x"]["y"] . "|";', [], true, false],
    'in_block'        => ['$s = "0"; if (true) { $arr = []; $arr["x"] .= "q"; $s = $arr["x"]; echo "inner:" . $s . ";"; } echo "outer:" . $s . "|";', [], false, true],
    'in_func'         => ['function f($v) { $d = ["n"=>$v]; $d["n"] .= "!"; return $d["n"]; } echo f("ok") . "|";', [], false, false],
    'list_dest'       => ['[$a, $b] = [1, 2]; echo $a . "," . $b . "|";', [], true, false],
    'heredoc'         => ['$m = "M"; $s = <<<EOT
line $m
EOT;
echo $s . "|";', [], true, false],
    'mixed'           => ['echo strlen("ok") . "|"; $a = []; $a["k"] .= "v"; echo $a["k"] . "|"; echo strlen("ok2") . "|";', [], false, true],
    'writeback'       => ['$x = "a"; $y = []; $y["k"] .= "b"; $x = $y["k"]; echo $x . "|";', [], false, true],
    'sm_eval'         => ['$a = ["k"=>"v"]; $a["k"] .= "y"; echo $a["k"] . "|";', ['stateMachine' => true], false, false],
    'flatten_eval'    => ['$a = ["k"=>"v"]; $a["k"] .= "y"; echo $a["k"] . "|";', ['flatten' => true], false, false],
];

$fail = 0;
foreach ($cases as $name => [$code, $opts, $expectEval, $noWarn]) {
    $native = runNative($code);
    try {
        $art = (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
            'baseDir' => sys_get_temp_dir(),
            'curFile' => sys_get_temp_dir() . '/p5_eval.php',
        ], $opts));
        $tmp = tempnam(sys_get_temp_dir(), 'vmeval') . '.php';
        file_put_contents($tmp, $art['php']);
        $vmOut = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        unlink($tmp);
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: VM 编译/运行异常 " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }

    // 验证 EVAL_STMT 确实出现在产物中（经 mapping 反查生成码；递归遍历各段）
    $prog = $art['program'];
    $evalCode = $art['mapping'][VmOp::EVAL_STMT] ?? null;
    $found = $evalCode !== null && hasOp($prog, $evalCode);

    $nativeCmp = $noWarn ? stripDiag(normalizeWarn($native)) : normalizeWarn($native);
    $vmCmp = $noWarn ? stripDiag(normalizeWarn($vmOut)) : normalizeWarn($vmOut);
    $ok = ($vmCmp === $nativeCmp) && ($found === $expectEval);
    if ($ok) {
        echo "[ OK ] {$name} => " . trim(normalizeWarn($vmOut)) . ($expectEval ? ' (EVAL_STMT 已确认)' : '') . "\n";
    } else {
        echo "[FAIL] {$name}\n  native: " . var_export($native, true) . "\n  vm    : " . var_export($vmOut, true)
            . "\n  expectEval=" . var_export($expectEval, true) . " found=" . var_export($found, true) . "\n";
        $fail++;
    }
}

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
