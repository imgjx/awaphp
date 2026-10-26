<?php
function __mod_base_convert($__num, $__from, $__to) {
    $__chars = '0123456789abcdefghijklmnopqrstuvwxyz';
    $__num = strtolower((string)$__num);
    $__n = 0; $__len = strlen($__num);
    for ($__i = 0; $__i < $__len; $__i++) {
        $__d = strpos($__chars, $__num[$__i]);
        if ($__d === false || $__d >= $__from) continue;
        $__n = $__n * $__from + $__d;
    }
    if ($__n === 0) return '0';
    $__out = '';
    while ($__n > 0) {
        $__r = $__n % $__to;
        $__out = $__chars[$__r] . $__out;
        $__n = intval($__n / $__to);
    }
    return $__out;
}
