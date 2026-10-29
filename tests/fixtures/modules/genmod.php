<?php
// 模块 genmod：含【生成器】的模块。v1.0.9 的 VM 已支持 yield / yield from
// （GEN_HEAD / YIELD / YIELD_FROM + Fiber 桥），故本模块能被完整编译链接，
// 调用点改走 VM 内的函数表——验证「生成器不再导致模块被整体跳过」。
function gen_seq($n)
{
    for ($i = 0; $i < $n; $i++) {
        yield $i => $i * 2;
    }
    return 'done';
}
