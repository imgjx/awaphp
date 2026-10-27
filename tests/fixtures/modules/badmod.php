<?php
// 模块 badmod：entry 含 VM 整体不支持的语法（yield 生成器），整文件编译失败
// → ModuleLinker 记 skips，调用点保持原生函数分发（与 P6 前行为一致）。
function bad_call($x)
{
    return $x;
}

// 生成器：VM 不支持 yield，使整文件编译失败（bad_call 因此被整体跳过，
// 而非被部分链接——模块是原子的：要么全编译、要么全跳过）。
function bad_gen()
{
    yield 1;
}
