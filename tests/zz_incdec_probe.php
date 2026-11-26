<?php
/**
 * 变量自增/自减专项探针（Task #95 回归；v1.3.1 恢复变量专用码后扩展）。
 * 通过 entry.php 真实管线，对比 原生 / --vm（参考运行期） / --vm --vm-p8（生成运行期）三者输出。
 * 覆盖：前缀/后缀 ++、--、循环内自增、自增作表达式与语句、字符串字母进位、布尔自增、
 * null 自增、静态属性自增、引用别名自增。
 *
 * 对比前归一化诊断信息：自增失败（如对数组 ++）时原生/VM 的告警文本与文件名天然不同，
 * 但值语义才是目标，故只比较用户输出与是否致命，忽略 Warning/Deprecated 行与调用栈。
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

function norm(string $s): string {
    $body = []; $sev = '';
    foreach (preg_split('/\r?\n/', $s) as $ln) {
        if (preg_match('/PHP (Warning|Notice|Deprecated)/i', $ln)) { continue; }
        if (preg_match('/PHP (Fatal error|Parse error)|^PHP Stack trace:|^(Stack trace:|#\d+ )|^\s+thrown /i', $ln)) { $sev = 'FATAL'; continue; }
        $ln = preg_replace('#/[^\s:()]+\.php#', '<FILE>', $ln);
        $body[] = $ln;
    }
    return $sev . '|' . implode("\n", $body);
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
    // v1.3.1：变量专用码恢复后，以下 PHP 原生边角语义也已支持，纳入必过项。
    'str_carry' => '$x="a"; $x++; echo $x; $x++; echo $x;',
    'str_wrap' => '$x="z"; $x++; echo $x;',
    'str_alpha' => '$x="Az"; $x++; echo $x;',
    'bool_inc'  => '$b=true; $b++; var_dump($b===true);',
    'bool_dec'  => '$b=false; $b--; var_dump($b===false);',
    'float_inc' => '$f=1.5; ++$f; echo $f;',
    'null_inc_v' => '$z=null; $z++; var_dump($z);',
    'ref_inc'   => '$a=1; $b=&$a; $b++; echo $a;',
];

$pass = 0; $fail = 0;
foreach ($cases as $name => $code) {
    $native = norm(runNative($code));
    $ref = norm(runCli($code, ''));
    $gen = norm(runCli($code, '--vm-p8'));
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
echo "\ninc/dec 语义：pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
