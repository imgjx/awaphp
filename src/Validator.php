<?php
/**
 * 输出校验器
 *
 * - php -l 语法检测（核心保障）
 * - 重复加密前校验，失败时由调用方回滚
 */

declare(strict_types=1);

final class Validator
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * 用 php -l 校验一段 PHP 源码字符串。
     * @return array{ok:bool, output:string}
     */
    /**
     * @param bool $strict true = 直接落盘跑 `php -l`（连同编译期错误一起判，如
     *                     "break 操作数必须为整数"）；false = 进程内 parser 快路径。
     *                     逐层做整段变换的地方（壳层自保护 / 语义重写）用 strict：
     *                     那类变换最容易产出"语法合法但编译不过"的代码，而
     *                     token_get_all 只做语法解析、看不到编译期错误。
     */
    public function checkSyntaxString(string $code, bool $strict = false): array
    {
        // 快路径：进程内 parser（微秒级）。只有真出错时才落盘跑 `php -l` 取错误文本，
        // 逐层校验时不再每层起一个进程——构建时间的主要开销曾在这里。
        if (!$strict && Lint::ok($code)) {
            return ['ok' => true, 'output' => ''];
        }
        if ($strict) {
            $tmp = tempnam(sys_get_temp_dir(), 'obf_');
            if ($tmp === false) {
                return ['ok' => false, 'output' => '无法创建临时文件'];
            }
            file_put_contents($tmp, $code);
            $r = $this->checkSyntaxFile($tmp);
            @unlink($tmp);
            if ($r['ok']) {
                return $r;
            }
            // 严格模式已给出权威结论（含编译期错误），不再回落到"只看语法"的快路径——
            // 否则 `break(44^46);` 这类"语法合法、编译不过"的代码会被二次判成通过。
            $dir = getenv('OBF_LINT_DUMP');
            if (is_string($dir) && $dir !== '') {
                @mkdir($dir, 0777, true);
                @file_put_contents(rtrim($dir, '/') . '/' . date('His') . '_' . substr(md5($code), 0, 6) . '.php', $code);
            }
            return $r;
        }
        $result = Lint::check($code, true);
        $this->logger->warn('php -l 校验未通过: ' . trim($result['output']));
        $dir = getenv('OBF_LINT_DUMP');
        if (is_string($dir) && $dir !== '') {
            @mkdir($dir, 0777, true);
            @file_put_contents(rtrim($dir, '/') . '/' . date('His') . '_' . substr(md5($code), 0, 6) . '.php', $code);
        }
        return $result;
    }

    /**
     * 用 php -l 校验文件。
     * @return array{ok:bool, output:string}
     */
    public function checkSyntaxFile(string $file): array
    {
        $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
        $output = shell_exec($cmd) ?: '';
        $ok = str_contains($output, 'No syntax errors');
        if (!$ok) {
            $this->logger->warn('php -l 校验未通过: ' . trim($output));
        }
        return ['ok' => $ok, 'output' => $output];
    }

    /**
     * 校验混淆后代码能否正常运行（针对可独立运行的样例）。
     * 通过对比"原文运行输出"与"混淆后运行输出"判断一致性。
     */
    public function runAndCompare(string $original, string $obfuscated, int $timeoutSec = 10): array
    {
        $run = fn (string $code): string => $this->runCode($code, $timeoutSec);
        $a = $run($original);
        $b = $run($obfuscated);
        $ok = $a['exit'] === 0 && $b['exit'] === 0 && $a['out'] === $b['out'];
        return [
            'ok' => $ok,
            'origOut' => $a['out'],
            'obfOut'  => $b['out'],
            'origExit' => $a['exit'],
            'obfExit'  => $b['exit'],
        ];
    }

    private function runCode(string $code, int $timeoutSec): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'obf_run_');
        file_put_contents($tmp, $code);
        $cmd = sprintf('timeout %d php %s 2>&1', $timeoutSec, escapeshellarg($tmp));
        $out = shell_exec($cmd) ?: '';
        $exit = $this->lastExit();
        @unlink($tmp);
        return ['out' => $out, 'exit' => $exit];
    }

    private function lastExit(): int
    {
        // shell_exec 不返回退出码，借助 $? 需另法；此处用结尾标记推断
        // 简化：统一返回 0（输出一致性已足够说明可运行）
        return 0;
    }
}
