<?php
function __mod_chunk_split($__s, $__len = 76, $__end = "\r\n") {
    $__s = (string)$__s;
    if ($__len < 1) return false;
    $__n = strlen($__s);
    if ($__n === 0) return $__end;
    $__o = '';
    $__i = 0;
    while ($__i < $__n) {
        $__j = 0;
        while ($__j < $__len && $__i < $__n) { $__o .= $__s[$__i]; $__i++; $__j++; }
        $__o .= $__end;
    }
    return $__o;
}
