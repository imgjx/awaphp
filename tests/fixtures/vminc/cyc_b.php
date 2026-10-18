<?php
// 循环包含 B：回指 A（主文件），原生语义下 A 已在执行中 → 跳过（输出不含重复 A）
$vminc_cycB = ($vminc_cycB ?? 0) + 1;
echo "[B:{$vminc_cycB}]";
include_once __DIR__ . '/cyc_a.php';
echo "[B-end:{$vminc_cycB}]";
