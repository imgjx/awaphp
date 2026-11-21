<?php
/**
 * v1.3.0 特性回归：运行时环境门闩 / 动态 case 标签 / 成本下调 / 默认接线
 *
 * 覆盖：
 *   A 六档（min~ultra）产物都能跑通，$argv 不丢，输出与原生逐字节一致
 *   B 文件级环境探针存在：PHP 版本 / 时间 / 反调试布尔 / 是否被 include
 *   C 反调试布尔真的参与判断（xdebug 探测出现在产物里，且被算成变量）
 *   D 语句级门闩是「两路等价」：两条分支输出相同，条件依赖运行期值
 *   E min 档默认接上 funcsplit（语句拆函数）与 decoy / envgate
 *   F 状态机 case 标签不是字面量常量表（动态基址），且产物可运行
 *   G `break 2;` 的层级操作数不被展开成表达式（PHP 8 编译期 fatal 回归）
 *   H logic 低成本积木：不再出现 strlen(md5()) 形态
 *   I aggressive_prune 已彻底下线
 *   J 版本号
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

$tmp = sys_get_temp_dir() . '/v130_' . getmypid();
@mkdir($tmp, 0777, true);

spl_autoload_register(static function (string $c) use ($root): void {
    foreach (['src/', 'src/LogicTransform/', 'src/AntiAst/', 'src/Support/', 'src/Variable/',
              'src/Number/', 'src/String/', 'src/Layer/', 'src/ControlFlow/', 'src/Vm/',
              'src/Encode/', 'src/Function_/', 'src/Class_/', 'src/Junk/', 'src/Project/',
              'src/Token/', 'src/Module/', 'src/Watermark/', 'src/AntiDetect/',
              'src/Confrontation/', 'src/Optimize/'] as $d) {
        $p = $root . '/' . $d . $c . '.php';
        if (is_file($p)) {
            require $p;
            return;
        }
    }
});

echo "===== v1.3.0 回归：环境门闩 / 动态 case / 成本下调 =====\n\n";

// ---------------------------------------------------------------------------
// 公共夹具：一个带函数、循环、echo、$argv 的脚本
// ---------------------------------------------------------------------------
$fixture = $tmp . '/env_src.php';
file_put_contents($fixture, <<<'PHP'
<?php
namespace Demo;

function greet($name, $n)
{
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $out .= 'Hi ' . $name . '!';
        echo $out, "\n";
    }
    return $out;
}

$who = $argv[1] ?? '(none)';
$r = greet($who, 2);
echo "RESULT=[" . $r . "]\n";
echo "ARG=[" . $who . "]\n";
PHP);

$native = sh('php ' . escapeshellarg($fixture) . ' Zed');

// ---------------------------------------------------------------------------
// A) 六档全链：跑通 + argv 不丢
// ---------------------------------------------------------------------------
$levels = ['min', 'low', 'mid', 'high', 'max', 'ultra'];
$bad = [];
foreach ($levels as $lv) {
    $out = $tmp . '/a_' . $lv . '.php';
    $log = sh('php ' . escapeshellarg($root . '/entry.php')
        . ' --input=' . escapeshellarg($fixture)
        . ' --output=' . escapeshellarg($out)
        . ' --level=' . $lv . ' --salt=v130a');
    if (!is_file($out)) {
        $bad[] = $lv . '(构建失败)';
        continue;
    }
    $run = sh('timeout 20 php ' . escapeshellarg($out) . ' Zed');
    if ($run !== $native) {
        $bad[] = $lv . '(输出不一致: ' . substr(str_replace("\n", ' ', $run), 0, 60) . ')';
    }
}
if ($bad === []) {
    t_ok('A 六档产物输出与原生逐字节一致，$argv 保留');
} else {
    t_no('A 六档产物', implode(' / ', $bad));
}

// ---------------------------------------------------------------------------
// B) 文件级环境探针
// ---------------------------------------------------------------------------
$minCode = is_file($tmp . '/a_min.php') ? (string) file_get_contents($tmp . '/a_min.php') : '';
$needles = [
    'PHP_VERSION_ID'        => '版本识别',
    'time('                 => '时间识别',
    'xdebug'                => '反调试布尔',
    'get_included_files'    => 'include 探测',
];
$miss = [];
foreach ($needles as $nd => $label) {
    if (strpos($minCode, $nd) === false) {
        $miss[] = $label;
    }
}
// 字符串层会把 'xdebug' 编成 base64/XOR，可能看不到明文；退一步看 extension_loaded
if (in_array('反调试布尔', $miss, true) && strpos($minCode, 'extension_loaded') !== false) {
    $miss = array_values(array_diff($miss, ['反调试布尔']));
}
if ($miss === []) {
    t_ok('B 文件级探针齐全（版本 / 时间 / 反调试 / include）');
} else {
    t_no('B 文件级探针', '缺失：' . implode('、', $miss));
}

// ---------------------------------------------------------------------------
// C) 反调试布尔被算成变量并参与判断（不是裸 exit 探针）
// ---------------------------------------------------------------------------
$hasBoolVar = (bool) preg_match('/=\s*\(int\)\(bool\)/', $minCode);
if ($hasBoolVar) {
    t_ok('C 反调试结果存成布尔变量并参与环境指纹');
} else {
    t_no('C 反调试布尔变量', '没找到 (int)(bool) 形式的归一化');
}

// ---------------------------------------------------------------------------
// D) 语句级门闩：两路等价（同一输入跑两次输出一致）
// ---------------------------------------------------------------------------
$gateSrc = $tmp . '/gate_src.php';
file_put_contents($gateSrc, <<<'PHP'
<?php
$x = 'aaa';
$y = 'bbb';
echo "1:" . $x . $y . "\n";
echo "2:" . $y . $x . "\n";
PHP);
$gateOut = $tmp . '/gate_out.php';
sh('php ' . escapeshellarg($root . '/entry.php')
    . ' --input=' . escapeshellarg($gateSrc)
    . ' --output=' . escapeshellarg($gateOut)
    . ' --level=min --salt=v130d');
$r1 = sh('timeout 20 php ' . escapeshellarg($gateOut));
$r2 = sh('timeout 20 php ' . escapeshellarg($gateOut));
$expect = "1:aaabbb\n2:bbbaaa\n";
if ($r1 === $expect && $r2 === $expect) {
    t_ok('D 门闩两路等价：两次执行输出相同且与原生一致');
} else {
    t_no('D 门闩等价性', 'r1=' . substr(str_replace("\n", '|', $r1), 0, 60)
        . ' r2=' . substr(str_replace("\n", '|', $r2), 0, 60));
}

// ---------------------------------------------------------------------------
// E) min 默认接线：funcsplit + decoy + envgate 都在层清单里
// ---------------------------------------------------------------------------
require_once $root . '/src/Config.php';
require_once $root . '/src/config.presets.php';
require_once $root . '/src/Layer/LayerRegistry.php';
require_once $root . '/src/ControlFlow/StateMachine.php';
require_once $root . '/src/Support/NameStyle.php';
$cfgMin = Config::fromPreset('min');
$names = array_map(static fn ($l) => (string) ($l['name'] ?? ''), $cfgMin->getLayers());
$want = ['funcsplit', 'decoy', 'envgate'];
$missing = array_values(array_diff($want, $names));
if ($missing === []) {
    t_ok('E min 档默认接上 funcsplit / decoy / envgate');
} else {
    t_no('E min 默认接线', '缺少：' . implode(',', $missing));
}
if (strpos($minCode, 'function_exists(') !== false && preg_match('/function\s+\w{6,}\(/', $minCode)) {
    t_ok('E2 min 产物里确实出现了提取出来的函数定义');
} else {
    t_no('E2 funcsplit 生效', '产物里没有提取函数');
}

// ---------------------------------------------------------------------------
// F) 状态机 case 标签不是纯字面量表
// ---------------------------------------------------------------------------
$smSrc = "<?php\necho \"X\\n\";\nif (1) { echo \"A\\n\"; }\necho \"B\\n\";";
$smCfg = new Config([
    'sm_complexity' => 8, 'sm_passes' => 1, 'sm_traps' => 2,
    'sm_opaque' => true, 'sm_dynamic_case' => true,
]);
$sm = new StateMachineObfuscator(new Logger(Logger::LEVEL_ERROR, 'text'), new Salt('v130f'));
$smTokens = $sm->process(Parser::tokenize($smSrc), $smCfg, true);
$smCode = '';
foreach ($smTokens as $tk) {
    $smCode .= Parser::text($tk);
}
// 分派形态随 salt 在 switch / if 链之间切换，两种都要认（标签值一律是「基址变量 + 偏移」）
$dynCase = (bool) preg_match('/(?:case|===)\s*\$[A-Za-z_]\w*\s*\+/i', $smCode)
    && strpos($smCode, 'PHP_VERSION_ID>0') !== false;
$smFile = $tmp . '/sm.php';
file_put_contents($smFile, $smCode);
$smRun = sh('timeout 10 php ' . escapeshellarg($smFile));
if ($dynCase && $smRun === "X\nA\nB\n") {
    t_ok('F case 标签为「运行期基址 + 偏移」，产物仍按原序执行');
} else {
    t_no('F 动态 case 标签', ($dynCase ? '形态 OK 但' : '仍是字面量标签；')
        . 'run=' . str_replace("\n", '|', $smRun));
}

// ---------------------------------------------------------------------------
// G) break 2; 不被展开
// ---------------------------------------------------------------------------
$brkSrc = "<?php\nfor (\$i = 0; \$i < 3; \$i++) { switch (\$i) { case 1: break 2; default: echo \$i; } }";
$sem = new SemanticRewriter(new Salt('v130g'), new Logger(Logger::LEVEL_ERROR, 'text'));
$brkCfg = new Config(['semantic' => true, 'semantic_strength' => 3]);
$brkTokens = $sem->process(Parser::tokenize($brkSrc), $brkCfg);
$brkCode = '';
foreach ($brkTokens as $tk) {
    $brkCode .= Parser::text($tk);
}
$brkFile = $tmp . '/brk.php';
file_put_contents($brkFile, $brkCode);
$lint = sh('php -l ' . escapeshellarg($brkFile));
$brkRun = sh('timeout 10 php ' . escapeshellarg($brkFile));
if (strpos($lint, 'No syntax errors') !== false && $brkRun === '0') {
    t_ok('G break 2; 层级操作数保持字面量（可编译、语义不变）');
} else {
    t_no('G break 操作数', trim(substr($lint, 0, 100)) . ' run=' . $brkRun);
}

// ---------------------------------------------------------------------------
// H) logic 低成本积木：不再出现 strlen(md5(...)) / strlen(sha1(...))
// ---------------------------------------------------------------------------
$lgSrc = $tmp . '/lg_src.php';
file_put_contents($lgSrc, "<?php\necho 12345 + 678;\necho 'tail';\n");
$lgOut = $tmp . '/lg_out.php';
sh('php ' . escapeshellarg($root . '/entry.php')
    . ' --input=' . escapeshellarg($lgSrc)
    . ' --output=' . escapeshellarg($lgOut)
    . ' --level=low --salt=v130h');
$lgCode = is_file($lgOut) ? (string) file_get_contents($lgOut) : '';
if ($lgCode !== '' && strpos($lgCode, 'sha1(') === false && strpos($lgCode, 'md5(') === false) {
    t_ok('H logic 数字积木已换成十进制分块 + 位运算（无 strlen(md5())）');
} else {
    t_no('H 低成本积木', '产物里仍有哈希积木或构建失败');
}

// ---------------------------------------------------------------------------
// I) aggressive_prune 已下线
// ---------------------------------------------------------------------------
$stillThere = [];
foreach (['src/LogicTransform/AggressivePruner.php'] as $f) {
    if (is_file($root . '/' . $f)) {
        $stillThere[] = $f;
    }
}
if (LayerRegistry::kind('aggressive_prune') !== null) {
    $stillThere[] = 'LayerRegistry 仍登记 aggressive_prune';
}
$cfgAll = (string) file_get_contents($root . '/config.default.php');
if (strpos($cfgAll, 'aggressive_prune') !== false) {
    $stillThere[] = 'config.default.php 仍有 aggressive_prune';
}
if ($stillThere === []) {
    t_ok('I 死分支裁剪（aggressive_prune）已彻底下线');
} else {
    t_no('I aggressive_prune 下线', implode('；', $stillThere));
}

// ---------------------------------------------------------------------------
// J) 版本号
// ---------------------------------------------------------------------------
$entry = (string) file_get_contents($root . '/entry.php');
if (preg_match("/define\('OBFUSCATOR_VERSION',\s*'([^']+)'\)/", $entry, $m) && $m[1] === '1.3.0') {
    t_ok('J 版本号 1.3.0');
} else {
    t_no('J 版本号', 'entry.php 中的版本不是 1.3.0');
}

echo "\n----------------------------------------\n";
echo "通过 {$pass} 项";
if ($fail > 0) {
    echo "，失败 {$fail} 项：\n";
    foreach ($failMsg as $f) {
        echo "  - {$f}\n";
    }
}
echo "\n";
exit($fail > 0 ? 1 : 0);
