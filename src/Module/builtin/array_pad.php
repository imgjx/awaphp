<?php
function __mod_array_pad($__a, $__size, $__value) {
    $__n = 0;
    foreach ($__a as $__k => $__v) { $__n++; }
    $__abs = $__size < 0 ? -$__size : $__size;
    if ($__abs <= $__n) return $__a;
    $__need = $__abs - $__n;
    $__out = [];
    // 与原生一致：字符串键保留，数字键重排
    if ($__size > 0) {
        foreach ($__a as $__k => $__v) {
            if (is_int($__k)) { $__out[] = $__v; } else { $__out[$__k] = $__v; }
        }
        for ($__i = 0; $__i < $__need; $__i++) { $__out[] = $__value; }
    } else {
        for ($__i = 0; $__i < $__need; $__i++) { $__out[] = $__value; }
        foreach ($__a as $__k => $__v) {
            if (is_int($__k)) { $__out[] = $__v; } else { $__out[$__k] = $__v; }
        }
    }
    return $__out;
}
