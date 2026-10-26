<?php
function __mod_array_merge(...$__arrs) {
    $__out = [];
    foreach ($__arrs as $__a) {
        if (!is_array($__a)) continue;
        foreach ($__a as $__k => $__v) {
            if (is_int($__k)) { $__out[] = $__v; }
            else { $__out[$__k] = $__v; }
        }
    }
    return $__out;
}
