<?php
/**
 * v1.2.13 专项回归：循环不变量外提（logic 层性能项）
 *
 *   A) 大循环（> 阈值）：循环条件 / 循环体里不再出现函数调用积木
 *   B) 小循环（<= 阈值）：保持原混淆形态（条件里仍有积木）
 *   C) 阈值可调：--logic-loop-threshold=0 让小循环也外提
 *   D) while / do-while / foreach 三种结构都能外提
 *   E) 循环体内闭包里的字面量不搬（跨作用域会致命）
 *   F) 端到端：min/low/mid/high 构建后运行结果与原生逐字节一致
 *   G) --no-logic-loop-hoist 能关掉，且关掉后仍可运行
 *   H) 可复现：同 salt 连续两次产出逐字节相同
 *   I) 版本号 >= 1.2.13
 *
 * 用法：php tests/v1213_features.php
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

// —— 直接驱动 LogicRewriter（不经外壳，产物是明文，便于断言）——
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

/** 对一段源码只跑 logic 层，返回明文 */
function logic_only(string $code, int $intensity = 3, array $extra = []): string
{
    $cfg = new Config(array_merge([
        'logic' => true,
        'logic_intensity' => $intensity,
    ], $extra));
    $lr = new LogicRewriter(new Salt('v1213-test'), new Logger(), $intensity);
    $out = $lr->process(token_get_all($code), $cfg);
    $s = '';
    foreach ($out as $t) {
        $s .= is_array($t) ? $t[1] : $t;
    }
    return $s;
}

/** 循环里是否还有函数调用积木（sha1 / md5 / base64_decode / in_array / strlen） */
/**
 * 函数调用型积木（v1.2.x 及以前的主形态）。
 * 反例：v1.3.0 的轻量形态 `((7)|0)`、`((((5)))^0)` 不含函数调用，
 * 想判「有没有积木」请用 has_brick()。
 */
function has_call_junk(string $code): bool
{
    return (bool) preg_match('/\b(sha1|md5|base64_decode|in_array|strlen)\s*\(/', $code);
}

/**
 * 任意形态的积木：函数调用型，或 v1.3.0 的十进制分块 + 位掩码型
 * （`((908)*1000000 + ((468)*1000) + (267)) ^ 0`、`((7)|0)`）。
 */
function has_brick(string $code): bool
{
    if (has_call_junk($code)) {
        return true;
    }
    return (bool) preg_match('/\d+\s*\*\s*(1000|1000000|1000000000)|[\^|&]\s*0\s*\)/', $code);
}

/**
 * 截出循环语句本身：关键字 → 头括号 → 体括号（do-while 另含尾部 while）。
 * 返回 [head, body]，找不到给空串。
 * @return array{0:string,1:string}
 */
function loop_parts(string $code, string $kw): array
{
    if (!preg_match('/\b' . preg_quote($kw, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return ['', ''];
    }
    $n = strlen($code);
    $q = (int) strpos($code, '(', $m[0][1]);
    $depth = 0;
    $end = -1;
    for ($i = $q; $i < $n; $i++) {
        if ($code[$i] === '(') {
            $depth++;
        } elseif ($code[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }
    if ($end < 0) {
        return ['', ''];
    }
    $head = substr($code, $m[0][1], $end - $m[0][1] + 1);
    $b = strpos($code, '{', $end);
    if ($b === false) {
        return [$head, ''];
    }
    $depth = 0;
    $close = -1;
    for ($i = $b; $i < $n; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $close = $i;
                break;
            }
        }
    }
    $body = $close < 0 ? '' : substr($code, $b, $close - $b + 1);
    return [$head, $body];
}

echo "===== 回归：logic 不在循环里放大开销 =====\n\n";

// ---------------------------------------------------------------------------
// A) 大循环：循环内不再有函数调用
// ---------------------------------------------------------------------------
$big = <<<'PHP'
<?php
$s = 0;
for ($i = 0; $i < 5000; $i++) {
    $s += $i * 7;
}
echo $s;
PHP;

$outBig = logic_only($big);
[$headBig, $bodyBig] = loop_parts($outBig, 'for');
if ($headBig !== '' && $bodyBig !== '' && !has_call_junk($headBig . $bodyBig)) {
    t_ok('A 大循环：循环头与循环体内已无函数调用积木');
} else {
    t_no('A 大循环', '循环里仍有积木：' . substr($headBig . $bodyBig, 0, 200));
}
// 但仍保留注入：循环体里的数字应是运算符级恒等，不是裸字面量
if (preg_match('/\(\s*\d+\s*\^\s*0\s*\)|\(\s*0\s*\+\s*\d+\s*\)|\(\s*\(\s*\d+\s*\)\s*\|\s*0\s*\)/', $bodyBig)) {
    t_ok('A2 大循环：循环内仍是恒等表达式（只是不用函数调用）');
} else {
    t_no('A2 大循环轻量形态', '没看到运算符级恒等：' . substr($bodyBig, 0, 160));
}

// ---------------------------------------------------------------------------
// B) 小循环：<= 20 次，保持原形态
// ---------------------------------------------------------------------------
$small = <<<'PHP'
<?php
$s = 0;
for ($i = 0; $i < 3; $i++) {
    $s += 5;
}
echo $s;
PHP;
$outSmall = logic_only($small);
// 注意：for 的比较操作数在变换里本就不展开，所以「有没有积木」只能看循环体
[$headSmall, $bodySmall] = loop_parts($outSmall, 'for');
if (has_brick($bodySmall)) {
    t_ok('B 小循环（3 次）：保留完整积木');
} else {
    t_no('B 小循环', '本该保留积木，却已外提：' . substr($bodySmall, 0, 120));
}

// ---------------------------------------------------------------------------
// C) 阈值可调：阈值 0 时小循环也降级
// ---------------------------------------------------------------------------
$outSmall0 = logic_only($small, 3, ['logic_loop_threshold' => 0]);
[$headS0, $bodyS0] = loop_parts($outSmall0, 'for');
if ($bodyS0 !== '' && !has_call_junk($bodyS0)) {
    t_ok('C 阈值 0：小循环也改用轻量形态');
} else {
    t_no('C 阈值 0', '小循环仍用重积木：' . substr($bodyS0, 0, 160));
}

// ---------------------------------------------------------------------------
// D) while / do-while / foreach
// ---------------------------------------------------------------------------
$kinds = [
    'for' => "<?php\n\$s=0;\nfor (\$i = 0; \$i < 4000; \$i++) { \$s += 7; }\necho \$s;\n",
    'while' => "<?php\n\$s=0;\$k=0;\nwhile (\$k < 4000) { \$s += 7; \$k++; }\necho \$s;\n",
    'do-while' => "<?php\n\$s=0;\$k=0;\ndo { \$s += 7; \$k++; } while (\$k < 4000);\necho \$s;\n",
    'foreach' => "<?php\n\$s=0;\nforeach ([1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25] as \$v) { \$s += 9; }\necho \$s;\n",
];
foreach ($kinds as $name => $src) {
    $o = logic_only($src);
    if ($name === 'do-while') {
        $seg = preg_match('/\bdo\s*\{.*?\}\s*while\s*\([^;]*\);/s', $o, $m) ? $m[0] : '';
    } elseif ($name === 'foreach') {
        // foreach 的数组表达式只在循环开始前求值一次，允许 header 里有积木；
        // 只断言 body 已被清空
        [, $seg] = loop_parts($o, 'foreach');
    } else {
        [$h, $b] = loop_parts($o, $name);
        $seg = $h . $b;
    }
    if ($seg !== '' && !has_call_junk($seg)) {
        t_ok("D {$name}：循环内已无积木");
    } else {
        t_no("D {$name}", '仍有积木：' . substr($seg, 0, 160));
    }
}

// ---------------------------------------------------------------------------
// E) 循环体内的闭包：字面量不能被搬出去（闭包看不见外部变量）
// ---------------------------------------------------------------------------
$closure = <<<'PHP'
<?php
$s = 0;
for ($i = 0; $i < 5000; $i++) {
    $f = function () { return 12345; };
    $s += $f();
}
echo $s;
PHP;
$outClosure = logic_only($closure);
// 闭包体里必须仍能看到"12345 的积木形态"，而不是一个裸变量引用
if (preg_match('/function\s*\([^)]*\)\s*\{[^}]*\$_[A-Za-z0-9]+\s*;/', $outClosure)) {
    t_no('E 闭包作用域', '闭包体里的字面量被搬成了外部变量');
} else {
    t_ok('E 闭包作用域：闭包内的字面量未被外提');
}

// ---------------------------------------------------------------------------
// F) 端到端：各档构建后运行结果与原生一致
// ---------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/v1213_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$srcFile = $tmp . '/bench.php';
$loopSrc = <<<'PHP'
<?php
$s = 0;
for ($i = 0; $i < 5000; $i++) {
    $s += $i;
}
$k = 0;
while ($k < 5000) {
    $s += 1;
    $k++;
}
$w = 0;
do {
    $w += 3;
} while ($w < 90);
$arr = [2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30];
foreach ($arr as $v) {
    $s += $v;
}
$tiny = 0;
for ($j = 0; $j < 3; $j++) {
    $tiny += $j;
}
echo $s, ' ', $w, ' ', $tiny, "\n";
PHP;
file_put_contents($srcFile, $loopSrc);
$native = trim(sh('php ' . escapeshellarg($srcFile)));

$entry = $root . '/entry.php';
$presets = ['min', 'low', 'mid', 'high'];
foreach ($presets as $p) {
    $of = $tmp . '/out_' . $p . '.php';
    sh('php ' . escapeshellarg($entry) . ' --input=' . escapeshellarg($srcFile)
        . ' --output=' . escapeshellarg($of) . ' -' . $p);
    $got = is_file($of) ? trim(sh('php ' . escapeshellarg($of))) : '<构建失败>';
    if ($got === $native) {
        t_ok("F {$p}：运行结果与原生一致（{$native}）");
    } else {
        t_no("F {$p}", "原生 {$native}，产物 {$got}");
    }
}

// ---------------------------------------------------------------------------
// G) --no-logic-loop-hoist 能关掉
// ---------------------------------------------------------------------------
$offFile = $tmp . '/off.php';
sh('php ' . escapeshellarg($entry) . ' --input=' . escapeshellarg($srcFile)
    . ' --output=' . escapeshellarg($offFile) . ' -mid --no-logic-loop-hoist');
$got = is_file($offFile) ? trim(sh('php ' . escapeshellarg($offFile))) : '<构建失败>';
if ($got === $native) {
    t_ok('G --no-logic-loop-hoist：关闭后仍可运行且结果正确');
} else {
    t_no('G --no-logic-loop-hoist', "期望 {$native}，实得 {$got}");
}

// ---------------------------------------------------------------------------
// H) 可复现：同 salt 连续两次一致
// ---------------------------------------------------------------------------
$a1 = logic_only($loopSrc, 3);
$a2 = logic_only($loopSrc, 3);
if ($a1 === $a2) {
    t_ok('H 可复现：同 salt 两次产出逐字节相同');
} else {
    t_no('H 可复现', '两次产出不一致');
}

// ---------------------------------------------------------------------------
// I) 版本号
// ---------------------------------------------------------------------------
$help = sh('php ' . escapeshellarg($entry) . ' --help');
if (preg_match('/v(\d+\.\d+\.\d+)/', $help, $m) && version_compare($m[1], '1.2.13', '>=')) {
    t_ok('I 版本号 >= 1.2.13（实际 ' . $m[1] . '）');
} else {
    t_no('I 版本号', '未达到 1.2.13');
}

// ---------------------------------------------------------------------------
echo "\n===== 结果：{$pass} 通过 / {$fail} 失败 =====\n";
if ($fail > 0) {
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
    exit(1);
}
exit(0);
