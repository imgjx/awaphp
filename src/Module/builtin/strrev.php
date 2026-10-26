<?php
function __mod_strrev($__s) {
    $__s = (string)$__s; $__n = strlen($__s); $__o = '';
    for ($__i = $__n - 1; $__i >= 0; $__i--) $__o .= $__s[$__i];
    return $__o;
}
