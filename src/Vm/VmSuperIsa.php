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
 * a) **真删占位，重算位置**（v1.2.11）。合并后写回 `[superOp, 子指令1操作数…, …]` 一条指令，
 *    被吸收的 (n-1) 条**从段里删掉**，段的指令条数随之变短。因此必须同步重算：
 *      - 段内所有相对跳转（JMP / JZ / JNZ / DISPATCH）的偏移；
 *      - 异常表 exTab 的 [tryStart, tryEnd, handlerPc] 三个绝对 pc。
 *    做法是先建立「旧索引 → 新索引」映射（含段尾哨兵 `map[total] = newTotal`），
 *    再把每条跳转按 `新目标 − (新索引 + 1)` 重写。异常表的三个端点与跳转目标一并
 *    进入"不可跨越"集合，合并绝不会横跨它们，因此端点必有精确映射。
 *
 *    v1.2.5 ~ v1.2.10 的旧实现是「用 NOP 占位、段的指令条数不变」，好处是零重算，
 *    代价是字节码里留下大量 NOP——它们占打包体积、占运行期数组内存，而且
 *    "成簇的 NOP"本身就是超级指令的显眼指纹（静态工具一眼看出这里被合并过）。
 *    改成真删之后，产物更小、字节码里也不再出现 NOP 簇。
 *
 * b) **跳转不得落入区间内部**。若某个跳转目标（或异常表端点）落在 [i+1, i+n-1]，
 *    说明有代码从中间开始执行这段指令，合并会漏掉前半部分。此类位置一律跳过。
 *
 * c) **只合并"无控制流副作用"的指令**。白名单里全是纯栈操作 / 算术 / 位 / 比较 /
 *    输出 / 转型，其 handler 都是单条语句，不含 `break`/`continue`/`return`、
 *    不修改 `$___PC___`。任何跳转、调用、返回、yield、闭包创建、异常表相关指令
 *    都不参与合并。
 *
 * d) 段内出现 `JMP_B`（跳转表绝对跳转，目标不在本段线性可推）时整段放弃。
 *
 * 调用时机：**常量池加密之后、remap 之前**，且必须是最后一个改动段结构的步骤——
 * 它之后只剩"改 opcode 编号"的 remap 与打包，二者都不依赖索引。此时 PUSH_C 的
 * 操作数已是常量池索引，超级指令直接携带索引即可，无须让常量池抽取逻辑认识超指令结构。
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

        // 2) 选前 max 条：长模式优先（一次省更多指令），同长度按 频次×节省 降序；
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

        // 3) 分配逻辑编号并逐段改写（段会真变短，跳转/异常表就地重算）
        $opOf = [];
        $next = self::SUPER_BASE;
        foreach ($chosen as $key => $subOps) {
            $opOf[$key] = $next++;
        }
        $applied = [];
        $this->eachSegWithET($program, function (array &$seg, ?array &$et) use ($opOf, $chosen, &$applied): void {
            $this->rewriteSeg($seg, $et, $opOf, $chosen, $applied);
        });

        // 4) 丢弃没有实际写入的超级指令定义
        foreach (array_keys($this->defs) as $op) {
            if (!isset($applied[$op])) {
                unset($this->defs[$op]);
            }
        }
    }

    /**
     * 收缩单个段：能合并的指令组折成一条超级指令，被吸收的指令直接删除，
     * 随后按「旧索引 → 新索引」映射重算所有相对跳转与异常表绝对 pc。
     *
     * @param array<int,mixed> $seg 段（就地改写）
     * @param array<int,array>|null $et 该段异常表（就地改写；无则 null）
     * @param array<string,int> $opOf 模式 key => 超级指令编号
     * @param array<string,array<int,int>> $chosen 模式 key => 子操作码序列
     * @param array<int,bool> $applied 实际用到的超级指令编号
     */
    private function rewriteSeg(array &$seg, ?array &$et, array $opOf, array $chosen, array &$applied): void
    {
        $total = count($seg);
        if ($total === 0) {
            return;
        }
        // 段内出现 JMP_B：整段放弃（目标不可线性推算）
        foreach ($seg as $ins) {
            if (is_array($ins) && isset(self::SEG_SKIP[(int) ($ins[0] ?? 0)])) {
                return;
            }
        }

        // "不可跨越"的旧索引：跳转目标 + 异常表三个端点 + 段尾哨兵。
        // 合并区间内一旦出现这些位置就跳过——否则被吸收的指令可能是别人的入口。
        $lock = [$total => true];
        foreach ($seg as $i => $ins) {
            if (!is_array($ins)) {
                continue;
            }
            $op = (int) ($ins[0] ?? 0);
            if (!isset(self::JUMPS[$op])) {
                continue;
            }
            $t = $i + 1 + (int) ($ins[1] ?? 0);
            if ($t < 0) {
                $t = 0;
            }
            if ($t > $total) {
                $t = $total;
            }
            $lock[$t] = true;
        }
        if ($et !== null) {
            foreach ($et as $e) {
                foreach ([0, 1, 2] as $f) {
                    if (!isset($e[$f])) {
                        continue;
                    }
                    $p = (int) $e[$f];
                    if ($p < 0) {
                        $p = 0;
                    }
                    if ($p > $total) {
                        $p = $total;
                    }
                    $lock[$p] = true;
                }
            }
        }

        $new = [];
        /** @var array<int,int> $map 旧索引 => 新索引 */
        $map = [];
        /** @var array<int,int> $srcOld 新索引 => 旧索引（重算跳转时回取旧偏移） */
        $srcOld = [];
        $i = 0;
        while ($i < $total) {
            $ins = $seg[$i];
            $matched = null;
            if (is_array($ins)) {
                foreach ($chosen as $key => $subOps) {
                    $len = count($subOps);
                    if ($i + $len > $total) {
                        continue;
                    }
                    // 匹配失败快速退出
                    $ok = true;
                    for ($k = 0; $k < $len; $k++) {
                        $cur = $seg[$i + $k];
                        if (!is_array($cur) || (int) ($cur[0] ?? 0) !== $subOps[$k]) {
                            $ok = false;
                            break;
                        }
                    }
                    if (!$ok) {
                        continue;
                    }
                    // 区间内部不得有跳转目标 / 异常表端点
                    for ($k = 1; $k < $len; $k++) {
                        if (isset($lock[$i + $k])) {
                            $ok = false;
                            break;
                        }
                    }
                    if (!$ok) {
                        continue;
                    }
                    $matched = $key;
                    break;
                }
            }
            if ($matched === null) {
                $slot = count($new);
                $new[] = $ins;
                $map[$i] = $slot;
                $srcOld[$slot] = $i;
                $i++;
                continue;
            }
            $subOps = $chosen[$matched];
            $len = count($subOps);
            $args = [];
            $meta = [];
            for ($k = 0; $k < $len; $k++) {
                $cur = $seg[$i + $k];
                $argc = count($cur) - 1;
                $meta[] = [$subOps[$k], $argc];
                for ($a = 1; $a <= $argc; $a++) {
                    $args[] = $cur[$a] ?? null;
                }
            }
            $superOp = $opOf[$matched];
            $this->defs[$superOp] = $meta;
            $slot = count($new);
            $new[] = array_merge([$superOp], $args);
            $srcOld[$slot] = $i;
            $map[$i] = $slot;
            // 被吸收的指令在映射里指向超级指令本身（它们不会是任何跳转目标——
            // 上面的 $lock 已保证；这里给个确定值只为映射表完整）
            for ($k = 1; $k < $len; $k++) {
                $map[$i + $k] = $slot;
            }
            $applied[$superOp] = true;
            $i += $len;
        }
        $map[$total] = count($new);

        // —— 重算相对跳转：offsets 是"从下一条指令算起"的位移 ——
        foreach ($new as $ni => $ins) {
            if (!is_array($ins)) {
                continue;
            }
            $op = (int) ($ins[0] ?? 0);
            if (!isset(self::JUMPS[$op])) {
                continue;
            }
            $oi = $srcOld[$ni] ?? null;
            if ($oi === null || !isset($seg[$oi])) {
                continue;
            }
            $t = $oi + 1 + (int) ($seg[$oi][1] ?? 0);
            if ($t < 0) {
                $t = 0;
            }
            if ($t > $total) {
                $t = $total;
            }
            $new[$ni][1] = ($map[$t] ?? count($new)) - ($ni + 1);
        }

        // —— 重算异常表：三个字段都是绝对 pc ——
        if ($et !== null && $et !== []) {
            foreach ($et as $ei => $e) {
                foreach ([0, 1, 2] as $f) {
                    if (!isset($e[$f])) {
                        continue;
                    }
                    $p = (int) $e[$f];
                    if ($p < 0) {
                        $p = 0;
                    }
                    if ($p > $total) {
                        $p = $total;
                    }
                    $et[$ei][$f] = $map[$p] ?? count($new);
                }
            }
        }

        $seg = $new;
    }

    /**
     * 遍历所有可能含有指令的段（只给段，不带异常表；用于频次统计）。
     * @param array<string,mixed> $program
     */
    private function eachSeg(array &$program, callable $fn): void
    {
        $this->eachSegWithET($program, static function (array &$seg, ?array &$et) use ($fn): void {
            $fn($seg);
        });
    }

    /**
     * 遍历所有段，并把该段的异常表（引用）一并交给回调。
     * main ↔ mainET；funcs[name] ↔ funcET[name]；embeds[i] ↔ embedET[i]；
     * classes[c].methods[m] ↔ classET[c][m]；props/consts/funcParams/classParams 无异常表。
     *
     * @param array<string,mixed> $program
     * @param callable(array<int,mixed>&, array<int,array>|null&):void $fn
     */
    private function eachSegWithET(array &$program, callable $fn): void
    {
        if (isset($program['main']) && is_array($program['main'])) {
            if (!isset($program['mainET']) || !is_array($program['mainET'])) {
                $program['mainET'] = [];
            }
            $et = &$program['mainET'];
            $fn($program['main'], $et);
            unset($et);
        }
        foreach (['funcs' => 'funcET', 'embeds' => 'embedET'] as $segKey => $etKey) {
            if (empty($program[$segKey]) || !is_array($program[$segKey])) {
                continue;
            }
            foreach ($program[$segKey] as $k => &$seg) {
                if (!is_array($seg)) {
                    continue;
                }
                if (!isset($program[$etKey]) || !is_array($program[$etKey])) {
                    $program[$etKey] = [];
                }
                if (!isset($program[$etKey][$k]) || !is_array($program[$etKey][$k])) {
                    $program[$etKey][$k] = [];
                }
                $et = &$program[$etKey][$k];
                $fn($seg, $et);
                unset($et);
            }
            unset($seg);
        }
        if (!empty($program['funcParams']) && is_array($program['funcParams'])) {
            foreach ($program['funcParams'] as &$spec) {
                foreach ($spec as &$p) {
                    if (is_array($p[1] ?? null)) {
                        $null = null;
                        $fn($p[1], $null);
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
                            $null = null;
                            $fn($p[1], $null);
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
        foreach ($program['classes'] as $cn => &$cls) {
            foreach (['props', 'consts'] as $k2) {
                if (empty($cls[$k2]) || !is_array($cls[$k2])) {
                    continue;
                }
                foreach ($cls[$k2] as &$def) {
                    if (is_array($def)) {
                        $null = null;
                        $fn($def, $null);
                    }
                }
                unset($def);
            }
            if (empty($cls['methods']) || !is_array($cls['methods'])) {
                continue;
            }
            foreach ($cls['methods'] as $mn => &$mseg) {
                if (!is_array($mseg)) {
                    continue;
                }
                if (!isset($program['classET']) || !is_array($program['classET'])) {
                    $program['classET'] = [];
                }
                if (!isset($program['classET'][$cn]) || !is_array($program['classET'][$cn])) {
                    $program['classET'][$cn] = [];
                }
                if (!isset($program['classET'][$cn][$mn]) || !is_array($program['classET'][$cn][$mn])) {
                    $program['classET'][$cn][$mn] = [];
                }
                $et = &$program['classET'][$cn][$mn];
                $fn($mseg, $et);
                unset($et);
            }
            unset($mseg);
        }
        unset($cls);
    }
}
