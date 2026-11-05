<?php
/**
 * 六档预设端到端回归（min / low / mid / high / max / ultra）
 *
 * 为什么单独做这一步：预设走的是【分层管线 runLayered】，与命令行逐项开关的
 * legacy 单管线是两套代码路径。历史上多次出现「单管线正常、预设档位致命错误」
 * 的漏网（例如 v1.1.4 的 VM 内自检：绑定标记只在 legacy 注入，high/max/ultra
 * 因此被判定为脱壳，产物一运行就指令流错乱）。故每个预设都必须有一个
 * 「构建 → php -l → 运行 → 与原生逐字节比对」的兜底回归。
 *
 * 断言：
 *   1. 构建成功（退出码 0，产物非空）；
 *   2. 产物通过 php -l；
 *   3. 运行输出与原生逐字节一致；
 *   4. 运行期无 Fatal / Parse error / Warning；
 *   5. 产物只有一个 <?php 开标签、无闭合标签（多壳嵌套的结构不变式）。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$php  = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp  = sys_get_temp_dir() . '/preset_e2e_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;
$fails = [];

function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $fails;
    if ($cond) {
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $fail++;
        $fails[] = $name . ' :: ' . $detail;
        echo "  \033[31m✗\033[0m {$name}\n     {$detail}\n";
    }
}

function run(string $cmd): array
{
    exec($cmd . ' 2>&1', $out, $exit);
    return ['out' => implode("\n", $out), 'exit' => (int) $exit];
}

$presets  = ['min', 'low', 'mid', 'high', 'max', 'ultra'];
$fixtures = ['sample.php', 'strings.php', 'classes.php'];

echo "===== 六档预设端到端回归 =====\n";
echo 'PHP: ' . PHP_VERSION . "\n\n";

foreach ($presets as $preset) {
    echo "-- 预设 {$preset} --\n";
    foreach ($fixtures as $fixture) {
        $in  = $root . '/tests/fixtures/' . $fixture;
        $out = $tmp . '/' . $preset . '_' . $fixture;

        $native = run(escapeshellarg($php) . ' ' . escapeshellarg($in));
        $built  = run(sprintf(
            '%s %s --input=%s --output=%s -%s --format=text',
            escapeshellarg($php),
            escapeshellarg($entry),
            escapeshellarg($in),
            escapeshellarg($out),
            $preset
        ));

        $label = "{$preset}/{$fixture}";
        if ($built['exit'] !== 0 || !is_file($out) || filesize($out) === 0) {
            ok(false, "{$label} 构建", 'exit=' . $built['exit'] . ' out=' . substr($built['out'], 0, 200));
            continue;
        }

        $lint = run(escapeshellarg($php) . ' -l ' . escapeshellarg($out));
        ok($lint['exit'] === 0, "{$label} php -l", substr($lint['out'], 0, 200));

        $code = (string) file_get_contents($out);
        // 开标签大小写不敏感（PHP 语法上 `<?php` / `<?PHP` / `<?Php` 等价），
        // 故此处必须忽略大小写统计——token 形态层的 case 随机化会把开标签写成
        // `<?PHP`，用大小写敏感的 substr_count 判定会误报「开标签数为 0」。
        $lower = strtolower($code);
        ok(substr_count($lower, '<?php') === 1 && strpos($lower, '?>') === false,
            "{$label} 结构不变式（单开标签 / 无闭标签）",
            'open=' . substr_count($lower, '<?php') . ' close=' . substr_count($lower, '?>'));

        $got = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
        ok(trim($got['out']) === trim($native['out']), "{$label} 输出与原生一致",
            'native=' . substr(trim($native['out']), 0, 120) . ' | got=' . substr(trim($got['out']), 0, 120));
        ok(stripos($got['out'], 'Fatal error') === false
            && stripos($got['out'], 'Parse error') === false
            && stripos($got['out'], 'Warning:') === false,
            "{$label} 无错误/告警", substr(trim($got['out']), 0, 200));
    }
    echo "\n";
}

// 失败时【保留产物】以便复现：偶发失败（flaky）此前因产物被无条件删除而无法定位。
// 全部通过才清理临时目录。
if ($fail === 0) {
    @array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
} else {
    echo "\n[诊断] 存在失败项，产物已保留以便复现：{$tmp}\n";
    foreach (glob($tmp . '/*') ?: [] as $f) {
        $sz = @filesize($f);
        if ($sz !== false && $sz > 0) {
            echo "  - {$f} ({$sz} bytes)\n";
        }
    }
}

echo "===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($fail > 0) {
    echo "失败项：\n";
    foreach ($fails as $f) {
        echo "  - {$f}\n";
    }
}
exit($fail === 0 ? 0 : 1);
