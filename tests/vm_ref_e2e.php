<?php
declare(strict_types=1);
/**
 * 引用赋值 / 引用 foreach 端到端验证（生成运行期 + flatten/stateMachine）。
 * 对每个用例：原生 PHP 输出 vs 完整混淆管线输出（含随机 opcode 生成运行期）。
 */
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

$cases = [
    'ref_assign'        => '$a=1;$b=&$a;$b=2;echo $a;',
    'ref_swap'          => '$a=1;$b=2;$t=&$a;$a=&$b;$b=&$t;echo $a.$b;',
    'ref_foreach'       => '$a=[1,2];foreach($a as &$v){$v*=10;}unset($v);echo $a[0].$a[1];',
    'ref_foreach_kv'    => '$a=["x"=>1];foreach($a as $k=>&$v){$v++;}echo $a["x"];',
    'ref_nested_arr'    => '$a=[[1]];$b=&$a[0][0];$b=7;echo $a[0][0];',
    'ref_prop'          => '$o=new stdClass();$o->p=1;$q=&$o->p;$q=9;echo $o->p;',
    'ref_append'        => '$a=[1];$b=&$a[];$b=5;echo $a[1];',
    'ref_expr_ctx'      => '$a=1;$b=2;$c=($b=&$a);$c=3;echo $a.$b.$c;',
    'ref_chain_prop'    => '$o=new stdClass();$o->p=new stdClass();$o->p->q=1;$r=&$o->p->q;$r=8;echo $o->p->q;',
    'ref_mixed_idx'     => '$o=new stdClass();$o->p=[7];$r=&$o->p[0];$r=3;echo $o->p[0];',
    'ref_byref_param'   => 'function inc(&$n){$n++;}$x=5;inc($x);echo $x;',
    'ref_foreach_mod'   => '$a=[1,2,3];foreach($a as &$v){$v=$v*2;}unset($v);$s=0;foreach($a as $x){$s+=$x;}echo $s;',
    'ref_func_local'    => 'function f(){$a=1;$b=&$a;$b++;return $a;}echo f();',
    'ref_arr_of_ref'    => '$a=[1,2];$r=[&$a[0],&$a[1]];$r[0]=10;$r[1]=20;echo $a[0].$a[1];',
];

$pass = 0; $fail = 0;
foreach ($cases as $name => $code) {
    // 原生
    $t1 = tempnam(sys_get_temp_dir(), 'e1') . '.php';
    file_put_contents($t1, "<?php\n" . $code);
    $orig = shell_exec('php ' . escapeshellarg($t1) . ' 2>&1');
    @unlink($t1);

    // 编译 + 生成运行期 + 执行（模拟完整 VM 管线）
    $src = tempnam(sys_get_temp_dir(), 'e2') . '.php';
    file_put_contents($src, $code);
    $h = tempnam(sys_get_temp_dir(), 'e3') . '.php';
    $prog = (new VmCompiler())->compile(file_get_contents($src));
    $phar = var_export(serialize($prog), true);
    $harness = "<?php\n"
        . "require " . var_export(__DIR__ . '/../src/Parser.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Support/StatementSplitter.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Vm/Op.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Vm/Compiler.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Vm/runtime.php', true) . ";\n"
        . "\$p = unserialize($phar);\n"
        . "try { ob_start(); __VMRUN__(\$p); echo ob_get_clean(); }\n"
        . "catch (Throwable \$e) { echo 'VM-ERR(' . get_class(\$e) . '): ' . \$e->getMessage(); }\n";
    file_put_contents($h, $harness);
    $vm = shell_exec('php ' . escapeshellarg($h) . ' 2>&1');
    @unlink($src); @unlink($h);

    $ok = $orig === $vm;
    echo ($ok ? '[PASS] ' : '[FAIL] '), $name, "\n";
    if (!$ok) {
        echo "    orig: ", var_export($orig, true), "\n";
        echo "    vm  : ", var_export($vm, true), "\n";
        $fail++;
    } else {
        $pass++;
    }
}
echo "\n端到端（参考运行期）：PASS=$pass FAIL=$fail\n";
