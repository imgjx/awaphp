<?php
/**
 * 指令集加速：超级指令（Superinstruction / ISA 扩展）
 *
 * 把运行时高频出现的"指令组"合并成一条新的复合指令（等价于为虚拟机新增一条 ISA 指令），
 * 运行期一次取指、一次 dispatch 就能执行完原本 n 条指令的工作。
 *
 * 双重收益：
 *   1. 性能：减少主循环迭代次数（每次迭代含一次数组取值、两次异或、跳转表查表、switch 分派）；
 *   2. 反分析：字节码里出现陌生的 opcode，且其操作数是"多条子指令操作数平铺"，
 *      静态统计指令频次、按 1:1 还原源码的套路直接失效。
 *
 * —— 正确性设计（宁可不合并，也不改炸）——
 *
 * a) **NOP 占位，指令索引恒定不变**。合并后写回的是
 *        [superOp, 子指令1操作数…, 子指令2操作数…]，紧接着补 (n-1) 条 [NOP]。
 *    段的指令**条数完全不变**，因此：
 *      - 所有相对跳转偏移（`patch()` 写入的 `target = idx+1+offset`）无需重算；
 *      - 异常表 exTab 的绝对 pc 区间无需重算；
 *      - 生成器段首标记 GEN_HEAD 的索引不变。
 *    super handler 末尾做 `$___PC___ += n-1` 跳过占位 NOP，从而真正省掉迭代。
 *
 * b) **跳转不得落入区间内部**。若某个跳转目标落在 [i+1, i+n-1]，说明有代码从中间
 *    开始执行这段指令，合并会漏掉前半部分。此类位置一律跳过。
 *
 * c) **只合并"无控制流副作用"的指令**。白名单里全是纯栈操作 / 算术 / 位 / 比较 /
 *    输出 / 转型，其 handler 都是单条语句，不含 `break`/`continue`/`return`、
 *    不修改 `$___PC___`。任何跳转、调用、返回、yield、闭包创建、异常表相关指令
 *    都不参与合并。
 *
 * d) 段内出现 `JMP_B`（跳转表绝对跳转，目标不在本段线性可推）时整段放弃。
 *
 * 调用时机：**常量池加密之后、remap 之前**。此时 PUSH_C 的操作数已是常量池索引，
 * 超级指令直接携带索引即可，无须让常量池抽取逻辑认识超指令结构。
 */

declare(strict_types=1);

final class VmSuperIsa
{
    /** 超级指令的逻辑编号起点（与 VmOp 现有编号不冲突） */
    public const SUPER_BASE = 200;

    /** 允许参与合并的指令：全部是"单语句、无控制流副作用"的 handler */
    private const WHITELIST = [
        VmOp::PUSH_C => true, VmOp::PUSH_V => true, VmOp::POP_V => true,
        VmOp::DUP => true, VmOp::DROP => true, VmOp::LOAD_THIS => true,
        VmOp::PUSH_NULL => true,
        VmOp::ADD => true, VmOp::SUB => true, VmOp::MUL => true, VmOp::DIV => true,
        VmOp::MOD => true, VmOp::POW => true, VmOp::CONCAT => true,
        VmOp::NEG => true, VmOp::BW_NOT => true,
        VmOp::BW_AND => true, VmOp::BW_OR => true, VmOp::BW_XOR => true,
        VmOp::BW_SHL => true, VmOp::BW_SHR => true,
        VmOp::EQ => true, VmOp::NE => true, VmOp::LT => true, VmOp::GT => true,
        VmOp::LE => true, VmOp::GE => true, VmOp::IDENT => true, VmOp::NIDENT => true,
        VmOp::SPACESHIP => true,
        VmOp::BOOL_AND => true, VmOp::BOOL_OR => true, VmOp::BOOL_NOT => true,
        VmOp::BOOL_XOR => true,
        VmOp::ECHO => true, VmOp::PRINT_ => true,
        VmOp::CAST_INT => true, VmOp::CAST_FLOAT => true, VmOp::CAST_STRING => true,
        VmOp::CAST_BOOL => true, VmOp::CAST_ARRAY => true, VmOp::CAST_OBJECT => true,
    ];

    /** 会改变取指位置 / 需要线性不可推目标的指令：整段放弃 */
    private const SEG_SKIP = [VmOp::JMP_B => true];

    /** 跳转指令（目标 = 索引 + 1 + 偏移） */
    private const JUMPS = [VmOp::JMP => true, VmOp::JZ => true, VmOp::JNZ => true, VmOp::DISPATCH => true];

    private Salt $salt;
    /** @var array<int,array<int,array{0:int,1:int}>> superOp => [[subOp, argc], ...] */
    private array $defs = [];

    public function __construct(Salt $salt)
    {
        $this->salt = $salt;
    }

    /** @return array<int,array<int,array{0:int,1:int}>> */
    public function defs(): array
    {
        return $this->defs;
    }

    /**
     * 规划并就地改写所有可执行段。
     * @param array<string,mixed> $program
     * @param int $max 最多新建多少条超级指令
     */
    public function plan(array &$program, int $max): void
    {
        $this->defs = [];
        if ($max <= 0) {
            return;
        }
        // 1) 统计 n-gram 频次
        $freq = [];
        $this->eachSeg($program, function (array &$seg) use (&$freq): void {
            $ops = [];
            foreach ($seg as $ins) {
                $ops[] = is_array($ins) ? (int) ($ins[0] ?? 0) : 0;
            }
            $n = count($ops);
            for ($len = 2; $len <= 4; $len++) {
                for ($i = 0; $i + $len <= $n; $i++) {
                    $ok = true;
                    for ($k = 0; $k < $len; $k++) {
                        if (!isset(self::WHITELIST[$ops[$i + $k]])) {
                            $ok = false;
                            break;
                        }
                    }
                    if (!$ok) {
                        continue;
                    }
                    $key = implode(',', array_slice($ops, $i, $len));
                    $freq[$key] = ($freq[$key] ?? 0) + 1;
                }
            }
        });
        if ($freq === []) {
            return;
        }

        // 2) 选前 max 条：长模式优先（一次省更多迭代），同长度按 频次×节省 降序；
        //    并剔除已被选中长模式"完全包含"的短模式——否则短模式永远匹配不到，白占名额。
        $cand = [];
        foreach ($freq as $key => $c) {
            $len = substr_count($key, ',') + 1;
            if ($c < 4) {
                continue; // 太罕见，不值得新增一条指令
            }
            $cand[] = ['key' => $key, 'len' => $len, 'score' => $c * ($len - 1)];
        }
        usort($cand, static function (array $a, array $b): int {
            return [$b['len'], $b['score']] <=> [$a['len'], $a['score']];
        });
        $chosen = [];
        $banned = [];
        foreach ($cand as $it) {
            if (count($chosen) >= $max) {
                break;
            }
            if (isset($banned[$it['key']])) {
                continue;
            }
            $chosen[$it['key']] = array_map('intval', explode(',', $it['key']));
            // 该模式的所有真子串（长度 >= 2）不再单独成条
            $ops = explode(',', $it['key']);
            $len = count($ops);
            for ($s = 0; $s < $len; $s++) {
                for ($e = $s + 2; $e <= $len; $e++) {
                    if ($e - $s === $len) {
                        continue;
                    }
                    $banned[implode(',', array_slice($ops, $s, $e - $s))] = true;
                }
            }
        }
        if ($chosen === []) {
            return;
        }
        // 长模式优先匹配
        uasort($chosen, static function (array $a, array $b): int {
            return count($b) <=> count($a);
        });

        // 3) 分配逻辑编号并逐段替换
        $opOf = [];
        $next = self::SUPER_BASE;
        foreach ($chosen as $key => $subOps) {
            $opOf[$key] = $next++;
        }
        $applied = [];
        $this->eachSeg($program, function (array &$seg) use ($opOf, $chosen, &$applied): void {
            if ($seg === []) {
                return;
            }
            // 段内出现 JMP_B：整段放弃（目标不可线性推算）
            foreach ($seg as $ins) {
                if (is_array($ins) && isset(self::SEG_SKIP[(int) ($ins[0] ?? 0)])) {
                    return;
                }
            }
            // 跳转目标集合
            $targets = [];
            $total = count($seg);
            foreach ($seg as $i => $ins) {
                if (!is_array($ins)) {
                    continue;
                }
                $op = (int) ($ins[0] ?? 0);
                if (isset(self::JUMPS[$op])) {
                    $off = (int) ($ins[1] ?? 0);
                    $t = $i + 1 + $off;
                    if ($t >= 0 && $t < $total) {
                        $targets[$t] = true;
                    }
                }
            }
            $out = $seg;
            $i = 0;
            for ($i = 0; $i < $total; $i++) {
                if (!is_array($out[$i])) {
                    continue;
                }
                $matched = null;
                foreach ($chosen as $key => $subOps) {
                    $len = count($subOps);
                    if ($i + $len > $total) {
                        continue;
                    }
                    // 匹配失败快速退出
                    $ok = true;
                    for ($k = 0; $k < $len; $k++) {
                        $cur = $out[$i + $k];
                        if (!is_array($cur) || (int) ($cur[0] ?? 0) !== $subOps[$k]) {
                            $ok = false;
                            break;
                        }
                    }
                    if (!$ok) {
                        continue;
                    }
                    // 区间内部不得有跳转目标
                    for ($k = 1; $k < $len; $k++) {
                        if (isset($targets[$i + $k])) {
                            $ok = false;
                            break 2;
                        }
                    }
                    $matched = $key;
                    break;
                }
                if ($matched === null) {
                    continue;
                }
                $subOps = $chosen[$matched];
                $len = count($subOps);
                $args = [];
                $meta = [];
                for ($k = 0; $k < $len; $k++) {
                    $cur = $out[$i + $k];
                    $argc = count($cur) - 1;
                    $meta[] = [$subOps[$k], $argc];
                    for ($a = 1; $a <= $argc; $a++) {
                        $args[] = $cur[$a] ?? null;
                    }
                }
                $superOp = $opOf[$matched];
                $this->defs[$superOp] = $meta;
                $out[$i] = array_merge([$superOp], $args);
                for ($k = 1; $k < $len; $k++) {
                    $out[$i + $k] = [VmOp::NOP];
                }
                $applied[$superOp] = true;
                $i += $len - 1;
            }
            $seg = $out;
        });

        // 4) 丢弃没有实际写入的超级指令定义
        foreach (array_keys($this->defs) as $op) {
            if (!isset($applied[$op])) {
                unset($this->defs[$op]);
            }
        }
    }

    /**
     * 遍历所有可能含有指令的段。
     * @param array<string,mixed> $program
     */
    private function eachSeg(array &$program, callable $fn): void
    {
        if (isset($program['main']) && is_array($program['main'])) {
            $fn($program['main']);
        }
        foreach (['funcs', 'embeds'] as $key) {
            if (empty($program[$key]) || !is_array($program[$key])) {
                continue;
            }
            foreach ($program[$key] as &$seg) {
                if (is_array($seg)) {
                    $fn($seg);
                }
            }
            unset($seg);
        }
        if (!empty($program['funcParams']) && is_array($program['funcParams'])) {
            foreach ($program['funcParams'] as &$spec) {
                foreach ($spec as &$p) {
                    if (is_array($p[1] ?? null)) {
                        $fn($p[1]);
                    }
                }
                unset($p);
            }
            unset($spec);
        }
        if (!empty($program['classParams']) && is_array($program['classParams'])) {
            foreach ($program['classParams'] as &$specs) {
                foreach ($specs as &$spec) {
                    foreach ($spec as &$p) {
                        if (is_array($p[1] ?? null)) {
                            $fn($p[1]);
                        }
                    }
                    unset($p);
                }
                unset($spec);
            }
            unset($specs);
        }
        if (empty($program['classes']) || !is_array($program['classes'])) {
            return;
        }
        foreach ($program['classes'] as &$cls) {
            foreach (['props', 'consts'] as $k2) {
                if (empty($cls[$k2]) || !is_array($cls[$k2])) {
                    continue;
                }
                foreach ($cls[$k2] as &$def) {
                    if (is_array($def)) {
                        $fn($def);
                    }
                }
                unset($def);
            }
            if (!empty($cls['methods']) && is_array($cls['methods'])) {
                foreach ($cls['methods'] as &$mseg) {
                    if (is_array($mseg)) {
                        $fn($mseg);
                    }
                }
                unset($mseg);
            }
        }
        unset($cls);
    }
}
