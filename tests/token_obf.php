<?php
/**
 * Token 形态混淆测试（tests/token_obf.php）
 *
 * 两层验证：
 *  1) 语义保真：各配置组合下，混淆产物运行输出与原生逐字节一致（含 php -l）；
 *  2) 结构实效：每个子特性确实改变了产物形态（混合大小写关键字 / !0 / cast 别名 /
 *     array()→[] / 引号翻转 / heredoc 化 / 诱饵注释 / 插值区间保护 / no-comments 门控）。
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$entry = $root . '/entry.php';
$fixture = $root . '/tests/fixtures/token_rich.php';
$tmp = sys_get_temp_dir() . '/token_obf_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

function runCapture(string $cmd): array
{
    exec($cmd . ' 2>&1', $out, $exit);
    return ['out' => implode("\n", $out), 'exit' => (int) $exit];
}

/** 跑一遍混淆，返回 [产物路径, 产物源码] */
function obfuscate(string $php, string $entry, string $fixture, string $tmp, string $name, string $opts): array
{
    $out = $tmp . '/' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.php';
    $cmd = sprintf('%s %s --input=%s --output=%s %s --format=text 2>&1',
        escapeshellarg($php), escapeshellarg($entry), escapeshellarg($fixture), escapeshellarg($out), $opts);
    $r = runCapture($cmd);
    if ($r['exit'] !== 0 || !is_file($out)) {
        throw new RuntimeException("混淆失败: " . $r['out']);
    }
    return [$out, (string) file_get_contents($out)];
}

$orig = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($fixture)));
if ($orig['exit'] !== 0) {
    fwrite(STDERR, "fixture 原生运行失败: " . $orig['out'] . "\n");
    exit(1);
}

echo "===== [1] 语义保真：各配置组合输出一致 =====\n";
$combos = [
    'token_default'      => '--token-obf --salt=tok1',
    'token_case_only'    => '--token-obf --token-no-literal --token-no-quote --salt=tok2',
    'token_literal_only' => '--token-obf --token-no-case --token-no-quote --salt=tok3',
    'token_quote_only'   => '--token-obf --token-no-case --token-no-literal --salt=tok4',
    'token_heredoc'      => '--token-obf --token-heredoc --salt=tok5',
    'token_no_decoy'     => '--token-obf --token-decoy=0 --salt=tok6',
    'token_nocomments'   => '--token-obf --no-comments --salt=tok7',
    'token_repeat2'      => '--token-obf --repeat=2 --salt=tok8',
    'token_with_others'  => '--token-obf --num-enc --str-length=3 --insert-dead --var-length=8 --func-length=8 --salt=tok9',
    'token_with_cfg'     => '--token-obf --cfg=high --goto=2 --salt=tok10',
    'token_with_vm'      => '--token-obf --vm --salt=tok11',
    'token_with_vm_p8'   => '--token-obf --vm --vm-p8 --salt=tok12',
    'token_with_eval'    => '--token-obf --eval --eval-layers=2 --salt=tok13',
    'token_full'         => '--token-obf --token-heredoc --var-length=10 --func-length=10 --class-obf --str-xor --num-enc --insert-dead --no-comments --compress --cfg=high --goto=3 --vm --vm-p8 --salt=tok14',
    'token_xor_heredoc'  => '--token-obf --token-heredoc --str-xor --salt=tok15',
];
foreach ($combos as $name => $opts) {
    try {
        [$file, $src] = obfuscate($php, $entry, $fixture, $tmp, $name, $opts);
    } catch (RuntimeException $e) {
        ok(false, $name, $e->getMessage());
        continue;
    }
    $lint = runCapture(sprintf('%s -l %s', escapeshellarg($php), escapeshellarg($file)));
    if ($lint['exit'] !== 0) {
        ok(false, $name, 'php -l 失败: ' . $lint['out']);
        continue;
    }
    $obf = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($file)));
    if ($obf['exit'] !== 0) {
        ok(false, $name, '运行失败: ' . $obf['out']);
        continue;
    }
    ok($obf['out'] === $orig['out'], $name, '输出不一致: ' . json_encode(substr($obf['out'], 0, 200)));
}

echo "\n===== [2] 结构实效：每个子特性确实生效 =====\n";
[$fDef, $srcDef]   = obfuscate($php, $entry, $fixture, $tmp, 'struct_def', '--token-obf --token-decoy=5 --salt=st1');
[$fCase, $srcCase] = obfuscate($php, $entry, $fixture, $tmp, 'struct_case', '--token-obf --token-no-literal --token-no-quote --token-decoy=0 --salt=st2');
[$fLit, $srcLit]   = obfuscate($php, $entry, $fixture, $tmp, 'struct_lit', '--token-obf --token-no-case --token-no-quote --token-decoy=0 --salt=st3');
[$fQuote, $srcQuote] = obfuscate($php, $entry, $fixture, $tmp, 'struct_quote', '--token-obf --token-no-case --token-no-literal --token-decoy=0 --salt=st4');
[$fHd, $srcHd]     = obfuscate($php, $entry, $fixture, $tmp, 'struct_hd', '--token-obf --token-heredoc --token-no-case --token-no-literal --token-no-quote --token-decoy=0 --salt=st5');
[$fNc, $srcNc]     = obfuscate($php, $entry, $fixture, $tmp, 'struct_nc', '--token-obf --token-heredoc --no-comments --salt=st6');
[$fArr, $srcArr]   = obfuscate($php, $entry, $fixture, $tmp, 'struct_arr', '--token-obf --token-no-case --token-no-quote --token-heredoc --token-decoy=0 --salt=st7');
[$fCast, $srcCast] = obfuscate($php, $entry, $fixture, $tmp, 'struct_cast', '--token-obf --token-no-case --token-no-quote --token-heredoc --token-decoy=0 --salt=st8');

// 2a. 关键字大小写：规范写法被消灭（应存在混合大小写形态，且非全部规范）
$kwMixed = preg_match_all('/\b(?:fUnCtIoN|FuNcTiOn|eChO|EcHo|WhIlE|wHiLe|ReTuRn|rEtUrN|ArRaY|aRrAy|InTeGeR|InTeGer|InT)\b/', $srcCase) >= 3;
ok($kwMixed, '关键字大小写随机化生效（存在混合大小写关键字）');
// token 级复核：随机化是逐 token 概率性的（固定盐确定但对 fixture 变动敏感），
// 故统计"非规范大小写"的关键字 token 数，而非枚举具体形态
$kwNonCanon = 0;
foreach (token_get_all($srcCase) as $tk) {
    if (!is_array($tk)) {
        continue;
    }
    if (in_array(token_name($tk[0]), ['T_STRING', 'T_VARIABLE', 'T_CONSTANT_ENCAPSED_STRING', 'T_ENCAPSED_AND_WHITESPACE', 'T_INLINE_HTML', 'T_COMMENT', 'T_DOC_COMMENT', 'T_WHITESPACE', 'T_OPEN_TAG', 'T_CLOSE_TAG', 'T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES', 'T_STRING_VARNAME', 'T_NUM_STRING', 'T_OPEN_TAG_WITH_ECHO', 'T_END_HEREDOC'], true)) {
        continue; // 标识符/字符串/heredoc 标签等非关键字类
    }
    if (preg_match('/^[A-Za-z_]+$/', $tk[1]) === 1 && $tk[1] !== strtolower($tk[1])) {
        $kwNonCanon++;
    }
}
ok($kwNonCanon >= 3, '规范全小写关键字已被打散（非规范关键字 token ≥3）', "nonCanon=$kwNonCanon");

// 2b. true/false -> !0/!1（逐字面量概率 1/2：单盐可能只翻一种，多盐并集）
$litHas0 = strpos($srcLit, '!0') !== false;
$litHas1 = strpos($srcLit, '!1') !== false;
for ($s = 0; $s < 4 && !($litHas0 && $litHas1); $s++) {
    [, $srcMore] = obfuscate($php, $entry, $fixture, $tmp, "struct_lit_m$s", '--token-obf --token-no-case --token-no-quote --token-decoy=0 --salt=lit' . $s);
    $litHas0 = $litHas0 || strpos($srcMore, '!0') !== false;
    $litHas1 = $litHas1 || strpos($srcMore, '!1') !== false;
}
ok($litHas0 && $litHas1, 'true→!0 / false→!1 生效');

// 2c. cast 别名：(integer)/(boolean)/(double) 出现
$castAlias = (bool) preg_match('/\(\s*integer\s*\)/i', $srcCast) || (bool) preg_match('/\(\s*boolean\s*\)/i', $srcCast) || (bool) preg_match('/\(\s*double\s*\)/i', $srcCast);
ok($castAlias, 'cast 别名互换生效（(integer)/(boolean)/(double)）');

// 2d. array(...) -> [...]：token 级统计 T_ARRAY 清零（注释文本里的 array( 不算代码）
$arrLeft = 0;
foreach (token_get_all($srcArr) as $atk) {
    if (is_array($atk) && $atk[0] === T_ARRAY) {
        $arrLeft++;
    }
}
ok($arrLeft === 0, "array(...) 全部转为 [...]（残留 T_ARRAY = {$arrLeft}）");

// 2e. 引号翻转：纯单引号串被翻成双引号（逐串概率 1/2，单盐可能没翻——多盐并集）
$sqIn = substr_count($srcQuote, "'plain single'");
$dqIn = substr_count($srcQuote, '"plain single"');
for ($s = 0; $s < 4 && $dqIn === 0; $s++) {
    [, $srcMore] = obfuscate($php, $entry, $fixture, $tmp, "struct_quote_m$s", '--token-obf --token-no-case --token-no-literal --token-decoy=0 --salt=qt' . $s);
    $sqIn += substr_count($srcMore, "'plain single'");
    $dqIn += substr_count($srcMore, '"plain single"');
}
ok($sqIn + $dqIn >= 1 && $dqIn >= 1, "引号形态翻转发生（单引号={$sqIn} 双引号={$dqIn}）");

// 2f. heredoc 化
ok(strpos($srcHd, "<<<'") !== false, 'heredoc/nowdoc 化生效（<<<\' 出现）');

// 2g. 诱饵注释：默认开（保留注释时）有、--no-comments 时无
$decoyOn = (bool) preg_match('/integrity probe v2|license cache|crc32 baseline|debug hook|key material rotated/', $srcDef);
$decoyOff = !preg_match('/integrity probe v2|license cache|crc32 baseline|debug hook|key material rotated/', $srcNc);
ok($decoyOn, '诱饵注释注入生效（默认 3 个）');
ok($decoyOff, '--no-comments 时诱饵注释被门控（不注入）');
// no-comments 产物里连 heredoc 化的字符串都应保留（注释门控不影响其它子特性）
ok(strpos($srcNc, "<<<'") !== false, '--no-comments 与 heredoc 化可共存');

// 2h. 插值区间保护：含插值的串不能被破坏（"pre {$arr['key']} post" 的 'key' 不能翻引号）
//     语义保真已覆盖；这里断言产物中不存在被破坏的痕迹（如 "$arr[" 这种断裂插值）
$broken = preg_match('/\{\$[a-zA-Z_\x80-\xff][\w\x80-\xff]*\["/', $srcDef) === 1;
ok(!$broken, '插值区间内字符串未被引号翻转（{$arr["key"]} 断裂形态不存在）');

echo "\n===== [3] 等级预设（含 token 层）=====\n";
foreach (['low', 'mid', 'high', 'max'] as $lv) {
    try {
        [$file, $src] = obfuscate($php, $entry, $fixture, $tmp, "level_$lv", "--level=$lv --salt=lv$lv");
    } catch (RuntimeException $e) {
        ok(false, "level=$lv", $e->getMessage());
        continue;
    }
    $obf = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($file)));
    ok($obf['exit'] === 0 && $obf['out'] === $orig['out'], "level=$lv 输出一致");
}

echo "\n===== [4] CLI 组合冒烟（token + 其它模块全开）=====\n";
try {
    [$file, $src] = obfuscate($php, $entry, $fixture, $tmp, 'kitchen_sink',
        '--token-obf --token-heredoc --var-length=8 --var-split=2 --func-length=8 --func-call --func-call-enc --class-obf '
        . '--str-length=4 --str-xor --html --num-enc --gz --insert-dead --no-comments --compress '
        . '--cfg=high --goto=3 --goto-cpx=4 --vm --vm-p8 --eval --eval-layers=2 --anti-debug --anti-tamper '
        . '--junk-funcs=3 --php-version=8.3 --repeat=2 --salt=kitchen');
    $obf = runCapture(sprintf('%s %s', escapeshellarg($php), escapeshellarg($file)));
    ok($obf['exit'] === 0 && $obf['out'] === $orig['out'], '厨房水槽全组合输出一致');
} catch (RuntimeException $e) {
    ok(false, '厨房水槽全组合', $e->getMessage());
}

function _cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? _cleanup($p) : @unlink($p);
    }
    @rmdir($dir);
}

echo "\nTOTAL=$pass FAIL=$fail\n";
_cleanup($tmp);
exit($fail === 0 ? 0 : 1);
