<?php
function __mod_substr_replace($__s, $__rep, $__start, $__len = null) {
    $__s = (string)$__s; $__rep = (string)$__rep;
    $__n = strlen($__s);
    $__b = $__start;
    if ($__b < 0) { $__b = $__n + $__b; if ($__b < 0) $__b = 0; }
    if ($__b > $__n) $__b = $__n;
    if ($__len === null) { $__e = $__n; }
    elseif ($__len < 0) { $__e = $__n + $__len; if ($__e < $__b) $__e = $__b; }
    else { $__e = $__b + $__len; if ($__e > $__n) $__e = $__n; }
    $__o = '';
    for ($__i = 0; $__i < $__b; $__i++) { $__o .= $__s[$__i]; }
    $__o .= $__rep;
    for ($__i = $__e; $__i < $__n; $__i++) { $__o .= $__s[$__i]; }
    return $__o;
}
