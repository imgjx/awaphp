<?php
declare(strict_types=1);
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';

function runOrig(string $code): string {
    // _PHPRUN 是编译器拦截的特殊指令；裸跑原始代码时需要一个等价 shim：
    // 把全局变量提取进 eval 作用域，使 eval 内的 $x 可见（与 VM 侧 extract($vars) 对齐）。
    $shim = 'function _PHPRUN($code){ if(isset($GLOBALS)){ foreach($GLOBALS as $__k=>$__v){ if($__k!=="GLOBALS"){ $$__k=$__v; } } } eval($code); }' . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'vgo') . '.php';
    file_put_contents($tmp, "<?php\n" . $shim . $code);
    $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), [
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    @unlink($tmp);
    if ($err !== '') {
        return "ERR:" . $err;
    }
    return $out;
}

$cases = [
    'while_sum'      => '$s=0;$i=1;while($i<=10){$s+=$i;$i++;}echo $s;',
    'for_sum'        => '$s=0;for($i=1;$i<=10;$i++){$s+=$i;}echo $s;',
    'for_init'       => 'for($i=2;$i<=5;$i++){echo $i;}',
    'for_nested'     => 'for($i=0;$i<3;$i++){for($j=0;$j<2;$j++){echo $i.$j;}}',
    'foreach'        => '$a=[1,2,3,4];$s=0;foreach($a as $v){$s+=$v;}echo $s;',
    'foreach_kv'     => '$a=["x"=>1,"y"=>2];$s=0;foreach($a as $k=>$v){$s+=$v;}echo $s;',
    'if_else'        => 'if(1>2){echo "a";}elseif(2>1){echo "b";}else{echo "c";}',
    'arith_prec'     => 'echo 2+3*4-6/2;',
    'str_cat'        => 'echo "a".(1+2)."c";',
    'cmp'            => 'echo (3>=3?"ge":"lt").(2!=3?"ne":"eq");',
    'logical'        => 'echo ((1==1)&&(2==2)?"t":"f").((1==2)||(3==3)?"t":"f");',
    'arr_idx'        => '$a=[10,20,30];echo $a[1];',
    'arr_push'       => '$a=[];$a[]=1;$a[]=2;echo $a[0].$a[1];',
    'func_multi'     => 'function add($a,$b,$c){return $a+$b+$c;}echo add(1,2,3);',
    'recursion'      => 'function f($n){return $n<=1?1:$n*f($n-1);}echo f(5);',
    'ternary'        => '$x=7;echo $x>5?"big":"small";',
    'break_loop'     => '$s=0;for($i=0;$i<100;$i++){if($i==5){break;}$s+=$i;}echo $s;',
    'continue_loop'  => '$s=0;for($i=0;$i<10;$i++){if($i%2==0){continue;}$s+=$i;}echo $s;',
    'pow'            => 'echo 2**10;',
    'bw'             => 'echo (6&3)." ".(6|1)." ".(6^2)." ".(1<<4)." ".(16>>2);',
    'neg'            => 'echo -3+10;',
    'class_method'   => 'class C{public $v=1;public function inc(){return $this->v+1;}}echo (new C())->inc();',
    'concat_method'  => 'class G{public function h(){return "hi";}}echo "x".(new G())->h()."y";',
    'isset_empty'    => '$a=["k"=>1];echo (isset($a["k"])?"Y":"N").(empty($a["x"])?"E":"F");',
    'unset'          => '$a=["k"=>1,"x"=>2];unset($a["k"]);echo isset($a["k"])?"Y":"N";',
    'phprun'         => '$x=3;_PHPRUN("echo 100+$x;");',
    'phprun_expr'    => 'function mk(){return "echo 7*6;";}_PHPRUN(mk());',
    // —— 大整数常量（>=2^30）往返：zigzag 翻倍后越过 2^31，曾因 varint 写端
    //    & 0x7FFFFFFF 截断导致高位静默损坏（低 32 位偶然蒙混）——
    'bigconst_mul'   => 'echo 119673848*1103515245;',
    'bigconst_mul2'  => 'echo 119673848*2246822519;',
    'bigconst_mask'  => 'echo (119673848*2246822519+3266489917)&0x7FFFFFFF;',
    'bigconst_neg'   => 'echo (0-2246822519)+100;',
    'bigconst_fn'    => 'function lcg($v){$v=($v*1103515245+12345)&0x7FFFFFFF;$v=($v*2246822519+3266489917)&0x7FFFFFFF;return $v;}echo lcg(119673831);',
    // —— try/catch/finally 真编译（验证生成运行期三模式无回归）——
    'try_catch'        => 'try { echo "a"; } catch (Exception $e) { echo "c"; } echo "d";',
    'try_throw'        => 'try { throw new Exception("x"); echo "a"; } catch (Exception $e) { echo "c"; } echo "d";',
    'try_finally'      => 'try { echo "a"; } finally { echo "f"; } echo "d";',
    'try_catch_type'   => 'try { throw new RuntimeException("x"); } catch (InvalidArgumentException $e) { echo "i"; } catch (RuntimeException $e) { echo "r"; } echo "d";',
    'try_func'         => 'function f($x){ try { if($x<0) throw new Exception("x"); return "ok"; } catch (Exception $e) { return "caught"; } } echo f(1); echo f(-1);',
    'try_method'       => 'class C { public function m($x){ try { if($x<0) throw new Exception("x"); return "ok"; } catch (Exception $e) { return "caught"; } } } $c=new C(); echo $c->m(1); echo $c->m(-1);',
    'try_finally_ret'  => 'function f(){ try { return "t"; } finally { echo "f"; } } echo f();',
    'try_finally_throw' => 'try { try { throw new Exception("x"); } finally { echo "fin"; } } catch (Exception $e) { echo "caught"; }',
];

$gen = new VmRuntimeGenerator(new Salt('probe-seed'));
$total = 0; $fail = 0;
// 每种模式对应一组 buildArtifact 选项；sm_on = 状态机混淆独立层（与 flatten 互斥）
foreach ([
    'flat_off' => ['flatten' => false, 'stateMachine' => false],
    'flat_on'  => ['flatten' => true,  'stateMachine' => false],
    'sm_on'    => ['flatten' => false, 'stateMachine' => true],
] as $mode => $opts) {
    echo "===== mode=$mode =====\n";
    foreach ($cases as $name => $code) {
        $total++;
        try {
            $orig = runOrig($code);
        } catch (Throwable $e) {
            echo "  [$name] ORIG-ERR " . $e->getMessage() . "\n"; continue;
        }
        try {
            $vm = $gen->execute($code, $opts);
        } catch (Throwable $e) {
            echo "  [$name] VM-ERR(" . get_class($e) . ") " . $e->getMessage() . "\n"; $fail++; continue;
        }
        if ($vm === $orig) {
            echo "  [$name] PASS\n";
        } else {
            $fail++;
            echo "  [$name] MISMATCH\n    orig: " . json_encode($orig) . "\n    vm  : " . json_encode($vm) . "\n";
        }
    }
}
echo "\nTOTAL=$total FAIL=$fail\n";
