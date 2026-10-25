<?php
// 模块 regexmod：正则匹配（match_regex），module 内部互相 include。
require_once __DIR__ . '/regexmod_helper.php';

function rx_tag($s)
{
    return '[' . rx_wrap($s) . ']';
}
