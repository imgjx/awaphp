<?php
// 模块 mystr：精确名匹配。entry 定义命中函数 + 一个内部辅助函数。
function my_strlen($s)
{
    if (!is_string($s)) {
        return 0;
    }
    return my_count_chars($s);
}

// 模块内部辅助函数（被 my_strlen 调用，验证模块内互相调用）
function my_count_chars($s)
{
    $n = 0;
    while (($c = substr($s, $n, 1)) !== '') {
        $n++;
    }
    return $n;
}
