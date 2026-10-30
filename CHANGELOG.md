# 更新日志（CHANGELOG）

## v1.1.2 — 新增 `min` 档（低混淆，零运行时开销）+ 修复空字符串编码丢失

> 主题：**给"不想付任何运行时代价"的场景一个档位**。新增 `min` 作为第六档，只做词法级替换、
> 不加任何壳，实测比原生还快；同时修掉一个会让 `$x='';` 变成 `$x=;` 的编码缺陷。

### 新增 · `min` 档（低混淆）

定位：**唯一不改变运行时特征**的档位。适合高频接口 / 常驻 worker / 只想"别一眼看明白"的
内部工具，也适合作为混淆流水线的第一段再按需叠加。

层清单（仅 5 层，全部纯词法替换）：

| 层 | 作用 | 运行时代价 |
|---|---|---|
| `var`(8) | 变量名混淆 | 零 |
| `number` | 数字等价形态（`0x` / 运算组合） | 零 |
| `string`(base64) | 字符串字面量编码 | 每串一次 `base64_decode` |
| `comment` | 移除注释 | 零（反而更小） |
| `ws` | 压缩空白 | 零（反而更小） |

刻意不做：`func` / `class`（改符号名，跨文件与框架按名解析易踩坑）、`controlflow` /
`semantic` / `anti_ast`（改控制流与语句形态）、`junk_runtime` / `dead` / `stat` / `token`
（要执行或改变体积形态）、以及**任何 `shell:*`**（无 EVAL、无 GZ、无探针、无自校验）。

注意 `shell_shield` 在 `Config` 里默认 `true`，`min` 显式关掉——否则结构指纹毒化壳仍会挂上。

实测：

| 指标 | 原生 | `min` | 说明 |
|---|---:|---:|---|
| TP8 `GET /shop/products` | 2.00 ms | **1.56 ms** | 比原生还快 |
| 计算密集负载 | 8.58 ms | **8.52 ms** | 持平 |
| TP8 示例产物体积 | — | 104 KB | `low` 为 228 KB |

**"比原生快"不是测量错误**：去注释 + 压空白让 PHP 解析与 opcache 编译的字节数变少，而唯一
新增成本是常数级的 `base64_decode`，两者相抵净收益为正。

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
