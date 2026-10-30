<?php
// 自制 sha1：替换原生 sha1。纯 PHP 实现，与 PHP 内置 sha1() 逐字节一致。
// VM 兼容写法（无类型声明；所有中间量用 & 0xffffffff 维持在 32 位无符号语义）。
function __mod_sha1($__msg, $__raw = false) {
    $__h = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476, 0xc3d2e1f0];
    $__bitLen = strlen($__msg) * 8;
    $__m = $__msg . "\x80";
    while (strlen($__m) % 64 !== 56) { $__m .= "\x00"; }
    $__m .= pack('N', intdiv($__bitLen, 0x100000000) & 0xffffffff) . pack('N', $__bitLen & 0xffffffff);
    $__n = strlen($__m);
    for ($__off = 0; $__off < $__n; $__off += 64) {
        $__w = [];
        for ($__j = 0; $__j < 16; $__j++) {
            $__w[$__j] = unpack('N', substr($__m, $__off + $__j * 4, 4))[1] & 0xffffffff;
        }
        for ($__j = 16; $__j < 80; $__j++) {
            $__v = $__w[$__j - 3] ^ $__w[$__j - 8] ^ $__w[$__j - 14] ^ $__w[$__j - 16];
            $__w[$__j] = ((($__v << 1) & 0xffffffff) | (($__v >> 31) & 1)) & 0xffffffff;
        }
        $__a = $__h[0]; $__b = $__h[1]; $__c = $__h[2]; $__d = $__h[3]; $__e = $__h[4];
        for ($__i = 0; $__i < 80; $__i++) {
            if ($__i < 20)      { $__f = ($__b & $__c) | ((~$__b & 0xffffffff) & $__d); $__k = 0x5a827999; }
            elseif ($__i < 40)  { $__f = $__b ^ $__c ^ $__d;                            $__k = 0x6ed9eba1; }
            elseif ($__i < 60)  { $__f = ($__b & $__c) | ($__b & $__d) | ($__c & $__d);  $__k = 0x8f1bbcdc; }
            else                { $__f = $__b ^ $__c ^ $__d;                            $__k = 0xca62c1d6; }
            $__f = $__f & 0xffffffff;
            $__tmp = ((($__a << 5) & 0xffffffff) | (($__a >> 27) & 0x1f)) & 0xffffffff;
            $__tmp = ((($__tmp + $__f) & 0xffffffff) + ($__e & 0xffffffff) + ($__k & 0xffffffff) + $__w[$__i]) & 0xffffffff;
            $__e = $__d; $__d = $__c; $__c = ((($__b << 30) & 0xffffffff) | (($__b >> 2) & 0x3fffffff)) & 0xffffffff;
            $__b = $__a; $__a = $__tmp;
        }
        $__h[0] = ($__h[0] + $__a) & 0xffffffff;
        $__h[1] = ($__h[1] + $__b) & 0xffffffff;
        $__h[2] = ($__h[2] + $__c) & 0xffffffff;
        $__h[3] = ($__h[3] + $__d) & 0xffffffff;
        $__h[4] = ($__h[4] + $__e) & 0xffffffff;
    }
    $__out = '';
    for ($__i = 0; $__i < 5; $__i++) {
        $__out .= pack('N', $__h[$__i]);
    }
    return $__raw ? $__out : bin2hex($__out);
}
