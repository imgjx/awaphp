<?php
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

function dump(string $name, string $code): void {
    echo "==== $name ====\n";
    try {
        $p = (new VmCompiler())->compile($code);
    } catch (Throwable $e) {
        echo "COMPILE-ERR: " . $e->getMessage() . "\n";
        return;
    }
    // main
    $names = VmOp::NAMES;
    $idx = 0;
    $lines = [];
    foreach ($p['main'] as $ins) {
        $op = $ins[0];
        $tail = '';
        for ($i=1;$i<count($ins);$i++) { $tail .= ' ' . json_encode($ins[$i]); }
        $lines[] = sprintf("%3d: %-10s%s", $idx++, ($names[$op] ?? $op), $tail);
    }
    echo implode("\n", $lines) . "\n";
    // funcs
    foreach ($p['funcs'] as $fn => $fc) {
        echo "  -- func $fn --\n";
        $idx = 0; $lines = [];
        foreach ($fc as $ins) {
            $op = $ins[0]; $tail='';
            for ($i=1;$i<count($ins);$i++){$tail.=' '.json_encode($ins[$i]);}
            $lines[] = sprintf("%3d: %-10s%s", $idx++, ($names[$op]??$op), $tail);
        }
        echo implode("\n", $lines) . "\n";
    }
    foreach ($p['classes'] as $cn => $cl) {
        echo "  -- class $cn --\n";
        echo "     props: " . json_encode($cl['props']) . "\n";
        foreach ($cl['methods'] as $mn => $mc) {
            echo "     method $mn:\n";
            $idx=0;$lines=[];
            foreach ($mc as $ins) {
                $op=$ins[0];$tail='';
                for($i=1;$i<count($ins);$i++){$tail.=' '.json_encode($ins[$i]);}
                $lines[]=sprintf("%3d: %-10s%s",$idx++,($names[$op]??$op),$tail);
            }
            echo "       ".implode("\n       ",$lines)."\n";
        }
    }
    // run
    ob_start();
    try { __VMRUN__($p); } catch (Throwable $e) { echo "RUN-ERR: ".$e->getMessage()."\n"; }
    $out = ob_get_clean();
    echo "  OUT: " . json_encode($out) . "\n";
    echo "  ORIG: " . json_encode((function($c){ob_start();(static function()use($c){eval('?'.'>'.$c);})();return (string)ob_get_clean();})($code)) . "\n\n";
}

dump('foreach', '$a=[1,2,3,4];$s=0;foreach($a as $v){$s+=$v;}echo $s;');
dump('cmp', 'echo (3>=3?"ge":"lt").(2!=3?"ne":"eq");');
dump('bw', 'echo (6&3)." ".(6|1)." ".(6^2)." ".(1<<4)." ".(16>>2);');
dump('recursion', 'function f($n){return $n<=1?1:$n*f($n-1);}echo f(5);');
dump('ternary', '$x=7;echo $x>5?"big":"small";');
dump('logical', 'echo ((1==1)&&(2==2)?"t":"f").((1==2)||(3==3)?"t":"f");');
dump('class_method', 'class C{public $v=1;public function inc(){return $this->v+1;}}echo (new C())->inc();');
dump('while_sum', '$s=0;$i=1;while($i<=10){$s+=$i;$i++;}echo $s;');
