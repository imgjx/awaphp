<?php
// 被包含模块：在嵌入段内调用原生函数（验证 natTbl 合并重映射）
function nat_mod_tag($s) { return "[" . strtoupper(trim($s)) . "]"; }
$nat_mod_len = strlen("embed");
echo nat_mod_tag("mod") . $nat_mod_len;
