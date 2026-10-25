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

// 用子类暴露 protected 逻辑：直接 new 后调用 compile 看 sigCache
$comp = new VmCompiler();
$prog = $comp->compile('function f($a,$b){return $a-$b;}echo f(b:1,a:2);');
$rc = new ReflectionClass($comp);
foreach (['sigCache','funcNames','funcs'] as $pn) {
    $p = $rc->getProperty($pn);
    $p->setAccessible(true);
    $v = $p->getValue($comp);
    echo "$pn: " . (is_array($v) ? json_encode($v) : var_export($v, true)) . "\n";
}
echo "prog funcs keys: " . implode(',', array_keys($prog['funcs'])) . "\n";
echo "prog main: " . json_encode($prog['main']) . "\n";
