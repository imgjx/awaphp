<?php
declare(strict_types=1);
/**
 * VM 语法能力广谱扫描：一次性对比原生 PHP 与参考运行期，输出支持矩阵。
 * 用法：php tests/vm_cap_scan.php [--filter=xxx]
 */
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

function runOrig(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'vso') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return (string) $out;
}
function runVm(string $code): string {
    // 子进程隔离：顶层 exit/return 等会终止进程的用例不会拖垮扫描本身
    $harness = tempnam(sys_get_temp_dir(), 'vsh') . '.php';
    $src = tempnam(sys_get_temp_dir(), 'vsc') . '.php';
    file_put_contents($src, $code);
    file_put_contents($harness, "<?php\n"
        . "require " . var_export(__DIR__ . '/../src/Parser.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Support/StatementSplitter.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Vm/Op.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Vm/Compiler.php', true) . ";\n"
        . "require " . var_export(__DIR__ . '/../src/Vm/runtime.php', true) . ";\n"
        . 'try { $p = (new VmCompiler())->compile(file_get_contents(' . var_export($src, true) . ")); ob_start(); __VMRUN__(\$p); echo ob_get_clean(); }\n"
        . "catch (Throwable \$e) { echo 'VM-ERR(' . get_class(\$e) . '): ' . \$e->getMessage(); }\n");
    $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($harness), [
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);
    @unlink($harness); @unlink($src);
    return $out;
}

$cases = [
    // ===== 引用体系 =====
    'ref_assign'        => '$a=1;$b=&$a;$b=2;echo $a;',
    'ref_swap'          => '$a=1;$b=2;$t=&$a;$a=&$b;$b=&$t;echo $a.$b;',
    'ref_foreach'       => '$a=[1,2];foreach($a as &$v){$v*=10;}unset($v);echo $a[0].$a[1];',
    'ref_foreach_kv'    => '$a=["x"=>1];foreach($a as $k=>&$v){$v++;}echo $a["x"];',
    'ref_param_ret'     => 'function &g(){$s=5;return $s;}$r=&g();$r=9;echo $r;',
    'ref_nested_arr'    => '$a=[[1]];$b=&$a[0][0];$b=7;echo $a[0][0];',
    'ref_foreach_list'  => '$a=[[1,2]];foreach($a as [$x,$y]){echo $x.$y;}',

    // ===== 闭包 / 匿名函数 =====
    'closure_basic'     => '$f=function($x){return $x*2;};echo $f(21);',
    'closure_capture'   => '$n=5;$f=function()use($n){return $n+1;};echo $f();',
    'closure_capture_ref'=> '$n=5;$f=function()use(&$n){$n++;};$f();echo $n;',
    'closure_this'      => 'class C{public $v=7;public function m(){$f=function(){return $this->v;};return $f();}}echo (new C())->m();',
    'closure_arrow'     => '$n=5;$f=fn($x)=>$x+$n;echo $f(1);',
    'closure_arrow_this'=> 'class C{public $v=3;public function m(){$f=fn()=>$this->v;return $f();}}echo (new C())->m();',
    'closure_static'    => '$f=static function(){return 1;};echo $f();',
    'closure_in_call'   => 'echo array_map(function($x){return $x+1;},[1,2,3])[2];',
    'closure_ret_closure'=> '$f=function($x){return function($y)use($x){return $x+$y;};};$g=$f(10);echo $g(5);',

    // ===== first-class callable =====
    'fcc_strlen'       => '$f=strlen(...);echo $f("abc");',
    'fcc_method'       => 'class C{public function m($x){return $x*2;}}$o=new C();$f=$o->m(...);echo $f(21);',

    // ===== 生成器 =====
    'gen_basic'        => 'function g(){yield 1;yield 2;}$s=0;foreach(g() as $v){$s+=$v;}echo $s;',
    'gen_key'          => 'function g(){yield "a"=>1;yield "b"=>2;}foreach(g() as $k=>$v){echo $k.$v;}',
    'gen_send'         => 'function g(){$x=yield 1;echo $x;}$it=g();$it->current();$it->send("S");',
    'gen_return'       => 'function g(){yield 1;return 9;}$it=g();$it->current();echo $it->getReturn();',
    'yield_from'       => 'function i(){yield 1;yield 2;}function g(){yield from i();yield 3;}$s=0;foreach(g() as $v){$s+=$v;}echo $s;',
    'yield_expr'       => 'function g(){$x=(yield 1)+10;echo $x;}$it=g();$it->current();$it->send(5);',
    'yield_none'       => 'function g(){if(false){yield 1;}return 42;}echo g()->getReturn();',

    // ===== 静态变量 =====
    'static_var'       => 'function c(){static $n=0;$n++;return $n;}echo c().c().c();',
    'static_var_arr'   => 'function c(){static $a=[];$a[]=count($a);return implode(",",$a);}echo c()."|".c();',
    'static_var_ref'   => 'function c(&$x){static $n=0;$n+=$x;return $n;}$v=3;echo c($v).c($v);',

    // ===== 解构 / list =====
    'list_basic'       => '$a=[1,2];list($x,$y)=$a;echo $x.$y;',
    'list_skip'        => '$a=[1,2,3];list($x,,$z)=$a;echo $x.$z;',
    'list_nested'      => '$a=[[1,2],[3,4]];list(list($x,$y),list($z,$w))=$a;echo $x.$y.$z.$w;',
    'destr_short'      => '$a=[1,2];[$x,$y]=$a;echo $x.$y;',
    'destr_kv'         => '$a=["k"=>5];["k"=>$v]=$a;echo $v;',
    'foreach_destr'    => 'foreach([[1,2],[3,4]] as [$x,$y]){echo $x.$y;}',
    'foreach_destr_kv' => 'foreach([["a",1],["b",2]] as [$k,$v]){echo $k.$v;}',

    // ===== 复合赋值 / 自增边缘 =====
    'cassign_arr'      => '$a=["k"=>1];$a["k"]+=5;echo $a["k"];',
    'cassign_prop'     => 'class C{public $p=1;public function m(){$this->p+=5;return $this->p;}}echo (new C())->m();',
    'cassign_prop_str' => 'class C{public $p="a";public function m(){$this->p.="b";return $this->p;}}echo (new C())->m();',
    'cassign_nested'   => '$a=[[1]];$a[0][0]-=1;echo $a[0][0];',
    'postinc_arr'      => '$a=[5];$a[0]++;echo $a[0];',
    'predec_arr'       => '$a=[5];echo --$a[0];',
    'postinc_prop'     => 'class C{public $p=1;public function m(){return $this->p++;}}echo (new C())->m();',

    // ===== 属性 / 静态成员 =====
    'prop_push'        => 'class C{public $a=[];public function m(){$this->a[]=1;$this->a[]=2;return count($this->a);}}echo (new C())->m();',
    'sprop_push'       => 'class C{public static $a=[];public static function m(){self::$a[]=1;return count(self::$a);}}echo C::m();',
    'sprop_read'       => 'class C{public static $p=42;public static function m(){return self::$p;}}echo C::m();',
    'static_late'      => 'class A{public static function n(){return 3;}}class B extends A{public static function m(){return static::n();}}echo B::m();',
    'dyn_prop'         => 'class C{public $p=1;}$o=new C();$n="p";echo $o->$n;',
    'dyn_method'       => 'class C{public function m(){return 5;}}$o=new C();$n="m";echo $o->$n();',
    'dyn_static_call'  => 'class C{public static function m(){return 5;}}$n="m";echo C::$n();',
    'instanceof_dyn'   => 'class C{}$o=new C();$n="C";echo $o instanceof $n?"y":"n";',

    // ===== 类特性 =====
    'ctor_promote'     => 'class C{public function __construct(private int $x, public string $y="d"){}public function s(){return $this->x.$this->y;}}echo (new C(1))->s();',
    'readonly_prop'    => 'class C{public readonly int $x;public function __construct(){$this->x=9;}}echo (new C())->x;',
    'class_const'      => 'class C{const V=5;public static function m(){return self::V;}}echo C::m();',
    'class_const_expr' => 'class C{const A=2;const B=self::A*3;public static function m(){return self::B;}}echo C::m();',
    'trait_basic'      => 'trait T{public function m(){return 5;}}class C{use T;}echo (new C())->m();',
    'trait_conflict'   => 'trait A{public function m(){return "A";}}trait B{public function m(){return "B";}}class C{use A,B{B::m insteadof A;}}echo (new C())->m();',
    'trait_alias'      => 'trait A{public function m(){return "A";}}class C{use A{m as am;}}echo (new C())->am();',
    'iface_const'      => 'interface I{const V=7;}class C implements I{}echo C::V;',
    'abstract_call'    => 'abstract class A{abstract public function m();public function t(){return $this->m();}}class C extends A{public function m(){return 4;}}echo (new C())->t();',
    'final_method'     => 'class C{final public function m(){return 1;}}echo (new C())->m();',
    'anon_class'       => '$o=new class{public function m(){return 6;}};echo $o->m();',
    'enum_basic'       => 'enum E{case A;case B;public function v(){return 1;}}echo E::A->v();',

    // ===== 控制流 =====
    'goto_stmt'        => 'goto e;echo "s";e:echo "d";',
    'switch_fall'      => '$x=1;switch($x){case 1:echo "a";case 2:echo "b";break;default:echo "c";}',
    'match_nows'       => '$x=1;echo match(true){$x>0=>"p",default=>"n"};',
    'match_arm_fn'     => 'echo match(2){1,2=>strlen("ab"),default=>0};',
    'label_break'      => 'for($i=0;$i<3;$i++){for($j=0;$j<3;$j++){if($j==1)break 2;echo $i.$j;}}',
    'label_continue'   => 'for($i=0;$i<2;$i++){for($j=0;$j<2;$j++){if($j==0)continue 2;echo $i.$j;}}',
    'throw_expr'       => '$f=fn($x)=>$x??throw new Exception("z");echo $f(5);',
    'nullsafe'         => 'class C{public $c=null;}$o=new C();echo $o->c?->m()??"d";',
    'nullsafe_chain'   => 'class A{public $b=null;}class B{public $c=null;}$a=new A();echo $a->b?->c?->m()??"n";',

    // ===== 顶层语句 =====
    'top_exit'         => 'echo "a";exit;echo "b";',
    'top_return'       => 'return 5;',
    'top_break'        => 'echo "a";break;echo "b";',

    // ===== 全局 / 超全局 =====
    'global_kw'        => '$g=1;function f(){global $g;return $g+1;}echo f();',
    'globals_set'      => 'function f(){$GLOBALS["x"]=9;}f();echo $x;',
    'globals_append'   => 'function f(){$GLOBALS["a"]=[];$GLOBALS["a"][]=1;}f();echo $GLOBALS["a"][0];',
    'superglobal_read' => 'echo strlen($_SERVER["argv"][0]??"x")>0?"y":"n";',
    'get_defined_vars' => '$a=1;$r=get_defined_vars();echo isset($r["a"])?"y":"n";',
    'compact_fn'       => '$x=1;$y=2;echo compact("x","y")["y"];',
    'extract_fn'       => '$a=["k"=>5];extract($a);echo $k;',

    // ===== 字符串 / 数值边缘语义 =====
    'heredoc_basic'    => "\$x=5;\$s=<<<EOT\nval=\$x\nEOT;echo \$s;",
    'heredoc_indent'   => "\$s=<<<EOT\n    line1\n    line2\n    EOT;echo \$s;",
    'nowdoc'           => "\$x=5;\$s=<<<'EOT'\nval=\$x\nEOT;echo \$s;",
    'unary_plus'       => 'echo +"5abc";',
    'hex_str_add'      => 'echo "0x1A"+1;',
    'octal_str_add'    => 'echo "010"+1;',
    'binary_str_add'   => 'echo "0b101"+1;',
    'concat_arr'       => '$a=[1,2];$b=[3];$c=$a+$b;echo count($c);',
    'array_cast_str'   => '$a=[1,2];echo (string)$a==="Array"?"y":"n";',
    'intdiv'           => 'echo intdiv(7,2);',
    'spread_call_arr'  => 'function f($a,$b,$c){return $a.$b.$c;}echo f(...[1,2,3]);',
    'spread_strkey'    => 'function f($x,$y){return $x.$y;}$a=["y"=>2,"x"=>1];echo f(...$a);',
    'named_spread'     => 'function f($a,$b){return $a.$b;}$a=["b"=>2];echo f(1,...$a);',
    'sprintf_pos'      => 'echo sprintf("%2$s %1$s","a","b");',
    'coalesce_chain'   => '$a=["k"=>null];echo $a["k"]??$a["x"]??"d";',
    'coalesce_isset'   => '$a=[1];echo $a[5]??"d";',
    'isset_prop_chain' => 'class C{public $p=null;}$o=new C();echo isset($o->p->x)?"y":"n";',
    'empty_coerce'     => '$a="0";echo empty($a)?"y":"n";',
    'spaceship_str'    => 'echo "a"<=>"b";',
    'numeric_str_cmp'  => 'echo "10"=="10.0"?"y":"n";',
    'pow_neg'          => 'echo 2**-1;',
    'mod_float'        => 'echo 5.5%2;',
    'bignum'           => 'echo 9223372036854775807+1;',
    'str_repeat'       => 'echo str_repeat("ab",3);',
    'array_key_cast'   => '$a=[];$a["1"]=1;$a[1.5]=2;echo count($a);',
    'unset_var'        => '$a=1;unset($a);echo isset($a)?"y":"n";',
    'unset_arr'        => '$a=[1,2];unset($a[0]);echo count($a);',
    'isset_dim'        => '$a=[1];echo isset($a[0])?"y":"n";',
    'interp_nested'    => '$a=["k"=>"v"];echo "x{$a["k"]}y";',
    'interp_obj'       => 'class C{public $p="v";}$o=new C();echo "x{$o->p}y";',
    'interp_method'    => 'class C{public $p="v";public function g(){return "m";}}$o=new C();echo "x{$o->g()}y";',
    'interp_idx_obj'   => 'class C{public $a=["k"=>"v"];}$o=new C();echo "x{$o->a["k"]}y";',
];

$filter = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--filter=')) { $filter = substr($a, 9); }
}

$pass = 0; $fail = 0; $fails = [];
foreach ($cases as $name => $code) {
    if ($filter !== null && !str_contains($name, $filter)) { continue; }
    $o = runOrig($code);
    $v = runVm($code);
    if ($o === $v) { $pass++; }
    else {
        $fail++; $fails[] = [$name, $o, $v];
        echo "[FAIL] $name\n    orig: " . json_encode(substr($o, 0, 120)) . "\n    vm  : " . json_encode(substr($v, 0, 160)) . "\n";
    }
}
echo "\n===== 能力扫描：PASS=$pass FAIL=$fail =====\n";
if ($fails) {
    echo "\n失败清单：" . implode('、', array_column($fails, 0)) . "\n";
}
