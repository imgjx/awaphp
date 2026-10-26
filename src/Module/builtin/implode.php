<?php
function __mod_implode($__g, $__p = null) {
    if ($__p === null) { $__p = $__g; $__g = ''; }
    $__o = ''; $__first = true;
    foreach ($__p as $__v) {
        if ($__first) { $__o = (string)$__v; $__first = false; }
        else { $__o .= $__g . (string)$__v; }
    }
    return $__o;
}
