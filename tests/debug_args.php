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

$code = 'function f($a,$b){return $a-$b;}echo f(b:1,a:2);';
$comp = new VmCompiler();
$prog = $comp->compile($code);
echo "funcs: " . implode(',', array_keys($prog['funcs'])) . "\n";
echo "funcParams[f]: " . json_encode($prog['funcParams']['f'] ?? null) . "\n";
echo "main: " . json_encode($prog['main']) . "\n";

// 检查 sigCache（通过反射）
$rc = new ReflectionClass($comp);
$p = $rc->getProperty('sigCache');
$p->setAccessible(true);
echo "sigCache: " . json_encode($p->getValue($comp)) . "\n";
