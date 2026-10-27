<?php
function __mod_array_search($__needle, $__hay, $__strict = false) {
    foreach ($__hay as $__k => $__v) {
        if ($__strict ? ($__v === $__needle) : ($__v == $__needle)) return $__k;
    }
    return false;
}
