<?php
// 自制 base64 编码：替换原生 base64_encode。VM 兼容写法（无闭包/无类型/用 []）。
//
// 与 __b64_dec 同一条性能红线：逐字符取值用下标直取，不要用 substr（否则
// 每次调用 O(n) 的 __mod_substr 会把整串编码拖成 O(n²)）。
function __b64_enc($__s)
{
    $__t = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    $__len = strlen($__s);
    $__out = '';
    $__i = 0;
    // 主循环：每次吃满 3 字节
    while ($__i + 2 < $__len) {
        $__tr = (ord($__s[$__i]) << 16) + (ord($__s[$__i + 1]) << 8) + ord($__s[$__i + 2]);
        $__i = $__i + 3;
        $__out .= $__t[($__tr >> 18) & 63] . $__t[($__tr >> 12) & 63]
            . $__t[($__tr >> 6) & 63] . $__t[$__tr & 63];
    }
    $__r = $__len - $__i;
    if ($__r === 1) {
        $__tr = ord($__s[$__i]) << 16;
        $__out .= $__t[($__tr >> 18) & 63] . $__t[($__tr >> 12) & 63] . '==';
    } elseif ($__r === 2) {
        $__tr = (ord($__s[$__i]) << 16) + (ord($__s[$__i + 1]) << 8);
        $__out .= $__t[($__tr >> 18) & 63] . $__t[($__tr >> 12) & 63]
            . $__t[($__tr >> 6) & 63] . '=';
    }
    return $__out;
}
