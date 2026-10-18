<?php
// 被包含模块：定义函数 + 新变量 + 输出标记（无 return，include 值为 1）
function vminc_helper($x) { return "helper(" . $x . ")"; }
$vminc_mod_made = "mod-var";
echo "[mod loaded]";
