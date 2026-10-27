<?php
function __mod_array_key_exists($__key, $__a) {
    if (!is_array($__a)) return false;
    foreach ($__a as $__k => $__v) {
        if ($__k === $__key) return true;
        if (is_int($__k) && is_string($__key) && (string)$__k === $__key) return true;
    }
    return false;
}
