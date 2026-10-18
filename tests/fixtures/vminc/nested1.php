<?php
// 一级被包含：检验 includer 变量可见、自身新变量可被 includer 看到、多级嵌套
$vminc_n1 = "N1";
include __DIR__ . '/mod.php';
echo "[l1 sees]" . $vminc_top;
include __DIR__ . '/nested2.php';
$vminc_l1 = "l1-var";
