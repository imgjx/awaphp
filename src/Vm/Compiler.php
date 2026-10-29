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
require_once __DIR__ . '/Parts/VmIncTrait.php';
require_once __DIR__ . '/Parts/VmControlTrait.php';
require_once __DIR__ . '/Parts/VmClosureTrait.php';
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
    /** @var array<string,array<string,true>> 类名 => 自身声明的静态属性名集合（继承链查找：
     *  B::$p 在 B 未声明时定位到真正声明该属性的父类，共享同一存储——PHP 继承语义） */
    private array $classStatics = [];
    /** @var array<string,array<string,true>> 类名 => 自身声明的静态方法名集合（FCC：
     *  C::m(...) 对非静态方法创建期即抛 Error——"Non-static method ... cannot be
     *  called statically"，与 PHP 8.1+ 一致；静态方法才可建一等可调用闭包） */
    private array $classStatic = [];
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

    // —— 编译器碎片（trait）：单文件主体按职责拆为七块 ——
    // 声明 / 语句 / 表达式 / 字面量 / token 工具 / 变量自增复合发射 / 控制流；
    // 加载见文件顶部 require_once。
    use VmDeclTrait;
    use VmStmtTrait;
    use VmExprTrait;
    use VmLitTrait;
    use VmTokTrait;
    use VmIncTrait;
    use VmControlTrait;
    use VmClosureTrait;

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
     * goto/label：当前作用域（main / 函数 / 方法各自独立）的标签表与待回填跳转。
     * 标签表 name => ['code'=>int, 'chain'=>array<int>]：
     *   code  = 标签绑定的指令下标（标签语句编译起点）；
     *   chain = 登记时所处 loop/switch 上下文 id 序列（「goto 进 loop/switch
     *          即原生致命」的判定依据）。
     * pendingGotos 每项 ['code'=>int, 'name'=>string, 'chain'=>array<int>]：
     *   code = JMP 占位下标，作用域结束时由 resolveGotos 统一校验回填。
     * 进入函数/方法作用域时整体换新（外层状态由调用方保存/恢复），main 作用域
     * 在 compile() 入口重置。
     * @var array<string,array{code:int,chain:array<int>}>
     */
    private array $labelTab = [];
    /** @var array<int,array{code:int,name:string,chain:array<int>}> */
    private array $pendingGotos = [];
    /**
     * 当前正在编译的函数/方法作用域内、已登记的静态局部变量：
     * 用户变量名(含$) => 共享帧槽位名（mangled）。作用域结束后由 compileFunctionCode 重置。
     * @var array<string,string>
     */
    private array $curStatic = [];
    /** 当前函数/方法作用域标识（函数名或 class::method），用于静态局部变量槽位命名 */
    private string $curScopeId = '';
    /**
     * 引用返回标志表：funcName => true（`function &g()`）。
     * 编译期两份用途：函数体内 return $var 前插 MAKE_REF 桥接槽；
     * `$r = &g()` 调用点发射 CALL_RR。
     * @var array<string,bool>
     */
    private array $funcRef = [];
    /** 当前正在编译的函数是否引用返回（return 桥接插桩依据） */
    private bool $curReturnsRef = false;

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
     * 当前段选择器：'main' / "f\0函数名" / "c\0类名\0方法名"。
     * 闭包编译是"段中段"，编译完必须把 curSegET 的引用重新指回外层段——
     * 只保存数组值无法还原引用身份（赋值会写穿到闭包自己的槽位），故记录选择器。
     */
    private string $curSegSel = 'main';

    /** 当前命名空间（顶层 namespace 声明；空=全局） */
    private string $curNs = '';
    /** @var array<string,string> 顶层 use 别名（小写短名 => 完全限定名） */
    private array $useMap = [];
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
    /**
     * PHP 8 合并令牌归一化（7.4 兼容形态）。
     *
     * 8.0 起 `\A\B` / `A\B` / `namespace\A` 分别被词法器合并成 T_NAME_FULLY_QUALIFIED /
     * T_NAME_QUALIFIED / T_NAME_RELATIVE，`&` 也分成 T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG 与
     * T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG（后者用于区分"引用参数 &$x"与"交集类型 A&B"，
     * 必须原样保留，故这里不动 `&` 系列令牌）。本编译器的名字解析是按 7.4 的
     * "T_STRING + T_NS_SEPARATOR" 形态写的，直接遇到合并令牌会抛
     * "VM 不支持的词法" 而整文件回退。这里统一拆回旧形态，一处归一、全局受益。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    /**
     * 切换"当前段"异常表引用。段选择器：
     *   'main'）/ "f\0函数名" / "c\0类名\0方法名"
     *
     * 用选择器而不是保存数组值，是为了让闭包（段中段）编译完能真正把引用
     * 重新指回外层段——保存/回写数组值只会写穿到闭包自己的槽位，外层后续的
     * try/catch 会错记到闭包表里。
     */
    private function bindSeg(string $sel): void
    {
        $this->curSegSel = $sel;
        if ($sel === 'main') {
            $this->curSegET = &$this->mainET;
            return;
        }
        $parts = explode("\x00", $sel);
        if ($parts[0] === 'f') {
            $name = $parts[1] ?? '';
            $this->funcET[$name] ??= [];
            $this->curSegET = &$this->funcET[$name];
            return;
        }
        $owner = $parts[1] ?? '';
        $mn = $parts[2] ?? '';
        $this->classET[$owner][$mn] ??= [];
        $this->curSegET = &$this->classET[$owner][$mn];
    }

    /**
     * 预扫描命名空间上下文：当前 namespace + 顶层 use 别名表。
     *
     * 为什么需要：VM 产物整体运行在全局命名空间里（`namespace { ... }`），
     * 源码里的短类名（`new OrderService()` / `Cache::get()`）一旦原样进字节码，
     * 运行期就变成"在全局命名空间里找 OrderService"——而它实际是
     * `app\service\OrderService`（由 `use app\service\OrderService;` 导入）。
     * 编译期把名字补全成 FQN，运行期才找得到真实类。
     *
     * 只登记顶层（深度 0）的 namespace / use；闭包参数里的 `use (...)` 不在
     * 语句起始位置，天然不会被误判。
     *
     * @param array<int,array<int,mixed>> $stmts
     */
    private function prescanNamespaceContext(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $sig = [];
            foreach ($stmt as $t) {
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sig[] = $t;
            }
            if ($sig === [] || !is_array($sig[0])) {
                continue;
            }
            if ($sig[0][0] === T_NAMESPACE) {
                $ns = '';
                for ($i = 1, $n = count($sig); $i < $n; $i++) {
                    $t = $sig[$i];
                    if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_NS_SEPARATOR)) {
                        $ns .= $t[1];
                        continue;
                    }
                    break;
                }
                $this->curNs = trim($ns, '\\');
                continue;
            }
            if ($sig[0][0] === T_USE) {
                $this->scanUseStatement($sig);
            }
        }
    }

    /**
     * 解析一条顶层 use 语句，填充别名表（键统一小写：PHP 类名大小写不敏感）。
     * 支持 `use A\B;`、`use A\B as C;`、`use A\{B, C as D};`；
     * `use function ...` / `use const ...` 不参与类名解析，直接跳过。
     *
     * @param array<int,mixed> $sig
     */
    private function scanUseStatement(array $sig): void
    {
        $n = count($sig);
        $i = 1;
        if ($i < $n && is_array($sig[$i]) && in_array($sig[$i][0], [T_FUNCTION, T_CONST], true)) {
            return;
        }
        $prefix = '';
        $name = '';
        $alias = '';
        // 状态：0=收集名字 1=AS 之后收集别名
        $state = 0;
        for (; $i < $n; $i++) {
            $t = $sig[$i];
            if (is_array($t) && $t[0] === T_AS) {
                $state = 1;
                $alias = '';
                continue;
            }
            if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_NS_SEPARATOR)) {
                if ($state === 1) {
                    $alias .= $t[1];
                } else {
                    $name .= $t[1];
                }
                continue;
            }
            if (is_string($t) && $t === '{') {
                // 组语法 use A\{B, C as D};
                $prefix = rtrim($name, '\\');
                $name = '';
                continue;
            }
            if (is_string($t) && ($t === ',' || $t === '}' || $t === ';')) {
                if ($name !== '' || $alias !== '') {
                    $fqn = trim(($prefix !== '' ? $prefix . '\\' : '') . trim($name, '\\'), '\\');
                    if ($fqn !== '') {
                        $p = strrpos($fqn, '\\');
                        $short = $alias !== '' ? $alias : ($p !== false ? substr($fqn, $p + 1) : $fqn);
                        $this->useMap[strtolower($short)] = $fqn;
                    }
                }
                $name = '';
                $alias = '';
                $state = 0;
                if ($t === '}') {
                    $prefix = '';
                }
                continue;
            }
        }
        // 语句末尾（无 ';' 的极端情况）补登记
        if ($name !== '') {
            $fqn = trim(($prefix !== '' ? $prefix . '\\' : '') . trim($name, '\\'), '\\');
            if ($fqn !== '') {
                $short = $alias !== '' ? $alias : (($p = strrpos($fqn, '\\')) !== false ? substr($fqn, $p + 1) : $fqn);
                $this->useMap[strtolower($short)] = $fqn;
            }
        }
    }

    /**
     * 类名引用补全（编译期）：把源码里的类名解析成运行期可用的名字。
     *
     * 规则与 PHP 一致（按优先级）：
     *   1. 前导 `\` → 已是完全限定名，去掉前导反斜杠；
     *   2. 本文件声明的类/trait/interface → 保留短名（VM 类表按短名寻址）；
     *   3. 顶层 use 别名命中 → 展开为导入的 FQN；
     *   4. 名字自带命名空间（A\B）→ 相对当前命名空间展开；
     *   5. 否则 → 当前命名空间 + 短名（全局命名空间下保持原样）。
     *
     * 运行期对"补全后仍不存在"的名字会再退回短名（PHP 的"命名空间内找不到
     * 就去全局找"回退语义），故此处只需给出首选名字。
     */
    private function resolveClassRef(string $name): string
    {
        if ($name === '') {
            return $name;
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        // 内置类型/关键字不是类名，绝不能做命名空间补全——
        // `int $n`、`fn(int $x)`、`A|int` 联合类型等都会流经类引用改写，
        // 补全成 app\demo\int 会直接 "Cannot use ... as class name as it is reserved"。
        static $builtins = [
            'int' => 1, 'float' => 1, 'string' => 1, 'bool' => 1, 'array' => 1,
            'object' => 1, 'mixed' => 1, 'void' => 1, 'never' => 1, 'null' => 1,
            'false' => 1, 'true' => 1, 'iterable' => 1, 'callable' => 1,
            'self' => 1, 'static' => 1, 'parent' => 1,
        ];
        if (isset($builtins[strtolower($name)])) {
            return $name;
        }
        if (isset($this->classes[$name]) || isset($this->traits[$name]) || isset($this->interfaces[$name])) {
            return $name;
        }
        $low = strtolower($name);
        if (isset($this->useMap[$low])) {
            return $this->useMap[$low];
        }
        if ($this->curNs === '') {
            return $name;
        }
        return $this->curNs . '\\' . $name;
    }

    private function normalizeTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                $out[] = $t;
                continue;
            }
            $id = $t[0];
            // 注释一律丢弃：VM 只关心语义，带注释反而会让"光标看下一个 token"的判断
            // （peek2 / ::判定 等）被注释卡住——例如壳间注入的注释插在 `json` 与 `(`
            // 之间，就会被误判成"不支持的标识符 json"。
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED
                || (defined('T_NAME_RELATIVE') && $id === T_NAME_RELATIVE)) {
                $name = (string) $t[1];
                $lead = ($id === T_NAME_FULLY_QUALIFIED);
                $rel = (defined('T_NAME_RELATIVE') && $id === T_NAME_RELATIVE);
                $parts = explode('\\', ltrim($name, '\\'));
                if ($rel) {
                    // namespace\Foo → namespace \ Foo
                    array_unshift($parts, '');
                }
                if ($lead) {
                    $out[] = [T_NS_SEPARATOR, '\\'];
                }
                $n = count($parts);
                for ($i = 0; $i < $n; $i++) {
                    $seg = $parts[$i];
                    if ($seg === '' && $rel && $i === 0) {
                        $out[] = [T_NAMESPACE, 'namespace'];
                        $out[] = [T_NS_SEPARATOR, '\\'];
                        continue;
                    }
                    if ($i > 0) {
                        $out[] = [T_NS_SEPARATOR, '\\'];
                    }
                    if ($seg !== '') {
                        $out[] = [T_STRING, $seg];
                    }
                }
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

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
        // PHP 8 合并令牌归一化：把 T_NAME_* / T_AMPERSAND_* 展开成 7.4 时代的
        // "T_STRING + T_NS_SEPARATOR" 序列与裸 '&'，使整套旧分词逻辑（专为 7.4 编写）
        // 无需逐处改造即可消费 8.0+ 源码，显著减少"VM 不支持的词法"回退。
        $body = $this->normalizeTokens($body);
        $this->tokens = $body;
        $this->pos = 0;
        // main 作用域的 goto/label 状态（函数/方法作用域在各自入口换新）
        $this->labelTab = [];
        $this->pendingGotos = [];

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
            // 引用返回预登记（function &g()）：& 在 PHP 8.1+ 是
            // T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG（其后是函数名而非
            // 变量），8.0- 是裸 '&'。调用点 $r = &g() 发射 CALL_RR 的依据，
            // 调用点可先于声明出现，故与 funcNames 一并预扫描。
            $sig2 = [];
            foreach ($s as $tk) {
                if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sig2[] = $tk;
            }
            if (count($sig2) >= 2 && $sig2[0][0] === T_FUNCTION
                && ($this->isAmpNotFollowedByVar($sig2[1]) || $sig2[1] === '&')) {
                foreach ($sig2 as $tk) {
                    if (is_array($tk) && $tk[0] === T_STRING) {
                        $this->funcRef[$tk[1]] = true;
                        break;
                    }
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
        // 预扫描：重复标签检测（原生 parse 期即致命：Label 'x' already defined）。
        // 深度感知地找「T_STRING ':'」标签声明（排除三元冒号 / case 值冒号 /
        // 返回类型冒号 / 可空标记），跳过函数/类/闭包体（独立标签作用域）。
        // 记录期校验（compileStatementInner 内）覆盖常规路径；此处兜底
        // 「标签所在外层语句被 EVAL_STMT 化、记录期校验不可达」的角落。
        $this->prescanDuplicateLabels($this->tokens);
        // 命名空间上下文预扫描：类名补全（new / 静态调用 / instanceof / catch 类型）
        // 依赖它，必须在任何类/函数体编译之前完成。
        $this->prescanNamespaceContext($stmts);
        foreach ($stmts as $stmt) {
            $this->compileTopStatement($stmt);
        }
        // main 作用域收尾：校验并回填全部 goto（未定义标签 / 进 loop / 跳出
        // try-finally 均抛 fileLevel，由壳层整文件回退、原生产出真实错误）
        $this->resolveGotos($this->main, $this->mainET);
        // 静态属性初始化前导段拼到 main 最前：PHP 在类声明时即设好静态属性默认值，
        // VM 类全部前置编译，最早安全点是 main 开头（类声明前使用该类本就非法）。
        // 前置只平移绝对下标，语句内 JZ/JMP 均为相对偏移，不受影响。
        return [
            'consts'      => $this->consts,
            'funcs'       => $this->funcs,
            'funcParams'  => $this->funcParams,
            // 引用返回函数表（function &g()）：return 桥接与 $r=&g() 编译依据
            'funcRef'     => $this->funcRef,
            'classes'     => $this->classes,
            'classParams' => $this->classParams,
            // 静态方法表（FCC 创建期校验：C::m(...) 非静态即 Error）
            'classStatic' => $this->classStatic,
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
        $this->bindSeg('main');
        $this->tryStack = [];
        $this->inFinally = false;
        $this->compileStatement($stmt, $this->main);
    }
}


/**
 * 自定义 VM 不支持当前语法时抛出，由 VirtualMachine 捕获并回退为普通加壳。
 *
 * $fileFallback：语句级 EVAL_STMT 回退亦不安全的构造（如引用返回方法调用
 * $r = &$o->m()——eval 里 VM 类不是真实 PHP 类，调方法必致命）。
 * compileStatement 的 catch 见此标记直接重抛，由壳层整文件回退。
 */
final class VmUnsupported extends RuntimeException
{
    public bool $fileFallback = false;

    public static function fileLevel(string $message): self
    {
        $e = new self($message);
        $e->fileFallback = true;
        return $e;
    }
}
