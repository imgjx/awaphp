<?php
// once 语义计数模块：无函数/类声明，可安全重复执行
$vminc_once_n = ($vminc_once_n ?? 0) + 1;
echo "[once:{$vminc_once_n}]";
