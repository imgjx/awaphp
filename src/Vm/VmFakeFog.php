<?php
/**
 * VM 字节码级"假雾"诱饵函数（VmFakeFog，P8 arch_fake_fog）
 *
 * 向 Program 的 funcs 表注入一批【永不调用】的诱饵函数：
 *   - 名字形如 _fg_<hex>（盐派生，确定性，且不与真实函数重名）；
 *   - 函数体是"真实执行但无副作用"的合法字节码（栈平衡、无输出、不写变量），
 *     即使被意外调用也只会算个丢弃值；
 *   - 附 0~2 个默认参数规格，使函数表看起来更像真实业务代码。
 *
 * 收益（反静态分析）：诱饵段在 collectUsed 阶段会把它们用到的 opcode 也纳入
 * "已用操作码集合"（分配随机编号 + 生成 handler），从而：
 *   1) 真实程序实际用到的指令被一批噪声 opcode 淹没，无法从 handler 集合反推；
 *   2) 函数表规模膨胀，静态统计"函数数 / 平均长度"等特征失效。
 *
 * 注入时机：buildArtifact 在 flatten/stateMachine 之后、collectUsed 之前调用 inject()，
 * 保证诱饵 opcode 进入已用集合但不参与控制流平坦化（避免无谓膨胀状态机）。
 * fakeFog=0（默认）不注入。
 */

declare(strict_types=1);

final class VmFakeFog
{
    private Salt $salt;

    public function __construct(?Salt $salt = null)
    {
        $this->salt = $salt ?? new Salt('');
    }

    /**
     * 注入 $count 个诱饵函数。
     *
     * @param array<string,mixed> $program
     * @return int 实际注入数量
     */
    public function inject(array &$program, int $count): int
    {
        $count = max(0, $count);
        if ($count === 0) {
            return 0;
        }
        if (!is_array($program['funcs'] ?? null)) {
            $program['funcs'] = [];
        }
        $injected = 0;
        for ($k = 0; $k < $count; $k++) {
            $name = $this->uniqueName($program['funcs'], $k);
            if ($name === null) {
                break; // 极端情况：名字空间耗尽，停止注入（不影响正确性）
            }
            $program['funcs'][$name] = $this->buildBody($k);
            // 0~2 个默认参数（默认值 null），纯装饰，令函数表更逼真
            $nParam = $this->salt->deriveInt('fog-np-' . $k, 0, 2);
            if ($nParam > 0) {
                $spec = [];
                for ($p = 0; $p < $nParam; $p++) {
                    $spec[] = ['_a' . $p, null];
                }
                $program['funcParams'][$name] = $spec;
            }
            $injected++;
        }
        return $injected;
    }

    /** 生成不与既有函数重名的诱饵名（盐派生，确定性） */
    private function uniqueName(array $funcs, int $k): ?string
    {
        for ($try = 0; $try < 64; $try++) {
            $cand = '_fg_' . $this->salt->deriveHex('fog-nm-' . $k . '-' . $try, 10);
            if (!isset($funcs[$cand])) {
                return $cand;
            }
        }
        return null;
    }

    /**
     * 构造一个栈平衡、无副作用的诱饵函数体。
     * 所有分支最终都 DROP 掉中间结果并以 RET_VOID 收尾，净栈效果为 0。
     *
     * @return array<int,array<int,mixed>>
     */
    private function buildBody(int $k): array
    {
        $c = fn (string $tag): int => $this->salt->deriveInt('fog-c-' . $k . '-' . $tag, 1, 100000);
        $tpl = $this->salt->deriveInt('fog-tpl-' . $k, 0, 4);
        switch ($tpl) {
            case 0:
                return [
                    [VmOp::PUSH_C, $c('a')],
                    [VmOp::PUSH_C, $c('b')],
                    [VmOp::ADD],
                    [VmOp::DROP],
                    [VmOp::RET_VOID],
                ];
            case 1:
                return [
                    [VmOp::PUSH_C, $c('a')],
                    [VmOp::PUSH_C, $c('b')],
                    [VmOp::MUL],
                    [VmOp::PUSH_C, $c('s')],
                    [VmOp::SUB],
                    [VmOp::DROP],
                    [VmOp::RET_VOID],
                ];
            case 2:
                // 计算 + DUP + 两次 DROP：净栈 0
                return [
                    [VmOp::PUSH_C, $c('a')],
                    [VmOp::PUSH_C, $c('b')],
                    [VmOp::BW_XOR],
                    [VmOp::DUP],
                    [VmOp::DROP],
                    [VmOp::DROP],
                    [VmOp::RET_VOID],
                ];
            case 3:
                // 字符串拼接 + 丢弃（用 base64 常量，避免破坏序列化）
                return [
                    [VmOp::PUSH_C, 'fog-' . $this->salt->deriveHex('fog-s-' . $k, 8)],
                    [VmOp::PUSH_C, $this->salt->deriveInt('fog-i-' . $k, 1, 9999)],
                    [VmOp::CONCAT],
                    [VmOp::DROP],
                    [VmOp::RET_VOID],
                ];
            default:
                return [
                    [VmOp::PUSH_C, $c('a')],
                    [VmOp::DUP],
                    [VmOp::DROP],
                    [VmOp::DROP],
                    [VmOp::RET_VOID],
                ];
        }
    }
}
