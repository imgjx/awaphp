# 自定义虚拟机（VM）特性支持文档

> 适用模块：`src/Vm/`（编译器 `Compiler.php` + 动态运行时生成器 `RuntimeGenerator.php` + 操作码 `Op.php`）
> 接入点：`src/ControlFlow/VirtualMachine::wrap()`（自定义 ISA 路径，失败优雅回退）

---

## 1. 这是什么

本 VM **不是**把 PHP 语句 XOR 后逐条 `eval` 的旧壳，而是一套**真正的自定义指令集（ISA）**：

- 栈式机（stack machine），操作数从操作数栈弹出 / 结果压回；
- 用户源码在**构建期**被编译成一组自定义 opcode 序号 + 常量池；
- 运行期由**每次构建动态生成**的专属解释器**逐条取指、解码、执行**；
- 破解者拿到的只有被打乱编号的 opcode 与一段分片加密的字节码，**看不到任何 PHP 语句，也看不到固定特征**。

数据流：`PHP 源码 → VmCompiler::compile() → ['consts','funcs','classes','main'] → VmRuntimeGenerator 动态生成专属 runtime + 二进制字节码容器（varint + 操作数类型标记 + gzip + 分片独立加密）→ 自包含产物（内联 runtime）解释执行`。

与旧版"一份固定 `runtime.php` + 明文操作码"的本质区别：新生成器在**每次构建**产出**每文件一份专属运行时**——操作码编号随机化、随机变量名、switch case 顺序打乱并注入诱饵、字节码分片独立加密。两个文件生成的解释器互不相同，通用特征库（按固定 `1=ADD`）直接失效。

---

## 2. 支持的语法特性

| 类别 | 支持的写法 | 说明 / 备注 |
|------|-----------|-------------|
| **变量** | `$x = 1; $x; $arr[$k]; $this->prop`；实例方法内 `$this` | 标量、数组、对象属性均可读写；实例方法序章发 `LOAD_THIS` 把对象注入 `$this` 帧槽（见 §11.1），静态方法内 `$this` 触发整文件回退 |
| **复合赋值** | `$x += 1; $x -= ; *= /= %= .=` | 简单变量与**单维数组/属性下标**（`$a['k'] .= x`、`$o->p .= x`）均已真编译（LVRead 双容器 + `INDEX_SET`/`PROPSET` 写回）；**多维嵌套**（`$a['x']['y'] .= x`）仍走 EVAL_STMT 兜底 |
| **数组** | `[1,2,3]`、`array(1,2)`、`['k'=>v]`、`$a[]=v`、`$a[$k]=v`、`$a[$k]` | 索引数组 / 关联数组 / push / 下标读写 |
| **数组函数** | `count()`、`array_push()` 等 | 作为内建函数调用（见下） |
| **函数** | `function f($a,$b){ return ...; }`、递归、`$f()` 变量函数 | 每次调用获得**独立变量帧**，无跨函数污染 |
| **一等可调用方法（FCC，PHP 8.1+）** | `$o->m(...)`、`$this->m(...)`、`C::m(...)`、`C::$m(...)`、`$cls::m(...)`、`self::/static::/parent::m(...)`、动态方法名 `$o->$n(...)` / `C::$n(...)` | 创建期即校验并抛错（措辞与原生逐字一致）：null 接收者 / 方法不存在（无 `__call`/`__callStatic` 时）/ 类名 FCC 指向非静态方法；实例 FCC 绑定 `$this`，静态方法亦可经实例 FCC；`__call`/`__callStatic` 允许不存在方法的 FCC 创建（调用期才魔术分发）；`static::` 创建期解析到 called class，闭包调用期压栈保证闭包内 `static::` 一致。已知缺口：VM 不记录方法可见性，private/protected 越权 FCC 不抛错（原生会抛） |
| **魔术方法** | `__construct`、`__call`、`__callStatic` | 普通调用与 FCC 双双走魔术分发；`get_called_class()` 在桥接闭包内解析到创建期类 |
| **实参绑定** | 引用参数 `f(&$x)`、可变参数 `f(...$args)`、命名实参 `f(b:1,a:2)`、解包实参 `f(...$arr)`（含字符串键→命名） | 编译期只压栈 + 发射标记（`PUSH_REF`/`ARG_NAMED`/`ARG_SPREAD`），重排/解引用全部在**运行期**按被调方签名完成——即使 receiver 类型编译期不可知（`$obj->m(b:1)`）也正确；引用参数按 by-ref 位做 copy-in/copy-out，命名参数跳过时可正确落入默认值 |
| **静态调用** | `C::m()`、`C::$m()`、`self::m()`、`parent::m()`、**`static::m()`（延迟静态绑定）** | 方法沿继承链查找；命名/引用/解包实参同样支持。`static::` 运行期按 **called class**（`get_called_class()` 同源）解析方法/常量/属性/`::class`；`self::`/`parent::` 方法调用是**转发调用**（保留 called class，PHP 实测语义）；实例方法内的静态形态调用转发 `$this`。动态类名 `instanceof $c` 亦支持 |
| **内建 PHP 函数** | `strlen()`、`substr()`、`implode()`、`range()`… 任意 `function_exists` | 经 `call_user_func_array` 桥接；含引用返回/特殊签名的函数按 PHP 默认语义 |
| **类** | `class C { public $p=...; function m(){...} }`、`extends` 继承、`implements` 接口、`trait`（`use T;` 及适配块 `use A,B{ A::m insteadof B; B::m as am; }`） | 属性默认值、构造器 `__construct`、`$this->p`、`$this->m()`、方法链；trait 适配块在**编译期**完成冲突消解与别名注册（按根 trait 判重，同一 trait 经不同路径引入不冲突），`insteadof` 改向 / `as` 别名均支持（含静态方法、多排除、空操作 `m as m`） |
| **对象** | `new C()`、`new $cls()`、`$obj->m()`、`$obj->p` | 对象以 `\stdClass` + 元信息存储，行为等价 |
| **循环** | `while` / `for` / `foreach`（索引 + 键值 `as $k=>$v`） | `break` / `continue` 均支持，跳转到正确位置 |
| **条件** | `if / elseif / else`、三元 `?:`、短三元 `?:`（elvis）、`match` 表达式 | `match($subj){ 1,2=>"a", default=>"b" }`；多分支共享同一条件；支持嵌套 `match`、默认分支、表达式结果 |
| **算术** | `+ - * / % **`、一元负 `-`、字符串连接 `.` | — |
| **位运算** | `~ & \| ^ << >>` | `&` 与 `&&`、`<<` 与 `<` 已正确区分 |
| **比较** | `== != === !== < > <= >=`、**太空船 `<=>`** | `<=>` 返回 -1/0/1，已支持 |
| **逻辑** | `&& || !`、**空合并 `??`** | `??` 等价于 `isset(lhs) ? lhs : rhs` |
| **自增/自减** | `++$x` / `--$x`（前缀）、`x++` / `x--`（后缀） | 变量自增经**复合指令序列**发射（`PUSH_V/PUSH_C/ADD/SUB/DUP/POP_V`，专用码已精简移除，见 §11.2）；属性/下标自增仍走 `INC_OP` 族专用码。仅**简单变量**；复杂表达式上的后缀 `++` 会回退。已知限制：字符串字母进位 / 布尔自增无法由通用算术表达（见 §11.2） |
| **常量** | `true / false / null`、整型/浮点/字符串字面量（含 `\n \t \r \xNN \u{NNNN}` 转义）、编译期常量（`PHP_VERSION` 等） | 双引号插值**支持**（`"a{$x}b"`、`"{$arr['k']}"`、`"${x}"` 三种形态均已修复） |
| **输出** | `echo $a, $b;` | 多参数逗号分隔 |
| **异常** | `throw` / `try-catch` / `try-finally` / `try-catch-finally`（含多 catch、`catch (A\|B)`、`catch (E)` 无变量） | 编译为**异常表**（`[tryStart,end,handlerPc,kind,type,varName]`），运行期按最内层区间优先分发：先 catch 后 finally；含异常表的段整段跳过 flatten / stateMachine（避免绝对 pc 失真） |

### 2.1 一个能跑的示例（`tests/fixtures/complex_app.php`）

包含：递归（斐波那契）、素数判定（while）、阶乘（for）、类与属性、方法调用链、foreach 键值遍历、位运算、字符串统计、三元 + 循环求和。在 VM 模式下**输出与原生 `require` 完全一致**（见第 4 节基准）。

---

## 3. 不支持 / 会优雅回退的特性

当遇到下列语法时，编译器抛出 `VmUnsupported`；上层的 `VirtualMachine::wrap()` 捕获后**保留原始代码、不破坏构建**（即该文件整体退回普通加壳，而非用 VM 包裹）。

| 特性 | 回退原因 |
|------|---------|
| 多维嵌套复合赋值 `$a['x']['y'] .= x` | 单维已真编译；嵌套左值链仍走 EVAL_STMT 兜底（语句级，语义保真） |
| `$this->prop[]` 属性数组追加 | 不支持属性 push |
| 复杂表达式上的后缀 `++/--`（如 `$a[0]++`） | 仅简单变量支持 |
| 动态属性读取 `$obj->$name()` 形式之外的动态成员 | 运行期类型不可知 |
| `self::$p[]` 静态属性数组读取 | 未实现该形态 |
| `$GLOBALS` 整表赋值 / `$GLOBALS[]` 追加 | 超全局表特殊语义 |
| 顶层 `return` / `exit` / `break` / `continue`（裸顶层） | 会破坏解释循环，外层 VM 壳跳过整段 |
| `goto` | 未实现对应指令 |
| 方法级引用返回 `$r = &$o->m()` | 函数级 `$r = &g()` 已支持（CALL_RR）；方法级回退该语句 |

> **编写可被 VM 包裹的代码时的建议**：函数/方法体保持"直来直去"——用 `for/foreach/while + if/三元`，避免匿名函数、属性数组追加；引用参数、可变参数、命名/解包实参、静态调用均已支持，可放心使用。

---

## 4. 性能损耗（实测基准）

测试驱动：`tests/vm_run.php`（编译 → 打包自包含产物 → 运行 → 与原生对比 + 多轮取中位数）。

| 场景 | 原生 | VM 模式 | 时间损耗 | 峰值内存 | 内存损耗 |
|------|------|---------|---------|---------|---------|
| 复杂业务（`complex_app.php`） | 0.063 ms | 0.95 ms | **≈15×** | 2.0 MB | 1.0× |
| 重型循环（1,000,000 次迭代，`perf_heavy.php`） | 22 ms | 1885 ms | **≈85×** | 2.0 MB → 26 MB | **≈13×** |

**解读：**
- 时间开销主要来自"逐条 opcode 的大 `switch` + 操作数栈进出"——每条 PHP 语句被放大成数条字节码指令，再经解释器执行，典型 **10–85×**；
- 轻量逻辑（少量语句）开销约 15×，紧循环被放大到 80×+；
- 内存：解释器本身（运行时闭包、栈数组）带来额外常驻开销，重负载下可达十余倍；
- **产物体积**：自包含产物 = 运行时(~13KB) + 编码字节码。对 3KB 复杂源码膨胀约 **4.7×**；对 230B 微型源码膨胀约 **57×**（运行时占比主导）。

> VM 模式的定位是**强混淆 / 抗静态分析**，不是性能优化。它适合对安全性敏感、对运行速度不敏感的分发包。若某段代码性能关键且 VM 不支持，编译器会自动回退普通加壳。

> **v1.1.1 起解释器热路径重写**，同样的代码比 1.1.0 快 **2.5~2.6×**（状态机/派发密集负载）到
> **1.8×**（混合负载）。两处改动：
> ① **操作数栈编码类型化**——旧版每压一个值就 `serialize()` + `str_repeat` 现算 XOR、出栈再
> `unserialize()`，占 VM 总耗时约 75%；新版按类型分流（int 单次整型 XOR / string 单次串 XOR /
> float·bool·null 直存 / array 才 serialize / 不可序列化值落明文槽），解码内联进 `MCPOP`/`MCPEEK`。
> ② **状态机派发压缩**——新增 `DISPATCH` / `SET_ST` 两条专用指令，把派发序章 5 条指令压成 1 条、
> 状态写入 2 条压成 1 条。完整对比见 `docs/PERFORMANCE.md` 第 4 节。

---

## 5. 使用与集成

```php
use VmRuntimeGenerator;
use Salt;

// 单文件 VM 化（构建期，动态生成专属 runtime + 分片加密字节码）
$gen = new VmRuntimeGenerator(new Salt('可选固定种子，保证可复现'));
$artifact = $gen->buildArtifact($sourceWithoutPhpTags, [
    'flatten'      => true,   // 或 'stateMachine' => true（二者互斥，取其一）
    'chunks'      => 3,      // 字节码分片数，每片独立密钥
])['php'];                    // 自包含 PHP 源码，可直接运行

// 仅运行并取标准输出（测试 / 校验）：
$out = $gen->execute($sourceWithoutPhpTags, ['stateMachine' => true, 'chunks' => 3]);

// 经 VirtualMachine::wrap() 接入混淆流水线：
//   - 优先编译为自定义 ISA；
//   - 抛 VmUnsupported 时优雅回退，保留原代码，不破坏产物。
```

测试与回归：
- `tests/vm_gen_probe.php` — **81 个用例 × 4 模式**（flat_off/flat_on/sm_off/sm_on 组合）全 PASS，覆盖循环/条件/数组/运算符/递归/类/break-continue/三元/`match`/`<=>`/`??`/双引号插值/内置函数等；
- `tests/vm_run.php <php文件> [轮数]` — 任意 PHP 的"能否运行 + 性能损耗"自动化度量；
- demo 构建：`php build_vm_demo.php` 生成 `vm_demo/`（含 `example_source.php` 与扁平化 / 状态机两套自包含产物，输出与原生一致）。

---

## 6. 模块链接（ModuleLinker，P6）

独立功能模块以 JSON 清单管理在一个目录下。VM 构建时若主程序源码在【函数调用位】调用了命中清单的函数，则把该模块对应的 PHP 代码**自动引入并一起编译进同一个 Program** —— 源码里的"调用模块函数"变成"调用同一 Program 内的联函数"，原始路径上的间接调用指令消失，调用关系无从分析：

1. 产物中**没有模块文件路径、没有 include 指令**，只有"某函数体内部的字节码 + 一个普通 `CALL`"；
2. `__FILE__` / `realpath` / `debug_backtrace` 里都不会出现模块文件名；
3. 模块代码经过符号表重命名（NameStyle 别名）+ 字符串/数字编码后再进 VM，与主程序融合度极高。

### 6.1 清单格式（`modules/xxx.json`）

```json
{
  "name": "strlen",
  "match": ["strlen", "mb_strlen"],        // 精确名匹配（函数调用位）
  "match_regex": ["/^strlen/", "/^mb_/"],  // 正则匹配（可选）
  "entry": "strlen.php",                   // 模块入口文件（相对本 json 所在目录）
  "deps": ["strlen_helper.php"],           // 依赖文件，一并打包（可选）
  "replace": true,                          // 命中后把源码调用替换为内联模块（默认 true）
  "priority": 10,                           // 多清单命中时的优先级（大者优先）
  "target": "my_strlen"                     // 可选：调用点改指向模块内的该函数
}
```

### 6.2 加载与命中扫描

- 构建期 `ModuleLinker::loadDir($dir)` 载入目录下全部 `*.json`（按文件名排序，保证 `priority` 同级时取先加载者）。
- 主程序源码经 `matchSource()` 扫描**函数调用位**（下一个有效 token 是左括号，且非 `->` / `::` / `new` / `function` / `use` 等位置的 `T_STRING`）：先精确 `match`，再 `match_regex`；多清单命中取 `priority` 高者。
- 返回 `matchedName => spec`，作为后续链接的种子。

### 6.3 传递式依赖链接

主源码命中的模块，其源码里可能调用**另一个未在主源码出现的模块**（如 `cross_run` → `mathx_double`）。`linkModules()` 以主源码的命中点为种子，递归发现并链接被引用模块（`linkRecursive`），**依赖方优先编译** —— 保证被调用方先进入 `linkedAlias`，调用点才会走 `CALL` 而非 `CALL_NATIVE`（否则运行期在真实函数表里找不到而致命）。

### 6.4 别名机制与合并

- 模块顶层函数以 `'_ml' . substr(hash('sha256', specName.'|'.fn), 0, 12)` 注册，字节码里**不出现模块原始函数名**。
- `renameMap()` 把调用点原名 → 别名；主程序 / 嵌入段已定义的原始名不重写（**主程序优先**）。
- 模块的函数表 / 类表 / 参数表 / 全局表合并进主 Program；模块内的 `include` 复用主程序的 `IncludeResolver`，嵌入 id 落在主程序 id 空间。
- 同模块被多个名命中（如 `match_regex /^rx_/` 同时命中 `rx_tag` / `rx_wrap`）只链一次，但每个命中名都登记到 `linked`，使主编译器预登记走 `CALL`（否则运行期 `CALL_NATIVE` 致命）。

### 6.5 原生兜底内嵌

模块**匹配但未被 VM 内联**（`replace=false` 或链接失败）时，调用点仍走 `CALL_NATIVE`；若产物里没有该函数会运行期致命。此时 `inlineSource()` 把模块源码（entry + deps + 静态 include 全部递归内联、去开/闭标签、移除 include 指令）以**原生形式兜底**进产物顶层，保证自包含、不致命。

### 6.6 与 IncludeResolver（P4）的分工

- 模块链接优先：命中的模块 → 内联进 Program（本类）；
- 未命中但路径可静态解析的 include → IncludeResolver 静态内联；
- 两者都做不动的（变量路径）→ `INCLUDE_EMBED` 运行期嵌入。

### 6.7 用法

```bash
# CLI：指定模块目录（JSON 清单所在目录）
php entry.php --input=app.php --output=app_obf.php --vm --vm-linker=tests/fixtures/modules
```

GUI（LayerDescriptor 分层参数 `modules`）与 CLI 等价：分层配置传 `modules` 键、`vm_linker` 散装键会自动折算为 `moduleDir`。相对路径优先按主文件所在目录解析（与 include 的 `baseDir` 一致）。

构建日志会报告每个命中 / 跳过：

- `shell:vm module-link: cross_run -> 模块[crossmod]（已内联）`
- `shell:vm module-link 跳过 mathx_double: 模块 mathx replace=false，未内联（调用点保持原生分发）`

### 6.8 测试

`tests/vm_module_link.php`（17 项）覆盖：精确 / 正则命中、多命中名复用、传递式跨模块链接、别名重写、原生兜底内嵌（`replace=false` / 链接失败）、模块内 `include` 扁平化、顶层语句忽略等。回归 `tests/run_tests.php`（32 项）、`tests/hardening_test.php`（32 项）全绿、无回退。

`tests/vm_builtin_modules.php`（96 项）覆盖：`src/Module/builtin` 下全部内置常用模块的"原生执行 vs VM 内联执行"逐字节一致性，并断言调用已被 `ModuleLinker` 命中（hits 含函数名，证明已内联而非回退原生分发）。

### 6.9 内置常用模块清单（src/Module/builtin，v1.0.1 新增）

默认模块目录 `src/Module/builtin`（`Config::defaultModuleDir()`）内置 30 个常用函数模块，VM 构建时若源码命中即**静态链接**进同一 `Program`（函数表 / 类表合并 + 别名重写 `_ml<sha256 前 12 位>`），产物中无模块路径、无 `include`、无原始函数名。

| 类别 | 模块（match 名） |
| --- | --- |
| 字符串 | `strlen` `substr` `strpos` `strtolower` `strtoupper` `trim` `ltrim` `rtrim` `strrev` `str_repeat` `str_pad` `ord` `chr` `explode` `implode`（含别名 `join`）`strcmp` |
| 数组 | `array_reverse` `array_merge` `array_keys` `array_values` `in_array` `array_sum` `array_unique` |
| 数学 / 编码 | `abs` `round` `number_format` `base_convert` `urlencode` `urldecode` `rawurlencode` |

模块实现约束（VM 可编译子集）：变量名统一 `__mod_` 前缀避免与原生冲突；`target` 把调用重定向到模块内函数；仅用 `[]` 字面量（不用 `array()`）、`foreach` 键值遍历、三元、`...$args` 变参等 VM 支持构造；模块名恰好为 `array_keys` 时，`compileForeach` 取键辅助调用改走 `CALL_NATIVE`（原生 `array_keys`）以免被 `renameCallTargets` 误改写为模块自身别名导致无限递归。

> **已知 VM 限制**：数组的整数键与数值型字符串键在 VM 内部表示中分离存储（位置式 vs `['__vmnamed__', key, val]` 元组），因此 `array_keys([1,2,3,"2"=>4])` 这类【整数键与字符串键碰撞】的输入在 VM 下与原生 PHP 结果不同（原生 `"2"` 键会折叠进整数键 `2`）。该差异源于 VM 数组模型本身，属边界场景，回归测试已剔除此类碰撞用例。

### 6.10 字节码级真实垃圾指令（`--vm-junk`，P7）

在**编译期、字节码层面**向已编译的 VM `Program` 注入"真实执行"的垃圾指令序列（而非在原始 PHP 源码 token 层插入废代码，那是 `src/Optimize/RuntimeJunk.php` 的职责）。

**与 RuntimeJunk 的本质区别**：垃圾指令是 VM **opcode 序列**，进入 gzip 二进制字节码容器、并随分片加密 + 操作码随机化一起被掩盖。静态分析者反编译出的只有一串被打乱编号的操作码，看不到任何 PHP 语句，抗分析更强。

**注入时机**（`RuntimeGenerator::buildArtifact` 合并完所有段之后、`collectUsed` / `remap` / 控制流平坦化之前）：

- 此时垃圾 opcode 会被纳入"已用操作码集合"——分配随机编号 + 生成 handler；
- 且能被随后的 `flatten` / `stateMachine` 进一步打散，与跳转表 / 分片加密叠加。

**安全性论证（绝不破坏语义）**：

1. 仅使用 `PUSH_C` / 二元运算 / 一元运算 / `DROP`，**绝不**读写帧变量表、不碰操作数栈之外的状态；
2. 每条"片段"以 `PUSH_C` 开头、以 `DROP` 结尾，中间每个运算恰好消耗一个 / 两个操作数并压回一个，**净栈变化恒为 0**（各模板均有平衡推导），执行前后操作数栈完全一致；
3. **不含 `JMP/JZ/JNZ`**（避免引入非法跳转目标破坏偏移），不含 `RET/HALT/ECHO/EXIT/CALL` 等终结 / 输出 / 调用指令，故插入到任何合法指令之前都安全可达、无害；
4. 经 salt 确定性生成，同 salt + 同源码 + 同强度 ⇒ 产物逐字节一致（可复现）。

**相对跳转偏移修正（关键正确性保证）**：注入会改变段内指令下标，而 `JMP/JZ/JNZ` 的偏移是"相对指令自身下标"的（绝对目标 = `源下标 + 1 + 偏移`，取指后 pc 已 `+1`）。`VmJunk::spliceSeg` 在 `array_splice` **之前**快照所有跳转的旧下标与旧绝对目标，splice 之后按"旧源 / 旧目标各自是否越过插入点 p"分别平移 `len` 重算新偏移：

```
新源   = 旧源   + (旧源   >= p ? len : 0)
新目标 = 旧目标 + (旧目标 >= p ? len : 0)
新偏移 = 新目标 - (新源 + 1)
```

> ⚠️ 早期实现在 `array_splice` **之后**用"已平移的新下标"去套"未平移的旧偏移"算目标（`t = j(+len) + 1 + d`），导致源 / 目标平移不一致时偏移错算——`junk=6` 以上的 `for/while` 循环在 linear 模式即死循环 / 超时。修复后 `junk` 在 linear / flatten / stateMachine 三种模式下输出均与原始一致。

**用法**：

```bash
php entry.php --input=app.php --output=app_obf.php --vm --vm-junk=6
php entry.php --input=app.php --output=app_obf.php --vm --vm-junk=12 --vm-flatten
```

- 强度建议：`0`（默认，不注入）~ `200`（LayerDescriptor `shell:vm` 的 `junk` 参数，默认 0，上限 200）。值过大时被 `min(1, ceil(段长/4))` 的逐段上限自动钳制，避免单段被塞爆。
- GUI：分层 `shell:vm` → **字节码垃圾指令**（整数，0–200）。

**测试**：`tests/vm_junk.php`（120 项，junk∈{0,3,6,12} × 3 种模式 × 10 个用例）全部 PASS，并额外做"注入增量检查"（junk>0 时 Program 操作码总数严格大于 junk=0，90/90 通过），回归 `run_tests` / `hardening` / `vm_gen_probe` / `vm_module_link` 全绿、无回退。

---

## 7. 动态运行时生成器：反分析能力

`VmRuntimeGenerator` 在每次构建时产出**每文件专属**的 VM 解释器，叠加多层互不影响的能力：

| 能力 | 机制 | 抗分析效果 |
|------|------|-----------|
| **操作码随机化** | 每文件把逻辑操作码映射到一组打乱的随机编号（再叠加 `scramble` 异或） | 按"固定 1=ADD"的特征库直接失效；两个文件的 opcode 数值互不相同 |
| **选择性发射** | 仅源码真正用到的操作码进入 runtime；未出现的指令（如 `_PHPRUN`）根本不生成 handler | 体积更小、特征更少 |
| **混淆分发** | 主循环以随机变量名编写；`switch` 的 case 顺序被打乱并注入诱饵 case；操作码以"被异或后的随机数字"作标签 | 看不到 `VmOp::ADD=10` 这类明文，难以还原指令语义 |
| **二进制字节码容器** | 操作码 + 操作数经 **varint + 操作数类型标记**编码为二进制，再 gzip | 静态无法直接读取明文指令序列 |
| **分片独立加密** | gzip 字节码切分为 N 片，每片用独立密钥 XOR 后 base64 内联，运行期分别解密再拼接 | 单一密钥泄露无法还原整段字节码 |
| **常量池加密**（默认开，`--no-vm-cpool` 关） | 所有 `PUSH_C` 字面量操作数抽入独立常量池（指令改索引引用、重复字面量去重），池以独立密钥（计数器模式 SHA-256 流，`vm-cpool` 盐派生）加密后 base64 内嵌 | 运行期解码后的指令流里只有索引没有明文；静态提取分片密钥（`$___KEYS___`）只能还原指令流，常量另有一密钥 |
| **跳转表调度** | `opcode ^ scramble` 经随机化跳转表（`array(...)` 字面量）映射到 case 标签，条目顺序随机 | 指令执行顺序被打乱，控制流难以线性还原 |
| **控制流平坦化**（可选 `flatten`） | 编译期把线性指令重写为通用 `JMP/JZ/JNZ + 状态变量`，不新增专用指令 | 删除原有的自然块结构 |
| **状态机混淆**（可选 `stateMachine`，与 flatten 互斥） | 在基本块划分之上做状态 ID 随机化（salted 非连续）+ 注入不可达诱饵状态 | 更激进的状态转移路径，诱饵状态干扰分析 |

> 两种控制流混淆层（`flatten` / `stateMachine`）取其一即可；二者均比"运行期 GOTO 平坦化"更可控、更易与跳转表/分片加密叠加。

---

## 8. 已知坑（已修 / 需规避）

- ✅ **`for` 初始化段必须是赋值**：早期 `compileFor` 误用 `compileExpr` 编译初始化段，导致 `$i=2` 的右值被吞掉（只压入 `$i`，循环从 0 开始）——已修复为 `compileExprOrAssign`，并补 `for_init` / `for_nested` 回归用例。
- ✅ **JMP 回跳必须指向条件起点**：上述修复同时修正了 `for` 循环回跳偏移的 off-by-one。
- ⚠️ 跨函数变量**无**污染（每次调用独立帧），但**顶层语句共享同一个全局帧**（与 PHP 全局作用域一致），注意变量名复用。
- ⚠️ `ARRAY_KV` 构建关联数组时按"值→键"压栈，运行期已正确逆序，遍历顺序与原生一致（有 `arr_kv_order` 用例守护）。
- ✅ **`match` 表达式的 token 边界**：`StatementSplitter` 曾把尾部 `;` 单独切成 `[';']` 空语句，导致 `parsePrimary(';')` 报错；已修正为 flush 时剔除顶层 `;`（裸空语句直接丢弃）。同时 `parseMatch` 收集分支结果改用 brace/paren/bracket **平衡收集器** `collectMatchArmResult()`，正确处理结果中含嵌套 `match`/`函数调用`/`数组` 的情形（其内部的 `,` 与 `}` 不再被误判为分支分隔符 / 体结束符）。已补 `match_int/match_var/match_multi/match_assign/match_nested` 等回归用例。
- ✅ **花括号字符串插值**：`T_CURLY_OPEN` 的成对花括号曾被误判为语句块边界而 flush，导致插值串被截断；已引入独立的插值串深度 `$iDepth` 跟踪，`"$x"` / `{$arr['k']}` / `"${x}"` 三种形态均可正确编译。
- ✅ **实参运行期绑定的三个连带坑**（v0.1.4 修复）：
  - **NEW handler 不得直接调用展平闭包**：`$__flatten` / `$___FLAT___` 不在解释器闭包的 `use` 列表中（闭包只捕获显式声明的变量），`new C(...$a)` 时运行期报 "Value of type null is not callable"——改为把标记原样传入 `$__newObj`，由内部的 `$__bindArgs` 统一展平。
  - **命名实参重排会留键空洞**：`f("x", c:"z")` 重排后得 `[0=>"x", 2=>"z"]`（跳过 `$b`），默认值补齐若按 `count()` 判定会把默认值追加到最高键之后，实参整体错位——补齐改为按 `array_key_exists($i, ...)` 逐位判定并 `ksort` 复位键序（下游 `POP_V` 序言按栈**位置**消费）。
  - **by-ref 形参不能传字面量 null**：`$__bindArgs($args, null, null)` 触发 "Argument #3 ($cv) could not be passed by reference"——原生类构造 / 原生对象方法路径改经局部变量中转（`$ncv = null`）。
  - 另修复参考运行期 `ARG_NAMED` 常量漏定义（handler 成死代码）与 `SCALL` 原生类路径漏绑定（标记泄漏给原生方法）两处双运行期不一致。
- ✅ **CALL_VAR 产物语法缺陷（v0.1.7 修复）**：生成运行期 `caseBody(CALL_VAR)` 曾把「绑定实参 + 调用」写成 `([$a,] = f(), g())` 逗号表达式——PHP 无逗号运算符，凡使用变量函数调用（`$f(...)`，含 FCC 创建后调用）的产物全部 `php -l` 失败（运行期表现为 `ParseError: Unclosed '{' ... does not match ')'`）。已改为 if/else 结构（与参考运行期逐行对齐）。
- ✅ **FCC 双运行期一致性（v0.1.7 新增）**：`$___FCC___` / `$__fcc` 建闭包函数初版漏抄了「VM 类名 FCC 分支的闭合括号 + `if(isset(...))` 块后的 stray `;`」，导致原生对象 FCC 路径被嵌进 VM 分支永远不可达、函数体括号不平衡——生成产物整体语法错误。修复后 FCC 探针三模式（flat_off/flat_on/sm_on）19 例、P8 特性矩阵 10×6 全绿。
- ✅ **trait 适配块 `insteadof` / `as`（v1.0.0 新增）**：编译期完成冲突消解与别名注册。规则按「先 insteadof 后 as」应用（与声明顺序无关）；冲突判重按**根 trait**（trait 内 `use` 的递归展开），同一 trait 经不同路径引入不冲突；未限定 `as` 用**规则应用前**的根集合判歧义（与是否有 insteadof 消解无关，对齐原生两种规则顺序均致命的语义）。以下场景由 `VmUnsupported` 触发整文件回退、交还原生产出真实致命错误：未消解方法冲突、未限定 `as` 歧义、`as` 别名撞名、insteadof 保留方/排除方不存在或自排除、trait 属性/常量/静态属性定义不一致、常量上的 `as`（原生视为方法别名而致命）、`as static` 等非法形态。已知缺口：VM 不记录方法可见性（`as protected pm` 的别名越权调用不抛错，原生会抛）；VM 类非真实 PHP 类，`ReflectionMethod` 不可见（与 trait 无关的存量架构限制）。探针：trait 语义 44 例（35 原生基线 + 9 追加）× 4 驱动（参考运行期 + 生成运行期三模式）全绿；真实管线 trait 重文件 `--vm` / `--vm --vm-p8` 输出与原生逐字节一致。
- ⚠️ **变量自增自减改用复合指令序列**（v1.0.0）：`++/--` 简单变量不再有专用操作码，改由 `PUSH_V/PUSH_C/ADD/SUB/DUP/POP_V` 叠加而成，对数值 / null / 数组语义与原生一致；但**字符串字母进位**（`"a"++`）与**布尔自增**（`++true`）两个 PHP 边角语义无法由通用算术表达，会分别得到 `TypeError` / `int`。详见 §11.2。

---

## 9. 五个架构方向（P8）

在既有 VM 之上再叠加五个**可独立开关**的架构级加固方向。全部默认关闭（`--vm-p8` 一键全开，单项可覆盖），开启后产物运行结果与原文逐字节一致（`tests/vm_p8.php` 77 项断言守护：语义保真 + 反分析实效）。

| 方向 | CLI | 机制 | 抗分析效果 |
|------|-----|------|-----------|
| **arch_key_chain** 密钥链分片加密 | `--vm-keychain` | 分片密钥由**哈希链**派生：`k0` = 盐派生的 master seed，`k[i]=sha256(k[i-1]‖i)`；运行期从 seed 起逐片顺序推导，`keys` 仅存 seed 一个元素 | 静态提取单点密钥无法还原其它分片（前向链）；密钥非单一静态串 |
| **arch_builtin_replace** 内置名编码 | `--vm-builtin-replace` | `natTbl` 里的内置函数名在 `packProgram` 时经 `base64(name XOR keystream)` 编码后写入二进制包，运行期 `$___CALLFN___` 内解码还原 | 解压后的字节码 blob 中**不再出现** `strlen`/`array_map` 等明文调用名 |
| **arch_morph_runtime** 运行时变形 | `--vm-morph` | 操作码派发叠加**独立的第二层 scramble**（盐派生），`jt` 键与运行期 `$op^scramble2` 同步；派发临时变量名随机化 | 每构建的"派发数学"不同，消除固定 dispatch 指纹；跨 salt 产物结构互异（多态） |
| **arch_fake_fog** 诱饵函数 | `--vm-fake-fog=N` | 向 `funcs` 表注入 N 个**永不调用**的栈平衡假函数（`_fg_<hex>`，无输出、不写变量，附 0~2 个默认参数），在 `collectUsed` 前注入 | 诱饵 opcode 进入"已用集合"→真实指令被噪声淹没；函数表规模膨胀，统计特征失效 |
| **arch_probe_matrix** 反分析探针 | `--vm-probe=N` | 解释器运行前注入 N 个自检探针（`dbg`：xdebug/phpdbg/uopz/runkit 检测；`crc`：对解码后 blob 取 crc32 与构建期期望值比对），判定量拆到随机变量，命中即静默 `exit(0)` | 调试 / hook / patch 字节码均无法产出正确结果；正常 standalone 运行下恒为假、无副作用 |

### 9.1 组合与分层

- 五者与 `flatten` / `stateMachine` / `--vm-junk` / `--vm-linker` **正交**，可任意组合；`tests/vm_p8.php` 覆盖 `all_linear` / `all_flatten` / `all_sm` 三态全开。
- 分层 JSON / GUI：`shell:vm` 层参数新增 `keychain` / `builtinReplace` / `morph` / `fakeFog` / `probe`（见 `LayerDescriptor`）。
- `--vm-p8` 聚合预设：`keychain`+`builtinReplace`+`morph` 开、`fakeFog=6`、`probe=4`；单项参数可再覆盖。

### 9.2 实现要点 / 边界

- **keychain**：链式解码序章与 `encryptChunks($blob,$n,$chain)` 严格对称；空 blob 亦保证 `keys[0]` 存在。关闭时每片独立派生（旧行为）。
- **builtinReplace**：解码器经字符串注入到 `$___CALLFN___` 的 `use(...)` 列表（闭包作用域，必须显式捕获）；`program` 逻辑层 natTbl 仍保留原名，仅打包层编码。关闭时产物不含任何额外代码。
- **morph**：关闭时 `scramble2=0`，派发 `^0` 与旧行为逐字节等价（默认产物不受影响）。
- **probe**：刻意**不做**"调用栈回溯深度"探针——被保护脚本常被第三方 `require`/`eval` 嵌入，回溯深度不可作调试判据（误杀正常用法）；仅 `dbg`（真实调试器/hook 敏感）+ `crc`（字节码改动敏感）。

---

## 10. 二进制容器：varint 整数往返（修复记录）

`packProgram` 的整数操作数走 **zigzag + varint** 编码（`bWriteZig` → `bWriteVarint`）。

**曾存在的 bug**：`bWriteVarint` 内含 `$v &= 0x7FFFFFFF` 截断。zigzag 会把数值翻倍
（`n → n<<1`），任何 `|n| ≥ 2^30` 的常数翻倍后即越过 2^31，最高位被静默砍掉——
运行期解包（读端无截断）得到错误值。典型症状：**乘积低 32 位偶然正确、高位全错**
（如 `119673848*1103515245` 在产物中算出 `3563099859194008` 而非 `132061915695812760`），
极易被"看起来差不多对"的测试漏过。用户代码中的 LCG 常数（`1103515245`/`2246822519`/`3266489917`
等）恰好密集落在该区间，由大整数常量回归用例（`tests/vm_gen_probe.php` 的 `bigconst_*`）首次暴露。

**修复**：移除截断（调用点均为非负值：zigzag 结果 / 指令数 / 字符串长度），
仅保留负值防御护栏（`&= PHP_INT_MAX`，防算术移位死循环，不影响任何正值路径）。
`tests/vm_gen_probe.php` 新增 5 条大整数常量回归用例（`bigconst_*`：
原始乘积 / 掩码运算 / 负常数 / 函数内 LCG），三态模式全覆盖。

---

## 11. v1.0.0 正式版：`$this` 指针 / 操作码复合化 / 编译器拆分 / VM 嵌套层数

### 11.1 `$this` 指针完整 VM 实现

实例方法内的 `$this` 由编译器**显式支持**，不再依赖运行期隐式注入：

- `$this` 作为普通帧槽名（含 `$` 前缀的 `'$this'`）编入局部变量表；
- 实例方法序章发射 `[LOAD_THIS] + [POP_V, '$this']`，运行期 `__callMethod` 把当前对象注入 `$this` 帧槽，随后 `$this->prop` / `$this->m()` 走常规属性读 / 方法调用路径；
- `compileFunctionCode(array $stmt, bool $isStatic)` 依据静态上下文决定是否发序章，并用 `curMethodStatic` 守卫 `varName()`；
- **静态方法内使用 `$this`** → `VmUnsupported::fileLevel('VM 静态方法内使用 $this')`，整文件回退（交还原生产出原生致命错误）；
- **对 `$this` 重新赋值**（`$this = $x` / `$this = &$x`）→ 在 `compileExprOrAssign` 与引用赋值守卫处拦截，同样走整文件回退。

验证：实例 `$this` 在 `--vm` / `--vm --vm-p8` 下输出与原生逐字节一致；静态误用 / 重赋值均正确回退。`cap_scan` / `p8` / `gen_probe` / `args` / `trycatch` / `ref_e2e` 全绿、零新增回退。

### 11.2 操作码复合化：变量自增自减改用复合指令序列

原先变量自增 / 自减有 4 条**专用操作码**（`PRE_INC=80` / `PRE_DEC=81` / `POST_INC=82` / `POST_DEC=83`）。这些本质是「取变量 + 加/减 1 + 写回」的叠加，可由更基础的栈指令表达，故**已从 ISA 中移除**（`Op.php` 的 `OPERANDS`/`NAMES`、参考运行期 `runtime.php`、生成运行期 `RuntimeGenerator` 的对应 handler 一并删除）：

```
前缀 ++$x :  PUSH_V $x; PUSH_C 1; ADD; DUP;  POP_V $x   // 栈留新值
后缀 $x++ :  PUSH_V $x; DUP;       PUSH_C 1; ADD; POP_V $x   // 栈留旧值
（-- 把 ADD 换成 SUB）
```

- 属性 / 下标自增族（`PROP_*_INC/DEC`、`IDX_*_INC/DEC` 共 8 条）因需对象 / 容器在栈上、干净叠加需 `SWAP` 原语，**仍保留**专用码，统一走 `INC_OP`（op 203）族分发（`VmIsa::INC` 已剔除前 4 个变量码，sub-id 0–7 对应属性 / 下标 8 条）。
- 编译器发射逻辑集中在新碎片 `src/Vm/Parts/VmIncTrait.php::emitIncDec()`。
- **语义边界（已知限制）**：复合序列用 `ADD/SUB` 做纯算术自增，对 `int/float/null` 与原生完全一致（`null` 经 `ADD` coercion 为 0），数组自增两者均抛 `TypeError`（一致）；但 PHP 的两个边角语义无法由通用算术表达——
  1. **字符串字母进位**（`$x="a"; $x++;` 原生得 `"b"`）会触发 `string + int` 的 `TypeError`；
  2. **布尔自增**（`++true` 保持 `true`）会 coercion 为 `int`。
  
  这两类是极罕见写法，作为复合编码的已知限制记录；主流用法（数值计数器、循环累加、静态属性 / 静态变量自增）经 `--vm` / `--vm --vm-p8` 双运行期验证与原生一致（`tests/zz_incdec_probe.php` 16/16 必须一致项通过）。

### 11.3 编译器拆分多文件

`VmCompiler` 主体继续按职责拆分为多个 trait 碎片（均在 `src/Vm/Parts/`，由主体顶部 `require_once` 加载、类内 `use` 组合，**同一个类体**，可直接互访 private 成员，纯源码组织、行为等价）：

| 碎片 | 职责 |
|------|------|
| `VmDeclTrait.php` | 声明：函数 / 类 / 方法 / 属性 / trait 适配块、类型提示 |
| `VmStmtTrait.php` | 语句派发、语句块 / 无花括号体收集、try/catch、include、echo、static 局部、控制逃逸守卫 |
| `VmControlTrait.php` | **控制流**（v1.0.0 新增拆分）：`if/elseif/else`、`while`、`for`、`foreach`、`switch`、`do-while`，及 `break/continue` 回填、`goto/label` 校验与回填辅助 |
| `VmExprTrait.php` | 表达式：前缀 / 后缀 / 三元 / 运算 / 属性 / 下标 / 调用 |
| `VmLitTrait.php` | 字面量、插值、常量池 |
| `VmTokTrait.php` | token 工具、游标辅助 |
| `VmIncTrait.php` | **变量自增自减复合序列发射**（v1.0.0 新增拆分，见 §11.2） |

拆分后全量回归（`run_tests` 32、`cap_scan` 95/18 基线、`p8` 77、`gen_probe` 120、`args` 93、`trycatch` 64、`ref_e2e` 14、`subscript`、`goto` 端到端）零差异。

### 11.4 VM 嵌套层数（真·嵌套 VM）

新增配置项 **`vm_layers`**（CLI `--vm-layers=N`，默认 `1`）：

- `=1`：单层 VM 壳（默认行为，与旧版一致）；
- `>=2`：**真·嵌套 VM**——把上一层 VM 产物（本身以 `<?php` 开头）再剥开 / 闭标签后喂给 VM 编译器，逐层叠加多层 VM 壳。产物体积随层数近似线性增长（实测 35KB → 80KB → 139KB），运行结果与原生逐字节一致；
- 实现位于 `Obfuscator::shellVm()`：`for` 循环调用 `shellVmOnce()`；某层未改变内容（编译失败回退原样）时提前终止，避免无效叠加；
- 任意层若编译失败均优雅回退到上一层产物（保留更强的那层壳），不破坏构建。

```bash
php entry.php --input=app.php --output=app_obf.php --vm --vm-layers=3 --vm-p8
```

### 11.5 测试随仓库提交

自 v1.0.0 起 `tests/` 目录纳入版本控制（`.gitignore` 不再排除），随发布一并提供，便于他人复现回归（`run_tests.php` 32 项冒烟 + 集成、`vm_cap_scan.php` 能力矩阵、`vm_gen_probe.php` 120 项三态、`vm_p8.php` 77 项、`vm_args_probe.php` 93 项、`vm_trycatch_probe.php` 64 项、`vm_ref_e2e.php`、`vm_subscript.php`、`zz_incdec_probe.php` 等）。

### 11.6 v1.0.1：30 个静态链接的常用模块

- 新增 `src/Module/builtin/` 目录，内置 **30 个常用函数模块**（字符串 16 + 数组 7 + 数学/编码 7），JSON 清单 + `__mod_` 前缀实现，VM 构建时由 `ModuleLinker` 自动静态链接进同一 `Program`；
- 配套 `tests/vm_builtin_modules.php`（96 项）回归：每个模块多组输入比对"原生 vs VM 内联"逐字节一致，并断言调用已被链接（hits 含函数名）；
- 修复 `compileForeach` 两处缺陷：
  1. **键值遍历值变量名取空白 token**——`as $k => $v`（带空格）时 `$vvar` 落到 `=>` 与值之间的 `T_WHITESPACE` token，得到空白/空变量名，导致值永远存不进（取出恒为 `null`）；改为跳过空白 token 再取值；
  2. **foreach 取键辅助 `array_keys` 被误改写**——`compileForeach` 原先发射 `CALL 'array_keys'`，当模块名恰好为 `array_keys` 时 `renameCallTargets` 把该内部调用也改写成模块自身别名，造成无限递归（OOM / Killed）；改为 `CALL_NATIVE` 调用原生 `array_keys`（恒走原生分发，免疫重命名）；
- 已知 VM 限制：数组整数键与数值型字符串键分离存储，碰撞类输入（`[1,2,3,"2"=>4]`）VM 与原生结果不同，回归已剔除此类用例（详见 §6.9）。

### 11.7 v1.0.2：静态局部变量 + 既有测试修复

- **修复 VM 静态局部变量在「GOTO 控制流混淆 + VM-P8」组合下丢失持久化**：`static $x` 计数器在混淆产物中恒为 0（原生 `1,2`）。
  - 根因：VM 原在「编译到 `static` 声明处」才登记 `curStatic` 并发射 `GLOBAL_REF` 绑定。GOTO 混淆把函数体改写成交错状态机，使 `$x++` 等使用点出现在 `static $x` 声明之前；此时使用点已被编译为普通局部槽位，与静态共享槽位断开，导致每次调用重置。
  - 修复：在 `compileFunction` / `compileFunctionCode` 进入函数体（`compileBlock`）前新增 `emitStaticPreamble` 预扫描——登记全部静态局部变量并按声明顺序在函数入口预绑定共享帧槽位（`GLOBAL_REF`），`varName()` 据此把任意使用点统一路由到同一持久槽位，与声明顺序无关，亦对齐 PHP 原生「声明前可用」的提升语义。预扫描对嵌套 `function/class/trait/interface/fn` 定义体整体跳过，避免串作用域。
  - 配套 `tests/token_obf.php` 三处组合（`token_full` / `level=mid` / `level=max`）回归由红转绿。
- **修复 by-ref 原生函数遇到属性/下标左值（如 `sort($o->items)`）引用失效**：按值压栈但 `refMask` 仍置位，运行期收到 "value given" 警告且原地修改失败。改为当引用位实参非简单变量时抛 `VmUnsupported`，整条语句走 `EVAL_STMT` 原生桥接（引用语义由桥接保真）。
- **修复 `tests/vm_module_link.php` 的 `badmod_skip` / `stats` 两项**：原 fixture 用 `trait/insteadof` 充当「VM 不支持语法」，但 VM 现已全面支持 trait 适配，模块被整编成功而非跳过；改用 `yield` 生成器（VM 唯一可靠抛 `VmUnsupported` 的构造）使整文件编译失败、调用点保持原生函数分发（P6 前行为一致）。
- 全测试套件保持通过：`run_tests.php` 32 项、`token_obf.php` 31 项、`vm_call_native` / `vm_module_link` 全绿，VM 综合（`vm_comprehensive`、`vm_args_probe` 93、`vm_ref_e2e` 14、`vm_gen_probe` 120、`vm_p8` 77、`vm_junk` 120、`vm_trycatch_probe` 64、`vm_subscript`、`vm_defaults`、`vm_include`、`vm_eval_stmt`、`vm_smoke` 4、`vm_builtin_modules` 96、`hardening` 32）无回归。

### 11.8 v1.0.3：混淆复杂度大幅提升（等级预设全面加码 + 新增 ultra 顶档）

- **五档等级预设全面加码**（`low/mid/high/max`，新增最高档 **`ultra`**），产物规模随等级指数上升（token_rich fixture：low 25KB → ultra 364KB）：
  - `low`：由"仅 token 级混淆"升级为已含**基础 VM 壳**（chunks=3）+ 数字/字符串编码 + 轻度控制流（cfg=low, goto=1）；
  - `mid`：VM 平坦化 + **启用 P8 架构方向**（`morph` + `keychain` 密钥链 + `builtinReplace` 内置名编码 + `fakeFog`=20 诱饵函数 + `probe`=4 探针），goto=2、eval=4；
  - `high`：全 P8 方向拉高（`fakeFog`=80、`probe`=12）、goto_cpx=8、junk_runtime=8、eval=6、anti_debug 探针扩展至 env/sapi、新增 anti_hook + anti_tamper；
  - `max`：单层 VM 参数顶格（chunks=12、`fakeFog`=160、`probe`=30）+ 拟真垃圾**执行缠结**（junk_runtime tangle）+ 全反检测矩阵（anti_debug 全探针 × intensity 4、anti_tamper segments=8）+ eval=8；
  - `ultra`（新顶档）：**真·嵌套 VM**（`shell:vm` 新增 `layers` 参数，layers=2 把上一层 VM 产物再 VM 化）+ 全参数顶格（chunks=16、fakeFog=200、probe=50、cpool）+ eval=10 + dead=10 + token 诱饵 50。
- **`shell:vm` 分层新增 `layers` 参数**（1-3）：分层模式（preset / 自定义分层 JSON）现在可启用真·嵌套 VM，此前仅散装 CLI `--vm-layers` 可用。
- **GOTO 混淆跳过 try/catch 函数**：`GotoObfuscator::containsUnsafe()` 的跳过列表在闭包 / `match` / 插值基础上补充 `T_TRY / T_CATCH / T_FINALLY / T_THROW`，含异常处理的函数体不再参与 GOTO 状态机改写（与既有"整体跳过"策略一致）。
- **预设稳健性验证**：每档以 `token_rich` fixture（属性 / 闭包 `use(&$x)` / match / trait / try-catch-finally / 静态变量 / heredoc）+ 独立样例各跑 **6 个盐值**逐字节比对原生输出，全部通过；`tests/token_obf.php` 等级遍历已加入 `ultra`（32 项全绿），`run_tests` 32 项、`hardening` 32 项及全部 VM 专项无回归。
- **已知限制（记录在案）**：
  - ~~`--vm-junk`（VM 字节码垃圾指令）注入段落在盐值随机分布下会**间歇性破坏 VM 异常桥接**~~（v1.0.4 已修复，见 §11.9——根因是垃圾注入未同步异常表）；
  - ~~`stateMachine` VM 模式对 try/catch/throw 的编译与 `flatten` 路径不等价~~（v1.0.4 已澄清：stateMachine 从未损坏，当时失败同为 VmJunk 缺陷连带）；
  - ~~`semantic` 语义等价变换对本仓库测试 fixture 存在破坏性~~（v1.0.4 已澄清：同上，语义变换本身无缺陷，预设已重新启用）。

### 11.9 v1.0.4：修复 VmJunk 异常表不同步（问题功能修复）+ 混淆引擎全量加强

- **修复「vm-junk 间歇性破坏异常桥接」根因**（v1.0.3 遗留的已知限制）：
  - 根因：VM 的异常表（`mainET` / `funcET` / `classET`，表项 `[tryStart, tryEnd, handlerPc, ...]`）与指令段**平行存储**，而 `VmJunk::spliceSeg()` 向段内插入垃圾片段后只修正了相对跳转偏移、**从未平移异常表的 pc 边界**——try 保护区整体错位，抛出的异常路由不到 catch/finally（表现为 `Uncaught Exception` 逃逸、catch 返回错误值或空）。由于垃圾插入点由盐值确定性随机选取，故障随盐值**间歇性复现**（junk=1 即可触发，junk≥20 高频复现）。
  - 修复：`spliceSeg()` 末尾按与跳转目标相同的平移规则同步调整对应段异常表的三个 pc 边界（`pc >= 插入点则 +片段长度`）；插入点落在 try 区间内部时区间自动扩为含垃圾片段（垃圾片段栈平衡、不抛异常，保护区语义不受影响）。
  - 连带澄清：v1.0.3 时误判为独立缺陷的 **`stateMachine` 模式 try/catch 失效**与 **`semantic` 变换破坏 fixture**，实为同一 VmJunk 缺陷的连带表现（当时的隔离配置均含 junk，个别"通过"组系盐值运气）。两者本身从未损坏。
- **混淆引擎全量加强**（基于修复后的安全顶格，五档预设再次全面加码）：
  - `high`：VM 由 flatten 升级为 **stateMachine** + junk=80，GOTO 2→3 层，新增 semantic（强度 2）；
  - `max`：stateMachine + junk=160，GOTO 2→4 层（cpx=12），新增 semantic（强度 3）；
  - `ultra`：stateMachine + junk=200 + 真·嵌套 VM（layers=2），GOTO 2→5 层（cpx=16 顶格），新增 semantic（强度 3）；
  - `mid`：VM 加 junk=20；`low`：VM 加 morph（消除固定指纹）。
- **语法兼容性验证**：新增覆盖 PHP 8.x 语法广度的测试样例（enum / readonly 属性 / 构造器属性提升 / 一等可调用语法 `strlen(...)` / `never` 返回 / 生成器 / union 类型 / 变长参数 / 引用传参 / 命名参数 / 展开运算符 / nullsafe 等），在 ultra 顶格配置下多盐值逐字节比对原生输出全部一致；token_rich fixture（属性 / 闭包 / match / trait / try-catch-finally / 静态变量 / heredoc）5 档 × 4 盐 × 3 样例矩阵全绿。
- **回归**：`run_tests` 32、`token_obf` 32、`hardening` 32、`vm_junk` 120、`vm_trycatch_probe` 64、`vm_p8` 77、`vm_gen_probe` 120、`vm_args_probe` 93、`vm_comprehensive` 28、`vm_ref_e2e` 14、`vm_builtin_modules` 96、`vm_call_native` / `vm_module_link` / `vm_smoke` 全部通过，无回归。

### 11.10 v1.0.5：38 个新内联模块 + 三类"坑人"级反分析（毒化壳 / 蜜罐 / GOTO 死状态）

- **内置模块 32 → 70 个**（`src/Module/builtin/`，JSON 清单 + `__mod_` 实现，`ModuleLinker` 静态链接进同一 `Program`）：
  - 字符串 18：`ucfirst` `lcfirst` `ucwords` `str_contains` `str_starts_with` `str_ends_with` `strrpos` `stripos` `strstr` `strrchr` `substr_count` `chunk_split` `addslashes` `stripslashes` `quotemeta` `strcasecmp` `strncmp` `substr_replace`；
  - 数组 12：`array_fill` `array_flip` `array_product` `array_pad` `array_slice` `array_search` `array_key_exists` `array_combine` `array_count_values` `array_diff` `array_intersect` `array_key_first`；
  - 数学/进制 8：`intdiv` `ceil` `dechex` `decbin` `decoct` `hexdec` `bindec` `octdec`。
  - 语义对齐原生实现细节：`strncmp` / `strcasecmp` 返回**原始字节差**（PHP 8.3 实测 `-2` 而非 `-1`）；`chunk_split('')` 返回终止符；`array_pad` 保持"字符串键保留、数字键重排"；`ucwords` 支持自定义分隔符集。
  - 回归 `tests/vm_builtin_modules.php` 由 96 项扩到 **205 项**，全部逐字节一致且新增模块均被命中链接。
- **`StructPoison` 结构指纹毒化壳（`--struct-poison`，max/ultra 默认）**——把"文件是否被改动"从**控制流**搬到**数据流**：
  - 构建期把载荷区替换为定长占位串得到骨架，`sha256(骨架受护区)` 经 1-8 轮 KDF 派生出 32 字节密钥流，`payload = base64(gzdeflate(inner) XOR keystream)`；
  - 运行期读 `__FILE__`、把载荷换回占位串、重算指纹、同路径派生密钥再解码。文件未改动时逐字节一致；**任何字节改动 → 密钥错位 → 解出乱码**，全程**无比较、无分支、无退出点**，hook 掉 `exit` / NOP 掉跳转这类传统绕过手段全部失效；
  - **插桩探针并入密钥**：`uopz_*` / `runkit7_*` / `override_function` 的在场计数直接进 KDF 参数——不修改文件、仅靠运行期 hook `gzinflate`/`eval` 抓明文的路径，拿到的是乱码而非明文；
  - 受护区用盐派生的起止标记圈定（`/*sp…*/` … `/*sx…*/`），因此可与 `anti_tamper` **叠加**：外层 anti_tamper 负责明文锚点与全文件分段校验，内层毒化壳负责自身解码链，互不破坏；
  - 另有死代码诱饵（`crc32` 伪校验值）与三种随机化的解码形态，消除固定指纹。
- **`Honeypot` 蜜罐函数（`--honeypot=N`，max=4 / ultra=6）**：
  - 注入 `deriveMasterKey` / `verifyLicense` / `unpackPayload` 等 16 个"逆向上最值得怀疑"的拟真命名函数（名字按盐洗牌，`function_exists` 防撞）；
  - 正常执行**永不调用**（零开销）；一旦被破解者主动调用/桩掉：首调用进入**时间上界 1.4s** 的确定性深循环（单步调试直接卡死），并在 shutdown 吐出一段"看似解密成功"的伪密钥污染自动化比对；返回值本身是输入参与哈希的拟真密钥串，误导其继续沿假线索逆向。
- **GOTO 诱饵死状态（`--goto-trap=N`，默认 2）**：每个分派器额外生成 1-4 个 `case`/`elseif` 分支，标签**从不被任何赋值产生**（正常执行永不可达），分支体把状态写回自身——分析者强行用调试器改状态变量跳进去即**自锁死循环**；静态上与真实分支外观完全一致，无法凭"有无跳入边"一眼判定死代码。
- **修复**：
  - `SemanticRewriter` 引用不存在的常量 `T_COLON`（PHP 8 下抛 `Error`），导致 semantic 层**每次构建都静默回滚**（`[层 semantic] 执行异常` 警告后整层失效）；改为字符 token `':'`；
  - `VmJunk::spliceSeg()` 经 `deref()` 隐式建出 `funcET[fn] = null`，`bWriteExTab()` 收到 null 抛 `TypeError` → **整个 VM 层静默回退为普通加壳**（max/ultra 上 `--vm-junk` 一开即触发）；现先把 null 归一为空表；
  - 分层顺序：`honeypot` / `structpoison` 移入"次外层"（`anti_tamper` 之内）。蜜罐若在 anti_tamper 之后注入，其追加的函数会落在受护尾区之外被判定为"篡改"；毒化壳若在 anti_tamper 之外，则 anti_tamper 的明文锚点被吞进密文。同时 `runLayered` 的 banner 注入点前移到毒化壳之前，避免 banner 追加破坏毒化壳指纹。
- **回归**：`run_tests` 32、`syntax_compat` 7（含 ultra 双盐）、`vm_builtin_modules` 205、`vm_junk` 120、`vm_p8` 77、`vm_args_probe` 93、`token_obf` 32、`hardening` 32、`vm_comprehensive` / `vm_call_native` / `vm_module_link` / `vm_include` / `vm_defaults` 全绿；五档预设 + 毒化壳三态（未篡改/美化/篡改）人工验证通过。

### 11.11 v1.0.6：逐层壳自保护（破一层先被恶心一层）+ 性能优化

- **`ShellProtector` 逐层壳自保护（核心新增，`--shell-shield-intensity=N`，默认随等级 1-3）**：
  - 设计动机：旧版各解码壳是「平铺」的——攻击者破掉最外层壳即可直接看到内层壳的**明文解码逻辑**，等于「破一层拿一层」。v1.0.6 让**每一层壳解码逻辑本身再被外层混淆护住**：在 `Obfuscator::shell()` / `applyLayer()` 每层包裹完成后，对该层产物施加一次 `ShellProtector` 变换（变量重命名 + 语义等价变换 + token 形态 + 压缩）。于是要读到第 N 层的解码逻辑，必须先解开第 N+1 层套在外面的混淆——**破一层要先被恶心一层**，攻击者无法「破一个是一个」。
  - 黑盒性保证：GZ / EVAL / VM / License 等壳把内层裹成字符串 / 字节码黑盒，承载内层密文的 base64 串是单 token，`ShellProtector` **永不触碰字符串字面量内容**（`token_quote` / `token_heredoc` 恒关），逐层套用不会改写内层载荷，自然形成「解一层先被恶心一层」的递归保护。
  - 三种模式：`full`（变量重命名 + token 形态 + 语义变换 + 压缩，供普通壳）、`norename`（仅 token 形态 + 语义变换，供 `anti_tamper` 等「靠变量名自匹配 needle / 自带区段哈希」的壳，避免破坏其自校验）、`caseonly`（仅 token 大小写随机，兜底）。强度 N 仅控制语义变换激进度，变量重命名始终开启。
  - 自参照壳特殊处理：`StructPoison` 在**指纹计算前**对自身施保护（受护区标记是注释、占位串是字符串字面量，均不被改写）；`AntiTamper` 的守卫行以 `norename` 模式自保护（保留变量名保证 needle 自匹配），且二者在 `shieldIfNeeded` 中跳过外层重复保护，避免整段字节改变导致运行期哈希失配误报篡改。
  - 排除项：目标 PHP 版本守卫（`phpver`）是运行时兼容校验、并非解码壳，对其施加保护等于把整段载荷再混淆一遍（无意义、拖慢、风险高），故跳过——真正需要「破一层先被恶心一层」的是各解码壳（vm / eval / gz / anti_debug / anti_hook / …）。
- **性能优化（`Salt` 记忆化）**：`Salt::derive()` / `deriveInt()` 增加实例级缓存（按 `(label,bytes)` / `(label,min,max)` 短路），纯确定性、不改变任何输出。被逐层壳保护放大的重复 `sha256` 调用得到削减，顶格等级构建耗时无退化。
- **新增 CLI**：`--shell-shield-intensity=N`（自保护语义变换强度 1-3）、`--no-shell-shield`（关闭逐层壳自保护，破解更易）。
- **修复（`ShellProtector` 压缩安全性）**：
  - 压缩去换行后，`//` 与 `#` 行注释会「吞掉」紧随其后的整段代码，导致产物运行期静默无输出——统一在压缩前把行注释改写为块注释（`/* … */`），块注释不依赖尾随换行；
  - `emit` 在 `<?php` 开标签后补边界空格，避免 `<?phpfunction` 粘连被误判；
  - `needsSpace` 在「词素单词后紧跟 `\` 起头的命名空间名」（`new \stdClass()`）处补空格，否则压缩成 `new\stdClass` 被 PHP 词法器误判为函数调用而报 `Call to undefined function new\stdClass()`。该规则同步修到 `Obfuscator::emit`，覆盖分层 `ws` 层的同等隐患。
- **回归**：`run_tests` 32、`syntax_compat` 7、`vm_builtin_modules` 205、`hardening_v105` 14、`hardening_v106` 29 全绿；五档预设（low/mid/high/max/ultra）产物运行输出与原文逐字节一致；`--no-shell-shield` 开关、`anti_tamper` 单字节篡改检测、结构毒化壳三态（未篡改/美化/篡改）均通过专项验证。

### 11.12 v1.0.7：VM 运行时内存加密 + 复杂语法兼容增强

- **操作数栈运行期内存加密（核心新增）**：
  - 动机：字节码虽已脱离 PHP 语句形态，但运行期操作数栈（`$__stack` / `$___STACK___`）里仍是**明文计算值**——内存转储或栈快照可直接读到中间结果（密钥串、解密后的载荷等）。
  - 机制：每个 `__VMRUN__` 帧初始化时用 `substr(hash('sha256', microtime() . memory_get_usage(), true), 0, 16)` 派生**每进程随机**的 16 字节 XOR 密钥（掺入内存指纹，重启/换进程即不同）；所有入栈值经 `serialize()` 后逐字节 XOR 循环加密，出栈/窥栈时解密还原。栈内静置的一律是密文，明文只在“出栈瞬间”短暂存在。
  - 旁路标记栈（不可序列化值）：对象、资源、以及**含闭包/对象的数组**无法 `serialize()`。这类值经 `try/catch` 捕获后存入独立的明文旁路表，栈上只放一个整数索引 + **并行标记栈**（`$__mcFlags` / `$___MCFLAGS___`，与值栈同压同弹保持对齐）。出栈时按标记决定解密还原还是取旁路值——因此含闭包数组、对象句柄等仍能正确往返，且不触发 `Serialization of 'Closure' is not allowed`。
  - 只加密操作数栈、**不加密变量帧**（`$__vars` / `$___VARS___`）：帧变量被 `CALL`/引用赋值/静态属性等大量按引用绑定，加密会破坏引用语义；栈值是转瞬即逝的中间量，加密它已足以阻断对“进行中计算”的内存窥探。
  - 双运行期同步：参考运行期 `src/Vm/runtime.php`（探针/预检用）与产物运行期 `src/Vm/RuntimeGenerator.php` 均接入同一套加解密与并行标记栈，语义逐字节一致。
- **复杂语法真编译扩展**（此前回退 `EVAL_STMT`，现进入自定义 ISA）：
  - `clone $obj`：新增 `CLONE` 操作码，弹对象克隆后压回（非对象与 PHP 一致抛 `TypeError`）。
  - 空安全 `?->`：新增 `NULLSAFE_PROPGET`（属性读）与 `NULLSAFE_MECALL`（方法调用）。接收者为 `null` 时属性读压 `null`；方法调用则弹出并**丢弃已编译的实参与方法名**后压 `null`。仅 `=== null` 触发短路（`0`/`''`/`false` 不短路），与 PHP 语义一致。
  - `??=`（空合并赋值）：语句级对简单变量按 `$a ??= rhs ≡ $a !== null ? $a : ($a = rhs)` 展开，复用 `??` 的 `DUP / PUSH_NULL / IDENT / JNZ / JMP` 跳转骨架，**仅在 `$a` 为 `null` 时求值 RHS**（短路副作用）。
  - 数组 / `list()` 解构赋值：`[$a,$b] = ...`、`["k"=>$v] = ...`、`[, $b] = ...`（跳位）、`list($a,$b) = ...`，复用 `foreach` 已有的 `emitListDestruct` 逐元素分发（支持键名、跳位、嵌套），RHS 先求值到临时帧槽再解构。
- **已知局限（保持回退，不保证真编译）**：空安全方法调用的实参在短路分支前已被求值（仅当“接收者为 null 且实参带副作用”时与 PHP 有细微差异）；`??=` 仅支持简单变量左值（属性/下标左值回退）；空安全 FCC（`$o?->m(...)` 的一等可调用形态）回退；生成器 / `yield`、闭包、一等可调用等结构性特性仍维持既有回退策略。
- **回归**：语法探针 `vm_syntax_probe` 真编译 581 → **588**（新增覆盖 clone / `?->` / `??=` / 四类解构），回退 31 → 24，失败维持 35（均为生成器/闭包/字符串自增/魔术常量等既有深度项，无新增回归）；生成器探针 `vm_gen_probe` 扩至 150 例（新增 10 例新语法 × 三模式）全绿 `FAIL=0`；`run_tests` 32；新增 `hardening_v107` 23 项（五等级功能保真 + 加密 helper 接入校验 + 含对象数组旁路往返 + 生产管线）全绿。

### 11.13 v1.0.8 正式版：VM 语法兼容性拓展 + 抗反编译 / 反 LLM 分析双线增强

- **VM 语法兼容性大幅拓展**（本版主线为「兼容性」，此前回退 `EVAL_STMT`/部分表达式，现进入自定义 ISA）：
  - **heredoc 插值**（`<<<EOT … $var … EOT`）：`T_START_HEREDOC` 探测到插值 token（`T_VARIABLE`/`T_CURLY_OPEN`/`T_DOLLAR_OPEN_CURLY_BRACES`）时走 `parseInterpolatedHeredoc`，按 `PUSH_C`+`CONCAT` 链拼装；纯字面 nowdoc（`<<<'ID'`）直接字面折叠；缩进式（flexible）heredoc 插值因难以保真仍回退。
  - **数组展开**（`[...$a, 3]`）：`emitArray` 检测到元素首项为 `...`/`T_ELLIPSIS` 时走 `emitArraySpread`，先行 `ARRAY_MAKE` N 个非展开元素，再对后续展开项 `ARRAY_SPREAD`、非展开项 `ARRAY_PUSH`（展开后接键名元素显式抛错以保正确）。
  - **`empty(任意表达式)`**（`empty(0)` / `empty(f())`）：`T_EMPTY` 对无法静态判定的目标 `try/catch` 回退到「编译实参 + `EMPTY_ANY` opcode」真编译（此前整体回退）。
  - **动态属性读写**（`$o->$p` / `$o->$p = v`）：`parsePostfix` 读 `$o->$p` 改发 `PROPGET_DYN`（弹属性名 + 对象再取值）；`matchLvalueSpan`/`compileLVResolve`/`compileLVStore` 接受 `T_VARIABLE` 型属性名左值，落 `PROPSET_DYN`。新增 `ARRAY_SPREAD=153` / `EMPTY_ANY=154` / `PROPGET_DYN=155` / `PROPSET_DYN=156` 四个 opcode，参考运行期 `runtime.php` 与产物运行期 `RuntimeGenerator` 双轨同步。
  - **回归**：语法探针 `vm_syntax_probe` 真编译 592 → **601**（新增 heredoc 插值 / nowdoc / 数组展开 / empty(任意) / 动态属性读写 / nullsafe / `??=` / list 解构 / clone 共 9 例全数「真编译」零回退），回退维持 20，失败维持 35（生成器/`yield`、闭包、一等可调用等既有深度项）；生成器探针 `vm_gen_probe` 扩至 **290** 例（含新语法 × 多模式 + combo 多强度）全绿 `FAIL=0`；新增 `hardening_v108` 35 项（五等级功能保真 + combo 等价性 + confrontation 注入校验 + newline-poison 独立/分层可见性 + ultra 全开管线）全绿。

- **VM opcode 组合执行（抗反编译，`--vm-combo[=N]`，N=1~3）**：
  - 动机：单条 opcode 与原始语义存在 1:1 映射，反编译工具可据 opcode 直接还原意图。把单 opcode 拆成等价多指令序列，可打散该映射。
  - 规则（经 `tests/vm_gen_probe` 全量等价性回归，运行输出与原文逐字节一致）：`SUB → NEG, ADD`；`PUSH_C 2, MUL → DUP, ADD`；`PUSH_C 2, POW → DUP, MUL`；`BOOL_NOT → PUSH_C false, EQ`。
  - 实现：新增 `src/Vm/VmCombo.php`，在 `RuntimeGenerator::buildArtifact` 完成程序装配（VMJunk / 扁平化 / ISA 收口之前）后，按 salt 确定性随机在段内逐指令应用上述规则；**相对跳转（JMP/JZ/JNZ）偏移经位置映射表重算**，确保插入指令后跳转仍正确指向。`JMP_B` 的 `jt` 回边表由扁平化/状态机 pass 于最终段重建，不受影响。强度越高命中概率越大（1≈低、2≈中、3≈高）。

- **Confrontation-LLM 反 LLM 静态分析（`--confrontation-llm`，强度 `--confrontation-intensity=N`，1~3）**：
  - 动机：现代逆向/审计大量依赖 LLM 对混淆产物做「语义理解 + 优先级排序」，而 LLM 倾向于信任文档注释。制造**误导性文本特征**可直接扰乱其优先级判断。
  - 实现：新增 `src/Confrontation/LlmConfrontation.php`，基于 `token_get_all` 在 token 边界（函数/类声明前、顶层 `;`（且不在 for/while/if 头内）/ `}` 边界、文件头 `<?php` 之后）注入**纯注释**，绝不切入字符串/heredoc/已有注释内部，对任意合法 PHP 安全可复现（随机分布由 salt 派生）。
  - 两类注入：① **误导性注释**——假 `@deprecated`（「自 v2.1 起不再使用，保留仅为向后兼容」）、`@see`（「`CoreCrypto::decryptPayload()` 替代实现」）、`@todo`、`@fixme`（「临时调试用，上线前需删除」），以及伪造 `/** CHANGELOG … */` 注释块声称某些「legacy 解密入口」已被移除（实际仍在执行）；② **生僻 CJK 散布**——随机生僻汉字作为 `//` 注释碎片散布到壳内层，制造「含中文业务注释」假象。
  - 注入点位于 `Obfuscator` 的 `Copyright::inject` 之后、自校验/毒化壳之前，伪造注释与生僻 CJK 一并纳入防篡改指纹；max/ultra 预设默认开启（ultra 强度拉满 + `vm_combo=3`）。

- **换行缠绕壳（结构指纹毒化壳配套，`--newline-poison`）**：
  - 动机：逆向 / 审计人员拿到混淆产物，常「下意识」整理一下——删空行、去换行、跑格式化把「没用的空白」清掉。本模块让产物遍布换行，且一部分换行**是数据本身**（嵌在 base64 载荷串里），诱导这类清理动作。
  - 数量：`目标 = max(原始行数 × ratio, min)`，默认 `ratio=0.1`（原始代码 1/10）、`min=2000`（保底）；`--newline-poison-min=N` / `--newline-poison-ratio=F` 可调。
  - 落点（`src/Encode/NewlinePoison.php`，`token_get_all` 驱动）：① **语句间空行**——在 `; } ) , ]` 等语句结束符后按 salt 确定性随机插入，分散全程、绝非堆在尾部；② **串内缠绕**——仅对**纯 base64 字符集且足够长（≥256）**的字面量做串内插入（`base64_decode` 默认忽略空白，插换行不改解码字节 → 运行行为不变）；短串（常量名 / 函数名 / 错误文案 / 正则）一律跳过，避免破坏 `constant('PHP_VERSION_ID')` 这类承载语义的串（早期版本曾因此把常量名拆断而报错）。
  - 与结构毒化壳的顺序（关键）：换行缠绕层排在 `shell:structpoison` **之前**。`mid`/`high`/独立模式下它落在所有编码壳之后，换行在最终文件中**可见**；`max`/`ultra` 含结构毒化壳时，换行随内层代码被 gz+base64 加密进密文（实测：任何在毒化壳指纹区内新增的换行都会使密钥错位、运行即 `fatal: cannot continue`，故必须前置）。
  - 实测：独立 `--newline-poison` 产物 ≈ 2039 换行、运行输出与原生逐字节一致；`--level=mid --newline-poison` ≈ 2004 换行同样一致；`--level=max --newline-poison` 换行被吸入密文、运行一致。

- **CLI / 预设 / 配置集成**：新增 `--vm-combo[=N]` / `--no-vm-combo`、`--confrontation-llm` / `--no-confrontation-llm` / `--confrontation-intensity=N`、`--newline-poison` / `--no-newline-poison` / `--newline-poison-min=N` / `--newline-poison-ratio=F`；`config.default.php` 与 `src/Config.php` 归一化补齐 `vm_combo`(0-3)、`confrontation_llm`(bool)、`confrontation_intensity`(1-3)、`newline_poison`(bool)、`newline_poison_min`、`newline_poison_ratio`；max/ultra 预设启用 confrontation（ultra 另开 `vm_combo=3`）。

### 11.14 v1.0.9：ThinkPHP8 级工程兼容 + 生成器/闭包真编译 + EVAL_STMT 兜底加固

- **FQCN 感知类改名（单文件模式 / 与 VM 类表协同）**：
  - 类改名按「命名空间全限定名」粒度进行，`namespace` 声明保持原样；声明点 `class_alias(新名, 原名)` 把原名回挂——`class_exists` / 反射 / 字符串类名实例化照常命中。
  - **目录级构建（整套系统模式）不再生成 classmap 自动加载器 `awaphp_autoload.php`**：类名改名会与 PSR-4「命名空间 + 文件名」约定冲突（改名后框架按原名找不到类），故工程模式强制保留类名原名、靠框架自动加载工作。需要排除个别符号时改用白名单（见 11.15）。
  - 单文件模式仍支持类名混淆（`--class-obf`，FQCN 感知 + `class_alias` 回挂）。
  - 同 salt 下各文件独立混淆、映射确定性一致（`Salt::derive` 派生），增量重建安全。
  - VM 运行期 `$___RCLS___`：FQN 查 class_exists / VM 类表命中则原样返回，失败降级短名再试（「命名空间内找不到就去全局找」语义）。
  - `parent::` **非虚派发**：`parent::m()` 沿声明类的父链解析（非 called class），与 PHP 语义一致；SCALL 抛错措辞与原生逐字对齐（`Class "X" not found`）。
- **生成器 / 闭包结构特性真编译（YIELD / GEN_HEAD + Fiber 生成器桥）**：
  - 生成器方法 / `yield` / `yield from` / 闭包生成器 / `send` 双向传值 / `getReturn()` / `try/finally` 中 yield / 无限生成器 + `break` 全部真编译，语义探针 8 类场景与原生逐一比对一致（`hardening_v109` [1]）。
  - 生成器的暂停/恢复经 **Fiber 生成器桥** 实现：VM 帧内 yield 语义由 Fiber 调度承载，`current/key/next/send/throw/getReturn` 行为保真。
  - 闭包 `use`（值捕获 / 引用捕获）按 PHP 语义修复；`f()()` 表达式结果直接调用、箭头函数、闭包生成器保真；trait `use` 方法体与适配块（`insteadof` / `as`）保真。
  - 帧栈纪律：`for` init/step 等「求值即丢弃」位置帧结束栈为空，隐式返回恒为 `null`（生成器 `getReturn()` 不会串味）。
- **解构表达式键（`emitListDestruct` 边界拓展）**：`KEY => TARGET` 的 KEY 支持**任意表达式**——字符串层把 `'usd'` 编码成 `(base64_decode(...)^str_repeat(...))` 括号表达式后，解构键不再「期望 T_VARIABLE」报错回退，单 token 字面量键走常量池、其余走表达式求值 + `INDEX_GET`（动态键）。
- **EVAL_STMT 兜底类引用 FQCN 化（`rewriteClassRefsForEval`）**：语句级兜底的 eval 文本运行在**全局命名空间**（eval 单元不继承调用方 namespace / use），类引用必须改写：
  - `new X` / `X::` / `instanceof X` / `extends X` / `implements X` / `catch(X` / 类型提示位 `X $var` / 联合类型位 `A|B` 的名字跨度统一解析后改写为 `\` + FQCN；`use` 别名展开、相对名补全；
  - **内置类型白名单**：`int / string / array / bool / … / self / static / parent` 不是类名，绝不做命名空间补全（否则 `int $n` 被补成 `app\demo\int` 直接 reserved 致命）；
  - 无控制逃逸 / 无 self·static·parent / 无本文件 VM 类引用的语句才允许兜底，否则整文件回退。
- **头部序言归一安全网（`DeclareHoist::normalizeTokens`）**：PHP 硬约束「`declare(strict_types=1)` 必须是首语句、`namespace X;` 必须紧随其后」。token 层注入器（动态垃圾 / 统计钩子 / 字符串密钥安装）若把语句错插进序言区，产物直接致命。两道防线：
  - **源头**：RuntimeJunk 语句边界过滤——`headInsertIndex`（序言结束点）之前的边界一律不插垃圾；
  - **出口**：token 流每次 emit 前归一——错位语句整体搬到序言之后（括号式 `namespace X {` 时搬进块内），幂等、对未来任何注入器失误兜底。
- **回归**：新增 `hardening_v109` 59 项（五等级特性保真含生成器方法/by-ref 写回/heredoc/`f()()`、生成器语义探针、帧栈纪律、壳层头部约束（anti_debug/anti_hook/anti_tamper 产物 declare/namespace 恒在最前）、换行缠绕配额、互斥注释内层性、字符串偏移/静态局部 isset/大值栈保真边界）全绿；`vm_eval_stmt` 新增解构表达式键与 EVAL_STMT 类引用 FQCN 化用例（13 例全绿）；`run_tests` 32、`hardening_v108` 37、`vm_gen_probe` 290、`vm_trycatch_probe` 64、`vm_p8` 77、`vm_junk` 120、`vm_comprehensive` 全绿、`vm_module_link` / `vm_builtin_modules` 205 全绿、`syntax_compat` 7 全绿；TP8 示例项目基线 26/26 逐字节一致。



### 11.15 v1.1.0：白名单模式 + 深度反AST + 换行量 1/2 + PHP 8.5 + 哈希内置模块

> 已发布为正式版：tag `1.1.0` + GitHub Release + `origin` 推送。

- **`low` / `mid` 预设不再包含 VM**：VM 把每条语句交给解释器执行，是唯一「与执行语句数成正比」的运行时开销。低中档定位为性能优先，现只保留 token 级变换 + 一次性解码的编码壳（`low` = token 级 + GZ 壳；`mid` = token 级全开 + EVAL 3 层 + 反调试 + GZ 壳）。VM 自 `high` 起启用。各档实测开销见 `docs/PERFORMANCE.md`（`low` ≈1.17×、`mid` ≈1.49×、`high`/`max` 千倍级）。

- **白名单模式（替代 classmap 自动加载器）**：
  - 废弃 `awaphp_autoload.php` 自动加载方案。**目录级构建（整套系统模式）强制保留类名原名**——类名混淆与 PSR-4「命名空间 + 文件名」约定根本冲突（改名后框架按原名找不到类），去掉自动加载器后改为工程模式不重命名类，框架自动加载照常工作。
  - 白名单是一个配置项（`whitelist`），支持排除：
    - **目录 / 文件**：整目录（`--whitelist-dirs=app/Admin/*`）或整文件（`--whitelist-files=config/app.php`）**原样拷贝**，不参与混淆；
    - **符号**：变量（`--whitelist-vars=\$db`）、函数（`--whitelist-funcs=helper`）、类 / 接口 / 特质 / 命名空间（`--whitelist-classes=App\Service\Order`）、常量、方法——跨文件保持原名。
  - 单文件模式仍可用 `--class-obf` 做类名混淆（FQCN 感知 + `class_alias` 回挂）。

- **深度反AST（anti_ast，新增 token 层）**：
  - 把语句位的 `echo` / `print` / `return` **常量字面量**改写成「累加器 + 恒等表达式」，源码里再见不到裸数字 / 裸串。积木来自恒等不变量：`strlen(md5(1))===32`、`strlen(sha1(1))===40`、`ord('A')===65`、定长串长度、`base64_decode(base64_encode(x))===x`；整数由积木加减组合，同一作用域内复用同一累加变量制造「变量被反复改写」的假象。
  - 示例：`echo 1; echo 123;` → `$_z=strlen('x'); echo $_z; $_z=$_z+(strlen(sha1(1))*3+strlen('xx')); echo $_z;`（1 与 1+120+2=123）。
  - 改写前后语义**逐字节等价**：每条生成式构建期经 `eval` 校验，不通过则整条原样保留（绝不引入错误）。默认关，需 `--anti-ast` 或分层 JSON 的 `anti_ast` 层显式开启。

- **换行缠绕量改为源代码字节的 1/2**：`--newline-poison-divisor` 默认由 5 改为 **2**，即源 10KB → 注入约 5000 个换行（原为 1/5）。换行均匀撒入 token 边界 + 部分嵌入 base64 载荷 + 头部兜底，配合结构毒化壳 / 自校验，逆向者「删空白 / 美化」即改动密文与指纹 → 解密错位。

- **哈希内置模块（VM `builtin` 层）**：`md5` / `sha1` / `crc32` 的**纯 PHP 自包含实现**作为标准 VM 模块（`src/Module/builtin/md5.{php,json}` 等）提供，开启 `builtinReplace` 时把混淆体内的原生哈希调用链到自制实现并内联；与原生逐字节一致（含 5KB 串验证）。此前独立的 `HashBuiltin` 类已删除——自制原语的正确形态是模块。

- **PHP 8.5 支持**：运行环境升级到 PHP 8.5（ppa:ondrej/php），混淆器在 8.5 运行时下对现代语法（enum、readonly 类、构造器属性提升、接口 / trait、`match`、一等调用 `strlen(...)`、属性钩子等）的 token 流健壮处理、混淆产物可运行。

- **调试钩子集中到 `docs/DEBUG.md`**：`OBF_VM_SRC` / `OBF_CPV_OUT` / `OBF_VM_NEST` / `OBF_SHELL_DUMP` / `OBF_LINT_DUMP` / `OBF_VM_DEBUG` 六个环境变量的位置、内容、典型排查流程统一归档，便于 CI / 本地定位「某层为何没生效 / 为何回退 / 为何非法」。

### 11.16 v1.1.1：类保留 VM 注入修复（整站空正文）+ 解释器提速 2.5× + 两条专用指令

> 这一版把「ThinkPHP8 五档全部跑得起来」和「VM 快一截」同时做到。

- **【严重修复】`ClassPreservingVm` 的注入正则漏了 argv 播种块 → 类里每个方法都 `return null`**。
  该类需要在生成的运行期里定位解释器变量名（`$___RUN___` / `$___P___` / `$___PAD___` 都会被
  重命名成生僻 CJK 名），做法是用正则从主调用行提取。正则只认
  `$__vmv=[];$RUN($P['main'],…)`；而运行期在 CLI / 目录模式下会在中间插入
  `if(array_key_exists('argv',$GLOBALS)){…}` 播种超全局。命中后者时提取失败，整段
  `$GLOBALS['__awa_run_<uid>']=…;$GLOBALS['__awa_prog_<uid>']=…` **被静默跳过**，顶层桩的委托
  函数一进函数体就 `if($run===null||$prog===null) return null` —— 控制器返回空串、接口回空对象。
  框架侧表现为「响应里只剩调试 trace 的 CSS、正文空白」，**全程无异常、无警告**，极难定位。
  现提取式与替换式同构，argv 块作为可选组。
- **【严重修复】`var` 层把链式访问末段当成本类属性改名**。`$this->app->request` 的 `request`
  属于 `think\App`（容器绑定名）；旧实现只看前一个 token 是 `->` 就改写，产物变成
  `$this->app->_gfc92adb3` → `Container::__get()` → 容器拿随机串当类名解析 →
  `ClassNotFoundException` → `controller not exists`。现仅当对象表达式是**裸变量**才改名。
- **【严重修复】`anti_debug` 的 `trace` 探针用裸 `debug` 子串判定**，ThinkPHP 的
  `think\debug\*`、`think\trace\TraceDebug` 全部命中，`max`/`ultra` 档在 TP8 上每请求 `exit(1)`。
  现只认 `xdebug_*` / `phpdbg_*` / `Xdebug\*` / `Blackfire\*` / `Tideways` / `xhprof` / `uopz` / `runkit`。
- **操作数栈编码类型化**（性能）：旧实现每压栈必 `serialize()` + `str_repeat` 现算 XOR、出栈
  `unserialize()`，占 VM 总耗时约 **75%**。现按类型分流（int 单次整型 XOR / string 单次串 XOR /
  float·bool·null 直存 / array 才 serialize / 含闭包等不可序列化值落明文槽），解码内联进
  `MCPOP`/`MCPEEK`（省一次闭包调用 ≈26 ns/指令）。保留旧的「栈下溢返回 null」宽容语义
  （`if($F===null) return null;`），否则下溢时对 null 做 `^` 会 TypeError。
- **两条早已定义却从未落地的操作码终于实现**：
  - `DISPATCH rel,var,state`：状态机派发序章 `PUSH_V st; PUSH_C s; EQ; JZ 1; JMP`（5 条）→ 1 条；
  - `SET_ST var,state`：状态写入 `PUSH_C + POP_V`（2 条）→ 1 条。
  相对跳转登记同步补进 `VmJunk`（垃圾指令插入后的位置映射 / 回写）、`VmCombo::isRelJump`、
  `RuntimeGenerator::isBranch`（两处）。
- **`$__vmcb` 命名空间兜底**：VM 产物整体跑在全局命名空间、`funcs` 表按**短名**索引；而
  `call_user_func('App\Util\foo')` 这类绝对名写法很常见（调用编码器也会为命名空间文件补全 FQN）。
  现按「绝对名未命中 → 退到短名」兜底，同一份字符串在 VM 内与原生下都能解析。
- **实测**：同一份轻负载 `high` 10,244 → 5,676 ms、`max` 14,476 → 8,120 ms；20 万次 while
  循环 `high` 5,655 → 2,198 ms（**2.6×**）、`max` 6,769 → 2,678 ms（**2.5×**）。
- **回归**：TP8 示例五档 × 11 端点全部逐字节等价（`low` 228 KB / `mid` 412 KB / `high` 2.1 MB /
  `max` 3.3 MB / `ultra` 6.9 MB）；`run_tests` 32、`hardening_test` 32、`v105` 14、`v106` 29、
  `v107` 23、`v108` 37、`v109` 59、`syntax_compat` 7、`token_obf` 32 全绿。
