<?php
// 自制 md5：替换原生 md5。纯 PHP 实现，与 PHP 内置 md5() 逐字节一致。
// VM 兼容写法（无类型声明；所有中间量用 & 0xffffffff 维持在 32 位无符号语义）。
function __mod_md5($__msg, $__raw = false) {
    $__s = [7,12,17,22, 7,12,17,22, 7,12,17,22, 7,12,17,22,
            5, 9,14,20, 5, 9,14,20, 5, 9,14,20, 5, 9,14,20,
            4,11,16,23, 4,11,16,23, 4,11,16,23, 4,11,16,23,
            6,10,15,21, 6,10,15,21, 6,10,15,21, 6,10,15,21];
    $__K = [0xd76aa478,0xe8c7b756,0x242070db,0xc1bdceee,0xf57c0faf,0x4787c62a,0xa8304613,0xfd469501,
            0x698098d8,0x8b44f7af,0xffff5bb1,0x895cd7be,0x6b901122,0xfd987193,0xa679438e,0x49b40821,
            0xf61e2562,0xc040b340,0x265e5a51,0xe9b6c7aa,0xd62f105d,0x02441453,0xd8a1e681,0xe7d3fbc8,
            0x21e1cde6,0xc33707d6,0xf4d50d87,0x455a14ed,0xa9e3e905,0xfcefa3f8,0x676f02d9,0x8d2a4c8a,
            0xfffa3942,0x8771f681,0x6d9d6122,0xfde5380c,0xa4beea44,0x4bdecfa9,0xf6bb4b60,0xbebfbc70,
            0x289b7ec6,0xeaa127fa,0xd4ef3085,0x04881d05,0xd9d4d039,0xe6db99e5,0x1fa27cf8,0xc4ac5665,
            0xf4292244,0x432aff97,0xab9423a7,0xfc93a039,0x655b59c3,0x8f0ccc92,0xffeff47d,0x85845dd1,
            0x6fa87e4f,0xfe2ce6e0,0xa3014314,0x4e0811a1,0xf7537e82,0xbd3af235,0x2ad7d2bb,0xeb86d391];
    $__a0 = 0x67452301; $__b0 = 0xefcdab89; $__c0 = 0x98badcfe; $__d0 = 0x10325476;
    $__bitLen = strlen($__msg) * 8;
    $__m = $__msg . "\x80";
    while (strlen($__m) % 64 !== 56) { $__m .= "\x00"; }
    $__m .= pack('V', $__bitLen & 0xffffffff) . pack('V', intdiv($__bitLen, 0x100000000) & 0xffffffff);
    $__a = $__a0; $__b = $__b0; $__c = $__c0; $__d = $__d0;
    $__n = strlen($__m);
    for ($__off = 0; $__off < $__n; $__off += 64) {
        $__M = [];
        for ($__j = 0; $__j < 16; $__j++) {
            $__M[$__j] = unpack('V', substr($__m, $__off + $__j * 4, 4))[1] & 0xffffffff;
        }
        $__A = $__a; $__B = $__b; $__C = $__c; $__D = $__d;
        for ($__k = 0; $__k < 64; $__k++) {
            if ($__k < 16)      { $__f = ($__B & $__C) | ((~$__B & 0xffffffff) & $__D); $__g = $__k; }
            elseif ($__k < 32)  { $__f = ($__D & $__B) | ((~$__D & 0xffffffff) & $__C); $__g = (5 * $__k + 1) & 15; }
            elseif ($__k < 48)  { $__f = $__B ^ $__C ^ $__D;                           $__g = (3 * $__k + 5) & 15; }
            else                { $__f = $__C ^ ($__B | (~$__D & 0xffffffff));          $__g = (7 * $__k) & 15; }
            $__f = $__f & 0xffffffff;
            // t = (A + f + K[k] + M[g]) mod 2^32，左循环移 s[k]，再加 B；随后 (A,B,C,D) := (D, t, B, C)
            $__t = (((($__A + $__f) & 0xffffffff) + ($__K[$__k] & 0xffffffff) + $__M[$__g]) & 0xffffffff);
            $__r = $__s[$__k];
            $__t = ((($__t << $__r) & 0xffffffff) | (($__t >> (32 - $__r)) & ((1 << $__r) - 1))) & 0xffffffff;
            $__t = ($__t + $__B) & 0xffffffff;
            $__A = $__D; $__D = $__C; $__C = $__B; $__B = $__t;
        }
        $__a = ($__a + $__A) & 0xffffffff;
        $__b = ($__b + $__B) & 0xffffffff;
        $__c = ($__c + $__C) & 0xffffffff;
        $__d = ($__d + $__D) & 0xffffffff;
    }
    $__out = pack('V', $__a) . pack('V', $__b) . pack('V', $__c) . pack('V', $__d);
    return $__raw ? $__out : bin2hex($__out);
}
