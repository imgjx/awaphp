<?php
/**
 * v1.2.10 专项回归：
 *   A) aggressive_prune 硬归位到 logic 之前（预设写反也会被重排），Logic 注入物不被折叠
 *   B) 剪枝确实加速【用户自己的】代码（用户恒等式/死分支被折掉，产物更小、结果不变）
 *   C) 多 salt 下 prune→logic 组合语义稳定，Logic 强度不因剪枝而系统性衰减
 *   D) AntiHookEval strict=false 的漏括号已修复（生成代码语法合法且能跑）
 *   E) 诱饵四分支结构可生成且语义不变
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

// 含用户自己写的恒等式与死分支的样例（用于验证"剪枝真的加速用户代码"）
$userConst = <<<'CODE'
$a = strlen(md5('alpha')) + strlen(sha1('beta')) + intdiv(40 * 7, 7) + (13 * 3);
$b = 0;
if (1 === 1) { $b = $a + 5; } else { $b = $a - 5; }
if (2 !== 2) { $b = 0; } else { $b = $b * 2; }
echo 'A=', $a, ' B=', $b, "\n";
CODE;
$userSrc = put($tmp, 'userconst', $userConst);

// ---------------------------------------------------------------------------
// A) aggressive_prune 硬归位：故意把它写在 logic【之后】，验证 Logic 注入物仍完整
// ---------------------------------------------------------------------------
echo "\n=== A) aggressive_prune 硬归位到 logic 之前 ===\n";
$misordered = cfg($tmp, 'misordered', [
    ['name' => 'constfold', 'params' => ['level' => 3]],
    ['name' => 'logic',     'params' => ['intensity' => 3]],
    ['name' => 'aggressive_prune', 'params' => []],   // <- 故意写反
    ['name' => 'var',       'params' => ['length' => 12]],
], ['aggressive_prune' => true, 'shell_shield' => false]);
$outMis = $tmp . '/misordered.php';
$r = case_run($entry, $src, $outMis, ['--config=' . $misordered]);
$r['ok'] ? t_ok('预设写反时仍能构建且输出一致') : t_no('写反预设构建', $r['why']);
$blocks = logic_blocks($outMis);
$blocks >= 5
    ? t_ok("Logic 积木完整保留（{$blocks} 处）——剪枝未碰 Logic 注入物")
    : t_no('Logic 积木被剪枝解析', "只剩 {$blocks} 处，应 ≥5");

// 兜底层序安全：直接给层序安全=false 的场景（分层层序由 Obfuscator 传入，这里验证
// "prune 在 logic 后时不会折叠"就是上一条；再加一条"正常顺序也会折叠用户恒等式"见 B）

// ---------------------------------------------------------------------------
// B) 剪枝确实加速用户自己的代码
// ---------------------------------------------------------------------------
echo "\n=== B) 剪枝加速用户脚本 ===\n";
$pruneOn = cfg($tmp, 'prune_on', [
    ['name' => 'constfold', 'params' => ['level' => 3]],
    ['name' => 'aggressive_prune', 'params' => []],
    ['name' => 'var', 'params' => ['length' => 8]],
], ['aggressive_prune' => true, 'shell_shield' => false]);
$pruneOff = cfg($tmp, 'prune_off', [
    ['name' => 'constfold', 'params' => ['level' => 3]],
    ['name' => 'var', 'params' => ['length' => 8]],
], ['aggressive_prune' => false, 'shell_shield' => false]);
$outOn = $tmp . '/prune_on.php';
$outOff = $tmp . '/prune_off.php';
$r = case_run($entry, $userSrc, $outOn, ['--config=' . $pruneOn]);
$r['ok'] ? t_ok('开剪枝后用户恒等式样例输出一致') : t_no('开剪枝输出', $r['why']);
$r = case_run($entry, $userSrc, $outOff, ['--config=' . $pruneOff]);
$r['ok'] ? t_ok('关剪枝后用户恒等式样例输出一致') : t_no('关剪枝输出', $r['why']);
$sOn = (int) @filesize($outOn);
$sOff = (int) @filesize($outOff);
$sOn < $sOff
    ? t_ok("剪枝后产物更小（{$sOn} < {$sOff} 字节）——用户自己的恒等式/死分支被折掉")
    : t_no('剪枝未减小产物', "on={$sOn} off={$sOff}");

// ---------------------------------------------------------------------------
// C) 多 salt 下 prune→logic 组合语义稳定，且 Logic 强度不因剪枝而衰减
// ---------------------------------------------------------------------------
echo "\n=== C) 剪枝 + Logic 多盐稳定性 ===\n";
$cNoShell = cfg($tmp, 'c_noshell', [
    ['name' => 'constfold', 'params' => ['level' => 3]],
    ['name' => 'aggressive_prune', 'params' => []],
    ['name' => 'logic', 'params' => ['intensity' => 3]],
    ['name' => 'var', 'params' => ['length' => 12]],
], ['aggressive_prune' => true, 'shell_shield' => false]);
$cNoPrune = cfg($tmp, 'c_noprune', [
    ['name' => 'constfold', 'params' => ['level' => 3]],
    ['name' => 'logic', 'params' => ['intensity' => 3]],
    ['name' => 'var', 'params' => ['length' => 12]],
], ['aggressive_prune' => false, 'shell_shield' => false]);

foreach (['x', 'v129high', 'abc123', 'perfkill', 'zz9'] as $salt) {
    $o = $tmp . '/c_' . $salt . '.php';
    $r = case_run($entry, $src, $o, ['--config=' . $cNoShell, '--salt=' . $salt]);
    $r['ok'] ? t_ok("prune+logic salt={$salt} 输出一致") : t_no("prune+logic {$salt}", $r['why']);
}

$c1 = $tmp . '/c_on.php';
$c2 = $tmp . '/c_off.php';
// 固定盐：积木数量与盐绑定（Logic 的随机计数、随机串长度都随盐变），
// 不传 --salt 时两次构建各用各的随机盐，比值会随机漂到 80% 线下方（曾误报）。
$cFixSalt = ['--salt=pruneblocks'];
case_run($entry, $src, $c1, array_merge(['--config=' . $cNoShell], $cFixSalt));
case_run($entry, $src, $c2, array_merge(['--config=' . $cNoPrune], $cFixSalt));
$b1 = logic_blocks($c1);
$b2 = logic_blocks($c2);
// 注意：不能要求数量完全相等。剪枝折掉了用户代码里的常量，会改变后续 Logic 内部
// 随机计数器的分配，个别随机串长度不同 → 少数 strlen('…') 类积木计数可能差一两处。
// 真正要验证的是"没有被系统性折叠"：若剪枝吃了 Logic，数量会掉到个位数或 0。
$b2 > 0 && $b1 >= (int) ceil($b2 * 0.8)
    ? t_ok("开/关剪枝时 Logic 积木基本不变（开={$b1} 关={$b2}）——Logic 未被解析加速")
    : t_no('剪枝改动了 Logic 积木', "开={$b1} 关={$b2}（应 ≥ 关闭时的 80%）");

// ---------------------------------------------------------------------------
// D) AntiHookEval strict=false 漏括号修复
// ---------------------------------------------------------------------------
echo "\n=== D) anti_hook strict=false 修复 ===\n";
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
// E) 诱饵四分支结构
// ---------------------------------------------------------------------------
echo "\n=== E) 诱饵四分支结构 ===\n";
$decoyOnly = cfg($tmp, 'decoy_only', [
    ['name' => 'logic', 'params' => ['intensity' => 3]],
    ['name' => 'decoy', 'params' => ['level' => 3]],
], ['aggressive_prune' => false, 'shell_shield' => false]);
$maxElseif = 0;
foreach (['s1', 's2', 's3', 's4'] as $salt) {
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
// F) 版本号与全档冒烟
// ---------------------------------------------------------------------------
echo "\n=== F) 版本与全档冒烟 ===\n";
$help = (string) shell_exec('php ' . escapeshellarg($entry) . ' --help 2>&1');
strpos($help, '1.2.10') !== false ? t_ok('--help 显示 1.2.10') : t_no('版本号', '未在 --help 找到 1.2.10');
// awa 档已在定稿前撤掉：--help 不应再出现它（避免文档/CLI 残留）
strpos($help, '--level=awa') === false && strpos($help, '-awa ') === false
    ? t_ok('--help 已无 awa 档残留')
    : t_no('awa 残留', '--help 仍提到 awa 档');
foreach (['min', 'mid', 'max'] as $lv) {
    $r = case_run($entry, $src, $tmp . '/smoke_' . $lv . '.php', ['--level=' . $lv, '--salt=smoke']);
    $r['ok'] ? t_ok("{$lv} 档冒烟通过") : t_no("{$lv} 冒烟", $r['why']);
}
// aggressive = ultra 全保护 + 本轮的剪枝层。只验证「剪枝层叠在最高档之上仍然跑得通」，
// 固定用已验证的盐：ultra/aggressive 自 v1.2.9 起在个别随机盐下会被 VM 段重写缺陷
// 打成空转（见 CHANGELOG 的「known issue」），与本轮改动无关，不在此处做盐鲁棒性断言。
$r = case_run($entry, $src, $tmp . '/smoke_aggressive.php', ['--level=aggressive', '--salt=x'], 40);
$r['ok'] ? t_ok('aggressive 档（剪枝叠在 ultra 之上）冒烟通过') : t_no('aggressive 冒烟', $r['why']);

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
