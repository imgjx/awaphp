<?php
function __mod_ord($__c) {
    $__c = (string)$__c;
    if ($__c === '') return 0;
    $__a = unpack('C', $__c[0]);
    return (int)$__a[1];
}
