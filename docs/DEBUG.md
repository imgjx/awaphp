# DEBUG.md — 调试钩子（OBF_* 环境变量）

混淆器内置一组**环境变量调试钩子**，开启后把中间产物/异常现场落盘或打到 stderr，
用于定位「某一层为什么没按预期生效」「为什么回退」「为什么产物非法」。

> 这些钩子**只在 CI/本地排查时**临时开启，生产构建不要设置（不影响产物正确性，
> 但会产生大量临时文件、拖慢构建、并可能泄露中间表示）。
> 所有 `OBF_*` 钩子读取自 `getenv()`，值为目录路径（绝对路径最佳），`OBF_VM_DEBUG` 只需"存在且非空"。

---

## 一览表

| 环境变量 | 作用域 | 触发时机 | 落盘内容 |
| --- | --- | --- | --- |
| `OBF_VM_SRC` | `Obfuscator::shellVmOnce` | 进入 VM 编译前 | 每个待编译文件的**源码转储** `.php` + 构建选项 `.opts.json` |
| `OBF_CPV_OUT` | `Obfuscator::shellVmOnce` | `ClassPreservingVm::wrap` 成功 | **类保留 VM 产物** `.php`（真实类桩 + 字节码） |
| `OBF_VM_NEST` | `Obfuscator::vmPayloadNest` | 走"不透明载荷嵌套"回退时 | 编入 VM 前的**原始源码** `.php` |
| `OBF_SHELL_DUMP` | `ShellProtector` | 壳层变换后 `php -l` 失败 | 非法产物 `.bad.php` + 变换前输入 `.in.php` |
| `OBF_LINT_DUMP` | `Validator::checkSyntaxString` | 任意 `php -l` 校验失败 | 未通过校验的源码 `.php` |
| `OBF_VM_DEBUG` | `VmStmtTrait`（VM 编译） | 某条语句触发 `EVAL_STMT` 语句级兜底 | 兜底语句文本 + 错误原因打到 **stderr**（`[EVAL_FALLBACK]` 前缀，截断到 2000 字符） |

---

## 各钩子详解

### OBF_VM_SRC — VM 输入源码转储
- **位置**：`src/Obfuscator.php`（`shellVmOnce` 内，两条路径：类保留 VM 分支与普通 VM 分支）。
- **内容**：进入 VM 编译/封装前的**源码文本**（已去除 `<?php` 开标签与 `?>` 闭标签）。
  - 普通 VM：文件名 `HHMMSS_<md5前6位>.php`，同目录另写 `<同名>.opts.json`（VM 构建选项：分片/常量池/垃圾指令/stateMachine 等）。
  - 类保留 VM：文件名 `HHMMSS_cpv_<md5前6位>.php`（与 `OBF_CPV_OUT` 内容对应，便于对照"输入→产物"）。
- **用途**：确认"到底喂给 VM 编译器的是什么"。排查类保留 VM 静默降级（见下）、字符串层把键编码成括号表达式等问题时，先看这里。
- **注意**：开启 `OBF_VM_SRC` 不一定触发 `OBF_CPV_OUT`——只有确实走了 `ClassPreservingVm::wrap` 且产物非空、且与原码不同，才会写 `OBF_CPV_OUT`。若 `OBF_VM_SRC` 有转储但 `OBF_CPV_OUT` 空，说明类保留 VM 被静默跳过（历史根因：引用了不存在的 `T_BITWISE_OR` 常量导致 `wrap` 抛错被吞 → 改走不透明载荷嵌套）。

### OBF_CPV_OUT — 类保留 VM 产物转储
- **位置**：`src/Obfuscator.php`（`ClassPreservingVm::wrap` 成功后）。
- **内容**：类保留 VM 的完整产物（真实类桩：方法体委托全局 `__awa_vmmethod_{uid}` + VM 字节码程序）。
- **用途**：验证"类名操作数 / `class_alias` 回挂 / 桩方法委托"是否如预期插桩。历史排查中靠它证明插桩串确实进入了产物、从而锁定"运行期错误其实是原生 PHP Error 而非 VM 抛错"的假象。

### OBF_VM_NEST — 不透明载荷嵌套转储
- **位置**：`src/Obfuscator.php`（`vmPayloadNest` 内）。
- **内容**：被 base64+gz 编进 VM 作为不透明载荷的**原始源码**（带命名空间块、或在 namespace 块里声明真实类的产物走此路径——这类结构无法直接编译为等价 VM 字节码）。
- **用途**：区分"真编译 VM"与"不透明载荷嵌套"两种路径。嵌套路径语义 100% 原生（载荷在 eval 单元里执行），但保护强度弱于真编译。

### OBF_SHELL_DUMP — 壳层自保护异常现场
- **位置**：`src/ControlFlow/ShellProtector.php`（壳层变换后 `php -l` 失败的分支）。
- **内容**：`HHMMSS_<md5前6位>.bad.php`（未通过语法校验的壳层产物）+ `.in.php`（变换前的原始输入）。
- **用途**：壳层（AntiDebug/AntiHook/AntiTamper 等）的某次变换把代码改坏时，直接对比 `in`/`bad` 找破坏点。

### OBF_LINT_DUMP — 语法校验失败落盘
- **位置**：`src/Validator.php`（`checkSyntaxString` 内）。
- **内容**：未通过 `php -l` 的源码 `.php`（命名 `HHMMSS_<md5前6位>.php`）。
- **用途**：任何校验失败（含壳层、VM 产物、最终 emit）的统一兜底落盘，最粗粒度但有总比没有好。

### OBF_VM_DEBUG — 语句级兜底日志（stderr）
- **位置**：`src/Vm/Parts/VmStmtTrait.php`（VM 编译抛 `VmUnsupported` 走 `EVAL_STMT` 兜底处）。
- **内容**：打到 stderr 的一行 `[EVAL_FALLBACK] <错误原因> :: <兜底语句文本（截断 2000 字符）>`。
- **用途**：看 VM 编译器"哪些语句兜不住、为什么兜"。注意日志截断原本只有 160 字符、会截掉长语句里的类名，已扩到 2000；若你看到类名在语句里被截断，调大这里的 `substr(..., 0, 2000)`。

---

## 典型排查流程

```bash
# 1) 看 VM 到底拿到了什么输入、类保留 VM 产物长啥样
OBF_VM_SRC=/tmp/dbg/vmsrc OBF_CPV_OUT=/tmp/dbg/cpv \
  php /workspace/entry.php --input=... --output=... --level=high

# 2) 某语句疑似被语句级兜底，开 stderr 日志
OBF_VM_DEBUG=1 php /workspace/entry.php --input=... --output=... 2>/tmp/dbg/vmdebug.txt
grep 'EVAL_FALLBACK' /tmp/dbg/vmdebug.txt

# 3) 产物疑似被壳层改坏
OBF_SHELL_DUMP=/tmp/dbg/shell php /workspace/entry.php --input=... --output=...
diff /tmp/dbg/shell/*.in.php /tmp/dbg/shell/*.bad.php

# 4) 最终产物 php -l 不过
OBF_LINT_DUMP=/tmp/dbg/lint php /workspace/entry.php --input=... --output=...
php -l /tmp/dbg/lint/*.php
```

> 调试产物会残留 `.php` 文件（含中间表示/原始源码），排查完记得清理对应目录，
> 不要随构建产物一起提交或分发。
