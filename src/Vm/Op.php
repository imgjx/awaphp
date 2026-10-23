<?php
/**
 * 自定义虚拟机操作码（VM Opcode Set）
 *
 * 这是一套与 PHP 语义无关的"自定义指令集"。用户源码在构建期被编译为这些 opcode
 * 组成的字节码，运行期由 src/Vm/runtime.php 里的解释器逐条取指执行。
 *
 * 关键性质（与旧版 VM 壳的区别）：
 *   - 旧版 VM 壳只是"把 PHP 语句 XOR 后逐条 eval"，解出来直接就是 PHP，等于没壳；
 *   - 本版字节码是真正的自定义 ISA（栈式机 + 自定义 opcode 编号），
 *     静态分析拿到的只是一串 opcode 序号与常量池，看不到任何 PHP 语句。
 *
 * 栈约定：所有运算数从操作数栈弹出 / 结果压回操作数栈。
 * 变量：以名字（字符串）为单位存于当前帧的局部变量表，跨语句共享。
 */

declare(strict_types=1);

final class VmOp
{
    // ---- 数据搬运 ----
    public const PUSH_C    = 1;   // 操作数: constIdx  -> 压入常量池 constIdx
    public const PUSH_V    = 2;   // 操作数: name      -> 压入局部变量 name 的值
    public const POP_V     = 3;   // 操作数: name      -> 弹出栈顶写入局部变量 name
    public const DUP       = 4;   // 复制栈顶
    public const DROP      = 5;   // 丢弃栈顶
    public const LOAD_THIS = 6;   // 压入当前 $this（方法上下文中才有效）
    public const PUSH_NULL = 7;   // 压入 null

    // ---- 算术 / 字符串 ----
    public const ADD     = 10;    // +
    public const SUB     = 11;    // -
    public const MUL     = 12;    // *
    public const DIV     = 13;    // /
    public const MOD     = 14;    // %
    public const POW     = 15;    // **
    public const CONCAT  = 16;    // .
    public const NEG     = 17;    // 一元 -
    public const BW_NOT  = 18;    // ~

    // ---- 位运算 ----
    public const BW_AND = 20;     // &
    public const BW_OR  = 21;     // |
    public const BW_XOR = 22;     // ^
    public const BW_SHL = 23;     // <<
    public const BW_SHR = 24;     // >>

    // ---- 比较 ----
    public const EQ      = 30;    // ==
    public const NE      = 31;    // !=
    public const LT      = 32;    // <
    public const GT      = 33;    // >
    public const LE      = 34;    // <=
    public const GE      = 35;    // >=
    public const IDENT   = 36;    // ===
    public const NIDENT  = 37;    // !==
    public const SPACESHIP = 38;  // <=> 返回 -1/0/1

    // ---- 逻辑 ----
    public const BOOL_AND = 40;    // &&
    public const BOOL_OR  = 41;    // ||
    public const BOOL_NOT = 42;    // !
    public const BOOL_XOR = 43;    // xor（非短路，两侧均求值）

    // ---- 控制流 ----
    public const JMP  = 50;        // 操作数: offset（相对当前 pc 的有符号偏移）
    public const JZ   = 51;        // 弹出 v，若为假则 pc += offset
    public const JNZ  = 52;        // 弹出 v，若为真则 pc += offset
    public const CALL = 53;        // 操作数: name, argc  -> 弹出 argc 个参数，调用函数
    public const MECALL = 54;      // 操作数: argc        -> 栈[对象,参数...,方法名]：弹方法名、argc参数、对象
    public const NEW  = 55;        // 操作数: class, argc -> 弹出 argc 参数，构造对象
    public const PROPGET = 56;     // 操作数: prop -> 弹出对象，取属性
    public const PROPSET = 57;     // 操作数: prop -> 弹出值、弹出对象，写属性
    public const RET  = 58;        // 弹出返回值并返回当前帧
    public const RET_VOID = 59;    // 返回 null
    public const ECHO = 60;        // 弹出并输出
    public const HALT = 61;        // 终止整个程序

    // ---- 数组 / 容器 ----
    public const ARRAY_MAKE  = 70; // 操作数: argc   -> 弹出 argc 元素，构造索引数组
    public const ARRAY_KV    = 71; // 操作数: pairs  -> 弹出 2*pairs（值,键），构造关联数组
    public const INDEX_GET   = 72; // 弹出 key、弹出 arr，按下标取
    public const INDEX_SET   = 73; // 弹出 val、弹出 key、弹出 arr，写下标
    public const COUNT       = 74; // 弹出 arr，压入 count()
    public const ARRAY_PUSH  = 75; // 弹出 val、弹出 arr，执行 $arr[] = val（再压回 arr）

    // ---- 变量自增自减 ----
    public const PRE_INC  = 80;    // 操作数: name
    public const PRE_DEC  = 81;    // 操作数: name
    public const POST_INC = 82;    // 操作数: name -> 压入旧值后自增
    public const POST_DEC = 83;    // 操作数: name -> 压入旧值后自减
    public const CALL_VAR = 90;    // 操作数: argc -> 弹出 argc 参数 + 函数名（变量值），动态调用

    // ---- 静态成员 / 语言构造 ----
    public const SCALL   = 91;     // 操作数: class, method, argc -> 静态/类方法调用
    public const SPROP   = 92;     // 操作数: class, prop -> 类常量 / 静态属性
    public const ISSET_  = 93;     // 操作数: name, nkeys -> 栈上有 nkeys 个键，压入 isset() 结果
    public const EMPTY_  = 94;     // 操作数: name, nkeys -> 压入 empty() 结果
    public const UNSET_  = 95;     // 操作数: name, nkeys -> 删除变量或下标
    public const EXIT_   = 96;     // 弹出值并 exit（等价于 PHP 的 exit($v)）
    public const EXIT_VOID = 97;   // exit
    public const PRINT_  = 98;     // 弹出并输出，压回 1（print 语义）

    // ---- 间接执行（_PHPRUN 指令模块）----
    // 弹出 $argc 个参数（最后一个为待执行 PHP 源码串），经"间接 eval"执行。
    // 仅当源码出现 _PHPRUN() 时才会出现在已用操作码集合中 —— 否则本 handler 根本不生成。
    public const PHPRUN   = 99;    // 操作数: argc -> 弹出 argc 参数，间接 eval 最后一个

    // ---- 类型转型（(int)/(float)/(string)/(bool)/(array)/(object)）----
    public const CAST_INT    = 100; // 弹出栈顶，压入 (int) 转型结果
    public const CAST_FLOAT  = 101; // (float)/(double)/(real)
    public const CAST_STRING = 102; // (string)
    public const CAST_BOOL   = 103; // (bool)/(boolean)
    public const CAST_ARRAY  = 104; // (array)
    public const CAST_OBJECT = 105; // (object)

    // ---- 超全局变量桥接（$GLOBALS 直通真实 PHP 全局符号表）----
    // VM 帧变量表与真实 PHP 全局符号表是两套存储；$GLOBALS 的读写必须穿透到
    // 真实 $GLOBALS，否则函数/方法帧内读不到全局（如编码混淆层下发的密钥串）。
    public const PUSH_GLOBALS = 106; // 压入真实 $GLOBALS 数组（副本，仅供读取）
    public const GLOBALS_SET  = 107; // 弹出 val、key、容器副本，执行 $GLOBALS[$key] = val
    public const GLOBAL_REF   = 108; // 操作数: 变量名（含$）——把帧槽位绑定为共享全局帧的引用
    public const DEFINE       = 109; // 操作数: 常量名——define(name, 弹出值)
    public const CONST_FETCH  = 110; // 操作数: 常量名——运行期取常量（const 语句声明的用户常量）

    // ---- include / require（构建期内嵌 + 运行期真实包含两路）----
    // INCLUDE_EMBED：构建期已把目标文件编译进 embeds 表的路径走此路。
    //   操作数: embedId(i), once(i) —— 在当前帧变量表（共享，PHP 包含作用域语义）中
    //   执行嵌入段，压入被包含文件的 return 值；once=1 且已执行过则直接压 true。
    public const INCLUDE_EMBED = 111;
    // INCLUDE_RT：运行期才能确定路径（$var / 插值串 / 函数调用等）。
    //   操作数: flags(i)（bit0=once, bit1=require）—— 弹路径，运行期做真实
    //   include/require，经"帧变量表 ⇄ 真实局部变量"桥接保住作用域语义。
    public const INCLUDE_RT    = 112;

    // ---- 语句级回退 / 原生间接调用 ----
    // EVAL_STMT：编译器对【单条语句】的兜底——编译该语句时遇到不支持的结构，
    //   不整文件回退，而是把该语句的源码文本压栈并由本指令在运行期 eval 执行，
    //   经"帧变量表 ⇄ 真实局部变量"快照差分桥接保住作用域（与 INCLUDE_RT 同构）。
    //   操作数: 无（源码串弹栈）。
    public const EVAL_STMT   = 113;
    // CALL_NATIVE：原生函数间接调用。操作数: nativeIdx(i), argc(i), refMask(i)。
    //   函数名不进字节码常量池，而是索引构建期生成的"原生函数名表"（natTbl），
    //   静态分析看不到 strlen/array_map 等调用点；运行期查表后交还原生分发
    //   （保留高阶函数回调包装语义）。refMask 标记按引用传参的位置（sort/usort/
    //   array_push/preg_match 等），对应实参由 PUSH_REF 压入引用句柄。
    public const CALL_NATIVE = 114;
    // PUSH_REF：压入【引用句柄】（非值）——句柄为 ['__vmref__', 帧变量名]，
    //   仅供 CALL_NATIVE 的 refMask 位置消费：运行期把该句柄绑定为帧变量的
    //   真实引用后传给原生函数，从而支持 sort()/usort()/array_push() 等
    //   第一参 by-ref 的原生函数对 VM 帧变量的原地修改。
    public const PUSH_REF    = 115;  // 操作数: name -> 压入变量 name 的引用句柄
    // SCALL_VAR：动态方法名的静态调用 C::$m() / self::$m()。操作数: class, argc。
    //   栈形态：[方法名(字符串), 实参...]——方法名先压栈，实参按左→右。
    //   运行期弹出方法名后按 SCALL 同款逻辑分发（VM 方法表优先，原生类反射兜底）。
    public const SCALL_VAR   = 117;
    // INSTANCEOF：instanceof 类型判定。操作数: class(字符串常量)。
    //   运行期经 $__instanceof 分发：VM 类/接口走 META 属性 + classParents/
    //   classImpl 链遍历；原生类走真实 instanceof。动态类名（$x instanceof $c）
    //   暂不支持，由编译期抛 VmUnsupported 回退。
    public const INSTANCEOF  = 118;  // 操作数: class -> 弹栈顶对象，压入 bool

    // ---- 异常处理（try / catch / finally 真编译）----
    // THROW：弹栈顶对象并按原样抛出（throw $e 语义）。运行期由 __exec 的外层
    //   try/catch 包装接管：查找覆盖当前 pc 的异常表条目决定路由到 catch 或 finally。
    public const THROW          = 119; // 操作数: 无（异常对象弹栈）
    // EX_FINALLY_ENTER：finally 块起始哨兵——置位 $__inFinally 标志，使 finally
    //   内部再抛出的异常不再被本帧异常表捕获（直接穿透到外层/调用方）。
    public const EX_FINALLY_ENTER = 120; // 操作数: 无
    // EX_FINALLY_END：finally 块终止哨兵——根据 $__pending（待重抛异常）与 $__ctl
    //   （延后控制转移：1=带值返回 / 2=void 返回 / 3=break / 4=continue）决定收尾：
    //   有 pending 则重抛；否则按 ctl 执行返回/跳出/继续，无 ctl 则顺序落下。
    public const EX_FINALLY_END  = 121; // 操作数: 无

    // ---- 可变参数调用（解包实参 ...$arr，PHP 5.6+）----
    // ARG_SPREAD：弹栈顶数组并压入【解包标记】['__vmspread__', 数组]，由随后的
    //   CALL / MECALL / SCALL / CALL_NATIVE 在真正调用前展平为位置实参
    //   （整数键→位置实参；字符串键→命名实参，等价 PHP 语义）。
    //   这样可绕开 CALL 的 argc 为编译期常量、无法表达「实参个数运行期才确定」的限制。
    public const ARG_SPREAD = 122; // 操作数: 无（数组弹栈）

    // ---- 命名实参（name: expr，PHP 8.0+）----
    // ARG_NAMED：弹栈顶值并压入【命名标记】['__vmnamed__', 形参名, 值]，由随后的
    //   CALL / MECALL / SCALL 在真正调用前按被调方参数规格重排为位置实参。
    //   重排推迟到运行期：被调方签名（funcParams / classParams）运行期必然已知，
    //   即使 receiver 类型编译期不可知（$obj->m(b:1)）也能正确解析。
    public const ARG_NAMED = 123; // 操作数: name -> 栈顶值包装为命名标记

    // ---- 引用赋值（=&，PHP 引用别名语义）----
    // 帧变量表 $__vars 是普通 PHP 数组，天然支持引用别名：
    //   $b = &$a        ≡ $__vars['$b'] = &$__vars['$a']
    //   $b = &$a[$k]    ≡ $__vars['$b'] = &$__vars['$a'][$k]
    //   $b = &$this->p  ≡ $__vars['$b'] = &$this->p
    // 别名建立后，PUSH_V/POP_V/自增/复合赋值等全部经 PHP 原生引用语义穿透，
    // 与 PHP 的「两个变量名同一存储」完全一致。foreach ($a as &$v) 亦复用
    // 本族指令：先把源数组槽位别名为临时槽，再逐迭代把值变量别名为元素。
    public const MAKE_REF     = 124; // 操作数: dst, src -> $__vars[dst] = &$__vars[src]
    public const MAKE_REF_EL  = 125; // 操作数: dst, base -> 键弹栈，$__vars[dst] = &$__vars[base][key]
    public const MAKE_REF_PROP = 126; // 操作数: dst, prop -> 对象弹栈，$__vars[dst] = &$obj->prop
    public const MAKE_REF_APPEND = 127; // 操作数: dst, base -> $b = &$a[] 追加引用（等价引用下一整数键）

    // ---- 数组字面量引用元素（[&$a, "k"=>&$b]）----
    // PUSH_REF_EL：把帧槽位名压为【引用元素句柄】['__vmrefel__', 槽名]。
    //   句柄仅允许紧随 ARRAY_MAKE/ARRAY_KV 消费：运行期据此把对应元素构造为
    //   真实 PHP 数组元素引用（$arr[$i] = &$__vars[slot]），写穿目标存储。
    public const PUSH_REF_EL = 128; // 操作数: name -> 压引用元素句柄（数组 & 元素专用）

    // ---- 属性/数组元素的自增自减（INC_OP 族 sub 4-11，见 VmIsa::INC）----
    // 属性版（操作数 name=属性名）：PROPGET 已把 [obj, old] 留在栈上，
    //   PROP_POST_* 弹 [old, obj] 原地写穿属性，压回旧值（表达式结果=旧值）；
    //   PROP_PRE_*  压回新值（结果=新值）。
    // 下标版（无操作数）：INDEX_GET 已把 [arr, key, old] 留在栈上，
    //   IDX_POST_* 原地更新 arr[key]，压 [旧值, 更新后的容器]（容器在顶，
    //   语句级由调用方 POP_V 写回帧变量，表达式级 DROP 丢弃）；
    //   IDX_PRE_*  压 [新值, 更新后的容器]。
    public const PROP_POST_INC = 129;
    public const PROP_POST_DEC = 130;
    public const PROP_PRE_INC  = 131;
    public const PROP_PRE_DEC  = 132;
    public const IDX_POST_INC  = 133;
    public const IDX_POST_DEC  = 134;
    public const IDX_PRE_INC   = 135;
    public const IDX_PRE_DEC   = 136;

    // ---- 引用返回函数调用（function &g() 的 $r = &g()）----
    // PHP 语义：$r = &g() 把 $r 绑定到 g 内 return 的变量本身（非值拷贝），
    // 之后 $r = 9 改变的是 g 内的 $s。实现：CALL_RR 调用时向被调帧预置
    // 引用桥接槽 "\x01rr"，函数内 return $var 前发 MAKE_REF "\x01rr" 槽名
    // 把桥接槽绑到返回变量；调用返回后 CALL_RR 把桥接槽的引用绑到目标帧槽。
    // 操作数: name(函数名), dst(目标帧槽), argc
    public const CALL_RR      = 137;

    // 类静态属性不设专用 opcode：编译期把 C::$p 映射为共享帧槽位（ mangled 名
    // "\x01sp:Class:prop"），读写/自增/复合赋值全部复用 GLOBAL_REF + PUSH_V /
    // POP_V / INC_OP——GLOBAL_REF 把帧槽位绑为共享帧引用后，这些指令自然穿透，
    // 且天然获得「同一 __VMRUN__ 内跨调用保持」的静态属性语义（见 VmExprTrait）。

    // ---- 参数化指令（ISA 精简：合并同构族，sub-id 指明具体子操作；见 src/Vm/VmIsa.php）----
    // 旧 opcode 常量全部保留为「内部 sub-id」，此处仅新增 6 个能覆盖 41 条旧码的顶层码。
    public const BINOP        = 200; // 操作数: sub -> 二元/比较/逻辑运算（见 VmIsa::BIN）
    public const UNOP         = 201; // 操作数: sub -> 一元运算（见 VmIsa::UN）
    public const CAST_OP      = 202; // 操作数: sub -> 类型转型（见 VmIsa::CAST）
    public const INC_OP       = 203; // 操作数: sub, name -> 自增/自减（见 VmIsa::INC）
    public const VAROP        = 204; // 操作数: sub, name, nkeys -> isset/empty/unset（见 VmIsa::VAR）
    public const ARRAY_BUILD  = 205; // 操作数: sub, n -> ARRAY_MAKE / ARRAY_KV（见 VmIsa::ARR）

    // ---- 控制流平坦化 / 跳转表 ----
    public const JMP_B   = 62;     // 操作数: blockId -> 经跳转表做绝对跳转
    public const SET_ST  = 63;     // 操作数: state -> 写入当前帧状态寄存器
    public const DISPATCH = 64;    // 读取状态寄存器，经调度表跳到对应基本块（无匹配则结束）

    /**
     * 操作数规格表：opcode => 各操作数类型。
     * 类型：'i' 整数（变长 zigzag）/ 'n' 名字表索引 / 'c' 常量（带类型标记）
     *
     * 序列化器（src/Vm/Serializer.php）据此为每个操作数写类型标记，
     * 运行期解码器据此还原。新增 opcode 必须同步登记，否则会漏编码操作数。
     */
    public const OPERANDS = [
        self::PUSH_C    => ['c'],
        self::PUSH_V    => ['n'],
        self::POP_V     => ['n'],
        self::DUP       => [],
        self::DROP      => [],
        self::LOAD_THIS => [],
        self::PUSH_NULL => [],
        self::ADD       => [], self::SUB  => [], self::MUL  => [], self::DIV  => [],
        self::MOD       => [], self::POW  => [], self::CONCAT => [], self::NEG => [],
        self::BW_NOT    => [],
        self::BW_AND    => [], self::BW_OR => [], self::BW_XOR => [],
        self::BW_SHL    => [], self::BW_SHR => [],
        self::EQ        => [], self::NE    => [], self::LT    => [], self::GT  => [],
        self::LE        => [], self::GE    => [], self::IDENT => [], self::NIDENT => [],
        self::SPACESHIP => [],
        self::BOOL_AND  => [], self::BOOL_OR => [], self::BOOL_NOT => [], self::BOOL_XOR => [],
        self::JMP       => ['i'], self::JZ  => ['i'], self::JNZ => ['i'],
        self::CALL      => ['n', 'i'],
        self::MECALL    => ['i'],
        self::NEW       => ['n', 'i'],
        self::PROPGET   => ['n'], self::PROPSET => ['n'],
        self::RET       => [], self::RET_VOID => [], self::ECHO => [], self::HALT => [],
        self::ARRAY_MAKE => ['i'], self::ARRAY_KV => ['i'],
        self::INDEX_GET => [], self::INDEX_SET => [], self::COUNT => [], self::ARRAY_PUSH => [],
        self::PRE_INC   => ['n'], self::PRE_DEC => ['n'],
        self::POST_INC  => ['n'], self::POST_DEC => ['n'],
        self::CALL_VAR  => ['i'],
        self::JMP_B     => ['i'], self::SET_ST => ['i'], self::DISPATCH => [],
        self::SCALL     => ['n', 'n', 'i'],
        self::SPROP     => ['n', 'n'],
        self::ISSET_    => ['n', 'i'],
        self::EMPTY_    => ['n', 'i'],
        self::UNSET_    => ['n', 'i'],
        self::EXIT_     => [], self::EXIT_VOID => [], self::PRINT_ => [],
        self::PHPRUN    => ['i'],
        self::CAST_INT => [], self::CAST_FLOAT => [], self::CAST_STRING => [],
        self::CAST_BOOL => [], self::CAST_ARRAY => [], self::CAST_OBJECT => [],
        self::PUSH_GLOBALS => [], self::GLOBALS_SET => [],
        self::GLOBAL_REF   => ['n'],
        self::DEFINE       => ['n'],
        self::CONST_FETCH  => ['n'],
        self::INCLUDE_EMBED => ['i', 'i'],
        self::INCLUDE_RT    => ['i'],
        self::EVAL_STMT     => [],
        self::CALL_NATIVE   => ['i', 'i', 'i'],
        self::PUSH_REF      => ['n'],
        self::SCALL_VAR     => ['n', 'i'],
        self::INSTANCEOF    => ['n'],
        self::THROW         => [],
        self::EX_FINALLY_ENTER => [],
        self::EX_FINALLY_END => [],
        self::ARG_SPREAD => [],
        self::ARG_NAMED  => ['n'],
        self::MAKE_REF     => ['n', 'n'],
        self::MAKE_REF_EL  => ['n', 'n'],
        self::MAKE_REF_PROP => ['n', 's'],
        self::MAKE_REF_APPEND => ['n', 'n'],
        self::PUSH_REF_EL => ['n'],
        self::PROP_POST_INC => ['n'],
        self::PROP_POST_DEC => ['n'],
        self::PROP_PRE_INC  => ['n'],
        self::PROP_PRE_DEC  => ['n'],
        self::IDX_POST_INC  => ['n'],
        self::IDX_POST_DEC  => ['n'],
        self::IDX_PRE_INC   => ['n'],
        self::IDX_PRE_DEC   => ['n'],
        self::CALL_RR       => ['n', 'n', 'i'],
    ];

    /** opcode => 可读名（仅调试用） */
    public const NAMES = [
        self::PUSH_C    => 'PUSH_C',
        self::PUSH_V    => 'PUSH_V',
        self::POP_V     => 'POP_V',
        self::DUP       => 'DUP',
        self::DROP      => 'DROP',
        self::LOAD_THIS => 'LOAD_THIS',
        self::PUSH_NULL => 'PUSH_NULL',
        self::ADD       => 'ADD',
        self::SUB       => 'SUB',
        self::MUL       => 'MUL',
        self::DIV       => 'DIV',
        self::MOD       => 'MOD',
        self::POW       => 'POW',
        self::CONCAT    => 'CONCAT',
        self::NEG       => 'NEG',
        self::BW_NOT    => 'BW_NOT',
        self::BW_AND    => 'BW_AND',
        self::BW_OR     => 'BW_OR',
        self::BW_XOR    => 'BW_XOR',
        self::BW_SHL    => 'BW_SHL',
        self::BW_SHR    => 'BW_SHR',
        self::EQ        => 'EQ',
        self::NE        => 'NE',
        self::LT        => 'LT',
        self::GT        => 'GT',
        self::LE        => 'LE',
        self::GE        => 'GE',
        self::IDENT     => 'IDENT',
        self::NIDENT    => 'NIDENT',
        self::SPACESHIP => 'SPACESHIP',
        self::BOOL_AND  => 'BOOL_AND',
        self::BOOL_OR   => 'BOOL_OR',
        self::BOOL_NOT  => 'BOOL_NOT',
        self::BOOL_XOR  => 'BOOL_XOR',
        self::JMP       => 'JMP',
        self::JZ        => 'JZ',
        self::JNZ       => 'JNZ',
        self::CALL      => 'CALL',
        self::MECALL    => 'MECALL',
        self::NEW       => 'NEW',
        self::PROPGET   => 'PROPGET',
        self::PROPSET   => 'PROPSET',
        self::RET       => 'RET',
        self::RET_VOID  => 'RET_VOID',
        self::ECHO      => 'ECHO',
        self::HALT      => 'HALT',
        self::ARRAY_MAKE => 'ARRAY_MAKE',
        self::ARRAY_KV   => 'ARRAY_KV',
        self::INDEX_GET  => 'INDEX_GET',
        self::INDEX_SET  => 'INDEX_SET',
        self::COUNT      => 'COUNT',
        self::ARRAY_PUSH  => 'ARRAY_PUSH',
        self::PRE_INC    => 'PRE_INC',
        self::PRE_DEC    => 'PRE_DEC',
        self::POST_INC   => 'POST_INC',
        self::POST_DEC   => 'POST_DEC',
        self::CALL_VAR   => 'CALL_VAR',
        self::JMP_B      => 'JMP_B',
        self::SET_ST     => 'SET_ST',
        self::DISPATCH   => 'DISPATCH',
        self::SCALL      => 'SCALL',
        self::SPROP      => 'SPROP',
        self::ISSET_     => 'ISSET',
        self::EMPTY_     => 'EMPTY',
        self::UNSET_     => 'UNSET',
        self::EXIT_      => 'EXIT',
        self::EXIT_VOID  => 'EXIT_VOID',
        self::PRINT_     => 'PRINT',
        self::PHPRUN     => 'PHPRUN',
        self::CAST_INT    => 'CAST_INT',
        self::CAST_FLOAT  => 'CAST_FLOAT',
        self::CAST_STRING => 'CAST_STRING',
        self::CAST_BOOL   => 'CAST_BOOL',
        self::CAST_ARRAY  => 'CAST_ARRAY',
        self::CAST_OBJECT => 'CAST_OBJECT',
        self::PUSH_GLOBALS => 'PUSH_GLOBALS',
        self::GLOBALS_SET  => 'GLOBALS_SET',
        self::GLOBAL_REF   => 'GLOBAL_REF',
        self::DEFINE       => 'DEFINE',
        self::CONST_FETCH  => 'CONST_FETCH',
        self::INCLUDE_EMBED => 'INCLUDE_EMBED',
        self::INCLUDE_RT    => 'INCLUDE_RT',
        self::EVAL_STMT     => 'EVAL_STMT',
        self::CALL_NATIVE   => 'CALL_NATIVE',
        self::PUSH_REF      => 'PUSH_REF',
        self::SCALL_VAR     => 'SCALL_VAR',
        self::INSTANCEOF    => 'INSTANCEOF',
        self::THROW         => 'THROW',
        self::EX_FINALLY_ENTER => 'EX_FINALLY_ENTER',
        self::EX_FINALLY_END => 'EX_FINALLY_END',
        self::ARG_SPREAD => 'ARG_SPREAD',
        self::ARG_NAMED  => 'ARG_NAMED',
        self::MAKE_REF     => 'MAKE_REF',
        self::MAKE_REF_EL  => 'MAKE_REF_EL',
        self::MAKE_REF_PROP => 'MAKE_REF_PROP',
        self::MAKE_REF_APPEND => 'MAKE_REF_APPEND',
        self::PUSH_REF_EL => 'PUSH_REF_EL',
        self::PROP_POST_INC => 'PROP_POST_INC',
        self::PROP_POST_DEC => 'PROP_POST_DEC',
        self::PROP_PRE_INC  => 'PROP_PRE_INC',
        self::PROP_PRE_DEC  => 'PROP_PRE_DEC',
        self::IDX_POST_INC  => 'IDX_POST_INC',
        self::IDX_POST_DEC  => 'IDX_POST_DEC',
        self::IDX_PRE_INC   => 'IDX_PRE_INC',
        self::IDX_PRE_DEC   => 'IDX_PRE_DEC',
        self::CALL_RR       => 'CALL_RR',
        self::BINOP         => 'BINOP',
        self::UNOP          => 'UNOP',
        self::CAST_OP       => 'CAST_OP',
        self::INC_OP        => 'INC_OP',
        self::VAROP         => 'VAROP',
        self::ARRAY_BUILD   => 'ARRAY_BUILD',
    ];

    /** 基本块终结指令（其后不再顺序执行） */
    public const TERMINATORS = [
        self::RET => true, self::RET_VOID => true, self::HALT => true,
        self::JMP => true, self::JMP_B => true, self::DISPATCH => true,
        self::EXIT_ => true, self::EXIT_VOID => true,
        self::EX_FINALLY_END => true,
    ];

    /** 条件跳转指令（有 fall-through 与 taken 两个出口） */
    public const COND_JUMPS = [self::JZ => true, self::JNZ => true];

    /** 指令的跳转目标（相对偏移）所在的操作数下标 */
    public const JUMP_OPERAND_INDEX = [
        self::JMP => 1, self::JZ => 1, self::JNZ => 1, self::JMP_B => 1,
    ];

    public static function isTerminator(int $op): bool
    {
        return isset(self::TERMINATORS[$op]);
    }

    public static function isCondJump(int $op): bool
    {
        return isset(self::COND_JUMPS[$op]);
    }

    /**
     * 相对跳转指令的绝对目标下标。
     * 运行期取指后 pc 已 +1，故目标 = 指令下标 + 1 + 偏移。
     */
    public static function relTarget(int $idx, int $offset): int
    {
        return $idx + 1 + $offset;
    }
}
