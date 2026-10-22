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


// —— VM 编译器碎片（主体拆分，行为等价）——
require_once __DIR__ . '/Parts/VmDeclTrait.php';
require_once __DIR__ . '/Parts/VmStmtTrait.php';
require_once __DIR__ . '/Parts/VmExprTrait.php';
require_once __DIR__ . '/Parts/VmLitTrait.php';
require_once __DIR__ . '/Parts/VmTokTrait.php';
require_once __DIR__ . '/Cursor.php';

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
    /** @var array<int,array<int,mixed>> 静态属性初始化前导段（编译期累积，compile() 末尾拼入 main 头部） */
    private array $staticInit = [];
    /** @var array<string,string> 类名 => 父类名（extends 解析，供 parent:: 静态成员/方法调用） */
    private array $classParent = [];
    /** @var array<string,array<string,true>> 类名 => implements 的接口名集合（instanceof 链遍历） */
    private array $classImpl = [];
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
    /** @var array<string,true> 全程序顶层类/trait/接口名（预扫描收集，供 EVAL 兜退守卫） */
    private array $vmClassNames = [];
    /**
     * 预扫描的【函数/方法签名】：key 为函数全名（顶层为 "f"，方法为 "C::m"），
     * value 为 ['names'=>按声明顺序的参数名(含$), 'refs'=>by-ref 参数下标集合(=>true)]。
     *
     * 用途：调用点（可能出现在声明之前，PHP 顶层函数支持前向引用）需要知道
     * 形参顺序才能把【命名实参 f(b:1,a:2)】还原为位置实参；也需要知道哪些位
     * 是引用参数才能发射 PUSH_REF 引用句柄。
     * @var array<string,array{names:array<int,string>,refs:array<int,true>}>
     */
    private array $sigCache = [];
    /**
     * 原生函数名表：name => idx（按首次分配顺序编号）。
     * CALL_NATIVE 的操作数是 idx；表随程序打包，字节码/常量池里不出现函数名。
     * @var array<string,int>
     */
    private array $natTbl = [];

    // —— 编译器碎片（trait）：单文件主体按职责拆为五块 ——
    // 声明 / 语句 / 表达式 / 字面量 / token 工具；加载见文件顶部 require_once。
    use VmDeclTrait;
    use VmStmtTrait;
    use VmExprTrait;
    use VmLitTrait;
    use VmTokTrait;

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
     * 当前正在编译的函数/方法作用域内、已登记的静态局部变量：
     * 用户变量名(含$) => 共享帧槽位名（mangled）。作用域结束后由 compileFunctionCode 重置。
     * @var array<string,string>
     */
    private array $curStatic = [];
    /** 当前函数/方法作用域标识（函数名或 class::method），用于静态局部变量槽位命名 */
    private string $curScopeId = '';

    // —— 异常处理：每段（main / 函数 / 类方法）独立的异常表（与 try 编译同步填充）——
    // 条目形态：[tryStart, tryEnd, handlerPc, kind, type, varName]
    //   kind: 'catch'（异常类型匹配则路由）/ 'finally'（保护区内未捕获异常 → 置 pending 后入 finally）
    //   type: catch 的异常类型（全限定名）；catch(Throwable) 等兜底用 '' 表任意 Throwable；
    //         finally 条目 type 恒为 ''。
    //   varName: catch 绑定的变量名（含$，无变量为 ''）。handlerPc 对 finally 占位、编译后回填。
    private array $mainET = [];
    /** @var array<string,array<int,array>> funcName => 异常表 */
    private array $funcET = [];
    /** @var array<string,array<string,array<int,array>>> cls => method => 异常表 */
    private array $classET = [];
    /** 当前段异常表引用（按编译上下文指向 mainET / funcET[n] / classET[c][m]） */
    private array $curSegET = [];
    /**
     * try/finally 嵌套栈：每项 ['finallyPc'=>int|null, 'retJmps'=>array, 'hasFinally'=>bool]
     * finallyPc 为 finally 块起始占位索引（编译后回填）；retJmps 收集保护区内被延后返回的
     * JMP 占位（最终都指向 finallyPc）；hasFinally 标记本 try 是否带 finally（决定返回拦截）。
     * @var array<int,array{finallyPc:?int,retJmps:array,hasFinally:bool}>
     */
    private array $tryStack = [];
    /** 编译器侧标志：当前是否正在编译 finally 块体（块内 return 为真实返回，不延后） */
    private bool $inFinally = false;

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
            // 顺带登记签名（形参名顺序 + 引用/可变标志），供命名实参重排与
            // by-ref 引用句柄发射使用（调用点可先于声明出现，故必须预扫描）。
            $this->prescanSignature($s);
        }
        // 预扫描：收集全部顶层类/trait/接口名。语句引用了 VM 类却又编译失败时，
        // EVAL_STMT 兜退会在运行期致命（VM 类只是字节码，不是真实 PHP 类，
        // eval 里访问即 "Class not found" / "Cannot access self"）——兜退守卫
        // referencesVmClass 据此把这类语句的失败升级为整文件回退（输出正确、
        // 损失该文件 VM 保护），而非带着致命隐患继续 VM 化。
        foreach ($stmts as $stmt) {
            $s = $this->stripLeadingAttributes($stmt);
            $first = $this->effectiveDeclSig($s);
            if (!in_array($first, [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                continue;
            }
            foreach ($s as $tk) {
                if (is_array($tk) && $tk[0] === T_STRING) {
                    $this->vmClassNames[$tk[1]] = true;
                    break;
                }
            }
            // 预扫描类/trait/接口内的方法签名（'C::m'）：$this->m() / self::m() /
            // C::m() 调用点可先于该类声明出现（PHP 类无前向引用限制仅限顶层
            // 函数，但同文件内方法互调极常见），故必须与顶层函数一并预登记。
            $this->prescanClassSignatures($s);
        }
        foreach ($stmts as $stmt) {
            $this->compileTopStatement($stmt);
        }
        // 静态属性初始化前导段拼到 main 最前：PHP 在类声明时即设好静态属性默认值，
        // VM 类全部前置编译，最早安全点是 main 开头（类声明前使用该类本就非法）。
        // 前置只平移绝对下标，语句内 JZ/JMP 均为相对偏移，不受影响。
        return [
            'consts'      => $this->consts,
            'funcs'       => $this->funcs,
            'funcParams'  => $this->funcParams,
            'classes'     => $this->classes,
            'classParams' => $this->classParams,
            // 类层级信息：instanceof 运行期链遍历（VM 类是 stdClass + META 属性，
            // 原生 instanceof 看不到继承/实现关系，必须由编译期记录）。
            'classParents' => $this->classParent,
            'classImpl'    => $this->classImpl,
            'main'        => array_merge($this->staticInit, $this->main),
            'globals'     => array_keys($this->globalNames),
            // 异常表（按段）：try/catch/finally 真编译的异常分发依据（运行期查覆盖当前
            // pc 的条目决定路由到 catch 或 finally）。main/函数/类方法各自独立。
            'mainET'      => $this->mainET,
            'funcET'      => $this->funcET,
            'classET'     => $this->classET,
            'embedET'     => [],   // 嵌入段异常表由 IncludeResolver/buildArtifact 装配
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
        // 顶层普通语句：异常表指向 main 段，控制栈复位
        $this->curSegET = &$this->mainET;
        $this->tryStack = [];
        $this->inFinally = false;
        $this->compileStatement($stmt, $this->main);
    }
}


/**
 * 自定义 VM 不支持当前语法时抛出，由 VirtualMachine 捕获并回退为普通加壳。
 */
final class VmUnsupported extends RuntimeException {}
