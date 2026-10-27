<?php
function __mod_strrpos($__h, $__n, $__off = 0) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__hl = strlen($__h); $__nl = strlen($__n);
    if ($__nl === 0) return $__hl;
    $__end = $__hl - $__nl;
    $__start = 0;
    if ($__off > 0) { $__start = $__off; }
    elseif ($__off < 0) { $__end = $__end + $__off; }
    for ($__i = $__end; $__i >= $__start; $__i--) {
        $__ok = true;
        for ($__j = 0; $__j < $__nl; $__j++) {
            if ($__h[$__i + $__j] !== $__n[$__j]) { $__ok = false; break; }
        }
        if ($__ok) return $__i;
    }
    return false;
}
