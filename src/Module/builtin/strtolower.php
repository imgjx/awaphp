<?php
function __mod_strtolower($__s) {
    $__s = (string)$__s; $__o = '';
    for ($__i = 0; $__i < strlen($__s); $__i++) {
        $__c = $__s[$__i]; $__v = ord($__c);
        if ($__v >= 65 && $__v <= 90) $__v += 32;
        $__o .= chr($__v);
    }
    return $__o;
}
