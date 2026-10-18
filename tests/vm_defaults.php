<?php
declare(strict_types=1);
/**
 * VM 默认参数值（runtime padding）专项测试
 *  A 组「合法 PHP」：原生执行 vs VM 执行，输出必须一致。
 *  B 组「VM 扩展」：原生 PHP 拒绝（函数调用/引用参数等非法默认值），
 *     VM 更宽容——只验证 VM 输出符合语义预期（源码进不了混淆器也无妨，宽进不出错）。
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';

$runtime = preg_replace('/^\s*<\?php/', '', file_get_contents(__DIR__ . '/../src/Vm/runtime.php'), 1);

// A 组：合法 PHP（可与原生对比）
$validCases = [
    'func_simple' => [
        'code' => '
            function greet($who = "guest") { return "hello " . $who; }
            echo greet() . "|" . greet("bob") . "|";
        ',
        'expect' => null,
    ],
    'func_multi' => [
        'code' => '
            function add3($a, $b = 2, $c = 3) { return $a + $b + $c; }
            echo add3(1) . "|" . add3(1, 5) . "|" . add3(1, 5, 10) . "|";
        ',
        'expect' => null,
    ],
    'func_expr_default' => [
        'code' => '
            function tag($t = "x" . "y", $n = 3 * 4) { return $t . $n; }
            echo tag() . "|" . tag("z", 9) . "|";
        ',
        'expect' => null,
    ],
    'func_const_default' => [
        'code' => '
            const APP_NAME = "MyApp";
            function label($n = APP_NAME) { return "[" . $n . "]"; }
            echo label() . "|" . label("Q") . "|";
        ',
        'expect' => null,
    ],
    'func_const_expr_default' => [
        'code' => '
            const BASE = 5;
            function f($a = BASE + 1, $b = BASE * 2) { return $a + $b; }
            echo f() . "|" . f(1) . "|" . f(1, 1) . "|";
        ',
        'expect' => null,
    ],
    'func_null_default' => [
        'code' => '
            function f($x = null) { return $x === null ? "NULL" : "VAL"; }
            echo f() . "|" . f(0) . "|";
        ',
        'expect' => null,
    ],
    'func_array_default' => [
        'code' => '
            function sum($a = [1, 2, 3]) { $s = 0; foreach ($a as $v) { $s += $v; } return $s; }
            echo sum() . "|" . sum([10, 20]) . "|";
        ',
        'expect' => null,
    ],
    'func_nested_call' => [
        'code' => '
            function inner($x = "i") { return "in:" . $x; }
            function outer($y = "o") { return inner() . "/" . $y; }
            echo outer() . "|" . outer("z") . "|";
        ',
        'expect' => null,
    ],
    'method_default' => [
        'code' => '
            class Greeter {
                private $prefix;
                function __construct($p = "Hi") { $this->prefix = $p; }
                function hello($who = "world", $punct = "!") { return $this->prefix . " " . $who . $punct; }
            }
            $g = new Greeter();
            echo $g->hello() . "|";
            $g2 = new Greeter("Yo");
            echo $g2->hello("php") . "|" . $g2->hello("php", "?") . "|";
        ',
        'expect' => null,
    ],
    'static_default' => [
        'code' => '
            class Util {
                static function fmt($v, $pad = 4) { return str_pad((string)$v, $pad, "0", STR_PAD_LEFT); }
            }
            echo Util::fmt(7) . "|" . Util::fmt(7, 2) . "|";
        ',
        'expect' => null,
    ],
    'callable_default' => [
        'code' => '
            function cb($x = 5) { return $x * 2; }
            $r = array_map("cb", [1, 2]);
            echo $r[0] . "," . $r[1] . "|";
            $r2 = call_user_func("cb");
            echo $r2 . "|";
        ',
        'expect' => null,
    ],
    'trait_default' => [
        'code' => '
            trait Loud {
                function say($msg = "trait-msg") { return "[" . $msg . "]"; }
            }
            class Speaker {
                use Loud;
            }
            $s = new Speaker();
            echo $s->say() . "|" . $s->say("own") . "|";
        ',
        'expect' => null,
    ],
    'trait_overridden' => [
        'code' => '
            trait T { function m($a = "trait") { return "T:" . $a; } }
            class C {
                use T;
                function m($a = "own") { return "C:" . $a; }
            }
            $c = new C();
            echo $c->m() . "|" . $c->m("x") . "|";
        ',
        'expect' => null,
    ],
    'mixed_arity' => [
        'code' => '
            function fmt($a, $b, $c = "c", $d = "d") { return $a . $b . $c . $d; }
            echo fmt("1", "2") . "|" . fmt("1", "2", "3") . "|" . fmt("1", "2", "3", "4") . "|";
        ',
        'expect' => null,
    ],
    'default_int_expr' => [
        'code' => '
            function calc($a = 2 * 3, $b = 10 - 4) { return $a + $b; }
            echo calc() . "|" . calc(1) . "|" . calc(1, 1) . "|";
        ',
        'expect' => null,
    ],
    'default_const_ternary' => [
        'code' => '
            function pick($v = (1 < 2 ? "yes" : "no")) { return $v; }
            echo pick() . "|" . pick("x") . "|";
        ',
        'expect' => null,
    ],
    'default_class_const' => [
        'code' => '
            class Cfg { const MODE = "prod"; }
            function f($m = Cfg::MODE) { return $m; }
            echo f() . "|" . f("dev") . "|";
        ',
        'expect' => null,
    ],
    'method_chain_default' => [
        'code' => '
            class Box {
                function __construct($v = 0) { $this->v = $v; }
                function get($scale = 2) { return $this->v * $scale; }
            }
            $b = new Box(5);
            echo $b->get() . "|" . $b->get(3) . "|";
            $b2 = new Box();
            echo $b2->get() . "|";
        ',
        'expect' => null,
    ],
];

// B 组：VM 扩展（原生 PHP 拒绝；VM 宽容处理，验证输出符合语义）
$extCases = [
    'ext_func_call_default' => [
        'code' => '
            function base() { return 42; }
            function f($v = base()) { return $v + 1; }
            echo f() . "|" . f(7) . "|";
        ',
        'expect' => "43|8|",
    ],
    'ext_strlen_default' => [
        'code' => '
            function tag($t = "x" . "y", $n = strlen("abc")) { return $t . $n; }
            echo tag() . "|" . tag("z", 9) . "|";
        ',
        'expect' => "xy3|z9|",
    ],
    'ext_param_ref_ternary' => [
        'code' => '
            function pick($flag = false, $v = ($flag ? "yes" : "no")) { return $v; }
            echo pick() . "|" . pick(true) . "|" . pick(true, "x") . "|";
        ',
        'expect' => "no|no|x|",
    ],
];

function runVm(string $runtime, string $code): string
{
    $compiler = new VmCompiler();
    $program = $compiler->compile($code);
    // 与 VmRuntimeGenerator::buildArtifact 同一约定：装配 natTbl（name=>idx），
    // CALL_NATIVE 操作数索引它；镜像运行期入口会归一为 idx=>name。
    $program['natTbl'] = $compiler->natTbl();
    $packed = base64_encode(gzencode(json_encode($program), 9));
    $artifact = "<?php\n"
        . '$__P = json_decode(gzdecode(base64_decode(' . var_export($packed, true) . ')), true);' . "\n"
        . $runtime . "\n"
        . "__VMRUN__(\$__P);\n";
    $tmp = tempnam(sys_get_temp_dir(), 'vmdef') . '.php';
    file_put_contents($tmp, $artifact);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

$fail = 0;
echo "== A 组：合法 PHP（原生 vs VM） ==\n";
foreach ($validCases as $name => $case) {
    $native = shell_exec('php -r ' . escapeshellarg($case['code']) . ' 2>&1');
    try {
        $vmOut = runVm($runtime, $case['code']);
    } catch (Throwable $e) {
        echo "[FAIL] $name: VM 编译/运行异常 " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }
    if ($vmOut === $native) {
        echo "[ OK ] $name => " . trim($vmOut) . "\n";
    } else {
        echo "[FAIL] $name\n  native: " . var_export($native, true) . "\n  vm    : " . var_export($vmOut, true) . "\n";
        $fail++;
    }
}

echo "== B 组：VM 扩展（原生拒绝，仅验证 VM 语义） ==\n";
foreach ($extCases as $name => $case) {
    $native = shell_exec('php -r ' . escapeshellarg($case['code']) . ' 2>&1');
    $nativeRejects = strpos($native, 'Fatal error') !== false || strpos($native, 'Parse error') !== false;
    if (!$nativeRejects) {
        echo "[FAIL] $name: 预期原生拒绝但未拒绝（用例需更新）: " . trim($native) . "\n";
        $fail++;
        continue;
    }
    try {
        $vmOut = runVm($runtime, $case['code']);
    } catch (Throwable $e) {
        echo "[FAIL] $name: VM 编译/运行异常 " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }
    if ($vmOut === $case['expect']) {
        echo "[ OK ] $name => " . trim($vmOut) . "（原生拒绝，VM 宽容）\n";
    } else {
        echo "[FAIL] $name\n  expect: " . var_export($case['expect'], true) . "\n  vm    : " . var_export($vmOut, true) . "\n";
        $fail++;
    }
}

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail === 0 ? 0 : 1);
