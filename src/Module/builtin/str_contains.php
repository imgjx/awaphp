<?php
function __mod_str_contains($__h, $__n) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__nl = strlen($__n);
    if ($__nl === 0) return true;
    $__hl = strlen($__h);
    if ($__nl > $__hl) return false;
    for ($__i = 0; $__i <= $__hl - $__nl; $__i++) {
        $__ok = true;
        for ($__j = 0; $__j < $__nl; $__j++) {
            if ($__h[$__i + $__j] !== $__n[$__j]) { $__ok = false; break; }
        }
        if ($__ok) return true;
    }
    return false;
}
