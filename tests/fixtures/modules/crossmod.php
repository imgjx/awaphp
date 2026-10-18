<?php
// 模块 crossmod：调用另一个模块（mathx）已链接的函数，验证跨模块互相调用。
function cross_run($n)
{
    return mathx_double($n) + 1;
}
