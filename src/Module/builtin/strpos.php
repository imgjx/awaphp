<?php
function __mod_strpos($__h, $__n, $__off = 0) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__hl = strlen($__h); $__nl = strlen($__n);
    if ($__nl === 0) return 0;
    if ($__off < 0) return false;
    for ($__i = $__off; $__i <= $__hl - $__nl; $__i++) {
        $__ok = true;
        for ($__j = 0; $__j < $__nl; $__j++) {
            if ($__h[$__i + $__j] !== $__n[$__j]) { $__ok = false; break; }
        }
        if ($__ok) return $__i;
    }
    return false;
}
