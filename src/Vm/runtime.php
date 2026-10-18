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
    $BOOL_AND=40;$BOOL_OR=41;$BOOL_NOT=42;
    $JMP=50;$JZ=51;$JNZ=52;$CALL=53;$MECALL=54;$NEW=55;$PROPGET=56;$PROPSET=57;$RET=58;$RET_VOID=59;$ECHO=60;$HALT=61;
    $ARRAY_MAKE=70;$ARRAY_KV=71;$INDEX_GET=72;$INDEX_SET=73;$COUNT=74;$ARRAY_PUSH=75;
    $PRE_INC=80;$PRE_DEC=81;$POST_INC=82;$POST_DEC=83;$CALL_VAR=90;
    $JMP_B=62;$SET_ST=63;$DISPATCH=64;
    $SCALL=91;$SPROP=92;$ISSET_=93;$EMPTY_=94;$UNSET_=95;$EXIT_=96;$EXIT_VOID=97;$PRINT_=98;$PHPRUN=99;
    $CAST_INT=100;$CAST_FLOAT=101;$CAST_STRING=102;$CAST_BOOL=103;$CAST_ARRAY=104;$CAST_OBJECT=105;
    $PUSH_GLOBALS=106;$GLOBALS_SET=107;$GLOBAL_REF=108;$DEFINE=109;$CONST_FETCH=110;
    $INCLUDE_EMBED=111;$INCLUDE_RT=112;$EVAL_STMT=113;$CALL_NATIVE=114;$PUSH_REF=115;

    $__META = "\x01__cls";

    // 共享全局帧：global 声明把帧槽位绑定为这里的引用（PHP global 语义）
    $__GFRAME = [];
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
    $__bin = function($op,$a,$b) use (&$__bin){
        switch($op){
            case 10:return $a+$b;
            case 11:return $a-$b;
            case 12:return $a*$b;
            case 13:return (int)$b===0?0:($a/$b);
            case 14:return (int)$b===0?0:($a%$b);
            case 15:return $a**$b;
            case 16:return (string)$a.(string)$b;
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
    $__pad = function($spec, $args) use (&$__exec, &$__code) {
        $n = count($spec);
        for ($i = count($args); $i < $n; $i++) {
            $d = $spec[$i][1];
            $__ev = [];
            $args[] = ($d === null) ? null : $__exec($__code($d), $__ev, null, [], null);
        }
        return $args;
    };

    // 构造对象
    $__newObj = function($cls,$args) use (&$__exec,$__P,$__META,&$__callMethod,&$__code){
        if (isset($__P['classes'][$cls])) {
            $o = new \stdClass();
            $o->$__META = $cls;
            foreach (($__P['classes'][$cls]['props']??[]) as $pn=>$def) {
                $__ev = [];
                $o->$pn = ($def===null) ? null : $__exec($__code($def), $__ev, null, [], null);
            }
            if (isset($__P['classes'][$cls]['methods']['__construct'])) {
                $__callMethod($o,'__construct',$args);
            }
            return $o;
        }
        // 原生类
        $rc = new \ReflectionClass($cls);
        return $rc->newInstanceArgs($args);
    };

    // VM 回调解析：把"指向 VM 函数/方法的 callable"包装成原生 PHP 闭包，
    // 使 call_user_func / array_map / usort 等原生高阶函数能直接驱动 VM 代码。
    $__vmcb = function($cb) use (&$__exec,$__P,&$__code,&$__callMethod,$__META,&$__pad){
        if (is_string($cb) && isset($__P['funcs'][$cb])) {
            $seg = $__P['funcs'][$cb];
            $sp = $__P['funcParams'][$cb] ?? [];
            return function(...$a) use (&$__exec,&$__code,&$__pad,$seg,$sp) { $__ev=[]; return $__exec($__code($seg),$__ev,null,$__pad($sp,$a),null); };
        }
        if (is_array($cb) && count($cb)===2) {
            $o = $cb[0]; $m = $cb[1];
            if (is_object($o) && isset($o->$__META) && isset($__P['classes'][$o->$__META]['methods'][$m])) {
                return function(...$a) use (&$__callMethod,$o,$m) { return $__callMethod($o,$m,$a); };
            }
            if (is_string($o) && isset($__P['classes'][$o]['methods'][$m])) {
                $seg = $__P['classes'][$o]['methods'][$m];
                $sp = $__P['classParams'][$o][$m] ?? [];
                return function(...$a) use (&$__exec,&$__code,&$__pad,$seg,$sp) { $__ev=[]; return $__exec($__code($seg),$__ev,null,$__pad($sp,$a),null); };
            }
        }
        if (is_string($cb) && strpos($cb,'::')!==false) {
            $p = explode('::',$cb,2);
            if (isset($__P['classes'][$p[0]]['methods'][$p[1]])) {
                $seg = $__P['classes'][$p[0]]['methods'][$p[1]];
                $sp = $__P['classParams'][$p[0]][$p[1]] ?? [];
                return function(...$a) use (&$__exec,&$__code,&$__pad,$seg,$sp) { $__ev=[]; return $__exec($__code($seg),$__ev,null,$__pad($sp,$a),null); };
            }
        }
        return $cb;
    };

    // 调用函数（用户定义或内置）
    $__callFunc = function($name,$args) use (&$__exec,$__P,&$__code,$__vmcb,&$__pad){
        if (isset($__P['funcs'][$name])) {
            $args = $__pad($__P['funcParams'][$name] ?? [],$args);
            $__ev = [];
            return $__exec($__P['funcs'][$name],$__ev,null,$args,null);
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
    $__callFn = function($idx,$args) use ($__P,$__vmcb){
        $nt = $__P['natTbl'] ?? [];
        $name = is_int($idx) && isset($nt[$idx]) ? (string)$nt[$idx] : (string)$idx;
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
    $__callMethod = function($obj,$m,$args) use (&$__exec,$__P,$__META,&$__code,&$__pad){
        if (is_object($obj) && isset($obj->$__META) && isset($__P['classes'][$obj->$__META]['methods'][$m])) {
            $args = $__pad($__P['classParams'][$obj->$__META][$m] ?? [],$args);
            $__ev = ['$this'=>$obj];
            return $__exec($__P['classes'][$obj->$__META]['methods'][$m],$__ev,$obj,$args,null);
        }
        if (is_object($obj) && method_exists($obj,$m)) {
            return call_user_func_array([$obj,$m],$args);
        }
        return null;
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
    $__exec = function($__codeArr,&$__vars,$__this,$__init,$__seg=null,$__isG=false) use (
        &$__exec,$__P,$__META,$__STN,$__bin,$__propGet,$__propSet,$__newObj,$__callFunc,$__callMethod,$__idxGet,$__pathGet,&$__pad,&$__code,
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
        $INCLUDE_EMBED,$INCLUDE_RT,$EVAL_STMT,$CALL_NATIVE,$PUSH_REF,$__callFn,&$__GFRAME,&$__INCTRACK
    ){
        $__stack = $__init;
        $__pc = 0;
        $__n = count($__codeArr);
        $__ret = null; $__retSet = false;
        while ($__pc < $__n) {
            $__ins = $__codeArr[$__pc++];
            $__op = $__ins[0];
            switch ($__op) {
                case $PUSH_C:   $__stack[] = $__ins[1]; break;
                case $PUSH_V:   $__stack[] = $__vars[$__ins[1]] ?? null; break;
                case $PUSH_REF: $__stack[] = ['__vmref__', $__ins[1]]; break;
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
                    $__stack[] = $__callFunc($__ins[1], $__a);
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
                        $__stack[] = call_user_func_array($__fn, $__a);
                    } else {
                        $__stack[] = $__callFunc((string)$__fn, $__a);
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
                    $__stack[] = $__callMethod($__obj, (string)$__m, $__a);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $SCALL:
                    // 静态 / 类方法调用：C::m(args)
                    $__argc = $__ins[3]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    $__cls = $__ins[1]; $__m = $__ins[2];
                    if (isset($__P['classes'][$__cls]['methods'][$__m])) {
                        if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                        $__mc = $__P['classes'][$__cls]['methods'][$__m];
                        if (is_array($__mc) && isset($__mc[0]) && is_array($__mc[0])) {
                            $__mcode = $__mc;
                        } else {
                            if (!isset($__mc['__c'])) { $__mc['__c'] = __VMSEG__($__mc)[0]; $__P['classes'][$__cls]['methods'][$__m] = $__mc; }
                            $__mcode = $__mc['__c'];
                        }
                        $__ev = [];
                        $__stack[] = $__exec($__mcode, $__ev, null, $__pad($__P['classParams'][$__cls][$__m] ?? [], $__a), null);
                        if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    } elseif (is_string($__cls) && class_exists($__cls) && method_exists($__cls, $__m)) {
                        $__stack[] = call_user_func_array([$__cls, $__m], $__a);
                    } else {
                        $__stack[] = null;
                    }
                    break;
                case $SPROP:
                    // 类常量 / 静态属性：C::CONST 或 C::$p
                    $__cls = $__ins[1]; $__p = $__ins[2];
                    if (isset($__P['classes'][$__cls]['consts'][$__p])) {
                        // VM 类的类常量：值段惰性求值
                        $__cs = $__P['classes'][$__cls]['consts'][$__p];
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
                case $ECHO:
                    $__v = array_pop($__stack);
                    if (is_array($__v)) { echo 'Array'; }
                    else { echo $__v; }
                    break;
                case $PRINT_:
                    $__v = array_pop($__stack);
                    if (is_array($__v)) { echo 'Array'; }
                    else { echo $__v; }
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
                    $__stack[] = (string)array_pop($__stack); break;
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
                        $__stack[] = $__exec($__P['embeds'][$__e] ?? [], $__vars, $__this, [], null, $__isG);
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
                    $__es = array_pop($__stack);
                    foreach ($__vars as $__ikey => $__ival) {
                        if ($__ikey === '$GLOBALS' || $__ikey === '$this') { continue; }
                        $__inm = substr($__ikey, 1);
                        $$__inm = $__ival;
                    }
                    $__isnap = get_defined_vars();
                    eval($__es);
                    foreach (get_defined_vars() as $__ikey => $__ival) {
                        if ($__ikey === 'this' || !array_key_exists($__ikey, $__isnap) || $__isnap[$__ikey] !== $__ival) {
                            $__vars['$' . $__ikey] = $__ival;
                        }
                    }
                    break;
                case $CALL_NATIVE:
                    // 原生函数间接调用：nativeIdx 索引 natTbl（静态不暴露函数名）
                    $__argc = $__ins[2]; $__a = [];
                    for ($i=0;$i<$__argc;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
                    // refMask：by-ref 参数位——引用句柄绑定为帧变量真实引用后传入
                    $__rm = $__ins[3] ?? 0;
                    for ($i=0;$i<$__argc;$i++) {
                        if ((($__rm >> $i) & 1) && is_array($__a[$i]) && ($__a[$i][0] ?? '') === '__vmref__') {
                            $__n = $__a[$i][1];
                            if (!array_key_exists($__n, $__vars)) { $__vars[$__n] = null; }
                            $__a[$i] = &$__vars[$__n];
                        }
                    }
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__vars)) { $__GFRAME[$k] = $__vars[$k]; } } }
                    $__stack[] = $__callFn((int) $__ins[1], $__a);
                    if ($__isG && !empty($__P['globals'])) { foreach ($__P['globals'] as $g) { $k='$'.$g; if (array_key_exists($k,$__GFRAME)) { $__vars[$k] = $__GFRAME[$k]; } } }
                    break;
                case $ARRAY_MAKE:
                    $__c = $__ins[1]; $__a = [];
                    for ($i=0;$i<$__c;$i++) { $__a[] = array_pop($__stack); }
                    $__a = array_reverse($__a);
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
                    foreach ($__pairs as $__p) { $__a[$__p[0]] = $__p[1]; }
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
                    $__cur = ($__vars[$__ins[1]] ?? 0) + 1;
                    $__vars[$__ins[1]] = $__cur; $__stack[] = $__cur;
                    break;
                case $PRE_DEC:
                    $__cur = ($__vars[$__ins[1]] ?? 0) - 1;
                    $__vars[$__ins[1]] = $__cur; $__stack[] = $__cur;
                    break;
                case $POST_INC:
                    $__cur = $__vars[$__ins[1]] ?? 0;
                    $__vars[$__ins[1]] = $__cur + 1; $__stack[] = $__cur;
                    break;
                case $POST_DEC:
                    $__cur = $__vars[$__ins[1]] ?? 0;
                    $__vars[$__ins[1]] = $__cur - 1; $__stack[] = $__cur;
                    break;
                default:
                    break 2; // 未知 opcode：终止
            }
        }
        return $__retSet ? $__ret : (count($__stack) ? array_pop($__stack) : null);
    };

    $__main = $__code($__P['main']);
    $__mseg = is_array($__P['main']) && isset($__P['main']['jt']) ? ['jt'=>$__P['main']['jt'],'dt'=>isset($__P['main']['dt'])?$__P['main']['dt']:[]] : null;
    // 顶层帧：与产物生成路径一致（引用传帧变量表，PHP 8 禁数组字面量按引用传递，先赋临时变量）
    $__v = [];
    return $__exec($__main, $__v, null, [], $__mseg, true);
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
