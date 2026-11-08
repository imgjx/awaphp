<?php
/**
 * VM 指令集动态生成（Dynamic ISA Planner）
 *
 * 背景：最早 ISA 定义了 84 条 opcode，其中二元/比较/逻辑（23 条）、一元（3 条）、
 * 转型（6 条）、自增自减（8 条）、isset/empty/unset（3 条）、数组构造（2 条）
 * 共 46 条「同构」指令各自拥有独立 handler，等于给逆向者贴了语义标签。
 * v1.1.x 起把它们合并为 6 个「参数化 opcode」，但那份实现仍是**静态**的：
 *   - 6 个参数化 opcode 的逻辑编号写死为 200–205；
 *   - 族内 sub-id 是族列表里的固定下标（ADD 恒为 0、SUB 恒为 1 ……）；
 *   - 分族方案、成员数量、case 顺序全部固定。
 * 于是只要识别出某个 handler 是「BINOP 族」，就能查表还原全部语义——ISA 精简
 * 只压缩了 handler 数量，没消除「ISA 结构本身」这个跨产物固定指纹。
 *
 * v1.2.0：改为**按 salt 逐构建动态生成 ISA 计划**，三个维度全部随机化：
 *   1. 参数化 opcode 的逻辑编号：从 [300,399] 洗牌取 6 个唯一值（避开全部 VmOp
 *      常量 1..205，故绝不与真实 opcode 撞车）；注意落盘的是 remap 后的**物理码**
 *      (1..250)，逻辑编号只存在于构建期的 mapping 键与运行期 case 标签里。
 *   2. 族内 sub-id：把 46 个旧 opcode 打乱后，从 [1,4095] 洗牌池分配**全局唯一且
 *      非连续**的随机值。sub 值不再暴露族大小 / 边界 / 语义顺序。
 *   3. 每族成员顺序按 salt 洗牌 → 运行期 switch 的 case 排列也逐构建不同。
 *
 * 关键性质（与静态版一致，必须严格保持）：
 *   - 重写只改变指令【内容】（[ADD] -> [参数化码, subId]），不改变【指令条数】，
 *     因此放在控制流平坦化（flatten）之后也绝不会破坏相对跳转偏移。
 *   - 编译端（rewriteProgram）与运行期分发表（RuntimeGenerator::paramFamilyBody）
 *     共用**同一个计划实例**，不依赖任何跨产物共享的静态表；同 salt 完全可复现。
 *   - 指令条数 / 操作数布局不变，容器仍是 varint 编码，无需改序列化协议。
 *
 * 修复（v1.2.0 漏洞排查）：静态版 rewriteProgram 只覆盖 main/funcs/classes.methods/
 * embeds，遗漏了 funcParams / classParams / classes.props / classes.consts。这些段里的
 * 常量表达式（如 `function f($a = 1 + 2)`、`const K = 5 + 6`）因此以**旧 opcode 明文**
 * 落盘，绕过了 ISA 收口，给静态分析留了语义明确的入口。现与 collectUsed / remap
 * 覆盖完全相同的段集合。
 */

declare(strict_types=1);

final class VmIsa
{
    // —— 参数化 opcode 的「子操作规范族」（仅作定义，实际 sub-id 与顺序无关）——
    /** 二元 / 比较 / 逻辑运算（栈：弹 b、弹 a，压 a OP b） */
    public const BIN = [
        VmOp::ADD, VmOp::SUB, VmOp::MUL, VmOp::DIV, VmOp::MOD, VmOp::POW, VmOp::CONCAT,
        VmOp::BW_AND, VmOp::BW_OR, VmOp::BW_XOR, VmOp::BW_SHL, VmOp::BW_SHR,
        VmOp::EQ, VmOp::NE, VmOp::LT, VmOp::GT, VmOp::LE, VmOp::GE,
        VmOp::IDENT, VmOp::NIDENT, VmOp::SPACESHIP, VmOp::BOOL_AND, VmOp::BOOL_OR, VmOp::BOOL_XOR,
    ];
    /** 一元运算（栈：弹 v，压 OP v） */
    public const UN = [VmOp::NEG, VmOp::BW_NOT, VmOp::BOOL_NOT];
    /** 类型转型（栈：弹 v，压 (type)v） */
    public const CAST = [
        VmOp::CAST_INT, VmOp::CAST_FLOAT, VmOp::CAST_STRING,
        VmOp::CAST_BOOL, VmOp::CAST_ARRAY, VmOp::CAST_OBJECT,
    ];
    /**
     * 属性 / 下标的自增自减（操作数：sub, name）。
     * 变量的 PRE_INC/PRE_DEC/POST_INC/POST_DEC（原 80–83）已精简移除，
     * 编译器改用基础栈指令复合序列（见 VmIncTrait::emitIncDec），故本族仅含
     * 属性 / 下标 8 条；运行期各 case 的语义由 paramFamilyBody 的 arms 表按旧 opcode 给出。
     */
    public const INC = [
        VmOp::PROP_POST_INC, VmOp::PROP_POST_DEC, VmOp::PROP_PRE_INC, VmOp::PROP_PRE_DEC,
        VmOp::IDX_POST_INC,  VmOp::IDX_POST_DEC,  VmOp::IDX_PRE_INC,  VmOp::IDX_PRE_DEC];
    /** isset / empty / unset（操作数：name, nkeys） */
    public const VAR = [VmOp::ISSET_, VmOp::EMPTY_, VmOp::UNSET_];
    /** 数组构造（操作数：n）。ARRAY_MAKE=索引数组，ARRAY_KV=关联数组 */
    public const ARR = [VmOp::ARRAY_MAKE, VmOp::ARRAY_KV];

    /** 族 key => 规范成员列表（顺序即静态默认计划的 sub-id 下标） */
    public const FAMILIES = [
        'bin'  => self::BIN,
        'un'   => self::UN,
        'cast' => self::CAST,
        'inc'  => self::INC,
        'var'  => self::VAR,
        'arr'  => self::ARR,
    ];

    /** 兼容旧引用：静态默认计划的「新 opcode -> 族」映射（VmOp 200–205） */
    public const FAMILY_OF = [
        VmOp::BINOP        => self::BIN,
        VmOp::UNOP         => self::UN,
        VmOp::CAST_OP      => self::CAST,
        VmOp::INC_OP       => self::INC,
        VmOp::VAROP        => self::VAR,
        VmOp::ARRAY_BUILD  => self::ARR,
    ];

    private ?Salt $salt;
    /** @var array<string,int> familyKey => 本次构建的逻辑 opcode 编号 */
    private array $paramOps = [];
    /** @var array<int,string> 逻辑 opcode 编号 => familyKey */
    private array $familyOf = [];
    /** @var array<int,int> 旧 opcode => 本次构建的 sub-id */
    private array $subIds = [];
    /** @var array<string,array<int,int>> familyKey => 洗牌后的成员列表 */
    private array $members = [];
    /** @var array<int,array{0:int,1:int}> 旧 opcode => [参数化逻辑码, sub-id] */
    private array $subCache = [];

    /**
     * @param Salt|null $salt 传 null 得到「静态默认计划」（编号 200–205、sub-id 为族内
     *                        下标），仅供文档对照 / 无盐调试；真实构建一律传 salt。
     */
    public function __construct(?Salt $salt = null)
    {
        $this->salt = $salt;
        $this->buildPlan();
    }

    /** 静态默认计划（等价于旧版行为，供测试与参考运行时对照） */
    public static function defaultPlan(): self
    {
        return new self(null);
    }

    private function buildPlan(): void
    {
        $keys = array_keys(self::FAMILIES);

        // —— 维度 1：参数化 opcode 的逻辑编号 ——
        // 动态计划从 [300,399] 洗牌取 6 个唯一值；该区间不与任何 VmOp 常量(≤205)重叠，
        // 故 collectUsed/assignCodes/caseBody 三方不会把「真实 opcode」与「参数化码」混淆。
        $numPool = $this->salt !== null
            ? $this->salt->shuffle(range(300, 399), 'vm-isa-paramops')
            : range(200, 205);
        foreach ($keys as $i => $key) {
            $op = $numPool[$i];
            $this->paramOps[$key] = $op;
            $this->familyOf[$op] = $key;
        }

        // —— 维度 3：每族成员顺序洗牌 ——
        $allOps = [];
        foreach (self::FAMILIES as $key => $fam) {
            $members = $this->salt !== null
                ? $this->salt->shuffle($fam, 'vm-isa-members-' . $key)
                : $fam;
            $this->members[$key] = $members;
            foreach ($members as $op) {
                $allOps[] = $op;
            }
        }

        // —— 维度 2：sub-id ——
        if ($this->salt === null) {
            // 静态默认：族内固定下标（与 v1.1.x 逐字节等价）
            foreach (self::FAMILIES as $key => $fam) {
                foreach ($fam as $i => $op) {
                    $this->subIds[$op] = $i;
                }
            }
        } else {
            // 动态：全族 46 个 opcode 打乱后，从 [1,4095] 洗牌池分配全局唯一、非连续的值。
            // 全局唯一（而非族内唯一）让「sub 值」无法反推族边界；非连续让 0/1/2 这类
            // 小整数不再暗示「常见子操作」。varint 编码对 4095 无任何压力。
            $subPool = $this->salt->shuffle(range(1, 4095), 'vm-isa-subids');
            $shuffledOps = $this->salt->shuffle($allOps, 'vm-isa-subassign');
            foreach ($shuffledOps as $i => $op) {
                $this->subIds[$op] = $subPool[$i];
            }
        }

        foreach (self::FAMILIES as $key => $fam) {
            foreach ($fam as $op) {
                $this->subCache[$op] = [$this->paramOps[$key], $this->subIds[$op]];
            }
        }
    }

    /** 族 key 对应的本次构建逻辑 opcode 编号 */
    public function paramOp(string $key): int
    {
        return $this->paramOps[$key] ?? 0;
    }

    /** 逻辑 opcode 编号是否属于本计划的参数化码；是则返回族 key */
    public function familyOf(int $logical): ?string
    {
        return $this->familyOf[$logical] ?? null;
    }

    /** 旧 opcode 的本次构建 sub-id；非族内 opcode 返回 null */
    public function subId(int $op): ?int
    {
        return $this->subIds[$op] ?? null;
    }

    /** 族成员（已按 salt 洗牌）——运行期据此生成 case 排列 */
    public function members(string $key): array
    {
        return $this->members[$key] ?? [];
    }

    public function isDynamic(): bool
    {
        return $this->salt !== null;
    }

    /** 供回归测试检视计划（不影响产物） */
    public function describe(): array
    {
        return [
            'paramOps' => $this->paramOps,
            'subIds'   => $this->subIds,
            'members'  => $this->members,
        ];
    }

    /**
     * 重写单条指令：把族内旧 opcode 升级为参数化 opcode + sub-id。
     * 规则：新指令 = [参数化逻辑码, sub-id, ...原操作数]，指令条数不变。
     * 幂等：已参数化的指令（逻辑码不在 subCache 里）不会再被转换。
     */
    private function rewriteInstr(array $ins): array
    {
        if ($ins === [] || !isset($ins[0]) || !is_int($ins[0])) {
            return $ins;
        }
        if (!isset($this->subCache[$ins[0]])) {
            return $ins;
        }
        [$newOp, $sub] = $this->subCache[$ins[0]];
        return array_merge([$newOp, $sub], array_slice($ins, 1));
    }

    private function rewriteSeg(array &$seg): void
    {
        foreach ($seg as &$ins) {
            if (is_array($ins)) {
                $ins = $this->rewriteInstr($ins);
            }
        }
        unset($ins);
    }

    /**
     * 重写整个 VM Program：必须与 RuntimeGenerator::collectUsed()/remap() 覆盖
     * **完全相同**的段集合，否则遗漏段里的旧 opcode 会绕过 ISA 收口（v1.2.0 修复）。
     *
     * 在 RuntimeGenerator 中于 flatten + fakeFog 之后、collectUsed 之前调用，
     * 可一并收口「真实码 + VmBytecodeJunk + VmFakeFog」三类来源的所有族内 opcode。
     *
     * @param array<string,mixed> $program
     */
    public function rewriteProgram(array &$program): void
    {
        if (isset($program['main']) && is_array($program['main'])) {
            $this->rewriteSeg($program['main']);
        }
        foreach (['funcs', 'embeds'] as $key) {
            if (!empty($program[$key]) && is_array($program[$key])) {
                foreach ($program[$key] as &$seg) {
                    if (is_array($seg)) {
                        $this->rewriteSeg($seg);
                    }
                }
                unset($seg);
            }
        }
        // 函数/方法参数默认值段：每个参数是 [name, seg|null, flags]
        if (!empty($program['funcParams']) && is_array($program['funcParams'])) {
            foreach ($program['funcParams'] as &$spec) {
                foreach ($spec as &$p) {
                    if (is_array($p[1] ?? null)) {
                        $this->rewriteSeg($p[1]);
                    }
                }
                unset($p);
            }
            unset($spec);
        }
        // 类方法参数默认值段：classes[cls][method] => [ [name, seg|null, flags], ... ]
        if (!empty($program['classParams']) && is_array($program['classParams'])) {
            foreach ($program['classParams'] as &$specs) {
                foreach ($specs as &$spec) {
                    foreach ($spec as &$p) {
                        if (is_array($p[1] ?? null)) {
                            $this->rewriteSeg($p[1]);
                        }
                    }
                    unset($p);
                }
                unset($spec);
            }
            unset($specs);
        }
        if (!empty($program['classes']) && is_array($program['classes'])) {
            foreach ($program['classes'] as &$cls) {
                // methods / props / consts 三处都可能含可执行的常量表达式段
                foreach (['methods', 'props', 'consts'] as $key) {
                    if (!empty($cls[$key]) && is_array($cls[$key])) {
                        foreach ($cls[$key] as &$seg) {
                            if (is_array($seg)) {
                                $this->rewriteSeg($seg);
                            }
                        }
                        unset($seg);
                    }
                }
            }
            unset($cls);
        }
    }
}
