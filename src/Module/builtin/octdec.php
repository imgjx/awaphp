<?php
function __mod_octdec($__s) {
    $__s = (string)$__s;
    $__n = strlen($__s);
    $__v = 0;
    for ($__i = 0; $__i < $__n; $__i++) {
        $__ch = $__s[$__i];
        if ($__ch < '0' || $__ch > '7') continue;
        $__v = $__v * 8 + (int)$__ch;
    }
    return $__v;
}
