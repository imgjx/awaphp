<?php
declare(strict_types=1);
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

function t(string $code): void {
    echo "--- ", $code, "\n";
    $p = (new VmCompiler())->compile($code);
    ob_start(); __VMRUN__($p); $vm = ob_get_clean();
    $tmp = tempnam(sys_get_temp_dir(), 'r') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $orig = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    echo "  vm=", var_export($vm, true), " orig=", var_export($orig, true),
        $vm === $orig ? "  OK\n" : "  *** DIFF\n";
}

t('$a=1;$r=[&$a];$r[0]=42;echo $a;');
t('$a=1;$b=2;$r=[&$a,&$b];$r[0]=42;$r[1]=43;echo $a.$b;');
t('$a=[[1]];$r=[&$a[0][0]];$r[0]=42;echo $a[0][0];');
t('$a=[[1]];$b=2;$r=[&$a[0][0],&$b];$r[0]=42;$r[1]=43;echo $a[0][0].$b;');
t('$a=[[1]];$b3=7;$r=[&$a[0][0],&$b3];$r[0]=42;$r[1]=43;echo $a[0][0].$b3;');
