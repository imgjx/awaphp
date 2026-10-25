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

use VmOp;
$code = 'class C{public $v=1;public function inc(){return $this->v+1;}}echo (new C())->inc();';
$compiler = new VmCompiler();
$program = $compiler->compile($code);
echo "CLASSES:\n";
var_export($program['classes']);
echo "\n\nMAIN:\n";
$rc = new ReflectionClass(VmOp::class);
foreach ($program['main'] as $i => $ins) {
    $name = array_search($ins[0], $rc->getConstants(), true);
    echo "  $i: $name " . json_encode(array_slice($ins,1)) . "\n";
}
