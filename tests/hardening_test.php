<?php
/**
 * 加固专项测试（Stage4 硬化）
 *
 * 覆盖 v2 硬化引入的能力与"外部测评指出的 7 个弱点"的回归防线：
 *   1. 跨盐模糊：全选项 × 多 salt，产物必须 php -l 通过 + 运行输出与原文一致
 *   2. 明文指纹：产物中不得出现 __awa_ / __junk_ / integrity failed 等一眼识别串
 *   3. 命名拟真：不得出现"双下划线"式假名变量；守卫变量来自业务词池
 *   4. 防篡改：追加 / 改字节 / 截断 三类篡改必须被拦截，未篡改必须放行
 *   5. 防 hook eval：守卫存在（编码壳内可解出探针），且正常环境不影响运行
 *   6. 随机化多样性：不同 salt 下 EVAL 壳 / GOTO 状态机 / 命名 必须产生不同形态
 *   7. 整套系统：同 salt 跨文件符号对齐 + manifest 增量重建沿用旧映射
 *   8. 动态符号：call_user_func('f') / 可变函数 / 可变类名 不得被改断
 *   9. 确定性：同 salt 两次构建逐字节一致（可复现构建）
 */

$root = dirname(__DIR__);
$php  = PHP_BINARY;
$entry = $root . '/entry.php';
$tmp  = sys_get_temp_dir() . '/obf_hard_' . getmypid();
@mkdir($tmp, 0755, true);

$pass = 0;
$fail = 0;
$fails = [];

function rec(bool $ok, string $name, string $detail = ''): void
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

function run(string $cmd): array
{
    exec($cmd . ' 2>&1', $out, $exit);
    return ['out' => implode("\n", $out), 'exit' => (int) $exit];
}

/** 混淆：返回 [产物路径, 构建退出码, 构建输出] */
function obf(string $entry, string $src, string $out, string $opts): array
{
    $r = run(escapeshellarg($php ?? PHP_BINARY) . ' ' . escapeshellarg($entry)
        . ' --input=' . escapeshellarg($src)
        . ' --output=' . escapeshellarg($out) . ' ' . $opts . ' --silence');
    return [$out, $r['exit'], $r['out']];
}

/**
 * 剥离文件头版权注释（含"生成时间"，每次构建必然不同），
 * 用于比较"代码本体"是否可复现。
 */
function stripHeader(string $code): string
{
    $end = strpos($code, '*/');
    return $end === false ? $code : substr($code, $end + 2);
}

/** 执行 PHP 文件，返回输出与退出码 */
function runPhp(string $file): array
{
    return run(PHP_BINARY . ' ' . escapeshellarg($file));
}

/** 迭代解码 base64/gz 外壳，返回所有解出的明文拼接（用于验证"守卫逻辑确实存在"） */
function decodeShells(string $code, int $rounds = 4): string
{
    $all = $code;
    $frontier = $code;
    for ($r = 0; $r < $rounds; $r++) {
        $next = '';
        if (preg_match_all('/base64_decode\(\s*[\'"]([A-Za-z0-9+\/=]+)[\'"]\s*\)/', $frontier, $m)) {
            foreach ($m[1] as $b64) {
                $raw = base64_decode($b64, true);
                if ($raw === false || $raw === '') {
                    continue;
                }
                foreach ([@gzinflate($raw), @gzdecode($raw), @gzuncompress($raw)] as $cand) {
                    if (is_string($cand) && $cand !== '') {
                        $next .= $cand . "\n";
                    }
                }
                if (preg_match('/^[[:print:]\s]+$/', $raw)) {
                    $next .= $raw . "\n"; // 纯 base64 层（无 gz 包裹）
                }
            }
        }
        if ($next === '') {
            break;
        }
        $all .= "\n" . $next;
        $frontier = $next;
    }
    return $all;
}

$php = PHP_BINARY;

// 被测源码
$src = $tmp . '/src.php';
file_put_contents($src, "<?php\n"
    . "function fib(\$n){return \$n<2?\$n:fib(\$n-1)+fib(\$n-2);}\n"
    . "\$cfg=['k'=>37];\n"
    . "echo '<div>第10项: '.fib(10).' cfg='.\$cfg['k'].\"</div>\\n\";\n");
$orig = runPhp($src);

// 全选项
$ALL = '--var-length=8 --func-length=10 --class-obf --str-length=4 --num-enc --html '
     . '--func-call --func-call-enc --insert-dead --no-comments --compress '
     . '--cfg=high --goto=3 --goto-cpx=2 --vm --eval --eval-layers=3 --str-xor '
     . '--anti-debug --anti-hook --anti-tamper --junk-funcs=4 --gz --php-version=8.1';

echo "===== 加固专项测试 =====\n\n-- 1) 跨盐模糊（全选项 × 10 salt） --\n";

$salts = ['k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7', 'k8', 'k9', 'k10'];
$outputs = [];
$badLint = 0;
$badRun = 0;
$badOut = 0;
foreach ($salts as $s) {
    $out = $tmp . "/f_{$s}.php";
    [, $be, $bo] = obf($entry, $src, $out, $ALL . ' --salt=' . $s);
    $lint = run(PHP_BINARY . ' -l ' . escapeshellarg($out));
    if ($lint['exit'] !== 0) {
        $badLint++;
        echo "     [{$s}] lint: " . $lint['out'] . "\n";
        continue;
    }
    $r = runPhp($out);
    if ($r['exit'] !== 0) {
        $badRun++;
        echo "     [{$s}] run exit={$r['exit']}: " . substr($r['out'], 0, 160) . "\n";
        continue;
    }
    if (trim($r['out']) !== trim($orig['out'])) {
        $badOut++;
        echo "     [{$s}] out mismatch: " . substr($r['out'], 0, 120) . "\n";
        continue;
    }
    $outputs[$s] = (string) file_get_contents($out);
}
rec($badLint === 0, '全选项产物 10 个盐全部通过 php -l', "bad={$badLint}");
rec($badRun === 0, '全选项产物 10 个盐全部正常执行（exit=0）', "bad={$badRun}");
rec($badOut === 0, '全选项产物 10 个盐输出与原文一致', "bad={$badOut}");
rec(count($outputs) === count($salts), '全部盐均产出可用文件', 'got=' . count($outputs));

echo "\n-- 2) 明文指纹（不得出现一眼识别串） --\n";
$fingerprints = [
    '__awa_'   => '旧版守卫变量前缀',
    '__junk_'  => '旧版诱饵函数前缀',
    '__awa_k'  => '旧版字符串 XOR 全局键',
    '__awa_t'  => '旧版防篡改守卫变量',
    '__awa_vc' => '旧版版本校验变量',
    '__KEY'    => '旧版 VM 密钥变量',
    '__CODE'   => '旧版 VM 字节码变量',
    'integrity failed' => '旧版防篡改明文提示',
    'anti_dbg' => '泄露意图的守卫命名',
    'awa-obf'  => '头部注释泄露工具品牌',
];
$dirty = [];
foreach ($outputs as $s => $code) {
    foreach ($fingerprints as $fp => $why) {
        if (str_contains($code, $fp)) {
            $dirty[] = "salt={$s} 命中 '{$fp}'（{$why}）";
        }
    }
}
rec($dirty === [], '产物无明文指纹（10 个盐 × 10 个特征串）', implode('; ', array_slice($dirty, 0, 4)));

echo "\n-- 3) 命名拟真（不得出现双下划线式假名） --\n";
$fake = [];
foreach ($outputs as $s => $code) {
    // 变量里含 "__" 属于典型"混淆器生成"外观（PHP 魔术变量是常量不是变量）
    if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*__[A-Za-z0-9_]/', $code, $m)) {
        $fake[] = "salt={$s} {$m[0]}";
    }
}
rec($fake === [], '产物无 $xxx__yyy 式假名变量', implode('; ', array_slice($fake, 0, 4)));

echo "\n-- 4) 自校验防篡改（三类篡改必须拦截） --\n";
$at = $tmp . '/at.php';
obf($entry, $src, $at, $ALL . ' --salt=tamper');
$clean = runPhp($at);
rec($clean['exit'] === 0 && trim($clean['out']) === trim($orig['out']),
    '防篡改：未篡改文件正常执行且输出一致', "exit={$clean['exit']}");

// 4a 尾部追加一个空格
$t1 = $tmp . '/at_append.php';
file_put_contents($t1, file_get_contents($at) . ' ');
$r1 = runPhp($t1);
rec($r1['exit'] !== 0, '防篡改：尾部追加字符被拦截', "exit={$r1['exit']}");

// 4b 中间改写字节（改注释里的一个字符，语义无关）
$t2 = $tmp . '/at_mid.php';
$raw = (string) file_get_contents($at);
$mid = (int) (strlen($raw) / 2);
$raw[$mid] = $raw[$mid] === 'x' ? 'y' : 'x';
file_put_contents($t2, $raw);
$r2 = runPhp($t2);
rec($r2['exit'] !== 0, '防篡改：中段字节改写被拦截', "exit={$r2['exit']}");

// 4c 截断
$t3 = $tmp . '/at_trunc.php';
file_put_contents($t3, substr((string) file_get_contents($at), 0, -12));
$r3 = runPhp($t3);
rec($r3['exit'] !== 0, '防篡改：截断文件被拦截', "exit={$r3['exit']}");

echo "\n-- 5) 防 hook eval --\n";
// anti_hook 守卫本身就是一层"编码壳"：@eval(gzinflate(base64_decode('blob')))，其 blob 为 gzdeflate
// 压缩的含 uopz/runkit/phpdbg 扩展检测的 PHP 源码（刻意不以明文留特征）；外层再加 gz/eval/vm 壳后，
// 该守卫被更深藏入编码壳（这正是"藏一下"的目标）。此处用仅 --anti-hook（不套外层壳）的样本，
// 抽取并解压 blob，验证守卫逻辑确实存在且可被解出（含完整扩展检测）。
$hookProbe = $tmp . '/hook_probe.php';
obf($entry, $src, $hookProbe, '--anti-hook --salt=hookprobe');
$hookRaw = (string) file_get_contents($hookProbe);
preg_match("/gzinflate\(base64_decode\('([A-Za-z0-9+\/=]+)'\)\)\)/", $hookRaw, $mm);
$guardSrc = isset($mm[1]) ? @gzinflate(base64_decode($mm[1])) : '';
rec(is_string($guardSrc) && (str_contains($guardSrc, 'uopz') || str_contains($guardSrc, 'runkit') || str_contains($guardSrc, 'phpdbg')),
    '防 hook 守卫含可劫持 eval 的扩展检测（编码壳内 gzdeflate 解出）', '');
rec(preg_match('/@?eval\s*\(/i', $hookRaw) === 1, '防 hook 守卫含 eval 完整性探针（关键字大小写随机化后仍可识别）', '');
rec($clean['exit'] === 0, '防 hook 守卫在干净环境不误伤（exit=0）', "exit={$clean['exit']}");

echo "\n-- 6) 随机化多样性（不同 salt 形态必须不同） --\n";
$uniq = array_unique(array_map(fn ($c) => md5($c), $outputs));
rec(count($uniq) >= 9, '10 个盐产生 ≥9 种不同产物（EVAL/命名/GOTO 随机化生效）', 'uniq=' . count($uniq));

// GOTO 状态机形态多样性：同一份源码、仅换 salt，状态机代码必须逐字节不同
// （分发形态 switch/if-elseif、循环头 while(1)/while(true)/for(;;)、大小写标签均随机化，
//  多层嵌套会让多种形态共存于同一文件，因此不能靠"是否含 switch"这种粗粒度特征判断，
//  改为比较整份产物的唯一性）
$forms = [];
foreach ($salts as $s) {
    $g = $tmp . "/g_{$s}.php";
    obf($entry, $src, $g, '--goto=3 --goto-cpx=2 --salt=' . $s);
    $forms[md5(stripHeader((string) file_get_contents($g)))] = true;
}
rec(count($forms) >= 8, 'GOTO 状态机：10 个盐产生 ≥8 种不同状态机代码', 'forms=' . count($forms));

// EVAL 层编码方案多样性
$evalForms = [];
foreach ($salts as $s) {
    $e = $tmp . "/e_{$s}.php";
    obf($entry, $src, $e, '--eval --eval-layers=3 --salt=' . $s);
    $c = (string) file_get_contents($e);
    $sig = (str_contains($c, 'gzinflate') ? 'gz' : '')
         . (str_contains($c, 'strrev') ? 'rev' : '')
         . (str_contains($c, '^') ? 'xor' : '');
    $evalForms[$sig] = true;
}
rec(count($evalForms) >= 2, 'EVAL 层存在多种编码方案组合', 'forms=' . implode(',', array_keys($evalForms)));

echo "\n-- 7) 整套系统（同 salt 跨文件 + manifest 增量） --\n";
$proj = $tmp . '/proj';
$out1 = $tmp . '/proj_out';
@mkdir($proj . '/inc', 0755, true);
file_put_contents($proj . '/inc/lib.php', "<?php\nfunction shared_calc(\$n){return \$n*2;}\nfunction helper_tag(){return 'TAG';}\n");
file_put_contents($proj . '/app.php', "<?php\nrequire __DIR__.'/inc/lib.php';\necho shared_calc(21).helper_tag();\n");
$manifest = $tmp . '/manifest.json';
$r = run(PHP_BINARY . ' ' . escapeshellarg($entry) . ' --input=' . escapeshellarg($proj)
    . ' --output=' . escapeshellarg($out1) . ' --manifest=' . escapeshellarg($manifest)
    . ' --func-length=10 --var-length=8 --salt=proj --silence');
$app1 = runPhp($out1 . '/app.php');
rec($app1['exit'] === 0 && trim($app1['out']) === '42TAG',
    '整套系统：跨文件符号对齐（app 调用 lib 的函数）', "exit={$app1['exit']} out={$app1['out']}");
rec(is_file($manifest), 'manifest 已落盘', '');

// 取第一次构建里 shared_calc 的混淆名
$lib1 = (string) file_get_contents($out1 . '/inc/lib.php');
preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $lib1, $mm);
$name1 = $mm[1] ?? '';

// 增量：lib 删掉 helper_tag，新增一个文件调用 shared_calc；用同一 manifest 重建
unlink($proj . '/inc/lib.php');
file_put_contents($proj . '/inc/lib.php', "<?php\nfunction shared_calc(\$n){return \$n*2;}\n");
file_put_contents($proj . '/extra.php', "<?php\nrequire __DIR__.'/inc/lib.php';\necho '['.shared_calc(5).']';\n");
$out2 = $tmp . '/proj_out2';
$r2b = run(PHP_BINARY . ' ' . escapeshellarg($entry) . ' --input=' . escapeshellarg($proj)
    . ' --output=' . escapeshellarg($out2) . ' --manifest=' . escapeshellarg($manifest)
    . ' --func-length=10 --var-length=8 --salt=proj --silence');
$lib2 = (string) file_get_contents($out2 . '/inc/lib.php');
preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $lib2, $mm2);
$name2 = $mm2[1] ?? '';
rec($name1 !== '' && $name1 === $name2,
    'manifest 增量重建沿用旧符号映射（shared_calc 名字不变）', "{$name1} vs {$name2}");
$extra = runPhp($out2 . '/extra.php');
rec($extra['exit'] === 0 && trim($extra['out']) === '[10]',
    'manifest 增量重建后新增文件仍可跨文件调用', "out={$extra['out']}");

echo "\n-- 8) 动态符号保护（不得被改断） --\n";
$dyn = $tmp . '/dyn.php';
file_put_contents($dyn, "<?php\n"
    . "function dyn_target(\$s){return 'T:'.\$s;}\n"
    . "class DynBox{public function hit(){return 'H';}}\n"
    . "\$f='dyn_target';\necho \$f('x');\n"
    . "\$m='hit';\$o=new DynBox();\necho \$o->\$m();\n"
    . "echo call_user_func('dyn_target','y');\n");
$dynOut = $tmp . '/dyn_o.php';
obf($entry, $dyn, $dynOut, '--var-length=8 --func-length=8 --class-obf --salt=dyn');
$rd = runPhp($dynOut);
rec($rd['exit'] === 0 && trim($rd['out']) === 'T:xHT:y',
    '单文件模式：可变函数/可变方法/call_user_func 均未被改断', "exit={$rd['exit']} out={$rd['out']}");

// 工程模式下的动态符号
$dynDir = $tmp . '/dynproj';
@mkdir($dynDir, 0755, true);
file_put_contents($dynDir . '/a.php', "<?php\nfunction dyn_p(\$s){return 'P:'.\$s;}\n");
file_put_contents($dynDir . '/b.php', "<?php\nrequire __DIR__.'/a.php';\necho call_user_func('dyn_p','z');\n");
$dynOutDir = $tmp . '/dynproj_out';
run(PHP_BINARY . ' ' . escapeshellarg($entry) . ' --input=' . escapeshellarg($dynDir)
    . ' --output=' . escapeshellarg($dynOutDir) . ' --func-length=10 --salt=dp --silence');
$rdp = runPhp($dynOutDir . '/b.php');
rec($rdp['exit'] === 0 && trim($rdp['out']) === 'P:z',
    '工程模式：call_user_func 跨文件动态调用未被改断', "exit={$rdp['exit']} out={$rdp['out']}");

echo "\n-- 9) 确定性（同 salt 可复现构建） --\n";
$d1 = $tmp . '/det1.php';
$d2 = $tmp . '/det2.php';
obf($entry, $src, $d1, $ALL . ' --salt=det');
obf($entry, $src, $d2, $ALL . ' --salt=det');
// 头部版权注释含"生成时间"，必然不同，因此只比较代码本体
$h1 = md5(stripHeader((string) file_get_contents($d1)));
$h2 = md5(stripHeader((string) file_get_contents($d2)));
rec(is_file($d1) && is_file($d2) && $h1 === $h2,
    '同 salt 两次构建代码本体逐字节一致（可复现构建）', "{$h1} vs {$h2}");
// 头部必须带生成时间（用户要求）
rec(str_contains((string) file_get_contents($d1), '生成时间'),
    '文件头版权注释含生成时间', '');

// 不同 salt 必须不同（否则说明随机化失效）
$d3 = $tmp . '/det3.php';
obf($entry, $src, $d3, $ALL . ' --salt=det_other');
$h3 = md5(stripHeader((string) file_get_contents($d3)));
rec($h1 !== $h3, '不同 salt 产物不同', '');

echo "\n-- 10) 字符串每串独立密钥（破一条 ≠ 破全部） --\n";
$sx = $tmp . '/sx.php';
obf($entry, $root . '/tests/fixtures/strings.php', $sx, '--str-xor --salt=sx');
$sxCode = (string) file_get_contents($sx);
preg_match_all('/str_repeat\(\$GLOBALS\[\'[A-Za-z0-9_]+\'\],3\),(\d+),8\)/', $sxCode, $mo);
$offs = array_unique($mo[1] ?? []);
rec(count($offs) >= 2, '字符串 XOR 使用多个不同密钥切片（每串独立）', 'offsets=' . implode(',', array_slice($offs, 0, 6)));
$rsx = runPhp($sx);
$rorig = runPhp($root . '/tests/fixtures/strings.php');
rec($rsx['exit'] === 0 && trim($rsx['out']) === trim($rorig['out']),
    '每串独立密钥后字符串还原正确（输出一致）', "exit={$rsx['exit']}");

echo "\n-- 11) 授权绑定守卫（域名 + 有效期） --\n";
$ld = $tmp . '/ld.php';
obf($entry, $src, $ld, '--license-domain=example.com,myapp.cn --salt=lic');
$rld = runPhp($ld);
rec($rld['exit'] === 0 && trim($rld['out']) === trim($orig['out']),
    '授权守卫：CLI 下不校验域名（不误杀命令行/定时任务）', "exit={$rld['exit']}");

$le = $tmp . '/le.php';
obf($entry, $src, $le, '--license-expire=2020-01-01 --salt=lic');
$rle = runPhp($le);
rec(trim($rle['out']) === '', '授权守卫：过期后停止执行（无输出）', 'out=' . substr($rle['out'], 0, 60));

$lv = $tmp . '/lv.php';
obf($entry, $src, $lv, '--license-domain=myapp.cn --license-expire=2099-12-31 --salt=lic');
$rlv = runPhp($lv);
rec($rlv['exit'] === 0 && trim($rlv['out']) === trim($orig['out']),
    '授权守卫：有效期内正常执行', "exit={$rlv['exit']}");

// Web SAPI 下的域名校验：授权域名 200，非授权域名 403
$port = 8977;
$srvPid = (int) trim(exec('php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($tmp) . ' >/dev/null 2>&1 & echo $!'));
$ready = false;
for ($i = 0; $i < 25; $i++) {
    if (@file_get_contents("http://127.0.0.1:{$port}/lv.php") !== false) {
        $ready = true;
        break;
    }
    usleep(200000);
}
if ($ready) {
    $allowed = trim((string) @file_get_contents("http://127.0.0.1:{$port}/lv.php"));
    $ctx = stream_context_create(['http' => ['header' => "Host: evil.com\r\n", 'method' => 'GET']]);
    $denied = @file_get_contents("http://127.0.0.1:{$port}/lv.php", false, $ctx);
    rec($allowed !== '' && str_contains($allowed, '第10项'), '授权守卫：Web 下授权域名正常输出', substr($allowed, 0, 40));
    rec($denied === false || trim((string) $denied) === '', '授权守卫：Web 下非授权域名被拒绝(403)', substr((string) $denied, 0, 40));
} else {
    rec(false, '授权守卫：Web 校验（内置服务器未就绪）', 'server not ready');
}
if ($srvPid > 0) {
    exec("kill {$srvPid} 2>/dev/null");
}

echo "\n===== 加固专项结果：通过 {$pass} / 失败 {$fail} =====\n";
if ($fails !== []) {
    echo "失败项：\n - " . implode("\n - ", $fails) . "\n";
}
exit($fail === 0 ? 0 : 1);
