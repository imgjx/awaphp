<?php
function __mod_strlen($__s) {
    $__s = (string)$__s;
    $__i = 0;
    // VM 下字符串越界读取返回 NULL（原生返回 ""），故以 !== null 判定边界。
    while ($__s[$__i] !== null) { $__i++; }
    return $__i;
}
