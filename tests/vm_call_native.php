<?php
declare(strict_types=1);
/**
 * VM 原生函数间接调用（CALL_NATIVE）专项测试（P5）
 *
 * 背景：函数调用按「用户函数 → CALL（VM 表）」与「其余 → CALL_NATIVE（natTbl 间接）」
 * 分流；编译期把原生函数名登记进 natTbl，字节码只携下标，运行期查表后
 * call_user_func_array，高阶函数（array_map/usort 等）的 VM 回调保留包装。
 * embeds 合并时子 natTbl 按「子表 idx→name→主表新 idx」重映射。
 *
 * 覆盖场景：
 *   1. basic        基础原生调用（strlen/strtoupper/max）
 *   2. hof          高阶函数 + 用户函数回调（array_map）
 *   3. hof_closure  高阶函数 + 闭包回调（usort）
 *   4. fwdref       前向引用用户函数（须走 CALL 而非 CALL_NATIVE）
 *   5. var_fn       可变函数调用（$fn = "strlen"）
 *   6. nested       原生嵌套调用（trim(preg_replace(...))）
 *   7. in_embed     include 嵌入段内原生调用（natTbl 合并重映射路径）
 *   8. sm_native    状态机混淆层下 CALL_NATIVE 语义不变
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

/** 原生执行（内联代码） */
function runNative(string $code): string
{
    return (string) shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
}

/** VM 产物执行（内联代码） */
function runVmCode(string $code, array $opts = []): string
{
    $art = (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
        'baseDir' => sys_get_temp_dir(),
        'curFile' => sys_get_temp_dir() . '/p5_native.php',
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vmnat') . '.php';
    file_put_contents($tmp, $art['php']);
    $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

/** VM 产物执行（主文件，含 include 嵌入） */
function runVmFile(string $mainFile, array $opts = []): string
{
    $src = file_get_contents($mainFile);
    // 与 Obfuscator::shellVm 同一约定：compile() 自行注入 <?php，须先剥离开标签
    $src = preg_replace('/^\s*<\?php/i', '', $src, 1);
    $src = preg_replace('/\?>\s*$/', '', $src);
    $art = (new VmRuntimeGenerator())->buildArtifact($src, array_merge([
        'baseDir' => dirname($mainFile),
        'curFile' => $mainFile,
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vmnat') . '.php';
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
            if (isset($ins[0]) && $ins[0] === $op && is_int($ins[0])) {
                return true;
            }
            if (hasOp($ins, $op)) {
                return true;
            }
        }
    }
    return false;
}

/** 收集 natTbl 名字（program 结构为 name => idx） */
function natNames(array $prog): array
{
    return array_keys($prog['natTbl'] ?? []);
}

$cases = [
    // name          => [kind, payload, opts, expectCN, expectNat]
    'basic'        => ['code', 'echo strlen("hello") . "|" . strtoupper("ab") . "|" . max(3, 9) . "|";', [], true, ['strlen', 'strtoupper', 'max']],
    'hof'          => ['code', 'function dbl($x){ return $x * 2; } echo implode(",", array_map("dbl", [1,2,3])) . "|";', [], true, ['implode', 'array_map']],
    'hof_closure'  => ['code', 'function cmp($a, $b){ return $a - $b; } $arr = [3, 1, 2]; usort($arr, "cmp"); echo implode(",", $arr) . "|";', [], true, ['usort', 'implode']],
    'fwdref'       => ['code', 'echo fwd(5) . "|"; function fwd($n){ return $n + 1; }', [], false, []],
    'var_fn'       => ['code', '$fn = "strlen"; echo $fn("abcd") . "|";', [], false, []],
    'nested'       => ['code', 'echo trim(preg_replace("/\d+/", "#", "a12b3")) . "|";', [], true, ['trim', 'preg_replace']],
    'in_embed'     => ['file', $fx . '/main_nat.php', [], true, null],
    'sm_native'    => ['code', 'echo strlen("hello") . "|" . strtoupper("ab") . "|";', ['stateMachine' => true], true, ['strlen', 'strtoupper']],
    // ---- by-ref 原生函数（PUSH_REF 引用句柄 + refMask 帧变量绑定）----
    'ref_sort'     => ['code', '$a = [3, 1, 2]; sort($a); echo implode(",", $a) . "|";', [], true, ['sort', 'implode']],
    'ref_rsort'    => ['code', '$a = [1, 3, 2]; rsort($a); echo implode(",", $a) . "|";', [], true, ['rsort', 'implode']],
    'ref_usort'    => ['code', 'function cmp($x, $y){ return $x <=> $y; } $a = [3, 1, 2]; usort($a, "cmp"); echo implode(",", $a) . "|";', [], true, ['usort', 'implode']],
    'ref_push'     => ['code', '$s = [1]; array_push($s, 2, 3); echo count($s) . "|" . implode(",", $s) . "|";', [], true, ['array_push', 'count', 'implode']],
    'ref_pop'      => ['code', '$s = [1, 2, 3]; $v = array_pop($s); echo $v . "|" . implode(",", $s) . "|";', [], true, ['array_pop', 'implode']],
    'ref_shift'    => ['code', '$s = [1, 2, 3]; $v = array_shift($s); echo $v . "|" . implode(",", $s) . "|";', [], true, ['array_shift', 'implode']],
    'ref_preg'     => ['code', '$n = preg_match("/(\d+)-(\d+)/", "ab 12-34 cd", $m); echo $n . "|" . $m[1] . "," . $m[2] . "|";', [], true, ['preg_match']],
    'ref_count'    => ['code', '$out = str_replace("a", "b", "aaa", $cnt); echo $out . "|" . $cnt . "|";', [], true, ['str_replace']],
    'ref_in_func'  => ['code', 'function sorter($tag) { $a = [5, 2, 9]; sort($a); return $tag . implode(",", $a); } echo sorter("<") . ">|";', [], true, ['sort', 'implode']],
    // 复杂左值（属性/下标）的 by-ref 调用：编译期回退 EVAL_STMT（natTbl 不含 sort），语义须与原生一致
    'ref_complex'  => ['code', '$o = new stdClass(); $o->items = [2, 1]; sort($o->items); echo implode(",", $o->items) . "|";', [], true, ['implode']],
    'ref_sm'       => ['code', '$a = [3, 1, 2]; sort($a); echo implode(",", $a) . "|";', ['stateMachine' => true], true, ['sort', 'implode']],
    'ref_flatten'  => ['code', '$a = [3, 1, 2]; sort($a); echo implode(",", $a) . "|";', ['flatten' => true], true, ['sort', 'implode']],
];

$fail = 0;
foreach ($cases as $name => [$kind, $payload, $opts, $expectCN, $expectNat]) {
    if ($kind === 'code') {
        $native = runNative($payload);
        $art = (new VmRuntimeGenerator())->buildArtifact($payload, array_merge([
            'baseDir' => sys_get_temp_dir(),
            'curFile' => sys_get_temp_dir() . '/p5_native.php',
        ], $opts));
    } else {
        $mainFile = $payload;
        $dir = dirname($mainFile);
        $native = (string) shell_exec('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg(basename($mainFile)) . ' 2>&1');
        $src = file_get_contents($mainFile);
        $src = preg_replace('/^\s*<\?php/i', '', $src, 1);
        $src = preg_replace('/\?>\s*$/', '', $src);
        $art = (new VmRuntimeGenerator())->buildArtifact($src, array_merge([
            'baseDir' => $dir,
            'curFile' => $mainFile,
        ], $opts));
    }

    try {
        $tmp = tempnam(sys_get_temp_dir(), 'vmnat') . '.php';
        file_put_contents($tmp, $art['php']);
        $vmOut = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        unlink($tmp);
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: VM 运行异常 " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }

    $prog = $art['program'];
    $cnCode = $art['mapping'][VmOp::CALL_NATIVE] ?? null;
    $foundCN = $cnCode !== null && hasOp($prog, $cnCode);
    $names = natNames($prog);
    $natOk = $expectNat === null || (empty(array_diff($expectNat, $names)) && empty(array_diff($names, $expectNat)));

    if ($vmOut === $native && $foundCN === $expectCN && $natOk) {
        echo "[ OK ] {$name} => " . trim($vmOut) . " (CN=" . ($foundCN ? 'yes' : 'no') . ", natTbl=" . implode(',', $names) . ")\n";
    } else {
        echo "[FAIL] {$name}\n  native: " . var_export($native, true) . "\n  vm    : " . var_export($vmOut, true)
            . "\n  expectCN=" . var_export($expectCN, true) . " foundCN=" . var_export($foundCN, true)
            . "\n  expectNat=" . json_encode($expectNat) . " natTbl=" . json_encode($names) . "\n";
        $fail++;
    }
}

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
