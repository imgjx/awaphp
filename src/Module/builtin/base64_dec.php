<?php
// 自制 base64 解码：替换原生 base64_decode。VM 兼容写法（无闭包/无类型/用 []）。
function __b64_dec($__s)
{
    $__t = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    $__len = strlen($__s);
    $__out = '';
    $__i = 0;
    while ($__i < $__len) {
        $__c0 = __b64_i($__t, substr($__s, $__i, 1));
        $__i++;
        $__c1 = __b64_i($__t, substr($__s, $__i, 1));
        $__i++;
        $__c2 = __b64_i($__t, substr($__s, $__i, 1));
        $__i++;
        $__c3 = __b64_i($__t, substr($__s, $__i, 1));
        $__i++;
        $__tr = (max($__c0, 0) << 18) + (max($__c1, 0) << 12) + (max($__c2, 0) << 6) + max($__c3, 0);
        $__out .= chr(($__tr >> 16) & 255);
        if ($__c2 >= 0) {
            $__out .= chr(($__tr >> 8) & 255);
        }
        if ($__c3 >= 0) {
            $__out .= chr($__tr & 255);
        }
    }
    return $__out;
}

function __b64_i($__t, $__ch)
{
    $__p = strpos($__t, $__ch);
    return ($__p === false) ? -1 : $__p;
}
