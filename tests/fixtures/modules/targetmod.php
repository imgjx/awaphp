<?php
// 模块 targetmod：命中名与模块内函数不同名，经 target 改指向。
function tm_impl($a, $b)
{
    return $a * 100 + $b;
}
