<?php
function __mod_str_ends_with($__h, $__n) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__nl = strlen($__n); $__hl = strlen($__h);
    if ($__nl === 0) return true;
    if ($__nl > $__hl) return false;
    $__b = $__hl - $__nl;
    for ($__i = 0; $__i < $__nl; $__i++) {
        if ($__h[$__b + $__i] !== $__n[$__i]) return false;
    }
    return true;
}
