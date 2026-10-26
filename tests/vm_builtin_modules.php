<?php
declare(strict_types=1);
/**
 * 内置模块（src/Module/builtin）专项回归：
 * 对每个常用模块，用多种输入比对「原生执行」与「VM 内联执行」输出，必须逐字节一致；
 * 并断言模块被 ModuleLinker 命中（hits 含函数名），证明已被内联而非回退原生调用。
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/ModuleLinker.php';

$MODDIR = __DIR__ . '/../src/Module/builtin';

function runNative(string $code): string
{
    return (string) shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
}

function runVm(string $code): string
{
    global $MODDIR;
    $art = (new VmRuntimeGenerator())->buildArtifact($code, [
        'baseDir'   => sys_get_temp_dir(),
        'curFile'   => sys_get_temp_dir() . '/p_mod.php',
        'moduleDir' => $MODDIR,
    ]);
    $tmp = tempnam(sys_get_temp_dir(), 'vmbm') . '.php';
    file_put_contents($tmp, $art['php']);
    $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

function buildVm(string $code): array
{
    global $MODDIR;
    return (new VmRuntimeGenerator())->buildArtifact($code, [
        'baseDir'   => sys_get_temp_dir(),
        'curFile'   => sys_get_temp_dir() . '/p_mod.php',
        'moduleDir' => $MODDIR,
    ]);
}

// fn => [ [args-source, ...], ... ]
$cases = [
    'strlen'       => [['"hello"'], ['""'], ['"a b c"'], ['"12345"']],
    'substr'       => [['"hello",1,3'], ['"hello",-2'], ['"hello",0,-1'], ['"hello",10'], ['"abcdef",2,2']],
    'strpos'       => [['"hello","l"'], ['"hello","x"'], ['"hello","l",3'], ['"hello","ll"']],
    'strtolower'   => [['"HELLO World"'], ['"AbC123"']],
    'strtoupper'   => [['"hello World"'], ['"AbC"']],
    'trim'         => [['"  hi  "'], ['"'.chr(9).chr(10).'x'.chr(13).'"'], ['"xyx","x"'], ['"  a b  "']],
    'ltrim'        => [['"  hi"'], ['"xxabc","x"']],
    'rtrim'        => [['"hi  "'], ['"abcxx","x"']],
    'strrev'       => [['"hello"'], ['"abc"'], ['""']],
    'str_repeat'   => [['"ab",3'], ['"x",0'], ['"-",5']],
    'str_pad'      => [['"x",5'], ['"x",5,".",1'], ['"x",6,"ab",2'], ['"ab",1']],
    'ord'          => [['"A"'], ['"z"'], ['"0"']],
    'chr'          => [['65'], ['97'], ['0']],
    'explode'      => [['",","a,b,c"'], ['"-","1-2-3-4"'], ['",","a,b,c",2']],
    'implode'      => [['",",["a","b","c"]'], ['["a","b"]'], ['"-",[1,2,3]']],
    'strcmp'       => [['"a","b"'], ['"b","a"'], ['"a","a"'], ['"ab","a"']],
    'array_reverse'=> [['[1,2,3]'], ['["a"=>1,"b"=>2],true'], ['[]']],
    'array_merge'  => [['[1,2],[3,4]'], ['[1,2],["x"=>"y"]'], ['[1,2],[3]']],
    'array_keys'   => [['["a"=>1,"b"=>2]'], ['[1,2,3]']],
    'array_values' => [['["a"=>1,"b"=>2]'], ['[1,2,3]']],
    'in_array'     => [['2,[1,2,3]'], ['"2",[1,2,3]'], ['2,[1,2,3],true'], ['"2",[1,2,3],true']],
    'array_sum'    => [['[1,2,3,4]'], ['[1.5,2.5]'], ['[]']],
    'array_unique' => [['[1,1,2,3,3]'], ['["a","a","b"]']],
    'abs'          => [['-5'], ['5'], ['-3.5'], ['0']],
    'round'        => [['2.5'], ['2.4'], ['-2.5'], ['3.14159,2']],
    'number_format'=> [['1234.567'], ['1234567'], ['1234.5'], ['0'], ['-1234.567,2']],
    'base_convert' => [['"255",10,16'], ['"ff",16,10'], ['"1010",2,10'], ['"z",36,10']],
    'urlencode'    => [['"hello world"'], ['"a/b?c=d&e"'], ['"foo bar+baz"']],
    'urldecode'    => [['"hello+world"'], ['"a%20b%2Fc"'], ['"foo+bar"']],
    'rawurlencode' => [['"hello world"'], ['"a/b?c=d&e"'], ['"foo bar+baz"']],
];

$total = 0;
$fail = 0;
$skipped = 0;
$notLinked = [];
foreach ($cases as $fn => $argsList) {
    foreach ($argsList as $row) {
        $args = $row[0];
        $total++;
        $code = "echo json_encode($fn($args));";
        $native = runNative($code);
        $vm = runVm($code);
        $ok = ($native === $vm);
        if (!$ok) {
            $fail++;
            echo "  [FAIL] {$fn}({$args}) native=[" . trim($native) . "] vm=[" . trim($vm) . "]\n";
        }
    }
    // 链接断言：每个函数应至少在其某次调用中被命中
    $art = buildVm("echo json_encode($fn(" . ($argsList[0][0] ?? '""') . "));");
    if (!isset($art['linker']['hits'][$fn])) {
        $notLinked[] = $fn;
    }
}

echo "\n==============================================\n";
echo "模块回归：用例={$total} 失败={$fail} 未链接=" . count($notLinked) . "\n";
if ($notLinked !== []) {
    echo "  [WARN] 以下模块未被链接（调用走原生分发）：" . implode(', ', $notLinked) . "\n";
}
if ($fail === 0 && $notLinked === []) {
    echo "ALL PASS ✅\n";
    exit(0);
}
exit(1);
