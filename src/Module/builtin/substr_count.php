<?php
function __mod_substr_count($__h, $__n, $__off = 0, $__len = null) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__hl = strlen($__h); $__nl = strlen($__n);
    if ($__nl === 0) return 0;
    if ($__off < 0) $__off = 0;
    $__end = $__hl;
    if ($__len !== null) {
        $__end = $__off + $__len;
        if ($__end > $__hl) $__end = $__hl;
    }
    $__c = 0;
    $__i = $__off;
    while ($__i <= $__end - $__nl) {
        $__ok = true;
        for ($__j = 0; $__j < $__nl; $__j++) {
            if ($__h[$__i + $__j] !== $__n[$__j]) { $__ok = false; break; }
        }
        if ($__ok) { $__c++; $__i += $__nl; }
        else { $__i++; }
    }
    return $__c;
}
