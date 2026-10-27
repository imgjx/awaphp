<?php
function __mod_decoct($__n) {
    $__n = (int)$__n;
    if ($__n === 0) return '0';
    $__neg = $__n < 0;
    if ($__neg) $__n = -$__n;
    $__o = '';
    while ($__n > 0) {
        $__o = (string)($__n % 8) . $__o;
        $__n = (int)($__n / 8);
    }
    return $__neg ? '-' . $__o : $__o;
}
