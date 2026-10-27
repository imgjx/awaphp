<?php
function __mod_stripslashes($__s) {
    $__s = (string)$__s;
    $__n = strlen($__s);
    $__o = '';
    $__i = 0;
    while ($__i < $__n) {
        $__c = $__s[$__i];
        if ($__c === '\\' && $__i + 1 < $__n) {
            $__i++;
            $__d = $__s[$__i];
            $__o .= ($__d === '0') ? "\0" : $__d;
        } else {
            $__o .= $__c;
        }
        $__i++;
    }
    return $__o;
}
