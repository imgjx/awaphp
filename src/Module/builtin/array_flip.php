<?php
function __mod_array_flip($__a) {
    $__out = [];
    foreach ($__a as $__k => $__v) {
        if (is_int($__v) || is_string($__v)) { $__out[$__v] = $__k; }
    }
    return $__out;
}
