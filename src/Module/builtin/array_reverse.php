<?php
function __mod_array_reverse($__a, $__preserve = false) {
    $__out = [];
    if ($__preserve) {
        $__keys = [];
        foreach ($__a as $__k => $__v) $__keys[] = $__k;
        $__n = count($__keys);
        for ($__i = $__n - 1; $__i >= 0; $__i--) { $__k = $__keys[$__i]; $__out[$__k] = $__a[$__k]; }
    } else {
        $__vals = [];
        foreach ($__a as $__v) $__vals[] = $__v;
        $__n = count($__vals);
        for ($__i = $__n - 1; $__i >= 0; $__i--) $__out[] = $__vals[$__i];
    }
    return $__out;
}
