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

$cases = [
    'while_sum'      => '$s=0;$i=1;while($i<=10){$s+=$i;$i++;}echo $s;',
    'for_sum'        => '$s=0;for($i=1;$i<=10;$i++){$s+=$i;}echo $s;',
    'for_init'       => 'for($i=2;$i<=5;$i++){echo $i;}',
    'for_nested'     => 'for($i=0;$i<3;$i++){for($j=0;$j<2;$j++){echo $i.$j;}}',
    'foreach'        => '$a=[1,2,3,4];$s=0;foreach($a as $v){$s+=$v;}echo $s;',
    'foreach_kv'     => '$a=["x"=>1,"y"=>2];$s=0;foreach($a as $k=>$v){$s+=$v;}echo $s;',
    'if_else'        => 'if(1>2){echo "a";}elseif(2>1){echo "b";}else{echo "c";}',
    'break_loop'     => '$s=0;for($i=0;$i<100;$i++){if($i==5){break;}$s+=$i;}echo $s;',
    'continue_loop'  => '$s=0;for($i=0;$i<10;$i++){if($i%2==0){continue;}$s+=$i;}echo $s;',
    'class_method'   => 'class C{public $v=1;public function inc(){return $this->v+1;}}echo (new C())->inc();',
    'phprun'         => '$x=3;_PHPRUN("echo 100+$x;");',
];

$gen = new VmRuntimeGenerator(new Salt('probe-seed'));
foreach ($cases as $name => $code) {
    echo "=== $name ===\n";
    $t0 = microtime(true);
    try {
        $vm = $gen->execute($code, ['flatten' => true]);
        $dt = microtime(true) - $t0;
        echo "  OK ({$dt}s): " . json_encode($vm) . "\n";
    } catch (Throwable $e) {
        echo "  ERR: " . $e->getMessage() . "\n";
    }
    flush();
}
