<?php
function __mod_round($__val, $__prec = 0) {
    $__mult = pow(10, $__prec);
    $__x = $__val * $__mult;
    $__sign = ($__x < 0) ? -1 : 1;
    $__ax = abs($__x);
    $__f = floor($__ax);
    $__r = $__ax - $__f;
    if ($__r >= 0.5) $__f = $__f + 1;
    return $__sign * $__f / $__mult;
}
