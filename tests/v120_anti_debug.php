<?php
declare(strict_types=1);
/**
 * v1.2.0 反调试加强回归
 *
 * 新增探针组 func2（xdebug_call_* / phpdbg_* 等）、ini2（opcache.jit_debug 等），
 * response=silent（@ob_start + $GLOBALS 命中标记 + shutdown 清空全部输出缓冲），
 * multipoint（shutdown 二次判定，覆盖运行中 attach）。silent 的判定与反制分离，
 * 避免"命中即 exit"暴露检测点。
 *
 * 断言：
 *   [1] 16 种参数组合（probes × response × multipoint × intensity）构建 + php -l
 *       + 未命中时运行输出一致 + 无未知参数告警；
 *   [2] exit 响应：ini2 命中时非零退出，未命中时正常；
 *   [3] silent 响应：ini2 命中时输出被清空（0 字节），未命中时输出完整；
 *   [4] func2/ini2 探针组被接受（不落入 WARN 忽略名单）。
 */
$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/v120_ad_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0; $fail = 0; $fails = [];
function ok(bool $c, string $m, string $d = ''): void
{
    global $pass, $fail, $fails;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m {$m}\n"; }
    else { $fail++; $fails[] = "{$m} :: {$d}"; echo "  \033[31m✗\033[0m {$m}\n     {$d}\n"; }
}
function run(string $cmd): array
{
    exec($cmd . ' 2>&1', $o, $ec);
    return ['out' => implode("\n", $o), 'exit' => (int) $ec];
}
function buildAd(string $php, string $entry, string $in, string $out, string $opts, string &$log = ''): bool
{
    $r = run(sprintf('%s %s --input=%s --output=%s %s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($in), escapeshellarg($out), $opts));
    $log = $r['out'];
    return $r['exit'] === 0 && is_file($out) && filesize($out) > 0;
}

$fixture = $root . '/tests/fixtures/demo.php';
$native  = run(escapeshellarg($php) . ' ' . escapeshellarg($fixture))['out'];

echo "===== v1.2.0 反调试加强回归 =====\n\n";

echo "-- [1] 参数矩阵（16 组）--\n";
$probes = [
    'ext,func,ini,env,sapi,proc',
    'ext,func,ini,env,sapi,trace,proc,func2,ini2',
];
$i = 0;
foreach ($probes as $pr) {
    foreach (['exit', 'silent'] as $resp) {
        foreach ([true, false] as $mp) {
            foreach ([2, 4] as $intensity) {
                $p = ['probes' => $pr, 'response' => $resp, 'multipoint' => $mp, 'intensity' => $intensity];
                $cfg = ['layers' => [['name' => 'shell:anti_debug', 'params' => $p]], 'shell_shield' => false];
                $cfgFile = "{$tmp}/ad_{$i}.json";
                file_put_contents($cfgFile, json_encode($cfg));
                $out = "{$tmp}/ad_{$i}.php";
                $log = '';
                $b = buildAd($php, $entry, $fixture, $out,
                    '--config=' . escapeshellarg($cfgFile) . ' --salt=ad' . $i, $log);
                $label = '#' . $i . ' ' . ($pr === $probes[1] ? '扩展探针' : '基础探针')
                    . " resp={$resp} mp=" . (int) $mp . " int={$intensity}";
                ok($b, "构建 {$label}", substr($log, -160));
                if (!$b) { $i++; continue; }
                ok(!str_contains($log, '[WARN]'), "{$label} 无未知参数告警", substr($log, -160));
                ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($out))['out'], 'No syntax errors'),
                    "{$label} php -l 通过");
                $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
                ok($rr['exit'] === 0 && $rr['out'] === $native, "{$label} 未命中时运行一致", substr($rr['out'], 0, 90));
                $i++;
            }
        }
    }
}

echo "\n-- [2][3] ini2 命中行为（判定与反制分离）--\n";
$iniAvailable = ini_get('opcache.jit_debug') !== false;
if (!$iniAvailable) {
    echo "  (跳过：当前 PHP 未注册 opcache.jit_debug，无法用 -d 触发 ini2)\n";
} else {
    foreach (['exit', 'silent'] as $resp) {
        $p = ['probes' => 'ini2', 'response' => $resp, 'multipoint' => true, 'intensity' => 2];
        $cfg = ['layers' => [['name' => 'shell:anti_debug', 'params' => $p]], 'shell_shield' => false];
        $cfgFile = "{$tmp}/hit_{$resp}.json";
        file_put_contents($cfgFile, json_encode($cfg));
        $out = "{$tmp}/hit_{$resp}.php";
        $log = '';
        $b = buildAd($php, $entry, $fixture, $out,
            '--config=' . escapeshellarg($cfgFile) . ' --salt=hit' . $resp, $log);
        ok($b, "命中样本构建 response={$resp}", substr($log, -160));
        if (!$b) { continue; }

        $normal = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
        ok($normal['exit'] === 0 && $normal['out'] === $native,
            "response={$resp} 未命中时正常输出", 'out=' . substr($normal['out'], 0, 80));

        $hit = run(escapeshellarg($php) . ' -d opcache.jit_debug=1 ' . escapeshellarg($out));
        if ($resp === 'exit') {
            ok($hit['exit'] !== 0, 'exit：ini2 命中时非零退出',
                'exit=' . $hit['exit'] . ' out=' . substr($hit['out'], 0, 90));
        } else {
            ok(trim($hit['out']) === '', 'silent：ini2 命中时输出被清空',
                'exit=' . $hit['exit'] . ' out=' . substr($hit['out'], 0, 90));
        }
    }
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) { echo "  FAIL: {$f}\n"; }
exit($fail === 0 ? 0 : 1);
