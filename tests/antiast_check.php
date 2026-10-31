<?php
/**
 * 深度反 AST（anti_ast）回归：11 个用例，逐字节等价
 *
 * 每个用例把源码经 AntiAst 改写后落盘、php -l 校验、执行取输出，与原生输出逐字节比对。
 * 覆盖：单字符 / 多字符 / 含引号转义 / 中文 / print / return / 函数内 return、
 *       多位整数、多整数连续输出、多字符串连续输出、if 分支内输出。
 */
declare(strict_types=1);
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Logger.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/AntiAst/IdentityExpr.php';
require __DIR__ . '/../src/AntiAst/AntiAst.php';

$pass = 0;
$fail = 0;

function run_case(string $src, int &$pass, int &$fail): void
{
    $cfg = new Config(['anti_ast' => true]);
    $aa = new AntiAst(new Salt(''), new Logger());
    $tokens = Parser::tokenize($src);
    $out = $aa->process($tokens, $cfg);
    $code = '';
    foreach ($out as $t) {
        $code .= is_array($t) ? $t[1] : $t;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'aa') . '.php';
    file_put_contents($tmp, $code);
    $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($tmp) . ' 2>&1');
    if (strpos((string) $lint, 'No syntax errors') === false) {
        echo "FAIL(SYNTAX) | {$src}\n--- code ---\n{$code}\n--- lint ---\n{$lint}\n";
        @unlink($tmp);
        $fail++;
        return;
    }
    $torig = tempnam(sys_get_temp_dir(), 'ao') . '.php';
    file_put_contents($torig, $src);
    $orig = shell_exec(PHP_BINARY . ' ' . escapeshellarg($torig) . ' 2>&1');
    $obf = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($torig);
    $ok = ((string) $orig === (string) $obf);
    echo ($ok ? 'OK  ' : 'FAIL') . " | orig=[" . trim((string) $orig) . '] obf=[' . trim((string) $obf) . "]\n";
    if (!$ok) {
        echo "--- code ---\n{$code}\n";
        $fail++;
    } else {
        $pass++;
    }
    @unlink($tmp);
}

// 字符串：单字符 / 多字符 / 含引号转义 / 中文
run_case("<?php echo 'h';", $pass, $fail);
run_case("<?php echo 'hello world';", $pass, $fail);
run_case("<?php echo 'a\"b\\'c';", $pass, $fail);
run_case("<?php echo '你好，世界';", $pass, $fail);
run_case("<?php print 'x';", $pass, $fail);
run_case("<?php return 'rv';", $pass, $fail);
run_case("<?php function f(){ return 'inside'; } echo 'outside';", $pass, $fail);
// 整数累加器：单值 / 多值 / 连续
run_case("<?php echo 1; echo 123; echo 0;", $pass, $fail);
run_case("<?php echo 255; echo 256; echo 40; echo 32;", $pass, $fail);
run_case("<?php echo 'a'; echo 'bb'; echo 'ccc';", $pass, $fail);
// 分支内输出
run_case("<?php if(true){ echo 'in-if'; } echo 'after';", $pass, $fail);

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
