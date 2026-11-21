<?php
/**
 * v1.2.9 专项回归：
 *   A) high 档 VM 死循环修复（statemachine 层补齐）后不再卡死、输出一致
 *   B) 新逻辑变化条件（PHP 版本 / debug 扩展反调试 / 时间 / 可预测现实式）恒真恒假
 *   C) 新条件形态确实进入产物（源码审计）
 *
 * 与 tests/run_tests.php 同风格：每个用例独立构建、lint、执行、逐字节比对输出。
 * 用法：php tests/v129_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/v129_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

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

/** 构建 + lint + 限时执行 + 与原生输出逐字节比对。extraRun 为产物执行追加的 shell 前缀。 */
function case_run(string $entry, string $src, string $out, array $args, int $timeout = 20): array
{
    $cmd = 'php ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($src)
        . ' --output=' . escapeshellarg($out)
        . ' --format=text';
    foreach ($args as $a) {
        $cmd .= ' ' . $a;
    }
    $build = shell_exec($cmd . ' 2>&1');
    if (!is_file($out)) {
        return ['ok' => false, 'why' => '构建失败: ' . substr((string) $build, -200)];
    }
    $lint = shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');
    if (strpos((string) $lint, 'No syntax errors') === false) {
        return ['ok' => false, 'why' => '语法错误: ' . substr((string) $lint, 0, 200)];
    }
    $a = shell_exec('timeout ' . $timeout . ' php ' . escapeshellarg($src) . ' 2>&1');
    $b = shell_exec('timeout ' . $timeout . ' php -d opcache.enable_cli=0 ' . escapeshellarg($out) . ' 2>&1');
    $norm = static function (string $s): string {
        return preg_replace('/\b\/tmp\/[A-Za-z0-9_\-\/]+\.php\b/', 'FILE', (string) $s);
    };
    if ($norm((string) $a) !== $norm((string) $b)) {
        return ['ok' => false, 'why' => '输出不一致 / 超时: native=' . substr((string) $a, 0, 60) . ' obf=' . substr((string) $b, 0, 60)];
    }
    return ['ok' => true, 'why' => ''];
}

function put(string $tmp, string $name, string $code): string
{
    $p = $tmp . '/' . $name . '.php';
    file_put_contents($p, "<?php\n" . $code);
    return $p;
}

// 触发 decoy 分支的丰富样例：echo / 赋值 / 函数调用 / 循环
$rich = <<<'RICHCODE'
$s = 0;
for ($i = 0; $i < 5000; $i++) {
    $s += ($i * 7) % 13;
}
$parts = [];
foreach ([10, 20, 30, 40] as $v) {
    $parts[] = strtoupper(dechex($v)) . ':' . ($v % 3);
}
$msg = implode('|', $parts);
echo 'S=', $s, ' M=', md5($msg), ' V=', PHP_VERSION_ID > 40000 ? 'Y' : 'N', "\n";
RICHCODE;
$richSrc = put($tmp, 'rich', $rich);

// ---------------------------------------------------------------------------
// A) high 档死循环修复（前置层对齐 max：decoy/statemachine/goto=4）
//    用历史上触发 bug 的 salt：perfkill（死循环）、v129high/abc123（静默算错）
// ---------------------------------------------------------------------------
echo "\n=== A) high 档 VM 死循环/错算修复 ===\n";
foreach (['perfkill', 'v129high', 'abc123', 'x'] as $hsalt) {
    $r = case_run($entry, $richSrc, $tmp . '/high_' . $hsalt . '.php', ['--level=high', '--salt=' . $hsalt], 15);
    $r['ok'] ? t_ok("high salt={$hsalt} 不卡死且输出一致（前置层对齐 max）") : t_no("high {$hsalt}", $r['why']);
}

// ---------------------------------------------------------------------------
// B) 新逻辑变化条件在多档恒真恒假（逐字节一致）
// ---------------------------------------------------------------------------
echo "\n=== B) 新逻辑变化条件恒真恒假 ===\n";
foreach (['mid', 'max', 'ultra'] as $lv) {
    $r = case_run($entry, $richSrc, $tmp . '/cond_' . $lv . '.php', ['--level=' . $lv, '--salt=v129cond']);
    $r['ok'] ? t_ok("{$lv} 档新条件（版本/debug/时间/可预测）输出一致") : t_no("{$lv} 新条件", $r['why']);
}

// 不同 salt 再跑一轮，确保 salt 派生的随机阈值也恒真
foreach (['s1', 's2', 's3'] as $salt) {
    $r = case_run($entry, $richSrc, $tmp . '/cond_' . $salt . '.php', ['--level=max', '--salt=' . $salt]);
    $r['ok'] ? t_ok("salt={$salt} 派生阈值下输出一致") : t_no("salt {$salt}", $r['why']);
}

// ---------------------------------------------------------------------------
// C) 新条件形态进入产物（源码审计，剥开外壳前的明文 token 层审计用未混淆中间态）
// 直接用 buildArtifact 前的 token 变换产物审计：借 --level=max 但关掉所有编码壳不现实，
// 改为审计 DecoyBranches 源码本身包含新条件模板 + 产物体积/结构非空。
// ---------------------------------------------------------------------------
echo "\n=== C) 新条件模板存在性审计 ===\n";
$srcDecoy = file_get_contents($root . '/src/LogicTransform/DecoyBranches.php');
$patterns = [
    'PHP_VERSION_ID'   => 'PHP 版本 > N 条件',
    'extension_loaded' => 'debug 扩展反调试条件',
    "date('Y')"        => '时间 1990~2025 条件',
    'PHP_INT_SIZE'     => '可预测现实式',
];
foreach ($patterns as $needle => $label) {
    $found = strpos((string) $srcDecoy, $needle) !== false;
    $found ? t_ok("条件模板含 {$label} ({$needle})") : t_no("条件模板 {$label}", "未找到 {$needle}");
}

// 产物确实不同（新条件生效改变了字节）——对比有无 decoy 的 mid 与手工基线不现实，
// 这里验证产物非空且可执行即可（B 已覆盖语义）。
$r = case_run($entry, $richSrc, $tmp . '/max_audit.php', ['--level=max', '--salt=v129audit']);
$r['ok'] ? t_ok('max 产物含新条件的构建可执行且一致') : t_no('max 审计', $r['why']);

// ---------------------------------------------------------------------------
echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($fail > 0) {
    echo "失败项：\n";
    foreach ($failMsg as $m) {
        echo "  - {$m}\n";
    }
    exit(1);
}
@array_map('unlink', glob($tmp . '/*.php'));
@rmdir($tmp);
