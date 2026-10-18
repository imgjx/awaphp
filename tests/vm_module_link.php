<?php
declare(strict_types=1);
/**
 * VM 模块链接（ModuleLinker，P6）专项测试
 *
 * 设计文档 §6：独立功能模块以 JSON 清单管理在一个目录下。VM 构建时源码调用位命中
 * 清单的函数 → 模块 PHP 代码编译进同一 Program（函数表/类表合并 + 别名重写），
 * 产物中无模块路径、无 include 指令，只有普通 CALL。
 *
 * 覆盖场景（原生执行 vs VM 产物执行，输出必须逐字节一致）：
 *   1. exact_match      精确名命中：模块函数内联，输出一致
 *   2. module_internal  模块内部辅助函数互相调用（my_strlen→my_count_chars）
 *   3. deps_merge       entry + deps 一并打包（mathx_double→mathx_neg）
 *   4. regex_match     match_regex 命中（rx_tag）
 *   5. module_include   模块内 include 经主 resolver 静态内联（regexmod→helper）
 *   6. target_rename    target 改指向（tm_call → tm_impl）
 *   7. cross_module     跨模块互相调用（cross_run → mathx_double）
 *   8. no_path_trace    产物中不出现模块文件名/路径（反分析收益验证）
 *   9. no_alias_trace   产物字节码（program 层）不出现模块原始函数名
 *  10. badmod_skip      模块含 VM 不支持语法 → 跳过，调用保持原生分发且不致命
 *  11. toplevel_warn    模块含顶层语句 → 告警忽略，函数仍可链接
 *  12. noreplace        replace=false：命中但不内联（原生函数语义）
 *  13. collision_main   主程序自定义同名函数 → 主程序优先（模块副本不抢调用）
 *  14. sm_module        状态机层下模块链接语义不变
 *  15. flatten_module   平坦化层下模块链接语义不变
 *  16. stats            命中/跳过统计正确
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/ModuleLinker.php';

$MODDIR = __DIR__ . '/fixtures/modules';

/** 收集源码中自行声明的函数名（避免与 fixture bootstrap 重定义冲突） */
function codeDeclaredFns(string $code): array
{
    $fns = [];
    $toks = token_get_all('<?php ' . $code);
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) {
                $u = $toks[$j];
                if (is_array($u) && $u[0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($u) && $u[0] === T_STRING) {
                    $fns[] = $u[1];
                }
                break;
            }
        }
    }
    return $fns;
}

/** 拼装模块 fixture 引导源码：entry + deps + 全部 *.php_helper，去掉 include 指令 */
function fixtureBootstrap(array $codeFns): string
{
    $dir = $GLOBALS['MODDIR'];
    $files = [];
    foreach (glob($dir . '/*.json') ?: [] as $jf) {
        $raw = json_decode((string) file_get_contents($jf), true);
        if (!is_array($raw)) {
            continue;
        }
        if (!empty($raw['entry'])) {
            $files[] = $raw['entry'];
        }
        foreach ((array) ($raw['deps'] ?? []) as $d) {
            $files[] = $d;
        }
    }
    foreach (glob($dir . '/*_helper.php') ?: [] as $hf) {
        $files[] = basename($hf);
    }
    $files = array_values(array_unique($files));
    $out = '';
    foreach ($files as $rel) {
        $abs = $dir . '/' . $rel;
        if (!is_file($abs)) {
            continue;
        }
        $src = (string) file_get_contents($abs);
        $src = (string) preg_replace('/^\s*<\?php/i', '', $src, 1);
        $src = (string) preg_replace('/\?>\s*$/', '', $src);
        // 去除 include/require 指令（__DIR__ 在 php -r 下无法静态解析，helper 已单独载入）
        $src = (string) preg_replace('/^\s*(?:require|require_once|include|include_once)\b.*?;\s*$/m', '', $src);
        // 若声明了与测试代码同名的函数则跳过整个文件，避免重定义致命
        if (preg_match_all('/\bfunction\s+([A-Za-z_]\w*)\s*\(/', $src, $m)) {
            $conflict = false;
            foreach ($m[1] as $fn) {
                if (in_array($fn, $codeFns, true)) {
                    $conflict = true;
                    break;
                }
            }
            if ($conflict) {
                continue;
            }
        }
        $out .= $src . "\n";
    }
    // target 转发 shim：清单的 target 与 match 名不同时（如 tm_call→tm_impl），
    // 原生侧需一个同名转发函数镜像 VM 的调用点改写，否则 native 报未定义函数。
    foreach (glob($dir . '/*.json') ?: [] as $jf) {
        $raw = json_decode((string) file_get_contents($jf), true);
        if (!is_array($raw)) {
            continue;
        }
        foreach ((array) ($raw['match'] ?? []) as $m) {
            $t = (string) ($raw['target'] ?? '');
            if ($t !== '' && $t !== (string) $m && !in_array($m, $codeFns, true)) {
                $out .= "function {$m}(...\$__a){ return {$t}(...\$__a); }\n";
            }
        }
    }
    return $out;
}

/** 原生执行（先载入模块 fixture 引导，使被测试调用的模块函数可见） */
function runNative(string $code): string
{
    $codeFns = codeDeclaredFns($code);
    $boot = fixtureBootstrap($codeFns);
    return (string) shell_exec('php -r ' . escapeshellarg($boot . $code) . ' 2>&1');
}

/** VM 产物执行 */
function runVm(string $code, array $opts = []): string
{
    $art = (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
        'baseDir'   => sys_get_temp_dir(),
        'curFile'   => sys_get_temp_dir() . '/p6_mod.php',
        'moduleDir' => $GLOBALS['MODDIR'],
    ], $opts));
    $tmp = tempnam(sys_get_temp_dir(), 'vmmod') . '.php';
    file_put_contents($tmp, $art['php']);
    $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

/** 构建并返回完整 artifact（供静态断言） */
function buildVm(string $code, array $opts = []): array
{
    return (new VmRuntimeGenerator())->buildArtifact($code, array_merge([
        'baseDir'   => sys_get_temp_dir(),
        'curFile'   => sys_get_temp_dir() . '/p6_mod.php',
        'moduleDir' => $GLOBALS['MODDIR'],
    ], $opts));
}

$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    if ($ok) {
        echo "[ OK ] {$name}" . ($detail !== '' ? " => {$detail}" : '') . "\n";
    } else {
        echo "[FAIL] {$name} {$detail}\n";
        $fail++;
    }
};

// ---------- 1) 精确名命中 ----------
$code = 'echo my_strlen("hello") . "|";';
$native = runNative($code);
$vm = runVm($code);
$check('exact_match', $vm === $native && $native === '5|', "native={$native} vm={$vm}");

// ---------- 2) 模块内部辅助函数 ----------
$code = 'echo my_strlen("abcde") . "|";';
$check('module_internal', runVm($code) === runNative($code));

// ---------- 3) deps 合并 ----------
$code = 'echo mathx_double(21) . "|";';
$check('deps_merge', runVm($code) === runNative($code) && runNative($code) === '42|');

// ---------- 4) 正则命中 ----------
$code = 'echo rx_tag("hi") . "|";';
$check('regex_match', runVm($code) === runNative($code) && runNative($code) === '[<hi>]|');

// ---------- 5) 模块内 include（__DIR__ 相对路径静态内联） ----------
$code = 'echo rx_tag("x") . rx_wrap("y") . "|";';
$check('module_include', runVm($code) === runNative($code));

// ---------- 6) target 改指向 ----------
$code = 'echo tm_call(3, 7) . "|";';
$check('target_rename', runVm($code) === runNative($code) && runNative($code) === '307|');

// ---------- 7) 跨模块互相调用 ----------
$code = 'echo cross_run(5) . "|";';
$check('cross_module', runVm($code) === runNative($code) && runNative($code) === '11|');

// ---------- 8) 产物无路径痕迹 ----------
$art = buildVm('echo my_strlen("abc") . "|";');
$php = $art['php'];
$noPath = strpos($php, 'mystr.php') === false
    && strpos($php, 'mystr.json') === false
    && strpos($php, 'mathx_helper.php') === false
    && strpos($php, 'fixtures/modules') === false;
$check('no_path_trace', $noPath, $noPath ? '产物无模块路径' : '产物泄漏了模块路径');

// ---------- 9) program 层无模块原始函数名 ----------
$progJson = json_encode($art['program'], JSON_UNESCAPED_SLASHES);
$noAlias = strpos($progJson, 'my_strlen') === false
    && strpos($progJson, 'my_count_chars') === false;
$hasAliasFn = strpos($progJson, '_ml') !== false;   // 别名函数已注册
$check('no_alias_trace', $noAlias && $hasAliasFn, $noAlias && $hasAliasFn ? '原始名已抹除（仅存别名）' : "noAlias=" . var_export($noAlias, true) . " hasAliasFn=" . var_export($hasAliasFn, true));

// ---------- 10) 不支持的模块 → 跳过且不致命 ----------
$code = 'echo bad_call("z") . "|";';
$art = buildVm($code);
$vm = runVm($code);
$check('badmod_skip', $vm === runNative($code) && isset($art['linker']['skips']['bad_call']), 'skip=' . ($art['linker']['skips']['bad_call'] ?? 'none'));

// ---------- 11) 模块顶层语句 → 告警忽略，函数仍可链接 ----------
$code = 'echo tl_add(2, 3) . "|";';
$art = buildVm($code);
$vm = runVm($code);
$check('toplevel_warn', $vm === runNative($code) && isset($art['linker']['hits']['tl_add']) && isset($art['linker']['skips']['tl_add']),
    'hits=' . json_encode($art['linker']['hits']) . ' skips=' . json_encode($art['linker']['skips']));

// ---------- 12) replace=false：不内联，原生语义 ----------
$code = 'echo nr_square(6) . "|";';
$art = buildVm($code);
$vm = runVm($code);
$check('noreplace', $vm === runNative($code) && !isset($art['linker']['hits']['nr_square']), 'hits=' . json_encode($art['linker']['hits']));

// ---------- 13) 主程序同名函数 → 主程序优先 ----------
$code = 'function my_strlen($s) { return 999; } echo my_strlen("abc") . "|";';
$vm = runVm($code);
$check('collision_main', $vm === '999|', "vm={$vm}");

// ---------- 14/15) 与混淆层组合 ----------
$code = 'echo my_strlen("abcd") . mathx_double(4) . "|";';
$check('sm_module', runVm($code, ['stateMachine' => true]) === runNative($code));
$check('flatten_module', runVm($code, ['flatten' => true]) === runNative($code));

// ---------- 16) 统计 ----------
$code = 'echo my_strlen("a") . mathx_double(1) . rx_tag("q") . cross_run(1) . tm_call(1,2) . tl_add(1,1) . bad_call("b") . nr_square(2) . "|";';
$art = buildVm($code);
$hits = $art['linker']['hits'];
$skips = $art['linker']['skips'];
$statsOk = isset($hits['my_strlen'], $hits['mathx_double'], $hits['rx_tag'], $hits['cross_run'], $hits['tm_call'], $hits['tl_add'])
    && isset($skips['bad_call'])
    && !isset($hits['nr_square']);
$vm = runVm($code);
$check('stats', $statsOk && $vm === runNative($code),
    'hits=' . json_encode(array_keys($hits)) . ' skips=' . json_encode(array_keys($skips)));

// ---------- 17) 无 moduleDir 时行为不变（回归保护） ----------
$code = 'echo strlen("ok") . "|";';
$artNoMod = (new VmRuntimeGenerator())->buildArtifact($code, [
    'baseDir' => sys_get_temp_dir(),
    'curFile' => sys_get_temp_dir() . '/p6_none.php',
]);
$tmp = tempnam(sys_get_temp_dir(), 'vmnb') . '.php';
file_put_contents($tmp, $artNoMod['php']);
$vmNoMod = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
unlink($tmp);
$check('no_moduledir', $vmNoMod === $code ? false : $vmNoMod === '2|', "vm={$vmNoMod}");

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
