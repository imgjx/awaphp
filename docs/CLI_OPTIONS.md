# 命令行选项与功能清单

> 适用于「嗷呜awaのphp混淆器」v1.0.6（文档随版本更新，具体以 `php entry.php --help` 即时输出为准）
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
| `--level=` | 等级预设：`low\|mid\|high\|max\|ultra`（一键切换完整方案；v1.0.3 起各档启用 P8 架构方向、新增最高档 ultra=真·嵌套 VM） |
| `-low -mid -high -max -ultra` | 等价 `--level=<name>` 的短旗标（等级仅作默认，散装参数可叠加覆盖） |
| `--salt=` | 混淆盐（空=不加盐，相同盐可复现） |
| `--repeat=` | 重复加密次数（默认 1） |

## 标识符混淆（变量 / 函数 / 类）

| 选项 | 说明 |
|------|------|
| `--var-length=` | 变量名混淆后长度 |
| `--var-split=` | 变量值拆分段数（>1 生效） |
| `--var-keep-props` | 保留属性名不改（框架/DI/反射/ORM 按属性名读写时必须开，ThinkPHP8 必开） |
| `--var-keep-params` | 保留方法参数名不改（按参数名绑定/命名实参的框架必须开，ThinkPHP8 必开） |
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
| `--vm` | VM 虚拟机壳（自定义指令集 + 解释器） |
| `--vm-layers=` | VM 嵌套层数（真·嵌套 VM）：`>=2` 时把上一层 VM 产物再喂给 VM 编译，逐层叠加多层 VM 壳；默认 `1`（单层） |
| `--vm-linker=` | ModuleLinker 模块目录（JSON 清单）：命中清单的函数调用内联进同一 Program，产物中无模块路径、无 include 指令 |
| `--vm-junk=` | VM 字节码级真实垃圾指令：注入 N 段「真实执行但无副作用」的废 opcode（栈平衡、无输出、无变量写入），抗静态分析 |
| `--vm-keychain` | VM 分片密钥哈希链：`k0=master`，`ki=sha256(k[i-1]‖i)`，运行期顺序派生，静态提取单点密钥无法还原其它分片 |
| `--vm-builtin-replace` | natTbl 内置函数名盐化编码进二进制包，运行期解码；包内无 `strlen`/`array_map` 等明文调用名 |
| `--vm-morph` | VM 解释器运行时变形：派发增加独立第二层 scramble，每构建派发数学不同（消除固定 dispatch 指纹） |
| `--vm-fake-fog=` | VM 诱饵函数：注入 N 个永不调用的栈平衡假函数，淹没真实 opcode 集合、膨胀函数表（>0 生效） |
| `--vm-probe=` | VM 反调试 / 反篡改探针矩阵：注入 N 个探针（xdebug/phpdbg/uopz 检测 + 字节码 crc 自校验），命中即静默停止（>0 生效） |
| `--vm-p8` | P8 一键总开关：等价开启 keychain+builtin-replace+morph，fake-fog=6、probe=4（单项可再覆盖） |
| `--no-vm-cpool` | 关闭 VM 常量池加密（默认开）：PUSH_C 字面量默认抽入独立常量池（指令改索引引用），池以独立密钥加密，静态分析看不到明文常量 |
| `--vm-combo[=N]` | VM opcode 组合执行（抗反编译）：把单条 opcode 拆成等价多指令序列（`SUB→NEG+ADD`、`PUSH_C 2,MUL→DUP,ADD`、`BOOL_NOT→PUSH_C 0,EQ` 等），打破 1:1 opcode 映射。N=1~3 为强度（默认 2）；`--no-vm-combo` 关闭 |
| `--confrontation-llm` | Confrontation-LLM 反 LLM 分析：注入误导性注释（假 `@deprecated`/`@see`/`@todo`/`@fixme` + 伪造 CHANGELOG 暗示「已被移除」实际仍在执行）并向壳内层散布生僻 CJK 注释，误导 LLM 优先级判断；配合 `--confrontation-intensity=N`（1~3，默认 2）调节密度；`--no-confrontation-llm` 关闭 |
| `--newline-poison` | 换行缠绕壳（结构指纹毒化壳配套）：注入大量换行符（数量 = `ceil(源字节数 / divisor)`，divisor 默认 2，即源 10KB → 5000 换行），一部分作语句间空行、一部分嵌进最长 base64 载荷串，纠缠进代码而非堆尾部；`mid`/`high`/独立模式下换行在产物中可见，`max`/`ultra`（含结构毒化壳）下换行随内层一同加密进密文；`--newline-poison-divisor=N` 改字节除数（默认 2）、`--newline-poison-min=N` 改下限（默认 0）、`--newline-poison-ratio=F` 改串内比例（默认 0.1）；`--no-newline-poison` 关闭 |
| `--eval` | EVAL 多层加壳 |
| `--eval-layers=` | EVAL 加壳层数（默认 3） |
| `--anti-debug` | 反调试探针（检测 phpdbg / xdebug 等） |
| `--anti-hook` | 防 hook eval（eval 完整性探针 + uopz/runkit/phpdbg 检测） |
| `--anti-tamper` | 自校验防篡改（掩码自校验 + 分段哈希 + shutdown 二次校验） |
| `--shell-shield-intensity=` | 逐层壳自保护强度 `1-3`（默认随等级：low=1 / mid,high=2 / max,ultra=3）；仅控制语义变换激进度，变量重命名始终开启 |
| `--no-shell-shield` | 关闭逐层壳自保护（每层壳解码逻辑不再被外层混淆护住，破解更易） |
| `--php-version=` | 目标 PHP 版本（通用）：`7.4\|8.0\|8.1\|8.2\|8.3`（默认 `8.1`） |
| `--opcache` | OPcache 辅助编译：产物写出后编译为 Zend 字节码（部分虚拟主机如 EP 虚拟主机默认启用 OPcache，可直接以字节码形态运行；环境不支持时自动跳过） |
| `--junk-funcs=` | 诱饵函数数量（>0 生效，拟真命名，干扰逆向） |

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

---

> 说明：上述开关可按需自由组合；每一类保护都是独立开关，组合越强、逆向门槛越高，但也可能影响运行性能与可调试性，请结合实际场景权衡。
