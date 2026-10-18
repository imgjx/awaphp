<?php
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';

function t(string $code): string {
    $c = preg_replace('/^\s*<\?php/', '', $code);
    $p = (new VmCompiler())->compile($c);
    ob_start();
    __VMRUN__($p);
    return (string) ob_get_clean();
}

$cases = [
    'E1' => "<?php class C { public \$x; public function __construct(\$v){ \$this->x=\$v; } public function g(){ return \$this->x; } } \$c=new C('HI'); echo \$c->g();",
    'E2' => "<?php class C { public \$x='def'; public function g(){ return \$this->x; } } \$c=new C(); echo \$c->g();",
    'E3' => "<?php class C { public function set(\$v){ \$this->x=\$v; } public function g(){ return \$this->x; } } \$c=new C(); \$c->set('ZZ'); echo \$c->g();",
    'E4' => "<?php class C { private string \$name; public function __construct(string \$name) { \$this->name = \$name; } public function hello(): string { return 'hi '.\$this->name; } } \$g=new C('awa'); echo \$g->hello();",
];
foreach ($cases as $k => $code) {
    echo $k . ': ';
    try { echo t($code) . "\n"; }
    catch (Throwable $e) { echo "ERR " . $e->getMessage() . "\n"; }
}
