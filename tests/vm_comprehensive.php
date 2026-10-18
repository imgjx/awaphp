<?php
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

function runOrig(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'vmo') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    ob_start();
    (static function() use ($tmp) { require $tmp; })();
    $out = (string) ob_get_clean();
    @unlink($tmp);
    return $out;
}
function runVm(string $code): string {
    $p = (new VmCompiler())->compile($code);
    ob_start();
    __VMRUN__($p);
    return (string) ob_get_clean();
}
function check(string $name, string $code): void {
    try {
        $orig = runOrig($code);
    } catch (Throwable $e) {
        echo "[$name] ORIG-ERR " . $e->getMessage() . "\n";
        return;
    }
    try {
        $vm = runVm($code);
    } catch (Throwable $e) {
        echo "[$name] VM-ERR " . $e->getMessage() . "\n";
        return;
    }
    if ($vm === $orig) {
        echo "[$name] PASS\n";
    } else {
        echo "[$name] MISMATCH\n  orig: " . json_encode($orig) . "\n  vm  : " . json_encode($vm) . "\n";
    }
}

check('while_sum', '$s=0;$i=1;while($i<=10){$s+=$i;$i++;}echo $s;');
check('for_sum', '$s=0;for($i=1;$i<=10;$i++){$s+=$i;}echo $s;');
check('for_init', 'for($i=2;$i<=5;$i++){echo $i;}'); // 回归：for 初始化段必须是赋值，不能丢右值
check('for_nested', 'for($i=0;$i<3;$i++){for($j=0;$j<2;$j++){echo $i.$j;}}'); // 回归：JMP 回跳必须指向条件起点
check('foreach', '$a=[1,2,3,4];$s=0;foreach($a as $v){$s+=$v;}echo $s;');
check('foreach_kv', '$a=["x"=>1,"y"=>2];$s=0;foreach($a as $k=>$v){$s+=$v;}echo $s;');
check('foreach_kv_order', '$a=["x"=>1,"y"=>2,"z"=>3];$o="";foreach($a as $k=>$v){$o.=$k.$v.";";}echo $o;');
check('arr_kv_order', '$a=["x"=>1,"y"=>2,"z"=>3];$ks="";foreach($a as $k=>$v){$ks.=$k;}echo $ks;');
check('if_else', 'if(1>2){echo "a";}elseif(2>1){echo "b";}else{echo "c";}');
check('nested_if', '$x=5;if($x>0){if($x<10){echo "ok";}}');
check('arith_prec', 'echo 2+3*4-6/2;');
check('str_cat', 'echo "a".(1+2)."c";');
check('cmp', 'echo (3>=3?"ge":"lt").(2!=3?"ne":"eq");');
check('logical', 'echo ((1==1)&&(2==2)?"t":"f").((1==2)||(3==3)?"t":"f");');
check('arr_idx', '$a=[10,20,30];echo $a[1];');
check('arr_kv_idx', '$a=["k"=>"v"];echo $a["k"];');
check('arr_push', '$a=[];$a[]=1;$a[]=2;echo $a[0].$a[1];');
check('func_multi', 'function add($a,$b,$c){return $a+$b+$c;}echo add(1,2,3);');
check('recursion', 'function f($n){return $n<=1?1:$n*f($n-1);}echo f(5);');
check('ternary', '$x=7;echo $x>5?"big":"small";');
check('break_loop', '$s=0;for($i=0;$i<100;$i++){if($i==5){break;}$s+=$i;}echo $s;');
check('continue_loop', '$s=0;for($i=0;$i<10;$i++){if($i%2==0){continue;}$s+=$i;}echo $s;');
check('pow', 'echo 2**10;');
check('bw', 'echo (6&3)." ".(6|1)." ".(6^2)." ".(1<<4)." ".(16>>2);');
check('neg', 'echo -3+10;');
check('class_method', 'class C{public $v=1;public function inc(){return $this->v+1;}}echo (new C())->inc();');
check('concat_method', 'class G{public function h(){return "hi";}}echo "x".(new G())->h()."y";');
check('method_call_chain', 'class A{public function b(){return 7;}}class B{public $a;public function __construct(){}}echo 1;');
