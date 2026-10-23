<?php
/**
 * 自定义 VM 运行时解释器（VM Runtime Interpreter）
 *
 * 本文件不是用户代码，而是"壳"本身：它逐条取出自定义字节码（见 VmOp）并执行，
 * 用户原始逻辑只以字节码形式存在，运行时由这里解释。破解者拿到的只是 opcode 序号 +
 * 常量池 / 名字表，看不到任何 PHP 语句，因此这是一套真正的自定义指令集解释器
 * （而非把 PHP 包一层 eval）。
 *
 * 两级入口：
 *   - __VMRUN__($__P)  $__P 为「程序数组」（测试 / 调试用，明文结构）
 *   - __VMRUN__($__B)  $__B 为「二进制串」（Serializer 产出的紧凑格式，见 Serializer.php）
 *     此时内部先调用 __VMBLOB__ 解码：分片解密 → 变长解码 → 操作码反查表还原。
 *
 * 操作码编号是硬编码的（与 src/Vm/Op.php 完全一致），使本文件可独立嵌入产物、
 * 不依赖 src/ 自动加载。类名/变量名均为解释器内部，与用户代码无关。
 *
 * 本文件是「静态参考运行时」：与 VmRuntimeGenerator::runtimeTemplate() 动态生成的
 * 解释器保持同一套语义（by-ref 帧变量表、include 两路支持、全局帧桥接等），
 * 供测试与文档对照。实际产物中的解释器由生成器按盐值动态生成（随机变量名 /
 * 随机操作码编号 / 打乱 case），不直接使用本文件。
 *
 * __VMRUN__ 会被 VirtualMachine 替换为拟真函数名，消除指纹。
 */
function __VMRUN__($__P)
{
    // 操作码常量（与 VmOp.php 一致）
    $PUSH_C=1;$PUSH_V=2;$POP_V=3;$DUP=4;$DROP=5;$LOAD_THIS=6;$PUSH_NULL=7;
    $ADD=10;$SUB=11;$MUL=12;$DIV=13;$MOD=14;$POW=15;$CONCAT=16;$NEG=17;$BW_NOT=18;
    $BW_AND=20;$BW_OR=21;$BW_XOR=22;$BW_SHL=23;$BW_SHR=24;
    $EQ=30;$NE=31;$LT=32;$GT=33;$LE=34;$GE=35;$IDENT=36;$NIDENT=37;$SPACESHIP=38;
    $BOOL_AND=40;$BOOL_OR=41;$BOOL_NOT=42;$BOOL_XOR=43;
    $JMP=50;$JZ=51;$JNZ=52;$CALL=53;$MECALL=54;$NEW=55;$PROPGET=56;$PROPSET=57;$RET=58;$RET_VOID=59;$ECHO=60;$HALT=61;
    $ARRAY_MAKE=70;$ARRAY_KV=71;$INDEX_GET=72;$INDEX_SET=73;$COUNT=74;$ARRAY_PUSH=75;
    $PRE_INC=80;$PRE_DEC=81;$POST_INC=82;$POST_DEC=83;$CALL_VAR=90;
    $JMP_B=62;$SET_ST=63;$DISPATCH=64;
    $SCALL=91;$SPROP=92;$ISSET_=93;$EMPTY_=94;$UNSET_=95;$EXIT_=96;$EXIT_VOID=97;$PRINT_=98;$PHPRUN=99;
    $CAST_INT=100;$CAST_FLOAT=101;$CAST_STRING=102;$CAST_BOOL=103;$CAST_ARRAY=104;$CAST_OBJECT=105;
    $PUSH_GLOBALS=106;$GLOBALS_SET=107;$GLOBAL_REF=108;$DEFINE=109;$CONST_FETCH=110;
    $INCLUDE_EMBED=111;$INCLUDE_RT=112;$EVAL_STMT=113;$CALL_NATIVE=114;$PUSH_REF=115;$SCALL_VAR=117;$INSTANCEOF=118;
    $THROW=119;$EX_FINALLY_ENTER=120;$EX_FINALLY_END=121;$ARG_SPREAD=122;$ARG_NAMED=123;$MAKE_REF=124;$MAKE_REF_EL=125;$MAKE_REF_PROP=126;$MAKE_REF_APPEND=127;$PUSH_REF_EL=128;$PROP_POST_INC=129;$PROP_POST_DEC=130;$PROP_PRE_INC=131;$PROP_PRE_DEC=132;$IDX_POST_INC=133;$IDX_POST_DEC=134;$IDX_PRE_INC=135;$IDX_PRE_DEC=136;$CALL_RR=137;$INSTANCEOF_V=138;$LSB_BIND=139;$LSB_CLASS=140;
    // FCC（PHP 8.1+ 一等可调用方法语法）
    $MECALL_FCC=141;$SCALL_FCC=142;$MECALL_FCC_VAR=143;$SCALL_FCC_VAR=144;$SCALL_FCC_DYN=145;

    $__META = "\x01__cls";

    // 共享全局帧：global 声明把帧槽位绑定为这里的引用（PHP global 语义）
    $__GFRAME = [];
    // called-class 栈（static:: 延迟静态绑定）：VM 方法执行期间压入「本次调用的
    // called class」。类操作数为 "\x00lsb:FB" 标记（static::）时按栈顶解析（栈空
    // 回退编译期类名 FB）；"\x00fwd:FB" 标记（self::/parent:: 方法转发）表示
    // 查找类为 FB 但 called class 保持栈顶（PHP 转发调用语义，实测确认）；
    // 显式类名调用（C::m()）则把该类自身压栈。\x00 不可能出现在 PHP 类名中。
    $__calledCls = [];
    // 包含去重表：include_once / require_once 与 INCLUDE_EMBED 的 once 语义共用
    // （INCLUDE_EMBED 记 embedId，INCLUDE_RT 记 'r'.realpath，二者键空间不冲突）
    $__INCTRACK = [];

    // 二进制格式：先解码成程序数组（明文结构），再统一走同一条执行路径
    if (is_string($__P)) {
        $__P = __VMBLOB__($__P);
    }

    // natTbl 形态归一：程序数组直传时为 name=>idx；二进制打包读回时为 idx=>name。
    // callFn 统一按 idx=>name 查表，故此处把 name=>idx 翻转为 idx=>name。
    if (isset($__P['natTbl']) && is_array($__P['natTbl']) && $__P['natTbl'] !== []) {
        if (is_string(array_key_first($__P['natTbl']))) {
            $__P['natTbl'] = array_flip($__P['natTbl']);
        }
    }

    // 状态寄存器名（控制流平坦化使用），随产物变化
    $__STN = isset($__P['stn']) ? $__P['stn'] : '__st';

    /**
     * 段解析：程序段既可能是「指令数组」（明文模式），也可能是「分片密文段」。
     * 后者在此处惰性解密 + 解码，并写回缓存，保证每段只解一次。
     */
    $__code = function (&$__raw) {
        if (is_array($__raw) && isset($__raw[0]) && is_array($__raw[0])) {
            return $__raw;                      // 已是明文指令数组
        }
        if (is_array($__raw) && isset($__raw['__c'])) {
            return $__raw['__c'];               // 已解码并缓存
        }
        $__dec = __VMSEG__($__raw);
        $__raw['__c'] = $__dec[0];
        return $__dec[0];
    };

    // 内置二元运算
    $__bin = function($op,$a,$b) use (&$__bin,&$__toStr){
        switch($op){
            case 10:return $a+$b;
            case 11:return $a-$b;
            case 12:return $a*$b;
            // 除零/模零：PHP 8 抛 DivisionByZeroError（旧实现静默返回 0，
            // 与原生语义不符——try/catch 结构下的行为会静默分叉）。
            // 数值型操作数（int/float/bool/null/数值串）判零；其它类型交还原生
            // 运算抛 TypeError，与 PHP 一致。
            case 13:
                if ($b===null||is_bool($b)||is_int($b)||is_float($b)||(is_string($b)&&is_numeric($b))) {
                    if ($b==0) { throw new \DivisionByZeroError('Division by zero'); }
                }
                return $a/$b;
            case 14:
                if ($b===null||is_bool($b)||is_int($b)||is_float($b)||(is_string($b)&&is_numeric($b))) {
                    if ((int)$b===0) { throw new \DivisionByZeroError('Modulo by zero'); }
                }
                return $a%$b;
            case 15:return $a**$b;
            case 16:return $__toStr($a).$__toStr($b);
            case 20:return $a&$b;
            case 21:return $a|$b;
            case 22:return $a^$b;
            case 23:return $a<<$b;
            case 24:return $a>>$b;
            case 30:return $a==$b;
            case 31:return $a!=$b;
            case 32:return $a<$b;
            case 33:return $a>$b;
            case 34:return $a<=$b;
            case 35:return $a>=$b;
            case 36:return $a===$b;
            case 37:return $a!==$b;
            case 38:return $a<=>$b;
            case 40:return ($a&&$b);
            case 41:return ($a||$b);
        }
        return null;
    };

    // 自增/自减（PHP 语义，实测 PHP 8.3 校准）：
    //   int/float          ±1
    //   null               ++ → 1；-- 无效果
    //   bool               无效果
    //   array              TypeError（Cannot increment/decrement array）
    //   数值串             按 int/float 转换后 ±1（"9"→10、"9.5"→10.5、"1e3"→1001.0）
    //   非数值串           ++ 走 Perl 式字母进位（"Az"→"Ba"、"Zz"→"AAa"、""→"1"）；
    //                     -- 无效果
    // 旧实现直接 $v+1，遇字符串即 TypeError，且语义与原生不符。
    $__inc = function($v,$d){
        if (is_int($v) || is_float($v)) { return $v + $d; }
        if (is_null($v)) { return $d > 0 ? 1 : $v; }
        if (is_bool($v)) { return $v; }
        if (is_array($v)) {
            throw new \TypeError($d > 0 ? 'Cannot increment array' : 'Cannot decrement array');
        }
        if (is_string($v)) {
            if (is_numeric($v)) {
                return (strpbrk($v, '.eE') !== false) ? (float)$v + $d : (int)$v + $d;
            }
            if ($v === '') { return $d > 0 ? '1' : $v; }
            if ($d < 0) { return $v; } // 非数值串递减无效果
            // Perl 式字母递增：从右向左，'z'→'a'/'Z'→'A'/'9'→'0' 进位，
            // 其它字符（含高位字节）终止且不进位。
            $s = $v;
            $carry = true;
            for ($i = strlen($s) - 1; $i >= 0; $i--) {
                $ch = $s[$i];
                if ($ch >= 'a' && $ch < 'z') { $s[$i] = chr(ord($ch) + 1); $carry = false; break; }
                if ($ch === 'z') { $s[$i] = 'a'; continue; }
                if ($ch >= 'A' && $ch < 'Z') { $s[$i] = chr(ord($ch) + 1); $carry = false; break; }
                if ($ch === 'Z') { $s[$i] = 'A'; continue; }
                if ($ch >= '0' && $ch < '9') { $s[$i] = chr(ord($ch) + 1); $carry = false; break; }
                if ($ch === '9') { $s[$i] = '0'; continue; }
                $carry = false;
                break;
            }
            if ($carry) {
                // 整串缠绕：按【原串首字符】类别前置 'a'/'A'/'1'
                // （"Zz"→"AAa"、"z9"→"aa0"、"9z"→"10a"，均经实测校准）
                $f = $v[0];
                $pre = ($f === 'Z') ? 'A' : (($f === 'z') ? 'a' : '1');
                $s = $pre . $s;
            }
            return $s;
        }
        return $v + $d; // 其它类型：交还原生语义
    };

    // 取属性
    $__propGet = function($obj,$p) use ($__META){
        if (is_object($obj)) { return $obj->$p ?? null; }
        if (is_array($obj)) { return $obj[$p] ?? null; }
        return null;
    };
    // 写属性（对象为引用语义，直接原地修改）
    $__propSet = function($obj,$p,$v) use ($__META){
        if (is_object($obj)) { $obj->$p = $v; return; }
        if (is_array($obj)) { $obj[$p] = $v; return; }
    };

    // 参数默认值补齐：实参不足时按参数规格（[名字, 默认值段|null]）从尾部依次补齐。
    // PHP 的默认值是惰性求值，故默认值以迷你指令段存储，在此按需执行。
    // 注意：$__exec 以引用接收帧变量表，PHP 8 禁止数组字面量按引用传递，
    // 故各调用点统一先赋临时变量（$__ev）再传参。
    // 键空洞：命名实参重排（$__reorder）会在中段留下空洞——如 f("x", c:"z") 跳过
    // $b 时得 [0=>"x", 2=>"z"]。此时 count() 少算、$args[]= 追加到最高键之后，
    // 均致实参错位；故补齐须按 array_key_exists 逐位判定，填充后 ksort 复位键序
    // （下游 POP_V 序言按栈位置消费，array_slice 亦按位置切片）。
    $__pad = function($spec, $args) use (&$__exec, &$__code) {
        $n = count($spec);
        // 可变参数（...$x）：末位形参吸收【所有】剩余实参为一个数组（PHP 语义）。
        // 固定参数仍按位对齐，缺失的由默认值段补齐。
        if ($n > 0 && (($spec[$n - 1][2] ?? 0) & 2)) {
            $fixed = $n - 1;
            $filled = false;
            for ($i = 0; $i < $fixed; $i++) {
                if (array_key_exists($i, $args)) { continue; }
                $d = $spec[$i][1];
                $__ev = [];
                $args[$i] = ($d === null) ? null : $__exec($__code($d), $__ev, null, [], null);
                $filled = true;
            }
            if ($filled) { ksort($args); }
            $rest = array_slice($args, $fixed);
            $args = array_slice($args, 0, $fixed);
            // 若调用点用了 ...$arr 解包，实参已被展平为多个元素，直接吸收即可
            $args[] = array_values($rest);
            return $args;
        }
        $filled = false;
        for ($i = 0; $i < $n; $i++) {
            if (array_key_exists($i, $args)) { continue; }
            $d = $spec[$i][1];
            $__ev = [];
            $args[$i] = ($d === null) ? null : $__exec($__code($d), $__ev, null, [], null);
            $filled = true;
        }
        if ($filled) { ksort($args); }
        return $args;
    };

    // 类常量继承链查找：返回真正声明该常量的 VM 类/接口名（含自身），
    // 未命中返回 null。优先级：自身 → implements 接口 → extends 父类
    // （PHP 继承语义：子类/实现类可访问父类/接口常量）。
    $__constOwner = function($cls,$name) use ($__P){
        $c = $cls; $guard = 0;
        while (is_string($c) && $c !== '' && $guard++ < 64 && isset($__P['classes'][$c])) {
            if (array_key_exists($name, $__P['classes'][$c]['consts'] ?? [])) { return $c; }
            foreach (array_keys($__P['classImpl'][$c] ?? []) as $if) {
                if (isset($__P['classes'][$if]) && array_key_exists($name, $__P['classes'][$if]['consts'] ?? [])) { return $if; }
            }
            $c = $__P['classParents'][$c] ?? null;
        }
        return null;
    };

    // 方法继承链查找：返回真正实现该方法的 VM 类名（含自身），未命中返回 null。
    // 覆盖父类方法（abstract 基类 + 子类实现的经典形态）与 trait 复制进来的方法
    // （trait 方法在 use 时已复制进类表，无需在此处理）。
    $__methodOwner = function($cls,$m) use ($__P){
        $c = $cls; $guard = 0;
        while (is_string($c) && $c !== '' && $guard++ < 64 && isset($__P['classes'][$c])) {
            if (isset($__P['classes'][$c]['methods'][$m])) { return $c; }
            $c = $__P['classParents'][$c] ?? null;
        }
        return null;
    };

    // 解包实参展平：ARG_SPREAD 压入的 ['__vmspread__', 数组] 标记展开为多个实参。
    // 整数键 → 位置实参；字符串键 → 命名实参（['__vmnamed__', 键, 值]），
    // 与 PHP 的 f(...$a) 语义一致（字符串键数组等价于命名实参）。
    $__flatten = function($args){
        foreach ($args as $__x) {
            if (is_array($__x) && ($__x[0] ?? '') === '__vmspread__') {
                $__flat = [];
                foreach ($args as $__y) {
                    if (is_array($__y) && ($__y[0] ?? '') === '__vmspread__') {
                        foreach ((array) ($__y[1] ?? []) as $__k => $__e) {
                            $__flat[] = is_int($__k) ? $__e : ['__vmnamed__', $__k, $__e];
                        }
                    } else { $__flat[] = $__y; }
                }
                return $__flat;
            }
        }
        return $args;
    };

    // 命名实参重排：把 ['__vmnamed__', 形参名, 值] 标记按被调方参数规格
    // （[参数名, 默认值段, flags]）还原为位置实参；未知形参名抛 Error
    // （与 PHP 的 "Unknown named parameter" 一致）。无标记时零开销返回。
    $__reorder = function($args,$spec){
        $__has = false;
        foreach ($args as $__x) {
            if (is_array($__x) && ($__x[0] ?? '') === '__vmnamed__') { $__has = true; break; }
        }
        if (!$__has) { return $args; }
        $__out = []; $__named = [];
        foreach ($args as $__x) {
            if (is_array($__x) && ($__x[0] ?? '') === '__vmnamed__') {
                $__named[(string) $__x[1]] = $__x[2];
            } else { $__out[] = $__x; }
        }
        if ($spec !== null) {
            foreach ($spec as $__i => $__sp) {
                $__pn = ltrim((string) $__sp[0], '$');
                if (array_key_exists($__pn, $__named)) {
                    $__out[$__i] = $__named[$__pn];
                    unset($__named[$__pn]);
                }
            }
        }
        if ($__named !== []) {
            throw new \Error('Unknown named parameter $' . array_key_first($__named));
        }
        return $__out;
    };

    // 实参绑定：把栈上实参（可含引用句柄 / 命名标记 / 解包标记）按被调方规格
    // 规整为「位置值 + by-ref 回写映射」——CALL/MECALL/SCALL/CALL_NATIVE 共用。
    //   1) 展平解包标记；2) 重排命名实参；3) 引用句柄按 spec flags 分流：
    //      by-ref 位 copy-in（换调用方当前值 + 记录回写），非引用位取快照值。
    // $__cv：调用方帧变量表（按引用）；返回 [规整后实参, 回写映射]。
    $__bindArgs = function($args,$spec,&$__cv=null) use ($__flatten,$__reorder){
        $args = $__flatten($args);
        $args = $__reorder($args,$spec);
        $refs = [];
        if ($spec !== null) {
            foreach ($args as $i => $a) {
                if (!is_array($a) || ($a[0] ?? '') !== '__vmref__') { continue; }
                $cvar = (string) $a[1];
                if (((int) ($spec[$i][2] ?? 0)) & 1) {
                    $refs[$i] = [$cvar, (string) $spec[$i][0]];
                    $args[$i] = ($__cv !== null && array_key_exists($cvar, $__cv)) ? $__cv[$cvar] : null;
                } else {
                    $args[$i] = $a[2] ?? null; // 非引用位：取压栈时的值快照
                }
            }
        } else {
            foreach ($args as $i => $a) {
                if (is_array($a) && ($a[0] ?? '') === '__vmref__') { $args[$i] = $a[2] ?? null; }
            }
        }
        return [$args,$refs];
    };

    // 原生函数实参绑定（CALL_NATIVE）：展平 + 命名重排（无 spec，遇命名实参抛错）
    // + 引用句柄分流：refMask 位绑定为调用方帧变量的【真实引用】（sort/usort 等
    // 原地修改语义），非 mask 位取压栈时的值快照。
    $__bindNative = function($args,$rm,&$__cv=null) use ($__flatten,$__reorder){
        $args = $__flatten($args);
        $args = $__reorder($args,null);
        foreach ($args as $i => $a) {
            if (!is_array($a) || ($a[0] ?? '') !== '__vmref__') { continue; }
            if ((($rm >> $i) & 1) && $__cv !== null) {
                $n = (string) $a[1];
                if (!array_key_exists($n, $__cv)) { $__cv[$n] = null; }
                $args[$i] = &$__cv[$n];
            } else {
                $args[$i] = $a[2] ?? null;
            }
        }
        return $args;
    };

    // 构造对象
    $__newObj = function($cls,$args) use (&$__exec,$__P,$__META,&$__callMethod,&$__code,$__methodOwner,$__bindArgs,&$__calledCls){
        // new static：类操作数为 "\x00lsb:FB" 标记 → 按 called class 解析
        // （PHP: new static 实例化运行期实际类）。
        if (is_string($cls) && strncmp($cls, "\x00lsb:", 5) === 0) {
            $cls = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : substr($cls, 5);
        }
        if (isset($__P['classes'][$cls])) {
            $o = new \stdClass();
            $o->$__META = $cls;
            // 属性默认值沿继承链收集（子类声明优先，父类补缺——PHP 继承语义）。
            // 父类默认值段已按父类作用域编译（self:: 等已折值），直接求值即可。
            $__pdefs = [];
            $__cc = $cls; $__g = 0;
            while (is_string($__cc) && $__cc !== '' && isset($__P['classes'][$__cc]) && $__g++ < 64) {
                foreach (($__P['classes'][$__cc]['props']??[]) as $pn=>$def) {
                    if (!array_key_exists($pn, $__pdefs)) { $__pdefs[$pn] = $def; }
                }
                $__cc = $__P['classParents'][$__cc] ?? null;
            }
            foreach ($__pdefs as $pn=>$def) {
                $__ev = [];
                $o->$pn = ($def===null) ? null : $__exec($__code($def), $__ev, null, [], null);
            }
            // 构造函数同样沿继承链查找（子类未定义 __construct 时调父类的）
            if ($__methodOwner($cls,'__construct') !== null) {
                $__callMethod($o,'__construct',$args);
            }
            return $o;
        }
        // 原生类：实参里的引用句柄先解包为值（原生构造器无 VM 参数规格）。
        // 注意：by-ref 形参不可传字面量 null，须经局部变量中转。
        $__ncv = null;
        [$args,] = $__bindArgs($args, null, $__ncv);
        $rc = new \ReflectionClass($cls);
        return $rc->newInstanceArgs($args);
    };

    // VM 回调解析：把"指向 VM 函数/方法的 callable"包装成原生 PHP 闭包，
    // 使 call_user_func / array_map / usort 等原生高阶函数能直接驱动 VM 代码。
    // 方法回调执行期间压 called class（static:: 延迟解析依据；对象回调压对象
    // 实际类，类名/字符串回调压该类）。
    $__vmcb = function($cb) use (&$__exec,$__P,&$__code,&$__callMethod,$__META,&$__pad,&$__calledCls){
        if (is_string($cb) && isset($__P['funcs'][$cb])) {
            $seg = $__P['funcs'][$cb];
            $sp = $__P['funcParams'][$cb] ?? [];
            $fet = $__P['funcET'][$cb] ?? null;
            return function(...$a) use (&$__exec,&$__code,&$__pad,$seg,$sp,$fet) { $__ev=[]; return $__exec($__code($seg),$__ev,null,$__pad($sp,$a),null,false,$fet); };
        }
        if (is_array($cb) && count($cb)===2) {
            $o = $cb[0]; $m = $cb[1];
            if (is_object($o) && isset($o->$__META) && isset($__P['classes'][$o->$__META]['methods'][$m])) {
                return function(...$a) use (&$__callMethod,$o,$m) { return $__callMethod($o,$m,$a); };
            }
            if (is_string($o) && isset($__P['classes'][$o]['methods'][$m])) {
                $seg = $__P['classes'][$o]['methods'][$m];
                $sp = $__P['classParams'][$o][$m] ?? [];
                $cet = $__P['classET'][$o][$m] ?? null;
                return function(...$a) use (&$__exec,&$__code,&$__pad,$seg,$sp,$cet,$o,&$__calledCls) { $__ev=[]; $__calledCls[]=$o; try { return $__exec($__code($seg),$__ev,null,$__pad($sp,$a),null,false,$cet); } finally { array_pop($__calledCls); } };
            }
        }
        if (is_string($cb) && strpos($cb,'::')!==false) {
            $p = explode('::',$cb,2);
            if (isset($__P['classes'][$p[0]]['methods'][$p[1]])) {
                $seg = $__P['classes'][$p[0]]['methods'][$p[1]];
                $sp = $__P['classParams'][$p[0]][$p[1]] ?? [];
                $cet = $__P['classET'][$p[0]][$p[1]] ?? null;
                return function(...$a) use (&$__exec,&$__code,&$__pad,$seg,$sp,$cet,$p,&$__calledCls) { $__ev=[]; $__calledCls[]=$p[0]; try { return $__exec($__code($seg),$__ev,null,$__pad($sp,$a),null,false,$cet); } finally { array_pop($__calledCls); } };
            }
        }
        return $cb;
    };

    // FCC：一等可调用创建（PHP 8.1+ $o->m(...) / C::m(...) / $o->$m(...) /
    // C::$m(...) / $cls::m(...)）。创建期校验与原生一致：
    //   - VM 类方法不存在（且无 __call / __callStatic）→ Error；
    //   - 类名 FCC 指向非静态方法 → Error（"Non-static method ... cannot be
    //     called statically"——原生亦在创建期抛，不在调用期）；
    //   - 接收者为 null → Error（"Call to a member function m() on null"）。
    // 返回【原生闭包】：实例 FCC 的 $this 绑定到该对象（经 $__callMethod 分发，
    // 静态方法亦可——原生允许 $o->s(...)）；类名 FCC 桥接类方法段，调用期压
    // called class（保证闭包内 static:: 解析到创建期类，与 PHP 闭包作用域一致）。
    // 非 VM 对象/类交还原生 Closure::fromCallable（可见性校验等原生语义保留）。
    // 已知缺口：VM 不记录方法可见性，private/protected 方法的越权 FCC 不会
    // 抛错（原生会抛 "Call to private method ..."）。
    $__fcc = function($cb) use (&$__exec,$__P,&$__code,&$__callMethod,$__META,&$__pad,&$__methodOwner,&$__calledCls){
        if (is_array($cb) && count($cb)===2) {
            $__o = $cb[0]; $__m = $cb[1];
            if ($__o === null) {
                throw new \Error('Call to a member function ' . (is_string($__m) ? $__m : '') . '() on null');
            }
            if (is_object($__o) && isset($__o->$__META) && is_string($__o->$__META)) {
                $__cls = $__o->$__META;
                if ($__methodOwner($__cls, $__m) === null) {
                    // __call 魔术：不存在的方法允许创建 FCC（PHP：调用期才走魔术分发）
                    if (!isset($__P['classes'][$__cls]['methods']['__call'])) {
                        throw new \Error("Call to undefined method {$__cls}::{$__m}()");
                    }
                }
                return function(...$a) use (&$__callMethod,$__o,$__m) { return $__callMethod($__o,$__m,$a); };
            }
        }
        if (is_string($cb) && strpos($cb,'::')!==false) {
            $__p = explode('::',$cb,2);
            $__cls = $__p[0]; $__m = $__p[1];
            if (isset($__P['classes'][$__cls])) {
                $__mo = $__methodOwner($__cls, $__m);
                if ($__mo === null) {
                    // __callStatic：桥接到魔术方法（调用期分发，PHP 同语义）
                    if (isset($__P['classes'][$__cls]['methods']['__callStatic'])) {
                        $__seg = $__P['classes'][$__cls]['methods']['__callStatic'];
                        $__sp = $__P['classParams'][$__cls]['__callStatic'] ?? [];
                        $__cet = $__P['classET'][$__cls]['__callStatic'] ?? null;
                        return function(...$a) use (&$__exec,&$__code,&$__pad,$__seg,$__sp,$__cet,$__cls,$__m,&$__calledCls) {
                            $__ev = []; $__calledCls[] = $__cls;
                            try { return $__exec($__code($__seg),$__ev,null,$__pad($__sp,array_merge([$__m],$a)),null,false,$__cet); }
                            finally { array_pop($__calledCls); }
                        };
                    }
                    throw new \Error("Call to undefined method {$__cls}::{$__m}()");
                }
                if (empty($__P['classStatic'][$__mo][$__m])) {
                    throw new \Error("Non-static method {$__cls}::{$__m}() cannot be called statically");
                }
                $__seg = $__P['classes'][$__mo]['methods'][$__m];
                $__sp = $__P['classParams'][$__mo][$__m] ?? [];
                $__cet = $__P['classET'][$__mo][$__m] ?? null;
                return function(...$a) use (&$__exec,&$__code,&$__pad,$__seg,$__sp,$__cet,$__cls,&$__calledCls) {
                    $__ev = []; $__calledCls[] = $__cls;
                    try { return $__exec($__code($__seg),$__ev,null,$__pad($__sp,$a),null,false,$__cet); }
                    finally { array_pop($__calledCls); }
                };
            }
        }
        // 原生对象 / 类路径：先按 FCC 语法语义自行校验（错误措辞与原生
        // $o->m(...) 一致——Closure::fromCallable 的措辞不同），通过后交还。
        if (is_array($cb) && count($cb)===2 && is_object($cb[0])) {
            if (!method_exists($cb[0], $cb[1]) && !method_exists($cb[0], '__call')) {
                throw new \Error('Call to undefined method ' . get_class($cb[0]) . '::' . $cb[1] . '()');
            }
            return Closure::fromCallable($cb);
        }
        if (is_string($cb) && strpos($cb,'::')!==false) {
            $__p = explode('::',$cb,2);
            if ((class_exists($__p[0]) || interface_exists($__p[0])) && !isset($__P['classes'][$__p[0]])) {
                if (!method_exists($__p[0], $__p[1]) && !method_exists($__p[0], '__callStatic')) {
                    throw new \Error("Call to undefined method {$__p[0]}::{$__p[1]}()");
                }
                if (method_exists($__p[0], $__p[1]) && !(new \ReflectionMethod($__p[0], $__p[1]))->isStatic()) {
                    throw new \Error("Non-static method {$__p[0]}::{$__p[1]}() cannot be called statically");
                }
            }
            return Closure::fromCallable($cb);
        }
        return Closure::fromCallable($cb);
    };


    // 调用函数（用户定义或内置）
    // $__cv：调用方帧变量表（按引用传入）——by-ref 实参需在此做 copy-in / copy-out。
    // VM 帧是值语义（POP_V 从栈拷贝），故引用参数按「传入当前值 → 被调方执行 →
    // 回写参数最终值」实现，等价于 PHP 的引用传递语义。
    // $rrDst：引用返回目标帧槽名（CALL_RR 用；null = 普通调用）。
    // 桥接回写必须走【容器元素级】引用赋值（$__cv[$rrDst] = &$__ev["\x01rr"]）：
    // PHP 不会把按引用参数的「变量级」重绑（$x = &...）传播回调用方（实测 8.3），
    // 而元素级赋值沿同一帧表 hashtable 直接穿透（MAKE_REF 即此机制）。
    // 且不再经过共享本地变量中转——否则两次调用的桥接槽会经同一变量并入
    // 同一引用集，导致 $a=&g();$b=&g() 后 $a/$b 共享存储。
    $__callFunc = function($name,$args,&$__cv=null,$rrDst=null) use (&$__exec,$__P,&$__code,$__vmcb,&$__pad,$__bindArgs){
        if (isset($__P['funcs'][$name])) {
            $spec = $__P['funcParams'][$name] ?? [];
            [$args,$refs] = $__bindArgs($args,$spec,$__cv);
            $args = $__pad($spec,$args);
            // 引用返回调用（CALL_RR）：被调帧预置桥接槽，函数内 return $var
            // 前已发 MAKE_REF "\x01rr" 槽名——返回后把桥接槽引用按元素级
            // 赋值写回调用方帧槽（PHP: $r=&g() 共享 g 内 $s 的存储）。
            $__ev = ($rrDst !== null) ? ["\x01rr" => null] : [];
            $__r = $__exec($__P['funcs'][$name],$__ev,null,$args,null,false,$__P['funcET'][$name] ?? null);
            // copy-out：被调方引用参数的最终值写回调用方变量（PHP: f($a) 里 $a 被改写）
            foreach ($refs as $i => $m) {
                if ($__cv !== null && array_key_exists($m[1], $__ev)) {
                    $__cv[$m[0]] = $__ev[$m[1]];
                }
            }
            if ($rrDst !== null && $__cv !== null) {
                if (!array_key_exists("\x01rr", $__ev)) { $__ev["\x01rr"] = null; }
                $__cv[$rrDst] = &$__ev["\x01rr"];
            }
            return $__r;
        }
        // 命名/解包标记对原生函数无意义（CALL_NATIVE 路径另行处理 refMask）：
        // 走到这里说明编译期已放行，仅可能残留引用句柄——统一解包为值。
        foreach ($args as $i => $a) {
            if (is_array($a) && ($a[0] ?? '') === '__vmref__') { $args[$i] = $a[2] ?? null; }
        }
        // 原生高阶函数（回调在 args[0]）：把 VM 回调包装后交还原生函数，保留原生语义
        if (($name==='call_user_func'||$name==='call_user_func_array'||$name==='forward_static_call'
            ||$name==='forward_static_call_array'||$name==='array_map') && isset($args[0])) {
            $args[0] = $__vmcb($args[0]);
            return call_user_func_array($name,$args);
        }
        // 原生高阶函数（回调在 args[1]）
        if (($name==='array_filter'||$name==='array_reduce'||$name==='array_walk'||$name==='usort'
            ||$name==='uasort'||$name==='uksort'||$name==='preg_replace_callback') && isset($args[1])) {
            $args[1] = $__vmcb($args[1]);
            return call_user_func_array($name,$args);
        }
        if (function_exists($name)) {
            return call_user_func_array($name,$args);
        }
        // 未知函数：尽力用可变函数调用
        return call_user_func_array($name,$args);
    };

    // 原生函数间接分发（CALL_NATIVE）：natTbl 索引 → 真实函数名。
    // 高阶函数的 VM 回调参数包装为原生闭包，保留原生语义。
    $__callFn = function($idx,$args) use ($__P,$__vmcb,$__META,&$__calledCls){
        $nt = $__P['natTbl'] ?? [];
        $name = is_int($idx) && isset($nt[$idx]) ? (string)$nt[$idx] : (string)$idx;
        // VM 类是 stdClass + META 代理：反射类名的内建函数须经 META 还原真实类名，
        // 否则 get_class/get_parent_class 对外暴露 stdClass（语义错误）。
        if ($name === 'get_class' && isset($args[0]) && is_object($args[0])
            && isset($args[0]->$__META) && is_string($args[0]->$__META)) {
            return $args[0]->$__META;
        }
        if ($name === 'get_parent_class' && isset($args[0]) && is_object($args[0])
            && isset($args[0]->$__META) && is_string($args[0]->$__META)) {
            return $__P['classParents'][$args[0]->$__META] ?? false;
        }
        if ($name === 'get_called_class') {
            // PHP: 类作用域外调用抛 Error
            if ($__calledCls === []) { throw new \Error('get_called_class(): Called from outside a class'); }
            return $__calledCls[count($__calledCls)-1];
        }
        if (($name==='call_user_func'||$name==='call_user_func_array'||$name==='forward_static_call'
            ||$name==='forward_static_call_array'||$name==='array_map') && isset($args[0])) {
            $args[0] = $__vmcb($args[0]);
            return call_user_func_array($name,$args);
        }
        if (($name==='array_filter'||$name==='array_reduce'||$name==='array_walk'||$name==='usort'
            ||$name==='uasort'||$name==='uksort'||$name==='preg_replace_callback') && isset($args[1])) {
            $args[1] = $__vmcb($args[1]);
            return call_user_func_array($name,$args);
        }
        return call_user_func_array($name,$args);
    };

    // 调用方法
    // $__cv：调用方帧变量表（按引用传入）——by-ref 实参的 copy-in / copy-out。
    $__callMethod = function($obj,$m,$args,&$__cv=null) use (&$__exec,$__P,$__META,&$__code,&$__pad,$__methodOwner,$__bindArgs,&$__calledCls){
        if (is_object($obj) && isset($obj->$__META) && is_string($obj->$__META)) {
            // 沿继承链查找方法实现（PHP 继承语义：子类对象调用父类方法）
            $__mo = $__methodOwner($obj->$__META, $m);
            if ($__mo !== null) {
                $spec = $__P['classParams'][$__mo][$m] ?? [];
                [$args,$refs] = $__bindArgs($args,$spec,$__cv);
                $args = $__pad($spec,$args);
                $__ev = ['$this'=>$obj];
                // called class = 对象实际类（PHP: get_called_class()）——方法体内的
                // static:: 据此延迟解析（子类对象调父类方法时解析到子类）。
                $__calledCls[] = $obj->$__META;
                try {
                    $__r = $__exec($__P['classes'][$__mo]['methods'][$m],$__ev,$obj,$args,null,false,$__P['classET'][$__mo][$m] ?? null);
                } finally {
                    array_pop($__calledCls);
                }
                // copy-out：被调方引用参数的最终值写回调用方变量
                foreach ($refs as $i => $mm) {
                    if ($__cv !== null && array_key_exists($mm[1], $__ev)) {
                        $__cv[$mm[0]] = $__ev[$mm[1]];
                    }
                }
                return $__r;
            }
            // __call 魔术分发（PHP：调用不存在的方法时触发，魔术方法收
            // (方法名, 实参数组)）。FCC 一等可调用（$o->m(...) 对不存在
            // 方法允许创建）与普通 $o->m() 均依赖此路径。
            $__mmo = $__methodOwner($obj->$__META, '__call');
            if ($__mmo !== null) {
                $__mspec = $__P['classParams'][$__mmo]['__call'] ?? [];
                $__margs = $__pad($__mspec, [$m, $args]);
                $__ev = ['$this'=>$obj];
                $__calledCls[] = $obj->$__META;
                try {
                    return $__exec($__P['classes'][$__mmo]['methods']['__call'],$__ev,$obj,$__margs,null,false,$__P['classET'][$__mmo]['__call'] ?? null);
                } finally {
                    array_pop($__calledCls);
                }
            }
        }
        if (is_object($obj) && (method_exists($obj,$m) || method_exists($obj,'__call'))) {
            // 原生对象方法：同样展平/去标记（同 $__callFunc 原生路径）。
            // by-ref 形参不可传字面量 null，须经局部变量中转。
            $__ncv = null;
            [$args,] = $__bindArgs($args, null, $__ncv);
            return call_user_func_array([$obj,$m],$args);
        }
        return null;
    };

    // instanceof 判定：VM 类/接口运行期是 stdClass + META 属性（无真实继承
    // 关系），沿 classParents / classImpl 链遍历；原生对象/类名交还原生
    // instanceof（VM 对象对原生类名判 false，与 PHP 语义一致）。
    $__instanceof = function($v,$cls) use ($__P,$__META){
        if (is_object($v)) {
            if (isset($v->$__META) && is_string($v->$__META)) {
                $c = $v->$__META;
                $guard = 0;
                while (is_string($c) && $c !== '' && $guard++ < 64) {
                    if ($c === $cls) { return true; }
                    if (isset($__P['classImpl'][$c][$cls])) { return true; }
                    $c = $__P['classParents'][$c] ?? null;
                }
                return false;
            }
        }
        return $v instanceof $cls;
    };

    // 值 → 字符串：命中 __toString 的 VM 类走方法调用（stdClass 直接转型会
    // 致命）；数组按 PHP 语义得 "Array"；其余交还原生转型（原生对象无
    // __toString 时 fatal 与 PHP 一致）。__toString 返回非字符串按 PHP 8
    // 抛 TypeError（实测：Return value must be of type string）。
    $__toStr = function($v) use (&$__toStr,$__P,$__META,&$__callMethod){
        if (is_object($v)) {
            if (isset($v->$__META) && is_string($v->$__META)
                && isset($__P['classes'][$v->$__META]['methods']['__toString'])) {
                $r = $__callMethod($v,'__toString',[]);
                if (!is_string($r)) {
                    throw new \TypeError('__toString() must return a string value');
                }
                return $r;
            }
        }
        if (is_array($v)) { return 'Array'; }
        return (string)$v;
    };

    // 索引读取
    $__idxGet = function($arr,$k){
        if (is_array($arr)) { return $arr[$k] ?? null; }
        if (is_string($arr)) { return $k===(int)$k && $k>=0 && $k<strlen($arr) ? $arr[$k] : null; }
        if (is_object($arr)) { return $arr->$k ?? null; }
        return null;
    };

    // isset / empty / unset 的键路径求值：$__vars[$name] 之后依次走 $k1,$k2...
    $__pathGet = function(&$__vars,$name,$keys,&$found){
        if ($name === '$GLOBALS') { $v = $GLOBALS; }
        elseif (!array_key_exists($name,$__vars)) { $found = false; return null; }
        else { $v = $__vars[$name]; }
        foreach ($keys as $k) {
            if (is_array($v)) {
                if (!array_key_exists($k,$v)) { $found = false; return null; }
                $v = $v[$k];
            } elseif (is_object($v)) {
                if (!isset($v->$k)) { $found = false; return null; }
                $v = $v->$k;
            } else { $found = false; return null; }
        }
        $found = true;
        return $v;
    };

    // 主执行器（递归执行函数/方法/表达式段）
    // 帧变量表 $__vars 以【引用】传入：INCLUDE_EMBED 的被包含段要与当前帧共享变量表
    //（PHP 包含作用域语义：被包含文件能看到 includer 的变量，其新变量返回后也对
    // includer 可见），对象/数组在帧内为引用传递，与 PHP 变量语义一致。
    // $__seg：当前段附带的跳转表 / 调度表（控制流平坦化、指令重排用），明文模式为 null
    // $__exTab：当前段的异常表（try/catch/finally 分发依据），无则 null
    $__exec = function($__codeArr,&$__vars,$__this,$__init,$__seg=null,$__isG=false,$__exTab=null) use (
        &$__exec,$__P,$__META,$__STN,$__bin,$__inc,$__propGet,$__propSet,$__newObj,$__callFunc,$__callMethod,$__idxGet,$__pathGet,$__toStr,$__instanceof,$__constOwner,$__methodOwner,$__bindArgs,$__bindNative,&$__pad,&$__code,
        $PUSH_C,$PUSH_V,$POP_V,$DUP,$DROP,$LOAD_THIS,$PUSH_NULL,
        $ADD,$SUB,$MUL,$DIV,$MOD,$POW,$CONCAT,$NEG,$BW_NOT,
        $BW_AND,$BW_OR,$BW_XOR,$BW_SHL,$BW_SHR,
        $EQ,$NE,$LT,$GT,$LE,$GE,$IDENT,$NIDENT,$SPACESHIP,
        $BOOL_AND,$BOOL_OR,$BOOL_NOT,
        $JMP,$JZ,$JNZ,$CALL,$MECALL,$NEW,$PROPGET,$PROPSET,$RET,$RET_VOID,$ECHO,$HALT,
        $ARRAY_MAKE,$ARRAY_KV,$INDEX_GET,$INDEX_SET,$COUNT,$ARRAY_PUSH,
        $PRE_INC,$PRE_DEC,$POST_INC,$POST_DEC,$CALL_VAR,
        $JMP_B,$SET_ST,$DISPATCH,
        $SCALL,$SPROP,$ISSET_,$EMPTY_,$UNSET_,$EXIT_,$EXIT_VOID,$PRINT_,
        $CAST_INT,$CAST_FLOAT,$CAST_STRING,$CAST_BOOL,$CAST_ARRAY,$CAST_OBJECT,
        $PUSH_GLOBALS,$GLOBALS_SET,$PHPRUN,$GLOBAL_REF,$DEFINE,$CONST_FETCH,
        $INCLUDE_EMBED,$INCLUDE_RT,$EVAL_STMT,$CALL_NATIVE,$PUSH_REF,$SCALL_VAR,$INSTANCEOF,$BOOL_XOR,$THROW,$EX_FINALLY_ENTER,$EX_FINALLY_END,$ARG_SPREAD,$ARG_NAMED,$MAKE_REF,$MAKE_REF_EL,$MAKE_REF_PROP,$MAKE_REF_APPEND,$PUSH_REF_EL,$PROP_POST_INC,$PROP_POST_DEC,$PROP_PRE_INC,$PROP_PRE_DEC,$IDX_POST_INC,$IDX_POST_DEC,$IDX_PRE_INC,$IDX_PRE_DEC,$CALL_RR,$INSTANCEOF_V,$LSB_BIND,$LSB_CLASS,$MECALL_FCC,$SCALL_FCC,$MECALL_FCC_VAR,$SCALL_FCC_VAR,$SCALL_FCC_DYN,$__callFn,$__fcc,&$__GFRAME,&$__INCTRACK,&$__calledCls
    ){
        $__stack = $__init;
        $__pc = 0;
        $__n = count($__codeArr);
        $__ret = null; $__retSet = false;
        // 异常控制状态（每帧独立）：pending=待重抛异常；inFinally=正处于 finally 块；
        // ctl/retv 由编译器写入帧变量（\x01ctl / \x01retv），EX_FINALLY_END 读回。
        $__pending = null; $__inFinally = false; $__ctl = 0; $__retv = null;
        $__loop = true;
        while ($__loop) {
         try {
            while ($__pc < $__n) {
            $__ins = $__codeArr[$__pc++];
            $__op = $__ins[0];
            switch ($__op) {
                case $PUSH_C:   $__stack[] = $__ins[1]; break;
                case $PUSH_V:   $__stack[] = $__vars[$__ins[1]] ?? null; break;
                case $PUSH_REF: $__stack[] = ['__vmref__', $__ins[1], $__vars[$__ins[1]] ?? null]; break;
                case $PUSH_REF_EL: $__stack[] = ['__vmrefel__', $__ins[1]]; break;
                case $POP_V:    $__vars[$__ins[1]] = array_pop($__stack); break;
                case $DUP:      $__stack[] = end($__stack); break;
                case $DROP:     array_pop($__stack); break;
                case $LOAD_THIS:$__stack[] = $__this; break;
                case $PUSH_NULL:$__stack[] = null; break;
                case $ADD: case $SUB: case $MUL: case $DIV: case $MOD: case $POW:
                case $CONCAT: case $BW_AND: case $BW_OR: case $BW_XOR: case $BW_SHL: case $BW_SHR:
                case $EQ: case $NE: case $LT: case $GT: case $LE: case $GE:
                case $IDENT: case $NIDENT: case $SPACESHIP: case $BOOL_AND: case $BOOL_OR:
                    $__b = array_pop($__stack); $__a = array_pop($__stack);
                    $__stack[] = $__bin($__op,$__a,$__b);
                    break;
                case $NEG: $__stack[] = -array_pop($__stack); break;
                case $BW_NOT: $__stack[] = ~array_pop($__stack); break;
                case $BOOL_NOT: $__stack[] = !array_pop($__stack); break;
                case $BOOL_XOR:
                    // xor 非短路：两侧均求值后做布尔异或（结果恒为 bool，与 PHP 一致）
                    $__b = array_pop($__stack); $__a = array_pop($__stack);
                    $__stack[] = ((bool)$__a) !== ((bool)$__b);
                    break;
                case $JMP: $__pc += $__ins[1]; break;
                case $JZ: $__v = array_pop($__stack); if (!$__v) { $__pc += $__ins[1]; } break;
                case $JNZ: $__v = array_pop($__stack); if ($__v) { $__pc += $__ins[1]; } break;
                case $JMP_B:
                    // 经跳转表做绝对跳转（指令重排后，块内偏移不再可信）
                    $__pc = isset($__seg['jt'][$__ins[1]]) ? $__seg['jt'][$__ins[1]] : $__n;
                    break;
                case $SET_ST:
                    $__vars[$__STN] = $__ins[1]; break;
                case $DISPATCH:
                    // 控制流平坦化：按状态寄存器经调度表间接跳转，无匹配则结束本段
                    $__st = $__vars[$__STN] ?? -1;
                    if (!isset($__seg['dt'][$__st])) { break 2; }
                    $__pc = $__seg['dt'][$__st];
                    break;
                case $CALL:
                    $__argc = $__ins[2]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                    // 传调用方帧变量表：by-ref 实参据此 copy-in / copy-out
                    $__stack[] = $__callFunc($__ins[1], $__a, $__vars);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $CALL_RR:
                    // 引用返回函数调用：$dst = &g(args)。弹参 → 经 callFunc
                    // 第 4 参（目标帧槽名）把桥接槽 "\x01rr" 的引用按元素级
                    // 写回本帧槽——PHP: $r=&g() 后 $r=9 改的是 g 内的 $s。
                    // 桥接回写在 callFunc 内完成（元素级赋值才穿透帧表），
                    // 此处不再经本地变量中转（变量级重绑不传播 + 引用集污染）。
                    $__argc = $__ins[3]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                    $__callFunc($__ins[1], $__a, $__vars, $__ins[2]);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $CALL_VAR:
                    $__argc = $__ins[1]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    $__fn = array_pop($__stack);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                    // 变量函数：值可能为非字符串可调用体（闭包/数组 callable，如
                    // EVAL_STMT 回退段写回的闭包）——直接调用，避免 (string) 化致命
                    if (!is_string($__fn) && is_callable($__fn)) {
                        [$__a,] = $__bindArgs($__a, null, $__vars);
                        $__stack[] = call_user_func_array($__fn, $__a);
                    } else {
                        $__stack[] = $__callFunc((string)$__fn, $__a, $__vars);
                    }
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $MECALL:
                    // 栈布局（底→顶）：[对象, 参数..., 方法名]
                    $__argc = $__ins[1]; $__a = [];
                    $__m = array_pop($__stack); // 方法名（栈顶）
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    $__obj = array_pop($__stack);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                    // 传调用方帧变量表：by-ref 实参据此 copy-in / copy-out
                    $__stack[] = $__callMethod($__obj, (string)$__m, $__a, $__vars);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $MECALL_FCC:
                    // FCC：$o->m(...)（PHP 8.1+ 一等可调用）——弹对象压闭包。
                    // 创建期校验（方法不存在/接收者为 null）与原生一致。
                    $__obj = array_pop($__stack);
                    $__stack[] = $__fcc([$__obj, $__ins[1]]);
                    break;
                case $MECALL_FCC_VAR:
                    // FCC：$o->$m(...)——栈[对象, 方法名]（方法名在下压入序之后）
                    $__m = array_pop($__stack);
                    $__obj = array_pop($__stack);
                    $__stack[] = $__fcc([$__obj, (string)$__m]);
                    break;
                case $SCALL:
                    // 静态 / 类方法调用：C::m(args)（方法沿继承链查找）
                    $__argc = $__ins[3]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    $__cls = $__ins[1]; $__m = $__ins[2];
                    // LSB / 转发标记：static:: → 解析类=called 栈顶（栈空回退 FB）；
                    // self::/parent:: 方法转发 → 查找类=FB，called class 保持栈顶。
                    $__fwd = false;
                    if (is_string($__cls) && strncmp($__cls, "\x00lsb:", 5) === 0) {
                        $__cls = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : substr($__cls, 5);
                    } elseif (is_string($__cls) && strncmp($__cls, "\x00fwd:", 5) === 0) {
                        $__fwd = true; $__cls = substr($__cls, 5);
                    }
                    $__mo = $__methodOwner($__cls, $__m);
                    if ($__mo !== null) {
                        if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                        $__mc = $__P['classes'][$__mo]['methods'][$__m];
                        if (is_array($__mc) && isset($__mc[0]) && is_array($__mc[0])) {
                            $__mcode = $__mc;
                        } else {
                            if (!isset($__mc['__c'])) { $__mc['__c'] = __VMSEG__($__mc)[0]; $__P['classes'][$__mo]['methods'][$__m] = $__mc; }
                            $__mcode = $__mc['__c'];
                        }
                        // 实参绑定：命名重排 + 引用 copy-in/copy-out（同 $__callFunc）
                        $__spec = $__P['classParams'][$__mo][$__m] ?? [];
                        [$__a,$__refs] = $__bindArgs($__a,$__spec,$__vars);
                        // $this 转发：PHP 语义——实例方法上下文内的 static::/self::/
                        // parent::/显式类名 调用非静态方法均转发 $this（实测确认，
                        // 四种形态一致）。静态方法不使用 $this（合法 PHP 不会在静态
                        // 方法内引用 $this），置入亦无害；无实例上下文时为 null。
                        $__ev = ($__this !== null) ? ['$this' => $__this] : [];
                        // called class 入栈：显式类名调用压该类自身；转发调用
                        // （self::/parent::/static::）保持当前 called class。
                        $__calledCls[] = $__fwd
                            ? (($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : $__cls)
                            : $__cls;
                        try {
                            $__r = $__exec($__mcode, $__ev, $__this, $__pad($__spec, $__a), null, false, $__P['classET'][$__mo][$__m] ?? null);
                        } finally {
                            array_pop($__calledCls);
                        }
                        foreach ($__refs as $__i => $__mm) {
                            if (array_key_exists($__mm[1], $__ev)) { $__vars[$__mm[0]] = $__ev[$__mm[1]]; }
                        }
                        $__stack[] = $__r;
                        if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    } elseif (is_string($__cls) && class_exists($__cls) && method_exists($__cls, $__m)) {
                        // 原生类静态方法：同样需展平/去标记（同 SCALL_VAR / CALL_VAR 原生路径）
                        [$__a,] = $__bindArgs($__a, null, $__vars);
                        $__stack[] = call_user_func_array([$__cls, $__m], $__a);
                    } else {
                        // __callStatic 魔术分发（PHP：调用不存在的静态方法时触发）。
                        // 仅当魔术方法存在才走——否则维持既有 null 语义。
                        $__cso = $__methodOwner($__cls, '__callStatic');
                        if ($__cso !== null) {
                            $__csspec = $__P['classParams'][$__cso]['__callStatic'] ?? [];
                            $__csargs = $__pad($__csspec, [$__m, $__a]);
                            $__calledCls[] = $__cls;
                            try {
                                $__stack[] = $__exec($__P['classes'][$__cso]['methods']['__callStatic'],[],null,$__csargs,null,false,$__P['classET'][$__cso]['__callStatic'] ?? null);
                            } finally {
                                array_pop($__calledCls);
                            }
                        } else {
                            $__stack[] = null;
                        }
                    }
                    break;
                case $SCALL_VAR:
                    // 动态方法名的静态调用：C::$m() / self::$m()——方法名先压栈。
                    // 与 SCALL 同款分发，仅方法名来自运行期变量值。
                    $__argc = $__ins[2]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    $__cls = $__ins[1]; $__m = array_pop($__stack);
                    // LSB / 转发标记（同 SCALL）：static::$m() / self::$m()
                    $__fwd = false;
                    if (is_string($__cls) && strncmp($__cls, "\x00lsb:", 5) === 0) {
                        $__cls = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : substr($__cls, 5);
                    } elseif (is_string($__cls) && strncmp($__cls, "\x00fwd:", 5) === 0) {
                        $__fwd = true; $__cls = substr($__cls, 5);
                    }
                    $__mo = is_string($__m) ? $__methodOwner($__cls, $__m) : null;
                    if ($__mo !== null) {
                        if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                        $__mc = $__P['classes'][$__mo]['methods'][$__m];
                        if (is_array($__mc) && isset($__mc[0]) && is_array($__mc[0])) {
                            $__mcode = $__mc;
                        } else {
                            if (!isset($__mc['__c'])) { $__mc['__c'] = __VMSEG__($__mc)[0]; $__P['classes'][$__mo]['methods'][$__m] = $__mc; }
                            $__mcode = $__mc['__c'];
                        }
                        // 实参绑定：命名重排 + 引用 copy-in/copy-out（同 SCALL）
                        $__spec = $__P['classParams'][$__mo][$__m] ?? [];
                        [$__a,$__refs] = $__bindArgs($__a,$__spec,$__vars);
                        // $this 转发：同 SCALL——实例上下文内的静态形态调用转发 $this
                        $__ev = ($__this !== null) ? ['$this' => $__this] : [];
                        $__calledCls[] = $__fwd
                            ? (($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : $__cls)
                            : $__cls;
                        try {
                            $__r = $__exec($__mcode, $__ev, $__this, $__pad($__spec, $__a), null, false, $__P['classET'][$__mo][$__m] ?? null);
                        } finally {
                            array_pop($__calledCls);
                        }
                        foreach ($__refs as $__i => $__mm) {
                            if (array_key_exists($__mm[1], $__ev)) { $__vars[$__mm[0]] = $__ev[$__mm[1]]; }
                        }
                        $__stack[] = $__r;
                        if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    } elseif (is_string($__cls) && is_string($__m) && class_exists($__cls) && method_exists($__cls, $__m)) {
                        [$__a,] = $__bindArgs($__a, null, $__vars);
                        $__stack[] = call_user_func_array([$__cls, $__m], $__a);
                    } else {
                        // __callStatic 魔术分发（同 SCALL）：仅当魔术方法存在才走
                        $__cso = is_string($__cls) ? $__methodOwner($__cls, '__callStatic') : null;
                        if ($__cso !== null && is_string($__m)) {
                            $__csspec = $__P['classParams'][$__cso]['__callStatic'] ?? [];
                            $__csargs = $__pad($__csspec, [$__m, $__a]);
                            $__calledCls[] = $__cls;
                            try {
                                $__stack[] = $__exec($__P['classes'][$__cso]['methods']['__callStatic'],[],null,$__csargs,null,false,$__P['classET'][$__cso]['__callStatic'] ?? null);
                            } finally {
                                array_pop($__calledCls);
                            }
                        } else {
                            $__stack[] = null;
                        }
                    }
                    break;
                case $SCALL_FCC:
                    // FCC：C::m(...) / self::m(...) / parent::m(...) / static::m(...)
                    // （PHP 8.1+ 类名一等可调用）。类操作数可带 LSB/fwd 标记——
                    // FCC 创建期即解析（static:: → called 栈顶；self::/parent:: →
                    // 标记内原始类名），解析后交 $__fcc 建闭包（创建期校验）。
                    $__cls = $__ins[1];
                    if (is_string($__cls) && strncmp($__cls, "\x00lsb:", 5) === 0) {
                        $__cls = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : substr($__cls, 5);
                    } elseif (is_string($__cls) && strncmp($__cls, "\x00fwd:", 5) === 0) {
                        $__cls = substr($__cls, 5);
                    }
                    $__stack[] = $__fcc($__cls . '::' . $__ins[2]);
                    break;
                case $SCALL_FCC_VAR:
                    // FCC：C::$m(...)（动态方法名，类名为操作数）——栈[方法名]
                    $__m = array_pop($__stack);
                    $__cls = $__ins[1];
                    if (is_string($__cls) && strncmp($__cls, "\x00lsb:", 5) === 0) {
                        $__cls = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : substr($__cls, 5);
                    } elseif (is_string($__cls) && strncmp($__cls, "\x00fwd:", 5) === 0) {
                        $__cls = substr($__cls, 5);
                    }
                    $__stack[] = $__fcc($__cls . '::' . (string)$__m);
                    break;
                case $SCALL_FCC_DYN:
                    // FCC：$cls::m(...) / $cls::$m(...)（类名与方法名均为运行期值）
                    // 栈[类名, 方法名]——类名在下先压入。
                    $__m = array_pop($__stack);
                    $__cls = array_pop($__stack);
                    $__stack[] = $__fcc((is_string($__cls) ? $__cls : '') . '::' . (string)$__m);
                    break;
                case $INSTANCEOF:
                    // 类型判定：$v instanceof C（C 为编译期常量类名）
                    $__stack[] = $__instanceof(array_pop($__stack), $__ins[1]);
                    break;
                case $INSTANCEOF_V:
                    // 动态类名 instanceof：$o instanceof $n（右值运行期求值）。
                    // 对象右值取其类名（PHP 语义）；非字符串/对象抛 TypeError。
                    $__cls = array_pop($__stack);
                    if (is_object($__cls)) { $__cls = get_class($__cls); }
                    elseif (!is_string($__cls)) { throw new \TypeError('instanceof expects a string or an object'); }
                    $__stack[] = $__instanceof(array_pop($__stack), $__cls);
                    break;
                case $LSB_BIND:
                    // static::$p：按 called class 解析声明该属性的类（沿继承链向上
                    // 找已初始化槽位——无默认值的静态属性也已在预导段建槽），把别名
                    // 帧槽 "\x01lsb:prop" 绑为该共享帧槽位的引用；后续 PUSH_V/POP_V/
                    // INDEX_SET 全部走编译期常量别名，避开运行期类名。
                    $__c = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : '';
                    $__mn = "\x01sp:" . $__c . ":" . $__ins[1];
                    $__g = 0;
                    while (!array_key_exists($__mn, $__GFRAME) && $__g++ < 64) {
                        $__nx = $__P['classParents'][$__c] ?? null;
                        if (!is_string($__nx) || $__nx === '') { break; }
                        $__c = $__nx; $__mn = "\x01sp:" . $__c . ":" . $__ins[1];
                    }
                    if (!array_key_exists($__mn, $__GFRAME)) { $__GFRAME[$__mn] = null; }
                    $__vars["\x01lsb:" . $__ins[1]] = &$__GFRAME[$__mn];
                    break;
                case $LSB_CLASS:
                    // static::class：压入当前 called 类名（PHP: 运行期实际类）
                    $__stack[] = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : '';
                    break;
                case $SPROP:
                    // 类常量 / 静态属性：C::CONST 或 C::$p
                    $__cls = $__ins[1]; $__p = $__ins[2];
                    // static::CONST：LSB 标记 → 按 called class 解析（实测 PHP 对
                    // 常量同样做延迟静态绑定；self::/parent:: 则编译期解析）。
                    if (is_string($__cls) && strncmp($__cls, "\x00lsb:", 5) === 0) {
                        $__cls = ($__calledCls !== []) ? $__calledCls[count($__calledCls)-1] : substr($__cls, 5);
                    }
                    // VM 类常量（含 implements/extends 继承链，PHP 继承语义）
                    $__co = $__constOwner($__cls, $__p);
                    if ($__co !== null) {
                        // VM 类的类常量：值段惰性求值
                        $__cs = $__P['classes'][$__co]['consts'][$__p];
                        $__ev = [];
                        $__stack[] = ($__cs === null) ? null : $__exec($__code($__cs), $__ev, null, [], null);
                    } elseif (defined($__cls . '::' . $__p)) {
                        $__stack[] = constant($__cls . '::' . $__p);
                    } elseif (is_string($__cls) && class_exists($__cls)) {
                        $__rp = new \ReflectionClass($__cls);
                        $__props = $__rp->getStaticProperties();
                        $__pn = ltrim((string)$__p, '$');
                        $__stack[] = array_key_exists($__pn, $__props) ? $__props[$__pn] : null;
                    } else {
                        $__stack[] = null;
                    }
                    break;
                case $ISSET_: case $EMPTY_: case $UNSET_:
                    $__nk = $__ins[2]; $__keys = [];
                    for ($i=0;$i<$__nk;$i++) { $__keys[] = array_pop($__stack); }
                    $__keys = array_reverse($__keys);
                    if ($__op === $UNSET_) {
                        if ($__ins[1] === '$GLOBALS') {
                            if ($__nk === 1) { unset($GLOBALS[$__keys[0]]); }
                            break;
                        }
                        if ($__nk === 0) { unset($__vars[$__ins[1]]); }
                        else {
                            $__ref = &$__vars[$__ins[1]]; $__ok = true;
                            for ($i=0;$i<$__nk-1;$i++) {
                                $__k = $__keys[$i];
                                if (is_array($__ref) && isset($__ref[$__k])) { $__ref = &$__ref[$__k]; }
                                elseif (is_object($__ref)) { $__ref = &$__ref->$__k; }
                                else { $__ok = false; break; }
                            }
                            if ($__ok) {
                                $__lk = $__keys[$__nk-1];
                                if (is_array($__ref)) { unset($__ref[$__lk]); }
                                elseif (is_object($__ref)) { unset($__ref->$__lk); }
                            }
                        }
                        break;
                    }
                    $__found = false;
                    $__v = $__pathGet($__vars, $__ins[1], $__keys, $__found);
                    if ($__op === $ISSET_) { $__stack[] = $__found && $__v !== null; }
                    else { $__stack[] = (!$__found) || $__v == false; }
                    break;
                case $NEW:
                    $__argc = $__ins[2]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    // 不再于此展平：$__newObj → $__bindArgs 内部已做（且 $__flatten 不在本闭包
                    // use 列表中，直接调用会是未定义变量）。解包标记原样传入即可。
                    $__a = array_reverse($__a);
                    $__cls = $__ins[1];
                    if ($__cls === '__VAR__') { $__cls = array_pop($__stack); }
                    $__stack[] = $__newObj((string)$__cls, $__a);
                    break;
                case $PROPGET:
                    $__o = array_pop($__stack);
                    $__stack[] = $__propGet($__o, $__ins[1]);
                    break;
                case $PROPSET:
                    $__v = array_pop($__stack);
                    $__o = array_pop($__stack);
                    $__propSet($__o, $__ins[1], $__v);
                    break;
                case $RET:
                    $__ret = array_pop($__stack); $__retSet = true; break 2;
                case $RET_VOID:
                    $__ret = null; $__retSet = true; break 2;
                case $THROW:
                    // 弹栈顶异常对象并抛出；交由外层 try/catch 包装做异常表分发
                    $__th = array_pop($__stack);
                    throw $__th;
                case $ARG_SPREAD:
                    // 解包实参：弹栈顶数组，压入解包标记（由随后 CALL/MECALL/SCALL 展平）
                    $__sp = array_pop($__stack);
                    if ($__sp instanceof \Traversable) { $__sp = iterator_to_array($__sp); }
                    $__stack[] = ['__vmspread__', is_array($__sp) ? $__sp :
                        (is_object($__sp) ? get_object_vars($__sp) : (array) $__sp)];
                    break;
                case $ARG_NAMED:
                    // 命名实参：弹栈顶值，压入命名标记 ['__vmnamed__', 形参名, 值]
                    // （由随后 CALL/MECALL/SCALL 按被调方参数规格重排为位置实参）
                    $__nv = array_pop($__stack);
                    $__stack[] = ['__vmnamed__', $__ins[1], $__nv];
                    break;
                case $MAKE_REF:
                    // 引用赋值（整变量）：$b = &$a —— 帧槽位别名。
                    // PHP 原生引用语义：此后对任一槽位的读写/自增/复合赋值互相穿透。
                    $__dn = $__ins[1]; $__sn = $__ins[2];
                    if (!array_key_exists($__sn, $__vars)) { $__vars[$__sn] = null; }
                    $__vars[$__dn] = &$__vars[$__sn];
                    break;
                case $MAKE_REF_EL:
                    // 引用赋值（数组元素）：$b = &$a[$k] —— 键弹栈，别名到元素。
                    // 链式形态（$b = &$a[0][0]）由编译期拆为多级：先别名为临时槽，
                    // 再别名为临时槽的元素（PHP 引用传递保证最终指向真实元素）。
                    $__dn = $__ins[1]; $__bn = $__ins[2];
                    $__k = array_pop($__stack);
                    if (!array_key_exists($__bn, $__vars) || !is_array($__vars[$__bn])) {
                        $__vars[$__bn] = []; // PHP 自动补全数组（访问 null 上的偏移量）
                    }
                    if (!array_key_exists($__k, $__vars[$__bn])) { $__vars[$__bn][$__k] = null; }
                    $__vars[$__dn] = &$__vars[$__bn][$__k];
                    break;
                case $MAKE_REF_PROP:
                    // 引用赋值（对象属性）：$b = &$this->p —— 对象弹栈，别名到属性。
                    $__dn = $__ins[1]; $__pn = $__ins[2];
                    $__o = array_pop($__stack);
                    if (!is_object($__o)) { break; }
                    if (!isset($__o->$__pn)) { $__o->$__pn = null; }
                    $__vars[$__dn] = &$__o->$__pn;
                    break;
                case $MAKE_REF_APPEND:
                    // 引用赋值（追加元素）：$b = &$a[] —— 追加一个 null 元素并别名到它
                    // （PHP 语义：追加键为下一整数键，写入 $b 即写穿数组）。
                    $__dn = $__ins[1]; $__bn = $__ins[2];
                    if (!array_key_exists($__bn, $__vars) || !is_array($__vars[$__bn])) {
                        $__vars[$__bn] = [];
                    }
                    $__vars[$__bn][] = null;
                    end($__vars[$__bn]);
                    $__ak = key($__vars[$__bn]);
                    $__vars[$__dn] = &$__vars[$__bn][$__ak];
                    break;
                case $EX_FINALLY_ENTER:
                    // finally 块起始：置位标志，使块内再抛出的异常不再被本帧异常表捕获
                    $__inFinally = true;
                    break;
                case $EX_FINALLY_END:
                    // finally 收尾：依据 pending（待重抛）与 ctl（延后返回）收束
                    $__inFinally = false;
                    if ($__pending !== null) {
                        $__pe = $__pending; $__pending = null;
                        throw $__pe;
                    }
                    $__c = $__vars["\x01ctl"] ?? 0;
                    if ($__c === 1) {
                        // 带值延后返回
                        $__ret = $__vars["\x01retv"] ?? null;
                        $__retSet = true;
                        break 2;
                    } elseif ($__c === 2) {
                        // void 延后返回
                        $__ret = null; $__retSet = true;
                        break 2;
                    }
                    // ctl===3/4（break/continue 延后）暂未实现，顺序落下
                    break;
                case $ECHO:
                    $__v = array_pop($__stack);
                    echo $__toStr($__v);
                    break;
                case $PRINT_:
                    $__v = array_pop($__stack);
                    echo $__toStr($__v);
                    $__stack[] = 1;
                    break;
                case $PHPRUN:
                    $__argc = $__ins[1]; $__code2 = null;
                    for ($i=0;$i<$__argc;$i++) { $__code2 = array_pop($__stack); }
                    if (is_array($__vars)) { extract($__vars); }
                    eval($__code2);
                    break;
                case $EXIT_:
                    $__v = array_pop($__stack);
                    if (is_int($__v)) { exit($__v); }
                    if (is_string($__v)) { echo $__v; }
                    exit(0);
                case $EXIT_VOID:
                    exit(0);
                case $CAST_INT:
                    $__stack[] = (int)array_pop($__stack); break;
                case $CAST_FLOAT:
                    $__stack[] = (float)array_pop($__stack); break;
                case $CAST_STRING:
                    $__stack[] = $__toStr(array_pop($__stack)); break;
                case $CAST_BOOL:
                    $__stack[] = (bool)array_pop($__stack); break;
                case $CAST_ARRAY:
                    $__stack[] = (array)array_pop($__stack); break;
                case $CAST_OBJECT:
                    $__stack[] = (object)array_pop($__stack); break;
                case $PUSH_GLOBALS:
                    $__stack[] = $GLOBALS; break;
                case $GLOBALS_SET:
                    $v = array_pop($__stack); $k = array_pop($__stack);
                    $GLOBALS[$k] = $v; array_pop($__stack); break;
                case $GLOBAL_REF:
                    // global $x：把帧槽位绑定为共享全局帧的引用（PHP global 语义）
                    $n = $__ins[1];
                    if (!array_key_exists($n, $__GFRAME)) { $__GFRAME[$n] = null; }
                    $__vars[$n] = &$__GFRAME[$n];
                    break;
                case $DEFINE:
                    // const NAME = expr：运行期 define（PHP 常量本就是运行期实体）
                    $v = array_pop($__stack);
                    define($__ins[1], $v);
                    break;
                case $CONST_FETCH:
                    // 引用本文件 const 声明的常量：运行期查表
                    $__stack[] = defined($__ins[1]) ? constant($__ins[1]) : null;
                    break;
                case $INCLUDE_EMBED:
                    // include（构建期内嵌）：嵌入段与当前帧【共享变量表】（PHP 包含作用域语义）。
                    // once=1 且同一 embed 已执行过 → 直接压 true 跳过。
                    $__e = $__ins[1];
                    if ($__ins[2] && isset($__INCTRACK[$__e])) {
                        $__stack[] = true;
                    } else {
                        $__INCTRACK[$__e] = true;
                        $__stack[] = $__exec($__P['embeds'][$__e] ?? [], $__vars, $__this, [], null, $__isG, $__P['embedET'][$__e] ?? null);
                    }
                    break;
                case $INCLUDE_RT:
                    // include（运行期真实包含）：路径弹栈；flags bit0=once bit1=require bit2=@抑制。
                    // 经"帧变量表 ⇄ 真实局部变量"双向桥接保住作用域：先把帧变量导出为
                    // 真实局部变量（被包含文件可见），快照后真实 include/require，再把
                    // 新增/被修改的变量差分捕获回帧表（get_defined_vars 键名无 $ 前缀）。
                    $__fl = $__ins[1];
                    $__ipth = (string)array_pop($__stack);
                    $__rp = realpath($__ipth);
                    if ($__rp === false && !empty($__P['srcBase'])) {
                        $__cand = rtrim((string)$__P['srcBase'],'/\\') . '/' . $__ipth;
                        if (is_file($__cand)) { $__ipth = $__cand; $__rp = realpath($__cand); }
                    }
                    $__k = 'r' . ($__rp !== false ? $__rp : $__ipth);
                    if (($__fl & 1) && isset($__INCTRACK[$__k])) {
                        $__stack[] = true;
                    } else {
                        $__INCTRACK[$__k] = true;
                        foreach ($__vars as $__ikey => $__ival) {
                            if ($__ikey === '$GLOBALS' || $__ikey === '$this') { continue; }
                            $__inm = substr($__ikey, 1);
                            $$__inm = $__ival;
                        }
                        $__isnap = get_defined_vars();
                        $__iret = ($__fl & 2) ? @require $__ipth : ((($__fl & 4) ? @include $__ipth : include $__ipth));
                        foreach (get_defined_vars() as $__ikey => $__ival) {
                            if ($__ikey === 'this' || !array_key_exists($__ikey, $__isnap) || $__isnap[$__ikey] !== $__ival) {
                                $__vars['$' . $__ikey] = $__ival;
                            }
                        }
                        $__stack[] = $__iret;
                    }
                    break;
                case $HALT:
                    $__retSet = true; $__ret = null; break 2;
                case $EVAL_STMT:
                    // 语句级回退：源码串弹栈，帧变量导出为真实局部变量 → 快照 →
                    // eval → 差分写回（与 INCLUDE_RT 桥接同构）。
                    // 导出为【引用绑定】（$$name = &帧槽）而非值拷贝：eval 内对
                    // 局部变量的写直接穿透帧槽，闭包 use(&$n) 捕获的引用链也直达
                    // 帧槽——PHP 原生语义即如此（执行到一半的写在异常时同样生效）。
                    // diff 只处理 eval 新建的变量（帧槽原本不存在）：帧槽绑定到
                    // 该局部变量的引用（后续 POP_V 赋值沿引用写穿）；已导出帧槽
                    // 的写已穿透，diff 若再写回反而会掐断引用链，故跳过。
                    $__es = array_pop($__stack);
                    $__eobj = (isset($__vars['$this']) && is_object($__vars['$this'])) ? $__vars['$this'] : null;
                    if ($__eobj !== null) {
                        // 类方法内的回退：eval 必须继承对象上下文（裸 eval 报
                        // "Using $this when not in object context"）。PHP 禁止
                        // eval 内重写 $this，但闭包绑定的对象上下文可被 eval
                        // 继承——用 Closure::bind 把桥接逻辑绑定到当前对象后
                        // 执行（VM 类运行期为 stdClass，内部类不可作 bind 的
                        // 作用域参数，故用两参形式；作用域仅影响 private 访问，
                        // VM 类属性均为动态公有属性，不受影响）。帧变量表以
                        // use 引用捕获（闭包内导出/写回直达外层帧表）。
                        Closure::bind(function () use ($__es, &$__vars) {
                            foreach ($__vars as $__ikey => $__ival) {
                                if ($__ikey === '$GLOBALS' || $__ikey === '$this') { continue; }
                                $__inm = substr($__ikey, 1);
                                $$__inm = &$__vars[$__ikey];
                            }
                            $__isnap = get_defined_vars();
                            eval($__es);
                            foreach (get_defined_vars() as $__ikey => $__ival) {
                                // this 由绑定继承；es/isnap 为桥接内部名
                                if ($__ikey === 'this' || $__ikey === '__es' || $__ikey === '__isnap') { continue; }
                                if (array_key_exists($__ikey, $__isnap)) { continue; } // 已穿透
                                $__vars['$' . $__ikey] = &$$__ikey;
                            }
                        }, $__eobj)();
                        break;
                    }
                    foreach ($__vars as $__ikey => $__ival) {
                        if ($__ikey === '$GLOBALS' || $__ikey === '$this') { continue; }
                        $__inm = substr($__ikey, 1);
                        $$__inm = &$__vars[$__ikey];
                    }
                    $__isnap = get_defined_vars();
                    eval($__es);
                    foreach (get_defined_vars() as $__ikey => $__ival) {
                        if ($__ikey === 'this' || $__ikey === '__es' || $__ikey === '__isnap') { continue; }
                        if (array_key_exists($__ikey, $__isnap)) { continue; } // 已穿透
                        $__vars['$' . $__ikey] = &$$__ikey;
                    }
                    break;
                case $CALL_NATIVE:
                    // 原生函数间接调用：nativeIdx 索引 natTbl（静态不暴露函数名）
                    $__argc = $__ins[2]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    // 实参绑定：展平解包 + 命名重排 + refMask 位真实引用绑定
                    $__a = $__bindNative($__a, $__ins[3] ?? 0, $__vars);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                    $__stack[] = $__callFn((int) $__ins[1], $__a);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $ARRAY_MAKE:
                    $__c = $__ins[1]; $__a = [];
                    for ($i=0;$i<$__c;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    // 引用元素句柄（[&$x]）：构造为真实数组元素引用，写穿目标存储
                    foreach ($__a as $i => $__v) {
                        if (is_array($__v) && ($__v[0] ?? '') === '__vmrefel__') {
                            $__rn = (string) $__v[1];
                            if (!array_key_exists($__rn, $__vars)) { $__vars[$__rn] = null; }
                            $__a[$i] = &$__vars[$__rn];
                        }
                    }
                    $__stack[] = $__a;
                    break;
                case $ARRAY_KV:
                    $__c = $__ins[1]; $__a = [];
                    // 编译器顺序：先压 val，再压 key（key 在栈顶）。按元素出栈得 [key,val]，
                    // 由于后入先出，元素顺序与源码相反，故收集后整体 reverse 以保序。
                    $__pairs = [];
                    for ($i=0;$i<$__c;$i++) {
                        $__k = array_pop($__stack);
                        $__v = array_pop($__stack);
                        $__pairs[] = [$__k, $__v];
                    }
                    $__pairs = array_reverse($__pairs);
                    foreach ($__pairs as $__p) {
                        // 引用元素句柄（"k"=>&$x）：构造为真实数组元素引用
                        if (is_array($__p[1]) && ($__p[1][0] ?? '') === '__vmrefel__') {
                            $__rn = (string) $__p[1][1];
                            if (!array_key_exists($__rn, $__vars)) { $__vars[$__rn] = null; }
                            $__a[$__p[0]] = &$__vars[$__rn];
                        } else {
                            $__a[$__p[0]] = $__p[1];
                        }
                    }
                    $__stack[] = $__a;
                    break;
                case $INDEX_GET:
                    $__k = array_pop($__stack);
                    $__arr = array_pop($__stack);
                    $__stack[] = $__idxGet($__arr, $__k);
                    break;
                case $INDEX_SET:
                    $__v = array_pop($__stack);
                    $__k = array_pop($__stack);
                    $__arr = array_pop($__stack);
                    if (!is_array($__arr) && !is_object($__arr)) { $__arr = []; }
                    if (is_array($__arr)) { $__arr[$__k] = $__v; }
                    else { $__arr->$k = $__v; }
                    $__stack[] = $__arr;
                    break;
                case $COUNT:
                    $__arr = array_pop($__stack);
                    $__stack[] = is_countable($__arr) ? count($__arr) : 0;
                    break;
                case $ARRAY_PUSH:
                    $__v = array_pop($__stack);
                    $__arr = array_pop($__stack);
                    if (is_array($__arr)) {
                        $__arr[] = $__v;
                    }
                    $__stack[] = $__arr; // 写回（数组为值类型，本地更新需回存）
                    break;
                case $PRE_INC:
                    // PHP 语义自增（字符串字母进位 / null / bool / 数组 TypeError），
                    // 见 $__inc 定义处注释。帧变量若经 GLOBAL_REF 绑为共享帧引用
                    // （类静态属性），写操作自动穿透。
                    $__cur = $__inc($__vars[$__ins[1]] ?? null, 1);
                    $__vars[$__ins[1]] = $__cur; $__stack[] = $__cur;
                    break;
                case $PRE_DEC:
                    $__cur = $__inc($__vars[$__ins[1]] ?? null, -1);
                    $__vars[$__ins[1]] = $__cur; $__stack[] = $__cur;
                    break;
                case $POST_INC:
                    $__old = $__vars[$__ins[1]] ?? null;
                    $__vars[$__ins[1]] = $__inc($__old, 1); $__stack[] = $__old;
                    break;
                case $POST_DEC:
                    $__old = $__vars[$__ins[1]] ?? null;
                    $__vars[$__ins[1]] = $__inc($__old, -1); $__stack[] = $__old;
                    break;
                // —— 属性/数组元素的自增自减（$o->p++ / ++$a[$k] 等）——
                case $PROP_POST_INC: case $PROP_POST_DEC:
                    // 栈 [obj, old]（PROPGET 刚执行）→ 弹 [old, obj]，原地写穿属性，压回旧值
                    $__d = ($__op === $PROP_POST_DEC) ? -1 : 1;
                    $__old = array_pop($__stack); $__o = array_pop($__stack);
                    $__propSet($__o, $__ins[1], $__inc($__old, $__d));
                    $__stack[] = $__old;
                    break;
                case $PROP_PRE_INC: case $PROP_PRE_DEC:
                    // 栈 [obj, old] → 弹 [old, obj]，原地写穿属性，压回新值
                    $__d = ($__op === $PROP_PRE_DEC) ? -1 : 1;
                    $__old = array_pop($__stack); $__o = array_pop($__stack);
                    $__new = $__inc($__old, $__d);
                    $__propSet($__o, $__ins[1], $__new);
                    $__stack[] = $__new;
                    break;
                case $IDX_POST_INC: case $IDX_POST_DEC: case $IDX_PRE_INC: case $IDX_PRE_DEC:
                    // 栈 [arr, key]（索引表达式刚求值）→ 弹 [key, arr]，自取旧值、
                    // 原地更新 arr[key]。操作数（可选）为基变量帧槽名：非空时把
                    // 更新后的容器写回帧变量（PHP 间接修改语义：$a[$k]++ 改变 $a
                    // 本身）。压结果值：POST 压旧值、PRE 压新值。
                    $__d = ($__op === $IDX_POST_DEC || $__op === $IDX_PRE_DEC) ? -1 : 1;
                    $__k = array_pop($__stack); $__a = array_pop($__stack);
                    if (is_array($__a)) {
                        $__old = $__idxGet($__a, $__k);
                        $__new = $__inc($__old, $__d);
                        $__a[$__k] = $__new;
                    } elseif (is_object($__a)) {
                        $__old = $__propGet($__a, $__k);
                        $__new = $__inc($__old, $__d);
                        $__propSet($__a, $__k, $__new);
                    } else {
                        $__old = null;
                        $__new = $__inc(null, $__d);
                    }
                    $__fn = $__ins[1] ?? '';
                    if ($__fn !== '') { $__vars[$__fn] = $__a; }
                    $__stack[] = ($__op === $IDX_POST_INC || $__op === $IDX_POST_DEC) ? $__old : $__new;
                    break;
                default:
                    break 2; // 未知 opcode：终止
            }
            }
            $__loop = false; // 内层 while 正常结束（pc 越界）
        } catch (\Throwable $__E) {
            // —— 异常分发：某条 opcode 在运行期抛出（除法错 / 调用用户码 / THROW 等）——
            // 此处捕获后，按异常表找覆盖当前 pc 的处理器：先匹配 catch（类型 instanceof），
            // 无匹配则取最内层 finally（置 pending 后入 finally）；都没有则原样重抛给调用方。
            if ($__inFinally) {
                // finally 内部再抛出：不再被本帧异常表捕获，直接穿透到外层 / 调用方
                throw $__E;
            }
            $__pt = $__pc - 1; // 抛出指令的下标（执行前 $__pc 已自增）
            $__handled = false;
            if ($__exTab !== null) {
                // 选覆盖 $__pt 的【最内层】处理器 —— 区间越小越内层。
                // 这与 PHP 语义精确对应：
                //   单层 try/catch/finally：catch 区间 [tryStart,tryEnd) 比 finally 区间
                //     [tryStart,finallyPc) 小，故 catch 先命中（PHP 输出顺序为 catch → finally）；
                //   内层 try/finally 嵌在外层 try/catch：内层 finally 区间最小，故外层 catch
                //     不得抢先，须先跑内层 finally，再由 EX_FINALLY_END 重抛给外层 catch。
                // 若按顺序扫描（先 catch 后 finally）则会让外层 catch 抢先、吞掉内层 finally。
                $__h = null; $__hs = PHP_INT_MAX;
                foreach ($__exTab as $__e) {
                    if ($__e[0] <= $__pt && $__pt < $__e[1]) {
                        $__sz = $__e[1] - $__e[0];
                        // finally 恒适用；catch 还需类型匹配（'' 表任意 Throwable）
                        $__ok = ($__e[3] === 'finally')
                            || ($__e[4] === '' || $__E instanceof $__e[4]);
                        if ($__ok && $__sz < $__hs) { $__hs = $__sz; $__h = $__e; }
                    }
                }
                if ($__h !== null) {
                    if ($__h[3] === 'finally') {
                        // 先跑 finally：pending 记下异常，finally 末尾 EX_FINALLY_END 重抛，
                        // 由【外层】 handler（如外层 try 的 catch）接管。
                        $__pending = $__E;
                        $__pc = $__h[2];
                    } else {
                        if ($__h[5] !== '') { $__vars[$__h[5]] = $__E; }
                        $__pc = $__h[2];
                        $__pending = null;
                        $__ctl = 0;
                    }
                    $__handled = true;
                }
            }
            if ($__handled) { continue; } // 重启外层 while → 以新 $__pc 重新进入内层 while
            throw $__E; // 本帧无处理器：重抛，由调用方的 CALL 等 opcode 承接
        }
    }
        return $__retSet ? $__ret : (count($__stack) ? array_pop($__stack) : null);
    };

    $__main = $__code($__P['main']);
    $__mseg = is_array($__P['main']) && isset($__P['main']['jt']) ? ['jt'=>$__P['main']['jt'],'dt'=>isset($__P['main']['dt'])?$__P['main']['dt']:[]] : null;
    // 顶层帧：与产物生成路径一致（引用传帧变量表，PHP 8 禁数组字面量按引用传递，先赋临时变量）
    $__v = [];
    return $__exec($__main, $__v, null, [], $__mseg, true, $__P['mainET'] ?? null);
}

if (!function_exists('__VMSEG__')) {
    /**
     * 段解码占位：明文（数组）模式下不会被调用。
     * 二进制模式由 Serializer 注入的真实实现覆盖（见 VmSerializer::runtimeDecoder()）。
     */
    function __VMSEG__($__raw)
    {
        return [is_array($__raw) && isset($__raw['ins']) ? $__raw['ins'] : [], []];
    }
}

if (!function_exists('__VMBLOB__')) {
    /**
     * 顶层二进制解码占位：明文模式下不会被调用。
     * 二进制模式由 Serializer 注入的真实实现覆盖。
     */
    function __VMBLOB__($__blob)
    {
        return json_decode(gzdecode(base64_decode($__blob)), true);
    }
}
