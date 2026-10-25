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

$code = 'for($i=2;$i<=5;$i++){echo $i;}';
$compiler = new VmCompiler();
$program = $compiler->compile($code);
echo "=== ORIGINAL main (" . count($program['main']) . " ins) ===\n";
foreach ($program['main'] as $i => $ins) {
    $name = array_search($ins[0], (new ReflectionClass(VmOp::class))->getConstants(), true);
    echo "  $i: $name " . json_encode(array_slice($ins, 1)) . "\n";
}

$gen = new VmRuntimeGenerator(new Salt('probe-seed'));
$ref = new ReflectionMethod($gen, 'flattenSegment');
$ref->setAccessible(true);
$flat = $ref->invoke($gen, $program['main']);
echo "\n=== FLATTENED (" . count($flat) . " ins) ===\n";
foreach ($flat as $i => $ins) {
    $name = array_search($ins[0], (new ReflectionClass(VmOp::class))->getConstants(), true);
    echo "  $i: $name " . json_encode(array_slice($ins, 1)) . "\n";
}
file_put_contents('/tmp/flat_for_init.json', json_encode($flat));
