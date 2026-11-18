# Custom virtual machine (VM) — feature support

[简体中文](../VM_FEATURES.md) · **English**

> Module: `src/Vm/` (compiler `Compiler.php` + dynamic runtime generator `RuntimeGenerator.php` + opcodes `Op.php`)
> Entry point: `src/ControlFlow/VirtualMachine::wrap()` (custom ISA path, graceful fallback on failure)

> Note: §1–§10 are the reference part and are translated in full. §11 (the release-by-release fix log,
> 1000+ lines in the Chinese original) is condensed here to one entry per version covering the change and
> the reason. Read the Chinese document for the line-by-line derivations.

---

## 1. What this is

This VM is **not** the old shell that XORs PHP statements and `eval`s them one by one — it is a **real custom instruction set (ISA)**:

- a stack machine: operands are popped from the operand stack, results pushed back;
- user source is compiled **at build time** into a set of custom opcode numbers plus a constant pool;
- at runtime a dedicated interpreter, **generated per build**, fetches, decodes and executes instruction by instruction;
- a cracker only sees scrambled opcode numbers and a split-encrypted bytecode blob — **no PHP statements, no fixed fingerprint**.

Data flow: `PHP source → VmCompiler::compile() → ['consts','funcs','classes','main'] → VmRuntimeGenerator generates a dedicated runtime + binary bytecode container (varint + operand type tags + gzip + independent per-shard encryption) → self-contained artifact (inlined runtime) interprets it`.

The essential difference from the old "one fixed `runtime.php` + plaintext opcodes": the new generator produces **one dedicated runtime per file per build** — randomised opcode numbers, random variable names, shuffled `switch` case order with injected decoys, independently encrypted bytecode shards. Two files yield interpreters that differ from each other, so generic signature libraries (assuming a fixed `1=ADD`) fail outright.

---

## 2. Supported syntax

| Category | Supported forms | Notes |
|------|-----------|-------------|
| **Variables** | `$x = 1; $x; $arr[$k]; $this->prop`; `$this` inside instance methods | Scalars, arrays and object properties are all readable/writable; the instance-method prologue emits `LOAD_THIS` to inject the object into the `$this` frame slot (§11.1); `$this` inside a static method triggers a whole-file fallback |
| **Compound assignment** | `$x += 1; $x -= ; *= /= %= .=` | Simple variables and **single-dimension array/property subscripts** (`$a['k'] .= x`, `$o->p .= x`) are truly compiled (LVRead double container + `INDEX_SET`/`PROPSET` write-back); **multi-dimensional nesting** (`$a['x']['y'] .= x`) still falls back to EVAL_STMT |
| **Arrays** | `[1,2,3]`, `array(1,2)`, `['k'=>v]`, `$a[]=v`, `$a[$k]=v`, `$a[$k]` | Indexed arrays / associative arrays / push / subscript read-write |
| **Array functions** | `count()`, `array_push()`, … | Called as builtins (below) |
| **Functions** | `function f($a,$b){ return ...; }`, recursion, `$f()` variable functions | Each call gets an **independent variable frame**, no cross-function pollution |
| **First-class callable methods (FCC, PHP 8.1+)** | `$o->m(...)`, `$this->m(...)`, `C::m(...)`, `C::$m(...)`, `$cls::m(...)`, `self::/static::/parent::m(...)`, dynamic method names `$o->$n(...)` / `C::$n(...)` | Validated at creation time with native-identical wording: null receiver / method missing (without `__call`/`__callStatic`) / class-name FCC pointing at a non-static method; instance FCC binds `$this`, static methods also work through instance FCC; `__call`/`__callStatic` allow FCC creation for missing methods (magic dispatch happens at call time); `static::` resolves to the called class at creation time, and a closure call-time push keeps `static::` consistent inside the closure. Known gap: the VM does not track method visibility, so private/protected cross-scope FCC does not throw (native does) |
| **Magic methods** | `__construct`, `__call`, `__callStatic` | Both normal calls and FCC go through magic dispatch; `get_called_class()` resolves to the creation-time class inside the bridge closure |
| **Argument binding** | by-reference `f(&$x)`, variadic `f(...$args)`, named `f(b:1,a:2)`, spread `f(...$arr)` (including string keys → named) | Compile time only pushes and emits markers (`PUSH_REF`/`ARG_NAMED`/`ARG_SPREAD`); reordering and dereferencing happen **at runtime** against the callee signature — correct even when the receiver type is unknown at compile time (`$obj->m(b:1)`); reference parameters do copy-in/copy-out per the by-ref bit, and skipped named parameters correctly fall back to defaults |
| **Static calls** | `C::m()`, `C::$m()`, `self::m()`, `parent::m()`, **`static::m()` (late static binding)** | Methods resolve along the inheritance chain; named/reference/spread arguments supported too. `static::` resolves methods/constants/properties/`::class` against the **called class** at runtime (same source as `get_called_class()`); `self::`/`parent::` method calls are **forwarding calls** (preserving the called class, per measured PHP semantics); static-form calls inside instance methods forward `$this`. Dynamic class names `instanceof $c` are supported as well |
| **Builtin PHP functions** | `strlen()`, `substr()`, `implode()`, `range()`… anything `function_exists` | Bridged via `call_user_func_array`; functions with reference returns / special signatures follow PHP default semantics |
| **Classes** | `class C { public $p=...; function m(){...} }`, `extends`, `implements`, `trait` (`use T;` and adaptation blocks `use A,B{ A::m insteadof B; B::m as am; }`) | Property defaults, `__construct`, `$this->p`, `$this->m()`, method chaining; trait adaptation blocks resolve conflicts and register aliases **at compile time** (deduplicated by root trait, so the same trait imported via different paths does not conflict), with `insteadof` redirection and `as` aliases (including static methods, multi-exclusion, and the no-op `m as m`) |
| **Objects** | `new C()`, `new $cls()`, `$obj->m()`, `$obj->p` | Objects are stored as `\stdClass` + metadata; behaviour is equivalent |
| **Loops** | `while` / `for` / `foreach` (indexed and key-value `as $k=>$v`) | `break` / `continue` both supported, jumping to the right place |
| **Conditionals** | `if / elseif / else`, ternary `?:`, short ternary (elvis), `match` | `match($subj){ 1,2=>"a", default=>"b" }`; multiple arms share one condition; nested `match`, default arms and expression results supported |
| **Arithmetic** | `+ - * / % **`, unary `-`, string concatenation `.` | — |
| **Bitwise** | `~ & \| ^ << >>` | `&` vs `&&`, `<<` vs `<` are correctly distinguished |
| **Comparison** | `== != === !== < > <= >=`, **spaceship `<=>`** | `<=>` returns -1/0/1 |
| **Logic** | `&& \|\| !`, **null coalescing `??`** | `??` equals `isset(lhs) ? lhs : rhs` |
| **Inc/dec** | `++$x` / `--$x` (prefix), `x++` / `x--` (postfix) | Variable increment is emitted as a **composite instruction sequence** (`PUSH_V/PUSH_C/ADD/SUB/DUP/POP_V`; the dedicated opcode was removed in §11.2); property/subscript increments still use the `INC_OP` family. **Simple variables only**; postfix `++` on complex expressions falls back. Known limitations: string letter-carry and boolean increment cannot be expressed by generic arithmetic (§11.2) |
| **Constants** | `true / false / null`, integer/float/string literals (including `\n \t \r \xNN \u{NNNN}`), compile-time constants (`PHP_VERSION`, …) | Double-quoted interpolation **is supported** (`"a{$x}b"`, `"{$arr['k']}"`, `"${x}"` — all three forms fixed) |
| **Output** | `echo $a, $b;` | Multiple comma-separated arguments |
| **Exceptions** | `throw` / `try-catch` / `try-finally` / `try-catch-finally` (multiple catch, `catch (A\|B)`, `catch (E)` without a variable) | Compiled into an **exception table** (`[tryStart,end,handlerPc,kind,type,varName]`), dispatched at runtime innermost-range first: catch before finally; segments containing an exception table skip flatten / stateMachine entirely (to avoid absolute-pc distortion) |

### 2.1 A working example (`tests/fixtures/complex_app.php`)

Covers: recursion (Fibonacci), primality test (while), factorial (for), classes and properties, method-call chains, key-value `foreach`, bitwise ops, string statistics, ternary + loop summation. Under the VM the **output is identical to a native `require`** (see the benchmark in §4).

---

## 3. Unsupported / gracefully degrading features

On the following syntax the compiler throws `VmUnsupported`; the upper layer `VirtualMachine::wrap()` catches it and **keeps the original code, never breaking the build** (the whole file falls back to ordinary packing instead of VM wrapping).

| Feature | Why it falls back |
|------|---------|
| Multi-dimensional compound assignment `$a['x']['y'] .= x` | One dimension is truly compiled; nested lvalue chains go through the EVAL_STMT fallback (statement level, semantics preserved) |
| `$this->prop[]` property array push | Property push unsupported |
| Postfix `++/--` on complex expressions (e.g. `$a[0]++`) | Only simple variables supported |
| Dynamic member access beyond `$obj->$name()` | Runtime type unknowable |
| `self::$p[]` static property array read | Form not implemented |
| `$GLOBALS` whole-table assignment / `$GLOBALS[]` push | Superglobal table has special semantics |
| Top-level `return` / `exit` / `break` / `continue` (bare top level) | Would break the interpreter loop; the VM shell skips the whole segment |
| `goto` | No corresponding instruction |
| Method-level reference return `$r = &$o->m()` | Function-level `$r = &g()` is supported (CALL_RR); method level falls back for that statement |

> **Advice for code you want the VM to wrap**: keep function/method bodies straightforward — `for/foreach/while + if/ternary`, avoid anonymous functions and property array pushes. Reference parameters, variadics, named/spread arguments and static calls are all supported, so use them freely.

---

## 4. Performance cost (measured)

Driver: `tests/vm_run.php` (compile → pack a self-contained artifact → run → compare with native + median over rounds).

| Scenario | Native | VM mode | Time cost | Peak memory | Memory cost |
|------|------|---------|---------|---------|---------|
| Complex business logic (`complex_app.php`) | 0.063 ms | 0.95 ms | **≈15×** | 2.0 MB | 1.0× |
| Heavy loop (1,000,000 iterations, `perf_heavy.php`) | 22 ms | 1885 ms | **≈85×** | 2.0 MB → 26 MB | **≈13×** |

**Reading the numbers:**
- Time cost mainly comes from "the big `switch` over opcodes + operand stack in/out" — each PHP statement becomes several bytecode instructions executed by the interpreter, typically **10–85×**;
- Light logic (few statements) costs ~15×; tight loops are amplified to 80×+;
- Memory: the interpreter itself (runtime closure, stack arrays) adds resident overhead, up to an order of magnitude under heavy load;
- **Artifact size**: self-contained artifact = runtime (~13 KB) + encoded bytecode. About **4.7×** for a 3 KB complex source, about **57×** for a 230 B micro source (runtime dominates).

> The VM is positioned as **strong obfuscation / anti-static-analysis**, not performance optimisation. It suits security-sensitive, latency-insensitive distributions. If a piece of code is performance-critical and the VM cannot handle it, the compiler falls back to ordinary packing automatically.

> **Since v1.1.1 the interpreter hot path was rewritten**, making the same code **2.5–2.6×** faster than 1.1.0 (dispatch-dense workloads) to **1.8×** (mixed workloads). Two changes:
> ① **Typed operand-stack encoding** — the old version `serialize()`d every push and computed the XOR mask with `str_repeat`, then `unserialize()`d on pop, accounting for ~75% of VM time; the new one branches by type (int → one integer XOR / string → one string XOR / float, bool, null stored directly / only arrays serialised / non-serialisable values to a plaintext slot), with decoding inlined into `MCPOP`/`MCPEEK`.
> ② **State-machine dispatch compression** — two dedicated instructions `DISPATCH` / `SET_ST` collapse the five-instruction dispatch prologue into one and the two-instruction state write into one. Full comparison: `docs/en/PERFORMANCE.md` §4.

> **Since v1.1.3 there is a JIT-friendly mode (on by default, `vm_jit=true`)**: the operand-stack encoding is **inlined into the `MCPUSH` closure**, removing the old `MCPUSH → MCENC` nested closure call (~26 ns saved per push, hundreds of ms over a million pushes); the interpreter stays a **flat mega-function**, which OPcache tracing-JIT compiles more readily. Measured on the isolated stack-encoding path (2M-push dense loop, CLI with OPcache JIT off, median of 5): jit inlined **64.05 s** vs nojit closure **66.82 s** — **~4% faster**, and both modes are byte-identical in semantics (objects go through the plaintext slot `$___MCPLAIN___`, property write-back is lossless). `--no-vm-jit` restores the old closure stack for maximum compatibility. Note: this VM is a PHP implementation with "one closure call per instruction", so OPcache tracing-JIT helps only moderately (deep cross-closure inlining is hard); the jit mode's value is "a flat function the JIT accepts + one less closure call". The `DROP` instruction was optimised to a no-op on an empty stack at the same time.

---

## 5. Usage and integration

```php
use VmRuntimeGenerator;
use Salt;

// Single-file VM compilation (build time; generates a dedicated runtime + split-encrypted bytecode)
$gen = new VmRuntimeGenerator(new Salt('optional fixed seed for reproducibility'));
$artifact = $gen->buildArtifact($sourceWithoutPhpTags, [
    'flatten'      => true,   // or 'stateMachine' => true (mutually exclusive)
    'chunks'      => 3,      // bytecode shard count, independent key per shard
])['php'];                    // self-contained PHP source, runnable as-is

// Run and capture stdout only (tests / verification):
$out = $gen->execute($sourceWithoutPhpTags, ['stateMachine' => true, 'chunks' => 3]);

// Wire it into the obfuscation pipeline via VirtualMachine::wrap():
//   - prefer compiling to the custom ISA;
//   - on VmUnsupported, fall back gracefully and keep the original code, never breaking the artifact.
```

Tests and regression:
- `tests/vm_gen_probe.php` — **81 cases × 4 modes** (flat_off/flat_on/sm_off/sm_on) all PASS, covering loops/conditionals/arrays/operators/recursion/classes/break-continue/ternary/`match`/`<=>`/`??`/double-quoted interpolation/builtins;
- `tests/vm_run.php <php file> [rounds]` — automated "can it run + how much does it cost" measurement for any PHP file;
- demo build: `php build_vm_demo.php` produces `vm_demo/` (with `example_source.php` and two self-contained artifacts, flattened and state-machine, whose output matches native).

---

## 6. Module linking (ModuleLinker, P6)

Standalone feature modules are managed as JSON manifests in a directory. During a VM build, if the main source calls a manifest-listed function **at a function-call site**, that module's PHP code is **pulled in and compiled into the same Program** — "call a module function" becomes "call a linked function inside the same Program", and the indirect-call instruction on the original path disappears, making the call graph unanalysable:

1. The artifact has **no module file path and no `include` instruction**, only "bytecode inside some function body + an ordinary `CALL`";
2. `__FILE__` / `realpath` / `debug_backtrace` never show the module filename;
3. Module code goes through symbol-table renaming (NameStyle aliases) plus string/number encoding before entering the VM, blending in tightly with the main program.

### 6.1 Manifest format (`modules/xxx.json`)

```json
{
  "name": "strlen",
  "match": ["strlen", "mb_strlen"],        // exact name match (call sites)
  "match_regex": ["/^strlen/", "/^mb_/"],  // regex match (optional)
  "entry": "strlen.php",                   // module entry file (relative to this json)
  "deps": ["strlen_helper.php"],           // dependencies, packed too (optional)
  "replace": true,                          // replace calls with the inlined module (default true)
  "priority": 10,                           // priority when several manifests match (higher wins)
  "target": "my_strlen"                     // optional: point call sites at this function in the module
}
```

### 6.2 Loading and hit scanning

- At build time `ModuleLinker::loadDir($dir)` loads every `*.json` in the directory (sorted by filename, so equal `priority` takes the earlier file).
- The main source is scanned by `matchSource()` for **function-call sites** (a `T_STRING` whose next significant token is `(`, and which is not preceded by `->` / `::` / `new` / `function` / `use`): exact `match` first, then `match_regex`; among multiple hits the highest `priority` wins.
- Returns `matchedName => spec` as the seed for linking.

### 6.3 Transitive dependency linking

A matched module may itself call **another module that never appears in the main source** (e.g. `cross_run` → `mathx_double`). `linkModules()` seeds from the main source's hits and recursively discovers and links referenced modules (`linkRecursive`), **compiling dependencies first** — so the callee is in `linkedAlias` before the call site is compiled, and the call site emits `CALL` instead of `CALL_NATIVE` (which would fatal at runtime because the real function table lacks it).

### 6.4 Aliases and merging

- Module top-level functions are registered as `'_ml' . substr(hash('sha256', specName.'|'.fn), 0, 12)`, so **the original module function name never appears in the bytecode**.
- `renameMap()` maps call-site original names to aliases; names already defined by the main program / embedded segments are not rewritten (**main program wins**).
- The module's function / class / parameter / global tables merge into the main Program; `include` inside modules reuses the main program's `IncludeResolver`, with embed ids allocated in the main id space.
- A module hit by several names (e.g. `match_regex /^rx_/` matching both `rx_tag` and `rx_wrap`) is linked once, but every hit name is registered in `linked` so the main compiler pre-registers `CALL` (otherwise `CALL_NATIVE` would fatal at runtime).

### 6.5 Native fallback inlining

When a module **matches but is not VM-inlined** (`replace=false` or linking failed), the call site still emits `CALL_NATIVE`; if the artifact lacks that function it fatals at runtime. `inlineSource()` then inlines the module source (entry + deps + static includes recursively, tags stripped, include instructions removed) **natively** at the artifact's top level, keeping it self-contained and non-fatal.

### 6.6 Division of labour with IncludeResolver (P4)

- Module linking has priority: matched modules are inlined into the Program (this class);
- Unmatched `include`s with statically resolvable paths go through IncludeResolver's static inlining;
- Anything neither can handle (variable paths) uses `INCLUDE_EMBED` runtime embedding.

### 6.7 Usage

```bash
# CLI: point at the module directory (where the JSON manifests live)
php entry.php --input=app.php --output=app_obf.php --vm --vm-linker=tests/fixtures/modules
```

The GUI (LayerDescriptor layered parameter `modules`) is equivalent to the CLI: passing a `modules` key in a layered config, or the loose `vm_linker` key, is normalised to `moduleDir`. Relative paths resolve against the main file's directory first (same as include's `baseDir`).

The build log reports every hit / skip:

- `shell:vm module-link: cross_run -> module[crossmod] (inlined)`
- `shell:vm module-link skipped mathx_double: module mathx has replace=false, not inlined (call site keeps native dispatch)`

### 6.8 Tests

`tests/vm_module_link.php` (17 checks) covers: exact / regex hits, reuse across multiple hit names, transitive cross-module linking, alias rewriting, native fallback inlining (`replace=false` / linking failure), `include` flattening inside modules, and top-level statement exclusion. Regressions `tests/run_tests.php` and `tests/hardening_test.php` are fully green with no fallback.

`tests/vm_builtin_modules.php` (96 checks) verifies byte-identical "native execution vs VM-inlined execution" for every built-in module under `src/Module/builtin`, and asserts the call was actually hit by `ModuleLinker` (the hits contain the function name, proving inlining rather than native dispatch).

### 6.9 Built-in module inventory (`src/Module/builtin`, added in v1.0.1)

The default module directory `src/Module/builtin` (`Config::defaultModuleDir()`) ships **70+** common function modules; during a VM build a hit is **statically linked** into the same `Program` (function/class tables merged + alias rewritten to `_ml<first 12 of sha256>`), leaving no module path, no `include` and no original function name in the artifact.

| Category | Modules (match names) |
| --- | --- |
| String | `strlen` `substr` `strpos` `strtolower` `strtoupper` `trim` `ltrim` `rtrim` `strrev` `str_repeat` `str_pad` `ord` `chr` `explode` `implode` (alias `join`) `strcmp` `substr_replace` `str_replace` `sprintf` `md5` `sha1` `crc32` |
| Array | `array_reverse` `array_merge` `array_keys` `array_values` `in_array` `array_sum` `array_unique` `array_slice` `array_map` `array_filter` |
| Math / encoding | `abs` `round` `number_format` `base_convert` `urlencode` `urldecode` `rawurlencode` `base64_encode` `base64_decode` |

Module implementation constraints (the VM-compilable subset): variables use a `__mod_` prefix to avoid native collisions; `target` redirects calls to the in-module function; only VM-supported constructs are used (`[]` literals instead of `array()`, key-value `foreach`, ternary, `...$args`); when a module is named exactly `array_keys`, `compileForeach`'s key-helper call uses `CALL_NATIVE` (native `array_keys`) so `renameCallTargets` cannot rewrite it to the module's own alias and cause infinite recursion.

> **Known VM limitation**: integer keys and numeric-string keys are stored separately in the VM's array representation (positional vs `['__vmnamed__', key, val]` tuples), so `array_keys([1,2,3,"2"=>4])` — where integer and string keys collide — differs from native PHP (native folds the `"2"` key into integer `2`). This stems from the VM array model itself, is an edge case, and such collisions are excluded from regression tests.

### 6.10 Bytecode-level real junk instructions (`--vm-junk`, P7)

**At compile time, at the bytecode level**, junk instruction sequences that **really execute** are injected into the compiled VM `Program` (implemented by `src/Vm/VmBytecodeJunk.php`; inserting dead code at the PHP token layer is the job of `src/Junk/GeneralJunk.php` — the two have been fully separate modules since v1.1.3).

**The essential difference from GeneralJunk (token-level runtime junk)**: junk instructions are VM **opcode sequences**, entering the gzip binary bytecode container and being masked along with shard encryption and opcode randomisation. A static analyst decompiling gets only scrambled opcode numbers, no PHP statements — much stronger against analysis.

**Injection point** (in `RuntimeGenerator::buildArtifact`, after all segments are merged and before `collectUsed` / `remap` / control-flow flattening):

- The junk opcodes then join the "used opcode set" — assigned random numbers and given handlers;
- And they can be further scrambled by the subsequent `flatten` / `stateMachine`, stacking with the jump table and shard encryption.

**Safety argument (never breaks semantics)**:

1. Only `PUSH_C` / binary ops / unary ops / `DROP` are used — **never** touching frame variables or any state outside the operand stack;
2. Each "fragment" starts with `PUSH_C` and ends with `DROP`; every operation consumes exactly one or two operands and pushes one back, so **net stack change is always 0** (each template has a balance derivation), leaving the stack identical before and after;
3. **No `JMP/JZ/JNZ`** (avoiding illegal jump targets that would break offsets), and no terminators / output / call instructions such as `RET/HALT/ECHO/EXIT/CALL`, so insertion before any legal instruction is reachable and harmless;
4. Generated deterministically from the salt: same salt + same source + same strength ⇒ byte-identical artifact (reproducible).

**Relative jump offset correction (the key correctness guarantee)**: injection changes instruction indices within a segment, and `JMP/JZ/JNZ` offsets are relative to the instruction's own index (absolute target = source index + 1 + offset, since pc is already +1 after fetch). `VmJunk::spliceSeg` snapshots every jump's old index and old absolute target **before** `array_splice`, then recomputes new offsets afterwards by shifting each of "old source / old target" by `len` depending on whether it is past the insertion point p:

```
new source = old source + (old source >= p ? len : 0)
new target = old target + (old target >= p ? len : 0)
new offset = new target - (new source + 1)
```

> ⚠️ An early implementation computed targets **after** `array_splice` using "already-shifted new indices" against "unshifted old offsets" (`t = j(+len) + 1 + d`), mis-computing offsets whenever source and target shifted differently — `for`/`while` loops with `junk=6` or more hung or timed out even in linear mode. After the fix, `junk` output matches the original in linear / flatten / stateMachine modes.

**Usage**:

```bash
php entry.php --input=app.php --output=app_obf.php --vm --vm-junk=6
php entry.php --input=app.php --output=app_obf.php --vm --vm-junk=12 --vm-flatten
```

- Strength: `0` (default, no injection) to `200` (the `junk` parameter of LayerDescriptor's `shell:vm`, default 0, cap 200). Large values are clamped per segment by `min(1, ceil(segment length / 4))` so a single segment is never swamped.
- GUI: layered `shell:vm` → **bytecode junk instructions** (integer, 0–200).

**Tests**: `tests/vm_junk.php` (120 checks, junk ∈ {0,3,6,12} × 3 modes × 10 cases) all PASS, plus an "injection delta" check (with junk>0 the Program's total opcode count strictly exceeds junk=0, 90/90 pass). Regressions `run_tests` / `hardening` / `vm_gen_probe` / `vm_module_link` are green with no fallback.

---

### 6.11 Native path: inlining outside VM presets (v1.1.9)

Before this, everything in §6 served VM bytecode only: `linkModules()` inside `RuntimeGenerator::buildArtifact()` compiles matched modules into the same `Program` (with `inlineSource()` as the native fallback). Non-VM presets (`low` / `mid`) had no module linking at all. v1.1.9 adds the **native path**.

**New layer `shell:module_link`** (`Obfuscator::shellModuleLink()`):

1. `effectiveModuleDir()` resolves the directory (layered parameter `dir` > loose key `vm_linker` > built-in module directory);
2. `ModuleLinker::loadDir()` loads manifests → `matchSource()` seeds the hits from the main source;
3. **Transitive** `inlineSource()` collection: recursively inline other manifest modules referenced inside module bodies (e.g. `substr_replace` → `__mod_substr`), equivalent to the VM path's `linkRecursive`;
4. Build a rename table (hit name → manifest `target`, e.g. `strlen` → `__mod_strlen`);
5. `rewriteCallTargets()` rewrites call sites **only at function-call positions** — strictly matching `matchSource()`'s prevSig exclusions, so `->` / `::` / `new` / `function` / `use` positions are untouched;
6. Module native PHP source is **appended to the end of the file**, leveraging PHP's function-declaration hoisting (`function` is registered when the file loads), so call sites earlier in the file resolve correctly;
7. Recorded as the **innermost** SHELL layer (before `shell:vm`; the position relative to `shell:eval` is decided by the preset), so the inlined definitions and rewritten call sites are encrypted together by later eval / gz shells and never appear in plaintext.

**Preset coverage**: `low` / `mid` add `shell:module_link` after `ws` and before `shell:eval`; `high` / `max` / `ultra` keep `shell:vm` bytecode inlining. **Every preset from `low` upwards now enables module linking.** Loose-key builds (no `--level`) enable the native path only with an explicit `--vm-linker=<dir>`, so the default artifact shape does not change (see §11.23).

**Semantic difference from the VM path (important)**: the module PHP runs natively, no longer constrained by the "VM-compilable subset". This exposed a historical implicit dependency (see §11.23) — `strlen.php` used `while ($__s[$__i] !== null)` for the boundary, relying on the VM returning `null` for out-of-range string access; native PHP returns `''` without fatal, and `'' !== null` is always true → infinite loop. v1.1.9 changed it to `while (isset($__s[$__i]))`, safe under both VM and native.

**Tests** `tests/vm_module_link_native.php` (35 checks): low / mid runtime equivalence, plaintext minimal-layer inspection of "call-site rewriting + inlined definition", transitive inlining, switch-off (call sites untouched), and max / ultra (VM path) unaffected — all green.

## 7. Dynamic runtime generator: anti-analysis capabilities

`VmRuntimeGenerator` produces a **per-file dedicated** VM interpreter on every build, stacking several independent capabilities:

| Capability | Mechanism | Anti-analysis effect |
|------|-----------|-----------|
| **Opcode randomisation** | Each file maps logical opcodes to a shuffled set of random numbers (plus a `scramble` XOR) | Signature libraries assuming a fixed `1=ADD` fail outright; two files' opcode values differ |
| **Selective emission** | Only opcodes the source actually uses enter the runtime; unused instructions (e.g. `_PHPRUN`) get no handler at all | Smaller size, fewer signatures |
| **Obfuscated dispatch** | The main loop uses random variable names; `switch` case order is shuffled with decoy cases injected; opcodes are labelled with "XORed random numbers" | No plaintext `VmOp::ADD=10`; instruction semantics are hard to recover |
| **Binary bytecode container** | Opcodes + operands encoded as binary via **varint + operand type tags**, then gzipped | Plaintext instruction sequences cannot be read statically |
| **Independent shard encryption** | The gzip bytecode is split into N shards, each XORed with its own key and inlined as base64, decrypted and concatenated at runtime | One leaked key cannot recover the whole bytecode |
| **Constant-pool encryption** (on by default, `--no-vm-cpool` to disable) | Every `PUSH_C` literal operand moves into a separate constant pool (instructions reference indexes, duplicates deduplicated), encrypted with its own key (counter-mode SHA-256 stream derived from the `vm-cpool` salt) and embedded as base64 | The decoded instruction stream contains only indexes; extracting the shard keys (`$___KEYS___`) recovers the instruction stream but not the constants |
| **Jump-table scheduling** | `opcode ^ scramble` is mapped through a randomised jump table (`array(...)` literal) to case labels, with entries in random order | Execution order is scrambled; control flow is hard to linearise |
| **Control-flow flattening** (optional `flatten`) | At compile time, linear instructions are rewritten into generic `JMP/JZ/JNZ + state variable`, adding no new instructions | Destroys the original natural block structure |
| **State-machine obfuscation** (optional `stateMachine`, mutually exclusive with flatten) | On top of basic-block partitioning: salted non-contiguous state IDs + unreachable decoy states | More aggressive transition paths; decoy states distract analysis |

> Pick one of the two control-flow layers (`flatten` / `stateMachine`); both are more controllable than "runtime GOTO flattening" and easier to combine with the jump table and shard encryption.

---

## 8. Known pitfalls (fixed / to avoid)

- ✅ **A `for` initialiser must be an assignment**: early `compileFor` used `compileExpr` for the initialiser, swallowing the right-hand side of `$i=2` (pushing only `$i`, so the loop started at 0) — fixed with `compileExprOrAssign`, plus `for_init` / `for_nested` regressions.
- ✅ **A JMP back-edge must point at the condition**: the same fix corrected the off-by-one in `for` loop back-edge offsets.
- ⚠️ Cross-function variables are **not** polluted (each call has its own frame), but **top-level statements share one global frame** (same as PHP's global scope) — watch out for name reuse.
- ⚠️ `ARRAY_KV` pushes value→key when building associative arrays; the runtime reverses correctly, so iteration order matches native (guarded by the `arr_kv_order` case).
- ✅ **`match` expression token boundaries**: `StatementSplitter` used to split a trailing `;` into a separate `[';']` empty statement, making `parsePrimary(';')` fail; fixed by dropping top-level `;` during flush (bare empty statements are discarded). `parseMatch` now collects arm results with the brace/paren/bracket **balanced collector** `collectMatchArmResult()`, correctly handling nested `match` / calls / arrays in results (their inner `,` and `}` are no longer mistaken for arm separators / body terminators). Regressions `match_int/match_var/match_multi/match_assign/match_nested` added.
- ✅ **Curly-brace string interpolation**: paired braces from `T_CURLY_OPEN` were once mistaken for block boundaries and flushed, truncating interpolated strings; a dedicated interpolation depth `$iDepth` now tracks them, so `"$x"` / `{$arr['k']}` / `"${x}"` all compile correctly.
- ✅ **Three knock-on bugs in runtime argument binding** (fixed in v0.1.4):
  - **The NEW handler must not call the flatten closure directly**: `$__flatten` / `$___FLAT___` are not in the interpreter closure's `use` list (closures capture only explicitly declared variables), so `new C(...$a)` failed at runtime with "Value of type null is not callable" — the marker is now passed to `$__newObj`, whose internal `$__bindArgs` flattens uniformly.
  - **Named-argument reordering leaves key holes**: `f("x", c:"z")` reorders into `[0=>"x", 2=>"z"]` (skipping `$b`); filling defaults by `count()` appends them after the highest key, shifting all arguments — filling now checks `array_key_exists($i, ...)` position by position and `ksort`s the key order (the downstream `POP_V` prologue consumes by stack **position**).
  - **A by-ref parameter cannot receive the literal null**: `$__bindArgs($args, null, null)` triggers "Argument #3 ($cv) could not be passed by reference" — native class construction / native object method paths now go through a local variable (`$ncv = null`).
  - Also fixed two dual-runtime inconsistencies: the reference runtime's missing `ARG_NAMED` constant definition (making the handler dead code) and `SCALL`'s missing binding on the native class path (leaking the marker into native methods).
- ✅ **CALL_VAR artifact syntax defect (fixed in v0.1.7)**: the generated runtime's `caseBody(CALL_VAR)` wrote "bind arguments + call" as the comma expression `([$a,] = f(), g())` — PHP has no comma operator, so every artifact using a variable function call (`$f(...)`, including post-FCC calls) failed `php -l` (runtime symptom: `ParseError: Unclosed '{' ... does not match ')'`). Now an if/else structure (line-aligned with the reference runtime).
- ✅ **FCC dual-runtime consistency (added in v0.1.7)**: the first version of the `$___FCC___` / `$__fcc` closure missed "the closing parenthesis of the VM class-name FCC branch + the stray `;` after the `if(isset(...))` block", embedding the native-object FCC path inside the VM branch where it was unreachable and unbalancing the function body's braces — a whole-artifact syntax error. After the fix, the FCC probe passes 19 cases across three modes (flat_off/flat_on/sm_on) and the P8 feature matrix passes 10×6.
- ✅ **trait adaptation `insteadof` / `as` (added in v1.0.0)**: conflicts are resolved and aliases registered at compile time. Rules apply "insteadof first, then as" (independent of declaration order); conflict detection deduplicates by **root trait** (recursively expanding traits used inside traits), so the same trait imported via different paths does not conflict; an unqualified `as` judges ambiguity against the root set **before** rule application (matching native, where both rule orders are fatal). The following cases raise `VmUnsupported` and fall back the whole file to native, which then produces the real fatal error: unresolved method conflicts, ambiguous unqualified `as`, alias name collisions, missing/self-excluding insteadof sides, inconsistent trait property/constant/static-property definitions, `as` on constants (native treats it as a method alias and fatals), and illegal forms such as `as static`. Known gaps: the VM does not track visibility (`as protected pm` alias calls do not throw, native does); VM classes are not real PHP classes, so `ReflectionMethod` cannot see them (a pre-existing architectural limit unrelated to traits). Probes: 44 trait-semantics cases (35 native baseline + 9 added) × 4 drivers (reference runtime + three generated-runtime modes) all green; a trait-heavy real-pipeline file under `--vm` / `--vm --vm-p8` is byte-identical to native.
- ⚠️ **Variable ++/-- now uses a composite instruction sequence** (v1.0.0): simple-variable `++/--` no longer has a dedicated opcode but is composed from `PUSH_V/PUSH_C/ADD/SUB/DUP/POP_V`, matching native for numbers / null / arrays; however **string letter-carry** (`"a"++`) and **boolean increment** (`++true`) cannot be expressed by generic arithmetic and yield `TypeError` / `int` respectively. See §11.2.

---

## 9. Five architectural directions (P8)

Five **independently switchable** architectural hardening directions on top of the existing VM. All are off by default (`--vm-p8` turns them all on; each can be overridden), and with them on the artifact's output stays byte-identical to the original (`tests/vm_p8.php`, 77 assertions: semantic fidelity + anti-analysis effectiveness).

| Direction | CLI | Mechanism | Anti-analysis effect |
|------|-----|------|-----------|
| **arch_key_chain** key-chain shard encryption | `--vm-keychain` | Shard keys derive from a **hash chain**: `k0` = salt-derived master seed, `k[i]=sha256(k[i-1]‖i)`; at runtime they are derived shard by shard from the seed, and `keys` stores only the seed | A statically extracted single key cannot recover the other shards (forward chain); the key is not a single static string |
| **arch_builtin_replace** builtin name encoding | `--vm-builtin-replace` | Builtin names in `natTbl` are encoded as `base64(name XOR keystream)` during `packProgram` and written into the binary package, decoded inside `$___CALLFN___` at runtime | The decompressed bytecode blob **no longer contains** plaintext call names such as `strlen`/`array_map` |
| **arch_morph_runtime** runtime morphing | `--vm-morph` | Opcode dispatch adds **an independent second scramble layer** (salt-derived); the `jt` key stays in sync with the runtime's `$op^scramble2`; dispatch temporaries are randomised | The "dispatch maths" differ per build, removing the fixed dispatch fingerprint; artifacts across salts are structurally distinct (polymorphic) |
| **arch_fake_fog** decoy functions | `--vm-fake-fog=N` | N **never-called** stack-balanced fake functions (`_fg_<hex>`, no output, no variable writes, with 0–2 default parameters) are injected into the `funcs` table before `collectUsed` | Decoy opcodes join the "used set", drowning real instructions; the function table inflates and statistical features fail |
| **arch_probe_matrix** anti-analysis probes | `--vm-probe=N` | N self-check probes run before the interpreter (`dbg`: xdebug/phpdbg/uopz/runkit detection; `crc`: crc32 of the decoded blob compared with the build-time expectation); the decision value is spread over random variables, and a hit silently `exit(0)`s | Debugging / hooking / bytecode patching cannot produce correct results; always false and side-effect-free in normal standalone runs |

### 9.1 Combination and layering

- All five are **orthogonal** to `flatten` / `stateMachine` / `--vm-junk` / `--vm-linker` and combine freely; `tests/vm_p8.php` covers `all_linear` / `all_flatten` / `all_sm` all-on states.
- Layered JSON / GUI: `shell:vm` gains `keychain` / `builtinReplace` / `morph` / `fakeFog` / `probe` parameters (see `LayerDescriptor`).
- `--vm-p8` aggregate preset: `keychain` + `builtinReplace` + `morph` on, `fakeFog=6`, `probe=4`; each can be overridden.

### 9.2 Implementation notes / boundaries

- **keychain**: the chained decode prologue and `encryptChunks($blob,$n,$chain)` are strictly symmetric; an empty blob still guarantees `keys[0]` exists. When off, each shard derives independently (old behaviour).
- **builtinReplace**: the decoder is injected via a string into `$___CALLFN___`'s `use(...)` list (closure scope — explicit capture is mandatory); the `program` logic layer's `natTbl` keeps original names, only the packing layer encodes. When off, the artifact contains no extra code.
- **morph**: when off, `scramble2=0`, and dispatch `^0` is byte-identical to the old behaviour (default artifacts unaffected).
- **probe**: deliberately **no** "call-stack depth" probe — protected scripts are often embedded by third-party `require`/`eval`, so depth is not a valid debugging criterion (it would kill legitimate usage); only `dbg` (real debugger/hook sensitivity) + `crc` (bytecode-tamper sensitivity).

---

## 10. Binary container: varint integer round-trip (fix log)

`packProgram` encodes integer operands with **zigzag + varint** (`bWriteZig` → `bWriteVarint`).

**The old bug**: `bWriteVarint` contained `$v &= 0x7FFFFFFF`. Zigzag doubles the value (`n → n<<1`), so any constant with `|n| ≥ 2^30` crosses 2^31 when doubled and its top bit is silently chopped — the runtime unpacker (no truncation) reads a wrong value. Typical symptom: **the low 32 bits of a product happen to be right while the high bits are all wrong** (e.g. `119673848*1103515245` produced `3563099859194008` instead of `132061915695812760`), easily missed by tests that "look roughly right". LCG constants in user code (`1103515245`/`2246822519`/`3266489917`, …) fall right in that range, and the big-integer-constant regressions (`bigconst_*` in `tests/vm_gen_probe.php`) exposed it first.

**Fix**: remove the truncation (all call sites are non-negative: zigzag results / instruction counts / string lengths), keeping only a negative-value guard (`&= PHP_INT_MAX`, preventing an arithmetic-shift infinite loop, affecting no positive path). `tests/vm_gen_probe.php` gained 5 big-integer regressions (`bigconst_*`: raw product / mask ops / negative constant / LCG inside a function) across all three modes.

---

## 11. Release-by-release log (condensed)

> The Chinese original keeps the full line-by-line derivations. Each entry below states the change and why it mattered.

### 11.1–11.12 v1.0.0: `$this` / opcode compositing / compiler split / VM nesting

- `$this` is explicitly supported by the compiler inside instance methods (no runtime implicit injection; `LOAD_THIS` in the prologue), fixing methods that read properties.
- Property and subscript increments keep dedicated `INC_OP` opcodes while simple-variable `++/--` became composite sequences (§2). Known gaps: string letter-carry and boolean increment.
- The compiler was split into traits (`VmStmtTrait`, `VmExprTrait`, `VmClassTrait`, `VmCompileTrait`, …) so each syntax family has one owner; `VmUnsupported` is the single fallback signal.
- VM nesting layers (`--vm-layers=2+`) feed a VM artifact back into the VM compiler, stacking shells; used by `ultra`.
- Bytecode containers moved to varint + type tags + gzip + per-shard encryption, and opcode numbering became per-build randomised.

### 11.13–11.19 v1.1.0–v1.1.5: correctness and runtime hardening

- v1.1.0: `$this` in static methods, class-preserving VM (real class stubs + delegated method bodies), trait adaptation blocks, FCC.
- v1.1.1: hot-path rewrite (typed operand stack + `DISPATCH`/`SET_ST`), 2.5–2.6× faster on dispatch-dense code; the empty-body / missing-controller defects were fixed here.
- v1.1.3: JIT-friendly mode (`--no-vm-jit` to disable), junk modules split into `GeneralJunk` / `VmBytecodeJunk`.
- v1.1.4: in-loop anti-debug; on detection it does not `exit` but XORs a pollution factor into opcode decoding (no breakpoint-able branch, no patchable exit).
- v1.1.5: two-point shell-binding self-check (outer `define` + inner sha256 digest compared with `hash_equals`), so extracting the inner VM and running it standalone fails silently.

### 11.20 v1.1.6: native state-machine layer (state machine split out of the VM)

- Flattening moved into a reusable, statement-level layer usable **without the VM**, so `mid` gets it.
- Randomised state IDs, dispatch shape, loop header and label radix; tautological opaque predicate on each transition with a decoy dead end in the `else`.
- Covers function bodies and top-level segments; state variables avoid user variables and are `unset` after top-level use.

### 11.21 v1.1.7: class-file syntax compatibility / anti-LLM comments / state-machine `use` barrier

- Closures, arrow functions, `match` and `yield` inside class files now compile to bytecode; `new \app\controller\Shop()` (`T_NS_SEPARATOR`) is parsed via `resolveClassRef()`.
- `ReflectionMethod::setAccessible(true)` fixes `cannot access private method` on `$this->privateMethod()`.
- Contradictory comment clusters: 12 → 22 themes, 3/6/12 clusters per shell, safe-boundary whitelist (no `ASBoom` fusion).
- `low` drops the state-machine layer to stay close to native.

### 11.22 v1.1.8: VM symbol ciphertext

- All strings inside the container (class/method/function/variable/property/constant names, exception-table fields, symbol operands) are encrypted with a per-file key derived from the source content.
- Container type tags are randomised per build (previously a fixed `NULL=0/INT=1/STR=2/…` fingerprint).
- Cost: +0.31% size, runtime difference < 1% (unmeasurable).

### 11.23 v1.1.9: the third "bad salt" class — bare `!0` / `!1` expression boundaries

- `true → !0` must be parenthesised: a bare `!0` is an expression, not an atom, and shell-level token re-compression can rebind it with neighbouring operators (verified breakage under specific salts).
- Introduced the native module-link path (`shell:module_link`) and fixed `strlen.php`'s out-of-range loop (`!== null` → `isset`), which only surfaced once modules ran natively.

### 11.24 v1.2.0-dev: per-build dynamic ISA + vulnerability hunt

- Logical opcode → number mapping became per-build with decoys; a dedicated hunt for correctness holes in the compiler (mostly array-key and interpolation edges).

### 11.25 v1.2.0: per-layer inlining, three-line counter-measures, advanced mixed templates

- `--inline-each-layer`: module linking after every shell layer, with a per-layer `_ml<layer><salt>_` prefix to avoid redefinition.
- `shell:anti_strip` interlock (anchor grid across K layers), `anti_tamper` chained hashes + multipoint checks + plaintext-blob integrity gate, `anti_debug` `func2`/`ini2`/`response`/`multipoint`.
- `InlineHtml::normalize()` rewrites mixed PHP/HTML (`<?=`, alternative syntax, heredoc HTML, `?><?php`, PHP in attributes/`<script>`) into pure PHP before any shell.

### 11.26 v1.2.1: full modern-PHP syntax in the VM/ultra chain + six correctness fixes

- The VM compiler chain now accepts the full modern syntax set; six classes of correctness fixes (prologue ordering, `use` handling, expression-statement boundaries, etc.).

### 11.27 v1.2.2: aggressive logical transform (`logic` layer) wired in

- Type-safe identity restructuring anywhere; every rewrite validated by value substitution at build time, failures keep the original.
- Constant-expression contexts use bitwise-only forms; `declare()` is skipped; the layer must precede `var` so new temporaries get renamed.

### 11.28 v1.2.3: aggressive logical transform strengthened

- Randomised identity bricks (`md5`/`sha1`/`strlen` first arguments from salt-derived strings), multi-encoding string reassembly.
- Decoy derived branches, dead-variable fake tails, line squeezing; all byte-identical output.

### 11.29 v1.2.5: super instructions / constant folding / function splitting

- **Super instructions (`--vm-super-isa=N`, default 3, max 24)**: frequent 2–4 instruction runs merge into one composite ISA instruction with flattened operands. The merged slot is padded with `NOP`s, so **segment instruction counts are unchanged** — jump offsets, exception-table `pc` ranges and generator segment-head indexes need no recomputation; the handler ends with `$___PC___ += n-1`. Merge positions are skipped when a jump target falls inside the range; segments containing `JMP_B` are abandoned; only instructions without control-flow side effects participate. Injection happens after constant-pool encryption and before remap, so `PUSH_C` operands are already pool indexes.
- **Constant folding (`constfold` layer, `--const-fold-level=1|2|3`)**: pure literal arithmetic evaluated at build time (`60*60*24 → 86400`), honouring PHP 8 precedence and right associativity (`2+3*4=14`, `2**3**2=512`), skipping division by zero / overflow / oversized results / type mismatches. It must run **before** `logic`. Three fixed edge classes: unary minus vs `**` (`-10 ** 2` is `-(10**2)`), folding from mid-expression must not cross a lower-precedence operator on the left (`A . B | C`), and partial folding is never allowed (it truncates outer operands).
- **Function splitting (`funcsplit` layer, `--func-split-level=1|2|3`, `--func-split-max=N`)**: extracts `echo` / `print` / `$v = EXPR;` into top-level functions with in-place calls. Arguments are always by value (by-reference would silently define undefined variables as null and swallow warnings); expressions must be side-effect free; files with multiple/bracketed namespaces or `__halt_compiler` are skipped; at level 3 multi-statement merging requires every variable to be already assigned before the group.
- All three fall back conservatively and keep byte-identical output; whole-site builds (WordPress / ThinkPHP 8) produced artifacts that all pass `php -l`.


---

## New in v1.2.6: bytecode disassociation and interpreter morphing

### Equivalent-ISA splitting (`--vm-isa-split=N`)

Superinstructions (v1.2.5) merge many instructions into one; bytecode disassociation does the opposite — one semantic opcode gets N **functionally identical** physical codes, used in rotation during remap. The same `PUSH_V` therefore appears as several different opcodes in the bytecode, breaking static 1:1 mapping and instruction-frequency statistics.

- Primary codes still come from 1..250; aliases come from a salt-shuffled 251..2047 pool. XOR is a bijection, so dispatch keys never collide and the serialisation protocol is unchanged.
- Each alias gets its own case label, but labels of one semantic opcode **share a single handler body** (`case A: case B: body`) — code size barely changes and dispatch stays one hash lookup, i.e. zero runtime cost.
- `GEN_HEAD` (generator segment-head literal check) and `NOP` are excluded, so opcode-literal-dependent logic stays intact.
- Orthogonal to constant-pool encryption, superinstructions, flattening, state machines and bytecode junk. `high`/`max`/`ultra` presets use 2/3/4 shapes.

### Interpreter morphing

The generated interpreter PHP gets one more post-processing pass:

1. every decimal `T_LNUMBER` becomes hexadecimal (compile-time constant, identical opcode);
2. eligible ASCII single-quoted strings become `\xHH` double-quoted strings (strings containing `$`, `\`, non-ASCII bytes or oversized are skipped to avoid interpolation/escaping ambiguity);
3. unreachable pseudo-logic blocks are woven between the closures (`strlen(str)<0` is always false), disguised as key-schedule / checksum construction;
4. misleading comments (compatibility shim / legacy path) are inserted.

None of these change opcode order or operands, and a given salt stays byte-for-byte reproducible.

### Division of labour with 3-way decoys

VM decoy cases (existing feature) and the PHP-token-level `decoy` layer (new in v1.2.6) are separate mechanisms: the former adds never-hit case labels to the dispatch table, the latter rewrites **user source statements** into three-branch structures (varied true conditions, look-alike dead branches). They stack without interfering.
