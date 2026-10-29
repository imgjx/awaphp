<?php
// 模块 badmod：链接期失败（清单声明的 deps 文件不存在）→ ModuleLinker 记 skips，
// 调用点保持原生函数分发；产物用 inlineSource 把模块源码原生内嵌，调用不致命。
//
// 历史说明：本夹具原先靠「yield 生成器 VM 不支持」制造编译失败。v1.0.9 已给 VM 补上
// 生成器支持（GEN_HEAD / YIELD / YIELD_FROM + Fiber 桥），该文件如今能正常编译链接，
// 故改用「依赖缺失」这条仍然真实存在的失败路径；生成器模块的正向用例见 genmod。
function bad_call($x)
{
    return $x;
}

function bad_gen()
{
    yield 1;
}
