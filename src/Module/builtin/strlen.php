<?php
function __mod_strlen($__s) {
    $__s = (string)$__s;
    $__i = 0;
    // isset() 在 VM 与原生下对字符串越界的判定一致（均返回 false，且不产生告警），
    // 故以 isset 判定边界，跨 VM / 原生等价（原生 PHP 越界下标返回 '' 而非 null，
    // 旧写法 while ($__s[$__i] !== null) 在原生下恒真 → 死循环）。
    while (isset($__s[$__i])) { $__i++; }
    return $__i;
}
