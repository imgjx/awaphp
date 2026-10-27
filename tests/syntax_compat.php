<?php
declare(strict_types=1);
/**
 * 语法兼容专项回归：覆盖 PHP 8.x 语法广度（enum / readonly / 构造器属性提升 /
 * 一等可调用语法 / never / 生成器 / union 类型 / 变长参数 / 引用传参 / 命名参数 /
 * 展开运算符 等），在最高强度分层配置（stateMachine + junk + 深层 GOTO + semantic）
 * 与顶格等级预设下，比对「原生执行」与「混淆产物执行」输出必须逐字节一致。
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

$entry = __DIR__ . '/../entry.php';
$fixture = __DIR__ . '/fixtures/syntax_wide.php';
$native = (string) shell_exec('php ' . escapeshellarg($fixture) . ' 2>&1');

$pass = 0;
$fail = 0;

function check(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function buildAndRun(string $entry, string $fixture, string $tag, string $args): ?string
{
    $out = tempnam(sys_get_temp_dir(), 'syn') . '.php';
    $cmd = sprintf('php %s --input=%s --output=%s %s --salt=%s --format=text 2>&1',
        escapeshellarg($entry), escapeshellarg($fixture), escapeshellarg($out), $args, escapeshellarg($tag));
    exec($cmd, $o, $ec);
    if ($ec !== 0) {
        return null;
    }
    $got = (string) shell_exec('php ' . escapeshellarg($out) . ' 2>&1');
    unlink($out);
    return $got;
}

echo "===== [1] 顶格分层（stateMachine+junk200+goto5+semantic3+全P8）=====\n";
foreach (['sc1', 'sc2', 'sc3'] as $salt) {
    $got = buildAndRun($entry, $fixture, $salt, '--config=' . escapeshellarg(__DIR__ . '/fixtures/syntax_max.json'));
    check($got === $native, "顶格分层 salt={$salt}", $got === null ? '构建失败' : '输出不一致');
}

echo "===== [2] 等级预设（high / ultra）=====\n";
foreach (['high', 'ultra'] as $lv) {
    foreach (['scv1', 'scv2'] as $salt) {
        $got = buildAndRun($entry, $fixture, $salt, "--level={$lv}");
        check($got === $native, "level={$lv} salt={$salt}", $got === null ? '构建失败' : '输出不一致');
    }
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
