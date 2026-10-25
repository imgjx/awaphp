<?php
declare(strict_types=1);
/**
 * handler 裸名冲突加固测试（Task：EVAL_STMT 引用导出穿透）
 *
 * 背景：RuntimeGenerator 的 handler 曾使用裸名临时变量（$a/$b/$v/$k/...）。
 * EVAL_STMT 桥接把用户变量以【引用】导出到运行期函数作用域后，handler 的
 * 裸名赋值会沿引用链穿透覆写用户帧变量（BINOP 的 $b=array_pop(...) 把
 * 用户 $b 改成栈顶值）。修复：handler 临时量全部占位符化（___T?___ →
 * 随机中文名）。
 *
 * 本测试用「用户变量名 = 全部原裸名」的最坏组合验证：每个用例都含至少
 * 一条 EVAL_STMT 回退语句（list 解构），后接各类运算读回用户变量。
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';

function runNative(string $code): string
{
    return (string) shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
}

function runVm(string $code, array $opts = []): string
{
    $art = (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
        'baseDir' => sys_get_temp_dir(),
        'curFile' => sys_get_temp_dir() . '/p5_eval.php',
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vmguard') . '.php';
    file_put_contents($tmp, $art['php']);
    $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

// 全部原裸名（用户侧最坏组合）
$names = ['a', 'b', 'c', 'v', 'k', 'i', 'o', 'arr', 'old', 'rn', 'pairs', 'p', 'fn', 'm', 'obj', 'cls',
    'mo', 'sp', 'refs', 'r', 'mm', 'pr', 'co', 'cs', 'rp', 'ps', 'pn', 'nk', 'keys', 'found', 'ref', 'ok',
    'lk', 'argc', 'args', 'g', 'dn', 'sn', 'bn', 'ak', 'rm', 'code2', 'e', 'fl', 'cand', 'es', 'eobj', 'n'];

$cases = [];

// 1) 每个裸名单独：list 解构（回退）赋值 + 回显
foreach ($names as $nm) {
    $cases["single_$nm"] = ['[$' . $nm . ', $z] = [7, 8]; echo $' . $nm . ' . "|" . $z . "|";'];
}

// 2) 全部裸名同时（list 多目标）
$lhs = '[$' . implode(', $', $names) . ']';
$rhs = '[' . implode(', ', range(1, count($names))) . ']';
$cases['all_names'] = [$lhs . ' = ' . $rhs . '; echo $' . implode(' . $', $names) . ';'];

// 3) 回退语句后接各类运算（触发所有 handler）
$cases['binops_after_eval'] = [
    '[$a, $b] = [10, 3]; echo ($a + $b) . "," . ($a - $b) . "," . ($a * $b) . "," . ($a / $b) . "," . ($a % $b) . "," . ($a ** $b) . "," . ($a . $b) . "," . ($a & $b) . "," . ($a | $b) . "," . ($a ^ $b) . "," . ($a << $b) . "," . ($a >> $b) . "," . ($a == $b) . "," . ($a != $b) . "," . ($a < $b) . "," . ($a > $b) . "," . ($a <= $b) . "," . ($a >= $b) . "," . ($a === $b) . "," . ($a !== $b) . "," . ($a <=> $b) . "," . ($a && $b) . "," . ($a || $b) . "|";'
];
$cases['unops_casts'] = ['[$v, $k] = [-5, "3.7"]; echo (-$v) . "," . (~$v) . "," . (!$v) . "," . ((int)$k) . "," . ((float)$k) . "," . ((string)$v) . "," . ((bool)$v) . "," . ((array)$v) . "|";'];
$cases['idx_ops'] = ['[$arr, $k] = [[9, 8, 7], 1]; echo $arr[$k] . "," . count($arr) . "|"; $arr[] = 6; echo count($arr) . "," . $arr[3] . "|";'];
$cases['inc_after_eval'] = ['[$c, $old] = [5, 9]; echo ++$c . "," . $c++ . "," . $c . "," . --$c . "," . $c-- . "," . $c . "|"; echo ++$old . "|";'];
$cases['isset_unset_after_eval'] = ['[$nk, $keys] = [1, 2]; echo isset($nk) . "," . empty($keys) . "|"; unset($nk); echo isset($nk) . "|";'];
$cases['prop_ops'] = ['[$o, $obj] = [null, null]; $o = new stdClass(); $o->p = 3; echo $o->p . "|"; $o->p++; echo $o->p . "|";'];
$cases['nested_eval_multi'] = [
    '[$a, $b] = [1, 2]; [$v, $k] = [3, 4]; [$arr, $p] = [[5, 6], 0]; echo $a . $b . $v . $k . $arr[$p] . "|";'
];
$cases['eval_then_func'] = [
    '[$args, $argc] = [3, 4]; function ff($x, $y) { return $x * $y; } echo ff($args, $argc) . "|";'
];
$cases['eval_in_loop'] = [
    '$s = 0; for ($i = 0; $i < 3; $i++) { [$a, $b] = [$i, $i + 1]; $s += $a + $b; } echo $s . "|";'
];
$cases['eval_arith_mix'] = [
    '[$a, $b, $c] = [2, 3, 4]; echo $a + $b * $c - ($a << $b) + ($c % $b) . "|";'
];
// 4) flatten / stateMachine 层下同样验证
$cases['flatten_all_names'] = [$lhs . ' = ' . $rhs . '; echo $' . implode(' . $', $names) . ';', ['flatten' => true]];
$cases['sm_all_names'] = [$lhs . ' = ' . $rhs . '; echo $' . implode(' . $', $names) . ';', ['stateMachine' => true]];

$pass = 0; $fail = 0;
$strip = fn(string $s): string => (string) preg_replace('/PHP (?:Warning|Notice):.*\n/m', '', $s);
foreach ($cases as $name => $case) {
    $code = $case[0];
    $opts = $case[1] ?? [];
    $nat = $strip(runNative($code));
    $vm = $strip(runVm($code, $opts));
    if ($nat === $vm) {
        $pass++;
    } else {
        $fail++;
        echo "[FAIL] $name\n  native: " . var_export($nat, true) . "\n  vm    : " . var_export($vm, true) . "\n";
    }
}
echo "裸名冲突加固：PASS=$pass FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
