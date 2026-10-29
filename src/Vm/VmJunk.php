<?php
/**
 * VM 字节码级真实垃圾指令（VmJunk，P7）
 *
 * 在【编译期、字节码层面】向已编译的 VM Program 注入"真实执行"的垃圾指令序列：
 * 运行时解释器会逐条执行这些 opcode，但它们
 *   1. 仅操作操作数栈（PUSH_C + 算术/位运算/比较/拼接/转型 + DROP），绝不触碰帧变量；
 *   2. 严格栈平衡（入栈与出栈配对，净变化为 0），不破坏后续真实指令的执行；
 *   3. 无输出、无副作用、不调用任何函数、不含控制流跳转，绝不崩溃；
 *   4. 经 salt 确定性生成，可复现（同 salt + 同源码 + 同强度 ⇒ 产物逐字节一致）。
 *
 * 与 src/Optimize/RuntimeJunk.php（在【原始 PHP 源码 token 层】插入真实废代码）的区别：
 * 本类工作在【VM 可编程指令集层】——垃圾指令是 VM opcode 序列，进入分片加密的字节码
 * 容器，静态分析只能看到一串被打乱编号的操作码，看不到任何 PHP 语句，抗分析更强。
 *
 * 注入时机：在 RuntimeGenerator::buildArtifact 合并完所有段之后、collectUsed/remap/
 * 控制流平坦化之前插入——保证这些 opcode 被纳入"已用操作码集合"（分配随机编号并生成
 * handler），且能被随后的控制流平坦化/状态机混淆进一步打散。
 *
 * 安全性论证（为什么不会破坏程序语义）：
 *   - 只使用 PUSH_C / 二元运算 / 一元运算 / DROP，这些指令都不读写帧变量表 $vars，
 *     也不碰操作数栈之外的任何状态；
 *   - 每条垃圾"片段"以 PUSH_C 开头、以 DROP 结尾，且中间每个二元/一元运算都恰好消耗
 *     一个/两个操作数并压回一个，整体净栈变化恒为 0（见各模板注释），因此执行前后
 *     操作数栈完全一致；
 *   - 不含 JMP/JZ/JNZ（避免引入非法跳转目标破坏平坦化前的相对偏移），不含 RET/HALT/
 *     ECHO/EXIT/CALL 等终结/输出/调用指令，故插入到任何合法指令之前都安全可达、无害。
 */

declare(strict_types=1);

final class VmJunk
{
    /** 各垃圾模板：逻辑 opcode 序列（operands 直接内联为常量字面量）。
     *  约定：所有模板均栈平衡（净栈变化 = 0）。注释标注平衡推导。 */
    private const TEMPLATES = [
        // —— 二元运算：[a, b] -> 运算 -> 丢弃。结构：PUSH a, PUSH b, OP, DROP
        //   入栈 2，运算净 -1（2->1），DROP 净 -1 → 0。安全。
        ['bin', VmOp::ADD],
        ['bin', VmOp::SUB],
        ['bin', VmOp::MUL],
        ['bin', VmOp::BW_XOR],
        ['bin', VmOp::BW_AND],
        ['bin', VmOp::BW_OR],
        ['bin', VmOp::CONCAT],
        ['bin', VmOp::EQ],
        ['bin', VmOp::NE],
        ['bin', VmOp::LT],
        ['bin', VmOp::GT],
        ['bin', VmOp::LE],
        ['bin', VmOp::GE],
        ['bin', VmOp::IDENT],
        ['bin', VmOp::NIDENT],
        ['bin', VmOp::SPACESHIP],
        ['bin', VmOp::BOOL_AND],
        ['bin', VmOp::BOOL_OR],
        // —— 移位（除数/移位数固定 1..8，避免越界/无意义）——
        ['shl', VmOp::BW_SHL],
        ['shr', VmOp::BW_SHR],
        // —— 取模（除数固定 1..9，杜绝 %0 除零告警）——
        ['mod', VmOp::MOD],
        // —— 一元运算：[a] -> 运算 -> 丢弃。结构：PUSH a, OP, DROP。净 0。
        ['un', VmOp::NEG],
        ['un', VmOp::BW_NOT],
        ['un', VmOp::BOOL_NOT],
        ['un', VmOp::CAST_INT],
        ['un', VmOp::CAST_FLOAT],
        ['un', VmOp::CAST_STRING],
        ['un', VmOp::CAST_BOOL],
        ['un', VmOp::CAST_ARRAY],
        // —— 链式（更长，熵更高）：[a,b,c] -> (a OP b) OP2 c -> 丢弃（3 入，2 运算，1 drop）
        ['chain', VmOp::ADD, VmOp::MUL],
        ['chain', VmOp::MUL, VmOp::ADD],
        ['chain', VmOp::BW_XOR, VmOp::BW_OR],
        // —— 双丢弃：PUSH a, PUSH b, DUP, DROP, DROP（栈：+2, dup +1, 2×drop -2 → +1? 需校验）——
        //   实际：PUSH a(+1) PUSH b(+1=2) DUP(+1=3) DROP(-1=2) DROP(-1=1) → 净 +1，不平衡！
        //   故双丢弃改为 PUSH a, DUP, DROP, DROP 也错（净 +1）。改为下面更安全的形式：
        ['dupdrop', VmOp::DUP],   // PUSH a, DUP, DROP, DROP => +1,+1,-1,-1 = 0 ✓（见 buildSnippet）
    ];

    private Salt $salt;

    public function __construct(Salt $salt)
    {
        $this->salt = $salt;
    }

    /**
     * 向 Program 注入 $count 条垃圾"片段"（每条片段由多字节码指令组成）。
     * 注入位置在主段 / 各函数体 / 各方法体内确定性选择，保留可达且无害。
     *
     * @param array<string,mixed> $program
     * @return int 实际注入的垃圾指令条数（含片段内的每条 opcode）
     */
    /** 段是否为生成器段（段首 GEN_HEAD）：此类段不可插指令，标记须恒在下标 0 */
    private static function isGenSeg(array $seg): bool
    {
        return isset($seg[0]) && is_array($seg[0]) && ($seg[0][0] ?? null) === VmOp::GEN_HEAD;
    }

    public function inject(array &$program, int $count): int
    {
        $count = max(0, $count);
        if ($count <= 0) {
            return 0;
        }
        // 收集所有可执行段（引用路径，便于原地插入）
        $paths = [];
        if (!empty($program['main']) && is_array($program['main'])) {
            $paths[] = ['main'];
        }
        foreach (($program['funcs'] ?? []) as $name => $seg) {
            // 生成器段（段首 GEN_HEAD）不插垃圾：标记必须恒定位于下标 0，运行期入口靠它识别。
            if (is_array($seg) && $seg !== [] && !self::isGenSeg($seg)) {
                $paths[] = ['funcs', $name];
            }
        }
        foreach (($program['classes'] ?? []) as $cname => $cls) {
            foreach (($cls['methods'] ?? []) as $mname => $seg) {
                // 同上：生成器段豁免
                if (is_array($seg) && $seg !== [] && !self::isGenSeg($seg)) {
                    $paths[] = ['classes', $cname, 'methods', $mname];
                }
            }
        }
        if ($paths === []) {
            return 0;
        }
        // 打乱段顺序（salt 确定性），使垃圾分散到不同段
        $paths = $this->salt->shuffle($paths, 'vmjunk-paths');
        // 每段可插入上限：与段长成正比，避免单段被塞爆
        $cap = [];
        foreach ($paths as $i => $path) {
            $seg = $this->deref($program, $path);
            $len = is_array($seg) ? count($seg) : 0;
            $cap[$i] = max(1, (int) ceil($len / 4));
        }

        $inserted = 0;
        $totalIns = 0;
        $pi = 0;
        $usedOnPath = array_fill(0, count($paths), 0);
        for ($k = 0; $k < $count; $k++) {
            // 找到还有余量的段（循环轮转）
            $tries = 0;
            while ($usedOnPath[$pi] >= $cap[$pi]) {
                $pi = ($pi + 1) % count($paths);
                if (++$tries > count($paths)) {
                    break 2;   // 所有段已达上限，停止
                }
            }
            $path = $paths[$pi];
            $seg = $this->deref($program, $path);
            $len = is_array($seg) ? count($seg) : 0;
            if ($len < 1) {
                $pi = ($pi + 1) % count($paths);
                continue;
            }
            // 插入点：[0, len-1]（在最后一条指令之前，保证可达且执行后再落到后续真实指令 /
            // 段尾终结符）。段很短时退化为 0。
            $at = $len > 1 ? $this->salt->deriveInt('vmjunk-at-' . $k, 0, $len - 1) : 0;
            $snippet = $this->buildSnippet($k);
            $this->spliceSeg($program, $path, $at, $snippet);
            $inserted += count($snippet);
            $usedOnPath[$pi]++;
            $pi = ($pi + 1) % count($paths);
        }
        $totalIns = $inserted;
        return $totalIns;
    }

    /** 依 salt 确定性生成第 $k 条垃圾片段（逻辑 opcode 序列） */
    private function buildSnippet(int $k): array
    {
        $idx = $this->salt->deriveInt('vmjunk-tpl-' . $k, 0, count(self::TEMPLATES) - 1);
        $tpl = self::TEMPLATES[$idx];
        $kind = $tpl[0];
        switch ($kind) {
            case 'bin':
                $a = $this->constInt($k, 1);
                $b = $this->constVal($k, 2, $tpl[1]);
                return [
                    [VmOp::PUSH_C, $a],
                    [VmOp::PUSH_C, $b],
                    [$tpl[1]],
                    [VmOp::DROP],
                ];
            case 'mod':
                $a = $this->constInt($k, 1);
                $b = $this->salt->deriveInt('vmjunk-b-' . $k, 1, 9);   // 除数 1..9，杜绝 %0
                return [[VmOp::PUSH_C, $a], [VmOp::PUSH_C, $b], [$tpl[1]], [VmOp::DROP]];
            case 'shl':
            case 'shr':
                $a = $this->constInt($k, 1);
                $b = $this->salt->deriveInt('vmjunk-s-' . $k, 0, 8);    // 移位数 0..8
                return [[VmOp::PUSH_C, $a], [VmOp::PUSH_C, $b], [$tpl[1]], [VmOp::DROP]];
            case 'un':
                $a = $this->constInt($k, 1);
                return [[VmOp::PUSH_C, $a], [$tpl[1]], [VmOp::DROP]];
            case 'chain':
                $a = $this->constInt($k, 1);
                $b = $this->constInt($k, 2);
                $c = $this->constInt($k, 3);
                return [
                    [VmOp::PUSH_C, $a],
                    [VmOp::PUSH_C, $b],
                    [$tpl[1]],          // a OP b
                    [VmOp::PUSH_C, $c],
                    [$tpl[2]],           // (a OP b) OP2 c
                    [VmOp::DROP],        // 净栈：3 - 2(两运算) - 1 = 0 ✓
                ];
            case 'dupdrop':
                $a = $this->constInt($k, 1);
                return [
                    [VmOp::PUSH_C, $a],  // +1
                    [VmOp::DUP],          // +1 -> 2
                    [VmOp::DROP],         // -1 -> 1
                    [VmOp::DROP],         // -1 -> 0 ✓
                ];
            default:
                // 兜底：纯栈平衡占位（两值相与后丢弃）
                return [[VmOp::PUSH_C, 0], [VmOp::PUSH_C, 1], [VmOp::BW_AND], [VmOp::DROP]];
        }
    }

    /** 常数：整数用 salt 派生；CONCAT 用字符串常量（base64 字符集，绝不破坏序列化） */
    private function constVal(int $k, int $seq, int $op): mixed
    {
        if ($op === VmOp::CONCAT) {
            return base64_encode($this->salt->derive('vmjunk-s-' . $k . '-' . $seq, 6));
        }
        return $this->constInt($k, $seq);
    }

    private function constInt(int $k, int $seq): int
    {
        return $this->salt->deriveInt('vmjunk-c-' . $k . '-' . $seq, 1, 255);
    }

    /** 按路径取段（引用） */
    private function &deref(array &$program, array $path): mixed
    {
        $seg = &$program;
        foreach ($path as $p) {
            $seg = &$seg[$p];
        }
        return $seg;
    }

    /**
     * 在段内 $at 处插入 $snippet（原地修改），并修正受影响的相对跳转偏移。
     *
     * 关键：相对跳转偏移 d 是"相对指令自身下标"的，绝对目标 = (源下标 + 1) + d
     * （取指后 pc 已 +1）。插入点 p 会同时平移"位于 p 之后的指令"，因此源下标与目标
     * 下标各自独立地随 p 平移 len：
     *     新源 = 旧源 + (旧源 >= p ? len : 0)
     *     新目标 = 旧目标 + (旧目标 >= p ? len : 0)
     *     新偏移 = 新目标 - (新源 + 1)
     * 必须在 array_splice 之前快照旧跳转位置（用旧下标算绝对目标），再于 splice 之后
     * 用新下标回写偏移——绝不能拿"已平移的新下标"去套"未平移的旧偏移"算目标（旧实现
     * 的 bug 根源：t = j(+len) + 1 + d 混用了新源下标与旧偏移，导致源/目标平移不一致时
     * 偏移错算，最终 flatten/线性执行错位、死循环）。
     */
    private function spliceSeg(array &$program, array $path, int $at, array $snippet): void
    {
        $seg = &$this->deref($program, $path);
        if (!is_array($seg)) {
            return;
        }
        $len = count($snippet);
        // 1) splice 之前快照所有跳转指令：旧下标 => 旧绝对目标下标
        $jumps = [];
        foreach ($seg as $j => $ins) {
            if (!is_array($ins) || !isset($ins[0])) {
                continue;
            }
            $op = $ins[0];
            if ($op !== VmOp::JMP && $op !== VmOp::JZ && $op !== VmOp::JNZ) {
                continue;
            }
            $d = (int) ($ins[1] ?? 0);
            $jumps[$j] = $j + 1 + $d;   // 旧绝对目标下标
        }
        // 2) 真实插入指令
        array_splice($seg, $at, 0, $snippet);
        // 3) 依"旧源/旧目标各自是否越过插入点"重算每处偏移并回写。
        //    jNew 即 splice 后该跳转指令的新下标，必定仍是一条跳转指令。
        $jumpOps = [VmOp::JMP, VmOp::JZ, VmOp::JNZ];
        foreach ($jumps as $jOld => $tgtOld) {
            $jNew = $jOld + ($jOld >= $at ? $len : 0);
            $tgtNew = $tgtOld + ($tgtOld >= $at ? $len : 0);
            $newOffset = $tgtNew - ($jNew + 1);
            if (isset($seg[$jNew]) && is_array($seg[$jNew]) && in_array($seg[$jNew][0] ?? null, $jumpOps, true)) {
                $seg[$jNew][1] = $newOffset;
            }
        }
        // 4) 异常表同步：每条 ET 表项 [tryStart, tryEnd, handlerPc, ...] 的三个 pc 边界与
        //    段指令下标一一对应，插入片段后须按同一规则整体平移（pc >= at 则 +len）。
        //    漏做会使 try 保护区错位——抛出的异常路由不到 catch/finally（表现为
        //    "Uncaught Exception" / catch 返回错误值），且随盐值随机分布间歇性复现。
        //    插入点落在 try 区间内部时 start 不动、end 右移，区间扩为含垃圾片段：
        //    垃圾片段栈平衡无副作用、不抛异常，保护区语义不受影响。
        $etPath = $this->etPathOf($path);
        if ($etPath !== null) {
            $et = &$this->deref($program, $etPath);
            // deref 用 "=& $seg[$k]" 逐级取引用：该段当前没有异常表时会【隐式建出
            // null 键】（funcET[fn] = null），后续 bWriteExTab 收到 null 即抛
            // TypeError → 整个 VM 层静默回退。这里先把 null 归一为空表。
            if ($et === null) { $et = []; }
            if (is_array($et)) {
                foreach ($et as &$entry) {
                    if (!is_array($entry) || count($entry) < 3) {
                        continue;
                    }
                    for ($i = 0; $i < 3; $i++) {
                        if (is_int($entry[$i]) && $entry[$i] >= $at) {
                            $entry[$i] += $len;
                        }
                    }
                }
                unset($entry);
            }
        }
    }

    /**
     * 段路径 → 对应异常表路径（段与异常表平行存储，键结构同构）。
     * ['main'] → ['mainET']；['funcs',$n] → ['funcET',$n]；
     * ['classes',$c,'methods',$m] → ['classET',$c,$m]。其余（不存在 ET 的路径）返回 null。
     * @param array<int,string|int> $path
     * @return array<int,string|int>|null
     */
    private function etPathOf(array $path): ?array
    {
        if ($path === ['main']) {
            return ['mainET'];
        }
        if (count($path) === 2 && $path[0] === 'funcs') {
            return ['funcET', $path[1]];
        }
        if (count($path) === 4 && $path[0] === 'classes' && $path[2] === 'methods') {
            return ['classET', $path[1], $path[3]];
        }
        return null;
    }
}
