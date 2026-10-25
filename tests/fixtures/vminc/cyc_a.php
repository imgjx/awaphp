<?php
// 循环包含 A：B 回指 A（主文件），原生 include_once 语义只执行一次
$vminc_cyc = ($vminc_cyc ?? 0) + 1;
echo "[A:{$vminc_cyc}]";
include_once __DIR__ . '/cyc_b.php';
echo "[A-end:{$vminc_cyc}]";
