<?php
/**
 * OPcache / Zend 字节码辅助编译
 *
 * 部分虚拟主机（如 EP 虚拟主机）默认启用 OPcache。本步骤在混淆产物写出后，
 * 额外「加一道工」将源码编译为 Zend 字节码，使部署主机可直接以字节码形态运行：
 *
 *   1. 若安装 phpc 扩展：生成可移植的 .phpc 字节码文件（phpc_compile_file 编译后落盘）；
 *   2. 否则若 OPcache 可用：调用 opcache_compile_file 预热运行期字节码缓存
 *      （在启用了 OPcache 的 Web 主机上，首次请求即命中字节码，无需单独产物）；
 *   3. 任一能力缺失时优雅降级并给出明确提示，不阻断混淆主流程。
 *
 * 设计上「非侵入」：纯后处理步骤，不修改混淆产物本体，关闭 --opcache 时完全无副作用。
 */

declare(strict_types=1);

final class OpcacheCompiler
{
    /** 是否具备任意一种字节码编译能力（phpc 或 OPcache） */
    public static function supported(): bool
    {
        return function_exists('phpc_compile_file') || function_exists('opcache_compile_file');
    }

    /**
     * 将给定 PHP 文件编译为 Zend 字节码。
     *
     * @return array{mode:string,ok:bool,note:string,artifact:string}
     */
    public static function compile(string $file, Logger $logger): array
    {
        $res = ['mode' => '', 'ok' => false, 'note' => '', 'artifact' => ''];

        if (!is_file($file)) {
            $res['note'] = "OPcache 编译跳过：文件不存在 {$file}";
            $logger->warn($res['note']);
            return $res;
        }

        // 1) 优先用 phpc 生成可移植的 .phpc 字节码文件
        if (function_exists('phpc_compile_file')) {
            $bc = false;
            try {
                $bc = @phpc_compile_file($file);
            } catch (Throwable $e) {
                $bc = false;
            }
            if (is_string($bc) && $bc !== '') {
                $out = $file . '.phpc';
                if (file_put_contents($out, $bc) !== false && is_file($out)) {
                    $res['mode'] = 'phpc';
                    $res['ok'] = true;
                    $res['artifact'] = $out;
                    $res['note'] = "已生成 Zend 字节码文件：{$out}（部署需 phpc 扩展或配套 loader 运行）";
                    $logger->info($res['note']);
                    return $res;
                }
            }
        }

        // 2) 退而求其次：opcache_compile_file 预热运行期字节码缓存
        if (function_exists('opcache_compile_file')) {
            $r = false;
            try {
                $r = @opcache_compile_file($file);
            } catch (Throwable $e) {
                $r = false;
            }
            if (!empty($r)) {
                $res['mode'] = 'opcache';
                $res['ok'] = true;
                $res['note'] = "已通过 OPcache 编译为字节码（运行期缓存预热；部署到启用 OPcache 的主机后将以字节码形态运行）";
                $logger->info($res['note']);
                return $res;
            }
            $res['note'] = "OPcache 扩展已加载但运行期未启用（CLI 需 opcache.enable_cli=1；Web 主机通常默认启用），跳过字节码编译";
            $logger->warn($res['note']);
            return $res;
        }

        // 3) 两者皆无：环境不支持
        $res['note'] = "环境未检测到 OPcache / phpc 扩展，无法编译为 Zend 字节码（部署主机需启用 OPcache）";
        $logger->warn($res['note']);
        return $res;
    }
}
