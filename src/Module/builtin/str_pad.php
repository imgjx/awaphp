<?php
function __mod_str_pad($__s, $__len, $__pad = " ", $__type = 1) {
    $__s = (string)$__s; $__n = strlen($__s);
    if ($__len <= $__n) return $__s;
    $__need = $__len - $__n;
    $__pl = strlen($__pad);
    if ($__pl === 0) $__pad = " ";
    $__pd = '';
    for ($__i = 0; $__i < $__need; $__i++) $__pd .= $__pad[$__i % $__pl];
    // 原生常量：STR_PAD_RIGHT=1, STR_PAD_LEFT=0, STR_PAD_BOTH=2
    if ($__type === 0) return $__pd . $__s;   // 左补
    if ($__type === 2) {
        $__l = intval($__need / 2); $__r = $__need - $__l;
        $__ls = ''; $__rs = ''; $__k = 0;
        for ($__i = 0; $__i < $__l; $__i++) { $__ls .= $__pad[$__k % $__pl]; $__k++; }
        for ($__i = 0; $__i < $__r; $__i++) { $__rs .= $__pad[$__k % $__pl]; $__k++; }
        return $__ls . $__s . $__rs;
    }
    return $__s . $__pd;   // 右补（默认/type=1）
}
