<?php
function __mod_dechex($__n) {
    $__n = (int)$__n;
    if ($__n === 0) return '0';
    $__neg = $__n < 0;
    if ($__neg) $__n = -$__n;
    $__ch = '0123456789abcdef';
    $__o = '';
    while ($__n > 0) {
        $__r = $__n % 16;
        $__o = $__ch[$__r] . $__o;
        $__n = (int)($__n / 16);
    }
    return $__neg ? '-' . $__o : $__o;
}
