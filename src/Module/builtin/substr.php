<?php
function __mod_substr($__s, $__start, $__len = null) {
    $__s = (string)$__s;
    // 快路径：非负起点 + 非负长度。一般路径首步 strlen($__s) 是整串扫描（O(n)），
    // 逐字符取值的调用（编码/解码/分词类循环）叠加起来会退化成 O(n²)。
    // isset($__s[$__i]) 在原生与 VM 下同为"该下标是否有效"，且不产生告警。
    if ($__len !== null && $__start >= 0 && $__len >= 0) {
        $__e = $__start + $__len;
        $__o = '';
        for ($__i = $__start; $__i < $__e && isset($__s[$__i]); $__i++) { $__o .= $__s[$__i]; }
        return $__o;
    }
    $__n = strlen($__s);
    $__b = $__start;
    if ($__b < 0) { $__b = $__n + $__b; if ($__b < 0) $__b = 0; }
    if ($__b > $__n) $__b = $__n;
    $__e = $__n;
    if ($__len !== null) {
        if ($__len < 0) { $__e = $__n + $__len; }
        else { $__e = $__b + $__len; }
        if ($__e > $__n) $__e = $__n;
        if ($__e < $__b) $__e = $__b;
    }
    $__o = '';
    for ($__i = $__b; $__i < $__e; $__i++) { $__o .= $__s[$__i]; }
    return $__o;
}
