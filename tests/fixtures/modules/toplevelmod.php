<?php
// 模块 toplevelmod：除函数外还有一条顶层语句（应被告警忽略，仅链接函数）。
$GLOBALS['_tl_mod_init'] = 1;

function tl_add($a, $b)
{
    return $a + $b;
}
