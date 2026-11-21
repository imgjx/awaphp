<?php
/**
 * v1.2.10 专项回归：
 *   A) AntiHookEval strict=false 的漏括号已修复（生成代码语法合法且能跑）
 *   B) 诱饵四分支结构可生成且语义不变
 *   C) 版本号与全档冒烟
 *
 * 与 tests/run_tests.php 同风格：每个用例独立构建、lint、执行、逐字节比对输出。
 * 用法：php tests/v1210_features.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/v1210_' . bin2hex(random_bytes(4));
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

/** 构建 + lint + 限时执行 + 与原生输出逐字节比对。 */
function case_run(string $entry, string $src, string $out, array $args, int $timeout = 25): array
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
    $b = shell_exec('timeout ' . $timeout . ' php -d opcache.enable_cli=0 ' . escapeshellarg($out) . ' 2>/dev/null');
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

/** 写一份分层 JSON 配置，返回路径 */
function cfg(string $tmp, string $name, array $layers, array $meta = []): string
{
    $p = $tmp . '/cfg_' . $name . '.json';
    file_put_contents($p, json_encode(['layers' => $layers] + $meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $p;
}

/** 统计产物里某子串出现次数 */
function count_in(string $file, string $needle): int
{
    return substr_count((string) @file_get_contents($file), $needle);
}

/** Logic 注入的积木总数（整数 sha1/md5 + 字符串多编码） */
function logic_blocks(string $file): int
{
    $s = (string) @file_get_contents($file);
    return substr_count($s, 'sha1(') + substr_count($s, 'md5(')
        + substr_count($s, 'base64_decode(') + substr_count($s, 'hex2bin(')
        + substr_count($s, 'str_rot13(');
}

$sample = <<<'CODE'
$n = 7;
$out = $n * 3;
echo 'v=', $out, "\n";
$x = $out + 11;
echo 'x=', $x, "\n";
CODE;
$src = put($tmp, 'sample', $sample);

// ---------------------------------------------------------------------------
// A) AntiHookEval strict=false 漏括号修复
// ---------------------------------------------------------------------------
echo "\n=== A) anti_hook strict=false 修复 ===\n";
foreach ([false, true] as $strict) {
    $name = $strict ? 'hook_strict' : 'hook_loose';
    $c = cfg($tmp, $name, [
        ['name' => 'constfold', 'params' => ['level' => 1]],
        ['name' => 'shell:anti_hook', 'params' => ['strict' => $strict]],
    ], ['shell_shield' => false]);
    $r = case_run($entry, $src, $tmp . '/' . $name . '.php', ['--config=' . $c]);
    $label = $strict ? 'strict=true' : 'strict=false';
    $r['ok'] ? t_ok("anti_hook {$label} 生成代码合法且输出一致") : t_no("anti_hook {$label}", $r['why']);
}

// ---------------------------------------------------------------------------
// B) 诱饵四分支结构
// ---------------------------------------------------------------------------
echo "\n=== B) 诱饵四分支结构 ===\n";
$decoyOnly = cfg($tmp, 'decoy_only', [
    ['name' => 'logic', 'params' => ['intensity' => 3]],
    ['name' => 'decoy', 'params' => ['level' => 3]],
], ['shell_shield' => false]);
$maxElseif = 0;
// 诱饵是**概率**注入（等级 3 约 45% 命中），随 salt 与语句下标抽样。v1.3.0 的
// 数字积木变短，同一 salt 的语句下标会平移，4 个盐可能全部抽样落空——所以这里
// 扫更多盐，只要有一个产物出现 ≥2 个 elseif 就算该层在工作。
foreach (['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10'] as $salt) {
    $o = $tmp . '/decoy_' . $salt . '.php';
    $r = case_run($entry, $src, $o, ['--config=' . $decoyOnly, '--salt=' . $salt]);
    if (!$r['ok']) {
        t_no("decoy salt={$salt}", $r['why']);
        continue;
    }
    $maxElseif = max($maxElseif, count_in($o, 'elseif'));
}
$maxElseif >= 2
    ? t_ok("四分支诱饵已生成（单文件最多 {$maxElseif} 个 elseif）")
    : t_no('四分支诱饵', "最多只有 {$maxElseif} 个 elseif，应 ≥2");

// ---------------------------------------------------------------------------
// C) 版本号与全档冒烟
// ---------------------------------------------------------------------------
echo "\n=== C) 版本与全档冒烟 ===\n";
preg_match("/define\('OBFUSCATOR_VERSION',\s*'([^']+)'\)/", (string) @file_get_contents($entry), $mv);
$curVer = $mv[1] ?? '?';
$help = (string) shell_exec('php ' . escapeshellarg($entry) . ' --help 2>&1');
strpos($help, $curVer) !== false ? t_ok("--help 显示当前版本 {$curVer}") : t_no('版本号', "未在 --help 找到 {$curVer}");
// awa 档已在定稿前撤掉：--help 不应再出现它（避免文档/CLI 残留）
strpos($help, '--level=awa') === false && strpos($help, '-awa ') === false
    ? t_ok('--help 已无 awa 档残留')
    : t_no('awa 残留', '--help 仍提到 awa 档');
foreach (['min', 'mid', 'max'] as $lv) {
    $r = case_run($entry, $src, $tmp . '/smoke_' . $lv . '.php', ['--level=' . $lv, '--salt=smoke']);
    $r['ok'] ? t_ok("{$lv} 档冒烟通过") : t_no("{$lv} 冒烟", $r['why']);
}


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
@array_map('unlink', glob($tmp . '/*.json'));
@rmdir($tmp);
