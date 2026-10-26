<?php
function __mod_explode($__d, $__s, $__limit = null) {
    $__s = (string)$__s; $__d = (string)$__d;
    if ($__d === '') return [$__s];
    $__out = []; $__n = strlen($__s); $__dl = strlen($__d);
    $__start = 0; $__cnt = 0;
    $__lim = ($__limit === null) ? 0 : (int)$__limit;
    while (true) {
        if ($__lim > 0 && $__cnt >= $__lim - 1) { $__out[] = substr($__s, $__start); break; }
        $__p = strpos($__s, $__d, $__start);
        if ($__p === false) { $__out[] = substr($__s, $__start); break; }
        $__out[] = substr($__s, $__start, $__p - $__start);
        $__start = $__p + $__dl; $__cnt++;
    }
    return $__out;
}
