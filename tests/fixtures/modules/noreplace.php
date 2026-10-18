<?php
// 模块 noreplace：replace=false，命中但不内联（调用点保持原生函数分发）。
function nr_square($n)
{
    return $n * $n;
}
