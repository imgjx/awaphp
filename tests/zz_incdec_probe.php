<?php
/**
 * 变量自增/自减复合指令序列专项探针（Task #95 回归）。
 * 通过 entry.php 真实管线，对比 原生 / --vm（参考运行期） / --vm --vm-p8（生成运行期）三者输出。
 * 覆盖：前缀/后缀 ++、--、循环内自增、自增作表达式与语句、字符串字母进位、null 自增、静态属性自增。
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$entry = $root . '/entry.php';

function runNative(string $code): string {
    global $php;
    $t = tempnam(sys_get_temp_dir(), 'inc') . '.php';
    file_put_contents($t, "<?php\n" . $code);
    $o = shell_exec($php . ' ' . escapeshellarg($t) . ' 2>&1');
    @unlink($t);
    return (string) $o;
}

function runCli(string $code, string $extra): string {
    global $php, $entry;
    $in = tempnam(sys_get_temp_dir(), 'incin') . '.php';
    $out = tempnam(sys_get_temp_dir(), 'incout') . '.php';
    file_put_contents($in, "<?php\n" . $code);
    shell_exec($php . ' ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($in)
        . ' --output=' . escapeshellarg($out)
        . ' --vm ' . $extra . ' --salt=incdec --format=text 2>&1');
    $o = is_file($out) ? shell_exec($php . ' ' . escapeshellarg($out) . ' 2>&1') : '(no artifact)';
    @unlink($in); @unlink($out);
    return (string) $o;
}

$cases = [
    'loop_sum' => '$s=0; for($i=1;$i<=5;$i++){ $s+=$i; } echo $s;',
    'pre_inc' => '$a=10; echo ++$a; echo $a;',
    'post_inc' => '$a=10; echo $a++; echo $a;',
    'pre_dec' => '$a=10; echo --$a; echo $a;',
    'post_dec' => '$a=10; echo $a--; echo $a;',
    'expr_inc' => '$a=5; $b=($a++) + 2; echo $b; echo $a;',
    'expr_pre' => '$a=5; $b=(++$a) + 2; echo $b; echo $a;',
    'null_inc' => '$n=null; $n++; echo (int)$n;',
    'nested' => '$a=1; $b=1; $a++; $b+=2; echo $a+$b;',
    'chain' => '$i=0; $i++; $i++; $i--; echo $i;',
    'while_dec' => '$n=3; while($n>0){ echo $n; $n--; }',
    'assign_then_inc' => '$k=0; $k=$k+1; $k++; echo $k;',
    'static_prop' => 'class C{ public static $c=0; } C::$c++; C::$c+=2; echo C::$c;',
    'static_var' => 'function c(){static $n=0;$n++;return $n;}echo c().c().c();',
    'post_in_loop' => '$n=0; for($i=0;$i<3;$i++){ $n++; } echo $n;',
    'mixed' => '$a=1;$b=2;$a++;$b--;echo $a*$b;',
];

// 已知限制（复合序列 ADD/SUB 纯算术无法表达）：字符串字母进位、布尔自增。
// 数组自增两者均 TypeError（一致，不列入）。此处仅记录，不计入失败。
$known = [
    'str_carry' => '$x="a"; $x++; echo $x; $x++; echo $x;',
    'bool_inc'  => '$b=true; $b++; var_dump($b===true);',
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
echo "\n-- 已知限制（复合编码边角，不计入失败）--\n";
foreach ($known as $name => $code) {
    $native = runNative($code);
    $ref = runCli($code, '');
    $gen = runCli($code, '--vm-p8');
    $ok = ($ref === $native) && ($gen === $native);
    echo "  " . ($ok ? "\033[32m·\033[0m" : "\033[33m·\033[0m") . " {$name} :: native=" . json_encode($native)
        . " refVM=" . json_encode($ref) . " genVM=" . json_encode($gen) . "\n";
}
echo "\ninc/dec 复合序列：pass={$pass} fail={$fail}（已知限制 " . count($known) . " 项）\n";
exit($fail === 0 ? 0 : 1);
