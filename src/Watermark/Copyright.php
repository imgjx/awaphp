<?php
/**
 * 版权注释注入（Copyright）
 *
 * 在输出文件头部（第一个 <?php 之后）注入版权与混淆器信息注释。
 * 该步骤在压缩/去注释之后执行，因此除非同时使用了 --compress/--no-comments，
 * 版权注释会保留在最终产物中。
 */

declare(strict_types=1);

final class Copyright
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * 字符串级别注入（在最终源码上操作，区别于 token 级模块）。
     *
     * 文件头固定注入块注释，包含三个字段：
     *  - 本文件由 <工具名> 生成
     *  - 生成器版本 <版本号>
     *  - 生成时间 <时间>
     * 若同时指定 --copyright 文本，则作为额外一行追加在版本号之后。
     * 通过 --no-banner 可关闭文件头注释注入。
     *
     * 形如：
     * <?php /*
     * 本文件由 嗷呜awaのphp混淆器生成
     * 生成器版本 0.1.0
     * 生成时间 2026-01-01 12:00:00
     * *\/
     */
    public function inject(string $code, Config $config, string $toolName, string $toolVersion): string
    {
        if ($config->get('no_banner')) {
            return $code;
        }
        $text = (string) $config->get('copyright');

        $block = "/*\n";
        $block .= "本文件由 {$toolName}生成\n";
        $block .= "生成器版本 {$toolVersion}\n";
        if ($text !== '') {
            $safe = str_replace(['*/', '/*'], '', $text);
            $block .= "{$safe}\n";
        }
        $block .= '生成时间 ' . date('Y-m-d H:i:s') . "\n*/";

        if (preg_match('/^<\?php/i', $code)) {
            $code = preg_replace('/^<\?php/i', "<?php " . $block, $code, 1);
        } else {
            $code = "<?php " . $block . "\n" . $code;
        }
        $this->logger->debug('已注入文件头版权注释');
        return $code;
    }
}
