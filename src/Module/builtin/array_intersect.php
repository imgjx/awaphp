<?php
function __mod_array_intersect($__a, ...$__rest) {
    $__out = [];
    foreach ($__a as $__k => $__v) {
        $__ok = true;
        foreach ($__rest as $__other) {
            $__hit = false;
            foreach ($__other as $__ov) {
                if ((string)$__ov === (string)$__v) { $__hit = true; break; }
            }
            if (!$__hit) { $__ok = false; break; }
        }
        if ($__ok) $__out[$__k] = $__v;
    }
    return $__out;
}
