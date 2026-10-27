<?php
function __mod_strstr($__h, $__n, $__before = false) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__hl = strlen($__h); $__nl = strlen($__n);
    if ($__nl === 0) return false;
    $__p = false;
    for ($__i = 0; $__i <= $__hl - $__nl; $__i++) {
        $__ok = true;
        for ($__j = 0; $__j < $__nl; $__j++) {
            if ($__h[$__i + $__j] !== $__n[$__j]) { $__ok = false; break; }
        }
        if ($__ok) { $__p = $__i; break; }
    }
    if ($__p === false) return false;
    $__end = $__before ? $__p : $__hl;
    $__beg = $__before ? 0 : $__p;
    $__o = '';
    for ($__i = $__beg; $__i < $__end; $__i++) { $__o .= $__h[$__i]; }
    return $__o;
}
