<?php
function __mod_array_fill($__start, $__count, $__value) {
    $__out = [];
    if ($__count <= 0) return $__out;
    for ($__i = 0; $__i < $__count; $__i++) { $__out[$__start + $__i] = $__value; }
    return $__out;
}
