<?php
function __mod_intdiv($__a, $__b) {
    $__a = (int)$__a; $__b = (int)$__b;
    if ($__b === 0) return false;
    $__q = $__a / $__b;
    $__q = $__q < 0 ? ceil($__q) : floor($__q);
    return (int)$__q;
}
