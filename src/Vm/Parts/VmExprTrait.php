<?php
/**
 * VmCompiler 碎片：表达式编译碎片：优先级递降解析 / 赋值与左值 / 调用参数。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性/方法；拆分的只是源码组织，不影响行为。
 */

declare(strict_types=1);

trait VmExprTrait
{

    /**
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileExprOrAssign(array $stmt, array &$code): void
    {
        // 语句级顶层出现 `and`/`or`/`xor` 关键字（优先级低于 `=`）时，整条语句是
        // 一个逻辑关键字表达式而非赋值语句（如 `$a = 1 and $b = 2` ≡
        // `($a = 1) and ($b = 2)`）——交由表达式链按正确优先级解析后丢弃结果。
        // 仅认顶层（非 ()/[]/{} 内部）的关键字，避免把 `($a and $b)` 这类括号内部
        // 的写法误判。
        if ($this->hasTopLevelLogicalKeyword($stmt)) {
            $this->compileExpr($stmt, $code);
            $code[] = [VmOp::DROP];
            return;
        }
        $m = $this->matchAssignStmt($stmt);
        if ($m === null) {
            // 表达式语句：编译表达式后丢弃结果
            $this->compileExpr($stmt, $code);
            $code[] = [VmOp::DROP];
            return;
        }
        $target = $m['span'];
        $compound = $m['compound'];
        $valueTokens = $m['value'];

        if ($compound !== null) {
            // 复合赋值：支持简单变量 $x op= v、实例属性 $o->p op= v（含 $this->p）、
            // 数组元素 $a[$k] op= v 与 VM 类静态属性 C::$p op= v。
            // 栈形态：LVRead 对属性/下标目标会压「两份容器(+键)」，第一份留在旧值
            // 之下，存储指令（PROPSET / INDEX_SET+POP_V）的弹序正好匹配。
            $t0 = $target[0] ?? null;
            $isSimpleVar = is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 1;
            $isProp = is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 3
                && $this->tokType($target[1]) === T_OBJECT_OPERATOR
                && is_array($target[2] ?? null) && $target[2][0] === T_STRING;
            $isIdx = is_array($t0) && $t0[0] === T_VARIABLE && count($target) >= 3 && $target[1] === '[';
            if (!$isSimpleVar && !$isProp && !$isIdx && $this->staticPropLvalueInfo($target) === null) {
                throw new VmUnsupported('VM 暂不支持数组/属性的复合赋值（已回退）');
            }
            $this->compileLVRead($target, $code);
            $this->compileExpr($valueTokens, $code);
            $code[] = [$compound];
            $this->compileLVStore($target, $code);
            return;
        }

        // 引用赋值：$b = &$a / $b = &$a[0][0] / $b = &$this->p / $b = &$a[]
        // （目标限简单变量；链式后缀由 compileRefAssignRHS 多级桥接。
        //  数组元素/属性/静态属性作引用目标时回退原生执行，保正确。）
        $refToks = $this->matchRefAmpPrefix($valueTokens);
        if ($refToks !== null) {
            $t0 = $target[0] ?? null;
            if (!(is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 1)
                || $t0[1] === '$GLOBALS') {
                throw new VmUnsupported('VM 引用赋值目标必须是简单变量（已回退）');
            }
            $this->compileRefAssignRHS(new Cursor($refToks), $this->varName($t0[1]), $code);
            return;
        }

        // 栈约定：先压入"容器"（+可选下标/键），再压入右值，最后发存储指令。
        // 运行期弹出顺序为 [container, key, value]（value 在栈顶），与 POP_V/PROPSET/INDEX_SET 一致。
        $this->compileLVResolve($target, $code);
        $this->compileExpr($valueTokens, $code);
        $this->compileLVStore($target, $code);
    }

    /**
     * 语句级赋值识别：扫描第一个顶层 '=' / 复合赋值运算符，并校验其左侧是
     * 受支持的左值（见 matchLvalueSpan）。
     *
     * 返回 ['span'=>左值紧凑token, 'compound'=>?VmOp, 'value'=>右值token] 或 null。
     * 注意：找到赋值运算符但左侧不是受支持左值时（如 $a || $x = 1、$a['k']['j'] = 1、
     * Foo::$bar = 1、$this->data['k'] = 1）返回 null——交给 compileExpr 走常规解析链：
     * parsePrimary 内的 tryCompileAssign 仍可捕获嵌套在各操作数里的赋值
     * （$a || ($x = 1) 等），真正无法覆盖的形态由 compileExpr 的残余 token 检查抛
     * VmUnsupported 回退，避免既往「静默丢弃残余 token」编译出与 PHP 语义不符的代码。
     *
     * @param array<int,mixed> $tokens
     */
    private function matchAssignStmt(array $tokens): ?array
    {
        // 复合赋值 += -= *= /= %= .= **= <<= >>= &= |= ^= （均为单 token）
        $compoundOps = [
            T_PLUS_EQUAL    => VmOp::ADD,
            T_MINUS_EQUAL   => VmOp::SUB,
            T_MUL_EQUAL     => VmOp::MUL,
            T_DIV_EQUAL     => VmOp::DIV,
            T_MOD_EQUAL     => VmOp::MOD,
            T_CONCAT_EQUAL  => VmOp::CONCAT,
            T_POW_EQUAL     => VmOp::POW,
            T_SL_EQUAL      => VmOp::BW_SHL,
            T_SR_EQUAL      => VmOp::BW_SHR,
            T_AND_EQUAL     => VmOp::BW_AND,
            T_OR_EQUAL      => VmOp::BW_OR,
            T_XOR_EQUAL     => VmOp::BW_XOR,
        ];
        // 顶层扫描：跟踪 () / [] / 插值花括号深度，只认表达式最外层的赋值运算符。
        // [] 深度是必须的：$a[$i = 1] . $b 这类写法里 '=' 在下标内部，不属于本语句。
        $depth = 0; $pdepth = 0; $bdepth = 0;
        $assignAt = -1; $compound = null;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '(') { $pdepth++; }
                elseif ($t === ')') { $pdepth = max(0, $pdepth - 1); }
                elseif ($t === '[') { $bdepth++; }
                elseif ($t === ']') { $bdepth = max(0, $bdepth - 1); }
                elseif ($t === '=' && $depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    // 普通赋值（不支持 ??= 链，这里只取第一个裸 '='）
                    $assignAt = $i;
                    break;
                }
            } elseif (is_array($t)) {
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif ($depth === 0 && $pdepth === 0 && $bdepth === 0 && isset($compoundOps[$t[0]])) {
                    $assignAt = $i;
                    $compound = $compoundOps[$t[0]];
                    break;
                }
            }
        }
        if ($assignAt < 0) {
            return null;
        }
        $prefix = $this->trimWs(array_slice($tokens, 0, $assignAt));
        if ($prefix === []) {
            return null; // '= expr' 无左值：非常法 PHP，交回常规链处理
        }
        $lc = new Cursor($prefix);
        $lm = $this->matchLvalueSpan($lc);
        if ($lm === null || !$lc->eof()) {
            return null; // 左侧不是受支持左值（或左值后还有残余）：交回常规链
        }
        $valueTokens = $this->trimWs(array_slice($tokens, $assignAt + 1));
        if ($valueTokens !== [] && $valueTokens[count($valueTokens) - 1] === ';') {
            array_pop($valueTokens);
        }
        return ['span' => $lm['span'], 'compound' => $compound, 'value' => $valueTokens];
    }

    /**
     * 扫描语句 token 流，判断是否含顶层（非 ()/[]/{} 内部）的 `and`/`or`/`xor`
     * 关键字（T_LOGICAL_AND/OR/XOR）。用于语句级把整条语句路由为逻辑关键字表达式。
     * @param array<int,mixed> $tokens
     */
    private function hasTopLevelLogicalKeyword(array $tokens): bool
    {
        $pdepth = 0; $bdepth = 0; $depth = 0;
        foreach ($tokens as $t) {
            if (is_string($t)) {
                if ($t === '(') { $pdepth++; }
                elseif ($t === ')') { $pdepth = max(0, $pdepth - 1); }
                elseif ($t === '[') { $bdepth++; }
                elseif ($t === ']') { $bdepth = max(0, $bdepth - 1); }
                elseif ($pdepth === 0 && $bdepth === 0
                    && ($t === 'and' || $t === 'or' || $t === 'xor')) {
                    return true;
                }
            } elseif (is_array($t)) {
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif (in_array($t[0], [T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR], true)
                    && $depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 从光标当前位置匹配一个受支持的「可赋值左值」token 跨度（紧凑形态，去空白）：
     *     $var | $var->prop | $var[key]
     * 约束：
     *  - 最多一层后缀。$a['k']['j'] / $this->a->b / $this->data['k'] 等复合左值
     *    compileLVResolve/compileLVStore 不覆盖，不匹配（由调用方回退，避免静默写错位置）；
     *  - 后随 '(' 的是方法调用/变量函数，不是左值。
     * 成功返回 ['span'=>紧凑token数组, 'end'=>光标位置]；失败返回 null 且光标不变。
     */
    private function matchLvalueSpan(Cursor $c): ?array
    {
        $save = $c->pos();
        $c->skipWs();
        $t = $c->peek();
        if (is_array($t) && $t[0] === T_STRING) {
            // Class::$prop 静态属性左值（self/static/parent/已登记 VM 类名）。
            // 收集紧凑 span：T_STRING (T_NS_SEPARATOR T_STRING)* T_DOUBLE_COLON T_VARIABLE
            $span = [$t];
            $c->next();
            while (!$c->eof()) {
                $c->skipWs();
                $tk = $c->peek();
                if (is_array($tk) && $tk[0] === T_NS_SEPARATOR) {
                    $span[] = $tk;
                    $c->next();
                    continue;
                }
                break;
            }
            $c->skipWs();
            if (!$c->is(T_DOUBLE_COLON)) {
                $c->seek($save);
                return null;
            }
            $span[] = $c->next();
            $c->skipWs();
            $mv = $c->peek();
            if (!(is_array($mv) && $mv[0] === T_VARIABLE)) {
                $c->seek($save);
                return null;
            }
            $span[] = $mv;
            $c->next();
            $c->skipWs();
            $after = $c->peek();
            if (is_string($after) && $after === '[') {
                // Class::$p[key]：平衡收集到配对 ']'（内层下标括号天然配对）
                $span[] = $c->next();
                $bd = 1;
                while (!$c->eof()) {
                    $tk = $c->peek();
                    if (is_string($tk)) {
                        if ($tk === '[') {
                            $bd++;
                        } elseif ($tk === ']') {
                            $bd--;
                            if ($bd === 0) {
                                break; // 配对 ']' 不消费，由下方统一入跨
                            }
                        }
                    }
                    $span[] = $c->next();
                }
                if ($bd !== 0 || $c->eof()) {
                    $c->seek($save);
                    return null;
                }
                $span[] = $c->next(); // 消费配对 ']'
                $c->skipWs();
                $after = $c->peek();
            }
            if (is_string($after) && $after === '(') {
                // 静态方法调用：不是左值
                $c->seek($save);
                return null;
            }
            if ($this->staticPropLvalueInfo($span) === null) {
                // 非 VM 类静态属性（原生类）或 self/parent 不可解析：不匹配
                $c->seek($save);
                return null;
            }
            return ['span' => $span, 'end' => $c->pos()];
        }
        if (!(is_array($t) && $t[0] === T_VARIABLE)) {
            $c->seek($save);
            return null;
        }
        $c->next();
        $span = [$t];
        $c->skipWs();
        $nx = $c->peek();
        if ($nx !== null && $this->tokType($nx) === T_OBJECT_OPERATOR) {
            // $var->prop
            $c->next();
            $span[] = $nx;
            $c->skipWs();
            $p = $c->peek();
            if (!(is_array($p) && $p[0] === T_STRING)) {
                $c->seek($save);
                return null;
            }
            $c->next();
            $span[] = $p;
        } elseif (is_string($nx) && $nx === '[') {
            // $var[key]：平衡收集到配对 ']'（插值串尾部字面量是 T_ENCAPSED_AND_WHITESPACE
            // 数组 token，不会误入 [] 深度；内层下标括号天然配对）
            $c->next();
            $span[] = $nx;
            $bd = 1;
            while (!$c->eof()) {
                $tk = $c->peek();
                if (is_string($tk)) {
                    if ($tk === '[') {
                        $bd++;
                    } elseif ($tk === ']') {
                        $bd--;
                        if ($bd === 0) {
                            break; // 配对 ']' 不消费，由下方统一入跨
                        }
                    }
                }
                $span[] = $tk;
                $c->next();
            }
            if ($bd !== 0 || $c->eof()) {
                $c->seek($save);
                return null;
            }
            $span[] = $c->next(); // 消费配对 ']'
        }
        $end = $c->pos();
        $c->skipWs();
        $after = $c->peek();
        if (is_string($after) && $after === '(') {
            // 方法调用 / 变量函数：不是左值
            $c->seek($save);
            return null;
        }
        return ['span' => $span, 'end' => $end];
    }

    /**
     * 表达式内赋值：parsePrimary 在 T_VARIABLE 处先行尝试。
     * 识别「左值 = 右值」/「左值 op= 右值」，编译后把赋值结果留栈——PHP 中赋值是
     * 表达式，while(($c = f()) !== '')、$a || ($x = 1)、foo($y = 2)、
     * $x = $y = 1（右结合，经递归 parseTernary→parsePrimary 自然支持）均依赖此语义。
     * 非赋值形态返回 false 并完整恢复光标，由常规解析链继续。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function tryCompileAssign(Cursor $c, array &$code): bool
    {
        $save = $c->pos();
        $m = $this->matchLvalueSpan($c);
        if ($m === null) {
            return false;
        }
        $c->skipWs();
        $compound = null;
        if ($c->is('=')) {
            // 普通赋值
        } else {
            $compoundOps = [
                T_PLUS_EQUAL    => VmOp::ADD,
                T_MINUS_EQUAL   => VmOp::SUB,
                T_MUL_EQUAL     => VmOp::MUL,
                T_DIV_EQUAL     => VmOp::DIV,
                T_MOD_EQUAL     => VmOp::MOD,
                T_CONCAT_EQUAL  => VmOp::CONCAT,
                T_POW_EQUAL     => VmOp::POW,
                T_SL_EQUAL      => VmOp::BW_SHL,
                T_SR_EQUAL      => VmOp::BW_SHR,
                T_AND_EQUAL     => VmOp::BW_AND,
                T_OR_EQUAL      => VmOp::BW_OR,
                T_XOR_EQUAL     => VmOp::BW_XOR,
            ];
            $tk = $c->peek();
            if (is_array($tk) && isset($compoundOps[$tk[0]])) {
                $compound = $compoundOps[$tk[0]];
            } else {
                $c->seek($save);
                return false;
            }
        }
        $c->next(); // 消费 '=' 或复合赋值运算符
        $target = $m['span'];
        if ($compound !== null) {
            // 复合赋值（表达式上下文）仅支持简单变量与 VM 类静态属性：
            // 属性/下标目标在此上下文需「补压容器 + DUP 留值」，弹序会多残留
            // [容器, 旧值] 两层垃圾（DROP 只弹一层），故回退原生执行保正确。
            $t0 = $target[0] ?? null;
            if (!(is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 1)
                && $this->staticPropLvalueInfo($target) === null) {
                throw new VmUnsupported('VM 暂不支持数组/属性的复合赋值（已回退）');
            }
            $this->compileLVRead($target, $code);
            $this->parseTernary($c, $code);
            $code[] = [$compound];
            // 静态属性带下标：compileLVRead 已留下「容器+键」（值之下），无需补发
        } else {
            // 引用赋值（表达式上下文）：$b = &$a —— & 必为 '=' 后首个有效 token
            // （按位与 $x = $y & $z 的 & 前有 $y，不会进入本分支）。
            $c->skipWs();
            $amp = $c->peek();
            if ($amp !== null && $this->isAmpFollowedByVar($amp)) {
                $t0 = $target[0] ?? null;
                if (!(is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 1)
                    || $t0[1] === '$GLOBALS') {
                    throw new VmUnsupported('VM 引用赋值目标必须是简单变量（已回退）');
                }
                $dst = $this->varName($t0[1]);
                $c->next(); // 消费 '&'
                $this->compileRefAssignRHS($c, $dst, $code);
                // 表达式语义：引用赋值表达式的结果为左值当前值，留一份供上层消费
                $code[] = [VmOp::PUSH_V, $dst];
                return true;
            }
            $this->compileLVResolve($target, $code);
            $this->parseTernary($c, $code);
        }
        // 表达式语义：赋值结果（右值/复合计算结果）在栈上多留一份供上层消费。
        // DUP 位于存储指令之前，对 POP_V/PROPSET/INDEX_SET/ARRAY_PUSH/GLOBALS_SET
        // 五种存储形态均恰好留下一个值。
        $code[] = [VmOp::DUP];
        $this->compileLVStore($target, $code);
        return true;
    }

    /**
     * 发射"读取变量"指令。
     * $GLOBALS 是超全局桥接：VM 帧变量表与真实 PHP 全局符号表是两套存储，
     * 必须穿透到真实 $GLOBALS（编码混淆层的密钥串即下发在 $GLOBALS 中）。
     * @param array<int,array<int,mixed>> $code
     */
    private function emitPushVar(string $name, array &$code): void
    {
        if ($name === '$GLOBALS') {
            $code[] = [VmOp::PUSH_GLOBALS];
            return;
        }
        $code[] = [VmOp::PUSH_V, $this->varName($name)];
    }

    /**
     * 变量名 → 实际帧槽位名。若为当前函数作用域内的静态局部变量，则返回其共享帧
     * 槽位名（与 GLOBAL_REF 绑定的槽位一致）；否则原样返回（普通局部变量）。
     */
    private function varName(string $name): string
    {
        return $this->curStatic[$name] ?? $name;
    }

    /**
     * 编译左值读取（把左值当前值压栈）。仅支持 变量 / 变量[下标] / $this->属性。
     * @param array<int,mixed> $target
     * @param array<int,array<int,mixed>> $code
     */
    private function compileLVRead(array $target, array &$code): void
    {
        $sp = $this->staticPropLvalueInfo($target);
        if ($sp !== null) {
            // 静态属性读（复合赋值 op= 的读取阶段）：绑定共享帧槽位后取当前值。
            // 带下标时先压一份「容器+键」留在值之下（INDEX_SET 弹出序为
            // [value, key, container]，与普通赋值路径 compileLVResolve 的栈形态一致），
            // 再压第二份「容器+键」供 INDEX_GET 读旧值（出栈即消费）。
            $mangled = $this->staticVarName($sp['cls'], $sp['prop']);
            $code[] = [VmOp::GLOBAL_REF, $mangled];
            if ($sp['key'] === null) {
                $code[] = [VmOp::PUSH_V, $mangled];
                return;
            }
            if ($sp['key'] === []) {
                // self::$p[] 不能读（PHP 本身即致命）：不当左值处理
                throw new VmUnsupported('VM 不支持 self::$p[] 读取形态（已回退）');
            }
            $code[] = [VmOp::PUSH_V, $mangled];
            $this->compileExpr($sp['key'], $code);
            $code[] = [VmOp::PUSH_V, $mangled];
            $this->compileExpr($sp['key'], $code);
            $code[] = [VmOp::INDEX_GET];
            return;
        }
        $t = $target[0] ?? null;
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) === 1) {
            $this->emitPushVar($t[1], $code);
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) >= 3 && $target[1] === '[') {
            // $var[key] 读旧值（复合赋值 op= 的读取阶段）：先压一份「容器+键」留在值
            // 之下（INDEX_SET 弹出序为 [value, key, container]），再压第二份供 INDEX_GET
            // 读旧值（出栈即消费）。
            $key = array_slice($target, 2);
            if ($key !== [] && $key[count($key) - 1] === ']') {
                array_pop($key);
            }
            $this->compileExpr([$t], $code);
            $this->compileExpr($key, $code);
            $this->compileExpr([$t], $code);
            $this->compileExpr($key, $code);
            $code[] = [VmOp::INDEX_GET];
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) >= 3
            && $this->tokType($target[1]) === T_OBJECT_OPERATOR
            && is_array($target[2] ?? null) && $target[2][0] === T_STRING) {
            // $var->prop 读旧值（含 $this->prop）：压两份容器，第二份经 PROPGET
            // 读旧值（出栈即消费），第一份留在值之下供 PROPSET 存储。
            $prop = $target[2];
            $pname = is_array($prop) ? $prop[1] : (string) $prop;
            $this->compileExpr([$t], $code);
            $this->compileExpr([$t], $code);
            $code[] = [VmOp::PROPGET, $pname];
            return;
        }
        throw new VmUnsupported('VM 不支持的赋值左值形式（已回退）');
    }

    /**
     * 解析左值为"容器"（必要时连同下标/键一起压栈），为后续压入右值、发出存储指令做准备。
     * 约定：调用后栈顶为容器，若带下标则再压下标。右值由调用方随后压入。
     * @param array<int,mixed> $target
     * @param array<int,array<int,mixed>> $code
     */
    private function compileLVResolve(array $target, array &$code): void
    {
        $sp = $this->staticPropLvalueInfo($target);
        if ($sp !== null) {
            // 静态属性：GLOBAL_REF 绑定共享帧槽位。裸属性无需压容器（POP_V 经引用
            // 直接写穿）；带下标则压「容器副本 (+键)」，由 store 阶段 INDEX_SET/
            // ARRAY_PUSH 后 POP_V 写回（与 $arr[$k] = v 同款栈形态）。
            $mangled = $this->staticVarName($sp['cls'], $sp['prop']);
            $code[] = [VmOp::GLOBAL_REF, $mangled];
            if ($sp['key'] !== null) {
                $code[] = [VmOp::PUSH_V, $mangled];
                if ($sp['key'] !== []) {
                    $this->compileExpr($sp['key'], $code);
                }
            }
            return;
        }
        $t = $target[0] ?? null;
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) >= 3 && $this->tokType($target[1]) === T_OBJECT_OPERATOR) {
            // $this->prop ：压入 $this
            $this->compileExpr([$t], $code);
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) >= 3 && $target[1] === '[') {
            // $arr[$k] / $arr[] ：压入数组变量（空下标时暂不压键，由 store 阶段用 ARRAY_PUSH 处理）
            $this->compileExpr([$t], $code);
            $key = array_slice($target, 2);
            if ($key !== [] && $key[count($key) - 1] === ']') {
                array_pop($key);
            }
            if ($key !== []) {
                $this->compileExpr($key, $code);
            }
            return;
        }
        // 简单变量：无需压入容器
    }

    /**
     * 在右值已压栈后，发出"写入左值"的存储指令。
     * @param array<int,mixed> $target
     * @param array<int,array<int,mixed>> $code
     */
    private function compileLVStore(array $target, array &$code): void
    {
        $sp = $this->staticPropLvalueInfo($target);
        if ($sp !== null) {
            // 静态属性写：裸属性直接 POP_V 写穿共享帧引用；带下标则先
            // INDEX_SET/ARRAY_PUSH 更新容器副本，再 POP_V 写回。
            $mangled = $this->staticVarName($sp['cls'], $sp['prop']);
            if ($sp['key'] === null) {
                $code[] = [VmOp::POP_V, $mangled];
            } elseif ($sp['key'] === []) {
                $code[] = [VmOp::ARRAY_PUSH];
                $code[] = [VmOp::POP_V, $mangled];
            } else {
                $code[] = [VmOp::INDEX_SET];
                $code[] = [VmOp::POP_V, $mangled];
            }
            return;
        }
        $t = $target[0] ?? null;
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) === 1) {
            if ($t[1] === '$GLOBALS') {
                // PHP 禁止整体写 $GLOBALS（"$GLOBALS can only be modified using..."），
                // 整表赋值回退到原生语句执行。
                throw new VmUnsupported('VM 暂不支持 $GLOBALS 整表赋值（已回退）');
            }
            $code[] = [VmOp::POP_V, $this->varName($t[1])];
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && $this->tokType($target[1]) === T_OBJECT_OPERATOR
            && count($target) >= 3) {
            $prop = $target[2];
            $pname = is_array($prop) ? $prop[1] : (string) $prop;
            $code[] = [VmOp::PROPSET, $pname];
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && $target[1] === '[' && count($target) >= 3) {
            $key = array_slice($target, 2);
            if ($key !== [] && $key[count($key) - 1] === ']') {
                array_pop($key);
            }
            if ($t[1] === '$GLOBALS') {
                // $GLOBALS['k'] = v：元素级直写真实全局符号表（PHP 允许的唯一写形态）。
                // 栈形态为 [容器副本, key, val]，GLOBALS_SET 依次弹出并写回。
                if ($key === []) {
                    throw new VmUnsupported('VM 暂不支持 $GLOBALS[] 追加（已回退）');
                }
                $code[] = [VmOp::GLOBALS_SET];
                return;
            }
            if ($key === []) {
                $code[] = [VmOp::ARRAY_PUSH];
            } else {
                $code[] = [VmOp::INDEX_SET];
            }
            $code[] = [VmOp::POP_V, $this->varName($t[1])]; // 写回（数组为值类型，本地更新需回存）
            return;
        }
        throw new VmUnsupported('VM 不支持的赋值左值形式（已回退）');
    }

    /**
     * 检测 token 序列是否以「引用 & 前缀」开头（$b = &$a / $b = &$a[0][0] / $b = &$this->p）。
     * PHP 8.1+：T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG；8.0-：字符串 '&'。
     * 命中时返回 & 之后的引用目标链 token（紧凑）；否则 null。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>|null
     */
    private function matchRefAmpPrefix(array $tokens): ?array
    {
        $t = $this->trimWs($tokens);
        if ($t === []) {
            return null;
        }
        // 赋值右值首 token 的 & 必为引用运算符，两种 amp 形态都要认：
        // PHP 8.1+ 按「& 后是否紧跟变量」分两种 token——
        //   &$a   → T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
        //   &g()  → T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG（后跟函数名）
        // PHP 8.0- 两种都是裸字符 '&'。只认 FOLLOWED 会漏掉引用返回
        // 函数调用 $r = &g()（CALL_RR 路径），故此处三种形态全accept。
        $a = $t[0];
        $isAmp = $this->isAmpFollowedByVar($a) || $this->isAmpNotFollowedByVar($a) || $a === '&';
        if (!$isAmp) {
            return null;
        }
        return $this->trimWs(array_slice($t, 1));
    }

    /**
     * 编译引用赋值右值（=& 之后的引用目标链）：
     *     $a | $a[$k] | $a[] | $a->p | $a[0][0] | $a->p[0] | $a[0]->p ...
     * 目标限「简单变量 + 下标/属性后缀链」。链式形态拆为多级桥接：
     * 每一级先别名为临时槽（PHP 引用传递保证最终指向真实存储），
     * 最后一级把左值 dst 别名为当前槽——此后对 dst 的读写即写穿目标。
     *
     * 流式解析（光标推进到链尾即停），语句级与表达式级（tryCompileAssign）共用。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function compileRefAssignRHS(Cursor $c, string $dst, array &$code): void
    {
        $c->skipWs();
        $first = $c->peek();
        if (is_array($first) && $first[0] === T_STRING) {
            // 引用返回函数调用：$dst = &g(args) —— 发射 CALL_RR
            // （调用时向被调帧预置桥接槽，返回后把桥接槽引用绑到 dst）。
            // 仅限编译期已登记 funcRef 的引用返回函数；否则回退不支持。
            $c->next();
            $fname = $first[1];
            $c->skipWs();
            if (!$c->is('(') || !isset($this->funcRef[$fname])) {
                throw new VmUnsupported('VM 引用赋值目标必须是简单变量或引用返回函数调用（已回退）');
            }
            // compileArgs 自身 expect('(') 开头、expect(')') 收尾（自消费双括号），
            // 此处勿再 next()/expect(')')——曾因双重消费 '(' 抛「期望 (」回退。
            $argc = $this->compileArgs($c, $code);
            $code[] = [VmOp::CALL_RR, $fname, $dst, $argc];
            return;
        }
        if (!(is_array($first) && $first[0] === T_VARIABLE)) {
            throw new VmUnsupported('VM 引用赋值目标必须是简单变量（已回退）');
        }
        $c->next();
        $curSlot = $this->varName($first[1]);
        while (true) {
            $c->skipWs();
            $t = $c->peek();
            if ($t === null) {
                break;
            }
            if (is_string($t) && $t === '[') {
                // 下标段：$curSlot[key]（key 为空表示追加 $a[]）
                $c->next();
                $keyToks = $c->collectUntilTop(']');
                $c->expect(']');
                $next = '$__rf' . ($this->tempSeq++);
                if ($this->trimWs($keyToks) === []) {
                    $code[] = [VmOp::MAKE_REF_APPEND, $next, $curSlot];
                } else {
                    $this->compileExpr($this->trimWs($keyToks), $code);
                    $code[] = [VmOp::MAKE_REF_EL, $next, $curSlot];
                }
                $curSlot = $next;
                continue;
            }
            if (is_array($t) && $t[0] === T_OBJECT_OPERATOR) {
                // 属性段：$curSlot->prop（仅限字面属性名，动态属性名回退）
                $c->next();
                $c->skipWs();
                $p = $c->peek();
                if (!(is_array($p) && $p[0] === T_STRING)) {
                    throw new VmUnsupported('VM 引用赋值不支持动态属性名（已回退）');
                }
                $c->next();
                $next = '$__rf' . ($this->tempSeq++);
                $code[] = [VmOp::PUSH_V, $curSlot];
                $code[] = [VmOp::MAKE_REF_PROP, $next, $p[1]];
                $curSlot = $next;
                continue;
            }
            break;
        }
        $code[] = [VmOp::MAKE_REF, $dst, $curSlot];
    }

    // ========================================================================
    // 表达式（运算符优先级爬升）
    // ========================================================================
    /**
     * @param array<int,mixed> $tokens
     * @param array<int,array<int,mixed>> $code
     */
    private function compileExpr(array $tokens, array &$code): void
    {
        if ($tokens === []) {
            $code[] = [VmOp::PUSH_NULL];
            return;
        }
        $c = new Cursor($tokens);
        $this->parseLogicalKeyword($c, $code);
        $c->skipWs();
        if (!$c->eof()) {
            // 解析链结束后仍有残余 token：说明存在链未覆盖的运算符/语法
            // （引用赋值 =&、yield、clone、复合左值下标 $a['k']['j'] = v 等）。
            // 既往是静默丢弃残余——会编译出与 PHP 语义不符的代码；现改为抛
            // VmUnsupported 让整条语句走 EVAL_STMT 原生语义回退，保证正确性。
            throw new VmUnsupported('VM 表达式存在无法消费的残余 token（已回退）');
        }
    }

    /**
     * 解析一段子表达式（如括号内部、数组下标、数组元素），语义同 compileExpr。
     * @param array<int,mixed> $tokens
     * @param array<int,array<int,mixed>> $code
     */
    private function parseExpr(array $tokens, array &$code): void
    {
        $this->compileExpr($tokens, $code);
    }

    /**
     * 逻辑关键字运算符 `and` / `or` / `xor`（优先级低于赋值 `=`）：
     *     or  <  xor  <  and  <  =  <  ?:  <  ??  <  ||  <  &&
     * 与 `||`/`&&`/`!` 不同，这三个是关键字 token（T_LOGICAL_*），且 `and`/`or`
     * 短路、`xor` 非短路（两侧均求值）。因优先级低于 `=`，其操作数允许包含赋值
     * 表达式——而赋值表达式本就在 parsePrimary 经 tryCompileAssign 解析并留值，
     * 故此处操作数直接走 parseTernary 即可正确覆盖 `$a = 1 and $b = 2` 等形态。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseLogicalKeyword(Cursor $c, array &$code): void
    {
        $this->parseLogicalOrKw($c, $code);
    }

    /** `or`（优先级最低，短路：左为真则结果直接为真，不求右） */
    private function parseLogicalOrKw(Cursor $c, array &$code): void
    {
        $this->parseLogicalXorKw($c, $code);
        $c->skipWs();
        while ($c->is(T_LOGICAL_OR)) {
            $c->next();
            $code[] = [VmOp::DUP];
            $jnz = count($code);
            $code[] = [VmOp::JNZ, 0];
            $this->parseLogicalXorKw($c, $code);
            $code[] = [VmOp::BOOL_OR];
            $jmp = count($code);
            $code[] = [VmOp::JMP, 0];
            $code[$jnz][1] = count($code) - ($jnz + 1); // 左为真：结果直接为 true
            $code[] = [VmOp::DROP];
            $code[] = [VmOp::PUSH_C, true];
            $code[$jmp][1] = count($code) - ($jmp + 1);
            $c->skipWs();
        }
    }

    /** `xor`（非短路：两侧均求值后做布尔异或） */
    private function parseLogicalXorKw(Cursor $c, array &$code): void
    {
        $this->parseLogicalAndKw($c, $code);
        $c->skipWs();
        while ($c->is(T_LOGICAL_XOR)) {
            $c->next();
            $this->parseLogicalAndKw($c, $code);
            $code[] = [VmOp::BOOL_XOR];
            $c->skipWs();
        }
    }

    /** `and`（短路：左为假则结果直接为假，不求右） */
    private function parseLogicalAndKw(Cursor $c, array &$code): void
    {
        $this->parseTernary($c, $code);
        $c->skipWs();
        while ($c->is(T_LOGICAL_AND)) {
            $c->next();
            $code[] = [VmOp::DUP];
            $jz = count($code);
            $code[] = [VmOp::JZ, 0];
            $this->parseTernary($c, $code);
            $code[] = [VmOp::BOOL_AND];
            $jmp = count($code);
            $code[] = [VmOp::JMP, 0];
            $code[$jz][1] = count($code) - ($jz + 1); // 左为假：结果直接为 false
            $code[] = [VmOp::DROP];
            $code[] = [VmOp::PUSH_C, false];
            $code[$jmp][1] = count($code) - ($jmp + 1);
            $c->skipWs();
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseTernary(Cursor $c, array &$code): void
    {
        $this->parseCoalesce($c, $code);
        $c->skipWs();
        if ($c->is('?')) {
            $c->next();
            $c->skipWs();
            if ($c->is(':')) {
                // ?: 形式：cond 为真 => 值为 cond 本身；为假 => 值为 rhs。
                // 形态：DUP 复制 cond（JZ 消费副本判断），真则 JMP 跳过 rhs
                // （保留原 cond），假则 DROP 掉 cond 再压 rhs。
                // （旧实现缺 DUP/JMP 且 JZ 方向反了——cond 为假也跳过 rhs，
                //  为真反而执行 rhs，`1 ?: "d"` 得出 "d"。）
                $c->next();
                $code[] = [VmOp::DUP];
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $code[] = [VmOp::DROP];
                $this->parseTernary($c, $code);
                $code[$jz][1] = ($jz + 2) - ($jz + 1); // 假：落到 DROP（jz+2 处）
                $code[$jmp][1] = count($code) - ($jmp + 1); // 真：跳到 end
            } else {
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
                $t1 = $c->collectUntilTop(':', null);
                $this->parseTernary(new Cursor($t1), $code);
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $code[$jz][1] = count($code) - ($jz + 1);
                $c->expect(':');
                $t2 = $c->collectUntilEnd();
                $this->parseTernary(new Cursor($t2), $code);
                $code[$jmp][1] = count($code) - ($jmp + 1);
            }
        }
    }

    /**
     * 空合并运算符 `??`（优先级低于 || 、高于三元）。
     * 语义等价于 isset(lhs) && lhs !== null ? lhs : rhs。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseCoalesce(Cursor $c, array &$code): void
    {
        $this->parseOr($c, $code);
        $c->skipWs();
        while ($c->is(T_COALESCE)) {
            $c->next();
            // lhs 已压栈：<lhs> DUP PUSH_NULL IDENT -> JNZ 到「丢弃 lhs 取 rhs」
            $code[] = [VmOp::DUP];
            $code[] = [VmOp::PUSH_NULL];
            $code[] = [VmOp::IDENT];
            $jnz = count($code);
            $code[] = [VmOp::JNZ, 0];
            $jmp = count($code);
            $code[] = [VmOp::JMP, 0]; // lhs 非空：保留 lhs，跳过 rhs
            $code[$jnz][1] = count($code) - ($jnz + 1); // lhs 为空：落到此处
            $code[] = [VmOp::DROP];
            $this->parseOr($c, $code);
            $code[$jmp][1] = count($code) - ($jmp + 1);
            $c->skipWs();
        }
    }

    /**
     * `||`：短路求值（左为真则不求右）。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseOr(Cursor $c, array &$code): void
    {
        $this->parseAnd($c, $code);
        $c->skipWs();
        while (true) {
            if ($c->is(T_BOOLEAN_OR) || ($c->is('|') && $c->peek2() === '|')) {
                if ($c->is('|')) { $c->next(); $c->next(); }
                else { $c->next(); }
                $code[] = [VmOp::DUP];
                $jnz = count($code);
                $code[] = [VmOp::JNZ, 0];
                $this->parseAnd($c, $code);
                $code[] = [VmOp::BOOL_OR];
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $code[$jnz][1] = count($code) - ($jnz + 1); // 左为真：结果直接为 true
                $code[] = [VmOp::DROP];
                $code[] = [VmOp::PUSH_C, true];
                $code[$jmp][1] = count($code) - ($jmp + 1);
            } else {
                break;
            }
            $c->skipWs();
        }
    }

    /**
     * `&&`：短路求值（左为假则不求右）。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseAnd(Cursor $c, array &$code): void
    {
        $this->parseBwOr($c, $code);
        $c->skipWs();
        while (true) {
            if ($c->is(T_BOOLEAN_AND) || ($c->is('&') && $c->peek2() === '&')) {
                if ($c->is('&')) { $c->next(); $c->next(); }
                else { $c->next(); }
                $code[] = [VmOp::DUP];
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
                $this->parseBwOr($c, $code);
                $code[] = [VmOp::BOOL_AND];
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $code[$jz][1] = count($code) - ($jz + 1); // 左为假：结果直接为 false
                $code[] = [VmOp::DROP];
                $code[] = [VmOp::PUSH_C, false];
                $code[$jmp][1] = count($code) - ($jmp + 1);
            } else {
                break;
            }
            $c->skipWs();
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseBwOr(Cursor $c, array &$code): void
    {
        $this->parseBwXor($c, $code);
        while ($c->is('|') && $c->peek2() !== '|') {
            $c->next();
            $this->parseBwXor($c, $code);
            $code[] = [VmOp::BW_OR];
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseBwXor(Cursor $c, array &$code): void
    {
        $this->parseBwAnd($c, $code);
        while ($c->is('^')) {
            $c->next();
            $this->parseBwAnd($c, $code);
            $code[] = [VmOp::BW_XOR];
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseBwAnd(Cursor $c, array &$code): void
    {
        $this->parseEq($c, $code);
        while (!$c->eof()) {
            // 注意：位运算 & 在表达式中会被 tokenizer 记为 T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
            if ($c->is('&') || $c->is(T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
                $c->next();
                $this->parseEq($c, $code);
                $code[] = [VmOp::BW_AND];
            } else {
                break;
            }
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseEq(Cursor $c, array &$code): void
    {
        $this->parseCmp($c, $code);
        $c->skipWs();
        while (!$c->eof()) {
            $op = null;
            if ($c->is(T_IS_EQUAL)) { $c->next(); $op = VmOp::EQ; }
            elseif ($c->is(T_IS_NOT_EQUAL)) { $c->next(); $op = VmOp::NE; }
            elseif ($c->is(T_IS_IDENTICAL)) { $c->next(); $op = VmOp::IDENT; }
            elseif ($c->is(T_IS_NOT_IDENTICAL)) { $c->next(); $op = VmOp::NIDENT; }
            elseif ($c->is(T_SPACESHIP)) { $c->next(); $op = VmOp::SPACESHIP; }
            else { break; }
            $this->parseCmp($c, $code);
            $code[] = [$op];
            $c->skipWs();
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseCmp(Cursor $c, array &$code): void
    {
        $this->parseConcat($c, $code);
        while (!$c->eof()) {
            $op = null;
            if ($c->is(T_IS_SMALLER_OR_EQUAL)) { $c->next(); $op = VmOp::LE; }
            elseif ($c->is(T_IS_GREATER_OR_EQUAL)) { $c->next(); $op = VmOp::GE; }
            elseif ($c->is('<')) { $c->next(); $op = VmOp::LT; }
            elseif ($c->is('>')) { $c->next(); $op = VmOp::GT; }
            else { break; }
            $this->parseConcat($c, $code);
            $code[] = [$op];
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseConcat(Cursor $c, array &$code): void
    {
        $this->parseShift($c, $code);
        while ($c->is('.')) {
            $c->next();
            $this->parseShift($c, $code);
            $code[] = [VmOp::CONCAT];
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseShift(Cursor $c, array &$code): void
    {
        $this->parseAdd($c, $code);
        while (!$c->eof()) {
            if ($c->is(T_SL)) { $c->next(); $this->parseAdd($c, $code); $code[] = [VmOp::BW_SHL]; }
            elseif ($c->is(T_SR)) { $c->next(); $this->parseAdd($c, $code); $code[] = [VmOp::BW_SHR]; }
            else { break; }
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseAdd(Cursor $c, array &$code): void
    {
        $this->parseMul($c, $code);
        while (!$c->eof()) {
            if ($c->is('+')) { $c->next(); $this->parseMul($c, $code); $code[] = [VmOp::ADD]; }
            elseif ($c->is('-')) { $c->next(); $this->parseMul($c, $code); $code[] = [VmOp::SUB]; }
            else { break; }
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseMul(Cursor $c, array &$code): void
    {
        $this->parseInstanceof($c, $code);
        while (!$c->eof()) {
            if ($c->is('*')) { $c->next(); $this->parsePow($c, $code); $code[] = [VmOp::MUL]; }
            elseif ($c->is('/')) { $c->next(); $this->parsePow($c, $code); $code[] = [VmOp::DIV]; }
            elseif ($c->is('%')) { $c->next(); $this->parsePow($c, $code); $code[] = [VmOp::MOD]; }
            else { break; }
        }
    }

    /**
     * instanceof 优先级层：位于一元运算（cast/-/~/!）与 * / % 之间
     * （PHP 优先级表：** > 一元 > instanceof > ! > * > + > ...）。
     * 注意 `!` 的操作数也经本层解析——`!$x instanceof A` 语义为
     * `!($x instanceof A)`（instanceof 绑定更紧）。
     * 动态类名（$x instanceof $c）不支持，抛 VmUnsupported 回退。
     */
    private function parseInstanceof(Cursor $c, array &$code): void
    {
        $this->parsePow($c, $code);
        while ($c->is(T_INSTANCEOF)) {
            $c->next();
            $c->skipWs();
            $t = $c->peek();
            if (is_array($t) && $t[0] === T_VARIABLE) {
                throw new VmUnsupported('VM 不支持动态类名 instanceof（已回退）');
            }
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_STATIC], true)) {
                throw new VmUnsupported('VM 不支持的 instanceof 右值（已回退）');
            }
            // 收集类名（可带命名空间分隔符）；self/static/parent 解析为编译期类名
            // （注意：self/parent 在 PHP 分词里就是 T_STRING；static 在表达式位置
            // 可能是 T_STATIC，一并收集）
            $cls = '';
            while (!$c->eof()) {
                $tk = $c->peek();
                if (is_array($tk) && $tk[0] === T_STRING) { $cls .= $tk[1]; $c->next(); continue; }
                if (is_array($tk) && $tk[0] === T_STATIC) { $cls .= 'static'; $c->next(); continue; }
                if (is_array($tk) && $tk[0] === T_NS_SEPARATOR) { $cls .= '\\'; $c->next(); continue; }
                break;
            }
            $low = strtolower($cls);
            if ($low === 'self' || $low === 'static') {
                if ($this->curClass === '') {
                    throw new VmUnsupported('VM 无法解析 self:: / static::（不在类上下文中，已回退）');
                }
                $cls = $this->curClass;
            } elseif ($low === 'parent') {
                if ($this->curClass === '') {
                    throw new VmUnsupported('VM 无法解析 parent::（不在类上下文中，已回退）');
                }
                $parent = $this->classParent[$this->curClass] ?? null;
                if ($parent === null) {
                    throw new VmUnsupported('VM 无法解析 parent::（未记录父类名，已回退）');
                }
                $cls = $parent;
            }
            if ($cls === '') {
                throw new VmUnsupported('VM 不支持的 instanceof 右值（已回退）');
            }
            $code[] = [VmOp::INSTANCEOF, ltrim($cls, '\\')];
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parsePow(Cursor $c, array &$code): void
    {
        $this->parseUnary($c, $code);
        while ($c->is(T_POW)) {
            $c->next();
            $this->parseUnary($c, $code);
            $code[] = [VmOp::POW];
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function parseUnary(Cursor $c, array &$code): void
    {
        $c->skipWs();
        // 类型转型：(int)/(integer)、(float)/(double)/(real)、(string)、(bool)/(boolean)、
        // (array)、(object)、(unset)。PHP 对每类转型产出单一 cast token。
        $castOps = [
            T_INT_CAST    => VmOp::CAST_INT,
            T_DOUBLE_CAST => VmOp::CAST_FLOAT,
            T_STRING_CAST => VmOp::CAST_STRING,
            T_BOOL_CAST   => VmOp::CAST_BOOL,
            T_ARRAY_CAST  => VmOp::CAST_ARRAY,
            T_OBJECT_CAST => VmOp::CAST_OBJECT,
        ];
        $t0 = $c->peek();
        if (is_array($t0) && isset($castOps[$t0[0]])) {
            $c->next();
            $this->parseUnary($c, $code); // 转型绑定同优先级（右结合）
            $code[] = [$castOps[$t0[0]]];
            return;
        }
        if (is_array($t0) && $t0[0] === T_UNSET_CAST) {
            // (unset)$x：求值后丢弃，结果为 null
            $c->next();
            $this->parseUnary($c, $code);
            $code[] = [VmOp::DROP];
            $code[] = [VmOp::PUSH_NULL];
            return;
        }
        if ($c->is('!')) { $c->next(); $this->parseInstanceof($c, $code); $code[] = [VmOp::BOOL_NOT]; return; }
        if ($c->is('+')) {
            // 一元 +：PHP 语义为数值强制转换（"5abc"→5、"5.5"→5.5、"abc"→0），
            // 与非数值告警。编译为 `$x + 0` 复刻之（ADD 走原生运算，告警文本一致）。
            $c->next();
            $this->parseUnary($c, $code);
            $code[] = [VmOp::PUSH_C, 0];
            $code[] = [VmOp::ADD];
            return;
        }
        if ($c->is('-')) { $c->next(); $this->parseUnary($c, $code); $code[] = [VmOp::NEG]; return; }
        if ($c->is('~')) { $c->next(); $this->parseUnary($c, $code); $code[] = [VmOp::BW_NOT]; return; }
        if ($c->is('@')) {
            // 错误抑制运算符：只抑制诊断输出、不影响求值结果。
            // 反调试探针等壳会生成 @ini_get(...) 形式，VM 必须能编译，否则整层回退。
            // 同时置抑制标志：@include 的告警在运行期也要静默（见 emitInclude）。
            $c->next();
            $this->suppressInc = true;
            $this->parseUnary($c, $code);
            return;
        }
        if ($c->is(T_INC) || $c->is(T_DEC)) { // ++$v / --$v（含 ++self::$s 静态属性）
            $dec = $c->is(T_DEC);
            $c->next();
            $sp = $this->tryParseStaticPropLvalue($c);
            if ($sp !== null) {
                // 静态属性：GLOBAL_REF 绑定共享帧槽位后走常规 PRE_INC/PRE_DEC
                // （运行期经引用写穿，语义与普通变量一致）。
                $mangled = $this->staticVarName($sp['cls'], $sp['prop']);
                $code[] = [VmOp::GLOBAL_REF, $mangled];
                $code[] = [$dec ? VmOp::PRE_DEC : VmOp::PRE_INC, $mangled];
                return;
            }
            $v = $c->expectText(T_VARIABLE);
            $c->skipWs();
            $nx = $c->peek();
            if ($nx !== null && $this->tokType($nx) === T_OBJECT_OPERATOR) {
                // ++$o->p：DUP 留 obj，PROPGET 压旧值，PROP_PRE_INC/DEC 弹
                // [old,obj] 原地写穿属性压新值（表达式结果=新值）。
                $this->emitPushVar($v, $code);
                $c->next();
                $name = $c->expectText(T_STRING);
                $code[] = [VmOp::DUP];
                $code[] = [VmOp::PROPGET, $name];
                $code[] = [$dec ? VmOp::PROP_PRE_DEC : VmOp::PROP_PRE_INC, $name];
                return;
            }
            if (is_string($nx) && $nx === '[') {
                // ++$a[$k]：IDX_PRE_INC/DEC 弹 [key, arr] 自取旧值、原地更新，
                // 写回基变量帧槽，只压新值作表达式结果。
                $this->emitPushVar($v, $code);
                $c->next();
                $key = [];
                $bdepth = 1;
                while (!$c->eof()) {
                    $tk = $c->peek();
                    if (is_string($tk)) {
                        if ($tk === '[') {
                            $bdepth++;
                        } elseif ($tk === ']') {
                            $bdepth--;
                            if ($bdepth === 0) {
                                break;
                            }
                        }
                    }
                    $key[] = $c->next();
                }
                $c->expect(']');
                if ($key !== [] && $key[count($key) - 1] === ';') {
                    array_pop($key);
                }
                $this->parseExpr($key, $code);
                $code[] = [$dec ? VmOp::IDX_PRE_DEC : VmOp::IDX_PRE_INC, $this->varName($v)];
                return;
            }
            $code[] = [$dec ? VmOp::PRE_DEC : VmOp::PRE_INC, $this->varName($v)];
            return;
        }
        $this->parsePostfix($c, $code);
    }

    /**
     * 后缀：属性/下标/方法调用/自增自减。
     * @param array<int,array<int,mixed>> $code
     */
    private function parsePostfix(Cursor $c, array &$code): void
    {
        // 记录主表达式是否恰为 $this（单 token，无其他左值后缀）——方法调用点据此
        // 取 curClass::m 签名（命名实参 / 引用参数 / 解包校验）
        $c->skipWs();
        $recvIsThis = $c->is(T_VARIABLE) && (($c->peek()[1] ?? '') === 'this');
        $this->parsePrimary($c, $code);
        while (!$c->eof()) {
            if ($c->is(T_OBJECT_OPERATOR)) {
                $c->next();
                if ($c->is(T_VARIABLE)) {
                    // 动态方法名 $o->$f()：方法名（变量值）在实参之后压栈，
                    // MECALL 运行期弹出分发（与静态名同一条指令）。
                    // 动态属性读取 $o->$p（无调用括号）不走本分支，由语句级
                    // 回退处理（PROPGET 的操作数是编译期常量名）。
                    $mv = $c->expectText(T_VARIABLE);
                    if (!$c->is('(')) {
                        throw new VmUnsupported('VM 不支持动态属性读取（已回退）');
                    }
                    $argc = $this->compileArgs($c, $code);
                    $this->emitPushVar($mv, $code);
                    $code[] = [VmOp::MECALL, $argc];
                    continue;
                }
                $name = $c->expectText(T_STRING);
                if ($c->is('(')) {
                    // $this->m()：类编译期已知，传 'C::m' 启用签名驱动的实参编译
                    $callee = ($recvIsThis && $this->curClass !== '') ? $this->curClass . '::' . $name : '';
                    $argc = $this->compileArgs($c, $code, [], $callee);
                    $code[] = [VmOp::PUSH_C, $name]; // 方法名（MECALL 期望：对象, 参数..., 方法名）
                    $code[] = [VmOp::MECALL, $argc];
                } else {
                    // $o->prop 后缀 ++/--：PROPGET 弹 obj 压旧值，故先 DUP 留一份
                    // 对象在旧值之下；PROP_POST_INC/DEC 弹 [old, obj] 原地写穿属性
                    // 并把旧值压回（表达式结果=旧值）。对象句柄原地写穿，语句级
                    // 无需写回帧变量。
                    $c->skipWs();
                    $nx = $c->peek();
                    if (is_array($nx) && ($nx[0] === T_INC || $nx[0] === T_DEC)) {
                        $c->next();
                        $code[] = [VmOp::DUP];
                        $code[] = [VmOp::PROPGET, $name];
                        $code[] = [$nx[0] === T_INC ? VmOp::PROP_POST_INC : VmOp::PROP_POST_DEC, $name];
                        continue;
                    }
                    $code[] = [VmOp::PROPGET, $name];
                }
            } elseif ($c->is(T_DOUBLE_COLON)) {
                throw new VmUnsupported('VM 不支持静态调用（已回退）');
            } elseif ($c->is('[')) {
                $c->next();
                $key = [];
                // 键表达式内可能再出现方括号下标（如 $GLOBALS['x']、$a[$b['k']]），
                // 必须跟踪 [] 嵌套深度，否则会在内层 ] 处提前截断导致括号失衡。
                $bdepth = 1;
                while (!$c->eof()) {
                    if ($c->is(']')) {
                        $bdepth--;
                        if ($bdepth === 0) {
                            break;
                        }
                        $key[] = $c->next();
                        continue;
                    }
                    if ($c->is('[')) {
                        $bdepth++;
                    }
                    $key[] = $c->next();
                }
                $c->expect(']');
                if ($key !== [] && $key[count($key) - 1] === ';') {
                    array_pop($key);
                }
                // $a[$k]++ / $a[$k]-- 后缀（表达式上下文）：IDX_POST_INC/DEC
                // 弹 [key, arr] 自取旧值并原地更新，把更新后的容器写回基变量帧槽
                // （PHP 间接修改语义），只压旧值作表达式结果（语句级由外层 DROP
                // 丢弃；多维/属性基回退保正确）。
                $c->skipWs();
                $nx = $c->peek();
                if (is_array($nx) && ($nx[0] === T_INC || $nx[0] === T_DEC)) {
                    $c->next();
                    // 基必须是简单变量（末尾恰为 PUSH_V）：多维下标/属性基的自增
                    // 需逐级写回，超出当前 opcode 能力，回退保正确。
                    $last = end($code);
                    if (!($last !== false && isset($last[0]) && $last[0] === VmOp::PUSH_V)) {
                        throw new VmUnsupported('VM 暂不支持复合左值的下标 ++/--（已回退）');
                    }
                    $frame = $last[1];
                    $this->parseExpr($key, $code);
                    $code[] = [$nx[0] === T_INC ? VmOp::IDX_POST_INC : VmOp::IDX_POST_DEC, $frame];
                    continue;
                }
                $this->parseExpr($key, $code);
                $code[] = [VmOp::INDEX_GET];
            } elseif ($c->is(T_INC)) {
                $c->next();
                $last = end($code);
                if ($last !== false && isset($last[0]) && $last[0] === VmOp::PUSH_V) {
                    // 后缀：结果压入旧值，再把变量自增（POST_INC 内部完成自增）
                    array_pop($code);
                    $code[] = [VmOp::POST_INC, $last[1]];
                } else {
                    throw new VmUnsupported('VM 暂不支持复杂表达式的后缀 ++（已回退）');
                }
                continue;
            } elseif ($c->is(T_DEC)) {
                $c->next();
                $last = end($code);
                if ($last !== false && isset($last[0]) && $last[0] === VmOp::PUSH_V) {
                    array_pop($code);
                    $code[] = [VmOp::POST_DEC, $last[1]];
                } else {
                    throw new VmUnsupported('VM 暂不支持复杂表达式的后缀 --（已回退）');
                }
                continue;
            } else {
                break;
            }
        }
    }

    /**
     * 编译实参列表，参数按【源码左→右】顺序压栈。返回参数个数。
     * 调用方光标应位于 '(' 之后（本方法自行消费 '(' 与 ')'）。
     *
     * 三种实参形态（PHP 5.6+ / 8.0+）统一以「标记 + 运行期解析」方式编译：
     *   - 位置实参：expr —— 直接编译；简单变量改发 PUSH_REF 引用句柄
     *     （句柄携带当前值快照），供 by-ref 形参做 copy-in / copy-out，
     *     非引用位运行期自动解包为值——故 receiver 类型编译期不可知时
     *     （$obj->m($a)）引用语义依然正确。
     *   - 命名实参：name: expr —— 编译值后发射 ARG_NAMED(name)，
     *     运行期按被调方参数规格（funcParams / classParams）重排为位置实参。
     *   - 解包实参：...expr —— 编译数组后发射 ARG_SPREAD，
     *     运行期按 key 类型展平（整数键→位置实参，字符串键→命名实参）。
     *
     * $refPos / $callee 仅为兼容保留（预扫描签名可提示），实参绑定全部推迟到
     * 运行期完成——被调方签名在运行期必然已知，这是命名/解包/引用三类特性
     * 在未知 receiver 场景下仍能正确工作的关键。
     *
     * @param array<int,array<int,mixed>> $code
     * @param array<int,int> $refPos
     * @param string $callee 被调函数名（历史参数，保留不用）
     */
    private function compileArgs(Cursor $c, array &$code, array $refPos = [], string $callee = ''): int
    {
        $c->expect('(');
        if ($c->is(')')) {
            $c->next();
            return 0;
        }
        $all = $c->collectUntilTop(')', null, 1);
        $raws = $this->splitTopLevel($all, ',');

        $argc = 0;
        foreach ($raws as $a) {
            if ($a === []) {
                continue;
            }
            // 剥离空白/注释后取"有效 token"，并保留其原始下标以便切片
            $sig = [];
            $idx = [];
            foreach ($a as $k => $t) {
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sig[] = $t;
                $idx[] = $k;
            }
            if ($sig === []) {
                continue;
            }
            // 解包实参：...expr（PHP 5.6+）
            if (count($sig) >= 2 && is_array($sig[0]) && $sig[0][0] === T_ELLIPSIS) {
                $this->parseExpr(array_slice($a, $idx[0] + 1), $code);
                $code[] = [VmOp::ARG_SPREAD];
                $argc++;
                continue;
            }
            // 命名实参：name: expr（PHP 8.0+）。注意与 ?: / :: 区分：
            // 此处要求「标识符 + 独立的字符串 ':' 」，而 A::B 的 :: 是独立的
            // T_DOUBLE_COLON token，三元 A?x:y 的第二有效 token 是 '?' 而非 ':'。
            if (count($sig) >= 3 && is_array($sig[0]) && $sig[0][0] === T_STRING
                && is_string($sig[1]) && $sig[1] === ':') {
                $this->parseExpr(array_slice($a, $idx[1] + 1), $code);
                $code[] = [VmOp::ARG_NAMED, $sig[0][1]];
                $argc++;
                continue;
            }
            // 位置实参：简单变量 → PUSH_REF 引用句柄（携带值快照）；
            // 其余表达式原样编译
            if (!$this->tryCompileSimpleRefArg($a, $code)) {
                $this->parseExpr($a, $code);
            }
            $argc++;
        }
        $c->expect(')');
        return $argc;
    }

    /**
     * 实参恰为简单变量（$name，无下标/属性等后缀）时发射 PUSH_REF 引用句柄。
     * 句柄形态 ['__vmref__', 帧变量名, 当前值快照]——运行期按被调方参数规格
     * 决定：by-ref 位做 copy-in / copy-out；非引用位直接取快照值。
     * 返回 false 表示不是简单变量（调用方按普通表达式编译）。
     *
     * @param array<int,mixed> $tokens
     * @param array<int,array<int,mixed>> $code
     */
    private function tryCompileSimpleRefArg(array $tokens, array &$code): bool
    {
        $sig = [];
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $sig[] = $t;
        }
        if (count($sig) === 1 && is_array($sig[0]) && $sig[0][0] === T_VARIABLE) {
            // 帧槽名经 varName() 路由：static 局部变量等共享槽位场景下
            // （$a → \x01sl:c:$a），直接发原名会读到不存在的帧槽得 null
            // （实测 static $a=[]; $a[]=count($a) 首调 count(null) 致命）。
            $code[] = [VmOp::PUSH_REF, $this->varName($sig[0][1])];
            return true;
        }
        return false;
    }

    /**
     * 在 token 序列中按"顶层"分隔符 $sep 切分（忽略括号/方括号/花括号内的分隔符）。
     * @param array<int,mixed> $tokens
     * @return array<int,array<int,mixed>>
     */
    private function splitTopLevel(array $tokens, string $sep): array
    {
        $out = [];
        $cur = [];
        $pd = 0; $bd = 0; $d = 0;
        foreach ($tokens as $tok) {
            if (is_string($tok)) {
                if ($tok === '(') { $pd++; }
                elseif ($tok === ')') { $pd = max(0, $pd - 1); }
                if ($tok === '[') { $bd++; }
                elseif ($tok === ']') { $bd = max(0, $bd - 1); }
                if ($tok === '{') { $d++; }
                elseif ($tok === '}') { $d = max(0, $d - 1); }
                if ($tok === $sep && $pd === 0 && $bd === 0 && $d === 0) {
                    $out[] = $cur;
                    $cur = [];
                    continue;
                }
            } elseif (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $d++;
            }
            $cur[] = $tok;
        }
        $out[] = $cur;
        return $out;
    }

    /**
     * 解析基础项（字面量 / 变量 / 括号 / 调用 / new / 数组）。
     * @param array<int,array<int,mixed>> $code
     */
    private function parsePrimary(Cursor $c, array &$code): void
    {
        $c->skipWs();
        $t = $c->peek();
        if ($t === null) {
            $code[] = [VmOp::PUSH_NULL];
            return;
        }
        if (is_array($t)) {
            // 赋值表达式（$v = expr / $v op= expr / $a[k] = expr / $this->p = expr）：
            // PHP 中赋值是表达式，可出现在任何表达式位置（循环/分支条件、函数实参、
            // 数组元素、三元分支、逻辑右操作数……）。在变量起始处先行识别，
            // 编译后赋值结果留栈；非赋值形态会完整恢复光标，不影响常规解析。
            if ($t[0] === T_VARIABLE && $this->tryCompileAssign($c, $code)) {
                return;
            }
            switch ($t[0]) {
                case T_LNUMBER:
                    $c->next();
                    $code[] = [VmOp::PUSH_C, $this->parseNumber($t[1])];
                    return;
                case T_DNUMBER:
                    $c->next();
                    // 数字分隔符（PHP 7.4+）同样出现在浮点字面量里（1_000.5）
                    $code[] = [VmOp::PUSH_C, (float) str_replace('_', '', $t[1])];
                    return;
                case T_CONSTANT_ENCAPSED_STRING:
                    $c->next();
                    $code[] = [VmOp::PUSH_C, $this->parseString($t[1])];
                    return;
                case T_START_HEREDOC:
                    // heredoc / nowdoc：无插值时编译期折值为常量（token 层
                    // --token-heredoc 的产物与用户源码中的纯字面量 heredoc 均属此类）；
                    // 带插值的 heredoc 抛 VmUnsupported，由语句级 EVAL_STMT 兜底。
                    $c->next();
                    $code[] = [VmOp::PUSH_C, $this->parseHeredoc($c, $t)];
                    return;
                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                    // include 作为表达式出现（$v = include 'x.php'; 等）：
                    // 发射包含逻辑，返回值留栈由上层继续使用
                    $c->next();
                    $this->emitInclude($t[0], $c, $code);
                    return;
                case T_ISSET:
                case T_EMPTY:
                    // isset($a['k']) / empty($a['k']) / 多参数时逐个求与
                    $c->next();
                    $c->expect('(');
                    $allArgs = $this->splitTopLevel($c->collectUntilTop(')', null, 1), ',');
                    $c->expect(')');
                    $args = array_values(array_filter($allArgs, fn ($a) => $this->trimWs($a) !== []));
                    if ($args === []) {
                        throw new VmUnsupported('isset/empty 缺少参数（已回退）');
                    }
                    foreach ($args as $ai => $arg) {
                        [$name, $keys] = $this->parseIssetTarget($this->trimWs($arg));
                        foreach ($keys as $k) { $this->compileExpr($k, $code); }
                        $code[] = [$t[0] === T_ISSET ? VmOp::ISSET_ : VmOp::EMPTY_, $name, count($keys)];
                        if ($ai > 0) { $code[] = [VmOp::BOOL_AND]; }
                    }
                    return;
                case T_EXIT:
                    // exit($x) / die($x) 作为表达式出现时（多数为语句，见 compileStatement）
                    $c->next();
                    $this->compileExit($c, $code);
                    return;
                case T_PRINT:
                    $c->next();
                    $this->parseTernary($c, $code);
                    $code[] = [VmOp::PRINT_];
                    return;
                case T_STRING:
                    // 关键字 true/false/null，或函数调用，或常量
                    $up = strtolower($t[1]);
                    if ($up === 'true') { $c->next(); $code[] = [VmOp::PUSH_C, true]; return; }
                    if ($up === 'false') { $c->next(); $code[] = [VmOp::PUSH_C, false]; return; }
                    if ($up === 'null') { $c->next(); $code[] = [VmOp::PUSH_NULL]; return; }
                    if ($up === 'array') {
                        // array(...) 构造
                        $c->next();
                        $this->parseArrayLegacy($c, $code);
                        return;
                    }
                    // 静态成员访问：C::m() / C::$p / C::CONST / self::m() / Foo\Bar::m()
                    if ($this->peekIsDoubleColon($c, 1)) {
                        $this->parseStaticMember($c, $code);
                        return;
                    }
                    // 函数调用？看下一个是 '('
                    $nx = $c->peek2();
                    if ($nx === '(') {
                        $name = $t[1];
                        $c->next();
                        // _PHPRUN($code) 指令模块：间接执行 eval。
                        // 仅当源码真正出现 _PHPRUN 时才会发射 PHPRUN 操作码（选择性发射）。
                        if ($name === '_PHPRUN') {
                            $argc = $this->compileArgs($c, $code);
                            $code[] = [VmOp::PHPRUN, $argc];
                            return;
                        }
                        // 第三参 callee=函数名：命中 sigCache 时启用命名实参重排 /
                        // 引用参数 PUSH_REF / 解包实参校验（预扫描见 prescanSignature）
                        $argc = $this->compileArgs($c, $code, self::NATIVE_REF_POS[strtolower($name)] ?? [], $name);
                        // 用户函数（预扫描全集）→ CALL（VM 函数表，支持前向引用）；
                        // 其余视为原生函数 → CALL_NATIVE（natTbl 间接，静态不暴露函数名）
                        if (isset($this->funcNames[$name])) {
                            $code[] = [VmOp::CALL, $name, $argc];
                        } else {
                            // refMask：by-ref 参数位图（仅覆盖实际存在的参数位）
                            $refPos = self::NATIVE_REF_POS[strtolower($name)] ?? [];
                            $refMask = 0;
                            foreach ($refPos as $rp) {
                                if ($rp < $argc) {
                                    $refMask |= 1 << $rp;
                                }
                            }
                            $code[] = [VmOp::CALL_NATIVE, $this->natIdx($name), $argc, $refMask];
                        }
                        return;
                    }
                    // 已定义常量（如 PHP_VERSION / M_PI）：编译期求值
                    if (defined($t[1])) {
                        $c->next();
                        $code[] = [VmOp::PUSH_C, constant($t[1])];
                        return;
                    }
                    // 本文件 const 语句声明的用户常量：运行期求值（PHP 常量本就是运行期实体）
                    if (isset($this->constNames[$t[1]])) {
                        $c->next();
                        $code[] = [VmOp::CONST_FETCH, $t[1]];
                        return;
                    }
                    throw new VmUnsupported('VM 不支持的标识符：' . $t[1]);
                case T_VARIABLE:
                    $name = $t[1];
                    $c->next();
                    // 变量类名静态访问：$cls::m() / $cls::$p
                    if ($c->is(T_DOUBLE_COLON)) {
                        $c->next();
                        $mem = $c->peek();
                        $mname = is_array($mem) ? $mem[1] : (string) $mem;
                        $c->next();
                        if ($c->is('(')) {
                            $argc = $this->compileArgs($c, $code);
                            $code[] = [VmOp::SCALL, '__VAR__', $mname, $argc];
                        } else {
                            $code[] = [VmOp::SPROP, '__VAR__', $mname];
                        }
                        return;
                    }
                    // 变量函数调用 $f(...)
                    if ($c->is('(')) {
                        $this->emitPushVar($name, $code); // 变量里存的是函数名
                        $argc = $this->compileArgs($c, $code);
                        $code[] = [VmOp::CALL_VAR, $argc];
                        return;
                    }
                    $this->emitPushVar($name, $code);
                    return;
                case T_NEW:
                    $c->next();
                    $this->parseNew($c, $code);
                    return;
                case T_DIR:
                case T_FILE:
                    // __DIR__ / __FILE__：编译期折值为【原始源码文件】的目录/路径。
                    // 产物是单文件，运行期自身的 __FILE__ 与源文件无关，保留构建期
                    // 取值才是对源语义的忠实保真（include 相对定位也依赖它）。
                    $c->next();
                    $code[] = [VmOp::PUSH_C, $t[0] === T_DIR ? $this->srcDir : $this->srcFile];
                    return;
                case T_MATCH:
                    $this->parseMatch($c, $code);
                    return;
                default:
                    throw new VmUnsupported('VM 不支持的词法：' . token_name($t[0]));
            }
        }
        if (is_string($t) && $t === '(') {
            $c->next();
            $inner = $c->collectUntilTop(')', null, 1);
            $c->expect(')');
            $this->parseExpr($inner, $code);
            return;
        }
        if (is_string($t) && $t === '[') {
            $this->parseArrayShort($c, $code);
            return;
        }
        if (is_string($t) && $t === '"') {
            $this->parseInterpolatedString($c, $code);
            return;
        }
        throw new VmUnsupported('VM 无法解析的表达式起始：' . (is_string($t) ? $t : token_name($t[0] ?? 0)));
    }

    /**
     * 静态成员访问：C::m() / C::$p / C::CONST / self::m() / static::m() / Foo\Bar::m()。
     * 调用方光标位于类名 T_STRING 处。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseStaticMember(Cursor $c, array &$code): void
    {
        // 类名可能带命名空间分隔符：Foo\Bar
        $cls = '';
        while (!$c->eof()) {
            $tk = $c->peek();
            if (is_array($tk) && $tk[0] === T_STRING) { $cls .= $tk[1]; $c->next(); continue; }
            if (is_array($tk) && $tk[0] === T_NS_SEPARATOR) { $cls .= '\\'; $c->next(); continue; }
            break;
        }
        $low = strtolower($cls);
        if ($low === 'self' || $low === 'static') {
            if ($this->curClass === '') {
                throw new VmUnsupported('VM 无法解析 self:: / static::（不在类上下文中，已回退）');
            }
            $cls = $this->curClass;
        } elseif ($low === 'parent') {
            // parent:: 解析为编译期记录的父类名（compileClass 解析 extends）。
            // 父类不在 VM 类表（原生父类）时保留原样，由 SPROP/SCALL 运行期反射路径处理。
            if ($this->curClass === '') {
                throw new VmUnsupported('VM 无法解析 parent::（不在类上下文中，已回退）');
            }
            $parent = $this->classParent[$this->curClass] ?? null;
            if ($parent === null) {
                throw new VmUnsupported('VM 无法解析 parent::（未记录父类名，已回退）');
            }
            $cls = $parent;
        }
        $c->expect(T_DOUBLE_COLON);
        $mem = $c->peek();
        if ($mem === null) {
            throw new VmUnsupported('静态成员语法异常（已回退）');
        }
        // C::class 是特例：直接压入类名字符串
        if (is_array($mem) && $mem[0] === T_STRING && strtolower($mem[1]) === 'class') {
            $c->next();
            $code[] = [VmOp::PUSH_C, $cls];
            return;
        }
        $mname = is_array($mem) ? $mem[1] : (string) $mem;
        $c->next();
        if ($c->is('(')) {
            if (is_array($mem) && $mem[0] === T_VARIABLE) {
                // C16::$m() 动态方法名静态调用：方法名（变量值）先压栈，再压实参，
                // 由 SCALL_VAR 在运行期弹出分发（静默按 null 处理会静默出错；
                // 直接回退 EVAL 也不行——VM 类不是真实 PHP 类，eval 里访问即致命）。
                $code[] = [VmOp::PUSH_V, $mname];
                $argc = $this->compileArgs($c, $code);
                $code[] = [VmOp::SCALL_VAR, $cls, $argc];
                return;
            }
            $argc = $this->compileArgs($c, $code, [], $cls . '::' . $mname);
            $code[] = [VmOp::SCALL, $cls, $mname, $argc];
            return;
        }
        if (is_array($mem) && $mem[0] === T_VARIABLE) {
            // 静态属性：VM 类走共享帧槽位；原生类（DateTime 等）走 SPROP 反射路径
            $prop = ltrim($mname, '$');
            if (isset($this->classes[$cls])) {
                $mangled = $this->staticVarName($cls, $prop);
                $code[] = [VmOp::GLOBAL_REF, $mangled];
                $code[] = [VmOp::PUSH_V, $mangled];
            } else {
                $code[] = [VmOp::SPROP, $cls, $prop];
            }
            return;
        }
        // 类常量（C::CONST / self::CONST / parent::CONST）
        $code[] = [VmOp::SPROP, $cls, $mname];
    }

    /**
     * 解析静态成员类名（self/static/parent/显式类名）为编译期已知的 VM 类名。
     * 返回 null 表示无法在编译期确定（或不是 VM 类——由调用方决定回退路径）。
     */
    private function resolveStaticClassName(string $cls): ?string
    {
        $low = strtolower($cls);
        if ($low === 'self' || $low === 'static') {
            return $this->curClass !== '' ? $this->curClass : null;
        }
        if ($low === 'parent') {
            if ($this->curClass === '') {
                return null;
            }
            return $this->classParent[$this->curClass] ?? null;
        }
        return $cls;
    }

    /**
     * 静态属性左值信息提取：把 matchLvalueSpan 收集的紧凑 span
     * （T_STRING (T_NS_SEPARATOR T_STRING)* T_DOUBLE_COLON T_VARIABLE [ '[' key ']' ]）
     * 解析为 ['cls'=>VM类名, 'prop'=>属性名, 'key'=>下标token|null（null=无下标）]。
     * 非静态属性左值 / 非 VM 类返回 null。
     *
     * @param array<int,mixed> $target
     * @return array{cls:string,prop:string,key:array<int,mixed>|null}|null
     */
    private function staticPropLvalueInfo(array $target): ?array
    {
        $t0 = $target[0] ?? null;
        if (!(is_array($t0) && $t0[0] === T_STRING)) {
            return null;
        }
        $n = count($target);
        if ($n < 3) {
            return null;
        }
        $cls = $t0[1];
        $i = 1;
        while ($i + 1 < $n) {
            $tk = $target[$i];
            if (is_array($tk) && $tk[0] === T_NS_SEPARATOR) {
                $nx = $target[$i + 1] ?? null;
                if (!(is_array($nx) && $nx[0] === T_STRING)) {
                    return null;
                }
                $cls .= '\\' . $nx[1];
                $i += 2;
                continue;
            }
            break;
        }
        $colon = $target[$i] ?? null;
        if (!(is_array($colon) && $colon[0] === T_DOUBLE_COLON)) {
            return null;
        }
        $var = $target[$i + 1] ?? null;
        if (!(is_array($var) && $var[0] === T_VARIABLE)) {
            return null;
        }
        $rest = array_slice($target, $i + 2);
        $key = null;
        if ($rest !== []) {
            // 仅允许 [key] 下标后缀（匹配端已平衡收集）；空 [] 表示追加
            $rn = count($rest);
            if ($rest[0] !== '[' || $rest[$rn - 1] !== ']') {
                return null;
            }
            $key = array_slice($rest, 1, -1);
        }
        $resolved = $this->resolveStaticClassName($cls);
        if ($resolved === null || !isset($this->classes[$resolved])) {
            return null;
        }
        return ['cls' => $resolved, 'prop' => ltrim($var[1], '$'), 'key' => $key];
    }

    /**
     * 从光标处尝试解析静态属性左值 `Class::$prop` / `self::$p` / `parent::$p`
     * （供 parseUnary 的 ++/-- 前缀形态使用）。成功消费 token 返回
     * ['cls'=>VM类名, 'prop'=>属性名]；失败完整恢复光标返回 null。
     */
    private function tryParseStaticPropLvalue(Cursor $c): ?array
    {
        $save = $c->pos();
        $c->skipWs();
        $t = $c->peek();
        if (!(is_array($t) && $t[0] === T_STRING)) {
            $c->seek($save);
            return null;
        }
        $clsName = '';
        while (!$c->eof()) {
            $tk = $c->peek();
            if (is_array($tk) && $tk[0] === T_STRING) { $clsName .= $tk[1]; $c->next(); continue; }
            if (is_array($tk) && $tk[0] === T_NS_SEPARATOR) { $clsName .= '\\'; $c->next(); continue; }
            break;
        }
        if (!$c->is(T_DOUBLE_COLON)) {
            $c->seek($save);
            return null;
        }
        $c->next();
        $c->skipWs();
        $mv = $c->peek();
        if (!(is_array($mv) && $mv[0] === T_VARIABLE)) {
            $c->seek($save);
            return null;
        }
        $resolved = $this->resolveStaticClassName($clsName);
        if ($resolved === null || !isset($this->classes[$resolved])) {
            $c->seek($save);
            return null;
        }
        $c->next();
        return ['cls' => $resolved, 'prop' => ltrim($mv[1], '$')];
    }

    /**
     * 判断从当前位置起第 $n 个有效 token 是否为 T_DOUBLE_COLON（跳过空白）。
     */
    private function peekIsDoubleColon(Cursor $c, int $n): bool
    {
        $probe = $c->cloneThis();
        for ($i = 0; $i < $n; $i++) {
            if ($probe->eof()) { return false; }
            $probe->next();
        }
        $probe->skipWs();
        return $probe->is(T_DOUBLE_COLON);
    }

    /**
     * new Class(args) 或 new $cls(args)。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseNew(Cursor $c, array &$code): void
    {
        $c->skipWs();
        $t = $c->peek();
        if ($t === null) {
            throw new VmUnsupported('new 语法异常');
        }
        if (is_array($t) && $t[0] === T_STRING) {
            $cls = $t[1];
            $c->next();
            $argc = $this->compileArgs($c, $code);
            $code[] = [VmOp::NEW, $cls, $argc];
        } elseif (is_array($t) && $t[0] === T_VARIABLE) {
            $clsVar = $t[1];
            $c->next();
            $argc = $this->compileArgs($c, $code);
            // 先压入类名变量，再 NEW_VAR
            $code[] = [VmOp::PUSH_V, $clsVar];
            $code[] = [VmOp::NEW, '__VAR__', $argc];
        } else {
            throw new VmUnsupported('new 仅支持类名 / 类名变量');
        }
    }
}
