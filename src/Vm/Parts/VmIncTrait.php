<?php
/**
 * VmCompiler 碎片：变量自增 / 自减的复合指令发射。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性 / 方法；拆分的只是源码组织，不影响行为。
 *
 * 设计：PRE_INC / PRE_DEC / POST_INC / POST_DEC 四条「专用自增码」本质是
 * 「取变量 + 加/减 1 + 写回」的叠加，可由更基础的 PUSH_V / PUSH_C / ADD / SUB /
 * DUP / POP_V 序列表达（见 Op.php 顶部「复合指令序列」说明）。因此编译器不再
 * 发射这四条专用码，改为发复合序列，从而精简主体操作码集（Op.php 已删除 80–83）。
 * 属性 / 下标自增族（PROP_*_INC/DEC、IDX_*_INC/DEC，8 条）因需对象 / 容器在栈上、
 * 干净叠加需要 SWAP 原语，仍保留专用码（走 INC_OP 族，见 VmIsa.php）。
 *
 * 语义边界（已知限制）：复合序列用 ADD/SUB 做纯算术自增，等价于 PHP 的 int/float/null
 * 自增（null 经 ADD  coercion 为 0，与原生一致）；数组自增两者均抛 TypeError（一致）。
 * 但 PHP 的两个边角语义无法由通用算术表达：① 字符串字母进位（$x="a"; $x++ → "b"）
 * 会触发 string+int TypeError；② 布尔自增（++true 保持 true）会 coercion 为 int。
 * 这两类是极罕见写法，作为复合编码的已知限制记录（详见 docs/VM_FEATURES.md）。
 */

declare(strict_types=1);

trait VmIncTrait
{
    /**
     * 发射变量自增 / 自减的复合指令序列（替代 PRE_INC/PRE_DEC/POST_INC/POST_DEC）。
     *
     * 前缀（!$post，表达式结果 = 新值）：
     *   PUSH_V name; PUSH_C 1; (ADD|SUB); DUP; POP_V name
     * 后缀（$post，表达式结果 = 旧值）：
     *   PUSH_V name; DUP; PUSH_C 1; (ADD|SUB); POP_V name
     *
     * 两种形态均依赖基础栈指令，参考运行期（runtime.php）与生成运行期
     * （RuntimeGenerator.php）均已原生支持，无需新增 handler。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function emitIncDec(array &$code, string $name, bool $dec, bool $post): void
    {
        $op = $dec ? VmOp::SUB : VmOp::ADD;
        if (!$post) {
            // 前缀：栈留新值
            $code[] = [VmOp::PUSH_V, $name];
            $code[] = [VmOp::PUSH_C, 1];
            $code[] = [$op];
            $code[] = [VmOp::DUP];
            $code[] = [VmOp::POP_V, $name];
        } else {
            // 后缀：栈留旧值
            $code[] = [VmOp::PUSH_V, $name];
            $code[] = [VmOp::DUP];
            $code[] = [VmOp::PUSH_C, 1];
            $code[] = [$op];
            $code[] = [VmOp::POP_V, $name];
        }
    }
}
