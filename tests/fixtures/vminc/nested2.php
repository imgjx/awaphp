<?php
// 二级被包含：检验跨级作用域（一级 includer 的变量 + 模块函数均可见）
echo "[l2]" . $vminc_n1 . "+" . vminc_helper($vminc_n1);
$vminc_l2 = "l2-var";
