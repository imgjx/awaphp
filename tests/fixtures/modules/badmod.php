<?php
// 模块 badmod：entry 含 VM 整体不支持的语法（trait 适配块），整文件编译失败
// → ModuleLinker 记 skips，调用点保持原生函数分发（与 P6 前行为一致）。
function bad_call($x)
{
    return $x;
}

trait T1 { public function a() {} }
trait T2 { public function a() {} }
class C {
    use T1, T2 {
        T1::a insteadof T2;
    }
}
