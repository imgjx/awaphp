<?php
/**
 * VmCompiler 碎片：声明编译碎片：函数 / 类 / trait 成员 / 参数规格。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性/方法；拆分的只是源码组织，不影响行为。
 */

declare(strict_types=1);

trait VmDeclTrait
{

    // ========================================================================
    // 函数
    // ========================================================================
    /**
     * @param array<int,mixed> $stmt
     */
    private function compileFunction(array $stmt): void
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        $c->expect(T_FUNCTION);
        // 引用返回标志：function &g() —— & 在 PHP 8.1 是
        // T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG（其后是函数名不是变量），
        // 8.0- 是裸 '&'；两者都在此处消费。
        // 注意先 skipWs()：peek() 含空白，不跳过则 isAmpNotFollowedByVar
        // 永远看到 T_WHITESPACE；PHP 8.1+ 的 & 是数组 token，is('&') 也不匹配。
        $c->skipWs();
        if ($this->isAmpNotFollowedByVar($c->peek()) || $c->is('&')) {
            $c->next();
            $byRef = true;
        } else {
            $byRef = false;
        }
        $name = $c->expectText(T_STRING);
        // 进入函数作用域：登记静态局部变量收集表，供变量读写路由到共享帧槽位
        $this->curScopeId = $name;
        $this->curStatic = [];
        $this->curReturnsRef = $byRef;
        if ($byRef) {
            $this->funcRef[$name] = true;
        }
        // 本函数异常表引用 + 异常控制栈复位（每个函数/方法是独立控制流边界）
        $this->funcET[$name] ??= [];
        $this->curSegET = &$this->funcET[$name];
        $this->tryStack = [];
        $this->inFinally = false;
        $c->expect('(');
        $params = $this->parseParams($c);
        $c->expect(')');
        // 跳过返回类型（: type）
        while (!$c->is('{') && !$c->eof()) {
            $c->next();
        }
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $code = [];
        $this->bindParams($code, $params);
        $this->compileBlock($body, $code);
        $this->funcs[$name] = $code;
        // 登记参数规格（默认值段），供运行期调用方补齐缺失实参
        $this->funcParams[$name] = $this->paramSpecs($params);
        // 离开函数作用域：复位，避免串到后续函数/主作用域
        $this->curScopeId = '';
        $this->curStatic = [];
        $this->curReturnsRef = false;
    }

    /**
     * @param array<int,mixed> $stmt
     */
    private function compileClass(array $stmt): void
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        // 跳过 abstract / final / readonly 等修饰符
        // （readonly class 为 PHP 8.2+ 语法；T_READONLY 自 8.1 起存在）
        while (is_array($c->peek()) && in_array($c->type(), [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
            $c->next();
        }
        $kind = $c->type(); // T_CLASS / T_INTERFACE / T_TRAIT
        if ($kind === T_INTERFACE) {
            // 接口：方法签名（无函数体）对执行无影响；但接口常量 I::K 是真实
            // 运行期实体（SPROP 查 classes[...]['consts']），必须编译进类表。
            // 以「无方法/无属性、仅常量」的伪类登记，isset(classes[...]) 的
            // 各判定点（静态成员/instanceof）随之覆盖接口。
            $c->expect(T_INTERFACE);
            $iname = $c->expectText(T_STRING);
            $this->interfaces[$iname] = true;
            while (!$c->is('{') && !$c->eof()) {
                $c->next();
            }
            if ($c->is('{')) {
                $iinner = $c->untilMatchBrace();
                $prevClass = $this->curClass;
                $this->curClass = $iname;
                [, , $iconsts] = $this->compileMembers($iinner, $iname);
                $this->curClass = $prevClass;
                $this->classes[$iname] = ['props' => [], 'methods' => [], 'consts' => $iconsts];
            }
            return;
        }
        if ($kind === T_TRAIT) {
            // trait：与类同样编译成员表，但不实例化；被类 use 时复制进去
            $c->expect(T_TRAIT);
            $tname = $c->expectText(T_STRING);
            while (!$c->is('{') && !$c->eof()) {
                $c->next();
            }
            $c->expect('{');
            $tinner = $c->untilMatchBrace();
            [$tprops, $tmethods, $tconsts, $tstatics] = $this->compileMembers($tinner, $tname);
            $this->traits[$tname] = [
                'props'   => $tprops,
                'methods' => $tmethods,
                'consts'  => $tconsts,
                // trait 静态属性默认值 token（use 时按【使用方类名】重新编译初始化段）
                'statics' => $tstatics,
                // 方法参数规格（默认值段）：随 use 一并复制进使用方类
                'params'  => $this->classParams[$tname] ?? [],
                // 方法签名（命名实参重排 / 引用参数 / 解包校验用）：use 时按
                // 使用方类名复制为 'C::m'，类自身同名方法优先
                'sigs'    => $this->traitSigsOf($tname, array_keys($tmethods)),
            ];
            return;
        }
        $c->expect(T_CLASS);
        $name = $c->expectText(T_STRING);
        // 解析 extends 父类名（供 parent:: 静态成员/方法调用解析）；命名空间父类
        // 按短名记录（VM 类表本就以短名为键，命名空间类不在完整支持范围）。
        $parent = $this->parseExtendsName($c);
        // 解析 implements 接口名列表（instanceof 运行期链遍历用）；
        // 命名空间接口按短名记录（VM 类表以短名为键）。
        $impl = [];
        while (!$c->is('{') && !$c->eof()) {
            if ($c->is(T_IMPLEMENTS)) {
                $c->next();
                while (!$c->is('{') && !$c->eof()) {
                    $t = $c->peek();
                    if (is_array($t) && $t[0] === T_STRING) {
                        $impl[ltrim($t[1], '\\')] = true;
                        $c->next();
                        continue;
                    }
                    if (is_array($t) && $t[0] === T_NS_SEPARATOR) {
                        $c->next();
                        continue;
                    }
                    if ($t === ',') {
                        $c->next();
                        continue;
                    }
                    break;
                }
                continue;
            }
            $c->next();
        }
        if ($impl !== []) {
            $this->classImpl[$name] = $impl;
        }
        // 跳过 extends / implements 链，直到类体 '{'
        while (!$c->is('{') && !$c->eof()) {
            $c->next();
        }
        $c->expect('{');
        $inner = $c->untilMatchBrace();

        $prevClass = $this->curClass;
        $this->curClass = $name;
        // 类表占位必须先于 compileMembers 登记：成员体内的 self::/static:: 解析
        // （parseStaticMember / tryParseStaticPropLvalue）以 isset(classes[...]) 判定
        // "是否 VM 类"，此时类体还没编译完——不占位会把 self::$x 误判为原生类静态
        // 属性而走 SPROP 反射路径（运行期得到 null，静默出错）。
        $this->classes[$name] = ['props' => [], 'methods' => [], 'consts' => []];
        // 预登记自身静态属性名：成员编译期 self::$p 的继承链查找（staticPropOwner）
        // 需要知道「本类声明了哪些静态属性」——PHP 语义：子类未重声明时与父类
        // 同名静态属性共享存储，重声明（含无默认值）则 shadow。
        $this->classStatics[$name] = $this->prescanStaticPropNames($inner);
        // parent:: 解析同理：成员编译期就要能查到父类名。
        if ($parent !== null) {
            $this->classParent[$name] = $parent;
        }
        [$props, $methods, $consts, $statics] = $this->compileMembers($inner, $name);
        $this->classes[$name] = ['props' => $props, 'methods' => $methods, 'consts' => $consts];
        // 静态属性所有权全量登记（编译结果是权威来源：含 trait use 复制进来的）。
        // 成员编译期 self::$p / A::$p 的继承链解析（staticPropOwner）依赖此表：
        // PHP 语义中，子类未重声明静态属性时与父类共享存储，重声明（含无默认值）
        // 则 shadow——只有知道「每个类自己声明了哪些静态属性」才能定 owner。
        $this->classStatics[$name] = array_fill_keys(array_keys($statics), true);
        // 静态属性默认值 → 主程序前导初始化段。仍在类作用域内编译，默认值表达式
        // 可引用 self::CONST 等类常量。
        $this->emitStaticInit($name, $statics);
        $this->curClass = $prevClass;
    }

    /**
     * 预扫描类体【自身声明】的静态属性名集合（name => true）。
     *
     * 必须跑在 compileMembers 之前：成员体内的 self::$p / parent::$p 引用在成员
     * 编译期就要经 staticPropOwner 沿继承链定位「声明类」（定共享帧槽位名），
     * 而此时 emitStaticInit 尚未建槽，只有这张表能回答「本类是否自己声明了 $p」。
     * PHP 语义：子类未重声明时与父类同名静态属性共享存储；重声明（含无默认值）
     * 则 shadow 父类槽位。
     *
     * 只扫类体直接声明；trait use 引入的静态属性由 compileMembers 复制时登记
     * （此时才知道使用方类名），并在 compileClass 末尾以编译结果全量覆盖。
     *
     * @param array<int,mixed> $inner 类体 token（不含外层花括号）
     * @return array<string,true>
     */
    private function prescanStaticPropNames(array $inner): array
    {
        $names = [];
        $members = StatementSplitter::split($inner);
        foreach ($members as $m) {
            if ($this->firstSig($m) === T_USE) {
                continue; // trait use：静态属性编译成员时才复制，此处不预知
            }
            if (!$this->memberIsStatic($m)) {
                continue;
            }
            $ms = $this->stripModifiers($m);
            $f = $this->firstSig($ms);
            if ($f === T_FUNCTION || $f === T_CONST) {
                continue; // 静态方法 / 类常量与静态属性存储无关
            }
            // 属性声明：`static $a = 1, $b;`——与 compileMembers 属性分支同款，
            // 按顶层逗号分段（类型提示内的 ',' 不可能出现，但保守起见分层判定），
            // 逐段跳过类型提示后取首个 T_VARIABLE。
            foreach ($this->splitTopLevel($ms, ',') as $part) {
                $part = $this->trimWs($part);
                if ($part === []) {
                    continue;
                }
                $vc = new Cursor($part);
                $vc->skipWs();
                while (!$vc->eof() && !$vc->is(T_VARIABLE)) {
                    $vc->skipWs();
                    if ($vc->eof() || $vc->is(T_VARIABLE)) {
                        break;
                    }
                    $t = $vc->peek();
                    if (is_array($t) && $t[0] === T_STRING) { $vc->next(); continue; }
                    if (is_array($t) && $t[0] === T_NS_SEPARATOR) { $vc->next(); continue; }
                    if (is_string($t) && in_array($t, ['?', '|', '&'], true)) { $vc->next(); continue; }
                    break;
                }
                if ($vc->is(T_VARIABLE)) {
                    $names[ltrim($vc->expectText(T_VARIABLE), '$')] = true;
                }
            }
        }
        return $names;
    }

    /**
     * 解析 class 声明头部（类名之后）的 extends 父类名。
     * 只取第一个 extends（PHP 类仅单继承）；接口多 extends / implements 不记录。
     * 光标停在 extends 名之后（由调用方继续推进到 '{'）。
     */
    private function parseExtendsName(Cursor $c): ?string
    {
        while (!$c->eof() && !$c->is('{')) {
            if ($c->is(T_IMPLEMENTS)) {
                break; // extends 之后（或无 extends 时）的 implements 交由调用方解析
            }
            if ($c->is(T_EXTENDS)) {
                $c->next();
                $c->skipWs();
                $name = '';
                while (!$c->eof()) {
                    $t = $c->peek();
                    if (is_array($t) && $t[0] === T_STRING) { $name .= $t[1]; $c->next(); continue; }
                    if (is_array($t) && $t[0] === T_NS_SEPARATOR) { $name .= '\\'; $c->next(); continue; }
                    break;
                }
                $name = ltrim($name, '\\');
                return $name !== '' ? $name : null;
            }
            $c->next();
        }
        return null;
    }

    /**
     * 编译类 / trait 成员表（方法 + 属性 + 常量 + 静态属性）。
     * 支持 trait 引入：`use LoggerTrait;` / `use A, B;`——把已编译 trait 的
     * 方法、属性、常量与静态属性复制进来（类自身成员优先，覆盖同名 trait 成员）。
     * trait 适配块（`use A { ... }` 的 insteadof / as）暂不支持，直接回退。
     *
     * @param array<int,mixed> $inner 类体 token（不含外层花括号）
     * @return array{0:array<string,array<int,array<int,mixed>>|null>,1:array<string,array<int,array<int,mixed>>>,2:array<string,array<int,array<int,mixed>>|null>,3:array<string,array<int,mixed>|null>}
     *         第 4 项：静态属性名 => 默认值表达式 token（null = 无默认值，首次绑定时为 null）
     */
    private function compileMembers(array $inner, string $owner): array
    {
        $props = [];
        $methods = [];
        $consts = [];
        $statics = [];
        $members = StatementSplitter::split($inner);
        foreach ($members as $m) {
            $sig = $this->firstSig($m);
            if ($sig === T_USE) {
                // use TraitName[, Other]; —— 复制 trait 成员（类自身成员优先）
                $names = $this->parseUseTraitNames($m);
                foreach ($names as $tn) {
                    if (!isset($this->traits[$tn])) {
                        throw new VmUnsupported("VM 不支持的 trait：{$tn}（未先声明或已适配，已回退）");
                    }
                    foreach ($this->traits[$tn]['methods'] as $mn => $mc) {
                        if (!isset($methods[$mn])) {
                            $methods[$mn] = $mc;
                        }
                    }
                    foreach ($this->traits[$tn]['props'] as $pn => $pd) {
                        if (!array_key_exists($pn, $props)) {
                            $props[$pn] = $pd;
                        }
                    }
                    // trait 常量一并复制；类自身同名常量优先
                    foreach (($this->traits[$tn]['consts'] ?? []) as $cn => $cseg) {
                        if (!array_key_exists($cn, $consts)) {
                            $consts[$cn] = $cseg;
                        }
                    }
                    // trait 静态属性一并复制；类自身同名静态属性优先。
                    // 注意默认值仍是【token】（未编译），use 方按自己的类名编译初始化段，
                    // 故 self:: 引用会解析到使用方类（PHP 语义）。
                    foreach (($this->traits[$tn]['statics'] ?? []) as $pn => $seg) {
                        if (!array_key_exists($pn, $statics)) {
                            $statics[$pn] = $seg;
                        }
                        // 登记所有权：use 进来的静态属性归使用方类所有
                        // （PHP 语义：trait 静态属性在使用方类上下文中生效）
                        $this->classStatics[$owner][$pn] = true;
                    }
            // trait 方法的参数规格（默认值段）一并复制；类自身同名方法优先
            foreach (($this->traits[$tn]['params'] ?? []) as $mn => $sp) {
                if (!isset($this->classParams[$owner][$mn])) {
                    $this->classParams[$owner][$mn] = $sp;
                }
            }
            // trait 方法签名一并复制为 'owner::m'（类自身 prescan 过的优先）：
            // use 进来的方法在调用点（$this->m() / self::m()）同样需要签名表
            foreach (($this->traits[$tn]['sigs'] ?? []) as $mn => $sg) {
                if (!isset($this->sigCache[$owner . '::' . $mn])) {
                    $this->sigCache[$owner . '::' . $mn] = $sg;
                }
            }
                }
                continue;
            }
            // static 修饰符判定必须在 stripModifiers【之前】（后者会把它剥掉）。
            // 静态属性走共享帧槽位方案（见 emitStaticInit / staticVarName）。
            $isStaticMember = $this->memberIsStatic($m);
            // 去掉前导可见性 / static 等修饰符
            $ms = $this->stripModifiers($m);
            $f = $this->firstSig($ms);
            if ($f === T_FUNCTION) {
                // 抽象方法（仅签名、无函数体）：无运行期效果。若不当先跳过，
                // compileFunctionCode 的 expect('{') 会抛 VmUnsupported，
                // 而类编译路径没有语句级兜底——整文件都会被踢出 VM 化。
                // 判定：剥掉属性/修饰符后成员 token 里不存在裸 '{'
                // （属性参数已被 stripModifiers 剥掉；PHP 默认值必须是常量
                // 表达式，不可能含闭包体，故 '{' 只可能来自方法体）。
                if (!$this->hasBraceToken($ms)) {
                    continue;
                }
                // 方法：按函数体编译；从 $ms 中取出方法名（作为静态局部变量作用域标识）
                $mc = new Cursor($ms);
                $mc->skipWs();
                $mc->expect(T_FUNCTION);
                // 引用返回方法 function &m()：消费 &（PHP 8.1+ 为 NOT_FOLLOWED
                // token，8.0- 为裸 '&'）——调用点 $r = &$o->m() 的引用绑定
                // 暂不支持（走语句级回退），此处至少保证声明可编译。
                $mc->skipWs();
                if ($this->isAmpNotFollowedByVar($mc->peek()) || $mc->is('&')) {
                    $mc->next();
                }
                $mn = $mc->expectText(T_STRING);
                if ($mn === null) {
                    continue;
                }
                // 进入方法作用域：静态局部变量按 class::method 键控（跨调用保持、全实例共享）
                $this->curScopeId = $owner . '::' . $mn;
                $this->curStatic = [];
                // 本方法异常表引用 + 异常控制栈复位（每个方法独立控制流边界）
                $this->classET[$owner][$mn] ??= [];
                $this->curSegET = &$this->classET[$owner][$mn];
                $this->tryStack = [];
                $this->inFinally = false;
                // 预登记方法签名（'C::m'）：$this->m() / self::m() / C::m() 调用点据此
                // 支持命名实参重排、引用参数 PUSH_REF 与解包实参校验
                $this->prescanSignature($ms, $owner . '::');
                [$mcode, $mspec] = $this->compileFunctionCode($ms);
                $this->curScopeId = '';
                $this->curStatic = [];
                $this->tryStack = [];
                $this->inFinally = false;
                $methods[$mn] = $mcode;
                // 登记方法参数规格（默认值段），运行期调用前补齐
                $this->classParams[$owner][$mn] = $mspec;
            } elseif ($f === T_CONST) {
                // 类常量：const NAME = expr; 或 const A = 1, B = 2;（值编译为小段，惰性求值）
                $cc = new Cursor($ms);
                $cc->skipWs();
                $cc->expect(T_CONST);
                $rest = $cc->untilTerminator(';');
                foreach ($this->splitTopLevel($rest, ',') as $part) {
                    $part = $this->trimWs($part);
                    if ($part === []) {
                        continue;
                    }
                    $pc = new Cursor($part);
                    $pc->skipWs();
                    if (!$pc->is(T_STRING)) {
                        continue;
                    }
                    $cname = $pc->expectText(T_STRING);
                    $pc->skipWs();
                    if (!$pc->is('=')) {
                        continue;
                    }
                    $pc->next();
                    $cseg = [];
                    $this->compileExpr($pc->collectUntilEnd(), $cseg);
                    $consts[$cname] = $cseg;
                }
            } else {
                // 属性声明：可能带类型提示，如 `private string $name;` 或 `$name;`
                $pc = new Cursor($ms);
                $pc->skipWs();
                // 跳过属性类型提示（?int|string Foo\Bar 等），直到遇到变量
                while (!$pc->eof() && !$pc->is(T_VARIABLE)) {
                    $pc->skipWs();
                    if ($pc->eof() || $pc->is(T_VARIABLE)) {
                        break;
                    }
                    $t = $pc->peek();
                    if (is_array($t) && $t[0] === T_STRING) { $pc->next(); continue; }
                    if (is_array($t) && $t[0] === T_NS_SEPARATOR) { $pc->next(); continue; }
                    if (is_string($t) && in_array($t, ['?', '|', '&'], true)) { $pc->next(); continue; }
                    break;
                }
                if (!$pc->is(T_VARIABLE)) {
                    continue; // const / use / 空行等忽略
                }
                $pname = substr($pc->expectText(T_VARIABLE), 1);
                // 默认值表达式单独编译成小段，运行期在 NEW 时执行
                if ($pc->is('=')) {
                    $pc->next();
                    $exprTokens = $pc->untilTerminator(';');
                    if ($isStaticMember) {
                        // 静态属性：默认值 token 交由 emitStaticInit 在 main 前导段编译
                        // （GLOBAL_REF 绑定共享帧槽位 + POP_V 写入）。
                        $statics[$pname] = $exprTokens;
                    } else {
                        $dcode = [];
                        $this->compileExpr($exprTokens, $dcode);
                        $props[$pname] = $dcode;
                    }
                } else {
                    if ($isStaticMember) {
                        $statics[$pname] = null;
                    } else {
                        $props[$pname] = null;
                    }
                }
            }
        }
        return [$props, $methods, $consts, $statics];
    }

    /**
     * 把类的静态属性默认值编译为 main 前导初始化段。
     *
     * 方案：每个静态属性 C::$p 映射为一个 mangled 共享帧槽位（staticVarName），
     * 前导段对每个带默认值的属性发射：
     *     GLOBAL_REF <mangled>   // 帧槽位绑为共享帧引用（PHP global 语义同款机制）
     *     <默认值表达式字节码>    // 压值
     *     POP_V <mangled>        // 经引用写穿到共享帧
     * 之后任意帧内 C::$p 的读/写/自增/复合赋值都以 GLOBAL_REF + PUSH_V/POP_V/INC_OP
     * 展开（见 VmExprTrait），天然获得「跨调用保持、全实例共享」的静态属性语义，
     * 无需新增 opcode / Program 表 / 序列化格式。
     *
     * @param array<string,array<int,mixed>|null> $statics 属性名 => 默认值 token
     */
    private function emitStaticInit(string $cls, array $statics): void
    {
        foreach ($statics as $pname => $exprTokens) {
            $mangled = $this->staticVarName($cls, $pname);
            if ($exprTokens === null || $exprTokens === []) {
                // 无默认值：仍需建共享帧槽——static::$p 的 LSB_BIND 靠「槽位存在性」
                // 区分「本类声明了该属性（默认 null）」与「未声明（应沿继承链上找）」，
                // 否则子类重声明的 null 属性会错误读到父类默认值。
                $this->staticInit[] = [VmOp::GLOBAL_REF, $mangled];
                continue;
            }
            $code = [];
            $code[] = [VmOp::GLOBAL_REF, $mangled];
            $this->compileExpr($exprTokens, $code);
            $code[] = [VmOp::POP_V, $mangled];
            $this->staticInit = array_merge($this->staticInit, $code);
        }
    }

    /**
     * 静态属性 → 共享帧槽位名。\x01 与 ':' 都不可能出现在 PHP 变量名中，
     * 与用户变量不可能碰撞；GLOBAL_REF 绑定后 PUSH_V/POP_V/++/-- 全部穿透共享帧。
     */
    private function staticVarName(string $cls, string $prop): string
    {
        return "\x01sp:" . $cls . ":" . $prop;
    }

    /**
     * 函数/方法内的静态局部变量 → 共享帧槽位名。与 staticVarName 同理，用不可出现
     * 于 PHP 变量名的 \x01 前缀隔离，并按函数/方法作用域键控（$scopeId 为函数名或
     * class::method）。GLOBAL_REF 绑定后 PUSH_V/POP_V/++/-- 全部穿透共享帧，
     * 从而获得「跨调用保持」的 static 局部语义。
     */
    private function staticLocalName(string $scopeId, string $var): string
    {
        return "\x01sl:" . $scopeId . ":" . $var;
    }

    /**
     * 判定成员声明前导修饰符区是否含 static（`public static $x` / `static $x`）。
     * 只在第一个非修饰符 token 之前扫描，默认值表达式里的 static（如 `new static`）
     * 不可能被误判。属性块 #[...] 与修饰符可能交替出现，需一并跳过。
     * @param array<int,mixed> $stmt
     */
    private function memberIsStatic(array $stmt): bool
    {
        $n = count($stmt);
        $i = 0;
        while ($i < $n) {
            $t = $stmt[$i];
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipAttributeBlock($stmt, $i);
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY, T_ABSTRACT, T_FINAL], true)) {
                $i++;
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            if (is_array($t) && $t[0] === T_STATIC) {
                return true;
            }
            break;
        }
        return false;
    }

    /**
     * 解析 `use TraitA, TraitB;`（类体内）中的 trait 名列表。
     * 出现适配块（`{`）时抛回退——insteadof / as 的语义改写暂不支持。
     *
     * @param array<int,mixed> $stmt
     * @return array<int,string>
     */
    private function parseUseTraitNames(array $stmt): array
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        $c->expect(T_USE);
        $names = [];
        while (!$c->eof() && !$c->is(';') && !$c->is('{')) {
            $c->skipWs();
            if ($c->is(T_STRING)) {
                $n = $c->expectText(T_STRING);
                // 支持命名空间形式 Foo\Bar
                while ($c->is(T_NS_SEPARATOR)) {
                    $c->next();
                    if ($c->is(T_STRING)) {
                        $n .= '\\' . $c->expectText(T_STRING);
                    }
                }
                $names[] = $n;
            }
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
                continue;
            }
            break;
        }
        if ($c->is('{')) {
            throw new VmUnsupported('VM 不支持 trait 适配块（insteadof/as，已回退）');
        }
        return $names;
    }

    /**
     * 编译函数体 token 为字节码（供方法复用）。
     * @param array<int,mixed> $stmt
     * @return array{0:array<int,array<int,mixed>>,1:array<int,array{0:string,1:array<int,array<int,mixed>>|null}>} [字节码, 参数规格]
     */
    private function compileFunctionCode(array $stmt): array
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        $c->expect(T_FUNCTION);
        // 引用返回标志：function &m()（方法级；消费 & 避免 Parse error，
        // 调用点 $r = &$o->m() 的引用绑定暂不支持，走语句级回退）。
        // 同 compileFunction：先 skipWs() 再判定（peek 含空白）。
        $c->skipWs();
        if ($this->isAmpNotFollowedByVar($c->peek()) || $c->is('&')) {
            $c->next();
        }
        $name = $c->expectText(T_STRING);
        $c->expect('(');
        $params = $this->parseParams($c);
        $c->expect(')');
        while (!$c->is('{') && !$c->eof()) {
            $c->next();
        }
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $code = [];
        $this->bindParams($code, $params);
        // 构造函数属性提升：public/protected/private 参数即属性声明，
        // 绑定参数后立即 `$this->p = $p`（PHP 语义：提升参数在构造函数
        // 执行前赋给同名属性；带默认值的提升参数由运行期补齐后同样赋值）。
        foreach ($params as $p) {
            if (empty($p['promote'])) {
                continue;
            }
            $code[] = [VmOp::LOAD_THIS];
            $code[] = [VmOp::PUSH_V, $p['n']];
            $code[] = [VmOp::PROPSET, ltrim($p['n'], '$')];
        }
        $this->compileBlock($body, $code);
        return [$code, $this->paramSpecs($params)];
    }

    /** 判定 token 流中是否存在裸 '{' 字符 token（方法体标记） */
    private function hasBraceToken(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if ($t === '{') {
                return true;
            }
        }
        return false;
    }

    /**
     * 去掉成员签名前导的可见性/static/abstract/final/readonly/var 等修饰符
     * 与属性块（#[...]）。
     * 注意：只去掉"修饰符/属性本身"，保留后面的 function 关键字、函数名、类型提示等。
     * @param array<int,mixed> $stmt
     * @return array<int,mixed>
     */
    private function stripModifiers(array $stmt): array
    {
        $skip = [
            T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL,
            T_READONLY, T_VAR,
        ];
        $out = [];
        $n = count($stmt);
        $i = 0;
        // 仅跳过开头的修饰符/属性块，遇到第一个其它 token 即停止并保留其后所有内容
        // （属性块与修饰符可能交替出现，如 #[A] public static function）。
        // 修饰符之间的空白/注释必须一并跳过——否则 `public static function`
        // 剥掉 public 后卡在空白上，static 永远剥不掉，firstSig 判出 T_STATIC，
        // 静态方法会被误送进属性分支而静默丢失（实测 inc() 方法消失的根因）。
        while ($i < $n) {
            $t = $stmt[$i];
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipAttributeBlock($stmt, $i);
                continue;
            }
            if (is_array($t) && in_array($t[0], $skip, true)) {
                $i++;
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            break;
        }
        for (; $i < $n; $i++) {
            $out[] = $stmt[$i];
        }
        return $out;
    }

    // ========================================================================
    // 参数
    // ========================================================================
    /**
     * 解析参数列表（含类型提示、引用/可变参数回退、默认值）。
     *
     * 返回「参数规格」：每项为 [变量名, 默认值表达式 token|null]。
     * 默认值 token 在编译期由 paramSpecs() 编译为迷你指令段，运行期在调用方
     * 实参不足时按序补齐（PHP 的默认参数求值是惰性的，故必须段化而非编译期常量）。
     *
     * @return array<int,array{0:string,1:array<int,mixed>|null}>
     */
    private function parseParams(Cursor $c): array
    {
        $params = [];
        $c->skipWs();
        while (!$c->is(')') && !$c->eof()) {
            $c->skipWs();
            if ($c->is(')')) {
                break;
            }
            // 构造函数属性提升：public/protected/private/readonly 前缀的参数
            // （PHP 8.0+）——记录提升标记，由 __construct 编译期发射
            // `$this->p = $p` 前导赋值（见 compileFunctionCode）。
            $promote = false;
            while (($t = $c->peek()) !== null) {
                if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                    $promote = true;
                    $c->next();
                    $c->skipWs();
                    continue;
                }
                break;
            }
            // 跳过类型（含 ? | 等）。注意：此处【不】吞 `&`——`&` 既可能是
            // 引用参数标记（&$x），也可能是交集类型（A&B，PHP 8.1+ 用
            // T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG 表示）；若在此吞掉，
            // `&$x` 的引用语义会被静默丢弃（实参按值传入、调用方看不到修改）。
            while (($t = $c->peek()) !== null) {
                if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR], true)) {
                    $c->next();
                    continue;
                }
                if (is_string($t) && in_array($t, ['?', '|'], true)) {
                    $c->next();
                    continue;
                }
                if ($this->isAmpNotFollowedByVar($t)) {
                    // 交集类型的 &：跳过，继续读后续类型 token
                    $c->next();
                    continue;
                }
                break;
            }
            // 引用参数 `&$x`：`&` 紧跟变量（PHP 8.1+ 为专用 token，8.0- 为字符串 '&'）
            $byRef = false;
            while ($this->isRefAmp($c)) {
                $c->next();
                $byRef = true;
            }
            // 可变参数 `...$args`
            $variadic = false;
            if ($c->is(T_ELLIPSIS)) {
                $c->next();
                $variadic = true;
            }
            $pname = $c->expectText(T_VARIABLE);
            // 默认值：收集到顶层 ',' 或 ')' 为止（括号/数组内的逗号不终止）
            $def = null;
            $c->skipWs();
            if ($c->is('=')) {
                $c->next();
                $def = $c->untilParamEnd();
            }
            $params[] = [
                'n' => $pname,
                'd' => $def,
                'promote' => $promote,
                'byRef' => $byRef,
                'variadic' => $variadic,
            ];
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
            }
        }
        return $params;
    }

    /**
     * 收集 trait 已 prescan 的方法签名（key 为 'trait::m'）。
     * compileMembers 对每个方法调用过 prescanSignature，这里按方法名取出。
     *
     * @param array<int,string> $mnames 方法名列表
     * @return array<string,array{names:array<int,string>,refs:array<int,true>,variadic:bool}>
     */
    private function traitSigsOf(string $tname, array $mnames): array
    {
        $out = [];
        foreach ($mnames as $mn) {
            $k = $tname . '::' . $mn;
            if (isset($this->sigCache[$k])) {
                $out[$mn] = $this->sigCache[$k];
            }
        }
        return $out;
    }

    /**
     * 预扫描一条类/trait/接口声明语句，登记其全部方法签名（key='C::m'）。
     * 只做 token 切分与 prescanSignature，不编译成员——供 compile() 的顶层
     * 预扫描阶段调用（方法互调可先于声明出现）。
     *
     * @param array<int,mixed> $stmt 类声明语句 token（已剥前导属性）
     */
    private function prescanClassSignatures(array $stmt): void
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        while (is_array($c->peek()) && in_array($c->type(), [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
            $c->next();
        }
        if (!in_array($c->type(), [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            return;
        }
        $c->next();
        $nameTok = $c->peekSig();
        if (!is_array($nameTok) || $nameTok[0] !== T_STRING) {
            return;
        }
        $owner = $nameTok[1];
        while (!$c->is('{') && !$c->eof()) {
            $c->next();
        }
        if (!$c->is('{')) {
            return;
        }
        $c->next();
        $inner = $c->untilMatchBrace();
        foreach (StatementSplitter::split($inner) as $m) {
            $ms = $this->stripModifiers($m);
            if ($this->firstSig($ms) !== T_FUNCTION) {
                continue;
            }
            $this->prescanSignature($ms, $owner . '::');
        }
    }

    /**
     * 预扫描一条函数声明语句，登记形参名顺序与引用参数下标。
     *
     * 只做轻量 token 扫描，不编译默认值表达式；默认值里的 `$var`
     * （如 `function f($a = $b)`）必须跳过，否则会被误登记成形参。
     * 任一处形态异常即静默放弃登记——调用点退化为「按位置实参」，
     * 若确实写了命名实参，正式编译阶段会因无法解析而回退，不会误编译。
     *
     * @param array<int,mixed> $stmt function 声明语句 token（应已剥前导属性）
     * @param string $prefix 键前缀：顶层函数为 ''，类/接口/trait 方法为 'C::'
     *                       （方法签名支持前向引用——被调方法可能声明在后面）
     */
    public function prescanSignature(array $stmt, string $prefix = ''): void
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        if (!$c->is(T_FUNCTION)) {
            return;
        }
        $c->next();
        $c->skipWs();
        $nameTok = $c->peekSig();
        if (!is_array($nameTok) || $nameTok[0] !== T_STRING) {
            return;
        }
        $fname = $nameTok[1];
        $c->next(); // 消费函数名 token（peekSig 不移动光标，否则 is('(') 永远判否）
        $c->skipWs();
        if (!$c->is('(')) {
            return;
        }
        $c->next();

        $depth = 0;
        $inDefault = false;
        $pendingRef = false;
        $pendingVar = false;
        $variadic = false;
        $names = [];
        $refs = [];
        while (!$c->eof()) {
            $t = $c->peek();
            if (is_string($t)) {
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    if ($t === ')' && $depth === 0) {
                        break;
                    }
                    $depth = max(0, $depth - 1);
                } elseif ($depth === 0 && !$inDefault && ($t === '&' || $this->isAmpFollowedByVar($t))) {
                    // 引用参数前缀：PHP 8.1+ 是 T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
                    // （数组 token），8.0- 是字符串 '&'；isRefAmp 前瞻排除交集类型 A&B
                    if ($this->isRefAmp($c)) {
                        $pendingRef = true;
                    }
                } elseif ($depth === 0 && $t === '=') {
                    $inDefault = true;
                } elseif ($depth === 0 && $t === ',') {
                    $inDefault = false;
                    $pendingRef = false;
                }
                $c->next();
                continue;
            }
            if (is_array($t) && $this->isAmpFollowedByVar($t) && $depth === 0 && !$inDefault) {
                // PHP 8.1+：`&$x` 的 & 是数组 token（T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG），
                // 不落入上面的字符串分支——单独处理，避免引用参数漏登记
                if ($this->isRefAmp($c)) {
                    $pendingRef = true;
                }
                $c->next();
                continue;
            }
            if ($depth === 0 && $t[0] === T_ELLIPSIS) {
                // 可变参数前缀：仅当其后紧跟变量时成立
                $nx = $c->peekSigAt(1);
                if (is_array($nx) && $nx[0] === T_VARIABLE) {
                    $pendingVar = true;
                }
            } elseif ($depth === 0 && $t[0] === T_VARIABLE && !$inDefault) {
                $names[] = $t[1];
                if ($pendingRef) {
                    $refs[count($names) - 1] = true;
                }
                if ($pendingVar) {
                    $variadic = true;
                }
                $pendingRef = false;
                $pendingVar = false;
            }
            $c->next();
        }
        $this->sigCache[$prefix . $fname] = [
            'names'    => $names,
            'refs'     => $refs,
            // 末位形参是否为可变参数（...$x）：决定解包调用 f(...$a) 能否安全编译——
            // 仅当被调方以可变参数收尾时，解包出的值才全部落进该参数、顺序语义等价。
            'variadic' => $variadic,
        ];
    }

    /**
     * 判断 token 是否为【交集类型】的 `&`（PHP 8.1+ 的
     * T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG）。
     * @param mixed $t
     */
    private function isAmpNotFollowedByVar($t): bool
    {
        if (!defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')) {
            return false;
        }
        return is_array($t) && $t[0] === constant('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG');
    }

    /**
     * 判断 token 是否为【引用参数前缀】的 `&`：其后紧跟变量或 ...$var。
     * PHP 8.1+：T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG；PHP 8.0-：字符串 '&'。
     * @param mixed $t
     */
    private function isAmpFollowedByVar($t): bool
    {
        if (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')) {
            return is_array($t) && $t[0] === constant('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG');
        }
        return is_string($t) && $t === '&';
    }

    /**
     * 光标当前位置是否为【引用参数前缀】`&`：其后紧跟 $var 或 ...$var。
     * 用于把 `&$x`（引用参数）与 `A&B`（交集类型）区分开——后者在 PHP 8.1+
     * 是 T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG，前瞻判定可自然排除。
     */
    private function isRefAmp(Cursor $c): bool
    {
        $t = $c->peek();
        if ($t === null || !$this->isAmpFollowedByVar($t)) {
            return false;
        }
        $n = $c->peekSigAt(1);
        if (is_array($n) && $n[0] === T_VARIABLE) {
            return true;
        }
        return is_array($n) && $n[0] === T_ELLIPSIS;
    }

    /**
     * 把 parseParams 的富结构编译为「参数规格」：默认值表达式编译成迷你段。
     * 规格项：[参数名, 默认值段|null, 标志位]（标志位：1=引用参数 &$x，2=可变参数 ...$x）
     * @param array<int,array{0:string,1:array<int,mixed>|null}> $params
     * @return array<int,array{0:string,1:array<int,array<int,mixed>>|null,2:int}>
     */
    private function paramSpecs(array $params): array
    {
        $spec = [];
        foreach ($params as $p) {
            $seg = null;
            if ($p['d'] !== null && $p['d'] !== []) {
                $code = [];
                $this->compileExpr($p['d'], $code);
                $seg = $code;
            }
            $flags = (($p['byRef'] ?? false) ? 1 : 0) | (($p['variadic'] ?? false) ? 2 : 0);
            $spec[] = [$p['n'], $seg, $flags];
        }
        return $spec;
    }

    /**
     * 在字节码头部绑定参数：从栈上逆序弹出并写入局部变量。
     * 注意：实参不足时由运行期调用方先补齐默认值段，故此处仍按参数个数 POP。
     * @param array<int,array{0:string,1:array<int,mixed>|null}> $params
     * @param array<int,array<int,mixed>> $code
     */
    private function bindParams(array &$code, array $params): void
    {
        $n = count($params);
        for ($i = $n - 1; $i >= 0; $i--) {
            $code[] = [VmOp::POP_V, $params[$i]['n']];
        }
    }
}
