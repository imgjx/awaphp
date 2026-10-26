<?php
function __mod_in_array($__n, $__h, $__strict = false) {
    foreach ($__h as $__v) {
        if ($__strict ? ($__v === $__n) : ($__v == $__n)) return true;
    }
    return false;
}
