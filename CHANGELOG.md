# 更新日志（CHANGELOG）

## v1.1.9 — 模块链接下沉到 low+ 档；坏 salt 根因（裸 `!0`/`!1`）修复

> 主题：两件事。① **模块链接不再专属 VM**——新增原生路径，`low` / `mid` 等非 VM 档位
> 也能把内置模块内联进产物（调用点改写 + 模块原生定义追加），`low` 及以上档位全覆盖；
> ② 系统性排查「坏 salt」成因，定位并修掉**第三类**（前两类在 v1.1.8 已修）：壳层自保护
> 把 `true` / `false` 改写成**裸 `!0` / `!1` 表达式**，二次 token 化 + 压缩去空白后与相邻
> 运算符重新结合，特定 salt 下破坏产物语义。

### 新增 / 增强

- **模块链接原生路径（`shell:module_link`，非 VM 档位可用）**
  - 此前模块链接（ModuleLinker，P6）只服务于 VM 字节码：`RuntimeGenerator::buildArtifact()`
    内 `linkModules()` 把命中模块编译进 Program + `inlineSource()` 原生兜底；非 VM 档位
    完全没有这条能力。
  - v1.1.9 新增 `Obfuscator::shellModuleLink()`：`effectiveModuleDir()` 解析目录 →
    `ModuleLinker::loadDir()` → `matchSource()` 取种子 → **传递式** `inlineSource()` 收集
    （递归内联模块间的引用）→ 生成 rename 表（命中名 → 清单 `target`）→
    `rewriteCallTargets()` **仅函数调用位**改写调用点（严格对齐 `matchSource` 的
    prevSig 排除规则：`->` / `::` / `new` / `function` / `use` 等位置不改写）→
    拼接模块源码追加到文件末尾（利用 PHP 函数声明提升，调用点在前也能正确解析）。
  - 登记为 SHELL 最内层（`LayerRegistry::SHELL` 中位于 `shell:anti_debug` / `shell:anti_hook`
    之后、`shell:vm` 之前），让内联定义与改写后的调用点被后续 eval/gz 壳一并加密；
    `LayerDescriptor` 补充该层 schema（`dir` 默认 `''` → 内置模块目录）。
  - **`low` / `mid` 预设在 `ws` 之后、`shell:eval` 之前新增 `shell:module_link` 层**；
    `high` / `max` / `ultra` 继续走 `shell:vm` 的字节码内联。至此 **low 及以上所有档位
    均启用模块链接**。
  - 分层模式（预设档位）与散装键模式分离：散装键构建仅在显式指定 `--vm-linker=<dir>`
    时启用原生链接，避免改变 `--newline-poison` 等独立构建的默认产物形态。
  - `tests/vm_module_link_native.php`（35 项）：low/mid 运行等价、明文分层检视调用点改写
    + 内联定义、传递式内联（`substr_replace` → `__mod_substr`）、`vm_link_modules=false`
    关闭行为、max/ultra（VM 路径）不受影响，全绿。

### 修复

- **坏 salt `[zz]`：`high` 档 `complex_app` 丢失首条记录（User Scores 只剩 Bob）**
  - 现象：诊断扫描（6 夹具 × 5 档 × 60 salt）捕获 `salt=[zz]` + `high` 档：
    原生输出 `1. Alice (#1) avg=84 / 2. Bob (#2) avg=66`，产物只输出 `1. Bob (#2) avg=66`
    —— Alice 整条消失、顺序错乱，其余段落（fib / isPrime / factorial / primes / bitmask）
    全部正确。且**只有精确字符串 `zz` 触发**（`Zz` / `a` / `42` 均正常）。
  - 排查路径（逐层二分）：`mid` 正常 → 根因不在 token 级状态机；移除 `shell:vm` 恢复正常
    → 锁定 VM 相关；但关闭全部 VM 子特性（stateMachine / morph / junk / fakeFog / probe /
    keychain / builtinReplace / cpool / symbols / jit / combo / inloop / chunks）仍复现；
    **裸 VM 产物（不经 token 层）正常** → 定位到「VM 产物 + 后置壳」的组合；
    逐个剥离后置壳无效，仅**关闭 `shell_shield`** 修复 →
    `ShellProtector::protect()` → 四步变换里只有 `TokenObfuscator` 破坏语义 →
    `token_literal` → `true/false → !0/!1`（`array()→[]` 无害）→
    **渐进二分（只改写前 K 条）定位到第 2 条 `ReTuRn true;` → `ReTuRn !0;`**。
  - 根因：`!0` / `!1` 是**表达式而非原子字面量**；壳层自保护会对整段 VM 产物反复
    token 化并压缩去空白，裸 `!` 会与相邻运算符重新结合（`!` 优先级低于 `**` /
    `instanceof`，压缩粘连后结合歧义更大），特定 salt 下改变表达式结构 → 语义被破坏。
    这是继 v1.1.8「状态机行注释吞代码」「注入层变量名撞名」之后的**第三类坏 salt**。
  - 修复：`TokenObfuscator::literalPass()` 把改写结果整体**加括号** → `(!0)` / `(!1)`，
    固定表达式边界，与 `true` / `false` **100% 等价**，仍保留形态多样性。
  - 回归：`tests/salt_regression.php`（22 项，历史坏 salt `zz` / `bs47` × 全档位、
    8 种形态 salt × high、坏 salt × 4 夹具）全绿；`tests/token_obf.php` 2b 段升级为
    同时断言「带括号」与「无裸 `!0`/`!1`」；`tests/run_tests.php` 固定加入
    `S4 坏 salt [zz] / high 档运行等价` 用例。全套 12 个测试文件 0 失败。

- **内置模块 `strlen.php` 在非 VM 路径下死循环**
  - 现象：原生路径启用后，`low` 档产物运行时刷几十万行 `Uninitialized string offset` 警告并挂死。
  - 根因：模块用 `while ($__s[$__i] !== null)` 判字符串边界，依赖 VM「越界返回 `null`」
    语义；原生 PHP 越界返回 `''`，`'' !== null` 恒真 → 死循环。
  - 修复：改为 `while (isset($__s[$__i]))`（与 `substr.php` 一致），VM / 原生双安全且不产生告警。
    精确核查 8 个含 `!== null` 的模块，仅此一处是真「字符串下标越界判定」，其余 7 个
    都是参数判空（原生安全）。
  - 验证：`vm_module_link.php` + `vm_builtin_modules.php`（205 用例）VM 路径不受影响，
    明文 / low 档运行与原生一致。

- **散装键模式默认行为回退（回归预防）**
  - 新增原生链接分支时曾以 `vm_link_modules`（默认 `true`）作散装键路径的触发条件，
    导致 `--newline-poison` 等独立构建默认内联模块，产物可见换行 701 → 329、
    `hardening_v108` 换行配额断言失败。现改为**仅在显式 `--vm-linker=<dir>` 时启用**，
    默认产物形态与 v1.1.8 一致（`hardening_v108` 37/37 恢复全绿）。

### 回归

全套 12 个测试文件 0 失败：`run_tests` 33、`token_obf` 33、`salt_regression` 22、
`vm_module_link_native` 35、`vm_module_link`、`vm_builtin_modules` 205、`vm_smoke`、
`vm_comprehensive`、`statemachine_native` 60、`hardening_v108` 37、`preset_e2e` 72、
`syntax_compat` 7。

## v1.1.8 — VM 符号名密文：静态提取密钥也读不到符号表

> 主题：堵上 VM 内混淆的最后一块明显短板——**符号表明文**。此前字节码容器虽被分片
> 加密，但容器内的类名 / 方法名 / 函数名 / 变量名 / 属性名 / 常量名**全是明文**：
> 攻击者从产物里提取出分片密钥（明文存在）即可解开容器，`strings` 直接读出
> 完整符号表——业务结构、命名风格、类与方法关系一览无余。

### 新增 / 增强

- **VM 符号名密文（默认开，`--no-vm-symbols` 关闭）**
  - 覆盖程序容器内的**全部字符串**：类名 / 方法名 / 函数名 / 变量名 / 属性名 /
    常量名 / 异常表 kind-type-varName / 指令操作数里的符号串 / 源文件基目录。
  - 机制：`symEncode()` = `base64(符号 XOR keystream(symKey))`，与 `natNameEncode`
    同构但**密钥完全独立**（`vm-symkey` vs `vm-natkey` vs `vm-cpool` vs `vm-chunk-*`）。
  - 运行期由 `$___val___` 读取器**解密还原**（解包即还原，语义零影响）；
    另保留原版 `$___rval___` 专供 `natTbl` 使用——`natTbl` 自带
    `arch_builtin_replace` 的名编码、在 `CALLFN` 内单条解码，若整表还原会让
    程序内存里重新出现明文原生函数名，抵消该特性的意义。
  - **密钥按源文件内容派生**（`vm-symkey:<sha1(src)[0:16]>`）：不跨文件复用——
    攻击者从 A 产物提取的符号密钥解不开 B 产物，批量解密整站产物的做法失效。
  - 效果（实测）：含函数 / 形参 / 局部变量 / 全局变量 / 常量的夹具，产物中
    13 个代表性符号名计数**全为 0**；打包层二进制容器内亦无任何明文符号。
  - 常量池（cpool）开关两条路径均对称：cpool 开时字面量在池内加密（池另有整体
    计数器流加密，两层独立密钥）；cpool 关时字面量内联指令流，走同一条加密路径。

- **`tests/vm_symbols.php`（28 项）**：符号密文 + 容器格式随机化专项回归——
  ① 打包层二进制容器无明文符号（11 类符号逐一断言）；② 关闭开关后退化为旧行为；
  ③ 端到端 3 个 salt × 4 种开关组合（默认 / 关符号 / 关常量池 / 双关）输出与原生
  逐字节一致；④ 产物明文检查；⑤ 类文件（真实类桩 + VM 方法体）一致且局部变量名
  不明文出现；⑥ 容器格式随机化——映射为双射、不同 salt / 不同源文件 → 不同容器格式、
  同 salt 重复构建 → 映射一致（可复现构建不受影响）。

- **`tests/vm_junk_collision.php`（12 项）**：状态机行注释 + 注入层变量名撞名专项回归——
  ① **已知坏 salt `bs47`**（修复前必现零输出 / Parse error）在 `max` / `ultra` 档输出一致；
  ② 尾随行注释场景经状态机改写后 `php -l` 通过且输出一致；③ `NameStyle` 保留名避让生效
  且不破坏确定性；④ 8 个 salt × 2 档扫描，全部语法正确且输出一致。

### 增强（VM 内混淆）

- **容器格式随机化（默认开启，无需开关）**：二进制容器的**类型标记字节**原本是固定序列
  （`TAG_NULL=0` / `TAG_INT=1` / `TAG_STR=2` / `TAG_BOOL=3` / `TAG_FLT=4` / `TAG_SEG=5`）——
  这正是容器格式的**固定指纹**：通用解容器脚本按这组常量即可直接套用。现把 6 个语义标记
  映射到**每次构建都不同**的随机字节（按源文件内容派生），打包侧 `tagOf()` 与解包侧
  `$___TM___` 映射表严格互逆，语义完全不变，但容器格式逐构建不同。
  实测：salt=a → `[64,192,140,102,9,149]`、salt=b → `[41,32,120,27,176,71]`、
  salt=c → `[85,62,163,204,220,152]`；**同 salt 重复构建完全一致**（可复现构建不受影响）。
  未知字节按旧版行为退化为 STR 处理，与 v1.1.7 的 default 分支语义一致。

- **符号密文与常量池加密形成双层**：字面量（业务文案、密钥串）与符号名（业务结构）
  分别使用两把互不派生的密钥，单点提取其一无法还原另一层。

### 修复

- **状态机行注释吞代码 → `PHP Parse error: Unclosed '{'`（产物完全不可运行）**
  - 现象：特定 salt 下，产物直接 Parse error（`Unclosed '{'`），整份文件跑不起来。
  - 根因：`StateMachine` 把顶层语句切分并重组进分发器，而 `StatementSplitter::splitRanges()`
    会把**尾随注释切成独立区间**——注释因此可能落到某个 case 体**开头**，其后紧跟状态机
    生成的转移代码（**同一行**），行注释一路吞掉后续代码 → 花括号不配对。
  - 修复：`StateMachine::process()` 在切分前把行注释统一**块化**（`// …` → 块注释形式），
    与 `Optimize\Whitespace::normalizeLineComments()` 同一思路；块注释不会吞掉后续代码。
  - 附带修掉「注释落在 case 体中间」的错位（注释本属上一条语句的尾部）。

- **注入层变量名撞名 → 产物零输出且无任何报错（最隐蔽的一类）**
  - 现象：特定 salt + 特定档位组合下，产物**运行没有任何输出、也不报任何错**。
  - 根因：拟真变量名来自固定词池（`NameStyle` 的 HEADS × TAILS），而**状态机层 / 死代码层 /
    诱饵层共用同一词池**。状态机先生成状态变量（如 `$connCursor`），运行时垃圾层随后插入
    **同名**垃圾变量并赋值 → **覆盖状态变量初值** → 分派器 `switch(0)` 落到 `default`
    直接退出 → 零输出。
  - 修复：`NameStyle` 新增**保留名机制**（`reserve()` + `varName()` 自动避让，换确定性变体
    重试）；能看到全代码的注入层（`GeneralJunk`）在入口登记已用变量名。
    保留表为**实例级**（非静态），多文件工程构建时不跨文件累积，可复现性不受影响。
  - 实测：修复前 150 个 salt 中有 2 个坏 salt（strings 夹具 + max 档），修复后 **150/150 全通过**。

- **无 salt 时改用随机盐兜底，消除未测试的随机分支**
  - 全系统约 20 处以 `salt->isEmpty()` 分流：有盐走**确定性派生**，无盐走 `random_bytes`
    兜底。那批 random 分支长期缺乏覆盖，是偶发失败的温床。
  - 修复：`Salt::random()` + `Obfuscator` / `ProjectBuilder` 在盐为空时自动改用随机盐——
    未指定盐时依然「每次构建产物不同」（随机盐不同 → 派生不同），但走的全是与指定盐时
    **完全相同**的派生路径。

- **测试断言加固**：`preset_e2e` 的「单开标签 / 无闭标签」与 `hardening_v109` 的「头部语句顺序」
  改为**大小写不敏感**——PHP 语法上 `<?php` / `<?PHP` 等价，而 token 形态层会把开标签
  随机成大写，用大小写敏感判定会误报。

### 文档

- `README.md`（版本徽章 + v1.1.8 要点 + VM 段符号密文说明）、`CHANGELOG.md`（本节）、
  `docs/CLI_OPTIONS.md`（新增 `--no-vm-symbols`）、`docs/VM_FEATURES.md`（§11.22）、
  `docs/PERFORMANCE.md`（符号密文开销 + 随机盐兜底说明）。
- 版本号：`entry.php` 升至 `1.1.8`。

---

## v1.1.7 — VM 兼容加强（闭包/箭头/match/yield）+ 反 LLM 互斥注释增强

> 主题：把「类文件里那 4 类语法没真正进 VM」的兼容缺口补齐，同时让反 LLM 的互斥注释
> **更近、更多、更矛盾**，并顺手修掉几处只有跑产物才暴露的 bug。

### 新增 / 兼容加强

- **类文件中的闭包、箭头函数、`match`、`yield` 真正进入 VM 字节码**
  - 此前顶层脚本里的这 4 类构造早已能编译进 VM，但**含类声明的文件**会走「类保留 VM」
    （`ClassPreservingVm`）路径，一旦编译期抛错就整份降级为 `gz+base64` 不透明载荷
    （`vmPayloadNest`）——方法体虽被包进密文，却**不是 VM 字节码保护**，只是 `_PHPRUN` 原生执行。
  - `src/Vm/Parts/VmExprTrait.php` 的 `parseNew()` 原先只接受单个 `T_STRING` / `static`，
    遇到 `new \app\controller\Shop()` 里的 `T_NS_SEPARATOR` 直接抛「new 仅支持类名」，
    导致真实命名空间类文件整体降级。现循环消费 `T_STRING` / `T_STATIC` / `T_NS_SEPARATOR`
    收集完整类名，再走 `resolveClassRef()` 展开 `use` 别名与当前命名空间。
  - 验证：综合类夹具在 `high` / `max` / `ultra` 均输出 `ok:75`；产物中 `function (int`、
    `fn(int`、`yield 1`、`match (`、`private function` 等源码明文 marker 计数全为 0。

- **VM 运行期真实 private / protected 方法调用修复**
  - 症状：`$this->privateMethod()` 抛 `TypeError: call_user_func_array(): Argument #1
    ($callback) must be a valid callback, cannot access private method ...::gen()`。
  - 根因不在 `SCALL`，而在 `MECALL` 委托的 `$___CALLMETHOD___` 真实对象分支——
    `call_user_func_array([$obj,$m],$args)` 在外部作用域无法访问私有/受保护方法。
  - `src/Vm/RuntimeGenerator.php`：`CALLMETHOD` 真实方法分支改用
    `ReflectionMethod` + `setAccessible(true)`（并区分静态/实例）；`SCALL` / `SCALL_VAR`
    的真实方法 fallback 同步统一为反射调用，杜绝同类问题。

### 增强（反 LLM）

- **`src/Confrontation/MutualComments.php`：互斥注释更近、更多、更矛盾**
  - 主题库 **12 → 22 组**，新增多条 3 向 / 4 向成簇断言（如「废弃桩 / 活核心 / 三条都不可信」、
    「审计指令蜜罐 4 向」、「密钥位置三态互斥」），认知污染不再只是「两两打架」。
  - 同簇伙伴距离由 `j1 + nextInt(1,40)` 收紧到 `step = nextInt(1,3)` 个 token 边界，
    N 条断言并排落在几行内，阅读者会同时看到整组矛盾。
  - 每层壳投放量 `1/2/4 对 → 3/6/12 簇`（`Obfuscator::mutualTick()` 的
    `[1 => 3, 2 => 6, 3 => 12]`），投放上限 `bn/12 → sn/4`；12 簇样本约 28 条注释。
  - **安全边界白名单 `isFusionRisk()`**：注释只允许投放到「前一个有效 token 以结构性标点 /
    非词素结尾」的边界。理由是多层壳会反复转写注释（行注释 → 块注释 → 压缩去空白 →
    字符串装配剥离），`use X AS/*c*/Boom` 一旦注释被剥离就粘成 `ASBoom`。
    宁可少插、不可插错——从注入点层面杜绝 `ASBoom` / `namespaceapp` 这类致命融合。

### 调整

- **`low` 预设移除 `statemachine` 层**（`src/config.presets.php`）：low 定位是「能日常上线、
  运行时成本仅加载时一次解码」的入门档，不再叠加语句级状态跳转。`mid` / `max` / `ultra`
  仍保留状态机；`high` 与 `min` 本就未接入。

### 修复

- **`StateMachine::isTopLevelSafe()` 漏判 `T_USE`（一处根因、三个失败）**
  - 症状：`hardening_v109` 中 `level=mid` 报 `unexpected token "use", expecting "case"...`，
    `level=max` / `ultra` 报 `ReflectionException: Class "app\demo\Boom" does not exist`。
  - 根因：顶层 `use RuntimeException as Boom;` 被当成普通语句搬进 `switch` case（PHP 不允许），
    同时它不再是顶层语句，VM 编译期 `prescanNamespaceContext()` 扫不到别名，
    `new Boom()` 被按当前命名空间补全成 `app\demo\Boom`，运行期 `ReflectionClass` 找不到类。
  - 修复：把 `T_USE` 列入屏障 token，import 语句原样保留在 `namespace` 之后、不进分发器。
  - 效果：`hardening_v109` 由 **56 通过 / 3 失败 → 59 通过 / 0 失败**，三档输出均与原生逐字节一致。

- **发布前清理**：`RuntimeGenerator.php` 中临时诊断用的 `DBG SCALL` 日志已全部移除（计数 0）。

### 回归

- 全量 10 套零失败：`run_tests`(32) · `preset_e2e`(72) · `vm_selfcheck`(12) ·
  `statemachine_native`(60) · `antidebug_probe_check`(39) · `hardening_v105`(14) ·
  `hardening_v106`(29) · `hardening_v107`(23) · `hardening_v108`(37) · `hardening_v109`(59)。
- 专项复测：9 个 salt × 2 种注释密度（`--anti-debug` 与 `--mutual-intensity=3`）共 18 组，
  `php -l` 全通过、输出全为 `sum=3;boom`，粘连回归 `fails=0`。

### 文档

- `README.md`（版本徽章 + v1.1.7 特性 + `low` 档说明）、`CHANGELOG.md`（本节）、
  `docs/CLI_OPTIONS.md`（补齐 `--mutual-comments` / `--mutual-intensity` 条目）、
  `docs/VM_FEATURES.md`（§11.21）、`docs/PERFORMANCE.md`（v1.1.7 开销说明）。
- 版本号：`entry.php` 升至 `1.1.7`。

---

## v1.1.6 — 状态机脱离 VM + 反调试探针语法自检

> 主题：把「状态机」从 VM 字节码层**拆出来变成通用原生层**，让 mid 档（无 VM）也能用上
> 同款强度的控制流平坦化；顺带修掉一类**构建期静默、只有跑产物才炸**的隐患。

### 新增

- **`src/ControlFlow/StateMachine.php`：原生状态机层（`statemachine`）**
  - 从 `shell:vm` 的字节码级状态机（`RuntimeGenerator::stateMachineProgram`）拆出，
    与 VM **完全解耦**——不依赖 VM 壳即可生效。
  - 两项 VM 同款加固：① **不透明谓词**转移 `if(($o^$o)===0){$s=$next;}else{$s=$trap;}`
    （谓词恒真无副作用，else 是指向自锁诱饵的死路）；② **诱饵死状态**（不可达标签，
    强行跳入即 `$s=$trap` 自锁死循环）。
  - **覆盖函数体 + 顶层语句段**：此前只处理函数体，纯脚本（无函数定义）等于完全空转。
    现新增顶层语句段改写——把连续 ≥2 条可安全包裹的顶层语句纳入分发器。
  - 随机化：状态变量名、不透明变量名、循环头形态（`while(1)`/`while(true)`/`for(;;)`）、
    分派形态（`switch` / `if-elseif` 链）、状态 ID（非连续随机）、标签展示（十进制/十六进制）、
    状态转移顺序、诱饵插入位置 —— 静态模板批量识别失效。
  - 变量名**避碰**：状态变量名与用户代码已用变量名冲突时自动换名（顶层作用域尤其重要，
    否则会覆盖用户数据）。
  - 顶层作用域引入的状态变量在分发器结束后立即 `unset`，不污染全局。

- **`StatementSplitter::splitRanges()`**：token 区间切分接口（保留空白与注释，
  区间连续覆盖整个数组），供状态机层在 token 流中原位替换语句段。

- **反调试探针语法自检（构建期）**
  - 探针组是**手写拼接的 PHP 源码**，一个括号错位就会让整份产物 Parse error，
    而构建期**零告警**、只有跑产物才暴露（本版开发中真实踩到：`in_array(...,true)`
    少写一个右括号 → 六档产物全部生成成功、全部一运行就 Parse error）。
  - 现每个探针组在构建期独立做一次 `php -l` 自检，出错的组**整组剔除**并明确告警，
    其余组照常生效——把损失限制在出错的那一组，产物可运行性优先于探针覆盖度。

- **`tests/statemachine_native.php`（60 项）**：原生状态机层回归——六档预设输出一致性、
  隔离构建下的明文结构断言（分发循环头 / 不透明谓词 / 诱饵死状态）、非 VM 产物断言。
- **`tests/antidebug_probe_check.php`（39 项）**：探针语法自检回归——全部探针组语法有效、
  人为注入坏探针 → 该组被剔除且产物仍可运行、探针组白名单与 `LayerDescriptor` 登记一致。

### 增强

- **六档预设全部接入状态机层**：`low`/`mid`/`high`/`max`/`ultra` 按档位递增强度
  （complexity 6→12、passes 1→3、traps 1→4）；`min` 档保持极简。
- **反调试探针面扩充**：`ini` 组新增 `auto_prepend_file` / `auto_append_file`
  （脱壳时挂解壳器的常用入口）与 `opcache.jit`；`proc` 组（`TracerPid` + 注入痕迹）
  接入 mid 及以上档位。
- **CLI 开关**：`--sm-complexity=N`、`--sm-passes=N`、`--sm-traps=N`、`--no-sm-opaque`。

### 修复

- **`shell` 探针与绑定标记的耦合**：`shell` 组校验的是「外壳绑定标记」，而该标记**只在
  VM 自检开启时才注入产物**。用户显式 `--ad-probes=shell` 但未开 VM 时，守卫永远判定
  「脱壳」→ **产物一运行就 exit(1) 误杀**。现两条管线（legacy 单管线 / 分层管线）
  均在标记不存在时自动剔除该组并告警。
- **`splitRanges()` 的分号归属**：`$f = function(){};` 这类语句，其闭合 `}` 被当成语句
  终结符，把结尾分号切到了下一区间——调用方保留前半段时会丢掉分号 → 产物 Parse error。
  现 `}` 后紧跟 `;` 时以分号为终结符，两者归入同一区间。
- **状态机 sentinel 与 trap label 碰撞**：sentinel 原先在 trap 生成前算出，可能与
  trap label 撞号 → 流程跳进诱饵状态自锁死循环。现生成 trap 时显式排除 sentinel。

### 文档

- `README.md`（版本徽章 + 状态机层说明 + 反调试探针面）、`CHANGELOG.md`（本节）、
  `docs/CLI_OPTIONS.md`（`--sm-*` 开关 + 探针组说明）、
  `docs/VM_FEATURES.md`（§11.20 原生状态机层）、`docs/PERFORMANCE.md`（状态机开销）。
- 版本号：`entry.php` 升至 `1.1.6`。

---

## v1.1.5 — 外壳反调试加强 + VM 高级环境自检（脱壳独立执行检测）

> 主题：**给"被抠出来单独运行的 VM 内层"上一道单向锁**。v1.1.4 的 in-loop 探针解决的是
> 「运行中被附加调试器」；本版解决「攻击者把 runtime 闭包 + 字节码整段抠走、丢进自己的
> 脚手架里独立跑」。做法不是加 if-else 去 exit（那只是多一个可 patch 的点），而是让
> **内层代码自己无法证明"我运行在完整混淆文件里"**——一旦证明失败，opcode 解码被污染，
> 指令流静默错乱，没有任何提示、没有任何出口可下断点。

### 新增 · 外壳绑定标记（`src/ShellBinding.php`）

- **原理**：混淆器在最外层（一切编码壳之外）`define()` 一个盐派生的全局常量；
  该常量名 = `_` + 11 位盐派生 hex，值 = 32 位盐派生 hex。VM 解释器初始化时校验它——
  完整文件运行时它一定先就位；一旦把 VM 内层抠出独立运行，或把 define 整行删掉，
  常量消失即判定「已脱壳 / 独立执行」。
- **单向摘要（关键设计）**：运行期没有 salt，期望值只能固化进产物。若把**原始 token**
  写进内层校验表达式，攻击者抠出内层即可读到期望值、自行 `define()` 伪造，整个自检归零。
  故改为：外层 `define(NAME, TOKEN)` 是**唯一**持有原文处，内层只持
  `DIGEST = sha256(TOKEN)`，校验 `hash_equals(DIGEST, hash('sha256', (string)constant(NAME)))`。
  攻击者只拿到摘要，无法构造原像 → 判定无法绕过。原始 token 在整份产物中只出现一次。
- **注入位置（三条约束同时满足）**：紧随 `shell:vm` 层之后注入——
  ① 在 VM 产物**之外**（落在其 `<?php` 之后、VM 闭包之前，运行期先 define 再跑 VM）；
  ② 在后续 eval/gz 载荷**之内**（随后被编码壳裹进密文，明文文件里看不到）；
  ③ 在 `shell:anti_tamper` 应用**之前**（纳入其基线哈希，改一字节即触发自校验）。
- **序言安全**：注入点经 `DeclareHoist::split()` 定位，`declare(strict_types=1)` /
  `namespace` 恒留在最前，不会出现 "strict_types declaration must be the very first statement"。

### 新增 · VM 高级环境自检（`vm_selfcheck`，默认开）

- **命中反制：污染而非终止**。自检失败时写 `$___PZ0___ = <盐派生污染因子>`，该因子在每轮
  取指时与 v1.1.4 的 `$___PZ___` 一并异或进 opcode 解码
  （`OP = (INS[0]^SCRAMBLE)^(PZ^PZ0)`）→ 跳转表查不到 → `default: break 2` → 静默结束。
  全程**没有 exit、没有异常、没有提示串**；实测抠出内层独立运行 → **零输出、零报错**。
- **双点注入**：① 解释器初始化一处；② dispatch 循环内**周期复检**一处（每 512~8192 条指令，
  与 in-loop 探针共用检查点）。只 patch 掉初始化那一行不足以恢复执行——下一条检查点会
  重新种下污染因子。开销：每数千条指令一次 `defined+constant+sha256`（≈0.3 µs），可忽略。
- **污染态异常静默收尾**：指令流错乱后，任何 `Throwable` 都只是错乱副产物。原先它会
  rethrow 成 `Uncaught TypeError: count(): Argument #1 must be of type Countable|array, null given`
  并暴露 VM 内部文件行号；现在 `catch` 开头判 `if(PZ|PZ0){break;}` 直接静默收尾，
  并把收尾返回值改为 `is_array(STACK)&&count(STACK) ? pop() : null`。
- **开关**：`--no-vm-selfcheck` 关闭（`vm_selfcheck=false`）；仅当 VM 层确实存在时才生效
  （分层配置按「是否存在 `shell:vm` 层」判定，而非 `vm` 配置键）。

### 加强 · 外壳反调试探针面

- **新增 `proc` 探针组（Linux /proc）**：`/proc/self/status` 的 `TracerPid != 0`（被
  ptrace 附着：gdb / lldb / strace）；`/proc/self/maps` 中出现 `frida|xdl|substrate|libinject`
  （注入式 hook 框架把自身 `.so` 映射进进程）。全部 `@` 抑制 + `is_string` 兜底，
  非 Linux / 无 `/proc` / `open_basedir` 限制下静默跳过。
- **`env` 组加强**：`LD_PRELOAD` / `LD_AUDIT` 中出现 `frida|xdl|substrate|inject|intercept|hook`
  即命中。**刻意不因非空就判**——jemalloc 等分配器预加载在正常生产也常见，误杀代价太大。
- **`ext` / `func` 组加强**：补 `vld`（opcode 导出）、`parsekit`、`taint`、`scream`；
  `func` 组补「实际挂载调试器」判定 `xdebug_is_debugger_active()` 与
  `php_strip_whitespace + xdebug_get_code_coverage` 组合。
- **新增 `shell` 探针组（脱壳检测）**：反调试守卫内校验同一个外壳绑定标记，缺失即显式终止。
  与 VM 自检形成纵深：守卫是「显式终止」，VM 自检是「静默污染」。
- **分层档位自动并入 `shell` 组**：`LayerDescriptor` 的 `T_LIST` 按 `options` 白名单过滤，
  未登记的组名会被**静默丢弃**；故不再依赖预设手写，而是在 `applyShellLayer()` 里按
  `selfcheck` 自动并入，并把 `shell` / `proc` 登记进 options（预设手写也合法）。

### 修复

- **P0 `high` / `max` / `ultra` 产物一运行即致命错误**：分层管线 `runLayered()` 从未注入
  外壳绑定标记（只有 legacy 单管线注入了），而 `shell:vm` 层又把 `selfcheck=1` 传给 VM →
  VM 判定「脱壳」→ opcode 污染 → `count(null)`。现已在 `shell:vm` 层应用后立即注入
  （用 v1.1.4 遗留未用的 `$bindingInjected` 标志保证只注一次）。
- **`ShellBinding::guardSource()` 语义反转**：`boundExpr()` 表示「绑定完好」，却被直接写进
  `if(...)` → 变成「绑定完好就报错」。改为 `if(isUnboundExpr(...))`。
- **`vm_inloop_probe` 兼容**：污染态静默收尾后，命中路径的输出更干净（仍无 Fatal/Parse）。

### 测试与回归

- 新增 `tests/vm_selfcheck.php`（12 项）：结构注入 / 内层不含 token 原文 / 双点注入计数 /
  标记就位时逐字节等价 / 抠出内层输出被污染且无 Fatal·Parse·提示串 / 关闭自检后裸产物正常 /
  守卫缺标记先于业务代码终止。
- 新增 `tests/preset_e2e.php`（72 项 = 6 档 × 3 fixture × 6 断言）：**预设档位的端到端兜底**。
  历史多次漏网都出在「legacy 单管线正常、分层预设致命错误」，此前没有任何测试覆盖预设路径；
  现每档都跑 构建 → `php -l` → 运行 → 与原生逐字节比对 → 无告警 → 结构不变式。
- 全量：`run_tests` 32、`hardening_test` 32、`syntax_compat` 7、`token_obf` 32、`antiast_check` 11、
  `vm_inloop_probe` 6、`vm_selfcheck` 12、`preset_e2e` 72、`vm_junk` 120、`vm_p8` 77、
  `vm_gen_probe` 290、`vm_comprehensive` 全绿；`vm_ref_e2e` 13/14、`vm_syntax_probe`
  （617/7/32）与 1.1.3/1.1.4 基线完全一致（既存缺口，与本次改动无关）。
- 文档：`README.md`、`docs/VM_FEATURES.md`（§11.19）、`docs/CLI_OPTIONS.md`
  （`--no-vm-selfcheck` + 探针组）、`docs/PERFORMANCE.md`（自检开销）。
- **测试目录安全清理**（承接上一轮 tests 审计）：删除 `tests/hardening_test.php` 里**从未被调用**
  的 `decodeShells()`——它是个「迭代 base64/gz 多轮解码、把明文拼回去」的通用解壳骨架，
  随仓库分发等于附送解壳起手式；同时把 anti_hook 的断言从「解压 blob 再查扩展名」改为
  **不解码**的「编码壳形态存在 + 扩展名不以明文出现」。`tests/vm_p8.php` 中保留的唯一一处
  `gzdecode` 是**只针对本项目 pack 格式**的单层定向断言（该格式在 `docs/VM_FEATURES.md`
  已公开描述），不构成通用解壳器。
- 版本号：`entry.php` 升至 `1.1.5`。

## v1.1.4 — VM 内反调试（dispatch 循环内周期探针 + 命中污染指令流）

> 主题：**把反调试从"入口一次"推进到"执行全程"**。此前 VM 探针（`--vm-probe`）只在解释器
> 序章执行一次、命中即 `exit(0)`——攻击者在序章后下断点、或直接 patch 掉那个 exit 即可绕过。
> 本版在 **dispatch 循环内部**周期性复检，命中不 exit，而是把污染因子混入 opcode 解码，
> 让指令流静默错乱、程序提前结束：没有可下断点的比较分支，也没有可 patch 的 `exit`。

### 新增 · VM 内反调试（`vm_inloop`）

- **注入位置**：解释器取指循环开头（`while(PC<N){ <probe> ; INS=CODE[PC++]; ... }`），
  每 **512~8192 条指令**（间隔由盐派生、每构建不同）复检一次；检查点无法按固定周期预测。
- **检测信号（只认强信号，避免误杀）**：
  - `PHP_SAPI === 'phpdbg'`（phpdbg 调试会话）；
  - `extension_loaded('uopz')` / `extension_loaded('runkit7')`（运行期 hook 扩展）；
  - `xdebug_is_debugger_active()`（**实际挂载了调试器**才为真；不像 `extension_loaded('xdebug')`
    那样把"装了 xdebug 但没在调试"的 CI/预发环境一并误杀）。
- **命中反制（关键）**：不 `exit`、不抛异常，而是把盐派生的随机污染值写进 `$___PZ___`；
  该变量在**每轮取指时异或进 opcode**（`OP = (INS[0]^SCRAMBLE)^PZ`），污染后跳转表查不到
  对应 case → 走 `default: break 2` → 内层循环静默退出、返回当前栈顶/`RET`。
  表现为「程序正常跑完但结果为空/错误」，比直接终止更难定位与 patch。
- **零语义影响**：正常环境 `PZ` 恒 0，opcode 解码结果与未注入时逐字节一致；开销约
  **2~3 ns/指令**（一次自增 + 按位与 + 未命中即跳过），百万条指令量级下 <5%。
- **开关**：默认**随 `--vm-probe` 开启**（`high`/`max`/`ultra` 的 probe 均 >0）；`--no-vm-inloop`
  关闭；分层配置 `shell:vm` 的 `inloop` 参数可覆盖（`inloop=false`）。
- **与序章探针的分工**：序章（`VmProbeMatrix`）继续负责「入口处的调试器/hook 扩展 + 字节码
  CRC 自校验、命中即 `exit(0)`」；in-loop 负责「运行中被附加调试器」的持续检测。两者叠加，
  覆盖「运行前」与「运行中」两个时间窗口。

### 测试与回归

- 新增 `tests/vm_inloop_probe.php`（6 项）：结构注入、正常环境逐字节等价、假调试器命中后
  输出被污染且无 Fatal/Parse、关闭 in-loop 后假调试器不生效（证明是 in-loop 独有能力）。
- VM 回归：`run_tests` 32、`vm_junk` 120、`vm_comprehensive`、`vm_class_debug`、`vm_ref_e2e`、
  `vm_p8` 77、`vm_gen_probe` 290 全绿；`vm_syntax_probe` 既存语法缺口数量不变。
- 文档：`docs/VM_FEATURES.md`（§4 性能注 + §11.18）、`docs/CLI_OPTIONS.md`（`--no-vm-inloop`）、
  `docs/PERFORMANCE.md`（探针开销）、`README.md`（VM 特性）同步更新。
- 版本号：`entry.php` 升至 `1.1.4`。

## v1.1.3 — 档位功能补全 + 垃圾模块重构 + 深度反AST加固 + VM JIT 友好模式（不改架构）

> 主题：**四件主线一起落地**——① 六档功能覆盖补齐（low 补 anti_ast、high 补蜜罐/结构毒化壳）；
> ② 垃圾模块彻底重拆（通用版 + VM 字节码版两个独立、极廉价模块，不再有"VM 专供"包袱）；
> ③ 深度反AST 强化（输出字符串变两段 base64 拼接 + 定义变量 + 极廉价运算，整数累加器保留并加廉价噪声）；
> ④ VM 性能提升 + JIT 友好模式（**不改栈式架构**，内联编码栈去掉嵌套闭包调用、整段解释器保持平坦大函数利于 OPcache 追踪 JIT，并新增 `--no-vm-jit` 回退）。

### 1. 档位功能覆盖补齐（#157）

- **`low` 预设新增 `anti_ast` 层**：低端档现在也做语句位常量反AST改写（此前 anti_ast 仅 mid 及以上有），`low`/`mid`/`high`/`max`/`ultra` 五档均已覆盖（`min` 刻意不含，保持零开销）。
- **`high` 预设补 `shell:honeypot` + `shell:structpoison`**：蜜罐诱饵函数（`count=2`）与结构指纹毒化壳（`rounds=2`）拉到 `high`（与 `mid` 同级开启），最高两档 `max`/`ultra` 维持 `count=4/6`、`rounds=3/5`。自此六档的反分析能力梯度更连续：min（无壳）→ low（anti_ast + 控制流）→ mid（VM + 蜜罐/毒化）→ high（VM + 蜜罐/毒化 + 状态机）→ max/ultra（顶格）。
- 回归：六档 × 多盐值对 `token_rich` 等 fixture 输出与原生逐字节一致；`run_tests` 32、`hardening` 32、`syntax_compat` 7、`token_obf` 32 全绿。

### 2. 垃圾模块重构（#158）：通用版 + VM 字节码版两个独立极廉价模块

- **删除**旧 `src/Optimize/RuntimeJunk.php`（VM 专供）与 `src/Vm/VmJunk.php`（原 VM 字节码垃圾），**重拆为两个独立模块**：
  - **`src/Junk/GeneralJunk.php`（通用垃圾）**——作用于 token 层（原 `junk_runtime` 层），涵盖全部非 VM 代码；只注入**极廉价计算**的废语句（恒等运算、无副作用赋值、死分支），绝不引入重计算或 IO，绝不会"卡成傻瓜"。
  - **`src/Vm/VmBytecodeJunk.php`（无用字节码覆盖）**——作用于已编译的 VM `Program`（字节码层面，随分片加密 + 操作码随机化一起被掩盖）；每条垃圾片段以 `PUSH_C` 开头、`DROP` 结尾、栈平衡恒为 0，**单指令级极廉价**，不读写帧变量、不碰操作数栈外状态、不含跳转/终结/输出指令。
- 两者由各自层开关驱动（`junk_runtime` 走 `GeneralJunk`；`shell:vm` 的 `junk` 参数走 `VmBytecodeJunk`），**计算量强制极低**——此前"VM 专供版"与"通用版"混在一起、强计算废代码会把运行拖慢的问题消除。
- 测试：`tests/vm_junk.php`（junk∈{0,3,6,12} × 3 模式 × 10 用例）全绿；`vm_gen_probe` / `vm_comprehensive` 跨等级无回归。

### 3. 深度反AST 强化加固（#159）

- **字符串常量输出结构升级**：语句位的 `echo`/`print`/`return` 字符串常量，由"单段 base64 解码"升级为**先解码一个内容、再拼接一个内容**两段式——`base64_decode('p1').base64_decode('p2')`，切分点按 `crc32` 哈希散列（`1 + abs(crc32($s)) % max(1, len-2)`），每次构建位置不同；并在拼接前**定义变量**承载，制造"变量被反复改写"的假象。
- **极廉价反AST 运算**：字符串走 `(string)` 强转 + `.''` 空串拼接；整数累加器保留（同作用域复用同一累加变量），并加 `$x = $x ^ 0` 这类**极廉价恒等噪声**，不增加任何可观计算量。
- 改写前后语义**逐字节等价**：每条生成式构建期经 `eval` 校验，不通过整条原样保留（绝不引入错误）。`src/Junk/AntiAst` 的 `stringExpr()` / `cheapStringOps()` / `cheapIntOps()` 协同实现。
- 测试：`tests/antiast_check.php`（11 个反AST 用例，逐字节等价）全绿。

### 4. VM 性能提升 + JIT 友好模式（#160，不改架构）

- **架构不变**：仍为栈式 VM 解释器（`$___RUN___` 闭包、int-id 跳转表 `switch` 派发），未改为寄存器式。
- **内联编码栈（JIT 友好默认开）**：操作数栈编码逻辑（按类型分流：int 单次 XOR / string 单次 XOR / float·bool·null 直存 / array serialize+XOR / 对象落明文槽 `$___MCPLAIN___`）**直接内联进 `MCPUSH` 闭包**，去掉旧实现 `MCPUSH → MCENC` 的嵌套闭包调用（每次压栈省一层调用 ≈26 ns，百万级压栈可省上百毫秒）；整段解释器保持**平坦大函数**，更易被 OPcache tracing-JIT 追踪编译。
- **`--no-vm-jit` 回退**：关闭则回到「`MCENC` 编码闭包 + `MCPUSH` 调用它」的旧实现，最大兼容（语义逐字节等价，对象同样走明文槽，属性写回不丢）。
- **DROP 优化**：`DROP` 改为一条 `if($___STACK___){array_pop;array_pop;}`（空栈直接空操作），省去无谓的解码弹栈。
- **实测（隔离栈编码路径，2M 次压栈密集循环，CLI 关 OPcache JIT，5 次中位数）**：jit 内联 **64.05 s** vs nojit 闭包 **66.82 s**——jit 模式**约快 4%**，且更利于 OPcache 追踪 JIT 编译；两种模式语义完全一致（`box=7` 等对象属性写回正确）。注：该 VM 是"每指令一次闭包调用"的 PHP 实现，OPcache tracing-JIT 对其整体提速有限（跨闭包难深度内联），jit 模式的价值主要在"平坦函数更易被 JIT 接纳 + 省一层闭包调用"。
- 文档：`docs/CLI_OPTIONS.md`（新增 `--no-vm-jit`）、`docs/VM_FEATURES.md`（§4 性能 / §11.17）同步更新。

### 5. 发布校验

- 测试全绿：`run_tests` 32 / `hardening_test` 32 / `syntax_compat` 7 / `token_obf` 32；VM 专项 `vm_gen_probe` 290（FAIL=0）、`vm_comprehensive`、`vm_class_debug`、`vm_ref_e2e`（13/14，1 个为参考运行期 `runtime.php` 既存数组元素引用限制，非发货产物：`RuntimeGenerator` 真实管线对 `ref_arr_of_ref` 输出 `1020` 与原生一致）、`vm_p8` 77、`vm_syntax_probe`（真编译 617 / 回退 7 / 失败 32，失败均为 VM 编译器已知语法覆盖缺口，与本次改动无关）、`antiast_check` 11。
- 版本号：`entry.php` 升至 `1.1.3`。

## v1.1.2 — 新增 `min` 档（接近原生、低混淆挡人版）+ 修复空字符串编码丢失

> 主题：**给"想让性能压到接近原生、又能稍微挡一下人"的场景一个档位**。新增 `min` 作为第六档，
> 只做词法级替换 + 字符串简单 XOR 解密，无反调试、无壳、无复杂派生解密；性能**接近原生**，损耗硬约束 ≤1.28×
> （原生跑 100 ms 开 `min` 必须 < 128 ms），实测纯计算 1.01×、冷启动 1.05×；同时修掉一个会让
> `$x='';` 变成 `$x=;` 的编码缺陷。

### 新增 · `min` 档（低混淆）

定位：**最接近原生**的档位（性能贴近原生、几乎零开销）。适合高频接口 / 常驻 worker / 只想"别一眼看明白"的
内部工具，也适合作为混淆流水线的第一段再按需叠加。

层清单（仅 5 层，全部纯词法替换）：

| 层 | 作用 | 运行时代价 |
|---|---|---|
| `var`(8) | 变量名混淆 | 零 |
| `number` | 数字等价形态（`0x` / 运算组合） | 零 |
| `string`(base64+XOR) | 字符串简单解密（每串独立密钥切片） | 每串一次 `base64_decode` + `XOR` |
| `comment` | 移除注释 | 零（反而更小） |
| `ws` | 压缩空白 | 零（反而更小） |

刻意不做：`func` / `class`（改符号名，跨文件与框架按名解析易踩坑）、`controlflow` /
`semantic` / `anti_ast`（改控制流与语句形态）、`junk_runtime` / `dead` / `stat` / `token`
（要执行或改变体积形态）、以及**任何 `shell:*`**（无 EVAL、无 GZ、无探针、无自校验）。

> `min` 的"挡人"只靠 **变量/数字改名 + 字符串简单 XOR 解密**（固定密钥异或、每串独立密钥切片）：**不含任何反调试探针**，也**不做 VM / 结构指纹毒化 / 多层密钥派生这类复杂派生解密**。这与 `low` / `mid` 的关键区别在于——后两者**默认已含 `shell:anti_debug` 反调试探针**（`low` = `ext,func`、`mid` = `ext,func,ini,env,sapi`），无需另行增加。

注意 `shell_shield` 在 `Config` 里默认 `true`，`min` 显式关掉——否则结构指纹毒化壳仍会挂上。

实测：

| 指标 | 原生 | `min` | 说明 |
|---|---:|---:|---|
| TP8 `GET /shop/products` | 2.00 ms | **1.56 ms** | 接近原生 |
| 计算密集负载（perf_workload） | 8.3 ms | **8.4 ms（1.01×）** | 持平；损耗远在 1.28× 内 |
| 冷启动总耗时（perf_workload） | 30.8 ms | **32.4 ms（1.05×）** | 满足 ≤1.28× 硬约束 |
| TP8 示例产物体积 | — | 104 KB | `low` 为 228 KB |

**"接近原生"不是测量错误**：去注释 + 压空白让 PHP 解析与 opcache 编译的字节数变少，而新增成本
只是常数级的 `base64_decode` + 一次 `XOR`（每串一次，文件头一条 `hash` 装密钥），两者相抵净收益几乎为零。
`min` 性能**贴近原生**，损耗硬约束 **≤1.28×**（原生跑 100 ms，开 `min` 必须 < 128 ms）；实测纯计算 **1.01×**、
冷启动 **1.05×**，均远低于上限。

### 修复 · 空字符串字面量被编码成空串（语法错误）

`StringEncoder` 的分段逻辑用 `str_split($work, $segLen)`，而 **PHP 8.2 起 `str_split('')`
返回 `[]`**（旧版返回 `['']`）。空数组经 `implode` 得空串，于是 `$x = '';` 的产物变成
`$x=;`，整文件 `Parse error: syntax error, unexpected token ";"`。

触发条件：`string` 层启用且 `split=1` 且 `length>0`。既有五档的 `split` 均 ≥2，走的另一分支
（`ceil(0/n)=0` → `[$work]`）恰好绕开，所以这个 bug 一直没暴露；新增的 `min`（`split=1`）
才踩到。现显式补 `['']` 让 `encodeSegment` 走它的空串分支输出 `''`。

影响面：所有手工使用 `--str-split=1 --str-length=N` 的用户（会直接构建出语法错误的产物）。

### 澄清 · v1.1.1 的「13.2×」与端到端「1.8~2.6×」是两种口径

v1.1.1 记录的 `high` 11 029 → 833 ms（13.2×）是**VM 内核本身**在同层配置下的提速；
而该版同时大幅加强了 `low`/`mid` 预设并给 `high` 加了更多壳层，所以端到端实测摊薄为
1.8×（轻负载）/ 2.6×（循环密集）。两者都对，但混用会误读——以 `docs/PERFORMANCE.md`
的端到端表为准。

### 其它

- 六档（`min` / `low` / `mid` / `high` / `max` / `ultra`）统一保留 `var_keep_props` /
  `var_keep_params`，保证框架可运行性一致。
- CLI 新增 `-min` 短旗标；`--level=min` 与 `--levels=min,...`（基准脚本）均可用。
- **验证**：13 个夹具 × `min` 全部语义一致；TP8 示例 `min` 档 11 端点逐字节等价；
  `run_tests` 32 / `hardening_test` 32 / `v105` 14 / `v106` 29 / `v107` 23 / `v108` 37 /
  `v109` 59 / `syntax_compat` 7 / `token_obf` 32 全绿。

---

## v1.1.1 — 五档全部跑得起来的框架 + VM 解释器提速 13× + low/mid 大幅加强

> 主题：**"能跑"与"能挡"同时成立**。这一版把 ThinkPHP8 真实站点在 `low`/`mid`/`high`/`max`/`ultra`
> **五档下全部跑通 11 个端点的逐字节等价**（此前 high 及以上会整站空正文），同时把 VM 解释器
> 的运行时开销砍掉一个量级，并把 `low` / `mid` 从"轻量防护"提到"真能挡人"。

### 新增
- **`SET_ST` / `DISPATCH` 两条 VM 专用指令**：控制流平坦化的状态机派发由 5 条指令
  （`PUSH_V st; PUSH_C s; EQ; JZ 1; JMP`）压成 1 条，状态写入由 2 条压成 1 条。这两个操作码
  自 v1.0.x 就已在 `VmOp` 中定义却从未落地，本版首次实现并在 `VmJunk`（垃圾指令插入后的
  相对跳转重定位）、`VmCombo::isRelJump`、`RuntimeGenerator::isBranch` 三处同步登记。
- **CLI：`--no-var-keep-props` / `--no-var-keep-params`**：显式关掉属性名 / 参数名保留
  （仅适用于"封闭代码"——纯库、单文件脚本、不继承框架基类）。默认保留，见下方「变更」。

### 变更
- **`low` 提升为"大部分混淆"**：`var(10) → func(12,call,enc) → class → number → string(xor,8,split2)
  → controlflow(high,goto2) → semantic(1) → junk_runtime(2) → dead(2) → comment → stat
  → token(case,literal,quote,decoy4) → ws → shell:eval(2) → shell:anti_debug(ext,func) → shell:gz(2)`。
  相比 1.1.0 的"token 级 + gz 壳"，补齐了函数/类名混淆、数字与字符串编码、控制流与 GOTO、
  语义恒等改写、运行时垃圾、诱饵注释、token 形态与 EVAL 双层壳。仍然**不用 VM**——VM 是唯一
  「运行时开销与业务语句数成正比」的开关，`low` 的开销压在"加载时一次解码"上。
- **`mid` 提升为"几乎所有混淆"**：在 `low` 基础上加 `anti_ast`、`semantic(2)`、`junk_runtime(8含tangle)`、
  `token(decoy8)`、`shell:eval(5)`、`shell:anti_debug(ext,func,ini,env,sapi,int3)`、
  `shell:anti_hook(strict)`、`shell:honeypot(2)`、`shell:newline_poison(divisor8)`、
  `shell:structpoison(2)`、`shell:anti_tamper(4,shutdown)`。**唯一缺的只有 VM**。
- **五档预设统一开启「可运行性基线」`var_keep_props` / `var_keep_params`**。属性重命名只在
  "单文件封闭世界"里成立；真实应用里框架基类按属性名读写子类属性（ThinkPHP `Model::$name`
  决定表名、`$table`/`$pk`/`$json`/`$hidden` 全是 vendor 代码在用 `$this->name` 读），
  属性一改名表名就退化成类名派生的 `product`，直接 `no such table`。参数名同理（路由按参数名
  注入、命名实参按名匹配）。放弃这两类标识符不影响任何强度维度：局部变量、函数、类、方法名
  照旧全改，各档差异（VM / 壳层 / 控制流）完全不受影响。确知目标是封闭代码可用上面两个
  `--no-*` 开关关掉。
- **VM 操作数栈编码类型化**：旧实现把**每个**压栈值 `serialize()` 后用 `str_repeat` + `substr`
  现算 XOR 掩码，出栈再 `unserialize()`；一次进出约 0.3 μs，而解释器每执行一条指令至少
  一压一弹——实测该编码占 VM 运行时总耗时的 **75%**。现按类型分流：int 走单次整型 XOR、
  string 走单次串 XOR（掩码从 8 KB 预算 pad 取 `substr`）、float/bool/null 直存、
  array 仍 serialize+XOR、不可序列化值（含 Closure 的数组、resource）落明文槽。
  出栈解码**内联**进 `MCPOP` / `MCPEEK`（省一次闭包调用 ≈ 26 ns/指令）。
- **VM 状态机派发压缩**：见「新增」的两条指令；`high` 档同一负载从 **11 029 ms 降到 833 ms（13.2×）**。
- `docs/PERFORMANCE.md` 全量换成本版实测数据；`docs/CLI_OPTIONS.md` / `docs/VM_FEATURES.md`
  同步档位描述与性能数字。

### 修复
- **【严重】`ClassPreservingVm` 注入失效 → 类里每个方法都 `return null`（整站空正文、无报错）**。
  该类要在生成的运行期里定位解释器变量名，用正则从主调用行提取；正则只认
  `$__vmv=[];$RUN($P['main'],…)` 这一种形态，不认带 CLI 超全局播种块的
  `$__vmv=[];if(array_key_exists('argv',$GLOBALS)){…}$RUN(…)`。凡命中后者（目录模式/CLI 常见），
  整段 `$GLOBALS['__awa_run_<uid>']=…` 注入被**静默跳过**，顶层桩的委托函数一进函数就
  `if($run===null||$prog===null) return null` —— 控制器返回空串、接口回空对象，框架侧表现为
  "响应里只剩调试 trace 的 CSS、正文空白"且全程无异常。现正则与替换式同构，argv 块可选。
  这是 1.1.0 及更早版本在 ThinkPHP8 上 `high`/`max`/`ultra` 全站空白的根因。
- **【严重】`var` 层把链式访问的末段当成"本类属性"改名**。`$this->app->request` 里的 `request`
  属于 `think\App`（容器绑定名），与本文件声明的同名属性无关；旧实现只看前一个 token 是 `->`
  就改写，产物里变成 `$this->app->_gfc92adb3`，`think\App` 无此属性 → 落到
  `Container::__get()` → 容器拿这个随机串当类名去解析 → `ClassNotFoundException` →
  `controller not exists`、整站 500。现仅当对象表达式是**裸变量**（`$this->prop` / `$var->prop`）
  才改名，`$a->b->prop`、`f()->prop`、`$a[0]->prop` 一律不动。
- **`FunctionCallEncoder` 在命名空间文件里生成错误回调**。`call_user_func('name')` 的字符串是
  **绝对**函数名，PHP 不会按当前命名空间回退；而 `name(…)` 会。命名空间文件里
  `make_gen(2)` 被改写成 `call_user_func('_o36…', 2)` 后必报
  `function "_o36…" not found or invalid function name`。现按声明处所在命名空间补成
  `app\demo\_o36…`，并在 VM 运行期 `$__vmcb` 增加"绝对名未命中 → 退到短名"兜底
  （VM 产物整体跑在全局命名空间、`funcs` 表按短名索引）。
- **`anti_debug` 的 `trace` 探针把任何名字含 `debug` 的类判为调试器**。ThinkPHP 自带
  `think\debug\*`、`think\trace\TraceDebug`，业务框架里叫 debug 的命名空间遍地都是——
  `max`/`ultra` 档因此在 ThinkPHP8 上**每个请求都被守卫 `exit(1)`**。现只认真正的调试/剖析器
  符号（`xdebug_*` / `phpdbg_*` / `Xdebug\*` / `Blackfire\*` / `Tideways` / `xhprof` / `uopz` / `runkit`），
  不再用裸 `debug` 子串。
- **`token` 层的类型转换别名触发 PHP 8.5 弃用告警**：`(int)↔(integer)`、`(bool)↔(boolean)`、
  `(float)↔(double)` 的等价互换在 8.5 上是 `Non-canonical cast … is deprecated`，而框架普遍用
  `set_error_handler` 把 deprecation 升级为异常（ThinkPHP 的 `Error` 初始化器即如此），
  产物直接 500。这类同义词对反编译毫无阻力（工具都会归一化），故整表清空。
- **`NewlinePoison` 的 mulberry32 乘法溢出**：`$t * (1|$t) & 0xffffffff` 的积可达 1.8e19，超出
  `PHP_INT_MAX` → 提升为 float，掩码时触发 `not representable as an int, cast occurred` 告警
  且精度丢失（`mid` 档构建时刷屏数百条）。现改用高低 16 位分块的 `mul32()`，全程 int 运算；
  与高精度参考实现 3000 组随机比对全部一致。
- **测试断言与实现规格对齐**：`hardening_test` 的"防 hook 守卫含 eval 探针"改用大小写不敏感
  匹配（关键字大小写随机化是既定特性，产物里可能是 `@EVAL(`）；`hardening_v108/v109` 的换行
  配额断言从 v1.0.8 的 `bytes/5` 改为实现自 v1.0.9 起文档化的 `bytes/divisor`（divisor 默认 2）。

### 验证
- **ThinkPHP8 示例工程（含 ORM / 事务 / 缓存 / 中间件 / 验证器 / 事件）五档全绿**：
  `low` 228 KB、`mid` 412 KB、`high` 2.1 MB、`max` 3.3 MB、`ultra` 6.9 MB，
  11 个端点（含 POST、缓存、聚合查询、签名中间件 401 分支）**状态码 + 响应体逐字节等价，11/11**。
- **测试套件**：`run_tests` 32/32、`hardening_test` 32/32、`hardening_v105` 14/14、
  `v106` 29/29、`v107` 23/23、`v108` 37/37、`v109` 59/59、`syntax_compat` 7/7、`token_obf` 32/32，
  `vm_*` 系列全通过。

---

## v1.1.0 — 白名单模式 + 深度反AST + 换行量 1/2 + PHP 8.5 + 哈希内置模块

> 正式版。tag `1.1.0` 与 GitHub Release 已发布，`origin` 已推送。此前的 `1.1.0-dev` 预发布线由本版本取代（预发布 tag 保留作为历史快照）。

### 新增
- **白名单模式（替代 classmap 自动加载器）**：废弃 `awaphp_autoload.php` 自动加载方案；目录级构建（整套系统模式）强制保留类名原名（类名混淆与 PSR-4「命名空间 + 文件名」约定冲突）。白名单是配置项 `whitelist`，支持排除目录 / 文件（整文件原样拷贝）与变量 / 函数 / 类（含接口 / 特质 / 命名空间）/ 常量 / 方法（跨文件保持原名）；CLI：`--whitelist-dirs` / `--whitelist-files` / `--whitelist-vars` / `--whitelist-funcs` / `--whitelist-classes` / `--whitelist-consts` / `--whitelist-methods`。
- **深度反AST（anti_ast，新增 token 层）**：语句位的 `echo` / `print` / `return` 常量字面量改写成「累加器 + 恒等表达式」（积木来自 `strlen(md5(1))===32` / `strlen(sha1(1))===40` / `ord('A')===65` / 定长串长度 / `base64_decode(base64_encode(x))`）；改写前后语义逐字节等价（构建期 `eval` 校验，不通过整条原样保留）。默认关，`--anti-ast` 或分层 JSON 的 `anti_ast` 层显式开启。
- **换行缠绕量改为源代码字节的 1/2**：`--newline-poison-divisor` 默认由 5 改为 2（源 10KB → 约 5000 换行）；均匀撒入 token 边界 + 部分嵌入 base64 载荷 + 头部兜底。
- **哈希内置模块（VM `builtin` 层）**：`md5` / `sha1` / `crc32` 的纯 PHP 重写作为标准 VM 模块（`src/Module/builtin/md5.{php,json}` 等）提供，开启 `builtinReplace` 时把混淆体内的原生哈希调用链到自制实现并内联，与 PHP 内置逐字节一致；此前的独立 `HashBuiltin` 类已删除——自制原语的正确形态是模块，而非独立类。
- **PHP 8.5 支持**：运行环境升级到 PHP 8.5（ppa:ondrej/php），对现代语法（enum、readonly 类、构造器属性提升、接口 / trait、`match`、一等调用 `strlen(...)`、属性钩子等）的 token 流健壮处理、混淆产物可运行。
- **调试钩子集中到 `docs/DEBUG.md`**：`OBF_VM_SRC` / `OBF_CPV_OUT` / `OBF_VM_NEST` / `OBF_SHELL_DUMP` / `OBF_LINT_DUMP` / `OBF_VM_DEBUG` 六个环境变量的位置、内容、典型排查流程统一归档。
- **`--config` 支持 PHP 数组配置文件**：`.php` 配置文件需 `return` 一个数组（可直接用 PHP 表达式与注释，维护更友好），语义与 JSON 配置完全一致（含顶层 `layers` 即走分层分支）。`Config::fromPhpFile` / `fromLayeredArray` 新增。
- **ThinkPHP 8 白名单配置范例 `other/thinkphp8.config.php`**：基于 `high` 分层、显式关闭类名混淆（兼容 PSR-4）、开启 `var_keep_props` / `var_keep_params`，并内置整目录跳过（vendor/config/route/runtime/public…）与变量 / 函数 / 类 / 方法白名单，开箱即用。
- **ThinkPHP 8 一键构建脚本 `other/thinkphp8-build.php`**：自动定位项目根（含 `app/` 且含 `think` / `composer.json` / `vendor/topthink`）、递归遍历 PHP 文件、用 `token_get_all` 单遍扫描自动生成白名单（命名空间 / 类 / 接口 / trait / enum / 全局函数 / 常量 / 控制器 public 方法），合并进基础配置后直接调用 `entry.php` 构建。参数：`--project` / `--output` / `--salt` / `--config` / `--scan` / `--app-only` / `--dry-run` / `--keep-config` / `--php`。实测整站 523 文件 8.9 s / 4.34 MB，端点全 200；`--app-only` 22 文件 9.4 s。
- **性能损耗对比文档 `docs/PERFORMANCE.md`**：结论表、可复现测量方法、混合负载与 VM 轻负载数据、ThinkPHP 8 真实页面延迟、逐开关开销归因、构建耗时与产物体积、选型建议。配套基准脚本 `tests/perf_workload.php`（token 级档）、`tests/perf_workload_vm.php`（VM 档轻负载）、`tests/perf_bench.php`（构建 + 多次采样取中位数 + 语义一致性校验）。

### 变更
- **`low` / `mid` 预设不再使用 VM 解释器（性能优先）**。VM 壳把每条语句交给 PHP 写的解释器逐条执行，是唯一「运行时开销与业务代码量成正比」的开关，也是 token 级档与 VM 档之间的量级断崖（实测混合负载 `low` 1.17× / `mid` 1.49×，`high` 超出 180 s 上限）。现 `low` = token 级 + `shell:gz`，`mid` = token 级全开 + `shell:eval(3)` + `shell:anti_debug` + `shell:gz`，两者运行时开销贴近原生；VM 自 `high` 起启用。需要 VM 级保护请用 `--level=high` 及以上。
- 工程模式类名不再混淆（PSR-4 友好）；单文件模式仍可用 `--class-obf` 做 FQCN 类名混淆。

### 修复（逐层壳「部分回退」根治）
- **壳层「部分回退」根治：所有壳完整保留，不再因 `php -l` 失败整层丢弃**。此前 anti_debug / ShellProtector 自保护 / 分层管线在产物语法校验失败时直接回退到上一层或原码，导致「破一层先被恶心一层」的复杂壳被部分剥离。现改为：回退点前先尝试 `DeclareHoist::reconcile()` 修复序言顺序，修复成功即保留壳。
- **根因 1 — 序言首位约束**：壳层注入的守卫代码把 `declare(strict_types=1)` / `namespace X;` 推离文件首位，触发 "strict_types declaration must be the very first statement" / "Namespace declaration statement has to be the very first statement"。新增 `DeclareHoist::reconcile()`（字符串版），把散落在正文中间的序言语句搬回 `<?php` 之后最前；并在分层管线 `applyShellLayer` 后、遗留 `shell()` 方法、以及 `ShellProtector::protect()` 自保护失败分支三处接入，校验前先归一。
- **根因 2 — `::class` 伪常量被破坏**：`TokenObfuscator::casePass` 对 `Foo::class` 的 `class` 做大小写随机化变成 `Foo::CLASS`，在常量表达式上下文（属性默认值 / `const` / 参数默认值）触发 "Constant expression contains invalid operations"，进而导致 ShellProtector 自保护回退。现 `T_CLASS` 且前一个 token 为 `T_DOUBLE_COLON` 时跳过随机化（`::class` 必须小写）。
- **根因 3 — 序言被注释切开**：`declare` / `namespace` 语句内部被注入注释（`declare /* x */ (strict_types=1)`、`namespace app /* x */ ;`）后，`DeclareHoist` 的正则只允许 `\s*` 导致不再识别为序言、无法搬回首部。现 `declare` / `namespace` 两处正则的**词间与终止符前**均改用含注释的 `$ws`，两种切开形态都能正确识别并归一。
- **根因 4 — 注释与除号粘连成行注释（吞代码）**：`--compress` 会删光所有空白 token，而行注释在压缩前已被改写成 `/* … */` 块注释。当注释紧跟在除号 `/` 之后时，`/` + `/*` 拼成 `//*`，词法器按**行注释**处理，把同一物理行后面所有代码吞进注释——连 heredoc 起始符 `<<<EOT` 也被吞掉，`EOT` 体变成游离语句（典型报错 `unexpected token "--"`）。现 `Obfuscator::emit` 与 `ShellProtector` 的 `needsSpace()` 在「注释 token 且上一字符为 `/` 或 `#`」时强制补一个空格，从拼接出口根治。
- **修复 `$_COOKIE` 被误改名（Web 应用读不到 Cookie）**：`VariableObfuscator` 的超全局排除表里把 `$_COOKIE` 误写成 `$COOKIE`，导致 `$_COOKIE` 一直被当作普通局部变量重命名——产物里 `$_COOKIE['k']` 变成 `$_x1a2b['k']`，而 PHP 只会写 `$_COOKIE`，读取恒为 `null`。现补正为 `$_COOKIE`。
- **修复 `$argv` / `$argc` 在 VM 下丢失（命令行工具跑错分支）**：`$argv` / `$argc` 是 CLI 主脚本的**全局变量**而非超全局，VM 把代码包进函数作用域执行时不可见，`$argv[1]` 恒为 `null`。现两处修：① 变量改名器把 `$argv` / `$argc` 加入排除表（不再改名）；② 生成的 VM 运行期在顶层帧从 `$GLOBALS` 播种这两个变量（`ClassPreservingVm` 的入口改写正则同步兼容该播种语句）。
- **修复「值为资源」的预定义常量被压成 int（`fwrite(STDERR, …)` 直接 TypeError）**：编译期把所有 `defined()` 命中的常量折进常量池 `PUSH_C`，而常量池要序列化并加密写进产物——`STDERR` / `STDOUT` / `STDIN` 的**资源**值无法序列化，落进产物变成 `int 0`。现编译期只折叠标量 / 数组常量，资源与对象常量改走运行期 `constant()` 求值。
- **验证**：ThinkPHP8 示例项目（22 文件 / 16 类）全量分层 + 全壳构建 **0 回退、0 WARN**，22 产物全 `php -l` 通过；另用 6 个不同 salt 重复构建（132 产物）**全部 0 回退 / 0 `php -l` 失败**（排除随机路径遗漏）；双服务 11 端点逐字节等价（状态码 + 响应体一致）；测试套件 32/32 通过。

---

## v1.0.9 — ThinkPHP8 级工程兼容 + 生成器/闭包真编译 + 兜底加固

> 主题：为 ThinkPHP8 这类重反射、重约定的真实项目提供**开箱即用**的混淆方案；把"不支持就回退"的边界一处处补成"真编译 / 保语义兜底"。

### 新增
- **FQCN 感知类改名**：按命名空间全限定名粒度改类名，声明点 `class_alias` 回挂原名（反射 / `class_exists` / 字符串类名照常命中）；目录级构建自动生成 **classmap 自动加载器 `awaphp_autoload.php`**（新名/原名 ↔ 原 rel 路径双向映射），产物头部自动 `require_once`；**文件名 / 目录结构保持原样**。
- **ThinkPHP8 混淆教程**：`docs/THINKPHP8_GUIDE.md`——配置推荐（`--var-keep-props --var-keep-params` 必开）、类改名机制、基线验证方法、调试钩子与 FAQ。
- **生成器真编译**（`YIELD` / `GEN_HEAD` + Fiber 生成器桥）：生成器方法、`yield`、`yield from`、闭包生成器、`send` / `getReturn` / `try-finally` 中 yield 语义与原生逐一比对一致。
- **解构表达式键**：`[$a, $b] = …` / `['k' => $v] = …` 的键支持任意表达式（字符串层把键编码成括号表达式后仍直接编译，不再整句兜底）。
- **EVAL_STMT 兜底类引用 FQCN 化**：兜底文本里的类引用自动展开 `use` 别名 / 补全命名空间（eval 单元在全局命名空间，短名类引用会 `not found`）。
- **头部序言归一安全网**：`declare(strict_types=1)` / `namespace` 恒为产物最前语句——注入器错插进序言区的语句自动搬回序言之后（否则 PHP 直接致命）。
- 回归套件：`tests/hardening_v109.php`（59 项）、`tests/fixtures/v109_features.php`；`tests/tptest.zip`（ThinkPHP8 示例项目打包）。

### 修复
- `rewriteClassRefsForEval` 引用不存在的 `T_BITWISE_OR` 常量（`|` 是单字符字符串 token）导致类保留 VM 静默降级为不透明载荷嵌套——修复后 CPV 类桩正常产出。
- `resolveClassRef` 对内置类型名（`int` / `string` / `array` 等）做命名空间补全 → `app\demo\int` 触发 "Cannot use … as class name as it is reserved"——内置类型 / `self` / `static` / `parent` 一律不补全。
- RuntimeJunk 把垃圾语句插进 `declare` 与 `namespace` 之间 → "Namespace declaration statement has to be the very first statement"——序言区边界过滤。
- 闭包 `use`（值/引用捕获）语义、trait `use` 方法体与适配块、`parent::` 非虚派发（沿声明类父链解析）、SCALL 抛错措辞与原生逐字对齐。
- 多维数组嵌套复合赋值、heredoc 插值、`f()()` 表达式结果直接调用、引用形参写回、帧栈纪律（隐式返回恒 `null`）。

### ThinkPHP8 实测
- 示例项目（22 文件 / 16 类：控制器、模型、服务、验证器、事件、中间件）`--level=high` 混淆后 **26 行基线与原版逐字节一致**（`BASELINE SAME 26/26`）。
- 同 salt 各文件独立混淆、构建确定性（同输入产物 md5 不变，仅 banner 时间戳不同）。

---

## v1.0.8
- VM 语法兼容性拓展：heredoc 插值 / nowdoc、数组展开 `[...$a]`、`empty(任意表达式)`、动态属性读写 `$o->$p`、nullsafe、`??=`、list 解构、clone。
- opcode 组合执行（`--vm-combo`）：单 opcode 拆成等价多指令序列，抗反编译。
- Confrontation-LLM 反静态分析（误导注释 / 伪造 CHANGELOG / 生僻 CJK 散布）。
- 换行缠绕壳（`--newline-poison`）：换行数 ≈ 输入字节 / 5，纠缠进密文，反美化整理。
- 语法探针真编译 601 例，生成器探针 290 例全绿。

## v1.0.7
- VM 操作数栈运行期内存加密（每进程随机 XOR 密钥 + 旁路标记栈）。
- 复杂语法真编译：clone、`?->`、`??=`、四类解构赋值。
- 指令集精简：41 条 opcode 合并为 6 个参数化 opcode。

## v1.0.6
- 逐层壳自保护（破一层先被恶心一层）；emit 空格边界修复。

## v1.0.5
- 38 个新内联模块（累计 70 个原生函数内置实现）。
- 数据流毒化壳（`--struct-poison`）/ 蜜罐陷阱（`--honeypot`）/ GOTO 死状态（`--goto-trap`）。

## v1.0.4 及更早
- token 级混淆基座：变量 / 函数 / 类名混淆、字符串 XOR 运行时装配、数字编码、控制流 GOTO、VM 壳、EVAL/GZ 多层壳、反调试 / 防篡改、OPcache 辅助编译、manifest 增量重建。
