# 命令行选项与功能清单

> 适用于「嗷呜awaのphp混淆器」v1.2.2（文档随版本更新，具体以 `php entry.php --help` 即时输出为准）
> 以下为全部支持的命令行开关；也可随时运行 `php entry.php --help` 查看即时帮助。

**用法**

```
php entry.php --input=<文件|目录> [--output=<文件|目录>] [选项]
```

---

## 基础 / 输入输出

| 选项 | 说明 |
|------|------|
| `-i, --input=` | 输入文件或目录（必填；目录递归混淆） |
| `-o, --output=` | 输出文件或目录 |
| `-c, --config=` | 指定配置文件（普通标量配置 / 分层 JSON / PHP 数组配置均可；`.php` 文件需 `return` 一个数组，可直接用 PHP 表达式与注释，便于维护） |
| `--level=` | 等级预设：`min\|low\|mid\|high\|max\|ultra`（一键切换完整方案）。强度递进：**`min` = 低混淆（只做词法级替换，无任何壳，运行时零开销）→ `low` = 大部分混淆 → `mid` = 几乎所有混淆**（这三档都不含 VM），`high` / `max` / `ultra` 自 `high` 起启用 VM。六档均默认保留属性名 / 参数名以保证框架可运行（见 `--no-var-keep-props`）。开销对比见 `docs/PERFORMANCE.md` |
| `-min -low -mid -high -max -ultra` | 等价 `--level=<name>` 的短旗标（等级仅作默认，散装参数可叠加覆盖） |
| `--salt=` | 混淆盐（空=不加盐，相同盐可复现） |
| `--repeat=` | 重复加密次数（默认 1） |

## 标识符混淆（变量 / 函数 / 类）

| 选项 | 说明 |
|------|------|
| `--var-length=` | 变量名混淆后长度 |
| `--var-split=` | 变量值拆分段数（>1 生效） |
| `--var-keep-props` | 保留属性名不改（框架/DI/反射/ORM 按属性名读写时必须开，ThinkPHP8 必开）。**v1.1.2 起六档预设默认开启** |
| `--var-keep-params` | 保留方法参数名不改（按参数名绑定/命名实参的框架必须开，ThinkPHP8 必开）。**v1.1.2 起六档预设默认开启** |
| `--no-var-keep-props` | 关闭上面的保留，把属性名也纳入重命名。**仅适用于封闭代码**（纯库 / 单文件脚本 / 不继承框架基类）：框架按原文读写的属性名被改会后**静默错位**（ThinkPHP `Model::$name` 决定表名，改名后直接 `no such table`） |
| `--no-var-keep-params` | 同上，参数名纳入重命名。路由按参数名注入、命名实参调用都会失效 |

> **保留这两个开关的强度代价比直觉小**。改名后的标识符是 10 字符级随机名（`$_n652ec9`），
> 比 `status` / `$count` 这类业务原名更长，所以**保留原名反而让产物略小**
> （ThinkPHP8 示例实测：`low` 199 KB → `--no-var-keep-props --no-var-keep-params` 200 KB，
> `mid` 361 KB → 365 KB，即不保留时体积涨 0.5%~1.0%）。真正的代价是**语义泄露**——
> `$autoWriteTimestamp` / `$batchValidate` / `$scene` 这类名字会把业务意图直接读出来。
> 该妥协只影响属性与参数名；局部变量、函数名、类名、方法名、字符串照旧全部混淆。
> 结论：框架项目没有理由为体积去关它们，封闭代码项目关掉可多一点语义遮蔽。
| `--func-length=` | 函数名混淆后长度 |
| `--func-call` | 函数调用混淆（间接调用） |
| `--func-call-enc` | 函数调用编码（包装名字） |
| `--class-obf` | 单文件模式启用类名混淆（FQCN 感知：namespace 不变、短名改写、声明点 `class_alias` 回挂原名）。**目录级构建（整套系统模式）默认不重命名类名**——改名会与 PSR-4「命名空间 + 文件名」约定冲突，故工程模式强制保留类名原名、靠框架自动加载工作；个别需要排除的类/函数可用 `--whitelist-classes` / `--whitelist-funcs` 保留（详见 `docs/THINKPHP8_GUIDE.md`） |

## 编码与优化

| 选项 | 说明 |
|------|------|
| `--str-length=` | 字符串编码分段长度（>0 生效） |
| `--str-xor` | 字符串运行时异或装配（替代 base64，更强抗扫描） |
| `--html` | 对 HTML 字符串做实体编码 |
| `--num-enc` | 数字编码 |
| `--token-obf` | Token 形态混淆（总开关）：关键字大小写随机化 + 字面量等价形态 + 引号安全翻转，杀死针对关键字的 grep / 正则特征 |
| `--token-no-case` | 关闭关键字大小写随机化（默认开） |
| `--token-no-literal` | 关闭字面量形态变换（`true→!0` / cast 别名 / `array()→[]`） |
| `--token-no-quote` | 关闭引号形态翻转（`'abc' ↔ "abc"`） |
| `--token-heredoc` | 纯字面量串改写为 nowdoc（`<<<'ID'`）形态（默认关） |
| `--token-decoy=` | 顶层诱饵注释数量（默认 3，0=关闭；保留注释时生效） |
| `--gz` | GZ 压缩加壳 |
| `--insert-dead` | 插入无用代码 |
| `--no-comments` | 移除注释 |
| `--strip-newline` | 去除换行符 |
| `--compress` | 代码压缩（去空白/注释） |
| `--copyright=` | 版权注释注入文本 |
| `--no-banner` | 关闭文件头「混淆引擎名 + 版本号」注入 |

## 白名单（不混淆指定目录 / 文件 / 符号）

白名单是一个配置项，用于把**绝不能改名的目录、文件或符号**排除出混淆，避免框架按名加载 / 反射 / DIP 绑定 / 动态调用断链。可在 JSON 配置里用 `"whitelist": {...}` 表达，也可用 PHP 数组配置文件（详见 `other/thinkphp8.config.php` 的 ThinkPHP 8 范例），或用下列 CLI 旗标（逗号分隔，支持 `*` 通配）。

| 选项 | 说明 |
|------|------|
| `--whitelist-dirs=` | 不混淆的目录（相对输入目录；`app/Admin` 或 `app/Admin/*` 通配），整目录**原样拷贝** |
| `--whitelist-files=` | 不混淆的文件（精确相对路径，如 `config/app.php`），整文件**原样拷贝** |
| `--whitelist-vars=` | 不重命名的变量名（带不带 `$` 均可，如 `\$db,\$cfg`） |
| `--whitelist-funcs=` | 不重命名的函数名（如 `helper,format_money`）——目录模式跨文件一致保留 |
| `--whitelist-classes=` | 不重命名的类名（FQCN 或短名，如 `App\Service\Order`）；另含 `--whitelist-namespaces` / `--whitelist-interfaces` / `--whitelist-traits` 等价归入 |
| `--whitelist-consts=` | 不重命名的常量名 |
| `--whitelist-methods=` | 不重命名的方法名（形如 `Class::method` 或裸方法名） |

> 目录 / 文件跳过的语义是「原样拷贝、不参与混淆」——其内声明的符号保持原名，跨文件引用自然不断链；符号保留的语义是「跨文件保持原名」。两者都可用，按排除粒度选择。

## 控制流与虚拟机保护

| 选项 | 说明 |
|------|------|
| `--cfg=` | 控制流混淆：`low\|medium\|high` |
| `--goto=` | GOTO 混淆嵌套层数（>0 生效） |
| `--goto-cpx=` | GOTO 每分发器最大语句数（>0 生效） |
| `--sm-complexity=` | 原生状态机每分发器最大语句数（2~32，默认 8）：把连续语句切块后每块一个分发器 |
| `--sm-passes=` | 原生状态机嵌套层数（1~5，默认 2）：把上一层每个分发器再分发，形成分发树 |
| `--sm-traps=` | 原生状态机诱饵死状态数（0~4，默认 2）：不可达标签，强行跳入即自锁死循环 |
| `--no-sm-opaque` | 关闭状态机转移上的不透明谓词（默认开：`if(($o^$o)===0)` 恒真谓词，else 为指向诱饵的死路） |
| `--vm` | VM 虚拟机壳（自定义指令集 + 解释器） |
| `--vm-layers=` | VM 嵌套层数（真·嵌套 VM）：`>=2` 时把上一层 VM 产物再喂给 VM 编译，逐层叠加多层 VM 壳；默认 `1`（单层） |
| `--vm-linker=` | ModuleLinker 模块目录（JSON 清单）：命中清单的函数调用内联进同一 Program，产物中无模块路径、无 include 指令。**v1.1.9 起**散装键构建显式指定该目录时，同时启用 `shell:module_link` **原生路径**（非 VM 档位内联：调用点改写 + 模块原生定义追加）；分层预设 `low` / `mid` 无需指定，已默认启用该层 |
| `--vm-link-modules` / `--no-vm-link-modules` | 内置模块目录（`src/Module/builtin/`，70+ 个函数）**默认开**；关闭后不自动加载内置模块，调用点保留原生函数分发。**v1.1.9 起 low 及以上档位全覆盖**：VM 档位（`high`/`max`/`ultra`）走字节码内联，非 VM 档位（`low`/`mid`）走原生内联 |
| `--vm-junk=` | VM 字节码级真实垃圾指令：注入 N 段「真实执行但无副作用」的废 opcode（栈平衡、无输出、无变量写入），抗静态分析 |
| `--vm-keychain` | VM 分片密钥哈希链：`k0=master`，`ki=sha256(k[i-1]‖i)`，运行期顺序派生，静态提取单点密钥无法还原其它分片 |
| `--vm-builtin-replace` | natTbl 内置函数名盐化编码进二进制包，运行期解码；包内无 `strlen`/`array_map` 等明文调用名 |
| `--vm-morph` | VM 解释器运行时变形：派发增加独立第二层 scramble，每构建派发数学不同（消除固定 dispatch 指纹） |
| `--vm-fake-fog=` | VM 诱饵函数：注入 N 个永不调用的栈平衡假函数，淹没真实 opcode 集合、膨胀函数表（>0 生效） |
| `--vm-probe=` | VM 反调试 / 反篡改探针矩阵：注入 N 个探针（xdebug/phpdbg/uopz 检测 + 字节码 crc 自校验），命中即静默停止（>0 生效） |
| `--vm-p8` | P8 一键总开关：等价开启 keychain+builtin-replace+morph，fake-fog=6、probe=4（单项可再覆盖） |
| `--no-vm-symbols` | 关闭 VM 符号名密文（**默认开**）：VM 字节码容器内的**全部字符串**（类名 / 方法名 / 函数名 / 变量名 / 属性名 / 常量名 / 异常表三字段 / 指令操作数里的符号串）以**按源文件内容派生**的盐密钥加密落盘，运行期解包时还原——即便攻击者提取出分片密钥解开容器，`strings` 也读不到任何符号名。密钥不跨文件复用（拿 A 产物的密钥解不开 B 产物）。**配套的「容器格式随机化」始终开启**（类型标记字节逐构建不同，无独立开关）：容器原本的固定类型标记序列 `NULL=0/INT=1/STR=2/BOOL=3/FLT=4/SEG=5` 是格式指纹，通用解容器脚本按这组常量即可套用；现映射到每次构建都不同的随机字节，同 salt 仍可复现。关闭本项后退化为 v1.1.7 行为（符号明文入容器） |
| `--no-vm-cpool` | 关闭 VM 常量池加密（默认开）：PUSH_C 字面量默认抽入独立常量池（指令改索引引用），池以独立密钥加密，静态分析看不到明文常量 |
| `--no-vm-jit` | 关闭 VM 解释器 JIT 友好模式（默认开，`vm_jit=true`）：开启时操作数栈编码逻辑**内联**进 `MCPUSH` 闭包、整段解释器保持平坦大函数，更易被 OPcache tracing-JIT 追踪编译、且每次压栈省一层嵌套闭包调用（≈26 ns/指令，百万级压栈可省上百毫秒）；关闭则回退到「`MCENC` 编码闭包 + `MCPUSH` 调用它」的旧实现，最大兼容。两种模式语义逐字节等价（对象均走明文槽 `$___MCPLAIN___`，属性写回不丢） |
| `--no-vm-inloop` | 关闭 VM 内反调试（默认随 `--vm-probe` 开启，`vm_inloop=true`）：解释器 dispatch 循环内每 512~8192 条指令复检一次调试器/hook 扩展（phpdbg SAPI、uopz、runkit7、xdebug 实际挂载），命中**不 exit**，而是把盐派生污染因子异或进 opcode 解码，使指令流静默错乱并提前结束——没有可下断点的比较分支、也没有可 patch 的 exit；正常环境零语义影响（污染因子恒 0），开销约 2~3 ns/指令。`shell:vm` 的 `inloop` 参数可覆盖 |
| `--no-vm-selfcheck` | 关闭 VM 高级环境自检（默认开，`vm_selfcheck=true`）：解释器初始化 + dispatch 循环内**双点**校验「外壳绑定标记」——混淆器在最外层定义盐派生常量，内层只持其 sha256 摘要并用 `hash_equals` 比对；把 VM 内层整段抠出独立运行、或删掉外层 define，自检即失败并把盐派生污染因子写进 opcode 解码 → 零输出、零报错（无可下断点、无可 patch 的 exit）。仅当产物含 VM 层时生效；`shell:anti_debug` 的 `probes` 含 `shell` 组时，守卫额外做一次显式终止校验 |
| `--vm-combo[=N]` | VM opcode 组合执行（抗反编译）：把单条 opcode 拆成等价多指令序列（`SUB→NEG+ADD`、`PUSH_C 2,MUL→DUP,ADD`、`BOOL_NOT→PUSH_C 0,EQ` 等），打破 1:1 opcode 映射。N=1~3 为强度（默认 2）；`--no-vm-combo` 关闭 |
| `--mutual-comments` | 互斥注释 / 认知污染（**默认开**，v1.1.7 增强）：在各层壳**内部**投放「互相打脸」的成簇断言——同一段代码同时被标注为「已废弃可删 / 唯一活路径 / 仅供审计 / 三条均不可信」，让 LLM 与人工阅读都无法确定该信哪条。主题库 22 组（含 3 向、4 向成簇矛盾）；同簇 N 条断言落在相邻 1~3 个 token 边界内并排可见。注释只投放到「前一个有效 token 以结构性标点结尾」的安全边界，杜绝多层壳剥离注释后的 `ASBoom` 式粘连。`--no-mutual-comments` 关闭 |
| `--mutual-intensity=N` | 互斥注释强度 `1~3`（默认 2）：每层壳投放 **3 / 6 / 12 簇**（簇内 2~4 条紧凑互斥断言），投放上限随可用安全边界数放宽。仅在 `--mutual-comments` 开启时生效；簇主题、位置与文本由 salt 确定性派生，相同盐可复现 |
| `--confrontation-llm` | Confrontation-LLM 反 LLM 分析：注入误导性注释（假 `@deprecated`/`@see`/`@todo`/`@fixme` + 伪造 CHANGELOG 暗示「已被移除」实际仍在执行）并向壳内层散布生僻 CJK 注释，误导 LLM 优先级判断；配合 `--confrontation-intensity=N`（1~3，默认 2）调节密度；`--no-confrontation-llm` 关闭 |
| `--newline-poison` | 换行缠绕壳（结构指纹毒化壳配套）：注入大量换行符（数量 = `ceil(源字节数 / divisor)`，divisor 默认 2，即源 10KB → 5000 换行），一部分作语句间空行、一部分嵌进最长 base64 载荷串，纠缠进代码而非堆尾部；`mid`/`high`/独立模式下换行在产物中可见，`max`/`ultra`（含结构毒化壳）下换行随内层一同加密进密文；`--newline-poison-divisor=N` 改字节除数（默认 2）、`--newline-poison-min=N` 改下限（默认 0）、`--newline-poison-ratio=F` 改串内比例（默认 0.1）；`--no-newline-poison` 关闭 |
| `--eval` | EVAL 多层加壳 |
| `--eval-layers=` | EVAL 加壳层数（默认 3） |
| `--anti-debug` | 反调试探针（检测 phpdbg / xdebug 等）。探针组可由配置项 `ad_probes` 指定（逗号分隔）：`ext`（扩展加载：xdebug/uopz/runkit/blackfire/tideways/xhprof/vld/parsekit/taint/scream/pcov）、`func`（函数存在 + xdebug 实际挂载）、`ini`（xdebug.mode/profiler/remote + `auto_prepend_file`/`auto_append_file`/`opcache.jit`）、`env`（XDEBUG_CONFIG / XDEBUG_MODE / PHPDBG / 插桩型 LD_PRELOAD·LD_AUDIT）、`sapi`（phpdbg）、`trace`（调用栈）、`proc`（`/proc/self/status` 的 TracerPid、`/proc/self/maps` 注入 .so）、`shell`（脱壳检测：外壳绑定标记缺失即终止）。分层档位下 `shell` 组由混淆器按 `vm_selfcheck` 自动并入；**未开 VM 自检时该组会被自动剔除**（无绑定标记可比对，否则产物一运行就误杀退出）。每个探针组在构建期做一次 `php -l` 语法自检，出错的组整组剔除并告警 |
| `--anti-hook` | 防 hook eval（eval 完整性探针 + uopz/runkit/phpdbg 检测） |
| `--anti-tamper` | 自校验防篡改（掩码自校验 + 分段哈希 + shutdown 二次校验） |
| `--shell-shield-intensity=` | 逐层壳自保护强度 `1-3`（默认随等级：min,low=1 / mid,high=2 / max,ultra=3；`min` 的 `shell_shield` 本身为关，此项对其无效）；仅控制语义变换激进度，变量重命名始终开启 |
| `--no-shell-shield` | 关闭逐层壳自保护（每层壳解码逻辑不再被外层混淆护住，破解更易） |
| `--php-version=` | 目标 PHP 版本（通用）：`7.4\|8.0\|8.1\|8.2\|8.3`（默认 `8.1`） |
| `--opcache` | OPcache 辅助编译：产物写出后编译为 Zend 字节码（部分虚拟主机如 EP 虚拟主机默认启用 OPcache，可直接以字节码形态运行；环境不支持时自动跳过） |
| `--junk-funcs=` | 诱饵函数数量（>0 生效，拟真命名，干扰逆向） |
| `--inline-each-layer` / `--no-inline-each-layer` | **v1.2.0**：逐层内联。每个 shell 壳（含多层 EVAL 的**每个子层**）包裹后各做一次模块链接，每层分配独立的 `__ml<层序号><salt>_` 前缀，避免跨层函数重定义；指纹 / 明文锚点敏感层（VM、反调试、反篡改等）自动跳过。分层 JSON 顶层可写 `"inline_each_layer": true`。默认关 |
| 分层层 `shell:anti_strip` | **v1.2.0 反剥离互锁壳**：参数 `depth`（2–6，默认 3）、`response`（`silent` 静默拒绝 / `exit` 终止）。K 层 EVAL 每层 `define` 一个 salt 派生锚点常量，最内层 eval 前校验完整锚点网格；抠出任意内层单独运行即拒绝执行真实源码。`mid` / `high` / `max` / `ultra` 预设已内置 |
| 分层层 `shell:anti_tamper` 新参数 | **v1.2.0 v3**：`chain`（分段链式哈希 `X[i]=sha1(seg[i]‖prev‖i)`）、`multipoint`（P1 启动 + P2/P3 shutdown 多点复检）、`segments`、`shutdown`；守卫行新增**明文 blob 完整性闸**，堵住「改坏密文 → `gzinflate` 静默失败 → eval 内自校验不执行」的绕过 |
| 分层层 `shell:anti_debug` 新参数 | **v1.2.0**：探针组新增 `func2`（`xdebug_call_*` / `phpdbg_*` 全家族）、`ini2`（`xdebug.*` / `opcache.jit_debug` 等 ini 全家族）；新增 `response`（`exit` 直接终止 / `silent` 判定与反制分离、shutdown 擦除输出缓冲）、`multipoint`（关机二次判定，覆盖运行中 attach）、`intensity` |

> **高级 PHP/HTML 混写（v1.2.0）**：含 `<?=` 短标签、替代语法（`if(): endif;` 等）、
> heredoc 内 HTML、`?><?php` 边界、PHP 嵌属性 / `<script>` / `<style>` 的模板页，
> 会在管线入口由 `InlineHtml::normalize()` 等价改写为纯 PHP 后再进入各壳，
> 六档预设输出逐字节一致。无需额外开关；纯 PHP 源码零改动。

## 授权绑定

| 选项 | 说明 |
|------|------|
| `--license-domain=` | 授权域名，逗号分隔（如 `a.com,b.com`）；仅 Web SAPI 下校验 |
| `--license-expire=` | 授权到期时间（`Y-m-d` 或 `Y-m-d H:i:s`）；到期后停止执行 |

## 整套系统 / 目录模式

| 选项 | 说明 |
|------|------|
| `--manifest=` | 符号映射 manifest JSON 路径：首次构建落盘，增量重建沿用旧映射，保证多次迭代后跨文件符号名兼容 |

## 界面与日志

| 选项 | 说明 |
|------|------|
| `--format=` | `text\|json`（json 便于 GUI / 脚本解析） |
| `--verbose` | 详细日志 |
| `--silence` | 静默 |
| `-h, --help` | 显示帮助 |
| `--info` | 显示工具信息 |

## 超激进逻辑变换（v1.2.2）

| 开关 | 说明 |
| --- | --- |
| `--logic` | 启用超激进逻辑变换（反 AST 超集）：任意位置表达式的类型安全恒等重组；强度 3 再把简单赋值 / `echo` 展开成「创建两个临时变量 → 串计算 → 跨语句复用」的变量网，临时变量再被变量层改名 |
| `--logic-intensity=1\|2\|3` | 变换强度（默认 2）：1=数字/一元 `!`，2=+比较/布尔，3=+临时变量缠结。仅 `--logic` / 分层 `logic` 层启用时生效 |
| 分层 `logic` 层 | `intensity` 参数 1–3；预设 `low=1 / mid=2 / high=2 / max=3 / ultra=3`，`min` 不启用。该层须置于 `var` 层之前，使新变量被一并重命名 |

> 所有改写均经样本值代入校验，不通过则原样保留；常量表达式上下文（`const` / enum case /
> 参数默认值 / 属性默认值 / `#[...]` / `static` 初始化）只用纯位运算形态，`declare()` 内跳过。
> 详见 `docs/VM_FEATURES.md` 11.27 与 `CHANGELOG.md` v1.2.2。

---

> 说明：上述开关可按需自由组合；每一类保护都是独立开关，组合越强、逆向门槛越高，但也可能影响运行性能与可调试性，请结合实际场景权衡。
