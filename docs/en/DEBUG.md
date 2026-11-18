# DEBUG.md — debug hooks (`OBF_*` environment variables)

[简体中文](../DEBUG.md) · **English**

The obfuscator ships a set of **environment-variable debug hooks**. When enabled they dump intermediate artifacts / failure snapshots to disk or print to stderr, which is how you answer "why did this layer not take effect", "why did it roll back", "why is the artifact invalid".

> Enable these hooks **only for CI/local investigation** — never in production builds (they do
> not affect correctness, but they create many temporary files, slow the build down, and may
> leak intermediate representations).
> All `OBF_*` hooks are read via `getenv()`; the value is a directory path (absolute preferred).
> `OBF_VM_DEBUG` only needs to be "present and non-empty".

---

## Overview

| Variable | Scope | Trigger | What is written |
| --- | --- | --- | --- |
| `OBF_VM_SRC` | `Obfuscator::shellVmOnce` | Right before VM compilation | **Source dump** of each file about to be compiled (`.php`) plus build options (`.opts.json`) |
| `OBF_CPV_OUT` | `Obfuscator::shellVmOnce` | `ClassPreservingVm::wrap` succeeded | **Class-preserving VM artifact** `.php` (real class stubs + bytecode) |
| `OBF_VM_NEST` | `Obfuscator::vmPayloadNest` | The "opaque payload nesting" fallback is taken | **Original source** `.php` before being packed into the VM |
| `OBF_SHELL_DUMP` | `ShellProtector` | `php -l` fails after a shell transform | Invalid artifact `.bad.php` + pre-transform input `.in.php` |
| `OBF_LINT_DUMP` | `Validator::checkSyntaxString` | Any `php -l` check fails | The source that failed validation `.php` |
| `OBF_VM_DEBUG` | `VmStmtTrait` (VM compilation) | A statement triggers the `EVAL_STMT` fallback | Fallback statement text + reason on **stderr** (`[EVAL_FALLBACK]` prefix, truncated to 2000 chars) |

---

## Hook details

### OBF_VM_SRC — dump of the VM input source
- **Location**: `src/Obfuscator.php` (inside `shellVmOnce`, two paths: the class-preserving VM branch and the normal VM branch).
- **Content**: the **source text** right before VM compilation/wrapping (`<?php` open tag and `?>` close tag removed).
  - Normal VM: file name `HHMMSS_<md5[0:6]>.php`, plus `<same name>.opts.json` in the same directory (VM build options: split keys / constant pool / junk instructions / stateMachine, …).
  - Class-preserving VM: file name `HHMMSS_cpv_<md5[0:6]>.php` (matches `OBF_CPV_OUT`, so you can compare "input → artifact").
- **Use**: confirm what is actually fed to the VM compiler. For a silently degraded class-preserving VM (see below) or a string layer encoding keys into parenthesised expressions, look here first.
- **Note**: enabling `OBF_VM_SRC` does not necessarily produce `OBF_CPV_OUT` — the latter is written only when `ClassPreservingVm::wrap` really ran, produced a non-empty artifact, and it differs from the source. If `OBF_VM_SRC` has dumps but `OBF_CPV_OUT` is empty, the class-preserving VM was silently skipped (a historical root cause: referencing the non-existent `T_BITWISE_OR` constant made `wrap` throw, the error was swallowed, and the pipeline fell back to opaque payload nesting).

### OBF_CPV_OUT — dump of the class-preserving VM artifact
- **Location**: `src/Obfuscator.php` (after a successful `ClassPreservingVm::wrap`).
- **Content**: the complete class-preserving VM artifact (real class stubs whose method bodies delegate to the global `__awa_vmmethod_{uid}` + the VM bytecode program).
- **Use**: verify that "class-name operands / `class_alias` re-registration / stub-method delegation" are instrumented as intended. In past investigations this proved the instrumentation string really made it into the artifact, which pinned down that "runtime errors were actually native PHP errors, not VM throws".

### OBF_VM_NEST — dump of the opaque-payload nesting path
- **Location**: `src/Obfuscator.php` (inside `vmPayloadNest`).
- **Content**: the **original source** that gets base64+gz packed into the VM as an opaque payload (artifacts with namespace blocks, or that declare real classes inside a namespace block, take this path — such structures cannot be compiled directly into equivalent VM bytecode).
- **Use**: distinguish "real VM compilation" from "opaque payload nesting". The nesting path is 100% native in semantics (the payload executes inside the eval unit) but protects less than real compilation.

### OBF_SHELL_DUMP — failure snapshot for shell self-protection
- **Location**: `src/ControlFlow/ShellProtector.php` (the branch where `php -l` fails after a shell transform).
- **Content**: `HHMMSS_<md5[0:6]>.bad.php` (the shell artifact that failed syntax validation) + `.in.php` (the original input before the transform).
- **Use**: when a shell transform (AntiDebug / AntiHook / AntiTamper, …) breaks the code, diff `in` against `bad` to find the breakage.

### OBF_LINT_DUMP — dump of syntax-validation failures
- **Location**: `src/Validator.php` (inside `checkSyntaxString`).
- **Content**: the source that failed `php -l`, as `.php` (`HHMMSS_<md5[0:6]>.php`).
- **Use**: a uniform safety net for any validation failure (shells, VM artifacts, final emit) — the coarsest granularity, but better than nothing.

### OBF_VM_DEBUG — statement-level fallback log (stderr)
- **Location**: `src/Vm/Parts/VmStmtTrait.php` (where a `VmUnsupported` exception routes the statement through the `EVAL_STMT` fallback).
- **Content**: one stderr line `[EVAL_FALLBACK] <reason> :: <fallback statement text (truncated to 2000 chars)>`.
- **Use**: see which statements the VM compiler cannot handle and why. The log used to truncate at 160 chars, cutting class names out of long statements; it is now 2000. If you still see truncation, raise the `substr(..., 0, 2000)` limit.

---

## Typical investigation workflow

```bash
# 1) See what the VM actually received, and what the class-preserving artifact looks like
OBF_VM_SRC=/tmp/dbg/vmsrc OBF_CPV_OUT=/tmp/dbg/cpv \
  php /workspace/entry.php --input=... --output=... --level=high

# 2) A statement seems to hit the statement-level fallback — turn on the stderr log
OBF_VM_DEBUG=1 php /workspace/entry.php --input=... --output=... 2>/tmp/dbg/vmdebug.txt
grep 'EVAL_FALLBACK' /tmp/dbg/vmdebug.txt

# 3) The artifact looks broken by a shell transform
OBF_SHELL_DUMP=/tmp/dbg/shell php /workspace/entry.php --input=... --output=...
diff /tmp/dbg/shell/*.in.php /tmp/dbg/shell/*.bad.php

# 4) The final artifact fails php -l
OBF_LINT_DUMP=/tmp/dbg/lint php /workspace/entry.php --input=... --output=...
php -l /tmp/dbg/lint/*.php
```

> Debug output leaves `.php` files behind (containing intermediate representations and original
> source). Clean up those directories when done, and never commit or ship them with build
> artifacts.
