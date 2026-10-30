<?php
// 自制 crc32：替换原生 crc32（IEEE 802.3）。纯 PHP 实现，与 PHP 内置 crc32()
// 在 64 位运行时下逐字节一致（返回无符号 32 位 int）。
// VM 兼容写法：表在每次调用内本地构建（不使用 static，避免被 VM 内联重命名破坏绑定）；
// 256×8 次运算开销可忽略。所有中间量用 & 0xffffffff 维持 32 位无符号语义。
function __mod_crc32($__data) {
    $__table = [];
    for ($__n = 0; $__n < 256; $__n++) {
        $__c = $__n;
        for ($__k = 0; $__k < 8; $__k++) {
            $__c = ($__c & 1) ? (0xedb88320 ^ ($__c >> 1)) : ($__c >> 1);
        }
        $__table[$__n] = $__c & 0xffffffff;
    }
    $__crc = 0xffffffff;
    for ($__i = 0, $__l = strlen($__data); $__i < $__l; $__i++) {
        $__crc = (($__crc >> 8) ^ $__table[($__crc ^ ord($__data[$__i])) & 0xff]) & 0xffffffff;
    }
    return ($__crc ^ 0xffffffff) & 0xffffffff;
}
