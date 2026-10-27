<?php
function __mod_array_diff($__a, ...$__rest) {
    $__out = [];
    foreach ($__a as $__k => $__v) {
        $__hit = false;
        foreach ($__rest as $__other) {
            foreach ($__other as $__ov) {
                if ((string)$__ov === (string)$__v) { $__hit = true; break; }
            }
            if ($__hit) break;
        }
        if (!$__hit) $__out[$__k] = $__v;
    }
    return $__out;
}
