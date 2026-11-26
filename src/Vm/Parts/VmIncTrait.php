<?php
/**
 * VmCompiler 碎片：变量自增 / 自减的指令发射。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性 / 方法；拆分的只是源码组织，不影响行为。
 *
 * 历史：v1.2.x 曾把变量自增改写成 PUSH_V / PUSH_C / ADD / DUP / POP_V 复合序列，
 * 以精简 ISA。但纯算术 ADD/SUB 无法表达 PHP 的两类边角语义：
 *   ① 字符串字母进位（$x="a"; $x++ → "b"）会触发 string+int 的 TypeError；
 *   ② 布尔自增（++true 保持 true）会被 coercion 成 int。
 * 这在 VM 下是「静默算错 / 运行期崩溃」，而非可接受的混淆代价，故 v1.3.1 起恢复
 * 4 条变量专用码（VAR_POST/PRE_INC/DEC），handler 直接对帧槽执行 PHP 原生 ++/--，
 * 与属性 / 下标自增共用 $__inc 助手，逐字节对齐原生。
 *
 * 属性 / 下标自增族（PROP_*_INC/DEC、IDX_*_INC/DEC，8 条）继续走 INC_OP 族；
 * 新增的 VAR_* 也并入同一族（VmIsa::INC），由动态 ISA 计划统一分配随机 sub-id。
 */

declare(strict_types=1);

trait VmIncTrait
{
    /**
     * 发射变量自增 / 自减指令。
     *
     * 前缀（!$post，表达式结果 = 新值）：VAR_PRE_INC/DEC name
     * 后缀（$post，表达式结果 = 旧值）：VAR_POST_INC/DEC name
     *
     * 运行期 handler 对帧槽执行 PHP 原生 ++/--（经 $__inc），语义覆盖
     * int/float/null/bool/string（含字母进位）/array（抛 TypeError），与原生一致。
     * 引用别名（$b = &$a）经帧表引用自然穿透。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function emitIncDec(array &$code, string $name, bool $dec, bool $post): void
    {
        if ($post) {
            $op = $dec ? VmOp::VAR_POST_DEC : VmOp::VAR_POST_INC;
        } else {
            $op = $dec ? VmOp::VAR_PRE_DEC : VmOp::VAR_PRE_INC;
        }
        $code[] = [$op, $name];
    }
}
