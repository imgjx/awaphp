<?php
/**
 * 混淆盐处理器（Salt）
 *
 * 凡涉及"生成可复现随机名字"的混淆（函数名、变量名、类名等）都使用本类，
 * 规则：最终标识符 = "_" + md5(原始名字 + salt) 的前 N 位。
 * 空 salt 时等同于 md5(原始名字 + "")，结果稳定可复现。
 *
 * 关键点（整套系统协同的基础）：
 *   标识符仅由 (原始名字, salt) 决定，与文件无关 —— 因此同一套系统里所有文件
 *   只要使用同一个 salt，同一个函数/类就会被重命名成同一个标识符，跨文件调用天然对齐。
 *
 * 另提供 derive()/deriveHex()：由 salt + 用途标签确定性地派生字节。
 * 用于"可复现构建"（--deterministic）：同一 salt + 同一输入 => 逐字节相同的产物。
 */

declare(strict_types=1);

final class Salt
{
    public function __construct(private string $salt = '')
    {
    }

    /**
     * 由原始名字生成加盐后的短标识符（形如 _a1b2c3）。
     * 长度不足时以 hex 字符填充，始终返回合法 PHP 标识符。
     */
    public function identifier(string $origin, int $len): string
    {
        $len = max(4, min(32, (int) $len));
        $hash = md5($origin . $this->salt);
        $id = substr($hash, 0, $len - 1);
        // PHP 标识符不能以数字开头：把首字符中的数字位按 0-9 -> g-p 的
        // 确定映射换成字母。这样首字符仍是 16 个等概率符号之一，md5 的
        // 全部 4 bit 熵都被保留；旧实现会退化成 "_v" + 少一位 hash，
        // len=4/5 时可用熵从 (len-1)*4 bit 掉到 (len-2)*4 bit，碰撞率明显上升。
        if (ctype_digit($id[0])) {
            $id[0] = substr('ghijklmnop', (int) $id[0], 1);
        }
        return '_' . $id;
    }

    public function getSalt(): string
    {
        return $this->salt;
    }

    public function isEmpty(): bool
    {
        return $this->salt === '';
    }

    /**
     * 由 (salt, 用途标签) 确定性派生指定字节数的二进制串。
     *
     * 采用计数器模式的 SHA-256 流：对同一 salt 与同一 label 恒定产出同一结果，
     * 因此可用于把 VM 密钥、EVAL 变量名、字符串密钥等"随机量"变成可复现的。
     *
     * @param string $label 用途标签（如 'vm-key'），不同用途互不影响
     * @param int    $bytes 需要的字节数
     */
    public function derive(string $label, int $bytes = 8): string
    {
        $bytes = max(1, (int) $bytes);
        $out = '';
        $counter = 0;
        while (strlen($out) < $bytes) {
            $out .= hash('sha256', $this->salt . '|' . $label . '|' . $counter, true);
            $counter++;
        }
        return substr($out, 0, $bytes);
    }

    /**
     * 派生十六进制串（用于生成随机变量名后缀等）。
     *
     * @param int $len 返回的十六进制字符数（非字节数）
     */
    public function deriveHex(string $label, int $len = 6): string
    {
        $len = max(1, (int) $len);
        return substr(bin2hex($this->derive($label, (int) ceil($len / 2))), 0, $len);
    }

    /**
     * 确定性洗牌（Fisher-Yates）。
     *
     * salt 为空时退化为真随机（与 EvalShell/Goto 的"空盐即随机"策略一致）；
     * salt 非空时同 salt 恒得同一排列 —— 这是"同 salt 可复现构建"的必要条件，
     * 否则 EVAL 分片顺序、GOTO 分支顺序每次构建都变，无法做增量/差分发布。
     *
     * @param array<int,mixed> $list
     * @return array<int,mixed>
     */
    public function shuffle(array $list, string $label): array
    {
        if ($this->salt === '') {
            shuffle($list);
            return $list;
        }
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = $this->deriveInt($label . '|swap|' . $i, 0, $i);
            $tmp = $list[$i];
            $list[$i] = $list[$j];
            $list[$j] = $tmp;
        }
        return $list;
    }

    /**
     * 派生一个位于 [min,max] 的确定性整数。
     */
    public function deriveInt(string $label, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        // 注意：unpack('J') 在 64 位 PHP 上返回**有符号**整数，直接取模会得到负数
        // （曾导致 NameStyle::pick() 下标为负 -> 返回 null -> 防篡改守卫静默回滚）。
        // 这里取前 4 字节按无符号 32 位解析，恒为正数。
        $raw = $this->derive($label, 8);
        $val = unpack('N', substr($raw, 0, 4));
        $n = $val === false ? 0 : $val[1];
        return $min + (int) ($n % ($max - $min + 1));
    }
}
