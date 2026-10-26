<?php
function __mod_ltrim($__s, $__c = " \t\n\r\0\x0B") {
    $__s = (string)$__s; $__n = strlen($__s); $__st = 0;
    while ($__st < $__n && strpos($__c, $__s[$__st]) !== false) $__st++;
    return substr($__s, $__st);
}
