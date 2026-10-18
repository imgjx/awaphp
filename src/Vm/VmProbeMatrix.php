<?php
/**
 * 反调试 / 反篡改探针矩阵（VmProbeMatrix，P8 arch_probe_matrix）
 *
 * 在 VM 解释器运行前生成一段"探针序章"：若干彼此独立的自检（probe），每个探针在
 * 【正常运行】下恒为假、无任何副作用；一旦命中（检测到调试器 / hook 扩展 / 回溯过深 /
 * 字节码被篡改）即静默 exit(0)，使被分析/被patch的产物无法产出正确结果。
 *
 * 两类探针（按 count 循环选取，顺序由盐打乱）：
 *   - dbg ：调试器 / hook 扩展检测（xdebug / phpdbg / uopz / runkit 等）；
 *   - crc ：字节码完整性——对解码后的压缩 blob 取 crc32，与构建期内嵌的期望值比对，
 *           任一被改动（patch 分片/密钥）即失配。
 *
 * 说明：刻意【不】做"调用栈回溯深度"探针——被保护脚本常被第三方 require/eval 嵌入，
 * 回溯深度不可作为调试判据（误杀正常用法）。dbg 仅对真实调试器/hook 扩展敏感，
 * crc 仅对字节码被改敏感，二者在正常 standalone 运行下恒为假。
 *
 * 反指纹：每个探针的判定量拆到若干盐派生的随机变量里，避免出现可被特征库匹配的
 * 固定"if(extension_loaded('xdebug'))exit;"字样。
 *
 * 注入时机：buildArtifact 在解码序章之后、解释器定义之前插入（保证 crc 探针能读到
 * 解码后的 $___RAW___）。probe=0（默认）不注入。
 */

declare(strict_types=1);

final class VmProbeMatrix
{
    private Salt $salt;

    public function __construct(?Salt $salt = null)
    {
        $this->salt = $salt ?? new Salt('');
    }

    /**
     * 生成探针序章（PHP 代码片段，直接拼接进 IIFE 体内）。
     *
     * @param int $count    探针数量
     * @param int $expectCrc 构建期 crc32(blob) ^ mask 的期望值（用于 crc 探针）
     * @param int $crcMask   盐派生的异或掩码
     */
    public function build(int $count, int $expectCrc, int $crcMask): string
    {
        $count = max(0, $count);
        if ($count === 0) {
            return '';
        }
        $kinds = ['dbg', 'crc'];
        $order = [];
        for ($i = 0; $i < $count; $i++) {
            $order[] = $kinds[$i % count($kinds)];
        }
        $order = $this->salt->shuffle($order, 'probe-order');
        $parts = [];
        $seq = 0;
        foreach ($order as $kind) {
            $parts[] = $this->oneProbe($kind, $seq++, $expectCrc, $crcMask);
        }
        return implode("\n", $parts);
    }

    /** 生成单个探针（随机变量名 + 静默 exit） */
    private function oneProbe(string $kind, int $seq, int $expectCrc, int $crcMask): string
    {
        // 注意：以下均返回"不含 $ 的变量名"，拼接时必须显式补 '$' 前缀
        $v = fn (string $tag): string => '_' . $this->salt->deriveHex('probe-' . $seq . '-' . $tag, 9);
        switch ($kind) {
            case 'dbg':
                // 调试器 / hook 扩展：拆成两个中间量再或
                $a = '$' . $v('da');
                $b = '$' . $v('db');
                $h = '$' . $v('h');
                return $a . '=(int)extension_loaded(\'xdebug\')|(int)function_exists(\'xdebug_break\')|(int)function_exists(\'xdebug_get_code_coverage\')|(int)extension_loaded(\'uopz\')|(int)extension_loaded(\'runkit7\');'
                    . $b . '=(int)(PHP_SAPI===\'phpdbg\')|(int)defined(\'PHPDBG_VERSION\')|(int)function_exists(\'phpdbg_start_oplog\');'
                    . $h . '=(' . $a . '|' . $b . ')!==0;' . $h . '&&exit(0);';
            case 'crc':
            default:
                // 字节码完整性：crc32(解码后 blob) ^ mask === 构建期期望值
                $e = '$' . $v('ce');
                $h = '$' . $v('h');
                return $e . '=crc32($___RAW___)^' . $crcMask . ';'
                    . $h . '=' . $e . '!==' . $expectCrc . ';' . $h . '&&exit(0);';
        }
    }
}
