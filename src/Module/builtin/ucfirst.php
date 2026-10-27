<?php
function __mod_ucfirst($__s) {
    $__s = (string)$__s;
    $__n = strlen($__s);
    if ($__n === 0) return '';
    $__o = strtoupper($__s[0]);
    for ($__i = 1; $__i < $__n; $__i++) { $__o .= $__s[$__i]; }
    return $__o;
}
