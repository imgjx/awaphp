# AWAPHP

**English** · [简体中文](README.zh-CN.md)

![Version](https://img.shields.io/badge/version-1.2.10-orange)
![PHP](https://img.shields.io/badge/PHP-7.4~8.5-777BB4?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)

> A `tokenizer`-based PHP source obfuscator and runtime protector: it rewrites readable PHP into a form that is **semantically identical, still runnable, and extremely hard to reverse statically**.

Every artifact passes `php -l`, and **the runtime output is byte-for-byte identical to the original**. The goal is to raise the cost of reverse engineering, never to change program behaviour.

---

<span style="color:red">

> ⚠️ **Note on Huorong (火绒) flagging `VirtualMachine.php` as a webshell**
>
> Huorong's heuristic engine has flagged `src/ControlFlow/VirtualMachine.php` as a webshell on several machines. The project is **100% open source**: the VM interpreter only compiles PHP into a custom ISA bytecode and dispatches it instruction by instruction. It **performs no malicious action, makes no network calls, writes nothing to disk, and opens no back-connection**. The heuristic simply matches the "runtime string assembly + `eval` dispatch" pattern that normal obfuscators share with webshells — a textbook false positive.
>
> **If your IDE or antivirus flags `VirtualMachine.php`, just add it to the trust list / whitelist.** The same applies to any other false positive: the full source is in this repository and can be audited.

</span>

---

## What it is

A source-protection tool for PHP projects. The core idea: run **reversible, semantics-preserving** transforms over the token stream produced by `token_get_all`, then wrap the result in multiple layers of **runtime protection shells**. It depends on no third-party service, ships no keys to a remote server, and keeps all logic local under `src/`.

Design goals:

- **Correctness first.** If a transform breaks `php -l` or changes the runtime result, it is repaired in place (prologue normalisation, literal-form correction, …) and re-validated; only if the repair fails does the pipeline roll back to the previous safe version. Under normal conditions **every shell layer survives** — nothing is silently dropped.
- **Token-level, not string-level.** All obfuscation works on the parsed token stream, avoiding fragile regex rewrites that break code.
- **Reproducible.** Given the same input and salt, the produced code body is byte-for-byte identical (only the header timestamp differs).
- **Gradual.** Every protection class is an independent switch; combine them as the project requires.
- **Optional performance cost**, six presets from "almost free" to "resists dynamic analysis". `min` is the **closest to native** preset: no anti-debug, no shell, no expensive derived decryption (identifier renaming / number forms / multi-scheme string encoding / comment stripping / whitespace squeezing / compile-time constant folding only — the artifact is still a plain PHP file). Measured: 1.01× on pure computation, same speed as native on a real ThinkPHP 8 app, and a *smaller* artifact. `low` / `mid` stack every "does not grow with statement count" protection (full token-level set + EVAL / struct-poison / anti-tamper shells) without the VM, so their runtime cost is a **single decode pass** — measured 6.3 ms / 11.1 ms. The VM is enabled from `high` upwards; v1.1.1 rewrote operand-stack encoding and collapsed the state-machine dispatch from 5 instructions into 1, making the same code **2.5–2.6×** faster than 1.1.0; v1.1.3 inlined the stack encoding into the push closure and kept the interpreter a flat mega-function for OPcache tracing JIT, gaining another ~4% (see [`docs/en/PERFORMANCE.md`](docs/en/PERFORMANCE.md)).

---

## Features

### Identifier obfuscation
- Variable / function / class / interface / trait names are renamed with globally consistent replacement.
- **FQCN-aware class renaming** (single-file mode): renaming is done at fully-qualified-name granularity, `app\service\PriceCalculator → app\service\_x7f3a…`, and the declaration point re-registers the original name through `class_alias` (reflection / string class names / `class_exists` keep working). **Directory-level builds (whole-system mode) keep class names intact** — class renaming conflicts with the PSR-4 "namespace + filename" contract, so project mode relies on the framework autoloader and only renames symbols you explicitly allow. Symbols that must stay unchanged are handled with the **whitelist** (see [`docs/en/THINKPHP8_GUIDE.md`](docs/en/THINKPHP8_GUIDE.md)) — no classmap autoloader is required.
- **Cross-file symbol consistency**: `--manifest` runs a two-pass scan to build a global symbol table, so symbols align across files under the same salt and incremental rebuilds are supported. **Files obfuscated independently under the same salt still agree globally** (mappings are derived deterministically from the salt).
- **Conservative exclusion of dynamic symbols**: any function / class whose name appears as a string literal (`call_user_func('f')`, `new $cls()`) is never renamed — eliminating the "passes `php -l`, explodes at runtime" class of bugs.
- **Plausible naming**: guard / decoy / version-check variables are drawn from business word pools, so artifacts no longer contain tell-tale strings like `__awa_*` or `$__CODE`.

### Token-form obfuscation (syntax-faithful, semantics untouched)
- **Keyword case randomisation**: `ecHo` / `WhIlE` / `fUnCtIoN` are all legal and kill keyword-based grep / regex signatures outright.
- **Equivalent literal forms**: `true→!0`, `false→!1`; `array(...)→[...]`. (No `(int)↔(integer)` cast-alias swapping: PHP 8.5 deprecates non-canonical casts, frameworks escalate that to an exception, and synonym pairs do nothing against a decompiler.)
- **Safe quote flipping**: `'a'↔"a"` only when there is no escape, interpolation, or conflicting quote; otherwise left untouched.
- **heredoc / nowdoc rewriting** (`--token-heredoc`): pure literal strings become `<<<'ID' … ID`.
- **Top-level decoy comments** (`--token-decoy=N`): misleading comments (fake licence cache, fake integrity probe) that pollute static reading.

### String and number encoding
- Segmented base64 encoding (`--str-length`).
- **Runtime XOR assembly** (`--str-xor`): every string gets its own key, so breaking one does not break the rest — much stronger against static scanning.
- HTML entity encoding (`--html`).
- Number rewriting to equivalent expressions (`--num-enc`): `0x` / arithmetic combinations instead of the literal.

### Control-flow obfuscation
- Opaque predicates, condition inversion with branch swapping, never-executed decoy branches (`--cfg=low|medium|high`).
- **GOTO state-machine flattening** (`--goto` / `--goto-cpx`): function bodies are rewritten into `while (1) { switch ($__s) { … } }`, with nesting and chunking.
- **Native state-machine layer (`statemachine`, new in v1.1.6)**: generic control-flow flattening **split out of the VM bytecode layer**, so it does **not** require the VM shell — the `mid` preset (no VM) gets the same strength. Every transition is wrapped in a **tautological opaque predicate** `if(($o^$o)===0){$s=$next;}else{$s=$trap;}` (the `else` is a dead end into a self-locking decoy), mixed with **decoy dead states** (unreachable labels; forcing a jump into one locks the program). Randomised state IDs (non-contiguous), dispatch shape (`switch` / `if-elseif` chain), loop header (`while(1)` / `while(true)` / `for(;;)`), and label radix (decimal / hex) defeat template matching. **Covers function bodies and top-level statement segments** (plain scripts included); state variable names are **collision-free** with user variables, and top-level state is `unset` immediately after use so globals stay clean. Switches: `--sm-complexity=N` (statements per dispatcher, 2–32), `--sm-passes=N` (nesting depth, 1–5), `--sm-traps=N` (decoys, 0–4), `--no-sm-opaque` (disable the opaque predicate). **Since v1.1.7 the `low` preset drops this layer** (the entry preset keeps "decode once at load, then run at native speed"); currently enabled in `mid` / `max` / `ultra` only; `high` / `min` never had it.

### Semantics-equivalent rewriting (anti static pattern matching)
Type-safe, identity-preserving rewrites that destroy the code shapes static analysis relies on (`--semantic`, strength `--semantic-strength=1|2|3`, applied progressively):
- **if/else inversion + swap**: `if (C) T else E` ⇄ `if (!(C)) E else T`.
- **De Morgan**: `!(A && B)` ⇄ `!A || !B`.
- **Ternary inversion**: `C ? T : E` ⇄ `!C ? E : T`.
- **for → while**: a `for` without `continue` becomes an equivalent `while`.
- Deliberately **excluded**: float- or overflow-unsafe "clever" rewrites such as `a*2→a<<1` or `a+1→-~a`. `php -l` is re-run after each round; failures roll back to the previous version.

### Deep anti-AST (`anti_ast`)
Rewrites **constant literals in statement position** from "readable at a glance" to "you have to compute it" (`--anti-ast` or the `anti_ast` layer in a layered config):
- `echo 'ok';` → accumulator + identity expressions (`strlen(md5(1))===32` / `ord('A')===65` / `base64_decode(base64_encode(x))` and other equivalent bricks).
- Every rewrite is **verified byte-for-byte at build time via `eval`** (output before vs. after); failures keep the original statement — nothing risky ever ships.
- Directly breaks the "grep the string constant to locate sensitive logic" workflow.
- **v1.1.3 hardening**: string constant output became a two-stage "decode one part, concatenate another" form (`base64_decode('p1') . base64_decode('p2')`, split point derived from a `crc32` hash, different on every build) + a holding variable + extremely cheap identity operations (`$s = $s ^ 0;` for the integer accumulator with cheap noise / `$s = (string)$s;` for strings / `$s = $s . '';`), giving higher density at **negligible extra cost**. `low` / `mid` / `high` / `max` / `ultra` include anti_ast by default (`min` deliberately excludes it to stay free).

### Logical identity transform (`logic`)
If the actual logic and output stay **byte-for-byte identical**, the code is mangled beyond recognition: numbers, comparisons, booleans and strings all become type-safe, value-identical equivalent expressions, and variables across statements are entangled (`--logic`, strength `--logic-intensity=1|2|3`):

- **Identity-brick randomisation**: the first argument of `md5` / `sha1` / `strlen` becomes a salt-derived random string (no more uniform `x` / `1`); strings are split by length and recombined from `base64_decode` / `hex2bin` / `str_rot13`; temporary variables drop from 5 to 2, with longer names and global uniqueness checks. Every rewrite is verified value-by-value at build time; failures keep the original.
- **Variable entanglement net** (strength 3): simple assignments / `echo` expand into "create two temporaries → compute a bit-operation chain → reuse in following statements" (printing `10086` becomes a chain of temporary computations), and the new variables are renamed again by the identifier layer.
- **Derived multi-branch decoys** (`--logic-decoy`): some `echo` statements spawn always-true / always-false branches whose `else` prints a derived fake value — a reverse engineer cannot tell the real output from "what this branch prints".
- **Dead-variable tail fake values** (`--logic-fake-tail`): temporaries are overwritten in place two statements later with random numbers / strings, simulating "still in use"; it does not append anything at end of file, so mixed HTML and bracketed namespaces stay safe.
- **Line squeezing** (`--logic-minify`): strips whitespace and comments, inserting real whitespace tokens only where lexemes would otherwise merge.
- **Per-shell aggressive transform** (`--logic-shell`, on by default for `ultra`): right after each shell wraps the payload, **that layer's decode logic is itself re-subjected** to logical identity restructuring — strip any layer and you see mangled identities. Inner ciphertext payloads larger than `--logic-shell-maxlen` (default 512 bytes) and byte / anchor / VM-sensitive layers are skipped automatically; strength `--logic-shell-intensity=2|3` (1 is promoted to 2); `php -l` is checked afterwards and failures roll back.
- **Wild brick library** (`--logic-wild`, independent switch, off by default): double base64 / `strrev` / rot13 combinations, identity empty-string noise, integer `ord` / `hexdec` / `bindec` / `abs` / shift chains, add/subtract entangling chains. Deeply encoded output slows VM compilation noticeably, so it is meant for VM-free presets such as `low` / `mid`; presets containing `shell:vm` should not enable it.
- **Extreme destruction** (`--logic-extreme`, independent switch, off by default): "save → fake → restore" on already-assigned user variables, leaving the final value intact — but it can define a previously undefined variable as null, hence off by default.

### Virtual machine protection (VM)
Compiles whole program segments into **custom bytecode** executed instruction by instruction by a built-in interpreter (`--vm`):
- **Module linking** (`--vm-linker`): calls matching the manifest are inlined into the same Program, so artifacts have no `include`, no module paths, and call relationships cannot be recovered statically. **Built-in modules** are enabled by default (`--vm-link-modules`): the packaged `src/Module/builtin/` replaces **70+** native functions such as `base64_encode` / `base64_decode` with custom implementations inlined into the bytecode, so call sites no longer expose native names (including `md5` / `sha1` / `crc32` — pure PHP rewrites that are byte-identical to native, purely "erase the function name from the artifact"). `--vm-linker=<dir>` points at a custom module directory.
  - **Since v1.1.9 this is no longer VM-only**: a native path layer `shell:module_link` (on by default in `low` / `mid`) rewrites matched call sites to the real in-module function name (`strlen` → `__mod_strlen`, …) and appends the module's **native PHP definitions** to the artifact (leveraging function-declaration hoisting) — inlining without a VM; **transitive** inlining is supported. `high` / `max` / `ultra` keep using VM bytecode inlining. Every preset from `low` upwards is covered. Loose-key builds need an explicit `--vm-linker=<dir>` to enable the native path, so the default artifact shape does not change.
- **Bytecode junk instructions** (`--vm-junk=N`): injects opcodes that really execute but keep the stack balanced and have no side effects, drowning the real logic.
- **Decoy functions / fake-fog** (`--vm-fake-fog=N`): never-called, stack-balanced fake functions that inflate the function table.
- **Split key chain** (`--vm-keychain`): `kᵢ = sha256(kᵢ₋₁ ‖ i)` — one key cannot recover the others.
- **Constant-pool encryption** (on by default, `--no-vm-cpool` to disable): `PUSH_C` literals move into a separate constant pool (instructions reference indexes, duplicates deduplicated) encrypted with its own key — the decoded instruction stream contains only indexes, so even extracting the split keys yields no plaintext constants.
- **Builtin name encoding** (`--vm-builtin-replace`): plaintext call names such as `strlen` / `array_map` are salted, encoded and packed, then decoded at runtime.
- **Interpreter runtime morphing** (`--vm-morph`): a second independent scramble layer over dispatch; the dispatch maths differ per build, eliminating a fixed fingerprint.
- **Anti-debug / anti-tamper probe matrix** (`--vm-probe=N`): xdebug / phpdbg / uopz detection plus bytecode CRC self-check.
- **Instruction-set reduction**: 41 isomorphic opcodes merged into 6 parameterised opcodes (`BINOP` / `UNOP` / `CAST_OP` / `INC_OP` / `VAROP` / `ARRAY_BUILD`) with a hidden sub-id table, removing the "every opcode announces itself" semantic fingerprint.
- **Opcode combination execution** (`--vm-combo[=N]`): a single opcode is expanded into an **equivalent multi-instruction sequence** — `SUB→NEG+ADD`, `PUSH_C 2,MUL→DUP+ADD`, `PUSH_C 2,POW→DUP+MUL`, `BOOL_NOT→PUSH_C 0,EQ` — destroying the 1:1 opcode mapping and resisting decompilation; N=1–3 is the strength (default 2), with runtime equivalence covered by the full `tests/vm_gen_probe.php` regression.
- **Complex-syntax fidelity** (extended in v1.0.9): `list()/[]` destructuring supports **expression keys** (string-layer encoding still compiles); generator methods / `yield from` / closure generators / Fiber generator bridges; non-virtual `parent::` dispatch; closure `use` write-back; trait method bodies; heredoc interpolation. The few unsupported statements fall back to **EVAL_STMT statement-level execution** — class references in the fallback text are automatically **FQCN-ised** (`use` alias expansion, namespace completion) and resolve normally inside the eval unit (global scope); header prologue (`declare` / `namespace`) always stays first after normalisation.
- **JIT-friendly mode (on by default, v1.1.3)**: the operand-stack encoding is **inlined into the `MCPUSH` closure** and the whole interpreter stays a **flat mega-function**, making it easier for OPcache tracing-JIT to compile and saving one nested closure call per push (≈26 ns/instruction, hundreds of ms over a million pushes). `--no-vm-jit` restores the older "`MCENC` encoding closure + `MCPUSH` calls it" implementation with byte-identical semantics (objects still go through the plaintext slot `$___MCPLAIN___`, property write-back is lossless).
- **In-VM anti-debug (on with `--vm-probe`, v1.1.4)**: inside the **dispatch loop**, every 512–8192 instructions (salt-derived interval) the interpreter re-checks for debuggers / hook extensions (phpdbg SAPI, uopz, runkit7, xdebug actually attached); on a hit it does **not `exit`** — it XORs a salt-derived pollution factor into opcode decoding, so the instruction stream silently derails and terminates early. **No comparable branch to break on, no `exit` to patch**; zero semantic impact in normal environments, ≈2–3 ns/instruction. `--no-vm-inloop` disables it.
- **Advanced VM environment self-check (detects standalone execution after unpacking, on by default, v1.1.5)**: the interpreter validates the "shell binding marker" at **two points** — initialisation and inside the dispatch loop. The obfuscator `define()`s a salt-derived constant at the outermost layer; inner layers only hold its **sha256 digest** (`hash_equals` on digests — **forging it is impossible without the preimage**). Extracting the runtime closure + bytecode and running them standalone, or deleting the outer `define`, makes the self-check fail and writes the pollution factor into opcode decoding → **no output, no error**, with no breakpoint-able or patchable `exit`. `--no-vm-selfcheck` disables it.
- **Class-file syntax compatibility (v1.1.7)**: closures / arrow functions / `match` / `yield` inside **files that also declare classes** are now compiled into VM bytecode as well. Previously such files degraded wholesale into a `gz+base64` opaque payload because `parseNew()` could not handle `new \app\controller\Shop()` (`T_NS_SEPARATOR`), leaving method bodies merely `_PHPRUN`-executed rather than VM-protected. Full FQCNs are now collected and expanded through `resolveClassRef()` (`use` aliases / current namespace); the runtime `$___CALLMETHOD___` and `SCALL` / `SCALL_VAR` use `ReflectionMethod::setAccessible(true)`, fixing `cannot access private method` when calling `$this->privateMethod()`. A combined class fixture is byte-identical across `high` / `max` / `ultra`, with zero plaintext markers in the source.
- **Symbol ciphertext + container-format randomisation (on by default, v1.1.8)**: **all strings** inside the bytecode container — class / method / function / variable / property / constant names, the three exception-table fields, symbol strings in instruction operands — are stored encrypted with a key derived from the source file content and restored when unpacking at runtime. Previously the container was encrypted as a whole but symbol names inside were **plaintext**: extracting the split keys and running `strings` revealed the entire symbol table (business structure, naming style, class/method relationships). Now even an extracted key leaves only ciphertext, and keys are **never reused across files** — the key from artifact A cannot open artifact B. The container's **type-tag bytes are also randomised per build** (previously a fixed `NULL=0/INT=1/STR=2/BOOL=3/FLT=4/SEG=5` sequence, a fixed container fingerprint any generic unpacker could apply). Every build now has a different structure while the same salt stays reproducible. Switch: `--no-vm-symbols` (disables only symbol ciphertext). Covered by `tests/vm_symbols.php` (28 assertions).

### VM acceleration: super instructions + compile-time folding (v1.2.5)

- **Super instructions** (`--vm-super-isa=N`, default 3, 0 = off, max 24): frequent 2–4 instruction runs are merged into **one new composite ISA instruction**, with the sub-instruction operands flattened after it. One fetch + one dispatch now executes what used to be n instructions, cutting main-loop overhead (array reads + two XORs + jump-table lookup + switch dispatch); the bytecode also contains unfamiliar opcodes, invalidating instruction-frequency statistics and resisting decompilation.
  - **Correctness design**: the merged slot is padded with `NOP`s so the **instruction count inside a segment is unchanged** — relative jump offsets, absolute `pc` ranges in the exception table `exTab`, and generator segment-head indexes **need no recomputation**; the super handler ends with `$___PC___ += n-1` to skip the padding.
  - **Safety boundaries**: a merge position is skipped whenever a jump target falls inside the merged range (code entering mid-sequence); a segment containing a jump-table absolute jump (`JMP_B`) is abandoned entirely; only instructions with **no control-flow side effects** participate (stack ops / arithmetic / bitwise / comparison / boolean / output / cast) — jumps, calls, returns, `yield`, closure creation and exception handling never do.
  - Injection happens **after constant-pool encryption and before remap**, when `PUSH_C` operands are already pool indexes that the composite instruction can reuse.
- **Compile-time folding** (`constfold` layer, `--const-fold-level=1|2|3`): "simple but expensive" pure-literal arithmetic is computed **at build time** — `60*60*24 → 86400`, `1024*1024+512 → 1049088`, `'a'.'b'.'c' → 'abc'`. The runtime no longer performs the computation (fewer instructions, zero extra cost) and the source no longer shows the original working (impairing manual reading). It **must run before `logic`**: fold first, then let the logic layer obfuscate the result — the other order backfires. Only pure literals are evaluated, honouring PHP 8 precedence and right associativity (`2+3*4=14`, `2**3**2=512`); division by zero / overflow / oversized results / type mismatches are skipped; the folded result is still a literal, so constant contexts (`const`, parameter defaults) remain valid.

### Function-type code splitting (`funcsplit` layer, v1.2.5)

A block of PHP is wrapped into a function: `echo $i + $k . $str;` → `function _fs9ab12c34($i, $k, $str){echo $i + $k . $str;}` plus an in-place call `_fs9ab12c34($i, $k, $str);` (definitions are appended at file top level, calls stay in place).

- **Originally limited to three statement kinds** (extended to whole statements in v1.2.6): `echo …;`, `print …;`, `$v = EXPR;`; the expression must be **side-effect free** (variables, number/string literals, array index reads, arithmetic/bitwise/comparison/logic, ternary, array literals). Any function call, member access, `new`, reference, increment/decrement, `isset/empty`, `@`, interpolated string, heredoc, magic constant or `global/static` is rejected — moving those to another stack frame changes behaviour.
- **Arguments are always passed by value**: by-reference passing would silently create undefined variables as null and swallow the original `Warning: Undefined variable`, whereas by-value reads behave exactly like the original expression.
- Files with multiple namespaces / bracketed namespaces / `__halt_compiler` are skipped entirely (the function definition cannot be placed in the right scope safely).
- Levels `--func-split-level=1|2|3` (1: echo/print only; 2: adds simple assignments; 3: also merges consecutive echo/print into larger blocks), capped per file by `--func-split-max` (default 64, 0 = off). At level 3, multi-statement merging only happens when every variable in the group was already assigned before the group — otherwise evaluating all arguments at once would move an undefined-variable warning earlier and change the output order.

### Whole-statement splitting, multi-branch decoys and ISA polymorphism (v1.2.6)

- **Function splitting is no longer limited to `echo`/`print`/simple assignment** (`funcsplit` levels 2–3). Level 2 adds `return`, side-effect-free expression statements (function/method calls, `new`) and simple assignments. Level 3 additionally wraps **state-changing statements** — array/property writes, compound assignments, `unset`, `++`/`--` — by moving the whole statement into the extracted function and binding every variable **by reference, but only when it was provably assigned before the statement**. Undefined variables always stay by-value, so the original `Warning: Undefined variable` is never swallowed. `goto` labels, control-flow heads, closures, interpolation, `$this`, superglobals and class files with multiple/bracketed namespaces are skipped wholesale.
- **Multi-shaped 3-way decoys** (`decoy` layer, `--decoy[=N]` / `--decoy-level=1|2|3`, densities ≈12% / 25% / 45%; on by default in `high`/`max`/`ultra`): one true statement becomes `if / elseif / else` with **three paths and two unreachable decoys**. The conditions are **not the giveaway `($v===$v)||($v!==$v)`**: a salt-picked template from 12 business-looking checks carries the decision — `strlen(...) >= 0`, `crc32(...) & 1 ∈ {0,1}`, `substr()` returning a string, `count(...)` on an array, `in_array(...,[0,1],true)`, … with two structural variants (real path first, or real path in `else`). The true branch applies "same-kind substitution" — variables are copied into fresh locals and string literals into fresh variables before use — while dead branches use look-alike values (numeric last digit +1, ASCII suffix). A probe value is normalised to a scalar first, so objects/closures never trigger a `__toString` error. **Only `echo`/`print` statements are decoyed**: assignments and write-back statements (`$a[] = $b`, `$o->p = $v`, compound assigns) are deliberately skipped — same-kind substitution copies variables into locals, which is safe for reads but would silently drop writes to the original variable (caught by whole-site runtime testing).
- **Zero-cost bytecode disassociation** (`--vm-isa-split=N`, default 2, 1 = off, up to 4): several **functionally identical physical opcodes** are created for one semantic opcode and **actually used in rotation** across the bytecode. Static 1:1 opcode→handler mapping and instruction-frequency statistics break down. Each alias gets its own dispatch label, but aliases of the same semantic opcode **share one handler body** (`case A: case B: body`): measured 400k-iteration loop 1.95 s at split=1 vs 1.95 s at split=2 — no runtime cost (dispatch stays a single jump-table lookup), only a slightly larger table.
- **VM interpreter morphing**: the interpreter's own PHP is post-processed — decimal constants become hexadecimal, ASCII literals become `\xHH` escapes (both compile-time constants, zero runtime cost), and unreachable "key-schedule / checksum" blocks plus misleading comments are woven between the closures. The generator stays byte-for-byte reproducible for a given salt.

### Pruning as a perf layer, stronger Logic (v1.2.10)

- **`aggressive_prune` is now honestly a performance layer** (`--aggressive-prune`, on by default in `aggressive`): it folds the constant identities and dead branches **you** wrote, so the artifact stops recomputing them at runtime. It is **hard-pinned ahead of `logic`** — presets declaring it later are rewritten, because a pruner running after Logic would fold the obfuscator's own identities back to literals (protection destroying itself). "Logic cannot be parsed/accelerated by the pruner" is a structural guarantee, backed by two fallbacks: the layer idles when the pipeline reports it ran after `logic`, and idles when the token stream already carries Logic's building-block fingerprint.
- **Stronger Logic at zero runtime cost**: each integer sub-term gets a random zero-cost mask (`^0`, `|0`, `0+(…)`, `-0`); the trailing zero-noise term rotates through 6 shapes, one of which — `(in_array(1,[])?0:0)` — is **outside a static token evaluator's whitelist**, so a "fold everything back to literals" tool cannot evaluate the identity. The string encoder library grows from 3 to 5 shapes (`strrev`, `strrev∘base64_decode` promoted out of the `wild` tier). Decoy branches gain a **four-branch** shape (real path buried in the final `else` behind three decoys) and 4 more environment-shaped constant predicates.
- **Fixed — decoys swallowed by line comments**: the decoy rewrite slices a statement expression and appends its own `;` / `}`. When the mutual-comment layer had planted a `// …` note inside that statement (legitimate — e.g. between `echo` arguments), the slice kept the note but dropped its trailing newline, so the appended syntax landed *inside* the comment and whole branches vanished. `php -l` still passed; the artifact died with `syntax error, unexpected …` at runtime, several `eval()` levels deep. The slice now preserves a terminating newline whenever its last line carries a line comment.
- **Fixed — `anti_hook` with `strict=false`**: emitted `if(...){function f(){return false;}` — one closing brace short, producing `Unclosed '{'`. Every shipped preset uses `strict=true`, so the bug had never surfaced; any custom config setting `strict=false` got an unparsable artifact.

### Environment-shaped decoy predicates + high-tier VM dead-loop fix (v1.2.9)

- **Environment-shaped constant predicates**: decoy conditions now include PHP-version thresholds (`PHP_VERSION_ID > N0000`, N∈1..4), debug-extension probes that double as anti-debugging (an `extension_loaded()` check on a salt-derived non-existent name, always false → real branch always taken), calendar checks (`(int)date('Y') > N`, N∈1990..2025), and other "predictable reality" forms (`PHP_INT_SIZE >= 4`, `strlen(PHP_VERSION) > 0`, `PHP_EOL !== ''`, `defined('PHP_VERSION')`, …). They read as runtime environment validation to a static analyzer but are provably constant and independent of the host — byte-identical output across all levels and salts.
- **high-tier VM dead-loop / miscompute fixed**: the layers before `shell:vm` in `high` are now aligned with `max`; high no longer emits bad relative jumps that spun the dispatch loop or silently miscomputed loop accumulators. Tier strength is preserved (VM params and shell depth still differ from max).

### Zero-eval build safety + Logic-first pruning + VM interpreter morphing (v1.2.8)

- **Build-time compute-loop folding, no `eval`** (on by default, `--no-loop-fold` to disable): deterministic loops over literal bounds and pure local arithmetic (the classic 200k-iteration `for` loop) are folded into their result literals at build time by `ConstEval` — a token-level symbolic evaluator using PHP's native operators, **zero `eval`**. This matters for online obfuscation services: evaluating *user-uploaded* tokens with `eval` is a free webshell injector, and that surface is now gone. Measured on the bundled workload: ~12 ms → ~8.5 ms (**faster than native**), byte-identical output. Strict token whitelist + iteration cap + double-check; any uncertain loop is left untouched.
- **Aggressive pruning runs *before* Logic** (`--aggressive-prune` / `--level=aggressive`, off by default): it only folds the user's own constant identities and dead branches. It is deliberately placed ahead of the Logic layers so it can **never** collapse the obfuscator's own injected identities — no de-obfuscation toolkit handed to attackers. Opt-in only; `min`–`ultra` are unchanged.
- **VM interpreter Logic morphing** (new, `--no-vm-runtime-logic` to disable): the interpreter's own integer literals become compile-time bitwise identity expressions (`N → ((Ka) ^ (Kb))`) — readable opcode numbers disappear from the interpreter source at **zero runtime cost** (compile-time constants). All levels verified byte-identical.
- **Stronger Logic layers**: maximum safe intensities retained; four extra constant-true/false decoy templates add branch variety. **Runtime KDF keeps its original algorithm** (StructPoison rounds, unbounded VM cpool stream, unconditional honeypot delay) — no downgrade.

### ThinkPHP 8 whole-site `max` byte-identical + parallel builds (v1.2.7)

- **Official whitelist safe build**: `other/thinkphp8.config.php` now runs the full `max` stack over application code while copying framework infrastructure (`vendor/`, `config/`, `route/`, `public/`, `runtime/`, …) verbatim via `whitelist.dirs`. The result keeps the framework's name-based dispatch / PSR-4 / config loading intact while deeply obfuscating business code.
- **Whole-site runtime equivalence**: on the bundled complex ThinkPHP 8 fixture (500+ files), each file was executed in an independent process and its stdout + exit code compared byte-for-byte against the original — all business files match. A dedicated guard skips the aggressive `logic`/`decoy` passes only for PHP/HTML mixed templates (they could drop inline HTML literals), with no loss of strength elsewhere.
- **Correctness fixes across the layered pipeline**: top-level `return` propagation through every shell, CLI shebang preservation, PHP 8 attributes in `class_alias` insertion, dynamic member access (`$obj->{$p}`), assignment-lvalue protection in boolean/comparison rewrites, `continue N` / `break N` operands, `array()` method names, repeated-include function guards, and heredoc/interpolation-safe comment injection.
- **Parallel project builds** (`--jobs=N`, needs `pcntl`): the global symbol table is built once, then worker processes obfuscate files concurrently (32 cores → ~3.5 min for a 525-file `max` build vs ~12 min serial). Available both via `entry.php --jobs=N` and the one-click `other/thinkphp8-build.php --jobs=N`.

### Anti-LLM static analysis (Confrontation-LLM) — *just for fun*

> **Just for fun**: this module is a tongue-in-cheek "cognitive pollution" experiment. It is **not guaranteed to affect any real LLM or reverse-engineering tool**, does not improve confidentiality, and does not change program semantics. Do not treat it as a security control. For real protection rely on VM bytecode / packing / integrity checks.

Modern reverse engineering and audits increasingly rely on LLMs doing "semantic understanding + prioritisation" of obfuscated artifacts. LLMs tend to trust doc comments, so this module manufactures **misleading text features** (`--confrontation-llm`, strength `--confrontation-intensity=N`, 1–3):
- **Fake misleading comments**: inject fake `@deprecated` ("unused since v2.1, kept for backward compatibility"), `@see` ("see the alternative `CoreCrypto::decryptPayload()`"), `@todo`, `@fixme` ("temporary debug, remove before release") in front of real functions / classes, marking still-executing core logic as "abandoned / non-production".
- **Fake CHANGELOG blocks**: a `/** CHANGELOG … */` block in the file header claiming some "legacy decryption entry points" were removed — while they still execute — nudging an LLM to skip those critical paths.
- **Rare CJK scatter**: random rare Chinese characters as `//` comment fragments spread inside shells (function / class / statement boundaries), faking "Chinese business comments" and disturbing dictionary- or token-based statistics and summarisation.
- **Mutually contradictory comments / cognitive pollution** (`--mutual-comments`, on by default; `--mutual-intensity=N`, 1–3, default 2): inside each shell, assertions that **contradict each other** — the same code is labelled "deprecated, safe to delete", "the only live path", "for audit only" and "none of the three is trustworthy", so neither an LLM nor a human knows what to believe. **v1.1.7 enhancement**: theme library 12 → 22 groups, with more 3-way / 4-way clusters; distance within a cluster tightened from 1–40 boundaries to **1–3** (so they sit within a few lines, visible together); **3/6/12 clusters per shell** (was 1/2/4 pairs) with a raised cap.
  - **Safe-boundary whitelist**: comments are only injected where the preceding significant token ends with structural punctuation / a non-lexeme character. Multi-layer shells turn line comments into block comments, squeeze whitespace, and strip comments during string assembly — if a comment were the only separator between `use X AS` and `Boom`, stripping would fuse them into the fatal `ASBoom`. Risky boundaries are rejected at the injection point: better fewer insertions than one bad one.

> Every injection produces **pure comments only**, never cuts into strings / heredoc / existing comments, and never affects syntax or runtime. Distribution is derived deterministically from the salt, so the same salt reproduces. Injection points live inside the self-check / poisoning shells and are covered by the anti-tamper fingerprint.

### Packing
- GZ compression shell (`--gz`), multi-layer EVAL shell (`--eval` / `--eval-layers`), target-PHP-version shell (`--php-version`, supporting `7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 / 8.5`, default `8.1`).
- **Newline poison of 1/2 the source byte count** (`--newline-poison-divisor=2`, 10 KB source → ~5000 newlines): spread evenly across token boundaries, partly embedded in the longest base64 payloads, plus a header fallback — "strip whitespace / beautify" immediately perturbs the ciphertext layout.

### Project mode and whitelists (v1.1.0)
- **Directory builds keep class names**: in whole-system mode class names are not renamed (PSR-4 loads by "namespace + filename"; renaming breaks resolution), and the old `awaphp_autoload.php` classmap approach is **removed** — no autoload patch is needed anymore.
- **Seven-dimension whitelist**: `dirs` / `files` / `variables` / `functions` / `classes` / `constants` / `methods` (CLI: `--whitelist-dirs` / `--whitelist-files` / `--whitelist-vars` / `--whitelist-funcs` / `--whitelist-classes` / `--whitelist-consts` / `--whitelist-methods`). A match means either a verbatim file copy or a symbol keeping its original name.
- **Framework compatibility switches**: `--var-keep-props` (keep instance property names — ORM field mapping / accessors / `toArray()` depend on them), `--var-keep-params` (keep parameter names — route injection / DI / validators look them up by name).
- **ThinkPHP 8 out-of-the-box config**: `other/thinkphp8.config.php` (equivalent to the `high` preset + class renaming off + framework directories skipped + built-in whitelist).

### One-command build (ThinkPHP 8)
`other/thinkphp8-build.php` combines "scan the project → generate the whitelist → run the obfuscator" into a single command:

```bash
php other/thinkphp8-build.php --project=/var/www/shop --salt=team-salt-2026
```

It statically parses `app/` with the tokenizer (add `--scan=` for more) and automatically collects:

| Collected | Why the original name must be kept |
|---|---|
| All classes / interfaces / traits / enums (FQCN) | PSR-4 autoloading, `class_exists`, container bindings look up by name |
| All global functions | Helper / global functions are called by name |
| Top-level `const` | Constants are referenced by name |
| Controller public methods + magic methods | Routing dispatches on "controller/method"; renaming yields 404/500 |

`--dry-run` only scans and prints the whitelist, `--app-only` obfuscates `app/` only (fast iteration), `--output` sets the output directory (default `<project>_obf`).

### OPcache-assisted compilation (Zend bytecode)
After artifacts are written, an extra pass can compile them to **Zend bytecode** (`--opcache`):
- Some shared hosts (e.g. **EP hosting**) enable OPcache by default; after deployment the code can run directly as bytecode, raising the bar for source recovery and improving runtime efficiency;
- If the `phpc` extension is installed, a portable `.phpc` bytecode file is produced as well;
- If only OPcache is available, `opcache_compile_file` warms the runtime bytecode cache (web hosts hit bytecode on the first request);
- On unsupported environments the step is **skipped automatically without blocking** the main flow — equivalent to not passing the option.

### Runtime junk and execution entanglement (anti-stripping)
- **Runtime junk injection** (`--junk-runtime`, strength `--junk-intensity=N`): side-effect-free `crc32` / `base64_decode` / `strrev` computations inserted between real statements to dilute logic density.
- **Execution entanglement** (`--junk-tangle`): junk values **participate in real assignments** and are then cancelled by an identity — `$v = intdiv($v * $jt, $jt)` (integer-faithful). If an attacker deletes the junk definition line `$jt = ($k|1)`, the entanglement line degrades to `intdiv($v * null, null)` and throws `DivisionByZeroError` — suicide on tampering. Junk is upgraded from "removable noise" to "part of correctness", directly countering block-deletion cracking.
- **v1.1.3 module restructure**: the junk module was split into **two independent, extremely cheap** modules — `GeneralJunk` (token-level generic junk covering all non-VM code, driven by `--junk-runtime`) and `VmBytecodeJunk` (useless bytecode at the compiled-VM-bytecode level, per-instruction cheap, always stack-balanced, driven by `--vm-junk=N`). The old "VM-only vs. generic" tangle and the expensive dead code that slowed execution are gone; both are forced to be extremely cheap and never "freeze the machine".

### Integrity protection
- **Anti-debug** (`--anti-debug`): detects xdebug / phpdbg / uopz·runkit / blackfire·tideways / vld·parsekit / debug backtraces; **v1.1.5 added the `proc` group** — `TracerPid` from `/proc/self/status` (ptrace attached: gdb/lldb/strace) and `frida|xdl|substrate|libinject` in `/proc/self/maps` (injection hook frameworks) — and strengthened the `env` group (instrumentation traces in `LD_PRELOAD`/`LD_AUDIT`, **without killing legitimate preloads such as jemalloc just for being non-empty**); **v1.1.6** added `auto_prepend_file`/`auto_append_file` (the usual entry point for attaching unpackers) and `opcache.jit` to the `ini` group.
- **Probe syntax self-check (v1.1.6)**: probe groups are hand-assembled PHP source; a single misplaced parenthesis makes the whole artifact a parse error, with **zero build-time warnings** and exposure only when the artifact runs. Each probe group is now independently `php -l`-checked **at build time**; a broken group is **dropped entirely with an explicit warning** while the others stay active — damage is confined to the failing group, and **artifact runnability outranks probe coverage**.
- **Unpack detection (shell binding marker, v1.1.5)**: the outer layer `define()`s a salt-derived constant, inner layers verify only its sha256 digest; a missing marker means "VM inner layer extracted and run standalone / marker stripped" — the anti-debug guard terminates explicitly while the VM self-check pollutes silently (two defence layers).
- **Anti eval-hook** (`--anti-hook`): a runtime probe verifies that `eval` really works, detecting uopz / runkit hijacking.
- **Guards moved inside and hidden**: anti-debug / anti-hook guards sit in the **innermost** layer of the VM / EVAL / GZ shells, swallowed into ciphertext by later shells — stripping outer blocks cannot reach them. Self-check / licence binding stay outermost because they need the `__FILE__` plaintext anchor.
- **Self-checking anti-tamper** (`--anti-tamper`): masked self-check + segmented hashes + total-length check + a second check at shutdown.
- **Licence binding** (`--license-domain` / `--license-expire`): domain binding (web SAPI only, wildcard subdomains supported) + expiry, off by default.
- **Structural fingerprint poisoning shell** (`--struct-poison`, default in max/ultra): the file fingerprint (including the instrumentation-extension presence count) feeds directly into decryption key derivation — beautifying, instrumenting, or hooking `gzinflate`/`eval` all result in "runs fine but decrypts to garbage", with no comparable branch for a breakpoint and no `exit` to hook.
- **Newline poisoning shell** (`--newline-poison`, tunable via `--newline-poison-min=N` / `--newline-poison-ratio=F`): injects a large number of newlines (count = `max(original lines × 1/10, previous tier's floor of 2000)`), some as blank lines between statements and some embedded directly inside the longest base64 payload — **entangled into the code, not piled at the end**. Combined with struct-poison: in `mid`/`high`/standalone mode the newlines are **clearly visible** in the final file, so anyone who "instinctively strips whitespace / beautifies" the artifact either perturbs the ciphertext layout or, under `max`/`ultra` (where newlines are encrypted into the payload by `struct-poison`), misaligns it. Normal execution stays byte-identical to native.
- **Honeypot functions** (`--honeypot=N`): plausible high-value names such as `deriveMasterKey` / `verifyLicense`, never called in normal operation; a cracker calling them enters a 1.4 s deep loop and gets a fake key — fishing specifically for "run every function" automation.
- **GOTO decoy dead states** (`--goto-trap=N`, default 2): every state-machine dispatcher gets visually identical unreachable branches; forcing the state in a debugger locks the program in a dead loop.

### Engineering guarantees
- **Reproducible builds** (with a non-empty salt, all randomness derives from `Salt::derive/shuffle`).
- **Automatic rollback**: `php -l` is checked at the end of every pipeline; failures roll back to the previous safe version.
- **Strict output equality**: tests assert "execution result identical before and after obfuscation".

---

## Architecture

```
entry.php             CLI entry (argument parsing, directory recursion, output, php -l check)
config.default.php    default config template
src/
  Config.php          config normalisation (CLI/JSON/array) + validation
  Salt.php            obfuscation salt: _ + first N chars of md5(name+salt) (reproducible)
  Parser.php          token_get_all wrapper + adjacent significant-token queries
  Logger.php          logging (colour text / silence / json)
  Validator.php       php -l check + runtime equality comparison
  Obfuscator.php      orchestrator: wires modules, repeated encryption, GZ shell, rollback
  Variable/           variable renaming (globally consistent, skips superglobals/$$/heredoc…)
  Function_/          function renaming + call encoding (call_user_func indirect calls)
  Class_/             class/interface/trait obfuscation
  Token/              keyword case, equivalent literals, quote flipping, heredoc, decoy comments
  String/             segmented base64 string encoding (skips const/default param/case constant contexts)
  Number/             number rewriting to equivalent expressions
  Encode/             HtmlEncoder (entity encoding), GzEncoder (compression shell)
  Optimize/           comment stripping, dead code, whitespace/compress, serialisation hooks
  AntiDetect/         runtime string XOR assembly (per-string key)
  ControlFlow/        control-flow obfuscation, GOTO state machine, native state machine,
                      VM shell, EVAL shell, anti-debug, anti-hook, anti-tamper, decoy
                      functions, licence guard
  LogicTransform/     constant folding (ConstFolder), function splitting (FunctionSplitter),
                      logical identity rewrites (LogicRewriter)
  Vm/                 VM compiler, ISA definition, super-instruction planner, runtime generator
  Layer/              layer descriptors (LayerDescriptor) + registry (shared CLI param schema)
  Project/            SymbolTable (global symbol table) + ProjectBuilder (two-pass build)
  Support/            NameStyle (plausible naming), DynamicSymbols (conservative exclusion),
                      PhpVersion, StatementSplitter, InlineHtml, Random
```

**Obfuscation pipeline (inside → outside)**

```
token level (reversible, semantics-preserving): identifier renaming → control flow (cfg/goto)
  → constant folding → number/string encoding → function splitting → dead code/compression
        ↓
string-level shells (wrapped inside → outside, php -l checked at each step, rollback on failure):
  target PHP version shell → anti-debug probes → anti eval-hook guard → VM shell
  → EVAL multi-layer shell → GZ shell → decoy functions → copyright header
  → self-checking anti-tamper → licence binding guard
        ↓
  · anti-debug / anti-hook sit in the innermost encoding shell, hidden inside VM/EVAL/GZ
    ciphertext (deleting outer blocks cannot reach them)
  · self-check / licence binding need the __FILE__ plaintext anchor, so they stay outermost
  · OPcache-assisted compilation (--opcache) is a standalone post-processing step outside the
    pipeline, compiling to Zend bytecode after artifacts are written
anti-tamper baseline = all of the above (including the header comment); the runtime reads its
own file and verifies it
```

---

## Quick start

```bash
# Requirements: PHP >= 7.4 (8.x recommended) with the tokenizer extension

# Obfuscate a single file
php entry.php --input=app.php --output=app_obf.php \
    --var-length=8 --func-length=10 --class-obf \
    --str-length=4 --num-enc --html --gz --salt=mySalt --repeat=2

# Recursive directory obfuscation (whole-system mode + manifest incremental rebuild)
php entry.php --input=src/ --output=dist/ --salt=team-salt --manifest=dist/.obf-manifest.json

# Strongest protection: VM + one-shot hardening + anti-detection
php entry.php --input=app.php --output=app_obf.php --vm --vm-p8 \
    --str-xor --num-enc --anti-debug --anti-tamper --salt=team-salt

# Semantic-equivalent rewriting + execution entanglement
php entry.php --input=app.php --output=app_obf.php \
    --semantic --semantic-strength=3 --junk-runtime --junk-tangle --salt=team-salt

# v1.2.5: VM acceleration (super instructions + constant folding) and function splitting
php entry.php --input=app.php --output=app_obf.php --level=high \
    --vm-super-isa=6 --const-fold-level=3 --func-split-level=3 --salt=team-salt

# OPcache-assisted compilation to Zend bytecode (some hosts such as EP enable OPcache)
php entry.php --input=app.php --output=app_obf.php --vm --opcache --salt=team-salt

# ThinkPHP 8 one-command build (max + official whitelist, parallel on 32 cores)
php other/thinkphp8-build.php --project=/var/www/shop --salt=team-salt-2026 --jobs=32

# Performance benchmark (produces the table in docs/en/PERFORMANCE.md)
php tests/perf_bench.php --runs=5 --levels=min,low,mid

# Run tests
php tests/run_tests.php          # CLI integration: 55 checks
php tests/v125_features.php      # v1.2.5 features: 54 checks
php tests/v126_features.php      # v1.2.6 features: 50 checks
php tests/hardening_test.php     # hardening: 32 checks
php tests/vm_syntax_probe.php    # VM syntax probe: 656 tri-state cases
php tests/vm_gen_probe.php       # VM generator probe: 290 cases (incl. combo strengths)
```

> Far more switches are available than shown above; see [`docs/en/CLI_OPTIONS.md`](docs/en/CLI_OPTIONS.md) for the full list.

---

## Documentation

English:

- [`docs/en/PERFORMANCE.md`](docs/en/PERFORMANCE.md) — runtime overhead per preset, cost attribution (which switch costs what), real ThinkPHP 8 page latency, selection advice.
- [`docs/en/VM_FEATURES.md`](docs/en/VM_FEATURES.md) — VM design details, limitations and fix log (heredoc support, do-while fixes, ISA reduction, …).
- [`docs/en/THINKPHP8_GUIDE.md`](docs/en/THINKPHP8_GUIDE.md) — ThinkPHP 8 hands-on guide: whitelist mode, one-command build, `--var-keep-props` / `--var-keep-params`, baseline verification, common edges.
- [`docs/en/CLI_OPTIONS.md`](docs/en/CLI_OPTIONS.md) — full command-line switch reference.
- [`docs/en/DEBUG.md`](docs/en/DEBUG.md) — the `OBF_*` debug hooks.

Chinese: [`README.zh-CN.md`](README.zh-CN.md) and [`docs/`](docs/) (same five documents).

---

## Licence and contributions

Released under the **MIT licence**: you may freely use, copy, modify, merge, publish and distribute this tool and its source, provided the copyright and licence notices are retained.

Contributions and research are welcome under the same licence. Please use Issues / Pull Requests for suggestions and bug reports.

> Note: obfuscation aims to **raise the cost of reverse engineering**, not to make code unbreakable — keys ship with the artifact, and any code that can run in a sandbox can in principle be recovered statically or dynamically. It raises the engineering bar for copying / tampering / reverse engineering; it is not a security boundary on its own.

---

Author blog: blog.awaone.cn
