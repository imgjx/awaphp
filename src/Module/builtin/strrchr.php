<?php
function __mod_strrchr($__h, $__n) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__nl = strlen($__n);
    if ($__nl === 0) return false;
    $__c = $__n[0];
    $__hl = strlen($__h);
    for ($__i = $__hl - 1; $__i >= 0; $__i--) {
        if ($__h[$__i] === $__c) {
            $__o = '';
            for ($__j = $__i; $__j < $__hl; $__j++) { $__o .= $__h[$__j]; }
            return $__o;
        }
    }
    return false;
}
