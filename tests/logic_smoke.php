<?php
/**
 * LogicRewriter 冒烟测试：直接调用核心类，对 fixtures 施加 intensity 1/2/3，
 * 校验 php -l 通过且运行输出与原版逐字节一致。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Salt.php',
    'src/Logger.php',
    'src/Config.php',
    'src/Parser.php',
    'src/AntiAst/IdentityExpr.php',
    'src/LogicTransform/LogicRewriter.php',
] as $f) {
    require_once $root . '/' . $f;
}

$fixtures = glob($root . '/tests/fixtures/*.php');
$intensities = [1, 2, 3];
$total = 0;
$failed = 0;
$reasons = [];

foreach ($fixtures as $fx) {
    $src = file_get_contents($fx);
    // 运行原版取基准输出
    $tmpOrig = tempnam(sys_get_temp_dir(), 'orig') . '.php';
    file_put_contents($tmpOrig, $src);
    $origOut = shell_exec('php ' . escapeshellarg($tmpOrig) . ' 2>&1');
    foreach ($intensities as $intensity) {
        $total++;
        $cfg = new Config(['logic_intensity' => $intensity]);
        $rw = new LogicRewriter(new Salt('smoke'), new Logger(), $intensity);
        try {
            $tokens = Parser::tokenize($src);
            $outTokens = $rw->process($tokens, $cfg);
            // 还原源码：用 Parser 或简单拼接
            $outSrc = '';
            foreach ($outTokens as $t) {
                $outSrc .= is_array($t) ? $t[1] : $t;
            }
        } catch (Throwable $e) {
            $failed++;
            $reasons[] = sprintf("[FAIL] %s int=%d EXCEPTION: %s in %s:%d", basename($fx), $intensity, $e->getMessage(), basename($e->getFile()), $e->getLine());
            continue;
        }
        $tmpOut = tempnam(sys_get_temp_dir(), 'out') . '.php';
        file_put_contents($tmpOut, $outSrc);
        $lint = shell_exec('php -l ' . escapeshellarg($tmpOut) . ' 2>&1');
        if (strpos($lint, 'No syntax errors') === false) {
            $failed++;
            $reasons[] = sprintf("[FAIL] %s int=%d php -l:\n%s\n---SRC---\n%s", basename($fx), $intensity, $lint, $outSrc);
            @unlink($tmpOut);
            continue;
        }
        $newOut = shell_exec('php ' . escapeshellarg($tmpOut) . ' 2>&1');
        if ($newOut !== $origOut) {
            $failed++;
            $reasons[] = sprintf("[FAIL] %s int=%d OUTPUT MISMATCH (len orig=%d new=%d)", basename($fx), $intensity, strlen($origOut), strlen($newOut));
            @unlink($tmpOut);
            continue;
        }
        @unlink($tmpOut);
    }
    @unlink($tmpOrig);
}

echo "TOTAL={$total} FAILED={$failed}\n";
foreach ($reasons as $r) {
    echo $r . "\n";
}
exit($failed === 0 ? 0 : 1);
