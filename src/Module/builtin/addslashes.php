<?php
function __mod_addslashes($__s) {
    $__s = (string)$__s;
    $__n = strlen($__s);
    $__o = '';
    for ($__i = 0; $__i < $__n; $__i++) {
        $__c = $__s[$__i];
        if ($__c === "'" || $__c === '"' || $__c === '\\') { $__o .= '\\' . $__c; }
        elseif ($__c === "\0") { $__o .= '\\0'; }
        else { $__o .= $__c; }
    }
    return $__o;
}
