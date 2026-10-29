<?php
/**
 * 序列化钩子（StatHook）
 *
 * 设计定位：在不破坏正确性的前提下，向代码中注入可被运行时反查的"序列化标记"。
 * 默认关闭；当配置 stat_hook=true（可通过 JSON 配置开启）时，于文件顶部注入一条
 * 无害的序列化赋值（unserialize 一个空数组），不会被任何业务读取，安全无副作用。
 *
 * 说明：更复杂的"结构上插入 opcode"实现风险较高且收益有限，这里以安全可复现的
 * 序列化标记作为 hook 占位，便于后续扩展。
 */

declare(strict_types=1);

final class StatHook
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        if (!$config->get('stat_hook')) {
            return $tokens;
        }
        $out = [];
        $inserted = false;
        // 钩子语句必须落在 declare/namespace 之后（否则 declare 不再是首语句直接致命）
        $insAt = DeclareHoist::headInsertIndex($tokens);
        foreach ($tokens as $i => $tok) {
            if (!$inserted && $insAt >= 0 && $i === $insAt) {
                $out[] = "\$_stat=unserialize('a:0:{}');";
                $inserted = true;
            }
            $out[] = $tok;
        }
        $this->logger->debug('已注入序列化钩子标记');
        return $out;
    }
}
