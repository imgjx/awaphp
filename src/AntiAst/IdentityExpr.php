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
     * 用 base64 包裹隐藏明文，运行期 decode 还原；base64 字符在单引号串中均安全。
     */
    public function stringExpr(string $str): string
    {
        if ($str === '') {
            return "''";
        }
        $enc = base64_encode($str);
        // 防单引号逃逸：base64 字母表不含 ' 与 \，故可直接单引号包裹
        return "base64_decode('" . $enc . "')";
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
