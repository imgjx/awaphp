<?php
function __mod_array_slice($__a, $__off, $__len = null, $__preserve = false) {
    $__items = [];
    foreach ($__a as $__k => $__v) { $__items[] = [$__k, $__v]; }
    $__n = 0;
    foreach ($__items as $__it) { $__n++; }
    $__b = $__off;
    if ($__b < 0) { $__b = $__n + $__b; if ($__b < 0) $__b = 0; }
    if ($__b > $__n) $__b = $__n;
    $__e = $__n;
    if ($__len !== null) {
        if ($__len < 0) { $__e = $__n + $__len; }
        else { $__e = $__b + $__len; }
        if ($__e > $__n) $__e = $__n;
        if ($__e < $__b) $__e = $__b;
    }
    $__out = [];
    for ($__i = $__b; $__i < $__e; $__i++) {
        $__k = $__items[$__i][0];
        $__v = $__items[$__i][1];
        if ($__preserve || !is_int($__k)) { $__out[$__k] = $__v; }
        else { $__out[] = $__v; }
    }
    return $__out;
}
