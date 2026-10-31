<?php
/**
 * 恒等表达式生成器（IdentityExpr）
 *
 * 把一个常量（整数 / 字符串）变换成"语义恒等、但看不出原值"的 PHP 表达式，
 * 供深度反AST 改写使用。核心积木：
 *   - strlen(md5(1))  === 32   （md5 恒为 32 个十六进制字符）
 *   - strlen(sha1(1)) === 40   （sha1 恒为 40 个十六进制字符）
 *   - strlen('xxxx')   === N   （定长字符串长度）
 *   - base64_decode(...)       （字符串恒等还原）
 * 整数由上述积木做加减组合；所有生成式在构建期经 eval 校验，确保与原值逐字节/逐值一致。
 *
 * @see docs/VM_FEATURES.md 深度反AST 章节
 */

declare(strict_types=1);

final class IdentityExpr
{
    /** @var array<int,string> 整数积木：表达式 => 恒等值 */
    private const INT_BLOCKS = [
        32 => 'strlen(md5(1))',   // 32
        40 => 'strlen(sha1(1))',  // 40
        65 => "ord('A')",         // 65
    ];

    /**
     * 生成等于 $value 的整数表达式（结果 `(int)` 后恒等于 $value）。
     * 优先用 md5/sha1 长度积木做主体，余下用定长字符串长度补全，整体加括号。
     */
    public function intExpr(int $value): string
    {
        if ($value === 0) {
            return '(0)';
        }
        if ($value < 0) {
            return '(-' . $this->intPosExpr(-$value) . ')';
        }
        return $this->intPosExpr($value);
    }

    private function intPosExpr(int $v): string
    {
        // 主积木取 40（sha1 长度）整除，余下再用 32（md5 长度）整除，最后用定长串补零头
        $b = intdiv($v, 40);
        $r = $v % 40;
        $parts = [];
        if ($b > 0) {
            $parts[] = 'strlen(sha1(1))*' . $b;
        }
        if ($r > 0) {
            $a = intdiv($r, 32);
            $rr = $r % 32;
            if ($a > 0) {
                $parts[] = 'strlen(md5(1))*' . $a;
            }
            if ($rr > 0) {
                $parts[] = "strlen('" . str_repeat('x', $rr) . "')";
            }
        }
        if ($parts === []) {
            // v 落在 [1,39] 且不能整除 40/32 的极小情况（实际不会，因 r<40 至少有 strlen 补）
            $parts[] = '(' . $v . ')';
        }
        return '(' . implode(' + ', $parts) . ')';
    }

    /**
     * 生成等于 $str 的字符串表达式（结果恒等于 $str）。
     * 强化：拆成「两段」各自 base64 编码、运行期各自解码、再拼接到一起——
     * 即"先解码一个内容、再拼接一个内容"，源码里看不到任何明文串；
     * base64 字母表不含 ' 与 \，故可直接单引号包裹；切分点按字符串哈希散列
     * 到 [1, len-2]，避免总在正中（降低可被模式匹配识别的概率）。
     * 长度 < 2 时回退单段编码（避免空串拼接歧义）。
     */
    public function stringExpr(string $str): string
    {
        if ($str === '') {
            return "''";
        }
        $len = strlen($str);
        if ($len < 2) {
            $enc = base64_encode($str);
            return "base64_decode('" . $enc . "')";
        }
        $off = 1 + (abs(crc32($str)) % max(1, $len - 2)); // 1 .. len-2，两段均非空（len=2 时退化为 1）
        $p1 = base64_encode(substr($str, 0, $off));
        $p2 = base64_encode(substr($str, $off));
        return "base64_decode('" . $p1 . "') . base64_decode('" . $p2 . "')";
    }

    /**
     * 字符串恒等"廉价运算"：在已定义变量 $var 上追加几行极廉价、不改变值的运算，
     * 进一步打散 AST（先解码拼接、再经运算、再输出）。均为逐字节恒等：
     *   - `$var = $var . ''` 拼接空串（字符串不变）
     *   - `$var = (string)$var` 强制字符串态（字符串不变）
     * 返回可直接拼进代码片段的 PHP 语句序列（末尾含换行）。
     */
    public function cheapStringOps(string $var): string
    {
        return "\${$var} = \${$var} . '';\n\${$var} = (string)\${$var};\n";
    }

    /**
     * 整数恒等"廉价运算"：在已定义累加变量 $var 上追加一行极廉价、不改变值的运算
     * （整数异或 0 恒等于自身），用于强化整数路径的反AST。末尾含换行。
     */
    public function cheapIntOps(string $var): string
    {
        return "\${$var} = \${$var} ^ 0;\n";
    }

    /**
     * 构建期自检：生成式是否与原值等价（整数按值、字符串按内容）。
     * 返回 true 表示安全可用。
     * @param int|string $expected
     */
    public function verify($expected, string $expr): bool
    {
        try {
            $got = eval('return ' . $expr . ';');
        } catch (Throwable $e) {
            return false;
        }
        if (is_int($expected)) {
            return is_int($got) && $got === $expected;
        }
        return is_string($got) && $got === $expected;
    }
}
