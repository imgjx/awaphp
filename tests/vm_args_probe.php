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
    $shim = 'function _PHPRUN($code){ if(isset($GLOBALS)){ foreach($GLOBALS as $__k=>$__v){ if($__k!=="GLOBALS"){ $$__k=$__v; } } } eval($code); }' . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'vao') . '.php';
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
    // —— 引用参数（&$x）：copy-in / copy-out 双运行期一致 ——
    'ref_write'      => 'function f(&$x){$x=99;}$a=1;f($a);echo $a;',
    'ref_read'       => 'function f(&$x){return $x*2;}$a=21;echo f($a);',
    'ref_swap'       => 'function s(&$a,&$b){$t=$a;$a=$b;$b=$t;}$x=1;$y=2;s($x,$y);echo $x.$y;',
    'ref_default_mix'=> 'function f($a,&$b,$c=5){$b+=$a+$c;}$x=1;$y=10;f($x,$y);echo $x.$y;',
    'ref_array_acc'  => 'function add(&$acc,$v){$acc[]=$v;}$a=[];for($i=0;$i<3;$i++){add($a,$i);}echo count($a).",".$a[0].$a[2];',
    'ref_method'     => 'class C{public function inc(&$n){$n++;}}$o=new C();$v=5;$o->inc($v);echo $v;',
    'ref_static'     => 'class C{public static function inc(&$n){$n++;}}$v=1;C::inc($v);echo $v;',
    'ref_native'     => '$a=[3,1,2];sort($a);echo $a[0].$a[2];',
    'ref_nested_fn'  => 'function g(&$x){$x=$x*3;}function f(&$x){g($x);$x+=1;}$a=2;f($a);echo $a;',
    'ref_cond'       => 'function f(&$x,$flag){if($flag){$x="Y";}else{$x="N";}}$v="";f($v,1);echo $v;',

    // —— 可变参数（...$args）——
    'va_sum'         => 'function s(...$n){$t=0;foreach($n as $v){$t+=$v;}return $t;}echo s(1,2,3,4);',
    'va_none'        => 'function s(...$n){return count($n);}echo s();',
    'va_fixed_head'  => 'function s($a,...$n){return $a.":".count($n);}echo s(1,2,3);',
    'va_default_head'=> 'function s($a=7,...$n){return $a+array_sum($n);}echo s();',
    'va_string_join' => 'function j($sep,...$p){return implode($sep,$p);}echo j("-","a","b","c");',

    // —— 命名实参（name: expr，PHP 8.0+）——
    'named_basic'    => 'function f($a,$b){return $a-$b;}echo f(b:1,a:2);',
    'named_partial'  => 'function f($a,$b=10){return $a+$b;}echo f(b:5,a:1);',
    'named_skip'     => 'function f($a,$b="b",$c="c"){return $a.$b.$c;}echo f("x",c:"z");',
    'named_all'      => 'function f($a,$b=2,$c=3){return $a+$b+$c;}echo f(1,c:10,b:100);',
    'named_ref'      => 'function f(&$x,$y){$x+=$y;}$a=1;f($a,y:5);echo $a;',
    'named_static'   => 'class C{public static function m($a,$b){return $a+$b;}}echo C::m(b:2,a:3);',
    'named_method_this' => 'class C{public $v=10;public function m($a,$b){return $this->v+$a+$b;}}$o=new C();echo $o->m(b:2,a:3);',

    // —— 解包实参（...$arr）——
    'spread_va'      => 'function s(...$n){return array_sum($n);}$a=[1,2,3];echo s(...$a);',
    'spread_head'    => 'function s($a,...$n){return $a+array_sum($n);}$a=[2,3,4];echo s(1,...$a);',
    'spread_assoc'   => 'function s($x,$y){return $x+$y;}$a=["x"=>1,"y"=>2];echo s(...$a);',
    'spread_empty'   => 'function s(...$n){return count($n);}$a=[];echo s(...$a);',
    'spread_mid'     => 'function s($a,$b,$c){return $a.$b.$c;}$m=["2","3"];echo s("1",...$m);',
    'spread_new'     => 'class C{public function __construct(...$a){$this->n=array_sum($a);}public $n=0;}$p=[3,4];$o=new C(...$p);echo $o->n;',

    // —— 组合场景 ——
    'combo_ref_va'   => 'function s(&$out,...$n){$out=array_sum($n);}$r=0;s($r,1,2,3);echo $r;',
    'combo_loop_acc' => 'function acc(&$t,$v){$t+=$v;}$t=0;foreach([1,2,3,4] as $v){acc($t,$v);}echo $t;',
    'combo_named_va' => 'function s($a,$b,...$n){return $a."-".$b."-".array_sum($n);}echo s(...[1],b:2);',
];

$gen = new VmRuntimeGenerator(new Salt('args-probe-seed'));
$total = 0; $fail = 0;
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
