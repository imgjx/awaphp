<?php
declare(strict_types=1);
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';

$toks = Parser::tokenize('<?php function f($a,$b){return $a-$b;}');
$body = [];
foreach ($toks as $t) {
    if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) { continue; }
    $body[] = $t;
}
$stmts = StatementSplitter::split($body);
echo "stmts count: " . count($stmts) . "\n";
foreach ($stmts as $i => $s) {
    echo "stmt $i: ";
    foreach ($s as $t) {
        echo is_array($t) ? token_name($t[0]) . '(' . ($t[1] ?? '') . ') ' : "'$t' ";
    }
    echo "\n";
}

$comp = new VmCompiler();
$rc = new ReflectionClass($comp);
$m = $rc->getMethod('prescanSignature');
$m->setAccessible(true);
$m->invoke($comp, $stmts[0]);
$p = $rc->getProperty('sigCache');
$p->setAccessible(true);
echo "sigCache after direct invoke: " . json_encode($p->getValue($comp)) . "\n";
