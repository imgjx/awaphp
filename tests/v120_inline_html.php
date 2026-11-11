<?php
declare(strict_types=1);
/**
 * v1.2.0 高级 PHP/HTML 混写回归
 *
 * InlineHtml::normalize() 在管线入口把 T_INLINE_HTML / ?> / <?= / 替代语法等价改写为
 * 纯 PHP，使 eval/gz/VM 壳、module_link、self-protect、控制流混淆都只面对可安全处理的
 * token 流。此前的三个典型故障：
 *   - high/VM 档 eval() 内遇 HTML 段 unexpected token "<"；
 *   - module_link 把函数定义追加到 ?> 之后的 HTML 里，语法损坏并回滚；
 *   - 末尾 ?> 吞换行 + rtrim 导致输出少 1 字节。
 *
 * 断言：
 *   [1] 两份混写夹具（复杂 TP8 模板 + 边界夹具）× 六档预设逐字节一致、0 警告；
 *   [2] InlineHtml::needs() 对纯 PHP 为 false、对混写为 true；
 *   [3] normalize() 产物 php -l 通过、运行输出与原生一致、无 <?= / 替代语法结束标记；
 *   [4] 混写 + 逐层内联组合仍等价；
 *   [5] 多 salt × 多档稳定。
 */
$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp   = sys_get_temp_dir() . '/v120_ih_' . getmypid();
@mkdir($tmp, 0755, true);
require_once $root . '/src/Support/InlineHtml.php';

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
function buildIh(string $php, string $entry, string $in, string $out, string $opts): bool
{
    $r = run(sprintf('%s %s --input=%s --output=%s %s --format=text',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($in), escapeshellarg($out), $opts));
    return $r['exit'] === 0 && is_file($out) && filesize($out) > 0;
}

$fixtures = [
    'mixed_advanced.php' => $root . '/tests/fixtures/mixed_advanced.php',
    'mixed_edge.php'     => $root . '/tests/fixtures/mixed_edge.php',
];
$presets = ['min', 'low', 'mid', 'high', 'max', 'ultra'];

echo "===== v1.2.0 高级 PHP/HTML 混写回归 =====\n\n";

echo "-- [1] 两份夹具 × 六档预设 --\n";
foreach ($fixtures as $label => $in) {
    $native = run(escapeshellarg($php) . ' ' . escapeshellarg($in));
    ok($native['exit'] === 0 && !preg_match('/(Warning|Notice|Deprecated|Fatal)/i', $native['out']),
        "{$label} 原生无警告", substr($native['out'], 0, 100));
    foreach ($presets as $lv) {
        $out = "{$tmp}/" . basename($label, '.php') . "_{$lv}.php";
        $b = buildIh($php, $entry, $in, $out, "--level={$lv} --salt=ih{$lv}");
        ok($b, "{$label} / {$lv} 构建");
        if (!$b) { continue; }
        ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($out))['out'], 'No syntax errors'),
            "{$label} / {$lv} php -l 通过");
        $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
        ok($rr['exit'] === 0 && $rr['out'] === $native['out']
            && !preg_match('/(Fatal error|Parse error|Warning|Deprecated|Notice)/i', $rr['out']),
            "{$label} / {$lv} 逐字节一致 / 0 警告", substr($rr['out'], 0, 100));
    }
}

echo "\n-- [2][3] InlineHtml 直接单测 --\n";
ok(InlineHtml::needs("<?php echo 1;\n") === false, '纯 PHP 源码 needs()=false');
ok(InlineHtml::needs("<?php echo 1; ?>\n<b>hi</b>\n") === true, '含 T_CLOSE_TAG 的源码 needs()=true');
ok(InlineHtml::needs("<?= \$x ?>\n") === true, '含 <?= 短标签的源码 needs()=true');

foreach ($fixtures as $label => $in) {
    $src = (string) file_get_contents($in);
    $norm = InlineHtml::normalize($src);
    $normFile = "{$tmp}/norm_" . basename($label, '.php') . '.php';
    file_put_contents($normFile, $norm);
    ok(str_contains(run(escapeshellarg($php) . ' -l ' . escapeshellarg($normFile))['out'], 'No syntax errors'),
        "{$label} normalize 产物 php -l 通过");
    // 注释文字里可能含 "<?=" 字样（例如夹具头部说明），须用 token 级判定
    $shortTags = 0;
    foreach (token_get_all($norm) as $t) {
        if (is_array($t) && $t[0] === T_OPEN_TAG_WITH_ECHO) { $shortTags++; }
    }
    ok($shortTags === 0, "{$label} normalize 后无 <?= 短标签（token 级）", 'count=' . $shortTags);
    $altEnd = 0;
    foreach (['endif;', 'endforeach;', 'endfor;', 'endwhile;', 'endswitch;'] as $kw) {
        $altEnd += substr_count($norm, $kw);
    }
    ok($altEnd === 0, "{$label} 替代语法已归一为花括号", 'count=' . $altEnd);
    $nr = run(escapeshellarg($php) . ' ' . escapeshellarg($normFile));
    $or = run(escapeshellarg($php) . ' ' . escapeshellarg($in));
    ok($nr['exit'] === 0 && $nr['out'] === $or['out'], "{$label} normalize 后运行输出等价",
        'norm=' . strlen($nr['out']) . ' orig=' . strlen($or['out']));
}

echo "\n-- [4] 混写 + 逐层内联 --\n";
foreach ($fixtures as $label => $in) {
    $native = run(escapeshellarg($php) . ' ' . escapeshellarg($in))['out'];
    foreach (['low', 'high', 'ultra'] as $lv) {
        $out = "{$tmp}/il_" . basename($label, '.php') . "_{$lv}.php";
        $b = buildIh($php, $entry, $in, $out, "--level={$lv} --inline-each-layer --salt=ihil{$lv}");
        ok($b, "{$label} / {$lv} + 逐层内联 构建");
        if (!$b) { continue; }
        $rr = run(escapeshellarg($php) . ' ' . escapeshellarg($out));
        ok($rr['exit'] === 0 && $rr['out'] === $native, "{$label} / {$lv} + 逐层内联 逐字节一致",
            substr($rr['out'], 0, 100));
    }
}

echo "\n-- [5] 多 salt --\n";
foreach (['ihS1', 'ihS2', 'ihS3'] as $s) {
    foreach (['min', 'ultra'] as $lv) {
        $in = $fixtures['mixed_edge.php'];
        $native = run(escapeshellarg($php) . ' ' . escapeshellarg($in))['out'];
        $out = "{$tmp}/salt_{$s}_{$lv}.php";
        $b = buildIh($php, $entry, $in, $out, "--level={$lv} --salt={$s}");
        $rr = $b ? run(escapeshellarg($php) . ' ' . escapeshellarg($out)) : ['out' => '', 'exit' => 1];
        ok($b && $rr['exit'] === 0 && $rr['out'] === $native, "salt={$s} / {$lv} 运行一致");
    }
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
foreach ($fails as $f) { echo "  FAIL: {$f}\n"; }
exit($fail === 0 ? 0 : 1);
