<?php
function __mod_ceil($__v) {
    $__v = $__v + 0;
    $__f = (int)$__v;
    if ($__v > $__f) return (float)($__f + 1);
    return (float)$__f;
}
