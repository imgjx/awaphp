<?php
function __mod_ucwords($__s, $__sep = " \t\r\n\f\v") {
    $__s = (string)$__s;
    $__n = strlen($__s);
    $__o = '';
    $__up = true;
    for ($__i = 0; $__i < $__n; $__i++) {
        $__c = $__s[$__i];
        $__o .= $__up ? strtoupper($__c) : $__c;
        $__up = (strpos($__sep, $__c) !== false);
    }
    return $__o;
}
