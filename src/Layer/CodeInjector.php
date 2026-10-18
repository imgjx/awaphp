<?php
/**
 * 分层注入器（CodeInjector）
 *
 * 处理分层 JSON 中每层的 inject 字段（注入反调试 / 代码 / probe 文件）。
 * 支持：
 *   - code:       任意 PHP 代码片段（构建期明文拼入，随后可能被外层再混淆）
 *   - code_at:    'top'（文件顶）/ 'after_shell'（当前产物尾部，默认）
 *   - probe_file: 从外部文件读取代码片段注入（支持模块化注入素材库）
 *
 * 所有注入代码都必须通过 php -l，失败则该注入项回滚并告警（不破坏整层）。
 */

declare(strict_types=1);

// 本项目为全局类（无命名空间）；AntiTamper 与 CodeInjector 共享尾区哨兵约定
final class CodeInjector
{
    public function __construct(
        private Logger $logger,
        private ?Salt $salt = null
    ) {}

    /**
     * 在 $code 中注入一段代码片段。
     * @param 'top'|'after_shell' $at
     */
    public function injectCode(string $code, string $snippet, string $at = 'after_shell'): string
    {
        $snippet = trim($snippet);
        if ($snippet === '') {
            return $code;
        }
        // 不允许裸顶层 return/exit 破坏包裹语义
        if ($at === 'top') {
            $candidate = $snippet . "\n" . $code;
        } else {
            // after_shell：注入代码落在 AntiTamper 终结符之后的"注入区"。
            // 哨兵前缀与 AntiTamper::injectZoneSentinel 一致；尾部换行归一化为
            // 恰好一个，使防篡改尾区校验能以"哨兵紧接受护体"定位注入区。
            $candidate = rtrim($code, "\n") . "\n"
                . AntiTamper::injectZoneSentinel($this->salt) . "\n" . $snippet;
        }
        if (!$this->syntaxOk($candidate)) {
            $this->logger->warn('inject.code 语法校验未通过，已回滚该注入');
            return $code;
        }
        return $candidate;
    }

    /**
     * 从外部文件读取代码片段注入。
     * @param 'top'|'after_shell' $at
     */
    public function injectProbeFile(string $code, string $path, string $at = 'after_shell'): string
    {
        if (!is_file($path)) {
            $this->logger->warn("inject.probe_file 不存在，已跳过: {$path}");
            return $code;
        }
        $snippet = trim((string) file_get_contents($path));
        if ($snippet === '') {
            return $code;
        }
        return $this->injectCode($code, $snippet, $at);
    }

    /**
     * 应用一个 inject 规范（可能含 code / probe_file / items 数组）。
     * @param array<string,mixed> $inject
     */
    public function apply(string $code, array $inject): string
    {
        if (isset($inject['items']) && is_array($inject['items'])) {
            foreach ($inject['items'] as $item) {
                if (is_array($item)) {
                    $code = $this->apply($code, $item);
                }
            }
            return $code;
        }
        $at = ($inject['code_at'] ?? 'after_shell') === 'top' ? 'top' : 'after_shell';
        if (isset($inject['code'])) {
            $code = $this->injectCode($code, (string) $inject['code'], $at);
        }
        if (isset($inject['probe_file'])) {
            $code = $this->injectProbeFile($code, (string) $inject['probe_file'], $at);
        }
        return $code;
    }

    private function syntaxOk(string $php): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inj') . '.php';
        if ($tmp === false) {
            return false;
        }
        // 候选串已是一个完整 <?php... 程序（被注入的源码自带标签），切勿再叠加 <?php，
        // 否则会出现两个开放标签导致 Parse error。
        file_put_contents($tmp, $php);
        $out = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        return $out !== null && !preg_match('/^PHP Parse error/i', $out);
    }
}
