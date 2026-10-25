<?php
// 模块 mathx：entry + deps 组合。entry 调用 deps 文件里的辅助函数。
function mathx_double($n)
{
    return $n - mathx_neg($n);
}
