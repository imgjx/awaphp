<?php
/**
 * 日志输出器
 *
 * - text 模式：带颜色（CLI 支持时）的友好输出
 * - silence 模式：完全静默
 * - json 模式：仅输出结构化 JSON（便于 GUI / 脚本解析）
 */

declare(strict_types=1);

final class Logger
{
    public const LEVEL_DEBUG = 0;
    public const LEVEL_INFO = 1;
    public const LEVEL_WARN = 2;
    public const LEVEL_ERROR = 3;

    public function __construct(
        private int $level = self::LEVEL_INFO,
        private string $mode = 'text'
    ) {
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function debug(string $msg): void
    {
        $this->log(self::LEVEL_DEBUG, $msg, 'DEBUG');
    }

    public function info(string $msg): void
    {
        $this->log(self::LEVEL_INFO, $msg, 'INFO ');
    }

    public function warn(string $msg): void
    {
        $this->log(self::LEVEL_WARN, $msg, 'WARN ');
    }

    public function error(string $msg): void
    {
        $this->log(self::LEVEL_ERROR, $msg, 'ERROR');
    }

    private function log(int $level, string $msg, string $tag): void
    {
        if ($this->mode === 'json') {
            // JSON 模式下日志走 stderr，避免污染 stdout 的结构化输出
            if ($level >= $this->level) {
                fwrite(STDERR, json_encode(['level' => $tag, 'msg' => $msg], JSON_UNESCAPED_UNICODE) . "\n");
            }
            return;
        }
        if ($level < $this->level) {
            return;
        }
        $color = match ($tag) {
            'ERROR' => "\033[31m",
            'WARN ' => "\033[33m",
            'DEBUG' => "\033[36m",
            default => "\033[32m",
        };
        $reset = "\033[0m";
        $line = (PHP_SAPI === 'cli' ? $color . '[' . $tag . '] ' . $reset : '[' . $tag . '] ') . $msg;
        fwrite(STDERR, $line . "\n");
    }
}
