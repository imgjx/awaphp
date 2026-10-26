<?php
function __mod_str_repeat($__s, $__n) {
    if ($__n <= 0) return '';
    $__o = '';
    for ($__i = 0; $__i < $__n; $__i++) $__o .= $__s;
    return $__o;
}
