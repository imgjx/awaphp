<?php
/**
 * P8 五个架构方向回归测试（arch_key_chain / arch_builtin_replace / arch_morph_runtime /
 * arch_fake_fog / arch_probe_matrix）。
 *
 * 两层验证：
 *   1) 语义保真：各特性单独 / 组合 / 叠加 flatten|stateMachine，VM 运行输出与原生一致；
 *   2) 反分析实效：每个特性确实改变了产物结构（而非空操作）——
 *      keychain 只存 seed、builtinReplace 包内无明文内置名、morph 派发含非零第二 scramble
 *      且跨 salt 多态、fakeFog 注入 N 个诱饵、probe 含 crc 自校验且不影响正常输出。
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

function p8_runOrig(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'p8o') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), [1=>['pipe','w'],2=>['pipe','w']], $p);
    $out = stream_get_contents($p[1]); proc_close($proc); @unlink($tmp);
    return $out;
}

$pass = 0; $fail = 0;
function ok(bool $cond, string $label, ?string $detail = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== null ? "  [$detail]" : '') . "\n"; }
}

$cases = [
    'loop_sum'   => '$s=0;for($i=1;$i<=50;$i++){$s+=$i;}echo $s;',
    'while_fib'  => 'function fib($n){$a=0;$b=1;for($i=0;$i<$n;$i++){$t=$a+$b;$a=$b;$b=$t;}return $a;}echo fib(12);',
    'native_str' => 'echo strlen("hello world").",".md5("abc");',
    'native_b64' => 'echo base64_encode("secret")."|".base64_decode("c2VjcmV0");',
    'native_arr' => '$a=[3,1,2];sort($a);echo implode(",",$a).",".count($a);',
    'class_box'  => 'class Box{public $v=0;function set($x){$this->v=$x*2;}function get(){return $this->v;}}$b=new Box();$b->set(21);echo $b->get();',
    'str_ops'    => 'echo strtoupper("abc")."-".strrev("xyz")."-".ord("A");',
    'nested'     => 'for($i=0;$i<3;$i++){for($j=0;$j<2;$j++){echo $i.$j;}}',
];

$gen = new VmRuntimeGenerator(new Salt('p8-test'));

echo "== 语义保真：特性 × 用例 ==\n";
$featureSets = [
    'keychain'       => ['keychain'=>true],
    'builtinReplace' => ['builtinReplace'=>true],
    'morph'          => ['morph'=>true],
    'fakeFog'        => ['fakeFog'=>5],
    'probe'          => ['probe'=>4],
    'all_linear'     => ['keychain'=>true,'builtinReplace'=>true,'morph'=>true,'fakeFog'=>4,'probe'=>3],
    'all_flatten'    => ['keychain'=>true,'builtinReplace'=>true,'morph'=>true,'fakeFog'=>4,'probe'=>3,'flatten'=>true],
    'all_sm'         => ['keychain'=>true,'builtinReplace'=>true,'morph'=>true,'fakeFog'=>4,'probe'=>3,'stateMachine'=>true],
];
foreach ($featureSets as $fname => $feat) {
    foreach ($cases as $cname => $code) {
        $orig = p8_runOrig($code);
        try { $vm = $gen->execute($code, $feat); }
        catch (Throwable $e) { ok(false, "$fname/$cname", get_class($e).': '.$e->getMessage()); continue; }
        ok($vm === $orig, "$fname/$cname", 'orig='.json_encode($orig).' vm='.json_encode($vm));
    }
}

echo "== 反分析实效断言 ==\n";
$src = 'echo strlen("hi");echo md5("x");echo base64_encode("y");';

// —— keychain：KEYS 仅存 master seed（单元素）——
$kc = $gen->buildArtifact('echo 1;', ['keychain'=>true, 'chunks'=>3]);
preg_match('/\$___KEYS___=(.*?);\$___K___/s', $kc['php'], $km);
$seedCount = preg_match_all('/=>\s*\'/', $km[1] ?? '');
ok($seedCount === 1, 'keychain 仅存 1 个 master seed', "seedCount=$seedCount");
// 非 keychain 每片一钥（>=1，通常=chunks）
$nk = $gen->buildArtifact('echo 1;', ['chunks'=>3]);
preg_match('/\$___KEYS___=(.*?);\$___RAW___/s', $nk['php'], $nm);
ok(preg_match_all('/=>\s*\'/', $nm[1] ?? '') >= 1, '非 keychain 每片一钥（旧行为保持）');

// —— builtinReplace：解压后的字节码 blob 不含明文内置名 ——
$br = $gen->buildArtifact($src, ['builtinReplace'=>true]);
preg_match_all('/base64_decode\(\'([A-Za-z0-9+\/=]{40,})\'\)/', $br['php'], $bm);
$leak = false;
foreach ($bm[1] as $b64) {
    $gz = @gzdecode(base64_decode($b64));
    if ($gz !== false && $gz !== '' && (strpos($gz,'strlen')!==false || strpos($gz,'md5')!==false || strpos($gz,'base64_encode')!==false)) { $leak = true; }
}
ok(!$leak, 'builtinReplace 字节码 blob 无明文 strlen/md5/base64_encode');
// program 逻辑层 natTbl 仍保留原名（供 encode 前的 packProgram 消费）
ok(in_array('strlen', array_keys($br['program']['natTbl']), true), 'builtinReplace program natTbl 保留原名');

// —— morph：baseline 派发 ^0，morph 派发 ^非零；跨 salt 多态 ——
$base = $gen->buildArtifact('echo 1;', [])['php'];
$morph = $gen->buildArtifact('echo 1;', ['morph'=>true])['php'];
preg_match('/\)\[\$[^\]\^]*\^(\d+)\]\?\?0;/', $base, $bx);
preg_match('/\)\[\$[^\]\^]*\^(\d+)\]\?\?0;/', $morph, $mx);
ok(($bx[1] ?? null) === '0', 'morph 关闭时派发 ^0（默认行为不变）', 'got='.($bx[1]??'?'));
ok(isset($mx[1]) && (int)$mx[1] !== 0, 'morph 开启时派发 ^非零第二 scramble', 'got='.($mx[1]??'?'));
$morph2 = (new VmRuntimeGenerator(new Salt('p8-other')))->buildArtifact('echo 1;', ['morph'=>true])['php'];
ok($morph2 !== $morph, 'morph 跨 salt 产物结构不同（多态）');

// —— fakeFog：注入 N 个 _fg_ 诱饵，且含 funcParams 规格 ——
$ff = $gen->buildArtifact('function real(){return 1;}echo real();', ['fakeFog'=>6]);
$fg = 0; foreach (array_keys($ff['program']['funcs']) as $fn) { if (strpos($fn,'_fg_')===0) $fg++; }
ok($fg === 6, 'fakeFog 注入 6 个诱饵函数', "got=$fg");
ok(isset($ff['program']['funcs']['real']), 'fakeFog 不影响真实函数');

// —— probe：含 crc 自校验与静默停止，且正常输出不受影响 ——
$pr = $gen->buildArtifact('echo 42;', ['probe'=>3]);
ok(strpos($pr['php'], 'crc32($___RAW___)') !== false, 'probe 含字节码 crc 自校验');
ok(strpos($pr['php'], '&&exit(0);') !== false, 'probe 命中即静默 exit');
ok(strpos($pr['php'], 'extension_loaded') !== false, 'probe 含调试器/hook 检测');
ok($gen->execute('echo 42;', ['probe'=>3]) === p8_runOrig('echo 42;'), 'probe 不影响正常输出');

echo "\nP8 TOTAL pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
