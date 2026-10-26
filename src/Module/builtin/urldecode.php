<?php
function __mod_urldecode($__s) {
    $__s = (string)$__s; $__out = ''; $__n = strlen($__s);
    $__hex = '0123456789ABCDEFabcdef';
    for ($__i = 0; $__i < $__n; $__i++) {
        if ($__s[$__i] === '%' && $__i+2 < $__n) {
            $__h = strtoupper($__s[$__i+1] . $__s[$__i+2]);
            $__v = strpos($__hex, $__h[0]) * 16 + strpos($__hex, $__h[1]);
            $__out .= chr($__v); $__i += 2;
        } elseif ($__s[$__i] === '+') {
            $__out .= ' ';
        } else {
            $__out .= $__s[$__i];
        }
    }
    return $__out;
}
