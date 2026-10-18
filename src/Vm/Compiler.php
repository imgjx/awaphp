<?php
/**
 * 自定义 VM 编译器（VM Compiler）
 *
 * 把一段（已做变量/函数/类名混淆、字符串/数字编码后的）PHP 源码，自顶向下递归下降编译为
 * 一套自定义字节码（见 VmOp）。字节码 + 常量池 + 函数表 + 类表 组成 Program，序列化后由
 * src/Vm/runtime.php 的解释器在运行期执行。
 *
 * 设计取舍：
 *   - 这是一套"真·自定义 ISA"：字节码里没有任何 PHP 语句，只有 opcode 序号 + 常量池引用。
 *     静态分析解出来的只是一串 opcode，看不到任何可读逻辑（旧版 VM 壳解出来就是 PHP，等于没壳）。
 *   - 由于完整 PHP 语义过于庞大，本编译器支持"常见子集"（函数/类/方法/递归/常用表达式与控制流，
 *     数组、属性、方法调用等）。遇到不支持的语法（闭包、箭头函数、trait、match、引用、yield…）
 *     抛出 VmUnsupported，由上层 VirtualMachine 捕获并优雅回退（保留原代码，不破坏构建）。
 *   - 变量以"名字"为单位存于局部变量表（字符串键），所以变量重命名 / 不可见字符装饰都不影响编译。
 */

declare(strict_types=1);

// 部分 PHP 版本未定义以下 tokenizer 常量，这里做兜底（值取自官方定义）。
if (!defined('T_ELLIPSIS')) { define('T_ELLIPSIS', 399); }
if (!defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')) { define('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG', 403); }
if (!defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')) { define('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG', 404); }
// PHP 7+ 的空合并运算符（??）。低版本 tokenizer 未定义时做兜底。
if (!defined('T_COALESCE')) { define('T_COALESCE', 400); }

final class VmCompiler
{
    /** @var array<int,mixed> */
    private array $tokens;
    private int $pos = 0;

    /** @var array<int,mixed> 常量池（int/float/string/bool/null） */
    private array $consts = [];
    /** @var array<string,array<int,array<int,mixed>>> 函数名 => 字节码 */
    private array $funcs = [];
    /** @var array<string,array<int,array{0:string,1:array<int,array<int,mixed>>|null}>> 函数名 => 参数规格（含默认值段） */
    private array $funcParams = [];
    /** @var array<string,array<string,array<int,array{0:string,1:array<int,array<int,mixed>>|null}>>> 类名 => 方法名 => 参数规格 */
    private array $classParams = [];
    /** @var array<string,array{props:array<string,int>,methods:array<string,array<int,array<int,mixed>>>}> */
    private array $classes = [];
    /** @var array<int,array<int,mixed>> 顶层字节码 */
    private array $main = [];
    /** @var array<string,true> 全程序 global 声明过的变量名（不含 $）——供运行期同步全局帧 */
    private array $globalNames = [];
    /** @var array<string,true> 全程序 const 语句声明的常量名（预扫描收集，供标识符解析） */
    private array $constNames = [];
    /** @var array<string,array{props:array,methods:array}> trait 名 => 成员表（use 时复制进类） */
    private array $traits = [];
    /** @var array<string,true> interface 名（仅登记，供 instanceof / 类型提示语义保留） */
    private array $interfaces = [];
    /** @var array<int,true> 已见到的函数/类/方法名（用于循环引用） */
    private int $tempSeq = 0;

    /**
     * include 计划（IncludeResolver::scan 的有序结果）。
     * 与源码中 include 语句的出现顺序一一对应：编译期每遇到一个 include
     * 表达式就消费一项；静态可解析的项带 embed id（发射 INCLUDE_EMBED），
     * 否则为 null（发射 INCLUDE_RT 运行期真实包含）。
     * @var array<int,array{kw:int,once:bool,require:bool,embed:?int,cycle:bool,path:string}>
     */
    private array $includePlan = [];
    private int $includeIdx = 0;

    /** 原始源码文件路径 / 目录（__DIR__ / __FILE__ 编译期折值） */
    private string $srcFile = '';
    private string $srcDir = '';
    /** @ 错误抑制标志：parseUnary 消费 '@' 时置位，emitInclude 取用后清除 */
    private bool $suppressInc = false;
    /** @var array<string,true> 全程序顶层函数名（预扫描收集，供调用点分流 CALL/CALL_NATIVE） */
    private array $funcNames = [];
    /**
     * 原生函数名表：name => idx（按首次分配顺序编号）。
     * CALL_NATIVE 的操作数是 idx；表随程序打包，字节码/常量池里不出现函数名。
     * @var array<string,int>
     */
    private array $natTbl = [];

    /** 原生函数名 → natTbl 索引（首次登记分配 idx） */
    private function natIdx(string $name): int
    {
        if (!isset($this->natTbl[$name])) {
            $this->natTbl[$name] = count($this->natTbl);
        }
        return $this->natTbl[$name];
    }

    /** @return array<string,int> 原生函数名表（name => idx） */
    public function natTbl(): array
    {
        return $this->natTbl;
    }

    /**
     * 常用 by-ref 原生函数表：函数名（小写） => 按引用传参的位置列表。
     * 这些函数的第一参（或指定参）必须是真实引用才能原地修改调用方的变量，
     * VM 栈式传值不支持——对应位置改发 PUSH_REF 引用句柄，运行期绑定帧变量
     * 真实引用后传入。引用位不是简单变量时（如 $obj->p、$a['k']）编译期抛
     * VmUnsupported，由语句级回退（EVAL_STMT）以原生语义执行。
     */
    private const NATIVE_REF_POS = [
        'sort' => [0], 'rsort' => [0], 'asort' => [0], 'arsort' => [0],
        'ksort' => [0], 'krsort' => [0], 'usort' => [0], 'uasort' => [0],
        'uksort' => [0], 'shuffle' => [0],
        'array_push' => [0], 'array_pop' => [0], 'array_shift' => [0],
        'array_unshift' => [0], 'array_splice' => [0],
        'reset' => [0], 'end' => [0], 'next' => [0], 'prev' => [0],
        'current' => [0], 'key' => [0], 'each' => [0],
        'preg_match' => [2], 'preg_match_all' => [2],
        'str_replace' => [3], 'str_ireplace' => [3],
        'settype' => [0],
    ];

    public function setIncludePlan(array $plan): void
    {
        $this->includePlan = $plan;
        $this->includeIdx = 0;
    }

    /** 设置原始源码文件上下文（__DIR__/__FILE__ 编译期折值用） */
    public function setSourceContext(string $curFile, string $baseDir): void
    {
        $this->srcFile = $curFile;
        $this->srcDir = $curFile !== '' ? dirname($curFile) : $baseDir;
    }

    /**
     * 预登记外部已知的函数名（include 嵌入段定义的顶层函数）。
     * PHP 语义：被包含文件声明的函数在包含后全局可见，主程序对其调用必须路由到
     * CALL（VM 函数表）而非 CALL_NATIVE——嵌入段函数是 VM 字节码，不是真实
     * PHP 函数，CALL_NATIVE 会在运行期致命。
     * 须在 compile() 之前调用（compile() 只追加不清空 funcNames）。
     * 入参兼容两种形态：函数名列表 ['f','g'] 或名=>true 集合 ['f'=>true]。
     *
     * @param array<int|string,mixed> $names
     */
    public function addKnownFunctions(array $names): void
    {
        foreach ($names as $k => $v) {
            $n = is_string($v) && $v !== '' ? $v : (is_string($k) && $k !== '' ? $k : '');
            if ($n !== '') {
                $this->funcNames[$n] = true;
            }
        }
    }

    /** 消费下一项 include 计划（顺序消费，保证与扫描序一致） */
    private function nextIncludeEntry(): ?array
    {
        if ($this->includeIdx < count($this->includePlan)) {
            return $this->includePlan[$this->includeIdx++];
        }
        return null;
    }

    /** @var string 当前正在编译的类名（用于 self:: / static:: 解析） */
    private string $curClass = '';
    /** @var array<int,array{k:string,id:int}> 中断上下文栈：loop / switch */
    private array $ctxStack = [];
    /** @var array<int,array{idx:int,sw:int}> 待回填的 switch-break 占位 */
    private array $pendingSwBreaks = [];

    /**
     * @return array{consts:array,funcs:array,funcParams:array,classes:array,classParams:array,main:array,globals:array,natTbl:array}
     */
    public function compile(string $code): array
    {
        $this->tokens = Parser::tokenize('<?php ' . $code);
        // 去掉开/闭标签，仅保留逻辑 token
        $body = [];
        foreach ($this->tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $body[] = $t;
        }
        $this->tokens = $body;
        $this->pos = 0;

        $stmts = StatementSplitter::split($this->tokens);
        // 预扫描：收集全程序 const 声明的常量名，使标识符解析能识别「运行期定义的常量」
        // （PHP 的常量在运行期才存在，编译期 defined() 看不到，需按名发射 CONST_FETCH）
        foreach ($stmts as $stmt) {
            if ($this->firstSig($stmt) !== T_CONST) {
                continue;
            }
            foreach ($stmt as $tk) {
                if (is_array($tk) && $tk[0] === T_STRING) {
                    $this->constNames[$tk[1]] = true;
                }
            }
        }
        // 预扫描：收集全部顶层函数名。函数调用点在遇到声明之前就可能出现（PHP 顶层
        // 函数声明支持前向引用——整个文件编译时统一登记），故编译调用点前必须已知
        // 全集，才能正确分流「用户函数 → CALL（VM 函数表）」与「原生函数 → CALL_NATIVE」。
        // 带属性的函数声明（#[A] function f()）同样要登记，否则调用点会按原生函数
        // 编译，而函数本体已进 VM 字节码表——运行期 Call to undefined function。
        foreach ($stmts as $stmt) {
            $s = $this->stripLeadingAttributes($stmt);
            if ($this->firstSig($s) !== T_FUNCTION) {
                continue;
            }
            foreach ($s as $tk) {
                if (is_array($tk) && $tk[0] === T_STRING) {
                    $this->funcNames[$tk[1]] = true;
                    break;
                }
            }
        }
        foreach ($stmts as $stmt) {
            $this->compileTopStatement($stmt);
        }
        return [
            'consts'      => $this->consts,
            'funcs'       => $this->funcs,
            'funcParams'  => $this->funcParams,
            'classes'     => $this->classes,
            'classParams' => $this->classParams,
            'main'        => $this->main,
            'globals'     => array_keys($this->globalNames),
            // 原生函数名表（name=>idx）：CALL_NATIVE 操作数索引本表，
            // 直接消费 compile() 返回值的一方（测试驱动/独立打包）无需再手工装配。
            'natTbl'      => $this->natTbl,
        ];
    }

    /**
     * 编译一条顶层语句：函数声明 / 类声明 / 普通语句。
     * @param array<int,mixed> $stmt
     */
    private function compileTopStatement(array $stmt): void
    {
        // 按"有效声明关键字"分发（跳过前导属性块与 final/abstract/readonly
        // 修饰符），否则 `final class Foo {}` / `#[A] function f() {}` 会被
        // 错送 EVAL_STMT 兜底——原生定义的类/函数体内引用 VM 函数表符号时
        // 直接致命（详见 effectiveDeclSig 的注释）。
        $first = $this->effectiveDeclSig($stmt);
        if ($first === T_FUNCTION) {
            $this->compileFunction($this->stripLeadingAttributes($stmt));
            return;
        }
        if (in_array($first, [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            $this->compileClass($this->stripLeadingAttributes($stmt));
            return;
        }
        $this->compileStatement($stmt, $this->main);
    }

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
        $name = $c->expectText(T_STRING);
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
            // 接口只有方法签名（无函数体），对执行无影响；登记名字后跳过函数体。
            // VM 的 instanceof 尚不支持，接口的运行时作用仅剩 class_exists/类型提示，
            // 二者都不影响字节码语义，故按无操作处理（远比整文件回退更有保护价值）。
            $c->expect(T_INTERFACE);
            $iname = $c->expectText(T_STRING);
            $this->interfaces[$iname] = true;
            while (!$c->is('{') && !$c->eof()) {
                $c->next();
            }
            if ($c->is('{')) {
                $c->untilMatchBrace();
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
            [$tprops, $tmethods, $tconsts] = $this->compileMembers($tinner, $tname);
            $this->traits[$tname] = [
                'props'   => $tprops,
                'methods' => $tmethods,
                'consts'  => $tconsts,
                // 方法参数规格（默认值段）：随 use 一并复制进使用方类
                'params'  => $this->classParams[$tname] ?? [],
            ];
            return;
        }
        $c->expect(T_CLASS);
        $name = $c->expectText(T_STRING);
        // 跳过 extends / implements 链，直到类体 '{'
        while (!$c->is('{') && !$c->eof()) {
            $c->next();
        }
        $c->expect('{');
        $inner = $c->untilMatchBrace();

        $prevClass = $this->curClass;
        $this->curClass = $name;
        [$props, $methods, $consts] = $this->compileMembers($inner, $name);
        $this->classes[$name] = ['props' => $props, 'methods' => $methods, 'consts' => $consts];
        $this->curClass = $prevClass;
    }

    /**
     * 编译类 / trait 成员表（方法 + 属性 + 常量）。
     * 支持 trait 引入：`use LoggerTrait;` / `use A, B;`——把已编译 trait 的
     * 方法、属性与常量复制进来（类自身成员优先，覆盖同名 trait 成员）。
     * trait 适配块（`use A { ... }` 的 insteadof / as）暂不支持，直接回退。
     *
     * @param array<int,mixed> $inner 类体 token（不含外层花括号）
     * @return array{0:array<string,array<int,array<int,mixed>>|null>,1:array<string,array<int,array<int,mixed>>>,2:array<string,array<int,array<int,mixed>>|null>}
     */
    private function compileMembers(array $inner, string $owner): array
    {
        $props = [];
        $methods = [];
        $consts = [];
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
                    // trait 方法的参数规格（默认值段）一并复制；类自身同名方法优先
                    foreach (($this->traits[$tn]['params'] ?? []) as $mn => $sp) {
                        if (!isset($this->classParams[$owner][$mn])) {
                            $this->classParams[$owner][$mn] = $sp;
                        }
                    }
                }
                continue;
            }
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
                // 方法：按函数体编译；从 $ms 中取出方法名
                [$mcode, $mspec] = $this->compileFunctionCode($ms);
                $mc = new Cursor($ms);
                $mc->skipWs();
                $mc->expect(T_FUNCTION);
                $mn = $mc->expectText(T_STRING);
                if ($mn === null) {
                    continue;
                }
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
                    $dcode = [];
                    $this->compileExpr($exprTokens, $dcode);
                    $props[$pname] = $dcode;
                } else {
                    $props[$pname] = null;
                }
            }
        }
        return [$props, $methods, $consts];
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
        // （属性块与修饰符可能交替出现，如 #[A] public static function）
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
            // 跳过类型（含 ? 联合 等）
            while (($t = $c->peek()) !== null) {
                if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR], true)) {
                    $c->next();
                    continue;
                }
                if (is_string($t) && in_array($t, ['?', '|', '&'], true)) {
                    $c->next();
                    continue;
                }
                break;
            }
            if ($c->is(T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
                $c->next();
            }
            if ($c->is('&')) {
                throw new VmUnsupported('VM 不支持引用参数（已回退）');
            }
            if ($c->is(T_ELLIPSIS)) {
                throw new VmUnsupported('VM 不支持可变参数（已回退）');
            }
            $pname = $c->expectText(T_VARIABLE);
            // 默认值：收集到顶层 ',' 或 ')' 为止（括号/数组内的逗号不终止）
            $def = null;
            $c->skipWs();
            if ($c->is('=')) {
                $c->next();
                $def = $c->untilParamEnd();
            }
            $params[] = ['n' => $pname, 'd' => $def];
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
            }
        }
        return $params;
    }

    /**
     * 把 parseParams 的富结构编译为「参数规格」：默认值表达式编译成迷你段。
     * @param array<int,array{0:string,1:array<int,mixed>|null}> $params
     * @return array<int,array{0:string,1:array<int,array<int,mixed>>|null}>
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
            $spec[] = [$p['n'], $seg];
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

    // ========================================================================
    // 语句块
    // ========================================================================
    /**
     * @param array<int,mixed> $block
     * @param array<int,array<int,mixed>> $code
     */
    private function compileBlock(array $block, array &$code): void
    {
        $stmts = StatementSplitter::split($block);
        foreach ($stmts as $stmt) {
            $this->compileStatement($stmt, $code);
        }
        // 末尾若没有返回，补一个 RET_VOID（顶层/函数都需要一个终止）
    }

    /**
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileStatement(array $stmt, array &$code): void
    {
        // 每条语句边界清除 @ 抑制标志，防止跨语句/跨块串味
        // （如 if (@$a) { include 'x.php'; } 中 @ 只覆盖条件）
        $this->suppressInc = false;
        $c = new Cursor($stmt);
        $c->skipWs();
        $t = $c->peek();
        if ($t === null) {
            return;
        }
        // 语句级兜底（EVAL_STMT）：编译该语句途中抛 VmUnsupported 时，不整文件回退，
        // 而是把【整条语句的源码文本】压栈、发射 EVAL_STMT 交运行期 eval 执行。
        // 先记录发射进度标记，失败时截断半成品指令，保证字节码自洽。
        $mark = count($code);
        try {
            $this->compileStatementInner($stmt, $c, $code);
        } catch (VmUnsupported $e) {
            array_splice($code, $mark);
            // 控制流逃逸守卫：return/yield/goto/break/continue/static/global 经
            // eval 兜底后语义会被静默破坏（eval 的 return 不从外层函数返回、
            // static 不跨调用持久也不与帧变量互通、break 出 eval 直接致命）。
            // 这类语句重抛 VmUnsupported，由壳层整文件回退到未 VM 化代码
            // （保留其它层混淆，输出正确、损失该文件的 VM 保护）。
            if ($this->hasControlEscape($stmt)) {
                throw $e;
            }
            $code[] = [VmOp::PUSH_C, $this->detokenize($stmt)];
            $code[] = [VmOp::EVAL_STMT];
        }
    }

    /**
     * 控制流逃逸判定：语句将走 EVAL_STMT 兜底时，检查其是否含「经 eval 后
     * 语义被破坏」的构造。EVAL_STMT 运行期模板虽做了「帧变量→局部变量提取
     * →eval→快照 diff 写回帧」的变量桥接，但以下构造的目标在语句之外，
     * 桥接管不到：
     *   - return / yield / yield from：eval 的返回/产出只作用于 eval 自身，
     *     外层函数静默返回 null、生成器收不到产出（实测 try+return 即此坑）；
     *   - goto：标签在文件别处，eval 内执行直接致命；
     *   - break / continue N：层数超过语句内循环开数（或语句内无循环）时，
     *     目标在语句之外，"Cannot break/continue N level(s)" 致命；
     *   - static $var：静态变量绑定在解释器闭包作用域而非 VM 帧，跨调用
     *     不持久、不与帧变量互通（实测计数器恒输出初值）；
     *   - global：eval 内的引用别名只作用于解释器闭包，VM 帧侧拿到的是
     *     值拷贝，写回丢失。
     * 命中即重抛 VmUnsupported，由壳层整文件回退（保留其它层混淆）。
     *
     * 扫描跳过嵌套闭包/匿名类的方法体——其中的 return 等属于它们自己的
     * 作用域，经 eval 定义后语义正确，不算逃逸。
     *
     * @param array<int,mixed> $tokens
     */
    private function hasControlEscape(array $tokens): bool
    {
        $n = count($tokens);
        // 第一遍：标出闭包/匿名类体区间（[体开 '{', 体闭 '}']，含端点）
        $skip = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_FUNCTION) {
                $nx = Parser::nextSignificant($tokens, $i);
                // 匿名闭包：function 后紧跟 '('（命名函数声明不可能嵌在语句里）
                if ($nx >= 0 && !is_array($tokens[$nx]) && $tokens[$nx] === '(') {
                    $body = $this->findBodyOpen($tokens, $nx);
                    if ($body >= 0) {
                        $end = $this->findBodyEnd($tokens, $body);
                        $skip[] = [$body, $end];
                        $i = $end; // 体内部件（含嵌套闭包）一并跳过
                    }
                }
                continue;
            }
            if ($t[0] === T_CLASS) {
                $pv = Parser::prevSignificant($tokens, $i);
                // 匿名类：new class（Foo::class 的前驱是 T_DOUBLE_COLON，不命中）
                if ($pv >= 0 && is_array($tokens[$pv]) && $tokens[$pv][0] === T_NEW) {
                    $body = $this->findBodyOpen($tokens, $i);
                    if ($body >= 0) {
                        $end = $this->findBodyEnd($tokens, $body);
                        $skip[] = [$body, $end];
                        $i = $end;
                    }
                }
                continue;
            }
        }
        // 第二遍：扫描闭包体之外的逃逸构造
        $loops = 0; // 语句内（闭包体外）已出现的循环/switch 开数
        for ($i = 0; $i < $n; $i++) {
            foreach ($skip as $rng) {
                if ($i >= $rng[0] && $i <= $rng[1]) {
                    continue 2;
                }
            }
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            switch ($t[0]) {
                case T_RETURN:
                case T_YIELD:
                case T_YIELD_FROM:
                case T_GOTO:
                case T_GLOBAL:
                    return true;
                case T_STATIC:
                    // 仅 static 声明（static $var = ...）危险；
                    // static:: / static 闭包 / static fn 均不是声明
                    $nx = Parser::nextSignificant($tokens, $i);
                    if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_VARIABLE) {
                        return true;
                    }
                    break;
                case T_BREAK:
                case T_CONTINUE:
                    // break/continue 的目标通常是语句内循环；仅当层数超过
                    // 语句内循环开数（或无循环可跳）时目标才在语句之外
                    $level = 1;
                    $nx = Parser::nextSignificant($tokens, $i);
                    if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_LNUMBER) {
                        $level = max(1, (int) $tokens[$nx][1]);
                    }
                    if ($level > $loops) {
                        return true;
                    }
                    break;
                case T_WHILE:
                case T_FOR:
                case T_FOREACH:
                case T_DO:
                case T_SWITCH:
                    $loops++;
                    break;
            }
        }
        return false;
    }

    /**
     * 从 $fromIdx 起找函数体/类体的开 '{'：先越过参数/继承链的圆括号深度，
     * 再取括号深度 0 处的第一个 '{'。遇深度 0 的 ';' 即止（无函数体）。
     * 插值串的 T_CURLY_OPEN 是数组 token（文本虽为 '{'），不会误配。
     *
     * @param array<int,mixed> $tokens
     */
    private function findBodyOpen(array $tokens, int $fromIdx): int
    {
        $n = count($tokens);
        $pd = 0;
        for ($i = $fromIdx; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                continue; // 字符串/注释/heredoc 内不可能有裸括号
            }
            if ($t === '(' || $t === '[') {
                $pd++;
            } elseif ($t === ')' || $t === ']') {
                if ($pd > 0) {
                    $pd--;
                }
            } elseif ($t === ';') {
                if ($pd === 0) {
                    return -1;
                }
            } elseif ($t === '{' && $pd === 0) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * 从体开 '{'（$openIdx）找配对的体闭 '}'。
     * 插值花括号深度不对称："{$expr}" 的起始是数组 token T_CURLY_OPEN
     * （文本 '{'），闭合却是裸字符 '}'——只数字符花括号会把插值闭合
     * 误判为体结束，必须把 T_CURLY_OPEN/T_DOLLAR_OPEN_CURLY_BRACES
     * 一并计入深度（与 Cursor::untilMatchBrace 等同理）。
     *
     * @param array<int,mixed> $tokens
     */
    private function findBodyEnd(array $tokens, int $openIdx): int
    {
        $n = count($tokens);
        $depth = 1;
        for ($i = $openIdx + 1; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                continue;
            }
            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return $n - 1; // 未闭合：保守跳到末尾（整个语句都在跳过区内）
    }

    /**
     * 把一条语句的 token 流还原为 PHP 源码文本（供 EVAL_STMT 运行期 eval）。
     *  - 保留 T_INLINE_HTML / 开闭标签：eval('?>html') 合法且会原样输出；
     *  - 丢弃注释：`//`/`#` 单行注释的 token 文本不含换行，直接拼接会把后续
     *    代码注释掉（多行注释含换行本可保留，统一丢弃更稳）；
     *  - heredoc/nowdoc 的各段 token 文本自带换行，拼接后结构完整；
     *  - 补分号：StatementSplitter 把 `;` 当分隔符消费掉了，需补回。
     *    块语句（if/function/class...）末尾的 `;` 是合法空语句；含 inline HTML
     *    的语句不补（分号会泄漏到输出）。
     * @param array<int,mixed> $stmt
     */
    private function detokenize(array $stmt): string
    {
        $out = '';
        $hasHtml = false;
        foreach ($stmt as $tk) {
            if (is_array($tk)) {
                if (in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($tk[0] === T_INLINE_HTML || $tk[0] === T_CLOSE_TAG) {
                    $hasHtml = true;
                }
                $out .= $tk[1];
            } else {
                $out .= $tk;
            }
        }
        if (!$hasHtml) {
            $out .= ';';
        }
        return $out;
    }

    /**
     * compileStatement 的原分发逻辑（抽出以便外层统一兜底）。
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileStatementInner(array $stmt, Cursor $c, array &$code): void
    {
        $t = $c->peek();
        if (is_array($t) && $t[0] === T_ECHO) {
            $c->next();
            $this->compileEcho($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_RETURN) {
            $c->next();
            if ($c->is(';') || $c->eof() || $this->nextIsBlockEnd($stmt)) {
                $code[] = [VmOp::RET_VOID];
                return;
            }
            $expr = $c->untilTerminator(';');
            $this->compileExpr($expr, $code);
            $code[] = [VmOp::RET];
            return;
        }
        if (is_array($t) && $t[0] === T_IF) {
            $this->compileIf($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_WHILE) {
            $this->compileWhile($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_FOR) {
            $this->compileFor($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_FOREACH) {
            $this->compileForeach($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_SWITCH) {
            $this->compileSwitch($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_DO) {
            $this->compileDoWhile($c, $code);
            return;
        }
        if (is_array($t) && in_array($t[0], [T_BREAK, T_CONTINUE], true)) {
            $c->next();
            $this->applyLoopJump($t[0] === T_BREAK ? 'break' : 'continue', $code, $c);
            return;
        }
        if (is_array($t) && $t[0] === T_UNSET) {
            $c->next();
            $this->compileUnset($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_GLOBAL) {
            $c->next();
            $this->compileGlobal($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_CONST) {
            $this->compileConst($stmt, $code);
            return;
        }
        if (in_array($t[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            // 语句级 include（非赋值右值）：发射包含逻辑后丢弃返回值
            $c->next();
            $this->emitInclude($t[0], $c, $code);
            $code[] = [VmOp::DROP];
            return;
        }
        if (is_array($t) && $t[0] === T_EXIT) {
            $c->next();
            $this->compileExit($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_PRINT) {
            $c->next();
            $expr = $c->untilTerminator(';');
            $this->compileExpr($expr, $code);
            $code[] = [VmOp::PRINT_];
            $code[] = [VmOp::DROP];
            return;
        }
        // 赋值语句 / 表达式语句
        $this->compileExprOrAssign($stmt, $code);
    }

    /**
     * 发射一条 include/require 逻辑：
     *  - 计划项带 embed id（构建期已静态解析并嵌入）→ INCLUDE_EMBED；
     *  - 计划项标记循环包含 → 压 true（视为已包含过）；
     *  - 其余（动态路径 / 目标不存在）→ 编译路径表达式 + INCLUDE_RT 运行期真实包含。
     * @param array<int,array<int,mixed>> $code
     */
    private function emitInclude(int $kw, Cursor $c, array &$code): void
    {
        $once = in_array($kw, [T_INCLUDE_ONCE, T_REQUIRE_ONCE], true);
        $require = in_array($kw, [T_REQUIRE, T_REQUIRE_ONCE], true);
        // @include 的诊断抑制（parseUnary 置位，这里取用即清）
        $suppress = $this->suppressInc;
        $this->suppressInc = false;
        $entry = $this->nextIncludeEntry();
        if ($entry !== null && $entry['cycle']) {
            // 构建期发现的循环包含：PHP include_once 的务实行为——直接跳过
            $this->collectIncludePath($c);   // 消费路径 token，避免残余 token 触发回退
            $code[] = [VmOp::PUSH_C, true];
            return;
        }
        if ($entry !== null && $entry['embed'] !== null) {
            $this->collectIncludePath($c);   // 消费路径 token，避免残余 token 触发回退
            $code[] = [VmOp::INCLUDE_EMBED, (int) $entry['embed'], $once ? 1 : 0];
            return;
        }
        // 运行期 include：路径表达式按普通表达式编译（值留栈），再发 INCLUDE_RT。
        // 目标文件在构建期不存在时也走此路——运行期由 PHP 原生产生
        // warning（include）/ fatal error（require），与原生语义一致。
        $expr = $this->collectIncludePath($c);
        $this->compileExpr($expr, $code);
        $flags = ($once ? 1 : 0) | ($require ? 2 : 0) | ($suppress ? 4 : 0);
        $code[] = [VmOp::INCLUDE_RT, $flags];
    }

    /**
     * 收集 include 的路径表达式 token：白名单式收集（字面串/变量/函数调用/
     * __DIR__/__FILE__/插值串/下标/括号/连接点），遇到路径表达式不可能出现的
     * token（三元、比较、逻辑运算、语句分隔符等）即停——兼容语句级
     * （include 'x.php';）与表达式级（$v = include 'x.php' ?: 'd';）两种上下文。
     * @return array<int,mixed>
     */
    private function collectIncludePath(Cursor $c): array
    {
        $out = [];
        $depth = 0;
        while (!$c->eof()) {
            $t = $c->peek();
            if (is_string($t)) {
                if ($t === '(' || $t === '[') {
                    $depth++;
                    $out[] = $c->next();
                    continue;
                }
                if ($t === ')' || $t === ']') {
                    if ($depth === 0) {
                        break;   // 收尾上一层上下文
                    }
                    $depth--;
                    $out[] = $c->next();
                    continue;
                }
                if ($depth === 0 && ($t === ';' || $t === ',' || $t === '?')) {
                    break;
                }
                if ($depth === 0 && $t === '.') {
                    $out[] = $c->next();
                    continue;
                }
                break;   // 其他字符（运算符等）不属于路径表达式
            }
            $ok = in_array($t[0], [
                T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
                T_VARIABLE, T_STRING, T_DIR, T_FILE, T_LNUMBER, T_DNUMBER,
                T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_STRING_VARNAME, T_NUM_STRING,
                T_START_HEREDOC, T_END_HEREDOC,
            ], true);
            if (!$ok) {
                break;
            }
            $out[] = $c->next();
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileExprOrAssign(array $stmt, array &$code): void
    {
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
            // 复合赋值：仅支持简单变量 $x op= v（数组/属性的复合赋值回退到原生执行）
            $t0 = $target[0] ?? null;
            $isSimpleVar = is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 1;
            if (!$isSimpleVar) {
                throw new VmUnsupported('VM 暂不支持数组/属性的复合赋值（已回退）');
            }
            $this->compileLVRead($target, $code);
            $this->compileExpr($valueTokens, $code);
            $code[] = [$compound];
            $this->compileLVStore($target, $code);
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
            // 复合赋值仅支持简单变量（与语句路径一致；数组/属性形态回退原生执行）
            $t0 = $target[0] ?? null;
            if (!(is_array($t0) && $t0[0] === T_VARIABLE && count($target) === 1)) {
                throw new VmUnsupported('VM 暂不支持数组/属性的复合赋值（已回退）');
            }
            $this->compileLVRead($target, $code);
            $this->parseTernary($c, $code);
            $code[] = [$compound];
        } else {
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
        $code[] = [VmOp::PUSH_V, $name];
    }

    /**
     * 编译左值读取（把左值当前值压栈）。仅支持 变量 / 变量[下标] / $this->属性。
     * @param array<int,mixed> $target
     * @param array<int,array<int,mixed>> $code
     */
    private function compileLVRead(array $target, array &$code): void
    {
        $t = $target[0] ?? null;
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) === 1) {
            $this->emitPushVar($t[1], $code);
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) >= 3 && $target[1] === '[') {
            $this->compileExpr([$t], $code);
            $key = array_slice($target, 2);
            if ($key !== [] && $key[count($key) - 1] === ']') {
                array_pop($key);
            }
            $this->compileExpr($key, $code);
            $code[] = [VmOp::INDEX_GET];
            return;
        }
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$this'
            && count($target) >= 3 && $this->tokType($target[1]) === T_OBJECT_OPERATOR) {
            $this->compileExpr([$t], $code);
            $prop = $target[2];
            $pname = is_array($prop) ? $prop[1] : (string) $prop;
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
        $t = $target[0] ?? null;
        if (is_array($t) && $t[0] === T_VARIABLE && count($target) === 1) {
            if ($t[1] === '$GLOBALS') {
                // PHP 禁止整体写 $GLOBALS（"$GLOBALS can only be modified using..."），
                // 整表赋值回退到原生语句执行。
                throw new VmUnsupported('VM 暂不支持 $GLOBALS 整表赋值（已回退）');
            }
            $code[] = [VmOp::POP_V, $t[1]];
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
            $code[] = [VmOp::POP_V, $t[1]]; // 写回（数组为值类型，本地更新需回存）
            return;
        }
        throw new VmUnsupported('VM 不支持的赋值左值形式（已回退）');
    }

    private function compileEcho(Cursor $c, array &$code): void
    {
        $c->skipWs();
        while (!$c->is(';') && !$c->eof()) {
            $expr = $c->collectUntilTop(',', ';');
            $this->compileExpr($expr, $code);
            $code[] = [VmOp::ECHO];
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
                $c->skipWs();
            }
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileIf(Cursor $c, array &$code): void
    {
        $c->expect(T_IF);
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($cond, $code);
        $jz = count($code);
        $code[] = [VmOp::JZ, 0]; // 占位
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $this->compileBlock($body, $code);

        $jEnds = []; // 跳到结尾的 JMP 占位（then/else 分支结束处）
        $hasElse = false;
        while (true) {
            $c->skipWs();
            $t = $c->peek();
            if ($t === null) {
                break;
            }
            if (is_array($t) && $t[0] === T_ELSEIF) {
                $c->next();
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $this->patch($code, $jz); // 上一轮 JZ 落到此处（elseif 入口）
                $c->expect('(');
                // initPdepth=1：调用方已消费 '('，否则条件含内层括号对时会在第一个内层 ) 处提前停止，
                // 收集到残缺表达式（如 `$c===(1-0`），compileExpr 递归时期望 ) 但已到末尾而抛错。
                $ec = $c->collectUntilTop(')', null, 1);
                $c->expect(')');
                $this->compileExpr($ec, $code);
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
                $c->expect('{');
                $b2 = $c->untilMatchBrace();
                $this->compileBlock($b2, $code);
                $jEnds[] = $jmp;
            } elseif (is_array($t) && $t[0] === T_ELSE) {
                $c->next();
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $this->patch($code, $jz); // 上一轮 JZ 落到此处（else 入口）
                $c->expect('{');
                $b3 = $c->untilMatchBrace();
                $this->compileBlock($b3, $code);
                $jEnds[] = $jmp;
                $hasElse = true;
                break;
            } else {
                break;
            }
        }
        // 没有 else/elseif 时，JZ 直接跳到整段末尾；有 else 时循环内已把 JZ 指向 else 入口，
        // 此处若再 patch 会把它错误地覆写为末尾，导致条件为假时整个跳过 else（这正是原 bug）。
        if (!$hasElse) {
            $this->patch($code, $jz);
        }
        foreach ($jEnds as $j) {
            $this->patch($code, $j);
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileWhile(Cursor $c, array &$code): void
    {
        $c->expect(T_WHILE);
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $start = count($code);
        // 空条件（while(;;)）恒为真：不能走 compileExpr（空 token 会压 null 成假，循环体永不执行）
        if ($this->trimWs($cond) === []) {
            $code[] = [VmOp::PUSH_C, 1];
        } else {
            $this->compileExpr($cond, $code);
        }
        $jz = count($code);
        $code[] = [VmOp::JZ, 0];
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => $start];
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $code[] = [VmOp::JMP, $start - (count($code) + 1)];
        $this->patch($code, $jz);
        $this->patchBreaks($lid, $code);
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileFor(Cursor $c, array &$code): void
    {
        $c->expect(T_FOR);
        $c->expect('(');
        $init = $c->collectUntilTop(';', null);
        $c->expect(';');
        $cond = $c->collectUntilTop(';', null);
        $c->expect(';');
        // initPdepth=1：调用方已消费 '('；step 含内层括号对（如 foo($x)）时，
        // pdepth=0 会在第一个内层 ) 处提前停止，导致 step 被截断。
        $step = $c->collectUntilTop(')', null, 1);
        $c->expect(')');

        // 注意：for 的初始化段可能是赋值（如 $i=0），必须用 compileExprOrAssign，
        // 不能用 compileExpr（后者走 parseTernary 链，不识别 '='，会把赋值右值吞掉）。
        if ($init !== []) {
            $this->compileExprOrAssign($init, $code);
        }
        $start = count($code);
        // 空条件（for(;;)）恒为真：不能走 compileExpr（空 token 会压 null 成假，循环体永不执行）
        if ($this->trimWs($cond) === []) {
            $code[] = [VmOp::PUSH_C, 1];
        } else {
            $this->compileExpr($cond, $code);
        }
        $jz = count($code);
        $code[] = [VmOp::JZ, 0];
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1]; // 占位：continue 目标 = step 起点，编译后再填
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $contIdx = count($code); // L_cont：step 起点（continue 先自增再循环）
        $this->loopCtx[$lid]['cont'] = $contIdx;
        if ($this->trimWs($step) !== []) {
            $this->compileExpr($step, $code);
        }
        $code[] = [VmOp::JMP, $start - (count($code) + 1)];
        $this->patch($code, $jz);
        $this->patchContinues($lid, $code);
        $this->patchBreaks($lid, $code);
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileForeach(Cursor $c, array &$code): void
    {
        $c->expect(T_FOREACH);
        $c->expect('(');
        $src = $c->collectUntilTop(T_AS, null);
        $c->expect(T_AS);
        // 键/值（initPdepth=1：调用方已消费 '('，防止 list($a,$b) 等内层括号对导致提前停止）
        $rest = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $rest = $this->trimWs($rest);
        // 分析 $k => $v 或 $v（注意：PHP 把 => 解析为单 token T_DOUBLE_ARROW，而非 '=' '>' 两个）
        $arrowAt = -1;
        foreach ($rest as $i => $t) {
            if (is_array($t) && $t[0] === T_DOUBLE_ARROW) {
                $arrowAt = $i;
                break;
            }
            if (is_string($t) && $t === '=' && isset($rest[$i + 1]) && is_string($rest[$i + 1]) && $rest[$i + 1] === '>') {
                $arrowAt = $i;
                break;
            }
        }
        if ($arrowAt >= 0) {
            $kvar = trim($rest[0][1] ?? '', '$');
            // 值的位置：T_DOUBLE_ARROW 是单 token → 值在 arrowAt+1；'=''>' 两 token → arrowAt+2
            $arrowTok = $rest[$arrowAt];
            $vOff = (is_array($arrowTok) && $arrowTok[0] === T_DOUBLE_ARROW) ? 1 : 2;
            $vvar = trim($rest[$arrowAt + $vOff][1] ?? '', '$');
            $hasKey = true;
        } else {
            $kvar = null;
            $vvar = trim($rest[0][1] ?? '', '$');
            $hasKey = false;
        }
        $ta = '__fe' . ($this->tempSeq++);
        $tk = '__fe' . ($this->tempSeq++);
        $ti = '__fe' . ($this->tempSeq++);

        // $ta = src; $tk = array_keys($ta); $ti = 0;
        $this->compileExpr($src, $code);
        $code[] = [VmOp::POP_V, '$' . $ta];
        $code[] = [VmOp::PUSH_V, '$' . $ta];
        $code[] = [VmOp::CALL, 'array_keys', 1];
        $code[] = [VmOp::POP_V, '$' . $tk];
        $code[] = [VmOp::PUSH_C, 0];
        $code[] = [VmOp::POP_V, '$' . $ti];

        $start = count($code);
        // if ($ti >= count($tk)) goto end
        $code[] = [VmOp::PUSH_V, '$' . $ti];
        $code[] = [VmOp::PUSH_V, '$' . $tk];
        $code[] = [VmOp::COUNT];
        $code[] = [VmOp::GE];
        $jz = count($code);
        // 当 $ti >= count($tk) 为真（非 0）时跳到循环末尾：用 JNZ
        $code[] = [VmOp::JNZ, 0];
        // 先解析出当前迭代的“键” $tk[$ti]（无论是否用 $k，值都要用真实键取）
        $keyTmp = '__fe' . ($this->tempSeq++);
        $code[] = [VmOp::PUSH_V, '$' . $tk];
        $code[] = [VmOp::PUSH_V, '$' . $ti];
        $code[] = [VmOp::INDEX_GET];
        $code[] = [VmOp::POP_V, '$' . $keyTmp];
        if ($hasKey) {
            // $k 直接就是解析出的键 $tk[$ti]，不要再对 $ta 做索引
            $code[] = [VmOp::PUSH_V, '$' . $keyTmp];
            $code[] = [VmOp::POP_V, '$' . $kvar];
        }
        // $v = $ta[ 当前键 ]（注意 $ta 是关联数组，必须用真实键而非整数下标 $ti）
        $code[] = [VmOp::PUSH_V, '$' . $ta];
        $code[] = [VmOp::PUSH_V, '$' . $keyTmp];
        $code[] = [VmOp::INDEX_GET];
        $code[] = [VmOp::POP_V, '$' . $vvar];

        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1]; // 占位：continue 目标 = $ti++ 起点
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $contIdx = count($code); // continue 目标 = $ti++ 起点（先自增再循环）
        $this->loopCtx[$lid]['cont'] = $contIdx;
        // $ti++
        $code[] = [VmOp::PUSH_V, '$' . $ti];
        $code[] = [VmOp::PUSH_C, 1];
        $code[] = [VmOp::ADD];
        $code[] = [VmOp::POP_V, '$' . $ti];
        $code[] = [VmOp::JMP, $start - (count($code) + 1)];
        $this->patch($code, $jz);
        $this->patchContinues($lid, $code);
        $this->patchBreaks($lid, $code);
    }

    /**
     * switch / case / default。
     *
     * 展开为「比较链 + 落体穿透」：
     *   检查case_i -> 命中则落入 body_i，body_i 末尾 JMP 到 body_{i+1}（穿透语义）；
     *   未命中则 JZ 跳到下一个 case 的检查；default 放在最后；break 跳到 switch 末尾。
     * @param array<int,array<int,mixed>> $code
     */
    private function compileSwitch(Cursor $c, array &$code): void
    {
        $c->expect(T_SWITCH);
        $c->expect('(');
        $subj = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $c->expect('{');
        $inner = $c->untilMatchBrace();

        // 按 T_CASE / T_DEFAULT 切分（只认顶层，忽略嵌套 switch 的 case）
        $segs = [];
        $cur = null;   // ['kind'=>'case'|'default','val'=>tokens,'body'=>tokens]
        $depth = 0;
        $pendingVal = false;  // case 的值还在收集
        $pendingTerm = false; // 标签终止符（':' 或 ';'）还未消费
        foreach ($inner as $tok) {
            $isCase = is_array($tok) && $tok[0] === T_CASE && $depth === 0;
            $isDefault = is_array($tok) && $tok[0] === T_DEFAULT && $depth === 0;
            if (is_string($tok)) {
                if ($tok === '{') { $depth++; }
                elseif ($tok === '}') { $depth = max(0, $depth - 1); }
            } elseif (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }
            if (($isCase || $isDefault) && $depth === 0) {
                if ($cur !== null) { $segs[] = $cur; }
                $cur = ['kind' => $isCase ? 'case' : 'default', 'val' => [], 'body' => []];
                $pendingVal = $isCase; // case 值位于 ':' / ';' 之前
                $pendingTerm = true;   // default 的 ':' 也必须被吞掉，否则会漏进 body
                continue;
            }
            if ($cur === null) {
                continue; // switch 体内的杂项（注释等）
            }
            if ($pendingTerm && is_string($tok) && ($tok === ':' || $tok === ';')) {
                $pendingTerm = false;
                $pendingVal = false;
                continue;
            }
            if ($pendingVal) { $cur['val'][] = $tok; } else { $cur['body'][] = $tok; }
        }
        if ($cur !== null) { $segs[] = $cur; }

        // 主题表达式求值一次，存进临时变量
        $sw = '__sw' . ($this->tempSeq++);
        $this->compileExpr($subj, $code);
        $code[] = [VmOp::POP_V, '$' . $sw];

        $sid = ++$this->swSeq;
        $this->ctxStack[] = ['k' => 'switch', 'id' => $sid];

        // 每个 case：检查段 + 体段；体段末尾 JMP 到下一个体段（穿透）
        $checks = [];      // 每条：JZ 占位下标（default 为 -1）
        $checkStarts = []; // 每条：检查段起点下标（default 与其体段同点）
        $bodyStarts = [];
        $jmpSlots = [];    // 每条：穿透 JMP 的占位下标（最后一条跳到 end）
        foreach ($segs as $i => $seg) {
            if ($seg['kind'] === 'case') {
                $checkStarts[$i] = count($code);
                $code[] = [VmOp::PUSH_V, '$' . $sw];
                $this->compileExpr($this->trimWs($seg['val']), $code);
                $code[] = [VmOp::EQ];
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
            } else {
                // default：无条件落到下一段（其检查段为空）
                $jz = -1;
                $checkStarts[$i] = count($code);
            }
            $bodyStarts[$i] = count($code);
            $this->compileBlock($seg['body'], $code);
            $jmp = count($code);
            $code[] = [VmOp::JMP, 0];
            $checks[$i] = $jz;
            $jmpSlots[$i] = $jmp;
        }
        $end = count($code);
        array_pop($this->ctxStack);

        // 回填：未命中 -> 下一个 case 的检查段起点（JZ 之后才是其体，不能用 JZ 下标）；
        // 下一个是 default 则直接落进其体；之后 -> end
        foreach ($checks as $i => $jz) {
            if ($jz < 0) { continue; }
            $target = $end;
            if (isset($checks[$i + 1])) {
                $target = $checks[$i + 1] >= 0 ? ($checkStarts[$i + 1] ?? $checks[$i + 1]) : $bodyStarts[$i + 1];
            }
            $code[$jz][1] = $target - ($jz + 1);
        }
        // 穿透：body_i 末尾 -> body_{i+1} 起点（最后一条 -> end）
        foreach ($jmpSlots as $i => $jmp) {
            $target = isset($bodyStarts[$i + 1]) ? $bodyStarts[$i + 1] : $end;
            $code[$jmp][1] = $target - ($jmp + 1);
        }
        // switch 内的 break -> end
        $this->patchSwitchBreaks($sid, $code, $end);
    }

    private int $swSeq = 0;

    /**
     * do { body } while (cond);
     * 语义：先执行体，再判定条件，真则回到体首。
     * @param array<int,array<int,mixed>> $code
     */
    private function compileDoWhile(Cursor $c, array &$code): void
    {
        $c->expect(T_DO);
        $start = count($code);
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1];
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $contIdx = count($code);
        $this->loopCtx[$lid]['cont'] = $contIdx;
        $c->skipWs();
        if (is_array($c->peek()) && $c->type() === T_WHILE) {
            $c->next();
        }
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($cond, $code);
        $code[] = [VmOp::JNZ, $start - (count($code) + 1)];
        $this->patchContinues($lid, $code);
        $this->patchBreaks($lid, $code);
    }

    /**
     * unset($a) / unset($a['k']) / unset($a, $b)
     * @param array<int,array<int,mixed>> $code
     */
    private function compileUnset(Cursor $c, array &$code): void
    {
        $c->skipWs();
        $c->expect('(');
        $all = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        foreach ($this->splitTopLevel($all, ',') as $arg) {
            $arg = $this->trimWs($arg);
            if ($arg === []) { continue; }
            [$name, $keys] = $this->parseIssetTarget($arg);
            $n = count($keys);
            foreach ($keys as $k) { $this->compileExpr($k, $code); }
            $code[] = [VmOp::UNSET_, $name, $n];
        }
    }

    /**
     * global $a, $b; —— 把当前帧的变量槽位绑定为「共享全局帧」的引用。
     *
     * VM 的帧变量表默认是每帧独立的普通数组（值语义），与 PHP 的全局符号表不通。
     * 这里为每个 global 变量发射 GLOBAL_REF：运行期把 $__vars[$name] 变成
     * $__gframe[$name] 的引用，此后 PUSH_V/POP_V/++/-- 全部自然穿透到全局帧，
     * 与 PHP 的 global 语义一致（读写共享、函数返回后仍有效）。
     * 顶层帧与全局帧的同步由运行期在「顶层帧发起调用」的边界上完成（见 RuntimeGenerator）。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function compileGlobal(Cursor $c, array &$code): void
    {
        $c->skipWs();
        while (!$c->eof() && !$c->is(';')) {
            if ($c->is(T_VARIABLE)) {
                $name = $c->expectText(T_VARIABLE);
                $this->globalNames[ltrim($name, '$')] = true;
                $code[] = [VmOp::GLOBAL_REF, $name];
                $c->skipWs();
                if ($c->is(',')) {
                    $c->next();
                    $c->skipWs();
                    continue;
                }
                break;
            }
            // global &$x 之类非常规形式：跳过该 token 继续尝试
            $c->next();
            $c->skipWs();
        }
        $c->skipWs();
        if ($c->is(';')) {
            $c->next();
        }
    }

    /**
     * const NAME = expr; / const A = 1, B = 2; —— 运行期 define()。
     * 常量在 PHP 里是运行期实体，编译期 defined() 不可见，故发射 DEFINE 操作码；
     * 引用处按预扫描收集的常量名发射 CONST_FETCH。
     *
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileConst(array $stmt, array &$code): void
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        $c->expect(T_CONST);
        // 整条取到语句尾，再按顶层逗号拆分：const A = 1; / const A = 1, B = 2;
        $rest = $c->untilTerminator(';');
        foreach ($this->splitTopLevel($rest, ',') as $part) {
            $part = $this->trimWs($part);
            if ($part === []) {
                continue;
            }
            $pc = new Cursor($part);
            $pc->skipWs();
            if (!$pc->is(T_STRING)) {
                continue; // 非常规形式：跳过该常量定义
            }
            $pname = $pc->expectText(T_STRING);
            $this->constNames[$pname] = true;
            $pc->skipWs();
            if (!$pc->is('=')) {
                continue;
            }
            $pc->next();
            $this->compileExpr($pc->collectUntilEnd(), $code);
            $code[] = [VmOp::DEFINE, $pname];
        }
    }

    /**
     * exit / die：exit; exit(1); die('msg');
     * @param array<int,array<int,mixed>> $code
     */
    private function compileExit(Cursor $c, array &$code): void
    {
        $c->skipWs();
        if ($c->is('(')) {
            $c->next();
            $args = $this->splitTopLevel($c->collectUntilTop(')', null, 1), ',');
            $c->expect(')');
            $args = array_values(array_filter($args, fn ($a) => $this->trimWs($a) !== []));
            if ($args === []) {
                $code[] = [VmOp::EXIT_VOID];
                return;
            }
            $this->compileExpr($this->trimWs($args[0]), $code);
            $code[] = [VmOp::EXIT_];
            return;
        }
        $code[] = [VmOp::EXIT_VOID];
    }

    /**
     * 解析 isset / empty / unset 的「变量 + 键路径」目标，如 $a['k']['j']。
     * @param array<int,mixed> $tokens
     * @return array{0:string,1:array<int,array<int,mixed>>}
     */
    private function parseIssetTarget(array $tokens): array
    {
        $t = $tokens[0] ?? null;
        if (!is_array($t) || $t[0] !== T_VARIABLE) {
            throw new VmUnsupported('VM 仅支持变量形式的 isset/empty/unset 目标（已回退）');
        }
        $name = $t[1];
        $keys = [];
        $i = 1;
        while ($i < count($tokens)) {
            if (($tokens[$i] ?? null) === '[') {
                $depth = 1; $i++; $buf = [];
                while ($i < count($tokens) && $depth > 0) {
                    $tk = $tokens[$i];
                    if ($tk === '[') { $depth++; }
                    elseif ($tk === ']') { $depth--; if ($depth === 0) { $i++; break; } }
                    $buf[] = $tk; $i++;
                }
                $keys[] = $this->trimWs($buf);
                continue;
            }
            if (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_OBJECT_OPERATOR) {
                // $a->b 形式：把属性名当作常量键
                $i++;
                $p = $tokens[$i] ?? null;
                $pname = is_array($p) ? $p[1] : (string) $p;
                $keys[] = [is_array($p) ? [T_CONSTANT_ENCAPSED_STRING, "'" . $pname . "'"] : "'" . $pname . "'"];
                $i++;
                continue;
            }
            break;
        }
        return [$name, $keys];
    }

    /**
     * 回填 switch 内 break 的跳转目标（switch 末尾）。
     * @param array<int,array<int,mixed>> $code
     */
    private function patchSwitchBreaks(int $sid, array &$code, int $end): void
    {
        $keep = [];
        foreach ($this->pendingSwBreaks as $b) {
            if ($b['sw'] === $sid) {
                $code[$b['idx']][1] = $end - ($b['idx'] + 1);
            } else {
                $keep[] = $b;
            }
        }
        $this->pendingSwBreaks = $keep;
    }

    /** @var array<int,array{cont:int}> loopId => 该循环的 continue 目标指令索引 */
    private array $loopCtx = [];
    private int $loopSeq = 0;

    /**
     * 回填一条跳转指令的偏移：target - (占位索引 + 1)。
     * （运行时取指后 pc 已指向下一条，故偏移需相对 `占位索引 + 1` 计算。）
     * @param array<int,array<int,mixed>> $code
     */
    private function patch(array &$code, int $idx): void
    {
        $code[$idx][1] = count($code) - ($idx + 1);
    }

    /**
     * break / continue，支持可选的层级参数（break N / continue N）。
     *
     * 历史 bug：原先虽消费了层级数字却从不使用，`break 2` 被当成 `break 1`——
     * 在 `while(true){switch(...){...break 2;...}}` 状态机里只跳出 switch，
     * 外层 while 永远无法退出，运行期死循环。
     *
     * 语义：
     *  - break N：从最内层【可中断结构】（switch 或 loop）向外数第 N 层，跳到其末尾；
     *  - continue N：只数【循环】（switch 不算 continue 目标），跳到该循环的 cont 点。
     * 层数超出栈深度时忽略（PHP 会 fatal，这里选择宽容）。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function applyLoopJump(string $kind, array &$code, Cursor $c): void
    {
        $m = count($code);
        // 解析可选的层级数字
        $level = 1;
        if ($c->is(T_LNUMBER)) {
            $lt = $c->next();
            $lv = is_array($lt) ? (int) $lt[1] : 1;
            if ($lv > 0) {
                $level = $lv;
            }
        }
        if ($kind === 'break') {
            // break N：跳过最内层 N-1 个结构，落到第 N 层结构的末尾
            $idx = count($this->ctxStack) - $level;
            $top = $idx >= 0 ? $this->ctxStack[$idx] : null;
            if ($top === null) {
                // 层数超出 / 不在任何可中断结构内：忽略（顶层 break 已在 VirtualMachine 层跳过）
            } elseif ($top['k'] === 'switch') {
                $code[] = [VmOp::JMP, 0];
                $this->pendingSwBreaks[] = ['idx' => $m, 'sw' => $top['id']];
            } else {
                $code[] = [VmOp::JMP, 0];
                $this->pendingBreaks[] = ['idx' => $m, 'lid' => $top['id']];
            }
        } else {
            // continue 只作用于循环（switch 不构成 continue 目标）
            $found = 0;
            $lid = null;
            for ($i = count($this->ctxStack) - 1; $i >= 0; $i--) {
                if ($this->ctxStack[$i]['k'] === 'loop') {
                    $found++;
                    if ($found === $level) {
                        $lid = $this->ctxStack[$i]['id'];
                        break;
                    }
                }
            }
            if ($lid === null) {
                // 不在循环内 / 层数超出：忽略
            } else {
                $code[] = [VmOp::JMP, 0];
                // continue 先记录占位，待循环体编译完、cont 目标确定后再回填（支持 for/foreach 的自增步）
                $this->pendingContinues[] = ['idx' => $m, 'lid' => $lid];
            }
        }
        if ($c->is(';')) {
            $c->next();
        }
    }

    /** @var array<int,array{idx:int,lid:int}> */
    private array $pendingBreaks = [];

    /** @var array<int,array{idx:int,lid:int}> */
    private array $pendingContinues = [];

    /**
     * 循环结束时，把该循环内所有 break 占位回填到结尾。
     * @param array<int,array<int,mixed>> $code
     */
    private function patchBreaks(int $lid, array &$code): void
    {
        $end = count($code);
        $keep = [];
        foreach ($this->pendingBreaks as $b) {
            if ($b['lid'] === $lid) {
                $code[$b['idx']][1] = $end - ($b['idx'] + 1);
            } else {
                $keep[] = $b;
            }
        }
        $this->pendingBreaks = $keep;
    }

    /**
     * 循环结束时，把该循环内所有 continue 占位回填到自增/自增步位置。
     * @param array<int,array<int,mixed>> $code
     */
    private function patchContinues(int $lid, array &$code): void
    {
        $keep = [];
        foreach ($this->pendingContinues as $b) {
            if ($b['lid'] === $lid) {
                $cont = $this->loopCtx[$lid]['cont'];
                $code[$b['idx']][1] = $cont - ($b['idx'] + 1);
            } else {
                $keep[] = $b;
            }
        }
        $this->pendingContinues = $keep;
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
        $this->parseTernary($c, $code);
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
     * @param array<int,array<int,mixed>> $code
     */
    private function parseTernary(Cursor $c, array &$code): void
    {
        $this->parseCoalesce($c, $code);
        $c->skipWs();
        if ($c->is('?')) {
            $c->next();
            $jz = count($code);
            $code[] = [VmOp::JZ, 0];
            if ($c->is(':')) {
                // ?: 形式
                $c->next();
                $this->parseTernary($c, $code);
                $code[$jz][1] = count($code) - ($jz + 1);
            } else {
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
        $this->parsePow($c, $code);
        while (!$c->eof()) {
            if ($c->is('*')) { $c->next(); $this->parsePow($c, $code); $code[] = [VmOp::MUL]; }
            elseif ($c->is('/')) { $c->next(); $this->parsePow($c, $code); $code[] = [VmOp::DIV]; }
            elseif ($c->is('%')) { $c->next(); $this->parsePow($c, $code); $code[] = [VmOp::MOD]; }
            else { break; }
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
        if ($c->is('!')) { $c->next(); $this->parseUnary($c, $code); $code[] = [VmOp::BOOL_NOT]; return; }
        if ($c->is('+')) { $c->next(); $this->parseUnary($c, $code); return; }
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
        if ($c->is(T_INC)) { // ++$v
            $c->next();
            $v = $c->expectText(T_VARIABLE);
            $code[] = [VmOp::PRE_INC, $v];
            return;
        }
        if ($c->is(T_DEC)) {
            $c->next();
            $v = $c->expectText(T_VARIABLE);
            $code[] = [VmOp::PRE_DEC, $v];
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
        $this->parsePrimary($c, $code);
        while (!$c->eof()) {
            if ($c->is(T_OBJECT_OPERATOR)) {
                $c->next();
                $name = $c->expectText(T_STRING);
                if ($c->is('(')) {
                    $argc = $this->compileArgs($c, $code);
                    $code[] = [VmOp::PUSH_C, $name]; // 方法名（MECALL 期望：对象, 参数..., 方法名）
                    $code[] = [VmOp::MECALL, $argc];
                } else {
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
     * 编译实参列表，参数按【左→右】顺序压栈。返回参数个数。
     * 调用方光标应位于 '(' 之后（本方法自行消费 '(' 与 ')'）。
     * $refPos：by-ref 参数位置（0 基）——对应实参须为简单变量，改发 PUSH_REF
     * 引用句柄（供 CALL_NATIVE 的 refMask 绑定帧变量真实引用）；非简单变量
     * 时抛 VmUnsupported，由语句级回退以原生语义执行。
     *
     * @param array<int,array<int,mixed>> $code
     * @param array<int,int> $refPos
     */
    private function compileArgs(Cursor $c, array &$code, array $refPos = []): int
    {
        $c->expect('(');
        if ($c->is(')')) {
            $c->next();
            return 0;
        }
        $all = $c->collectUntilTop(')', null, 1);
        $args = $this->splitTopLevel($all, ',');
        $argc = 0;
        foreach ($args as $a) {
            if ($a === []) {
                continue;
            }
            if (in_array($argc, $refPos, true)) {
                $this->parseRefArg($a, $code);
            } else {
                $this->parseExpr($a, $code);
            }
            $argc++;
        }
        $c->expect(')');
        return $argc;
    }

    /**
     * 编译 by-ref 实参：仅支持简单变量（$name）——发射 PUSH_REF 引用句柄。
     * 其余左值形式（$obj->p / $a['k'] / $a['x']['y'] 等）VM 引用绑定不覆盖，
     * 抛 VmUnsupported 让整条语句走 EVAL_STMT 原生语义回退。
     *
     * @param array<int,mixed> $tokens
     * @param array<int,array<int,mixed>> $code
     */
    private function parseRefArg(array $tokens, array &$code): void
    {
        $sig = [];
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $sig[] = $t;
        }
        if (count($sig) === 1 && is_array($sig[0]) && $sig[0][0] === T_VARIABLE) {
            $code[] = [VmOp::PUSH_REF, $sig[0][1]];
            return;
        }
        throw new VmUnsupported('VM 暂不支持该原生函数引用参数的非简单变量形式（已回退）');
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
                    $code[] = [VmOp::PUSH_C, (float) $t[1]];
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
                        $argc = $this->compileArgs($c, $code, self::NATIVE_REF_POS[strtolower($name)] ?? []);
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
            throw new VmUnsupported('VM 不支持 parent::（已回退）');
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
            $argc = $this->compileArgs($c, $code);
            $code[] = [VmOp::SCALL, $cls, $mname, $argc];
        } else {
            // 静态属性写作 $p（token 为 T_VARIABLE，带 $ 前缀）
            $code[] = [VmOp::SPROP, $cls, ltrim($mname, '$')];
        }
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
     * match 表达式：match ($subj) { c1,c2 => r1; default => r2 }。
     * 编译为：把 $subj 存临时变量，对每个非 default 分支依次做 ($subj == ci) 比较，
     * 命中则跳到该分支结果块；全部未命中且存在 default 则落到 default 结果块；
     * 否则压入 null。结果块末尾 JMP 到 match 结束点。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseMatch(Cursor $c, array &$code): void
    {
        $c->next(); // 消费 T_MATCH
        $c->expect('(');
        $subjToks = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($subjToks, $code);
        $subj = '$__m' . ($this->tempSeq++);
        $code[] = [VmOp::POP_V, $subj];
        $c->expect('{');

        $endJmps = [];          // 各结果块的 JMP → match 结束
        $defaultRes = null;      // default 分支的结果 token

        while (!$c->is('}') && !$c->eof()) {
            // 收集本分支的条件（可能有多个，逗号分隔），直到顶层 '=>'
            $conds = [];
            $conds[] = $c->collectUntilTop(T_DOUBLE_ARROW, ',');
            while ($c->is(',')) {
                $c->next();
                $conds[] = $c->collectUntilTop(T_DOUBLE_ARROW, ',');
            }
            $c->expect(T_DOUBLE_ARROW);
            $resToks = $c->collectMatchArmResult();

            $isDefault = false;
            if (count($conds) === 1) {
                $ct = $this->trimWs($conds[0]);
                if (count($ct) === 1 && is_array($ct[0]) && $ct[0][0] === T_DEFAULT) {
                    $isDefault = true;
                }
            }

            if ($isDefault) {
                $defaultRes = $resToks;
            } else {
                $jnzIdx = [];
                foreach ($conds as $ct) {
                    $code[] = [VmOp::PUSH_V, $subj];
                    $this->compileExpr($ct, $code);
                    $code[] = [VmOp::EQ];          // $subj == cond
                    $code[] = [VmOp::JNZ, 0];     // 命中 → 跳到结果块
                    $jnzIdx[] = count($code) - 1;
                }
                // 未命中：跳过结果块
                $code[] = [VmOp::JMP, 0];
                $skipIdx = count($code) - 1;
                // 结果块
                $resStart = count($code);
                foreach ($jnzIdx as $ji) {
                    $code[$ji][1] = $resStart - ($ji + 1);
                }
                $this->compileExpr($resToks, $code);
                $code[] = [VmOp::JMP, 0];
                $endJmps[] = count($code) - 1;
                // skip 目标（下一分支起点）
                $code[$skipIdx][1] = count($code) - ($skipIdx + 1);
            }

            if ($c->is(',')) { $c->next(); }
        }
        $c->expect('}');

        if ($defaultRes !== null) {
            $this->compileExpr($defaultRes, $code);
        } else {
            $code[] = [VmOp::PUSH_NULL];
        }
        $endPos = count($code);
        foreach ($endJmps as $jmpIdx) {
            $code[$jmpIdx][1] = $endPos - ($jmpIdx + 1);
        }
    }

    /**
     * 双引号插值字符串："a{$x}b{$arr['k']}c$d"。
     * 展开为若干段字面量 / 表达式的 CONCAT 链。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseInterpolatedString(Cursor $c, array &$code): void
    {
        $c->expect('"');
        $parts = [];   // ['lit', string] | ['expr', tokens]
        $buf = '';
        while (!$c->eof() && !$c->is('"')) {
            $tk = $c->peek();
            if (is_array($tk) && $tk[0] === T_ENCAPSED_AND_WHITESPACE) {
                $buf .= $this->decodeEncapsed($tk[1]);
                $c->next();
                continue;
            }
            if (is_array($tk) && $tk[0] === T_VARIABLE) {
                if ($buf !== '') { $parts[] = ['lit', $buf]; $buf = ''; }
                $c->next();
                $expr = [$tk];
                // 简单下标：$a[0] / $a[key]（键内再嵌 [] 时跟踪深度，防提前截断）
                if ($c->is('[')) {
                    $c->next();
                    $keyToks = [];
                    $bdepth = 1;
                    while (!$c->eof()) {
                        if ($c->is(']')) {
                            $bdepth--;
                            if ($bdepth === 0) {
                                break;
                            }
                            $keyToks[] = $c->next();
                            continue;
                        }
                        if ($c->is('[')) {
                            $bdepth++;
                        }
                        $keyToks[] = $c->next();
                    }
                    $c->expect(']');
                    $expr[] = '[';
                    foreach ($this->normalizeStringKey($keyToks) as $kt) { $expr[] = $kt; }
                    $expr[] = ']';
                } elseif ($c->is(T_OBJECT_OPERATOR)) {
                    $c->next();
                    $pn = $c->peek();
                    $pname = is_array($pn) ? $pn[1] : (string) $pn;
                    $c->next();
                    $expr[] = T_OBJECT_OPERATOR;
                    $expr[] = [T_STRING, $pname];
                }
                $parts[] = ['expr', $expr];
                continue;
            }
            if (is_array($tk) && $tk[0] === T_CURLY_OPEN) {
                if ($buf !== '') { $parts[] = ['lit', $buf]; $buf = ''; }
                $c->next(); // 消费开头的 '{'（T_CURLY_OPEN）
                $inner = [];
                $depth = 0; // 仅追踪内嵌表达式里的 () [] {} 深度，遇到最外层 '}' 即收尾
                while (!$c->eof()) {
                    $x = $c->peek();
                    if (is_string($x)) {
                        if ($x === '}') {
                            if ($depth === 0) { $c->next(); break; }
                            $depth--;
                        } elseif ($x === '{' || $x === '(' || $x === '[') {
                            $depth++;
                        } elseif ($x === ')' || $x === ']') {
                            // 闭合同类括号必须配对递减，否则 "{$arr['k']}tail" 的
                            // 内层 ] 不降深度 → 外层 } 被当作内层括号吞掉，尾部字面量全丢
                            if ($depth > 0) { $depth--; }
                        }
                    }
                    $inner[] = $c->next();
                }
                $parts[] = ['expr', $inner];
                continue;
            }
            if (is_array($tk) && $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                if ($buf !== '') { $parts[] = ['lit', $buf]; $buf = ''; }
                $c->next();
                $inner = [];
                while (!$c->is('}') && !$c->eof()) { $inner[] = $c->next(); }
                $c->expect('}');
                $parts[] = ['expr', $inner];
                continue;
            }
            // 其它（如注释）跳过
            $c->next();
        }
        if ($c->is('"')) { $c->next(); }
        if ($buf !== '') { $parts[] = ['lit', $buf]; }

        if ($parts === []) {
            $code[] = [VmOp::PUSH_C, ''];
            return;
        }
        $emitted = 0;
        foreach ($parts as $p) {
            if ($p[0] === 'lit') {
                $code[] = [VmOp::PUSH_C, $p[1]];
            } else {
                $this->compileExpr($p[1], $code);
            }
            $emitted++;
            if ($emitted > 1) { $code[] = [VmOp::CONCAT]; }
        }
    }

    /**
     * 插值字符串中的下标 token（T_STRING / T_NUM_STRING）转成可编译表达式 token。
     * @param array<int,mixed> $toks
     * @return array<int,mixed>
     */
    private function normalizeStringKey(array $toks): array
    {
        $out = [];
        foreach ($toks as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_STRING, T_NUM_STRING], true)) {
                $out[] = [T_CONSTANT_ENCAPSED_STRING, "'" . $tk[1] . "'"];
                continue;
            }
            $out[] = $tk;
        }
        return $out;
    }

    /** 解析双引号 / 插值片段中的转义序列 */
    private function decodeEncapsed(string $s): string
    {
        $map = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch !== '\\' || $i + 1 >= $len) { $out .= $ch; continue; }
            $nx = $s[$i + 1];
            if (isset($map[$nx])) { $out .= $map[$nx]; $i++; continue; }
            if ($nx === 'x' && preg_match('/^[0-9a-fA-F]{1,2}/', substr($s, $i + 2), $m)) {
                $out .= chr(hexdec($m[0])); $i += 1 + strlen($m[0]); continue;
            }
            if (preg_match('/^[0-7]{1,3}/', substr($s, $i + 1), $m)) {
                $out .= chr(octdec($m[0])); $i += strlen($m[0]); continue;
            }
            if ($nx === 'u' && preg_match('/^u\{([0-9a-fA-F]+)\}/', substr($s, $i + 1), $m)) {
                $cp = hexdec($m[1]);
                $out .= $this->utf8Chr($cp);
                $i += strlen($m[0]);
                continue;
            }
            $out .= $nx; $i++;
        }
        return $out;
    }

    private function utf8Chr(int $cp): string
    {
        if ($cp < 0x80) { return chr($cp); }
        if ($cp < 0x800) { return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F)); }
        if ($cp < 0x10000) { return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)); }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
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

    /**
     * [ ... ] 短数组语法。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseArrayShort(Cursor $c, array &$code): void
    {
        $c->expect('[');
        $elems = $this->collectArrayElems($c, ']');
        $c->expect(']');
        $this->emitArray($elems, $code);
    }

    /**
     * array( ... ) 旧语法。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseArrayLegacy(Cursor $c, array &$code): void
    {
        $c->expect('(');
        if ($c->is(')')) {
            $c->next();
            $code[] = [VmOp::ARRAY_MAKE, 0];
            return;
        }
        $elems = $this->collectArrayElems($c, ')');
        $c->expect(')');
        $this->emitArray($elems, $code);
    }

    /**
     * 收集数组的所有元素（key => val 或纯 val）。
     *
     * 历史 bug：原先用 collectUntilTop(',', $close) 收集元素，其"先减后查"语义导致
     * 元素含内层括号对时（如 array(foo($a), 2)）在第一个内层 ) 处提前停止，收集到
     * 残缺表达式；且箭头探测存在运算符优先级错误（A && B && C || D）。
     * 现改为专用扫描器：在数组自身顶层（pd=bd=d=0）遇 ',' / $close / '=>' 才停止，
     * 并正确忽略闭包（fn/function）形参表后的 '=>'。
     *
     * @return array<int,array{key:?array,val:array}>
     */
    private function collectArrayElems(Cursor $c, string $close): array
    {
        $elems = [];
        $c->skipWs();
        if ($c->is($close)) {
            return $elems;
        }
        while (!$c->eof()) {
            [$toks, $stop] = $this->collectArrayElem($c, $close);
            $key = null;
            $val = $toks;
            if ($stop === '=>') {
                $key = $toks;
                // 消费 '=>'（T_DOUBLE_ARROW 单 token；个别 tokenize 路径给 '=' '>' 两 token 时兼容）
                if ($c->is(T_DOUBLE_ARROW)) {
                    $c->next();
                } elseif ($c->is('=') && $c->peek2() === '>') {
                    $c->next();
                    $c->next();
                }
                [$vt, $stop] = $this->collectArrayElem($c, $close);
                $val = $vt;
            }
            if ($this->trimWs($val) !== [] || ($key !== null && $this->trimWs($key) !== [])) {
                $elems[] = ['key' => $key, 'val' => $val];
            }
            if ($stop === ',') {
                $c->next(); // 消费逗号
                $c->skipWs();
                if ($c->is($close)) {
                    break; // 尾逗号
                }
                continue;
            }
            break; // $close 或 EOF
        }
        return $elems;
    }

    /**
     * 收集数组的一个元素 token 序列（不含分隔符/结束符/'=>'，均不消费）。
     * 仅在数组自身顶层（pd=bd=d=0）停止；闭包形参表后的 '=>' 属于闭包自身。
     *
     * @return array{0:array<int,mixed>,1:string} [tokens, stop]，stop ∈ {',', $close, '=>', ''}
     */
    private function collectArrayElem(Cursor $c, string $close): array
    {
        $out = [];
        $pd = 0;
        $bd = 0;
        $d = 0;
        /** @var array<int,array{0:int,1:int,2:int}> 闭包栈：fn/function 出现时的 (pd,bd,d) */
        $closures = [];
        while (!$c->eof()) {
            $t = $c->peek();
            if (is_string($t)) {
                if ($pd === 0 && $bd === 0 && $d === 0) {
                    if ($t === ',') {
                        return [$out, ','];
                    }
                    if ($t === $close) {
                        return [$out, $close];
                    }
                }
                if ($t === '(') {
                    $pd++;
                } elseif ($t === ')') {
                    $pd = max(0, $pd - 1);
                }
                if ($t === '[') {
                    $bd++;
                } elseif ($t === ']') {
                    $bd = max(0, $bd - 1);
                }
                if ($t === '{') {
                    $d++;
                } elseif ($t === '}') {
                    $d = max(0, $d - 1);
                }
                $out[] = $c->next();
                continue;
            }
            if (is_array($t)) {
                if ($t[0] === T_FN || $t[0] === T_FUNCTION) {
                    $closures[] = [$pd, $bd, $d];
                    $out[] = $c->next();
                    continue;
                }
                if ($t[0] === T_DOUBLE_ARROW && $pd === 0 && $bd === 0 && $d === 0) {
                    if ($closures !== []) {
                        $top = end($closures);
                        if ($top[0] === $pd && $top[1] === $bd && $top[2] === $d) {
                            // 闭包自身的 =>：属于元素内部，消费后继续收集
                            array_pop($closures);
                            $out[] = $c->next();
                            continue;
                        }
                    }
                    return [$out, '=>'];
                }
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $d++;
                }
                $out[] = $c->next();
                continue;
            }
            $out[] = $c->next();
        }
        return [$out, ''];
    }

    /**
     * @param array<int,array{key:?array,val:array}> $elems
     * @param array<int,array<int,mixed>> $code
     */
    private function emitArray(array $elems, array &$code): void
    {
        $hasKey = false;
        foreach ($elems as $e) {
            if ($e['key'] !== null) { $hasKey = true; break; }
        }
        if (!$hasKey) {
            foreach ($elems as $e) {
                $this->parseExpr($e['val'], $code);
            }
            $code[] = [VmOp::ARRAY_MAKE, count($elems)];
        } else {
            foreach ($elems as $e) {
                $this->parseExpr($e['val'], $code);
                if ($e['key'] !== null) {
                    $this->parseExpr($e['key'], $code);
                } else {
                    $code[] = [VmOp::PUSH_NULL];
                }
            }
            $code[] = [VmOp::ARRAY_KV, count($elems)];
        }
    }

    // ========================================================================
    // 工具
    // ========================================================================
    private function parseNumber(string $text): int
    {
        $text = trim($text);
        if (strlen($text) > 2 && (stripos($text, '0x') === 0)) {
            return (int) base_convert(substr($text, 2), 16, 10);
        }
        if (strlen($text) > 2 && (stripos($text, '0b') === 0)) {
            return (int) base_convert(substr($text, 2), 2, 10);
        }
        // 八进制：0 前缀且全为 0-7（NumberEncoder 会生成 0ddd 形式，必须正确解析，
        // 否则 036 会被当成十进制 36，造成静默错值）
        if (strlen($text) > 1 && $text[0] === '0' && ctype_digit($text)) {
            return (int) base_convert(substr($text, 1), 8, 10);
        }
        return (int) $text;
    }

    private function parseString(string $text): string
    {
        $len = strlen($text);
        $q = $text[0];
        $inner = substr($text, 1, $len - 2);
        if ($q === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        // 双引号：理论上混淆后不会出现插值，但这里仍做安全处理
        if (strpos($inner, '$') !== false) {
            throw new VmUnsupported('VM 不支持双引号字符串插值（已回退）');
        }
        // 双引号转义全集（与 StringEncoder 方案 E 的 \xHH 输出对齐）
        return $this->unescapeDoubleQuoted($inner);
    }

    /**
     * heredoc / nowdoc 编译期折值为常量字符串。
     *
     * PHP 语义（token_get_all 实测）：
     *  - T_START_HEREDOC 文本形如 "<<<EOT\n" / "<<<'NOW'\n"（含起行换行）；
     *  - 正文是若干 T_ENCAPSED_AND_WHITESPACE 片，插值变量是独立 token
     *    （T_VARIABLE / T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES），
     *    转义的 \$ 则留在正文片内——据此区分"可折叠"与"需运行期求值"；
     *  - 闭合标识前恰好一个换行不属于串值；tokenizer 已把闭合行的缩进从
     *    各体行剥离（PHP 7.3+ flexible heredoc）；
     *  - heredoc 处理双引号转义集，nowdoc 全字面（反斜杠不转义）。
     *
     * @param array{0:int,1:string} $startTok T_START_HEREDOC token
     */
    private function parseHeredoc(Cursor $c, array $startTok): string
    {
        $nowdoc = str_contains($startTok[1], "'"); // <<<'ID' 为 nowdoc
        $body = '';
        $closed = false;
        while (!$c->eof()) {
            $t = $c->next();
            if (is_array($t) && $t[0] === T_END_HEREDOC) {
                $closed = true;
                break;
            }
            if (!is_array($t)) {
                continue; // 裸字符不会出现在 heredoc 体内，防御性跳过
            }
            if ($t[0] === T_VARIABLE || $t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                throw new VmUnsupported('VM 不支持带插值的 heredoc（已回退）');
            }
            if ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $body .= $t[1];
            }
            // T_WHITESPACE / T_COMMENT 忽略（注释不会出现在 heredoc 体内）
        }
        if (!$closed) {
            throw new VmUnsupported('VM heredoc 未闭合（已回退）');
        }
        // 闭合标识前的一个换行不属于串值
        if (str_ends_with($body, "\r\n")) {
            $body = substr($body, 0, -2);
        } elseif (str_ends_with($body, "\n")) {
            $body = substr($body, 0, -1);
        }
        return $nowdoc ? $body : $this->unescapeDoubleQuoted($body);
    }

    /** 双引号串转义还原：\" \\ \n \t \r \v \f \e \xHH \u{XXXX} 八进制\ddd */
    private function unescapeDoubleQuoted(string $inner): string
    {
        $out = '';
        $len = strlen($inner);
        $i = 0;
        while ($i < $len) {
            $c = $inner[$i];
            if ($c !== '\\' || $i + 1 >= $len) {
                $out .= $c;
                $i++;
                continue;
            }
            $n = $inner[$i + 1];
            switch ($n) {
                case 'n': $out .= "\n"; $i += 2; break;
                case 't': $out .= "\t"; $i += 2; break;
                case 'r': $out .= "\r"; $i += 2; break;
                case 'v': $out .= "\v"; $i += 2; break;
                case 'f': $out .= "\f"; $i += 2; break;
                case 'e': $out .= "\e"; $i += 2; break;
                case '\\': $out .= '\\'; $i += 2; break;
                case '"': $out .= '"'; $i += 2; break;
                case '$': $out .= '$'; $i += 2; break;
                case '`': $out .= '`'; $i += 2; break;
                case 'x':
                    if (preg_match('/^[0-9a-fA-F]{1,2}/', substr($inner, $i + 2), $m)) {
                        $out .= chr(hexdec($m[0]));
                        $i += 2 + strlen($m[0]);
                    } else {
                        $out .= '\\x'; $i += 2;
                    }
                    break;
                case 'u':
                    if (preg_match('/^\{([0-9a-fA-F]+)\}/', substr($inner, $i + 2), $m)) {
                        $out .= $this->utf8Chr(hexdec($m[1]));
                        $i += 2 + strlen($m[0]) + 2;
                    } else {
                        $out .= '\\u'; $i += 2;
                    }
                    break;
                default:
                    if (preg_match('/^[0-7]{1,3}/', substr($inner, $i + 1), $m)) {
                        $out .= chr(octdec($m[0]));
                        $i += 1 + strlen($m[0]);
                    } else {
                        $out .= '\\' . $n; $i += 2;
                    }
            }
        }
        return $out;
    }


    /** 取 token 类型（数组型返回 $tok[0]，符号型返回原字符） */
    private function tokType($tok)
    {
        return is_array($tok) ? $tok[0] : $tok;
    }

    /** 去掉 token 序列首尾的空白/注释 token。 */
    private function trimWs(array $tokens): array
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        while (!empty($tokens) && is_array($tokens[0]) && in_array($tokens[0][0], $skip, true)) {
            array_shift($tokens);
        }
        while (!empty($tokens) && is_array(end($tokens)) && in_array(end($tokens)[0], $skip, true)) {
            array_pop($tokens);
        }
        return $tokens;
    }

    /** @param array<int,mixed> $tokens */
    private function firstSig(array $tokens)
    {
        foreach ($tokens as $t) {
            if (is_array($t) && !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $t[0];
            }
        }
        return null;
    }

    /**
     * 跳过 $attrIdx 处 T_ATTRIBUTE 起始的属性块，返回块后第一个 token 下标。
     * 属性参数里可能出现嵌套括号/数组（#[Attr([1,2])]），按字符串 token 深度配对；
     * 字符串/注释 token 内的括号天然不计（is_array 即跳过）。
     * 注意：T_ATTRIBUTE 的文本是 "#["——开方括号被吞在 token 文本里，
     * 故初始深度为 1，遇到配对的裸 "]" 才算块结束。
     */
    private function skipAttributeBlock(array $tokens, int $attrIdx): int
    {
        $n = count($tokens);
        $i = $attrIdx + 1;
        $depth = 1;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '[' || $t === '(' || $t === '{') {
                    $depth++;
                } elseif ($t === ']' || $t === ')' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }
            $i++;
        }
        return $i; // 异常形态（属性块未闭合）：跳到末尾
    }

    /**
     * 去掉语句最前面的若干属性块（#[...]，可多个叠加）。
     * 属性可出现在类/函数/成员声明之前；VM 的声明分发与成员解析都必须
     * 先剥掉它，否则 `#[A] final class X {}` 会被当作普通表达式语句
     * 走 EVAL_STMT 兜底（见 effectiveDeclSig 的说明）。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function stripLeadingAttributes(array $tokens): array
    {
        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipAttributeBlock($tokens, $i);
                continue;
            }
            break;
        }
        return $i === 0 ? $tokens : array_slice($tokens, $i);
    }

    /**
     * 取语句的"有效声明关键字"：跳过前导属性块与类修饰符
     * （final / abstract / readonly）。
     *
     * 为什么不能直接用 firstSig：`final class Foo {}` 的裸首 token 是
     * T_FINAL、`#[A] class Foo {}` 是 T_ATTRIBUTE——按裸首 token 分发会把
     * 整条类/函数声明错送 compileStatement 的 EVAL_STMT 兜底。类被原生
     * 定义后，其方法体内引用 VM 函数表符号（用户函数已被编译进字节码、
     * 原定义被移除）的调用会直接致命：Call to undefined function。
     */
    private function effectiveDeclSig(array $tokens): ?int
    {
        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipAttributeBlock($tokens, $i);
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                $i++;
                continue;
            }
            if (is_array($t)) {
                return $t[0];
            }
            return null; // 语句以裸字符起始（表达式语句）
        }
        return null;
    }

    /** @param array<int,mixed> $tokens */
    private function firstString(array $tokens)
    {
        foreach ($tokens as $t) {
            if (is_array($t) && !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return is_array($t) && $t[0] === T_STRING ? $t[1] : null;
            }
        }
        return null;
    }

    /** @param array<int,mixed> $stmt */
    private function nextIsBlockEnd(array $stmt): bool
    {
        return false;
    }
}

/**
 * 语法游标：在一段 token 数组上移动，提供前瞻/收集/匹配括号等能力。
 */
final class Cursor
{
    /** @var array<int,mixed> */
    private array $t;
    private int $p = 0;

    /** @param array<int,mixed> $tokens */
    public function __construct(array $tokens)
    {
        $this->t = $tokens;
    }

    public function eof(): bool
    {
        return $this->p >= count($this->t);
    }

    /** 跳过空白/注释 */
    public function skipWs(): void
    {
        while (!$this->eof()) {
            $tok = $this->t[$this->p];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $this->p++;
            } else {
                break;
            }
        }
    }

    /** 当前 token（含空白） */
    public function peek()
    {
        return $this->t[$this->p] ?? null;
    }

    /** 下一个有效（跳过空白）token */
    public function peekSig()
    {
        $i = $this->p;
        while ($i < count($this->t)) {
            $tok = $this->t[$i];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            return $tok;
        }
        return null;
    }

    /** 当前 token 类型（字符串或 token 常量） */
    public function type()
    {
        $tok = $this->peek();
        return $tok === null ? null : (is_array($tok) ? $tok[0] : $tok);
    }

    public function is($what): bool
    {
        $this->skipWs();
        $tok = $this->peek();
        if ($tok === null) {
            return false;
        }
        if (is_int($what)) {
            return is_array($tok) && $tok[0] === $what;
        }
        return is_string($tok) && $tok === $what;
    }

    /** 第二个 token（含空白） */
    public function peek2()
    {
        return $this->t[$this->p + 1] ?? null;
    }

    /** 当前光标位置（供前瞻失败时回退） */
    public function pos(): int
    {
        return $this->p;
    }

    /** 恢复光标位置 */
    public function seek(int $p): void
    {
        $this->p = max(0, min($p, count($this->t)));
    }

    public function next()
    {
        return $this->t[$this->p++] ?? null;
    }

    /**
     * 期望某个 token（int 类型或字符），否则抛异常。
     * @return mixed
     */
    public function expect($what)
    {
        $this->skipWs();
        $tok = $this->peek();
        if ($tok === null) {
            throw new VmUnsupported('VM 编译期望 ' . (is_int($what) ? token_name($what) : $what) . ' 但已到末尾');
        }
        if (is_int($what)) {
            if (!(is_array($tok) && $tok[0] === $what)) {
                throw new VmUnsupported('VM 编译期望 ' . token_name($what) . '，得到 ' . (is_array($tok) ? token_name($tok[0]) : $tok));
            }
        } else {
            if (!is_string($tok) || $tok !== $what) {
                throw new VmUnsupported('VM 编译期望 ' . $what);
            }
        }
        return $this->next();
    }

    /**
     * 同 expect()，但返回 token 文本（数组型返回 $tok[1]，符号型返回原字符）。
     * 便于调用方直接拿到标识符名/操作符字符。
     * @return string
     */
    public function expectText($what)
    {
        $this->skipWs();
        $tok = $this->peek();
        if ($tok === null) {
            throw new VmUnsupported('VM 编译期望 ' . (is_int($what) ? token_name($what) : $what) . ' 但已到末尾');
        }
        if (is_int($what)) {
            if (!(is_array($tok) && $tok[0] === $what)) {
                throw new VmUnsupported('VM 编译期望 ' . token_name($what) . '，得到 ' . (is_array($tok) ? token_name($tok[0]) : $tok));
            }
        } else {
            if (!is_string($tok) || $tok !== $what) {
                throw new VmUnsupported('VM 编译期望 ' . $what);
            }
        }
        $this->next();
        return is_array($tok) ? $tok[1] : $tok;
    }

    /**
     * 收集直到【顶层】遇到分隔符 $stop（不被括号内的分隔符打断）。
     * 返回含分隔符之前的所有 token（不含分隔符）。
     * @return array<int,mixed>
     */
    /**
     * 收集直到【顶层】遇到分隔符 $stop（不被括号内的分隔符打断）。
     * 返回含分隔符之前的所有 token（不含分隔符）。
     * @param string|int $stop  分隔符（字符串符号或 token 常量，如 ')' / ';' / T_AS）
     * @param string|int|null $stop2 第二个可选分隔符
     * @param int $initPdepth 调用方已消费的圆括号层数（用于正确匹配"包裹"的右括号）
     * @return array<int,mixed>
     */
    public function collectUntilTop($stop, $stop2 = null, int $initPdepth = 0): array
    {
        $out = [];
        $depth = 0;          // 花括号深度
        $pdepth = $initPdepth; // 圆括号深度（调用方已消费的 '(' 计入初始深度）
        $bdepth = 0;         // 方括号深度
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($tok === '(') { $pdepth++; }
                elseif ($tok === ')') { $pdepth = max(0, $pdepth - 1); }
                if ($tok === '[') { $bdepth++; }
                elseif ($tok === ']') { $bdepth = max(0, $bdepth - 1); }
                if ($tok === '{') { $depth++; }
                elseif ($tok === '}') { $depth = max(0, $depth - 1); }
            } elseif (is_array($tok)) {
                if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
            }
            $isStop = false;
            if ($depth === 0 && $pdepth === 0 && $bdepth === 0) {
                if (is_int($stop) && is_array($tok) && $tok[0] === $stop) { $isStop = true; }
                elseif (is_string($stop) && is_string($tok) && $tok === $stop) { $isStop = true; }
                if (!$isStop && $stop2 !== null) {
                    if (is_int($stop2) && is_array($tok) && $tok[0] === $stop2) { $isStop = true; }
                    elseif (is_string($stop2) && is_string($tok) && $tok === $stop2) { $isStop = true; }
                }
            }
            if ($isStop) {
                break;
            }
            $out[] = $this->next();
        }
        return $out;
    }

    /**
     * 收集一条 match 分支的结果表达式 token，直到【真正顶层】的 ','（下一分支）
     * 或 '}'（match 体结束）。关键在于：结果表达式内部可能再嵌套 match / 函数调用 / 数组，
     * 其中的 '{'..'}'、'('..')'、'['..']' 都需要成对平衡，其内部的 ',' 与 '}' 都不能当作停止符；
     * 只有在大括号/圆括号/方括号全部归零时的 ',' 或 '}' 才停止，且停止符本身不纳入返回。
     * @return array<int,mixed>
     */
    public function collectMatchArmResult(): array
    {
        $out = [];
        $bd = 0; // 大括号深度
        $pd = 0; // 圆括号深度
        $ad = 0; // 方括号深度
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($tok === '{') { $bd++; }
                elseif ($tok === '}') {
                    if ($bd === 0 && $pd === 0 && $ad === 0) { break; }
                    if ($bd > 0) { $bd--; }
                } elseif ($tok === '(') { $pd++; }
                elseif ($tok === ')') { if ($pd > 0) { $pd--; } }
                elseif ($tok === '[') { $ad++; }
                elseif ($tok === ']') { if ($ad > 0) { $ad--; } }
                elseif ($tok === ',') {
                    if ($bd === 0 && $pd === 0 && $ad === 0) { break; }
                }
            } elseif (is_array($tok)) {
                if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $bd++; }
            }
            $out[] = $this->next();
        }
        return $out;
    }

    /**
     * 收集直到顶层 ';' 或结构结束（用于 return/echo 的剩余部分）。
     * @return array<int,mixed>
     */
    public function untilTerminator(string $term): array
    {
        $out = [];
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok) && $tok === $term) {
                break;
            }
            $out[] = $this->next();
        }
        return $out;
    }

    public function untilEnd(): array
    {
        $out = [];
        while (!$this->eof()) {
            $out[] = $this->next();
        }
        return $out;
    }

    /** 收集直到末尾（与 collectUntilTop 命名风格一致） */
    public function collectUntilEnd(): array
    {
        return $this->untilEnd();
    }

    /**
     * 从当前位置（应位于 '{' 之后）匹配到右大括号，返回【内部】token。
     *
     * 插值修正："{$expr}" 的起始是数组 token T_CURLY_OPEN（文本 "{"），
     * 闭合却是裸字符 '}'——若只数字符 '{'/'}'，插值串的闭合括号会被
     * 误认为块结束，把函数/类/闭包体在第一个插值处拦腰截断
     * （曾致 "a{$x}b" 之后的语句整体丢失）。故 T_CURLY_OPEN /
     * T_DOLLAR_OPEN_CURLY_BRACES 也必须计入深度。
     * @return array<int,mixed>
     */
    public function untilMatchBrace(): array
    {
        // 当前应为 '{'
        if ($this->is('{')) {
            $this->next();
        }
        $out = [];
        $depth = 1;
        while (!$this->eof()) {
            $tok = $this->next();
            if ($tok === '{') {
                $depth++;
            } elseif (is_array($tok) && ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($tok === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $out[] = $tok;
        }
        return $out;
    }

    /**
     * 从当前位置收集直到顶层 ',' 或 ')' 的 token（用于参数默认值表达式）。
     * 括号 / 方括号 / 花括号内部的逗号不终止；不消耗终止符本身。
     * 插值修正同 untilMatchBrace：T_CURLY_OPEN 的闭合是裸 '}'，
     * 必须计入深度，否则默认值里的插值串会提前终止参数收集。
     * @return array<int,mixed>
     */
    public function untilParamEnd(): array
    {
        $out = [];
        $depth = 0;
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($tok === '(' || $tok === '[' || $tok === '{') {
                    $depth++;
                } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                    if ($depth === 0 && $tok === ')') {
                        break; // 所属参数列表的右括号
                    }
                    if ($depth > 0) {
                        $depth--;
                    }
                } elseif ($tok === ',' && $depth === 0) {
                    break; // 下一个参数
                }
            } elseif (is_array($tok) && ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            }
            $out[] = $this->next();
        }
        return $out;
    }

    public function cloneThis(): Cursor
    {
        $c = new Cursor($this->t);
        $c->p = $this->p;
        return $c;
    }
}

/**
 * 自定义 VM 不支持当前语法时抛出，由 VirtualMachine 捕获并回退为普通加壳。
 */
final class VmUnsupported extends RuntimeException {}
