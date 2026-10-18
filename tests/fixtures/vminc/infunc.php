<?php
// 函数内 include：共享函数局部作用域（includer 的局部变量可见，新变量也留在该作用域）
function vminc_infunc_scope($seed) {
    $vminc_local = "local-" . $seed;
    include __DIR__ . '/mod.php';
    return $vminc_mod_made . "/" . $vminc_local;
}
