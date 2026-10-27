<?php
function __mod_hexdec($__s) {
    $__s = strtolower((string)$__s);
    $__c = '0123456789abcdef';
    $__n = strlen($__s);
    $__v = 0;
    for ($__i = 0; $__i < $__n; $__i++) {
        $__d = strpos($__c, $__s[$__i]);
        if ($__d === false) continue;
        $__v = $__v * 16 + $__d;
    }
    return $__v;
}
