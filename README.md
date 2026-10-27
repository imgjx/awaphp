# 嗷呜awaのPHP 混淆器

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)

> 基于 PHP `tokenizer` 的源码混淆与运行时保护工具：把可读的 PHP 源码转换成**等价、可运行、但极难静态逆向**的形态。

混淆结果经 `php -l` 校验，且**运行输出与原文严格一致**——它抬高逆向成本，而不是改变程序行为。

---

## 这是什么

一个面向 PHP 项目的源码保护工具，核心思路是在 `token_get_all` 解析出的 token 流上做**可逆、保语义**的变换，再在外层套上多层**运行时保护壳**。它不依赖任何第三方服务、不把密钥交给远程服务器，全部逻辑都在本地 `src/` 内完成。

设计目标：

- **正确性优先**：任何一层变换若导致 `php -l` 失败或运行结果变化，整层自动回滚到上一安全版本。
- **token 级而非字符串级**：所有混淆作用于解析后的 token 流，避免脆弱的正则替换破坏代码。
- **可复现**：给定相同的输入与 salt，产出的代码本体逐字节一致（仅文件头时间戳不同）。
- **渐进式**：每一类保护都是独立开关，可按项目需要自由组合强度。

---

## 特性

### 标识符混淆
- 变量 / 函数 / 类 / 接口 / trait 名称混淆，全局一致替换。
- **跨文件符号一致**：`--manifest` 两遍扫描生成全局符号表，同 salt 下跨文件符号对齐，支持增量重建。
- **动态符号保守排除**：名字以字符串字面量出现过的函数 / 类（`call_user_func('f')`、`new $cls()`）一律不重命名，杜绝「能过 `php -l`、一运行就崩」。
- **拟真命名**：守卫 / 诱饵 / 版本校验变量从业务词池生成，产物中不再出现 `__awa_*`、`$__CODE` 等一眼识别串。

### Token 形态混淆（语法级保真，不改语义）
- **关键字大小写随机化**：`ecHo` / `WhIlE` / `fUnCtIoN` 均合法，直接杀死针对关键字的 grep / 正则特征。
- **字面量等价形态**：`true→!0`、`false→!1`；类型转换别名互换 `(int)↔(integer)`、`(bool)↔(boolean)`；`array(...)→[...]`。
- **引号形态安全翻转**：`'a'↔"a"`，仅在没有转义 / 插值 / 冲突引号时翻转，否则原样保留。
- **heredoc / nowdoc 变换**（`--token-heredoc`）：纯字面量串改写为 `<<<'ID' … ID` 形态。
- **顶层诱饵注释**（`--token-decoy=N`）：插入误导性注释（假许可证缓存、假完整性探针），干扰静态阅读。

### 字符串与数字编码
- 分段 base64 编码（`--str-length`）。
- **运行时异或装配**（`--str-xor`）：每条字符串使用独立密钥，破一条不等于破全部，抗静态扫描更强。
- HTML 实体编码（`--html`）。
- 数字等价表达式改写（`--num-enc`）：`0x` / 运算组合替代字面量。

### 控制流混淆
- 不透明谓词、条件取反 + 分支交换、永不执行诱饵分支（`--cfg=low|medium|high`）。
- **GOTO 状态机扁平化**（`--goto` / `--goto-cpx`）：函数体改写为 `while (1) { switch ($__s) { … } }`，支持嵌套与分块。

### 语义等价变换（反静态模式匹配）
用**类型安全、恒等保真**的重写打乱静态分析赖以匹配的代码形态（`--semantic`，强度 `--semantic-strength=1|2|3` 递增施加）：
- **if/else 逆置 + 交换**：`if (C) T else E` ⇄ `if (!(C)) E else T`。
- **德摩根律**：`!(A && B)` ⇄ `!A || !B`。
- **三元逆置**：`C ? T : E` ⇄ `!C ? E : T`。
- **for → while**：无 `continue` 的 `for` 改写为等价 `while`。
- 刻意**排除** `a*2→a<<1`、`a+1→-~a` 等浮点 / 溢出不保真的「小聪明」改写；每轮结束 `php -l` 校验，失败自动回滚上一版本。

### 虚拟机保护（VM）
把整段程序编译为**自定义字节码**，由内置解释器逐条解密执行（`--vm`）：
- **模块链接**（`--vm-linker`）：命中清单的函数调用内联进同一 Program，产物无 `include`、无模块路径、调用关系无法静态还原。默认启用**内置模块**（`--vm-link-modules`，默认开）：自动加载包内 `src/Module/builtin/`，将 `base64_encode` / `base64_decode` 等 **68 个**原生函数替换为内置自定义实现并内联进字节码，调用点不再暴露原生函数名。可通过 `--vm-linker=<dir>` 指定自定义模块目录覆盖。
- **字节码垃圾指令**（`--vm-junk=N`）：注入「真实执行但栈平衡、无副作用」的废 opcode，淹没真实逻辑。
- **诱饵函数 / fake-fog**（`--vm-fake-fog=N`）：永不调用的栈平衡假函数，膨胀函数表。
- **分片密钥链**（`--vm-keychain`）：`kᵢ = sha256(kᵢ₋₁ ‖ i)`，单点密钥无法还原其它分片。
- **常量池加密**（默认开，`--no-vm-cpool` 关）：`PUSH_C` 字面量抽入独立常量池（指令改索引引用、重复字面量去重），池以独立密钥加密——运行期解码后的指令流里只有索引，静态提取分片密钥也看不到明文常量。
- **内置函数名编码**（`--vm-builtin-replace`）：`strlen` / `array_map` 等明文调用名盐化后编码进包，运行期解码。
- **解释器运行时变形**（`--vm-morph`）：派发叠加独立第二层 scramble，每构建 dispatch 数学不同，消除固定指纹。
- **反调试 / 反篡改探针矩阵**（`--vm-probe=N`）：xdebug / phpdbg / uopz 检测 + 字节码 CRC 自校验。
- **指令集精简**：41 条同构 opcode 合并为 6 个参数化 opcode（`BINOP` / `UNOP` / `CAST_OP` / `INC_OP` / `VAROP` / `ARRAY_BUILD`）+ 隐藏 sub-id 表，消除「每个 opcode 自报家门」的语义指纹。

### 加壳
- GZ 压缩壳（`--gz`）、EVAL 多层壳（`--eval` / `--eval-layers`）、目标 PHP 版本壳（`--php-version`，支持 `7.4 / 8.0 / 8.1 / 8.2 / 8.3`，默认 `8.1`）。

### OPcache 辅助编译（Zend 字节码）
混淆产物写出后，可「加一道工」将其编译为 **Zend 字节码**（`--opcache`）：
- 部分虚拟主机（如 **EP 虚拟主机**）默认启用 OPcache，部署后可直接以字节码形态运行，进一步抬高源码恢复门槛、提升运行效率；
- 若环境安装 `phpc` 扩展，额外生成可移植的 `.phpc` 字节码文件；
- 若仅启用 OPcache，则通过 `opcache_compile_file` 预热运行期字节码缓存（Web 主机首次请求即命中字节码）；
- 环境不支持时**自动跳过、不阻断**主流程，等价于不开启该选项。

### 运行时垃圾与执行缠结（反删除剥离）
- **运行时垃圾注入**（`--junk-runtime`，强度 `--junk-intensity=N`）：在真实语句间插入 `crc32` / `base64_decode` / `strrev` 等无副作用计算，稀释真实逻辑密度。
- **执行缠结**（`--junk-tangle`）：让垃圾值**参与真实赋值**再以恒等式抵消——`$v = intdiv($v * $jt, $jt)`（整型保真）。一旦攻击者删掉 `$jt = ($k|1)` 这类垃圾定义行，缠结行退化为 `intdiv($v * null, null)`，运行即抛 `DivisionByZeroError` 自杀。垃圾从「可剔除的噪音」升级为「正确性的组成部分」，直接反制「删代码块就完事」的剥离式破解。

### 完整性防护
- **反调试**（`--anti-debug`）：检测 xdebug / phpdbg / uopz·runkit / blackfire·tideways / 调试回溯。
- **防 hook eval**（`--anti-hook`）：运行期探针验证 eval 真实生效，识别被 uopz / runkit 劫持。
- **守卫内移藏匿**：反调试 / 防 hook 守卫置于 VM / EVAL / GZ 编码壳**最内层**，被后续壳裹入密文——攻击者剥离外层代码块时够不到它们；自校验 / 授权绑定因依赖 `__FILE__` 明文锚点，仍留在最外层。
- **自校验防篡改**（`--anti-tamper`）：掩码自校验 + 分段哈希 + 总长校验 + shutdown 二次校验。
- **授权绑定**（`--license-domain` / `--license-expire`）：域名绑定（仅 Web SAPI，支持泛子域）+ 有效期，默认关闭。
- **结构指纹毒化壳**（`--struct-poison`，max/ultra 默认）：文件指纹（含插桩扩展在场计数）直接参与解密密钥派生——美化、插桩、hook `gzinflate`/`eval` 全部表现为「正常跑完但解出乱码」，没有可下断点的比较分支，也没有可 hook 的 exit。
- **蜜罐函数**（`--honeypot=N`）：`deriveMasterKey` / `verifyLicense` 这类拟真高价值命名，正常永不调用；被破解者调用即进入 1.4s 深循环并返回伪密钥，专钓「把所有函数都跑一遍」的自动化分析。
- **GOTO 诱饵死状态**（`--goto-trap=N`，默认 2）：每个状态机分派器混入外观一致的不可达分支，调试器强行改状态跳入即自锁死循环。

### 工程保障
- **可复现构建**（salt 非空时全部随机量由 `Salt::derive/shuffle` 派生）。
- **自动回滚**：每次管线结束 `php -l` 校验，失败回滚到上一安全版本。
- **输出严格一致**：测试统一断言「混淆前后执行结果一致」。

---

## 架构

```
entry.php            CLI 入口（参数解析、目录递归、输出、php -l 校验）
config.default.php   默认配置模板
src/
  Config.php         配置归一化（CLI/JSON/数组）+ 校验
  Salt.php           混淆盐：_ + md5(名字+salt) 前 N 位（可复现）
  Parser.php         token_get_all 封装 + 相邻有效 token 查询
  Logger.php         日志（text 彩色 / silence / json）
  Validator.php      php -l 校验 + 运行一致性比对
  Obfuscator.php     调度器：编排各模块、重复加密、GZ 外壳、回滚
  Variable/          变量名混淆（全局一致替换，排除超全局/$$/heredoc 等）
  Function_/         函数名混淆 + 调用编码（call_user_func 间接调用）
  Class_/            类/接口/trait 混淆
  Token/             关键字大小写、字面量等价、引号翻转、heredoc、诱饵注释
  String/            字符串分段 base64 编码（跳过 const/默认参数/case 常量上下文）
  Number/            数字等价改写
  Encode/            HtmlEncoder（实体编码）、GzEncoder（压缩加壳）
  Optimize/          去注释、插死代码、空白/压缩、序列化钩子
  AntiDetect/        字符串运行时异或装配（每串独立密钥）
  ControlFlow/       控制流混淆、GOTO 状态机、VM 壳、EVAL 壳、反调试、防 hook、防篡改、诱饵函数、授权守卫
  Layer/             层描述（LayerDescriptor）+ 层注册表（CLI 共用的参数 schema）
  Project/           SymbolTable（全局符号表）+ ProjectBuilder（整套系统两遍扫描构建）
  Support/           NameStyle（拟真命名）、DynamicSymbols（动态符号保守排除）、
                     PhpVersion、StatementSplitter、Random
```

**混淆管线（由内到外）**

```
token 级（可逆、保语义）：标识符重命名 → 控制流(cfg/goto) → 数字/字符串编码 → 死代码/压缩
        ↓
字符串级外壳（由内到外依次包裹，每步 php -l 校验，失败回滚）：
  目标 PHP 版本壳 → 反调试探针 → 防 hook eval 守卫 → VM 壳 → EVAL 多层壳 → GZ 壳
  → 诱饵函数 → 文件头版权 → 自校验防篡改 → 授权绑定守卫
        ↓
  · 反调试 / 防 hook 位于编码壳最内层，被 VM/EVAL/GZ 裹入密文藏匿（删外层代码块够不到）
  · 自校验 / 授权绑定依赖 __FILE__ 明文锚点，须留在最外层
  · OPcache 辅助编译（`--opcache`）为独立的后处理步骤，不参与管线包裹，于产物写出后再编译为 Zend 字节码
自校验防篡改基线 = 上述全部内容（含文件头注释），运行期读取自身文件即可校验
```

---

## 快速开始

```bash
# 要求：PHP ≥ 7.4（推荐 8.x），启用 tokenizer 扩展

# 混淆单个文件
php entry.php --input=app.php --output=app_obf.php \
    --var-length=8 --func-length=10 --class-obf \
    --str-length=4 --num-enc --html --gz --salt=mySalt --repeat=2

# 目录递归混淆（整套系统模式 + manifest 增量重建）
php entry.php --input=src/ --output=dist/ --salt=team-salt --manifest=dist/.obf-manifest.json

# 最强保护：VM + 一键加固 + 反检测
php entry.php --input=app.php --output=app_obf.php --vm --vm-p8 \
    --str-xor --num-enc --anti-debug --anti-tamper --salt=team-salt

# 语义等价变换 + 执行缠结（反静态模式匹配 / 反删除剥离）
php entry.php --input=app.php --output=app_obf.php \
    --semantic --semantic-strength=3 --junk-runtime --junk-tangle --salt=team-salt

# OPcache 辅助编译为 Zend 字节码（部分虚拟主机如 EP 默认启用 OPcache）
php entry.php --input=app.php --output=app_obf.php --vm --opcache --salt=team-salt

# 运行测试
php tests/run_tests.php          # CLI 集成：32 项
php tests/hardening_test.php    # 加固专项：32 项
```

> 可用保护开关远多于上方示例，完整清单见 [`docs/CLI_OPTIONS.md`](docs/CLI_OPTIONS.md)。

---

## 文档

- [`docs/VM_FEATURES.md`](docs/VM_FEATURES.md)：虚拟机保护的设计细节、局限与已知修复记录（含 heredoc 支持、do-while 修复、指令集精简等）。

---

## 许可与贡献

本项目以 **MIT 开源许可证** 发布：在保留版权声明与许可声明的前提下，你可以自由地使用、复制、修改、合并、发布、分发本工具及其源码。

欢迎在遵循项目许可证的前提下使用、研究与贡献。请通过 Issue / Pull Request 提出改进建议与问题反馈。

> 说明：混淆的目标是**抬高逆向成本**，而非「不可破」——密钥随产物分发，任何能在沙箱中运行的代码理论上都可被静态或动态还原。它适用于提高复制 / 篡改 / 逆向的工程门槛，而非作为唯一的安全边界。

---

作者 Blog：blog.awaone.cn
