<?php
function __mod_strcmp($__a, $__b) {
    $__a = (string)$__a; $__b = (string)$__b;
    $__n = strlen($__a); $__m = strlen($__b);
    $__min = ($__n < $__m) ? $__n : $__m;
    for ($__i = 0; $__i < $__min; $__i++) {
        $__ca = ord($__a[$__i]); $__cb = ord($__b[$__i]);
        if ($__ca !== $__cb) return $__ca - $__cb;
    }
    return $__n - $__m;
}
