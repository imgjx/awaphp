# 更新日志（CHANGELOG）

## v1.1.0-dev — 白名单模式 + 深度反AST + 换行量 1/2 + PHP 8.5 + 内置哈希

> 本版本已发布为**预发布版（prerelease）**：tag `1.1.0-dev`、GitHub Release 与 `origin` 推送均已就绪；属开发预览线，不进稳定版本序列。

### 新增
- **白名单模式（替代 classmap 自动加载器）**：废弃 `awaphp_autoload.php` 自动加载方案；目录级构建（整套系统模式）强制保留类名原名（类名混淆与 PSR-4「命名空间 + 文件名」约定冲突）。白名单是配置项 `whitelist`，支持排除目录 / 文件（整文件原样拷贝）与变量 / 函数 / 类（含接口 / 特质 / 命名空间）/ 常量 / 方法（跨文件保持原名）；CLI：`--whitelist-dirs` / `--whitelist-files` / `--whitelist-vars` / `--whitelist-funcs` / `--whitelist-classes` / `--whitelist-consts` / `--whitelist-methods`。
- **深度反AST（anti_ast，新增 token 层）**：语句位的 `echo` / `print` / `return` 常量字面量改写成「累加器 + 恒等表达式」（积木来自 `strlen(md5(1))===32` / `strlen(sha1(1))===40` / `ord('A')===65` / 定长串长度 / `base64_decode(base64_encode(x))`）；改写前后语义逐字节等价（构建期 `eval` 校验，不通过整条原样保留）。默认关，`--anti-ast` 或分层 JSON 的 `anti_ast` 层显式开启。
- **换行缠绕量改为源代码字节的 1/2**：`--newline-poison-divisor` 默认由 5 改为 2（源 10KB → 约 5000 换行）；均匀撒入 token 边界 + 部分嵌入 base64 载荷 + 头部兜底。
- **内置哈希原语（HashBuiltin）**：md5 / sha1 / crc32 的纯 PHP 自包含实现（不依赖扩展，32 位无符号运算 + 小端 / 大端输出），供深度反AST 恒等积木与工具自身使用；与 PHP 内置逐字节比对一致。
- **PHP 8.5 支持**：运行环境升级到 PHP 8.5（ppa:ondrej/php），对现代语法（enum、readonly 类、构造器属性提升、接口 / trait、`match`、一等调用 `strlen(...)`、属性钩子等）的 token 流健壮处理、混淆产物可运行。
- **调试钩子集中到 `docs/DEBUG.md`**：`OBF_VM_SRC` / `OBF_CPV_OUT` / `OBF_VM_NEST` / `OBF_SHELL_DUMP` / `OBF_LINT_DUMP` / `OBF_VM_DEBUG` 六个环境变量的位置、内容、典型排查流程统一归档。
- **`--config` 支持 PHP 数组配置文件**：`.php` 配置文件需 `return` 一个数组（可直接用 PHP 表达式与注释，维护更友好），语义与 JSON 配置完全一致（含顶层 `layers` 即走分层分支）。`Config::fromPhpFile` / `fromLayeredArray` 新增。
- **ThinkPHP 8 白名单配置范例 `other/thinkphp8.config.php`**：基于 `high` 分层、显式关闭类名混淆（兼容 PSR-4）、开启 `var_keep_props` / `var_keep_params`，并内置整目录跳过（vendor/config/route/runtime/public…）与变量 / 函数 / 类 / 方法白名单，开箱即用。

### 变更
- 工程模式类名不再混淆（PSR-4 友好）；单文件模式仍可用 `--class-obf` 做 FQCN 类名混淆。

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
