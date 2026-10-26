<?php
function __mod_rtrim($__s, $__c = " \t\n\r\0\x0B") {
    $__s = (string)$__s; $__n = strlen($__s); $__en = $__n;
    while ($__en > 0 && strpos($__c, $__s[$__en - 1]) !== false) $__en--;
    return substr($__s, 0, $__en);
}
