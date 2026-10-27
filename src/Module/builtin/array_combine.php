<?php
function __mod_array_combine($__keys, $__vals) {
    $__ks = []; $__vs = [];
    foreach ($__keys as $__k) { $__ks[] = $__k; }
    foreach ($__vals as $__v) { $__vs[] = $__v; }
    $__nk = 0; $__nv = 0;
    foreach ($__ks as $__x) { $__nk++; }
    foreach ($__vs as $__x) { $__nv++; }
    if ($__nk !== $__nv) return false;
    $__out = [];
    for ($__i = 0; $__i < $__nk; $__i++) { $__out[$__ks[$__i]] = $__vs[$__i]; }
    return $__out;
}
