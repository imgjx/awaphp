<?php
/**
 * P7 回归测试：VM 字节码级真实垃圾指令（VmJunk）
 *
 * 验证要点：
 *   1. 语义保真：注入 N 段真实垃圾 opcode 后，VM 执行结果与原始 PHP 完全一致
 *      （覆盖 linear / flatten / stateMachine 三种模式 × junk=0/3/6/12）。
 *   2. 真正注入：junk>0 时生成的 Program 操作码总数严格大于 junk=0（说明垃圾指令
 *      确实进入了字节码容器，而非被后续优化消除）。
 *   3. 栈平衡自检：每条垃圾模板净栈变化恒为 0（由设计约束保证；本测试通过"执行结果与
 *      未注入时逐字节一致"间接证明——若出现栈不平衡，循环/函数返回必然错位或死循环）。
 */
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
    $tmp = tempnam(sys_get_temp_dir(), 'vjk') . '.php';
    file_put_contents($tmp, "<?php\n" . $shim . $code);
    $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), [
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    @unlink($tmp);
    return $err !== '' ? "ERR:" . $err : $out;
}

/** 统计 Program 内全部操作码条数（main + funcs + classes.methods） */
function countOps(array $program): int {
    $n = 0;
    $walk = static function (array $seg) use (&$n) {
        foreach ($seg as $ins) {
            if (is_array($ins)) { $n++; }
        }
    };
    if (!empty($program['main']) && is_array($program['main'])) { $walk($program['main']); }
    foreach (($program['funcs'] ?? []) as $seg) { if (is_array($seg)) { $walk($seg); } }
    foreach (($program['classes'] ?? []) as $cls) {
        foreach (($cls['methods'] ?? []) as $seg) { if (is_array($seg)) { $walk($seg); } }
    }
    return $n;
}

$cases = [
    'for_sum'        => '$s=0;for($i=1;$i<=10;$i++){$s+=$i;}echo $s;',
    'while_sum'      => '$s=0;$i=1;while($i<=10){$s+=$i;$i++;}echo $s;',
    'func_sum'       => 'function f($n){$s=0;for($i=1;$i<=$n;$i++){$s+=$i;}return $s;}echo f(10);',
    'recursion'      => 'function f($n){return $n<=1?1:$n*f($n-1);}echo f(5);',
    'fib'            => 'function fib($n){$a=0;$b=1;for($i=0;$i<$n;$i++){$t=$a+$b;$a=$b;$b=$t;}return $a;}echo fib(10);',
    'cls_method'     => 'class Box{public $v=0;function set($x){$this->v=$x;}}$b=new Box();$b->set(42);echo $b->v;',
    'nested_loop'    => 'for($i=0;$i<3;$i++){for($j=0;$j<3;$j++){echo $i.$j;}}',
    'foreach_kv'     => '$a=["x"=>1,"y"=>2,"z"=>3];$s=0;foreach($a as $k=>$v){$s+=$v;}echo $s;',
    'bw'             => 'echo (6&3)." ".(6|1)." ".(6^2)." ".(1<<4)." ".(16>>2);',
    'ternary_loop'   => '$s=0;for($i=0;$i<10;$i++){$s+=$i>5?$i:0;}echo $s;',
];

$modes = [
    'linear'       => ['flatten' => false, 'stateMachine' => false],
    'flatten'      => ['flatten' => true,  'stateMachine' => false],
    'stateMachine' => ['flatten' => false, 'stateMachine' => true],
];
$junkLevels = [0, 3, 6, 12];

$gen = new VmRuntimeGenerator(new Salt('vmjunk-test-seed'));
$total = 0; $fail = 0; $injectOk = 0; $injectCheck = 0;

foreach ($cases as $name => $code) {
    $orig = runOrig($code);
    if (str_starts_with($orig, 'ERR:')) {
        echo "  [$name] ORIG-ERR $orig (跳过)\n";
        continue;
    }
    foreach ($modes as $mode => $modeOpts) {
        // 先取 junk=0 的 Program 操作码基数
        $baseProg = $gen->buildArtifact($code, $modeOpts + ['junk' => 0])['program'];
        $baseOps = countOps($baseProg);
        foreach ($junkLevels as $j) {
            $total++;
            $opts = $modeOpts + ['junk' => $j];
            try {
                $art = $gen->buildArtifact($code, $opts);
                $vm = $gen->execute($code, $opts);
            } catch (Throwable $e) {
                $fail++;
                echo "  [$name/$mode/junk=$j] EXC " . get_class($e) . ': ' . $e->getMessage() . "\n";
                continue;
            }
            // 注入增量断言（仅当 junk>0 时）
            if ($j > 0) {
                $injectCheck++;
                $gotOps = countOps($art['program']);
                if ($gotOps > $baseOps) { $injectOk++; }
                else { $fail++; echo "  [$name/$mode/junk=$j] INJECT-FAIL base=$baseOps got=$gotOps\n"; }
            }
            if ($vm === $orig) {
                echo "  [$name/$mode/junk=$j] PASS\n";
            } else {
                $fail++;
                echo "  [$name/$mode/junk=$j] MISMATCH orig=" . json_encode($orig) . " vm=" . json_encode($vm) . "\n";
            }
        }
    }
}

echo "\n=== 注入增量检查：$injectOk/$injectCheck 通过 ===\n";
echo "TOTAL=$total FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
