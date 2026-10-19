<?php
// 自制 base64 编码：替换原生 base64_encode。VM 兼容写法（无闭包/无类型/用 []）。
function __b64_enc($__s)
{
    $__t = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    $__len = strlen($__s);
    $__out = '';
    $__i = 0;
    while ($__i < $__len) {
        $__b0 = ord(substr($__s, $__i, 1));
        $__i++;
        $__b1 = ($__i < $__len) ? ord(substr($__s, $__i, 1)) : 0;
        $__i++;
        $__b2 = ($__i < $__len) ? ord(substr($__s, $__i, 1)) : 0;
        $__i++;
        $__tr = ($__b0 << 16) + ($__b1 << 8) + $__b2;
        $__out .= substr($__t, ($__tr >> 18) & 63, 1) . substr($__t, ($__tr >> 12) & 63, 1);
        $__out .= ($__i > $__len + 1) ? '=' : substr($__t, ($__tr >> 6) & 63, 1);
        $__out .= ($__i > $__len) ? '=' : substr($__t, $__tr & 63, 1);
    }
    return $__out;
}
