<?php
/**
 * VM opcode 组合执行（combo execution）
 *
 * 把单条 opcode 拆解为等价的【多指令序列】，打破"单 opcode ↔ 单一语义"的 1:1 映射，
 * 让反编译器（基于 opcode 表做模式匹配的那种）无法把字节码直接还原成可读的高级运算。
 *
 * 设计原则：
 *   - 只做【语义等价的重写】，绝不改变程序行为：展开前后的栈效应、结果类型完全一致。
 *   - 只动算术/逻辑类 opcode，不碰控制流（JMP/JZ/JNZ）与跳转偏移相关的指令，因此
 *     放在 flatten/stateMachine/ISA 精简之前注入也绝不破坏相对跳转。
 *   - 概率化展开（按强度决定命中率），同一源码里并非所有站点都被替换，进一步打乱规律。
 *   - 确定性：命中判定由 Salt 派生，同 salt 复现同一产物（便于增量/差分发布）。
 *
 * 四条规则（stack 效应自证等价）：
 *   A. SUB      → NEG, ADD
 *        [.., a, b] --SUB--> [.., a-b]
 *        [.., a, b] --NEG--> [.., a, -b] --ADD--> [.., a+(-b)] = a-b      ✓
 *   B. BOOL_NOT → PUSH_C false, EQ
 *        [.., x] --BOOL_NOT--> [.., !x]
 *        [.., x] --PUSH_C false--> [.., x, false] --EQ--> [.., x==false] = !x  （对全部类型恒成立）✓
 *   C. PUSH_C 2, MUL → DUP, ADD
 *        [.., x] --PUSH_C 2--MUL--> [.., x*2]
 *        [.., x] --DUP--> [.., x, x] --ADD--> [.., x+x] = 2x               ✓
 *   D. PUSH_C 2, POW → DUP, MUL
 *        [.., x] --PUSH_C 2--POW--> [.., x**2]
 *        [.., x] --DUP--> [.., x, x] --MUL--> [.., x*x] = x**2             ✓
 *
 * 展开后产生的 NEG/ADD/DUP/EQ/PUSH_C 会被随后的 ISA 精简进一步收口（ADD→BINOP 等），
 * 组合痕迹与真实码、脏指令、诱饵函数一并被打散，难以从 handler 集合反推原运算。
 */

declare(strict_types=1);

final class VmCombo
{
    private Salt $salt;
    /** 每次命中判定自增，保证同段内不同站点拿到不同派生值 */
    private int $tick = 0;

    public function __construct(Salt $salt)
    {
        $this->salt = $salt;
    }

    /**
     * 对整个 VM Program 施加组合执行重写。
     *
     * @param array<string,mixed> $program
     * @return int 实际展开的指令条数（用于测试/日志）
     */
    public function inject(array &$program, int $intensity): int
    {
        if ($intensity <= 0) {
            return 0;
        }
        $p = $this->prob($intensity);
        $n = 0;
        $segId = 0;

        // 含异常表（try/catch/finally）的段：异常表以【绝对 pc 区间】记录处理器入口，
        // combo 插入指令会使 pc 失真，故整段跳过——与 flattenProgram 的处理一致（见 RuntimeGenerator）。
        // 否则异常处理器落点错乱，catch 块永不命中（如异常被静默吞掉）。
        $hasET = static function (array $et): bool {
            foreach ($et as $e) {
                if (!empty($e)) {
                    return true;
                }
            }
            return false;
        };
        $mainET  = $program['mainET']  ?? [];
        $funcET  = $program['funcET']  ?? [];
        $classET = $program['classET'] ?? [];
        $embedET = $program['embedET'] ?? [];

        if (isset($program['main']) && is_array($program['main']) && !$hasET($mainET)) {
            $n += $this->seg($program['main'], $p, $segId++);
        }
        if (!empty($program['funcs']) && is_array($program['funcs'])) {
            foreach ($program['funcs'] as $fn => &$s) {
                if (is_array($s) && !$hasET($funcET[$fn] ?? [])) {
                    $n += $this->seg($s, $p, $segId++);
                }
            }
            unset($s);
        }
        // include 嵌入段同样是可执行字节码
        if (!empty($program['embeds']) && is_array($program['embeds'])) {
            foreach ($program['embeds'] as $eid => &$s) {
                if (is_array($s) && !$hasET($embedET[$eid] ?? [])) {
                    $n += $this->seg($s, $p, $segId++);
                }
            }
            unset($s);
        }
        if (!empty($program['classes']) && is_array($program['classes'])) {
            foreach ($program['classes'] as $cname => &$cls) {
                if (!empty($cls['methods']) && is_array($cls['methods'])) {
                    foreach ($cls['methods'] as $mn => &$s) {
                        if (is_array($s) && !$hasET($classET[$cname][$mn] ?? [])) {
                            $n += $this->seg($s, $p, $segId++);
                        }
                    }
                    unset($s);
                }
            }
            unset($cls);
        }
        // 函数/方法参数默认值段也是可执行小段（调用前补齐时运行）
        if (!empty($program['funcParams']) && is_array($program['funcParams'])) {
            foreach ($program['funcParams'] as $fn => $spec) {
                if ($hasET($funcET[$fn] ?? [])) {
                    continue;
                }
                foreach ($spec as $i => $par) {
                    if (is_array($par[1] ?? null)) {
                        $n += $this->seg($program['funcParams'][$fn][$i][1], $p, $segId++);
                    }
                }
            }
        }
        if (!empty($program['classParams']) && is_array($program['classParams'])) {
            foreach ($program['classParams'] as $cn => $specs) {
                foreach ($specs as $i => $spec) {
                    if ($hasET($classET[$cn][$i] ?? [])) {
                        continue;
                    }
                    foreach ($spec as $j => $par) {
                        if (is_array($par[1] ?? null)) {
                            $n += $this->seg($program['classParams'][$cn][$i][$j][1], $p, $segId++);
                        }
                    }
                }
            }
        }
        return $n;
    }

    /** 强度（1..5）→ 展开命中率（0.30..0.90） */
    private function prob(int $intensity): float
    {
        return min(0.9, 0.15 + 0.15 * $intensity);
    }

    /** 确定性命中判定：同 salt 复现 */
    private function hit(float $p, int $segId): bool
    {
        $v = $this->salt->deriveInt('vm-combo|seg' . $segId . '|t' . ($this->tick++), 0, 9999);
        return ($v / 10000.0) < $p;
    }

    /**
     * 重写单段指令序列（原地）。
     *
     * 关键：规则 A/B 会【插入】指令（1→2），使段内所有绝对位置后移；而 JMP/JZ/JNZ 的
     * 操作数是相对偏移（$pc += operand），插入点之后的跳转目标随之偏移，必须同步修正，
     * 否则会跳错位置——这是初版直接替换导致阶乘返回 0 的根因。
     *
     * 修正方式：构建「旧下标 → 新下标」位置映射 map，再遍历所有相对跳转，把操作数重算为
     * newTarget - (jumpNewPos + 1)。JMP_B 的偏移是 jt 表索引（由随后的 flatten/stateMachine
     * 重建），不在此修正范围。
     *
     * @param array<int,array<int,mixed>> $seg
     */
    private function seg(array &$seg, float $p, int $segId): int
    {
        $out = [];
        $map = [];                 // 旧指令下标 -> 新指令下标（每个原下标都必须有映射，含被吃掉的）
        $fixups = [];             // ['np'=>新位置, 'old'=>旧位置]
        $n = count($seg);
        $applied = 0;
        $newPos = 0;
        for ($i = 0; $i < $n; $i++) {
            $origIdx = $i;        // 本指令原下标（规则 C/D 会 $i++ 吃掉下一条，必须以 origIdx 记录）
            $ins = $seg[$i];
            $repl = null;
            $consumed = -1;        // 规则 C/D 吃掉的下一条原下标（需补映射）
            // —— 规则 C：PUSH_C 2, MUL → DUP, ADD（2→2，不偏移）——
            if ($repl === null && isset($seg[$i + 1]) && $this->isPushC($ins, 2) && $this->isOp($seg[$i + 1], VmOp::MUL)) {
                if ($this->hit($p, $segId)) {
                    $repl = [[VmOp::DUP], [VmOp::ADD]];
                    $consumed = $i + 1;   // 被吃掉的 MUL 原下标
                    $i++;                 // 吃掉 MUL（循环末 $i++ 会再跳到其后）
                    $applied++;
                }
            }
            // —— 规则 D：PUSH_C 2, POW → DUP, MUL（2→2，不偏移）——
            if ($repl === null && isset($seg[$i + 1]) && $this->isPushC($ins, 2) && $this->isOp($seg[$i + 1], VmOp::POW)) {
                if ($this->hit($p, $segId)) {
                    $repl = [[VmOp::DUP], [VmOp::MUL]];
                    $consumed = $i + 1;   // 被吃掉的 POW 原下标
                    $i++;
                    $applied++;
                }
            }
            // —— 规则 A：SUB → NEG, ADD（1→2，偏移 +1）——
            if ($repl === null && $this->isOp($ins, VmOp::SUB)) {
                if ($this->hit($p, $segId)) {
                    $repl = [[VmOp::NEG], [VmOp::ADD]];
                    $applied++;
                }
            }
            // —— 规则 B：BOOL_NOT → PUSH_C false, EQ（1→2，偏移 +1）——
            if ($repl === null && $this->isOp($ins, VmOp::BOOL_NOT)) {
                if ($this->hit($p, $segId)) {
                    $repl = [[VmOp::PUSH_C, false], [VmOp::EQ]];
                    $applied++;
                }
            }
            if ($repl !== null) {
                $map[$origIdx] = $newPos;   // 本指令（PUSH_C / SUB / BOOL_NOT）映射到新位置
                foreach ($repl as $r) {
                    if ($this->isRelJump($r)) {
                        $fixups[] = ['np' => $newPos, 'old' => $origIdx];
                    }
                    $out[] = $r;
                    $newPos++;
                }
                // 被吃掉的 MUL/POW 映射到替换序列末尾（即下一指令的新位置），
                // 使原本跳到该乘/幂运算的跳转落到运算结束之后，而非错落到序列中段。
                if ($consumed >= 0) {
                    $map[$consumed] = $newPos;
                }
            } else {
                $map[$origIdx] = $newPos;
                if ($this->isRelJump($ins)) {
                    $fixups[] = ['np' => $newPos, 'old' => $origIdx];
                }
                $out[] = $ins;
                $newPos++;
            }
        }
        // 修正相对跳转偏移：newOperand = newTarget - (jumpNewPos + 1)
        $segLen = count($seg);
        $newLen = count($out);
        foreach ($fixups as $f) {
            $oldTarget = ($f['old'] + 1) + (int) ($seg[$f['old']][1] ?? 0);
            if ($oldTarget >= $segLen) {
                // 跳到段尾（oldTarget == segLen）或越界：对应"隐式返回点"，新段尾 = 新指令条数。
                // 这是 while/for 循环退出 JZ 的典型形态——条件为假时跳到段末返回。
                // 绝不能回落到旧下标（旧段长度 ≠ 新段长度），否则会落到段中替换序列，导致死循环。
                $newTarget = $newLen;
            } else {
                // 正常段内跳转：map 必含该下标（每条原指令都已建映射，含被规则 C/D 吃掉的）
                $newTarget = $map[$oldTarget] ?? $oldTarget;
            }
            $out[$f['np']][1] = $newTarget - ($f['np'] + 1);
        }
        $seg = $out;
        return $applied;
    }

    /** 仅 JMP/JZ/JNZ 使用相对偏移，需要修正；JMP_B 用 jt 表，不在此列 */
    private function isRelJump(array $ins): bool
    {
        $op = $ins[0] ?? null;
        return $op === VmOp::JMP || $op === VmOp::JZ || $op === VmOp::JNZ;
    }

    private function isOp($ins, int $op): bool
    {
        return is_array($ins) && ($ins[0] ?? null) === $op;
    }

    private function isPushC($ins, $val): bool
    {
        return is_array($ins)
            && ($ins[0] ?? null) === VmOp::PUSH_C
            && count($ins) >= 2
            && $ins[1] === $val;
    }
}
