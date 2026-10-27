<?php
function __mod_str_starts_with($__h, $__n) {
    $__h = (string)$__h; $__n = (string)$__n;
    $__nl = strlen($__n);
    if ($__nl === 0) return true;
    if ($__nl > strlen($__h)) return false;
    for ($__i = 0; $__i < $__nl; $__i++) {
        if ($__h[$__i] !== $__n[$__i]) return false;
    }
    return true;
}
