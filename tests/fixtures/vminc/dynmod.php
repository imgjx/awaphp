<?php
// 运行期动态包含的目标：路径由变量拼接决定（走 INCLUDE_RT）
echo "[dynmod:" . $vminc_dyn_seen . "]";
$vminc_dyn_new = "dyn-var";
