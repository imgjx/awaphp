<?php
/**
 * 嗷呜awaのphp混淆 — 测试运行器（冒烟 + 集成）
 *
 * 对每个测试用例：混淆 → php -l 校验 → 运行原文与混淆产物 → 断言输出一致。
 * 覆盖 Stage1（命名混淆）与 Stage2（编码 / 优化 / GZ）的多种组合。
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$entry = $root . '/entry.php';
$fixtures = $root . '/tests/fixtures';
$tmp = sys_get_temp_dir() . '/obf_test_' . getmypid();
@mkdir($tmp, 0755, true);

// ---- 工具函数 ----
function runCapture(string $cmd): array
{
    exec($cmd . ' 2>&1', $out, $exit);
    return ['out' => implode("\n", $out), 'exit' => (int) $exit];
}

$pass = 0;
$fail = 0;
$fails = [];

function record(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail, $fails;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $fail++;
        $fails[] = $name . ' :: ' . $detail;
        echo "  \033[31m✗\033[0m {$name}\n     " . $detail . "\n";
    }
}

// ---- 测试用例：[名称, 输入, 选项, 是否要求输出一致] ----
$cases = [
    ['S1 变量/函数/类 命名混淆',            'sample.php',   '--var-length=6 --func-length=8 --class-obf --salt=abc', true],
    ['S1 仅变量混淆',                       'sample.php',   '--var-length=10 --salt=s1', true],
    ['S1 仅函数混淆',                       'sample.php',   '--func-length=12 --salt=s2', true],
    ['S1 重复加密 x2',                      'sample.php',   '--var-length=6 --func-length=8 --repeat=2 --salt=r2', true],
    ['S2 字符串编码',                       'strings.php',  '--str-length=3 --salt=s3', true],
    ['S2 数字编码',                         'strings.php',  '--num-enc --salt=s4', true],
    ['S2 HTML 编码',                        'strings.php',  '--html --str-length=2 --salt=s5', true],
    ['S2 函数调用混淆+编码',                'sample.php',   '--func-call --func-call-enc --func-length=8 --salt=s6', true],
    ['S2 插入死代码',                       'sample.php',   '--insert-dead --salt=s7', true],
    ['S2 去注释',                           'sample.php',   '--no-comments --salt=s8', true],
    ['S2 压缩单行',                         'sample.php',   '--compress --salt=s9', true],
    ['S2 全量组合',                         'sample.php',   '--var-length=6 --func-length=8 --class-obf --str-length=3 --num-enc --html --func-call --func-call-enc --insert-dead --compress --repeat=2 --salt=full', true],
    ['S2 GZ 加壳',                          'sample.php',   '--gz --str-length=3 --num-enc --salt=gz1', true],
    ['S2 GZ + 全量 + 重复',                 'strings.php',  '--gz --str-length=2 --num-enc --html --class-obf --repeat=2 --salt=gz2', true],
    ['S2 类/接口/trait 混淆',              'classes.php',  '--class-obf --func-length=10 --salt=cls', true],
    ['S2 字符串安全上下文跳过',             'strings.php',  '--str-length=2 --html --num-enc --salt=ctx', true],

    // ===== 第三阶段（控制流 / VM / 反检测） =====
    ['S3 控制流混淆 cfg=high',              'sample.php',   '--cfg=high --salt=s3a', true],
    ['S3 GOTO 混淆',                       'sample.php',   '--goto=3 --goto-cpx=2 --salt=s3b', true],
    ['S3 VM 虚拟机壳',                     'demo.php',     '--vm --salt=s3c', true],
    ['S3 EVAL 多层加壳',                   'demo.php',     '--eval --eval-layers=2 --salt=s3d', true],
    ['S3 反调试探针',                      'demo.php',     '--anti-debug --salt=s3e', true],
    ['S3 自校验防篡改',                    'demo.php',     '--anti-tamper --salt=s3f', true],
    ['S3 字符串 XOR 装配',                 'strings.php',  '--str-xor --salt=s3g', true],
    ['S3 诱饵函数',                        'demo.php',     '--junk-funcs=5 --salt=s3h', true],
    ['S3 目标 PHP 版本',                   'demo.php',     '--php-version=8.0 --salt=s3i', true],
    ['S3 组合(cfg+goto+vm+anti-debug+str-xor)', 'demo.php', '--cfg=high --goto=3 --goto-cpx=2 --vm --anti-debug --str-xor --salt=s3j', true],
    ['S3 全量组合(repeat=1)',              'demo.php',     '--var-length=6 --var-split=3 --func-length=6 --func-call --func-call-enc --class-obf --str-length=8 --str-xor --html --num-enc --gz --insert-dead --no-comments --compress --cfg=high --goto=3 --goto-cpx=2 --vm --eval --eval-layers=3 --anti-debug --anti-tamper --php-version=8.1 --junk-funcs=5 --salt=full3', true],
];

echo "===== PHP 混淆器 测试套件 =====\n";
echo "PHP: " . PHP_VERSION . "\n\n";

foreach ($cases as [$name, $in, $opts, $compare]) {
    $inFile = $fixtures . '/' . $in;
    $obfFile = $tmp . '/' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.php';
    $cmd = sprintf('%s %s --input=%s --output=%s %s --format=text 2>&1',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($inFile), escapeshellarg($obfFile), $opts);
    $r = runCapture($cmd);
    $out = $r['out']; $exit = $r['exit'];
    if ($exit !== 0 || !is_file($obfFile)) {
        record(false, $name, '混淆命令失败 (exit=' . $exit . '): ' . $out);
        continue;
    }
    // php -l 校验
    $lintR = runCapture(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($obfFile)));
    if ($lintR['exit'] !== 0) {
        record(false, $name, 'php -l 未通过: ' . $lintR['out']);
        continue;
    }
    if (!$compare) {
        record(true, $name, '仅校验语法');
        continue;
    }
    // 运行原文
    $origR = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($inFile)));
    $obfR = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($obfFile)));
    if ($origR['exit'] !== 0) {
        record(false, $name, '原文运行失败 exit=' . $origR['exit'] . ' ' . $origR['out']);
        continue;
    }
    if ($obfR['exit'] !== 0) {
        record(false, $name, '混淆产物运行失败 exit=' . $obfR['exit'] . ' ' . $obfR['out']);
        continue;
    }
    record($origR['out'] === $obfR['out'], $name, '运行输出不一致。原=' . json_encode($origR['out']) . ' 混=' . json_encode($obfR['out']));
}

// ---- 目录模式测试 ----
echo "\n-- 目录递归混淆 --\n";
$dirIn = $fixtures;
$dirOut = $tmp . '/dir_out';
$cmd = sprintf('%s %s --input=%s --output=%s --var-length=6 --class-obf --compress --salt=dir 2>&1',
    escapeshellarg($php), escapeshellarg($entry), escapeshellarg($dirIn), escapeshellarg($dirOut));
runCapture($cmd);
$okCount = 0;
if (is_dir($dirOut)) {
    foreach (glob($dirOut . '/*.php') as $f) {
        $lr = runCapture(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($f)));
        if ($lr['exit'] === 0) $okCount++;
    }
}
record(is_dir($dirOut) && $okCount > 0, '目录递归混淆 + php -l', '通过校验文件数=' . $okCount);

// ---- Stage3 专项：自校验防篡改 ----
echo "\n-- Stage3 专项：防篡改 --\n";
$atOut = $tmp . '/s3_tamper.php';
runCapture(sprintf('%s %s --input=%s --output=%s --anti-tamper --salt=at --format=text 2>&1',
    escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fixtures . '/demo.php'), escapeshellarg($atOut)));
if (is_file($atOut)) {
    $cleanR = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($atOut)));
    record($cleanR['exit'] === 0, '防篡改：未篡改文件正常执行', 'exit=' . $cleanR['exit']);
    // 语法安全的篡改：尾部追加一个空格，应触发自校验异常退出（非 0）
    file_put_contents($tmp . '/s3_tamper_bad.php', file_get_contents($atOut) . ' ');
    $badR = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($tmp . '/s3_tamper_bad.php')));
    record($badR['exit'] !== 0, '防篡改：被篡改文件异常退出', 'exit=' . $badR['exit']);
} else {
    record(false, '防篡改：产物生成', '未生成产物');
}

// ---- 帮助 / 信息输出 ----
echo "\n-- 元信息 --\n";
$infoR = runCapture(sprintf('%s %s --info', escapeshellarg($php), escapeshellarg($entry)));
record($infoR['exit'] === 0 && str_contains($infoR['out'], '嗷呜'), '`--info` 输出工具信息', $infoR['out']);
$helpR = runCapture(sprintf('%s %s --help', escapeshellarg($php), escapeshellarg($entry)));
record($helpR['exit'] === 0 && str_contains($helpR['out'], '用法'), '`--help` 输出帮助', '');

// ---- 汇总 ----
echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) {
    echo "  FAIL: {$f}\n";
}
exit($fail === 0 ? 0 : 1);
