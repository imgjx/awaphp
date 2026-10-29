<?php
/**
 * 内置哈希原语（HashBuiltin）
 *
 * 纯 PHP 实现的 md5 / sha1 / crc32，**不依赖任何扩展**，供混淆器自身（尤其是
 * 深度反AST 的恒等表达式生成器）在构建期使用，使工具对运行环境完全自包含。
 *
 * 设计要点：
 *   - 所有中间量按 32 位无符号处理（u32 掩码），避免 64 位平台符号位干扰；
 *   - 长度类恒等（strlen(md5(x))===32、strlen(sha1(x))===40）在任何平台恒定，
 *     是最稳的"恒等常量"来源，供 IdentityExpr 组装表达式；
 *   - 也提供完整摘要（md5/sha1/crc32），与 PHP 内置函数逐字节一致（见 tests）。
 */

declare(strict_types=1);

final class HashBuiltin
{
    /** md5 摘要固定 32 个十六进制字符 —— 恒等常量 32。 */
    public const MD5_LEN = 32;
    /** sha1 摘要固定 40 个十六进制字符 —— 恒等常量 40。 */
    public const SHA1_LEN = 40;

    private static function u32(int $v): int
    {
        return $v & 0xffffffff;
    }

    private static function rotl(int $v, int $n): int
    {
        $v = self::u32($v);
        // 左循环移位 n：高位 (32-n) 位经 <<n 上移，低 n 位经 >> (32-n) 回落到 0..n-1
        return self::u32(($v << $n) | (($v >> (32 - $n)) & ((1 << $n) - 1)));
    }

    private static function add(int $a, int $b): int
    {
        return self::u32($a + $b);
    }

    /** md5 摘要（小写十六进制 32 位），与 PHP 内置 md5() 一致。 */
    public static function md5(string $msg): string
    {
        static $s = [
            7,12,17,22, 7,12,17,22, 7,12,17,22, 7,12,17,22,
            5, 9,14,20, 5, 9,14,20, 5, 9,14,20, 5, 9,14,20,
            4,11,16,23, 4,11,16,23, 4,11,16,23, 4,11,16,23,
            6,10,15,21, 6,10,15,21, 6,10,15,21, 6,10,15,21,
        ];
        static $K = [
            0xd76aa478,0xe8c7b756,0x242070db,0xc1bdceee,0xf57c0faf,0x4787c62a,0xa8304613,0xfd469501,
            0x698098d8,0x8b44f7af,0xffff5bb1,0x895cd7be,0x6b901122,0xfd987193,0xa679438e,0x49b40821,
            0xf61e2562,0xc040b340,0x265e5a51,0xe9b6c7aa,0xd62f105d,0x02441453,0xd8a1e681,0xe7d3fbc8,
            0x21e1cde6,0xc33707d6,0xf4d50d87,0x455a14ed,0xa9e3e905,0xfcefa3f8,0x676f02d9,0x8d2a4c8a,
            0xfffa3942,0x8771f681,0x6d9d6122,0xfde5380c,0xa4beea44,0x4bdecfa9,0xf6bb4b60,0xbebfbc70,
            0x289b7ec6,0xeaa127fa,0xd4ef3085,0x04881d05,0xd9d4d039,0xe6db99e5,0x1fa27cf8,0xc4ac5665,
            0xf4292244,0x432aff97,0xab9423a7,0xfc93a039,0x655b59c3,0x8f0ccc92,0xffeff47d,0x85845dd1,
            0x6fa87e4f,0xfe2ce6e0,0xa3014314,0x4e0811a1,0xf7537e82,0xbd3af235,0x2ad7d2bb,0xeb86d391,
        ];

        $a0 = 0x67452301; $b0 = 0xefcdab89; $c0 = 0x98badcfe; $d0 = 0x10325476;
        $bitLen = strlen($msg) * 8;

        $m = $msg . "\x80";
        while (strlen($m) % 64 !== 56) {
            $m .= "\x00";
        }
        $m .= pack('V', self::u32($bitLen)) . pack('V', self::u32(intdiv($bitLen, 0x100000000)));

        $a = $a0; $b = $b0; $c = $c0; $d = $d0;
        $n = strlen($m);
        for ($off = 0; $off < $n; $off += 64) {
            $M = [];
            for ($j = 0; $j < 16; $j++) {
                $M[$j] = self::u32(unpack('V', substr($m, $off + $j * 4, 4))[1]);
            }
            $A = $a; $B = $b; $C = $c; $D = $d;
            for ($k = 0; $k < 64; $k++) {
                if ($k < 16) {
                    $f = ($B & $C) | ((~$B & 0xffffffff) & $D);
                    $g = $k;
                } elseif ($k < 32) {
                    $f = ($D & $B) | ((~$D & 0xffffffff) & $C);
                    $g = (5 * $k + 1) & 15;
                } elseif ($k < 48) {
                    $f = $B ^ $C ^ $D;
                    $g = (3 * $k + 5) & 15;
                } else {
                    $f = $C ^ ($B | (~$D & 0xffffffff));
                    $g = (7 * $k) & 15;
                }
                $f = self::u32($f);
                // temp = (A + f + K[k] + M[g]) <<< s[k]，再 + B；随后 (A,B,C,D) := (D, temp, B, C)
                $t = self::add(self::add(self::add($A, $f), self::u32($K[$k])), $M[$g]);
                $t = self::rotl($t, $s[$k]);
                $t = self::add($t, $B);
                $A = $D; $D = $C; $C = $B; $B = $t;
            }
            $a = self::add($a, $A);
            $b = self::add($b, $B);
            $c = self::add($c, $C);
            $d = self::add($d, $D);
        }

        // MD5 摘要按"每个 32 位字小端"拼接（与 PHP md5() 一致）
        return bin2hex(pack('V', self::u32($a)) . pack('V', self::u32($b))
            . pack('V', self::u32($c)) . pack('V', self::u32($d)));
    }

    /** sha1 摘要（小写十六进制 40 位），与 PHP 内置 sha1() 一致。 */
    public static function sha1(string $msg): string
    {
        $h = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476, 0xc3d2e1f0];
        $bitLen = strlen($msg) * 8;

        $m = $msg . "\x80";
        while (strlen($m) % 64 !== 56) {
            $m .= "\x00";
        }
        $m .= pack('N', intdiv($bitLen, 0x100000000)) . pack('N', self::u32($bitLen));

        $n = strlen($m);
        for ($off = 0; $off < $n; $off += 64) {
            $w = [];
            for ($j = 0; $j < 16; $j++) {
                $w[$j] = self::u32(unpack('N', substr($m, $off + $j * 4, 4))[1]);
            }
            for ($j = 16; $j < 80; $j++) {
                $v = $w[$j - 3] ^ $w[$j - 8] ^ $w[$j - 14] ^ $w[$j - 16];
                $w[$j] = self::rotl($v, 1);
            }
            $a = $h[0]; $b = $h[1]; $c = $h[2]; $d = $h[3]; $e = $h[4];
            for ($i = 0; $i < 80; $i++) {
                if ($i < 20) {
                    $f = ($b & $c) | ((~$b & 0xffffffff) & $d);
                    $k = 0x5a827999;
                } elseif ($i < 40) {
                    $f = $b ^ $c ^ $d;
                    $k = 0x6ed9eba1;
                } elseif ($i < 60) {
                    $f = ($b & $c) | ($b & $d) | ($c & $d);
                    $k = 0x8f1bbcdc;
                } else {
                    $f = $b ^ $c ^ $d;
                    $k = 0xca62c1d6;
                }
                $f = self::u32($f);
                $tmp = self::add(self::rotl($a, 5), self::add(self::add(self::add($f, $e), self::u32($k)), $w[$i]));
                $e = $d; $d = $c; $c = self::rotl($b, 30); $b = $a; $a = $tmp;
            }
            $h[0] = self::add($h[0], $a);
            $h[1] = self::add($h[1], $b);
            $h[2] = self::add($h[2], $c);
            $h[3] = self::add($h[3], $d);
            $h[4] = self::add($h[4], $e);
        }

        return sprintf('%08x%08x%08x%08x%08x',
            self::u32($h[0]), self::u32($h[1]), self::u32($h[2]), self::u32($h[3]), self::u32($h[4]));
    }

    /** crc32（IEEE 802.3，与 PHP 内置 crc32() 一致，返回无符号 32 位 int）。 */
    public static function crc32(string $data): int
    {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($n = 0; $n < 256; $n++) {
                $c = $n;
                for ($k = 0; $k < 8; $k++) {
                    $c = ($c & 1) ? (0xedb88320 ^ ($c >> 1)) : ($c >> 1);
                }
                $table[$n] = self::u32($c);
            }
        }
        $crc = 0xffffffff;
        for ($i = 0, $l = strlen($data); $i < $l; $i++) {
            $crc = self::u32(($crc >> 8) ^ $table[($crc ^ ord($data[$i])) & 0xff]);
        }
        return self::u32($crc ^ 0xffffffff);
    }
}
