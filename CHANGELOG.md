# 更新日志（CHANGELOG）

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

### 变更
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
