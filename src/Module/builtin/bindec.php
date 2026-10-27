<?php
function __mod_bindec($__s) {
    $__s = (string)$__s;
    $__n = strlen($__s);
    $__v = 0;
    for ($__i = 0; $__i < $__n; $__i++) {
        $__ch = $__s[$__i];
        if ($__ch !== '0' && $__ch !== '1') continue;
        $__v = $__v * 2 + ($__ch === '1' ? 1 : 0);
    }
    return $__v;
}
