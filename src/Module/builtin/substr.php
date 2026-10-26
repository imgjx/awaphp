<?php
function __mod_substr($__s, $__start, $__len = null) {
    $__s = (string)$__s;
    $__n = strlen($__s);
    $__b = $__start;
    if ($__b < 0) { $__b = $__n + $__b; if ($__b < 0) $__b = 0; }
    if ($__b > $__n) $__b = $__n;
    $__e = $__n;
    if ($__len !== null) {
        if ($__len < 0) { $__e = $__n + $__len; }
        else { $__e = $__b + $__len; }
        if ($__e > $__n) $__e = $__n;
        if ($__e < $__b) $__e = $__b;
    }
    $__o = '';
    for ($__i = $__b; $__i < $__e; $__i++) { $__o .= $__s[$__i]; }
    return $__o;
}
