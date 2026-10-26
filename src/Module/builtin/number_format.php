<?php
function __mod_number_format($__num, $__dec = 0, $__dp = '.', $__ts = ',') {
    $__neg = ($__num < 0);
    $__num = round(abs($__num), $__dec);
    $__int = floor($__num);
    $__frac = $__num - $__int;
    $__is = (string)$__int;
    $__rev = '';
    for ($__i = strlen($__is) - 1; $__i >= 0; $__i--) $__rev .= $__is[$__i];
    $__grp = ''; $__c = 0;
    for ($__i = 0; $__i < strlen($__rev); $__i++) {
        if ($__c > 0 && $__c % 3 == 0) $__grp .= $__ts;
        $__grp .= $__rev[$__i]; $__c++;
    }
    $__out = '';
    for ($__i = strlen($__grp) - 1; $__i >= 0; $__i--) $__out .= $__grp[$__i];
    if ($__dec > 0) {
        $__fv = (string)round($__frac * pow(10, $__dec));
        while (strlen($__fv) < $__dec) $__fv = '0' . $__fv;
        if (strlen($__fv) > $__dec) {
            $__int = $__int + 1;
            $__is = (string)$__int;
            $__rev = '';
            for ($__i = strlen($__is) - 1; $__i >= 0; $__i--) $__rev .= $__is[$__i];
            $__grp = ''; $__c = 0;
            for ($__i = 0; $__i < strlen($__rev); $__i++) { if ($__c>0 && $__c%3==0) $__grp.=$__ts; $__grp.=$__rev[$__i]; $__c++; }
            $__out=''; for($__i=strlen($__grp)-1;$__i>=0;$__i--)$__out.=$__grp[$__i];
            $__fv=''; for($__i=0;$__i<$__dec;$__i++)$__fv.='0';
        }
        $__out .= $__dp . $__fv;
    }
    return ($__neg ? '-' : '') . $__out;
}
