<?php
function __mod_strncmp($__a, $__b, $__len) {
    $__a = (string)$__a; $__b = (string)$__b;
    $__na = strlen($__a); $__nb = strlen($__b);
    $__n = $__len;
    if ($__n > $__na) $__n = $__na;
    if ($__n > $__nb) $__n = $__nb;
    for ($__i = 0; $__i < $__n; $__i++) {
        $__x = ord($__a[$__i]); $__y = ord($__b[$__i]);
        if ($__x !== $__y) return $__x - $__y;
    }
    if ($__len <= $__n) return 0;
    $__ra = $__na > $__len ? $__len : $__na;
    $__rb = $__nb > $__len ? $__len : $__nb;
    return $__ra - $__rb;
}
