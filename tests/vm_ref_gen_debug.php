<?php
declare(strict_types=1);
$root = '/workspace';
foreach ([
    'src/Salt.php','src/SemanticRewriter.php','src/Support/Random.php','src/Support/NameGenerator.php',
    'src/Support/NameStyle.php','src/Support/DynamicSymbols.php','src/Logger.php','src/Config.php',
    'src/Parser.php','src/Validator.php','src/Obfuscator.php','src/Variable/VariableObfuscator.php',
    'src/Function_/FunctionObfuscator.php','src/Function_/FunctionCallEncoder.php','src/Class_/ClassObfuscator.php',
    'src/String/StringEncoder.php','src/Number/NumberEncoder.php','src/Encode/HtmlEncoder.php','src/Encode/GzEncoder.php',
    'src/Optimize/CommentRemover.php','src/Optimize/DeadCodeInjector.php','src/Optimize/Whitespace.php',
    'src/Optimize/StatHook.php','src/Junk/GeneralJunk.php','src/Optimize/OpcacheCompiler.php',
    'src/Token/TokenObfuscator.php','src/Layer/LayerRegistry.php','src/Layer/LayerDescriptor.php',
    'src/Layer/CodeInjector.php','src/Watermark/Copyright.php','src/Support/StatementSplitter.php',
    'src/Support/PhpVersion.php','src/ControlFlow/GotoObfuscator.php','src/ControlFlow/ControlFlowObfuscator.php',
    'src/ControlFlow/VirtualMachine.php','src/Vm/Op.php','src/Vm/Compiler.php','src/Vm/RuntimeGenerator.php',
    'src/Vm/IncludeResolver.php','src/Vm/ModuleLinker.php','src/Vm/VmBytecodeJunk.php','src/Vm/VmFakeFog.php',
    'src/Vm/VmProbeMatrix.php','src/ControlFlow/EvalShell.php','src/ControlFlow/AntiDebug.php',
    'src/ControlFlow/AntiHookEval.php','src/ControlFlow/LicenseGuard.php','src/ControlFlow/AntiTamper.php',
    'src/ControlFlow/JunkFunctions.php','src/AntiDetect/StringRuntimeAssembler.php','src/Project/SymbolTable.php',
    'src/Project/ProjectBuilder.php',
] as $f) { require_once $root . '/' . $f; }

function t(string $code, array $opts, string $tag): void {
    $art = (new VmRuntimeGenerator(new Salt('st1')))->buildArtifact($code, $opts);
    $tmp = tempnam(sys_get_temp_dir(), 'rg') . '.php';
    file_put_contents($tmp, $art['php']);
    $vm = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $t2 = tempnam(sys_get_temp_dir(), 'ro') . '.php';
    file_put_contents($t2, "<?php\n" . $code);
    $orig = shell_exec('php ' . escapeshellarg($t2) . ' 2>&1');
    @unlink($t2);
    echo "[", $tag, "] vm=", var_export($vm, true), " orig=", var_export($orig, true),
        $vm === $orig ? "  OK\n" : "  *** DIFF\n";
}

$code = '$a0=2;$a3=[[1]];$b3=&$a3[0][0];$b3=7;$r=[&$a3[0][0],&$a0];$r[0]=42;$r[1]=43;echo $a3[0][0].$a0;';
$base = ['flatten' => false, 'stateMachine' => false, 'chunks' => 3, 'cpool' => true];
t($code, $base, 'plain');
t($code, $base + ['flatten' => true], 'flatten');
t($code, $base + ['cpool' => false], 'nocpool');
t($code, $base + ['flatten' => true, 'cpool' => false], 'flat+nocpool');
