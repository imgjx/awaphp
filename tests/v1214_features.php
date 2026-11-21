<?php
/**
 * v1.2.14 特性回归：循环内轻量注入 + 字符串转义保真（CPU 100% 死循环回归）
 *
 * 覆盖：
 *   A 全链 mid + logic-shell 不再死循环，输出与原生逐字节一致
 *   B 含转义序列的字符串（"\x00" / "\x80"）混淆后字节数不变（曾经被当成 4 字符文本）
 *   C 大循环内的字符串不做运行时 XOR 解码（保留字面量拼接）
 *   D 大循环内的数字走运算符级恒等，不出现函数调用积木
 *   E 小循环（≤ 阈值）仍用完整积木
 *   F 循环密集脚本的运行耗时回到毫秒级
 *   G --no-logic-loop-light 可整体关闭
 *   H 版本号
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failMsg = [];

function t_ok(string $name): void
{
    global $pass;
    $pass++;
    echo "  \033[32m✓\033[0m {$name}\n";
}

function t_no(string $name, string $why): void
{
    global $fail, $failMsg;
    $fail++;
    $failMsg[] = "{$name}: {$why}";
    echo "  \033[31m✗\033[0m {$name} — {$why}\n";
}

function sh(string $cmd): string
{
    return (string) shell_exec($cmd . ' 2>&1');
}


/**
 * 只有混淆器才会生成的函数名（夹具里不会自然出现），用来判「有没有积木」。
 * 不能把 strlen 放进来：夹具本身就写了 strlen('…')，那是用户代码不是积木。
 */
function has_brick_call(string $code): bool
{
    return (bool) preg_match(
        '/\b(sha1|md5|base64_decode|in_array|str_repeat|hex2bin|str_rot13|strrev)\s*\(/',
        $code
    );
}

/**
 * 任意形态积木：函数调用型（含 v1.3.0 的
 * `strlen(hex2bin('…').str_rot13('…').strrev('…'))`），
 * 或 v1.3.0 的十进制分块 + 位掩码型（`((7)|0)`、`((908)*1000000 + …) ^ 0`）。
 */
function has_brick(string $code): bool
{
    if (has_brick_call($code) || preg_match('/\bstrlen\s*\(\s*(hex2bin|str_rot13|strrev)\s*\(/', $code)) {
        return true;
    }
    return (bool) preg_match('/\d+\s*\*\s*(1000|1000000|1000000000)|[\^|&]\s*0\s*\)/', $code);
}

/**
 * 截出循环语句本身（关键字 + 头括号 + 体括号），找不到返回空串。
 * 只取循环本身，避免把循环之后的代码也算进来。
 */
function loop_slice(string $code, string $kw): string
{
    if (!preg_match('/\b' . preg_quote($kw, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $n = strlen($code);
    $q = (int) strpos($code, '(', $m[0][1]);
    $depth = 0;
    $close = -1;
    for ($i = $q; $i < $n; $i++) {
        if ($code[$i] === '(') {
            $depth++;
        } elseif ($code[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                $close = $i;
                break;
            }
        }
    }
    if ($close < 0) {
        return substr($code, (int) $m[0][1]);
    }
    $b = strpos($code, '{', $close);
    if ($b === false) {
        return substr($code, (int) $m[0][1], $close - (int) $m[0][1] + 1);
    }
    $depth = 0;
    $end = -1;
    for ($i = $b; $i < $n; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }
    if ($end < 0) {
        return substr($code, (int) $m[0][1]);
    }
    return substr($code, (int) $m[0][1], $end - (int) $m[0][1] + 1);
}

$tmp = sys_get_temp_dir() . '/v1214_' . getmypid();
@mkdir($tmp, 0777, true);

spl_autoload_register(static function (string $c) use ($root): void {
    foreach (['src/', 'src/LogicTransform/', 'src/AntiAst/', 'src/Support/', 'src/Variable/',
              'src/Number/', 'src/String/', 'src/Layer/', 'src/ControlFlow/', 'src/Vm/',
              'src/Encode/', 'src/Function_/', 'src/Class_/', 'src/Junk/', 'src/Project/',
              'src/Token/', 'src/Module/', 'src/Watermark/', 'src/AntiDetect/', 'src/Confrontation/'] as $d) {
        $p = $root . '/' . $d . $c . '.php';
        if (is_file($p)) {
            require $p;
            return;
        }
    }
});

echo "===== v1.2.14 回归：循环内轻量注入 / 字符串转义 =====" .
     "\n\n";

// ---------------------------------------------------------------------------
// A) 全链 mid + logic-shell：曾经在这里死循环（CPU 100%）
// ---------------------------------------------------------------------------
$fixture = $root . '/tests/fixtures/vm_full_syntax.php';
$nativeOut = sh('php ' . escapeshellarg($fixture));
$build = sh('timeout 180 php ' . escapeshellarg($root . '/entry.php')
    . ' --input=' . escapeshellarg($fixture)
    . ' --output=' . escapeshellarg($tmp . '/full.php')
    . ' --level=mid --logic-shell --salt=v1214a');
if (is_file($tmp . '/full.php')) {
    $run = sh('timeout 15 php ' . escapeshellarg($tmp . '/full.php'));
    if ($run === $nativeOut && $nativeOut !== '') {
        t_ok('A mid + logic-shell：15s 内跑完，输出与原生逐字节一致');
    } else {
        t_no('A mid + logic-shell', '输出不一致或超时：' . substr($run, 0, 120));
    }
} else {
    t_no('A mid + logic-shell', '构建失败：' . substr($build, 0, 200));
}

// ---------------------------------------------------------------------------
// B) 转义字符串的字节数必须保持不变
//    旧实现把 "\x00" 当成 4 个字符，填充循环的步长从 1 变 4 → 永远命不中目标 → 死循环
// ---------------------------------------------------------------------------
$esc = <<<'PHP'
<?php
$m = 'abc' . "\x80";
while (strlen($m) % 4 !== 3) { $m .= "\x00"; }
echo strlen($m), ' ', bin2hex($m), "\n";
$t = "\x00\xff";
echo strlen($t), ' ', bin2hex($t), "\n";
PHP;
file_put_contents($tmp . '/esc.php', $esc);
$escNative = sh('php ' . escapeshellarg($tmp . '/esc.php'));
foreach (['low', 'mid'] as $lvl) {
    sh('php ' . escapeshellarg($root . '/entry.php')
        . ' --input=' . escapeshellarg($tmp . '/esc.php')
        . ' --output=' . escapeshellarg($tmp . "/esc_$lvl.php")
        . " -$lvl --salt=v1214b");
    $got = sh('timeout 10 php ' . escapeshellarg($tmp . "/esc_$lvl.php"));
    if ($got === $escNative && $escNative !== '') {
        t_ok('B ' . $lvl . '：转义字符串字节数不变（' . str_replace("\n", ' / ', trim($escNative)) . '）');
    } else {
        t_no('B ' . $lvl . ' 转义保真', '期望 ' . trim($escNative) . ' 实得 ' . substr(trim($got), 0, 80));
    }
}

// ---------------------------------------------------------------------------
// C/D/E) 直接跑 logic + 字符串层，产物是明文，便于断言
// ---------------------------------------------------------------------------
function pipeline(string $code, array $extra = []): string
{
    $cfg = new Config(array_merge([
        'logic'           => true,
        'logic_intensity' => 3,
        'str_xor'         => true,
    ], $extra));
    $tokens = token_get_all($code);
    $tokens = (new LogicRewriter(new Salt('v1214-test'), new Logger(), 3))->process($tokens, $cfg);
    $tokens = (new StringRuntimeAssembler(new Salt('v1214-test'), new Logger()))->process($tokens, $cfg);
    $s = '';
    foreach ($tokens as $t) {
        $s .= is_array($t) ? $t[1] : $t;
    }
    return $s;
}

$big = <<<'PHP'
<?php
$s = 0;
for ($i = 0; $i < 5000; $i++) {
    $s += strlen('abcdefghijklmnop');
}
echo $s;
PHP;
$outBig = pipeline($big);
$loop = loop_slice($outBig, 'for');

if ($loop !== '' && !has_brick_call($loop)) {
    t_ok('D 大循环：循环头与循环体内无函数调用积木');
} else {
    t_no('D 大循环', '仍有函数调用：' . substr($loop, 0, 160));
}
if (preg_match("/'abcdefgh'\\s*\\.\\s*'ijklmnop'/", $loop)) {
    t_ok('C 大循环：字符串保留为字面量拼接（未做运行时解码）');
} else {
    t_no('C 大循环字符串', '字符串仍被编码：' . substr($loop, 0, 200));
}
if (preg_match('/\(\s*\d+\s*\^\s*0\s*\)|\(\s*0\s*\+\s*\d+\s*\)|\(\s*\(\s*\d+\s*\)\s*\|\s*0\s*\)/', $loop)) {
    t_ok('D2 大循环：数字仍以运算符级恒等形态注入');
} else {
    t_no('D2 大循环恒等', '没看到运算符级恒等：' . substr($loop, 0, 160));
}

$small = <<<'PHP'
<?php
$s = 0;
for ($i = 0; $i < 3; $i++) {
    $s += strlen('abcdefghijklmnop');
}
echo $s;
PHP;
$outSmall = pipeline($small);
$smallLoop = loop_slice($outSmall, 'for');
// v1.3.0：积木默认换成十进制分块 + 位掩码形态，has_brick() 一并认。
if ($smallLoop !== '' && has_brick($smallLoop)) {
    t_ok('E 小循环（3 次）：仍使用完整积木');
} else {
    t_no('E 小循环', '小循环丢了积木：' . substr($smallLoop, 0, 160));
}

// ---------------------------------------------------------------------------
// F) 循环密集脚本的耗时
// ---------------------------------------------------------------------------
$bench = <<<'PHP'
<?php
$n = isset($argv[1]) ? (int) $argv[1] : 20000;
$t0 = microtime(true);
$acc = 0;
for ($i = 0; $i < $n; $i++) {
    $acc += strlen('abcdefghijklmnop');
    if ('yes' === 'yes') { $acc += 1; }
}
printf("%d %.1f\n", $acc, (microtime(true) - $t0) * 1000);
PHP;
file_put_contents($tmp . '/bench.php', $bench);
foreach (['low', 'mid'] as $p) {
    sh('php ' . escapeshellarg($root . '/entry.php')
        . ' --input=' . escapeshellarg($tmp . '/bench.php')
        . ' --output=' . escapeshellarg($tmp . "/bench_$p.php")
        . " -$p --salt=v1214f");
    $line = trim(sh('timeout 30 php ' . escapeshellarg($tmp . "/bench_$p.php") . ' 20000'));
    $parts = preg_split('/\s+/', $line);
    $ms = isset($parts[1]) ? (float) $parts[1] : 999999;
    if ($ms < 80.0) {
        t_ok(sprintf('F %s：20000 轮耗时 %.1f ms（阈值 80）', $p, $ms));
    } else {
        t_no("F $p 性能", sprintf('%.1f ms 超过阈值 80', $ms));
    }
}

// ---------------------------------------------------------------------------
// G) 关闭开关后回到重积木
// ---------------------------------------------------------------------------
$outOff = pipeline($big, ['logic_loop_light' => false]);
$offLoop = loop_slice($outOff, 'for');
if ($offLoop !== '' && has_brick_call($offLoop)) {
    t_ok('G --no-logic-loop-light：循环内恢复完整积木');
} else {
    t_no('G 关闭开关', '仍是轻量形态：' . substr($offLoop, 0, 160));
}

// ---------------------------------------------------------------------------
// H) 版本号
// ---------------------------------------------------------------------------
$help = sh('php ' . escapeshellarg($root . '/entry.php') . ' --help');
if (preg_match('/v(\d+\.\d+\.\d+)/', $help, $m) && version_compare($m[1], '1.2.14', '>=')) {
    t_ok('H 版本号 >= 1.2.14（实际 ' . $m[1] . '）');
} else {
    t_no('H 版本号', '未找到 >= 1.2.14');
}

// 清理
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo "\n===== 结果：{$pass} 通过 / {$fail} 失败 =====\n";
if ($fail > 0) {
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
    exit(1);
}
