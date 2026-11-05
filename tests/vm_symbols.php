<?php
/**
 * VM 符号名密文（v1.1.8）回归
 *
 * 背景：VM 字节码容器（packProgram）此前把**类名 / 方法名 / 函数名 / 变量名 /
 * 属性名 / 常量名 / 异常表三字段 / 指令操作数里的符号串**全部以明文写入二进制。
 * 容器整体虽被分片 XOR 加密，但分片密钥以明文存在产物里——攻击者提取密钥即可
 * 解开容器，`strings` 直接读出完整符号表（业务结构、命名风格、类与方法关系一览无余）。
 *
 * 验证要点：
 *   1. 打包层：packProgram 输出的二进制里不含任何明文符号名；
 *   2. 对称性：打包加密 / 解包解密严格对称——端到端产物运行输出与原生逐字节一致；
 *   3. 旁路：常量池（cpool）开/关两条路径都对称；natTbl 不被误解密
 *      （否则程序内存里会重新出现明文原生函数名，抵消 arch_builtin_replace）；
 *   4. 端到端：类文件 / 闭包 / 生成器 / 原生函数调用在多 salt 下均一致；
 *   5. 开关：--no-vm-symbols 关闭后退化为旧行为（产物仍可运行、输出一致）。
 */
declare(strict_types=1);

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$msg}\n";
    } else {
        $fail++;
        echo "  FAIL  {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$root = dirname(__DIR__);
$entry = $root . '/entry.php';
$tmp = sys_get_temp_dir() . '/vmsym_' . getmypid();
@mkdir($tmp, 0755, true);

function runPhp(string $file): string
{
    $cmd = 'php ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $o);
    return implode("\n", $o);
}

function buildOut(string $entry, string $in, string $out, string $args): bool
{
    $cmd = sprintf(
        'php %s --input=%s --output=%s %s --format=text 2>&1',
        escapeshellarg($entry),
        escapeshellarg($in),
        escapeshellarg($out),
        $args
    );
    exec($cmd, $o, $ec);
    return $ec === 0 && is_file($out);
}

// ---------------------------------------------------------------- 1. 打包层断言
echo "===== [1] 打包层：二进制容器不含明文符号 =====\n";

$program = [
    'main' => [
        [VmOp::PUSH_C, 0],
        [VmOp::PUSH_V, '$localVariableName'],
        [VmOp::POP_V, '$anotherLocal'],
        [VmOp::CALL, 'topLevelFunc', 1],
    ],
    'funcs' => [
        'topLevelFunc' => [[VmOp::RET_VOID]],
    ],
    'classes' => [
        'MyClassName' => [
            'props'  => ['propertyName' => null],
            'methods' => ['myMethodName' => [[VmOp::RET_VOID]]],
            'consts' => ['classConstName' => null],
        ],
    ],
    'funcParams' => ['topLevelFunc' => [['$paramName', null, 0]]],
    'classParams' => ['MyClassName' => ['myMethodName' => [['$methodParam', null, 0]]]],
    'globals'   => ['globalVarName'],
    'classParents' => [],
    'classImpl'    => [],
    'embeds'       => [],
    'srcBase'      => '/some/base/dir',
    'natTbl'       => [],
    'mainET'       => [],
    'funcET'       => [],
    'classET'      => [],
    'embedET'      => [],
    'classStatic'  => [],
];

function packBlob(array $program, string $salt, bool $symbols, string $source = 'vmsym-test-source'): string
{
    $rg = new VmRuntimeGenerator(new Salt($salt));
    $rp = new ReflectionProperty($rg, 'symKey');
    $rp->setAccessible(true);
    // 与 buildArtifact 完全相同的派生规则（按源文件内容派生，密钥不跨文件复用）
    $rp->setValue($rg, $rg->getSalt()->derive('vm-symkey:' . substr(sha1($source), 0, 16), 64));
    $rs = new ReflectionProperty($rg, 'symbols');
    $rs->setAccessible(true);
    $rs->setValue($rg, $symbols);
    $rm = new ReflectionMethod($rg, 'packProgram');
    $rm->setAccessible(true);
    return (string) $rm->invoke($rg, $program);
}

$blobOn = packBlob($program, 'vmsym', true);
ok($blobOn !== '', 'packProgram 产出非空');

$symbolNeedles = [
    'MyClassName'     => '类名',
    'myMethodName'    => '方法名',
    'topLevelFunc'    => '函数名',
    'propertyName'    => '属性名',
    'classConstName'  => '类常量名',
    '$localVariableName' => '局部变量名',
    '$anotherLocal'   => '局部变量名',
    '$paramName'      => '形参名',
    '$methodParam'    => '方法形参名',
    'globalVarName'   => '全局变量名',
    '/some/base/dir'  => '源目录',
];
$leaked = [];
foreach ($symbolNeedles as $n => $kind) {
    if (strpos($blobOn, $n) !== false) {
        $leaked[] = $n . '(' . $kind . ')';
    }
}
ok($leaked === [], '容器二进制无明文符号名', implode(', ', $leaked));

// 密文确实存在（而非「根本没写进去」）
ok(strlen($blobOn) > 200, '容器二进制长度合理', 'len=' . strlen($blobOn));

// 关闭开关后应退化为明文（旧行为）
$blobOff = packBlob($program, 'vmsym', false);
$offLeaks = [];
foreach ($symbolNeedles as $n => $kind) {
    if (strpos($blobOff, $n) !== false) {
        $offLeaks[] = $n;
    }
}
ok($offLeaks !== [], 'symbols 关闭后退化为明文（旧行为）', 'leaks=' . count($offLeaks));

// ---------------------------------------------------------------- 2. 端到端
echo "===== [2] 端到端：构建 → 运行 → 与原生逐字节一致 =====\n";

$src = <<<'PHP'
<?php
declare(strict_types=1);

const VM_SYM_LABEL = 'sym-tag';

function vmSymCalc(int $unitPrice, int $quantity): int
{
    $subtotal = $unitPrice * $quantity;
    $discountRate = 5;
    return $subtotal - $discountRate;
}

function vmSymGreet(string $who): string
{
    $prefix = 'hi';
    return $prefix . '-' . $who . '-' . strlen($who);
}

$vmSymTotal = vmSymCalc(100, 3);
$vmSymLabel = vmSymGreet('world');
$vmSymGen = (static function (): Generator {
    for ($i = 0; $i < 3; $i++) {
        yield $i * 2;
    }
})();
$vmSymList = implode(',', iterator_to_array($vmSymGen));
echo "t={$vmSymTotal};l={$vmSymLabel};g={$vmSymList};c=", VM_SYM_LABEL, PHP_EOL;
PHP;

$srcFile = "$tmp/sym.php";
file_put_contents($srcFile, $src);
$native = trim(runPhp($srcFile));
ok($native !== '', '原生夹具可运行', $native);

foreach (['vmsym-a', 'vmsym-b', 'vmsym-c'] as $salt) {
    foreach ([
        '默认(符号密文+常量池)' => '--vm',
        '关符号密文'             => '--vm --no-vm-symbols',
        '关常量池'               => '--vm --no-vm-cpool',
        '双关'                   => '--vm --no-vm-cpool --no-vm-symbols',
    ] as $tag => $args) {
        $out = "$tmp/o_" . md5($salt . $tag) . '.php';
        $okB = buildOut($entry, $srcFile, $out, "--salt={$salt} {$args}");
        if (!$okB) {
            ok(false, "构建 {$salt} / {$tag}");
            continue;
        }
        $got = trim(runPhp($out));
        ok($got === $native, "运行一致 {$salt} / {$tag}", 'got=' . substr($got, 0, 60));
    }
}

// ---------------------------------------------------------------- 3. 产物明文检查
echo "===== [3] 产物明文：符号名不可静态提取 =====\n";

$out = "$tmp/probe.php";
buildOut($entry, $srcFile, $out, '--vm --salt=vmsym-probe');
$prod = (string) file_get_contents($out);
$prodLeaks = [];
foreach (['vmSymCalc', 'vmSymGreet', 'unitPrice', 'subtotal', 'discountRate', 'vmSymTotal', 'VM_SYM_LABEL', 'sym-tag'] as $n) {
    if (strpos($prod, $n) !== false) {
        $prodLeaks[] = $n;
    }
}
ok($prodLeaks === [], '产物明文不含符号名', implode(', ', $prodLeaks));

// ---------------------------------------------------------------- 4. 类文件
echo "===== [4] 类文件：真实类桩 + VM 方法体 =====\n";

$cls = <<<'PHP'
<?php
declare(strict_types=1);
namespace vmsym\demo;

final class VmSymSvc
{
    private int $discount = 7;

    public function calc(int $price): int
    {
        $local = $price - $this->discount;
        return $local;
    }

    public static function tag(): string
    {
        return 'cls-sym';
    }
}

$svc = new VmSymSvc();
echo 'v=', $svc->calc(100), ';t=', VmSymSvc::tag(), PHP_EOL;
PHP;

$clsFile = "$tmp/cls.php";
file_put_contents($clsFile, $cls);
$clsNative = trim(runPhp($clsFile));
$clsOut = "$tmp/cls_out.php";
buildOut($entry, $clsFile, $clsOut, '--vm --salt=vmsym-cls');
ok(trim(runPhp($clsOut)) === $clsNative, '类文件端到端输出一致', 'want=' . $clsNative);

// 真实类名/方法签名是 PHP 语法硬约束，必然明文；但局部变量名应已密文
$clsProd = (string) file_get_contents($clsOut);
ok(strpos($clsProd, '$local ') === false && strpos($clsProd, '$local;') === false && strpos($clsProd, '$local=') === false,
    '类文件中局部变量名未明文出现');

// ---------------------------------------------------------------- 5. 容器格式随机化
echo "===== [5] 容器格式随机化：类型标记字节逐构建不同 =====\n";

function tagMapOf(string $salt, string $source): array
{
    $rg = new VmRuntimeGenerator(new Salt($salt));
    $m = new ReflectionMethod($rg, 'initTagMap');
    $m->setAccessible(true);
    $m->invoke($rg, $source);
    $p = new ReflectionProperty($rg, 'tagMap');
    $p->setAccessible(true);
    $map = $p->getValue($rg);
    ksort($map);
    return $map;
}

$mapA = tagMapOf('taga', 'src-a');
$mapB = tagMapOf('tagb', 'src-a');
$mapC = tagMapOf('tagc', 'src-a');
$mapA2 = tagMapOf('taga', 'src-a');

ok(count($mapA) === 6, '映射覆盖 6 个语义类型标记', 'n=' . count($mapA));
ok(count(array_unique($mapA)) === 6, '映射为双射（6 个字节互异）', json_encode($mapA));
ok($mapA !== $mapB && $mapB !== $mapC, '不同 salt → 不同容器格式');
ok($mapA === $mapA2, '同 salt 重复构建 → 映射一致（可复现）');

// 不同源文件 → 不同映射（密钥不跨文件复用）
$mapSrcB = tagMapOf('taga', 'src-b');
ok($mapA !== $mapSrcB, '不同源文件 → 不同容器格式');

// 端到端：不同 salt 的产物均运行正确
foreach (['taga', 'tagb', 'tagc'] as $salt) {
    $out = "$tmp/tag_$salt.php";
    buildOut($entry, $srcFile, $out, "--vm --salt={$salt}");
    ok(trim(runPhp($out)) === $native, "不同 salt 运行一致 {$salt}");
}

echo "\n===== 结果：通过 {$pass} / 失败 {$fail} =====\n";
exit($fail === 0 ? 0 : 1);
