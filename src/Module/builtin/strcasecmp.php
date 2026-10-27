<?php
function __mod_strcasecmp($__a, $__b) {
    $__a = strtolower((string)$__a);
    $__b = strtolower((string)$__b);
    $__na = strlen($__a); $__nb = strlen($__b);
    $__n = $__na < $__nb ? $__na : $__nb;
    for ($__i = 0; $__i < $__n; $__i++) {
        $__x = ord($__a[$__i]); $__y = ord($__b[$__i]);
        if ($__x !== $__y) return $__x - $__y;
    }
    if ($__na !== $__nb) return $__na - $__nb;
    return 0;
}
