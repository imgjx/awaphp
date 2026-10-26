<?php
function __mod_array_unique($__a) {
    $__seen = []; $__out = [];
    foreach ($__a as $__k => $__v) {
        $__key = is_int($__v) ? ('i:' . $__v) : ('s:' . $__v);
        if (!isset($__seen[$__key])) { $__seen[$__key] = true; $__out[$__k] = $__v; }
    }
    return $__out;
}
