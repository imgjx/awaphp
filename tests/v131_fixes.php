<?php
/**
 * v1.3.1 修复回归：真实管线（--vm / --vm --vm-p8）对比原生。
 *
 * 覆盖本次修复的「此前不支持 / 静默算错」项：
 *   1. 动态属性读取/写入 $o->$k（var 改名层现自动保留属性名）
 *   2. compact() / get_defined_vars()（此前 "Cannot call ... dynamically" 致命）
 *   3. by-ref 原生函数：parse_str / similar_text / preg_replace($count)
 *
 * 对比前归一化诊断信息（PHP Warning/Deprecated 行、调用栈、临时文件路径），
 * 只比较用户输出与是否致命——值语义才是目标。
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$entry = $root . '/entry.php';

function norm(string $s): string {
    $body = []; $fatal = '';
    foreach (preg_split('/\r?\n/', $s) as $ln) {
        if (preg_match('/PHP (Warning|Notice|Deprecated)/i', $ln)) { continue; }
        if (preg_match('/PHP (Fatal error|Parse error)|^PHP Stack trace:|^(Stack trace:|#\d+ )|^\s+thrown /i', $ln)) { $fatal = 'FATAL'; continue; }
        $ln = preg_replace('#/[^\s:()]+\.php#', '<FILE>', $ln);
        $body[] = $ln;
    }
    return $fatal . '|' . implode("\n", $body);
}

function runNative(string $code): string {
    global $php;
    $t = tempnam(sys_get_temp_dir(), 'v131n') . '.php';
    file_put_contents($t, "<?php\n" . $code);
    $o = shell_exec($php . ' ' . escapeshellarg($t) . ' 2>&1');
    @unlink($t);
    return norm((string) $o);
}

function runCli(string $code, string $extra): string {
    global $php, $entry;
    $in = tempnam(sys_get_temp_dir(), 'v131i') . '.php';
    $out = tempnam(sys_get_temp_dir(), 'v131o') . '.php';
    file_put_contents($in, "<?php\n" . $code);
    $log = shell_exec($php . ' ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($in)
        . ' --output=' . escapeshellarg($out)
        . ' --vm ' . $extra . ' --salt=v131fix --format=text 2>&1');
    if (!is_file($out)) { @unlink($in); return 'NO_ARTIFACT|' . (string) $log; }
    $o = shell_exec($php . ' ' . escapeshellarg($out) . ' 2>&1');
    @unlink($in); @unlink($out);
    return norm((string) $o);
}

$cases = [
    'dyn_prop_read'   => 'class E1 { public $n="hi"; } $o=new E1(); $k="n"; echo $o->$k;',
    'dyn_prop_write'  => 'class E2 { public $n="hi"; } $o=new E2(); $k="n"; $o->$k="yo"; echo $o->n;',
    'dyn_method'      => 'class E3 { function go(){return 7;} } $o=new E3(); $m="go"; echo $o->$m();',
    'compact_fn'      => '$x=1; $y=2; $r=compact("x","y"); echo $r["x"].$r["y"];',
    'gdv_count'       => '$a=1; $b=2; echo count(get_defined_vars())>0 ? "y" : "n";',
    'parse_str'       => 'parse_str("a=1&b=2", $out); echo $out["a"].$out["b"];',
    'similar_text'    => 'similar_text("World", "Word", $p); echo (int) round($p);',
    'preg_replace_cnt' => '$n=0; $r=preg_replace("/a/","b","aaa",-1,$n); echo $r."|".$n;',
    'closure_ref_param' => '$f=function(&$x){$x++;}; $n=1; $f($n); echo $n;',
    'closure_ref_default' => '$f=function(&$x,$y=5){$x+=$y;}; $n=1; $f($n); echo $n;',
    'closure_ref_capture' => '$b=10; $f=function(&$x) use($b){$x+=$b;}; $n=1; $f($n); echo $n;',
    'array_walk_closure' => '$a=[1,2]; array_walk($a,function(&$v,$k){$v+=$k;}); echo $a[0].$a[1];',
    'array_walk_named' => 'function v131dbl(&$v){$v*=2;} $a=[1,2]; array_walk($a,"v131dbl"); echo $a[0].$a[1];',
];

$pass = 0; $fail = 0;
foreach ($cases as $name => $code) {
    $native = runNative($code);
    $ref = runCli($code, '');
    $gen = runCli($code, '--vm-p8');
    $ok = ($ref === $native) && ($gen === $native);
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m {$name}\n"; }
    else {
        $fail++;
        echo "  \033[31m✗\033[0m {$name}\n";
        echo "     native=" . json_encode($native) . "\n";
        echo "     refVM =" . json_encode($ref) . "\n";
        echo "     genVM =" . json_encode($gen) . "\n";
    }
}
echo "\nv1.3.1 修复回归：pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
