<?php
function __mod_array_count_values($__a) {
    $__out = [];
    foreach ($__a as $__v) {
        $__key = is_int($__v) ? $__v : (string)$__v;
        if (isset($__out[$__key])) { $__out[$__key] = $__out[$__key] + 1; }
        else { $__out[$__key] = 1; }
    }
    return $__out;
}
