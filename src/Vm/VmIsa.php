<?php
/**
 * VM 指令集精简辅助（ISA compaction helper）
 *
 * 背景：原 ISA 定义了 84 条 opcode，其中二元/比较/逻辑（23 条）、一元（3 条）、
 * 转型（6 条）、自增自减（4 条）、isset/empty/unset（3 条）、数组构造（2 条）
 * 共 41 条「同构」指令各自拥有独立 handler，等于给逆向者贴了语义标签。
 *
 * 方案：把上述 41 条合并为 6 个「参数化 opcode」（BINOP / UNOP / CAST_OP /
 * INC_OP / VAROP / ARRAY_BUILD）。每条参数化指令携带一个 sub-id 指明具体子操作；
 * 旧 VmOp 常量保留为「内部 sub-id」，runtime 端按 sub-id 走族内表达式表做表驱动分发。
 *
 * 关键性质：
 *   - 重写只改变指令【内容】（[ADD] -> [BINOP, subId]），不改变【指令条数】，
 *     因此放在控制流平坦化（flatten）之后也绝不会破坏相对跳转偏移。
 *   - sub-id 是族内的固定下标（确定性，不依赖 salt），编译器发射端与 runtime
 *     解码端通过同一份 VmIsa 族列表保持对齐，无需共享 salt。
 */

declare(strict_types=1);

final class VmIsa
{
    // —— 参数化 opcode 的「子操作规范族」（顺序固定；sub-id = 族内下标）——
    /** 二元 / 比较 / 逻辑运算（栈：弹 b、弹 a，压 a OP b） */
    public const BIN = [
        VmOp::ADD, VmOp::SUB, VmOp::MUL, VmOp::DIV, VmOp::MOD, VmOp::POW, VmOp::CONCAT,
        VmOp::BW_AND, VmOp::BW_OR, VmOp::BW_XOR, VmOp::BW_SHL, VmOp::BW_SHR,
        VmOp::EQ, VmOp::NE, VmOp::LT, VmOp::GT, VmOp::LE, VmOp::GE,
        VmOp::IDENT, VmOp::NIDENT, VmOp::SPACESHIP, VmOp::BOOL_AND, VmOp::BOOL_OR,
    ];
    /** 一元运算（栈：弹 v，压 OP v） */
    public const UN = [VmOp::NEG, VmOp::BW_NOT, VmOp::BOOL_NOT];
    /** 类型转型（栈：弹 v，压 (type)v） */
    public const CAST = [
        VmOp::CAST_INT, VmOp::CAST_FLOAT, VmOp::CAST_STRING,
        VmOp::CAST_BOOL, VmOp::CAST_ARRAY, VmOp::CAST_OBJECT,
    ];
    /** 自增 / 自减（操作数：name）。PRE_INC 压新值；POST_INC 压旧值。 */
    public const INC = [VmOp::PRE_INC, VmOp::PRE_DEC, VmOp::POST_INC, VmOp::POST_DEC];
    /** isset / empty / unset（操作数：name, nkeys） */
    public const VAR = [VmOp::ISSET_, VmOp::EMPTY_, VmOp::UNSET_];
    /** 数组构造（操作数：n）。0=ARRAY_MAKE（索引数组），1=ARRAY_KV（关联数组） */
    public const ARR = [VmOp::ARRAY_MAKE, VmOp::ARRAY_KV];

    /** 旧 opcode -> [新 opcode, sub-id] 的懒缓存 */
    private static array $subCache = [];

    /** 参数化 opcode 对应族的反查（新 opcode -> 族数组） */
    public const FAMILY_OF = [
        VmOp::BINOP        => self::BIN,
        VmOp::UNOP         => self::UN,
        VmOp::CAST_OP      => self::CAST,
        VmOp::INC_OP       => self::INC,
        VmOp::VAROP        => self::VAR,
        VmOp::ARRAY_BUILD  => self::ARR,
    ];

    private static function ensureCache(): void
    {
        if (self::$subCache !== []) {
            return;
        }
        foreach (self::FAMILY_OF as $newOp => $fam) {
            foreach ($fam as $i => $oldOp) {
                self::$subCache[$oldOp] = [$newOp, $i];
            }
        }
    }

    /** 取某旧 opcode 的 sub-id；非族内 opcode 返回 null */
    public static function subId(int $op): ?int
    {
        self::ensureCache();
        return isset(self::$subCache[$op]) ? self::$subCache[$op][1] : null;
    }

    /**
     * 重写单条指令：把族内旧 opcode 升级为参数化 opcode + sub-id。
     * 规则：新指令 = [新 opcode, sub-id, ...原操作数]，指令条数不变。
     * 幂等：已参数化的指令不会再被转换（新 opcode 不在族内）。
     */
    public static function rewriteInstr(array $ins): array
    {
        if ($ins === [] || !isset($ins[0]) || !is_int($ins[0])) {
            return $ins;
        }
        self::ensureCache();
        $op = $ins[0];
        if (!isset(self::$subCache[$op])) {
            return $ins;
        }
        [$newOp, $sub] = self::$subCache[$op];
        return array_merge([$newOp, $sub], array_slice($ins, 1));
    }

    /** 重写整段指令序列（原地） */
    private static function rewriteSeg(array &$seg): void
    {
        foreach ($seg as &$ins) {
            if (is_array($ins)) {
                $ins = self::rewriteInstr($ins);
            }
        }
        unset($ins);
    }

    /**
     * 重写整个 VM Program：覆盖主段 / 函数体 / 类方法 / 内嵌段。
     * 在 RuntimeGenerator 中于 flatten + fakeFog 之后、collectUsed 之前调用，
     * 可一并收口「真实码 + VmJunk + VmFakeFog」三类来源的所有族内 opcode。
     *
     * @param array<string,mixed> $program
     */
    public static function rewriteProgram(array &$program): void
    {
        if (isset($program['main']) && is_array($program['main'])) {
            self::rewriteSeg($program['main']);
        }
        if (!empty($program['funcs']) && is_array($program['funcs'])) {
            foreach ($program['funcs'] as &$seg) {
                if (is_array($seg)) {
                    self::rewriteSeg($seg);
                }
            }
            unset($seg);
        }
        if (!empty($program['classes']) && is_array($program['classes'])) {
            foreach ($program['classes'] as &$cls) {
                if (!empty($cls['methods']) && is_array($cls['methods'])) {
                    foreach ($cls['methods'] as &$seg) {
                        if (is_array($seg)) {
                            self::rewriteSeg($seg);
                        }
                    }
                    unset($seg);
                }
            }
            unset($cls);
        }
        if (!empty($program['embeds']) && is_array($program['embeds'])) {
            foreach ($program['embeds'] as &$seg) {
                if (is_array($seg)) {
                    self::rewriteSeg($seg);
                }
            }
            unset($seg);
        }
    }
}
