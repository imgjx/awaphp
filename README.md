# AWAPHP

**English** · [简体中文](README.zh-CN.md)

![Version](https://img.shields.io/badge/version-1.2.14-orange)
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
- **Optional performance cost**, six presets from "almost free" to "resists dynamic analysis":
  - `min` is the **closest to native** preset: no anti-debug, no shell, no expensive derived decryption — identifier renaming / number forms / multi-scheme string encoding / comment stripping / whitespace squeezing / compile-time constant folding only, and the artifact stays a plain PHP file whose decoding uses nothing but native `base64_decode` / `gzuncompress` / `chr` / `\xHH`.
  - `low` / `mid`: every "does not grow with statement count" protection is stacked (full token-level set + struct-poison / anti-tamper / anti-debug shells) without the VM, so runtime cost is concentrated in **one decode per request**; `mid` adds the state machine, honeypots and thicker shells.
  - `high` / `max` / `ultra`: function bodies are compiled into a custom ISA bytecode and interpreted by the VM, layered with super instructions, state machines, XOR string assembly and integrity checks; `ultra` nests the VM.
  - Measured numbers for every tier live in [`docs/en/PERFORMANCE.md`](docs/en/PERFORMANCE.md); tuning and mixing advice in [`docs/en/USAGE.md`](docs/en/USAGE.md).

---

## Features

### Identifier obfuscation
- Variable / function / class / interface / trait / enum names are renamed with globally consistent replacement.
- **FQCN-aware class renaming** (single-file mode): renaming is done at fully-qualified-name granularity, `app\service\PriceCalculator → app\service\_x7f3a…`, and the declaration point re-registers the original name through `class_alias` (reflection / string class names / `class_exists` keep working). **Directory-level builds (whole-system mode) keep class names intact** — class renaming conflicts with the PSR-4 "namespace + filename" contract, so project mode relies on the framework autoloader and keeps class names; symbols that must stay unchanged are listed in the **whitelist** (see [`docs/en/THINKPHP8_GUIDE.md`](docs/en/THINKPHP8_GUIDE.md)) — no classmap autoloader is required.
- **Cross-file symbol consistency**: `--manifest` runs a two-pass scan to build a global symbol table, so symbols align across files under the same salt and incremental rebuilds are supported. **Files obfuscated independently under the same salt still agree globally** (mappings are derived deterministically from the salt).
- **Conservative exclusion of dynamic symbols**: any function / class whose name appears as a string literal (`call_user_func('f')`, `new $cls()`) is never renamed — eliminating the "passes `php -l`, explodes at runtime" class of bugs.
- **Plausible naming**: guard / decoy / version-check variables are drawn from business word pools, so artifacts no longer contain tell-tale strings like `__awa_*` or `$__CODE`.
- **Framework compatibility switches**: `--var-keep-props` (keep instance property names — ORM field mapping / getters / `toArray()` depend on them), `--var-keep-params` (keep parameter names — route injection / DI / validators look parameters up by name).

### Token-form obfuscation (syntax-faithful, semantics untouched)
- **Keyword case randomisation**: `ecHo` / `WhIlE` / `fUnCtIoN` are all legal and kill keyword-based grep / regex signatures outright.
- **Equivalent literal forms**: `true→!0`, `false→!1`; `array(...)→[...]`. (No `(int)↔(integer)` cast-alias swapping: PHP 8.5 deprecates non-canonical casts, frameworks escalate that to an exception, and synonym pairs do nothing against a decompiler.)
- **Safe quote flipping**: `'a'↔"a"` only when there is no escape, interpolation, or conflicting quote; otherwise left untouched.
- **heredoc / nowdoc rewriting** (`--token-heredoc`): pure literal strings become `<<<'ID' … ID`.
- **Top-level decoy comments** (`--token-decoy=N`): misleading comments (fake licence cache, fake integrity probe) that pollute static reading.

### String and number encoding
- Segmented base64 encoding (`--str-length`).
- **Runtime XOR assembly** (`--str-xor`): every string gets its own key, so breaking one does not break the rest — much stronger against static scanning.
- **Mixed encoding schemes**: within one file `base64_decode` / `hex2bin` / `str_rot13` / `strrev` / `strrev∘base64` rotate deterministically from the salt, so "all strings are base64" is no longer a single regex away.
- HTML entity encoding (`--html`).
- Number rewriting to equivalent expressions (`--num-enc`): `0x` / binary / octal / add-subtract-multiply / bit-operation combinations instead of the literal — all compile-time foldable literal arithmetic.
- **No decoders inside loops**: in loops whose static iteration count exceeds the threshold (default 20), strings stay literal or compile-time concatenated and numbers use operator-level identities only — see "Logical identity transform" below.

### Control-flow obfuscation
- Opaque predicates, condition inversion with branch swapping, never-executed decoy branches (`--cfg=low|medium|high`).
- **GOTO state-machine flattening** (`--goto` / `--goto-cpx`): function bodies are rewritten into `while (1) { switch ($__s) { … } }`, with nesting and chunking; every dispatcher mixes in indistinguishable unreachable states (`--goto-trap=N`), so a debugger that forces a state jump locks itself in a loop.
- **Native state-machine layer (`statemachine`)**: generic control-flow flattening that does **not** require the VM shell, so the `mid` preset (no VM) gets the same strength. Every transition is wrapped in a **tautological opaque predicate** `if(($o^$o)===0){$s=$next;}else{$s=$trap;}` (the `else` is a dead end into a self-locking decoy), mixed with **decoy dead states** (unreachable labels; forcing a jump into one locks the program). Randomised state IDs (non-contiguous), dispatch shape (`switch` / `if-elseif` chain), loop header (`while(1)` / `while(true)` / `for(;;)`), and label radix (decimal / hex) defeat template matching. **Covers function bodies and top-level statement segments** (plain scripts included); state variable names are **collision-free** with user variables, and top-level state is `unset` immediately after use so globals stay clean. Switches: `--sm-complexity=N` (statements per dispatcher, 2–32), `--sm-passes=N` (nesting depth, 1–5), `--sm-traps=N` (decoys, 0–4), `--no-sm-opaque` (disable the opaque predicate). `mid` / `max` / `ultra` enable it.
- **Multi-way three-branch decoys** (`decoy` layer, `--decoy[=N]` / `--decoy-level=1|2|3`, density ≈12% / 25% / 45%): one real statement becomes `if / elseif / else`, two of which are unreachable decoys. Conditions are not the obvious `($v===$v)||($v!==$v)` but one of a dozen "looks like a business check" templates picked by salt: `strlen(...)>=0`, `crc32(...)&1∈{0,1}`, `substr()` returning a string, `count(...)` on an array, `in_array(...,[0,1],true)`, a PHP version threshold (`PHP_VERSION_ID > N0000`), a debug-extension probe (`extension_loaded()` on a salt-derived nonexistent name — always false), a calendar check (`(int)date('Y') > N`), `PHP_INT_SIZE >= 4`, `PHP_EOL !== ''` … Statically these look like runtime environment checks, yet they are constant and machine-independent. Two structural variants are mixed in (real branch first, or real branch in the `else`); the real branch gets the same treatment (variables copied into fresh locals, string literals assigned to a fresh variable before use), the dead branch holds look-alike fake values (last digit +1, ASCII suffix). Probe values are normalised to scalars first, so objects / closures never trigger a `__toString` error. Decoys are only applied to echo/print: assignments and write-back statements (`$a[] = $b`, `$o->p = $v`, compound assignment) are skipped — copying a variable into a local is safe for reads but silently drops writes to the original.
- **VM instruction-set polymorphic aliasing** (`--vm-isa-split=N`, default 2, 1=off, max 4): each semantic opcode gets several physically distinct but functionally identical codes, rotated through the bytecode. Static 1:1 opcode→handler mappings and instruction-frequency statistics stop working. Each alias has its own dispatch label but shares one handler body (`case A: case B: body`), so runtime cost is zero.

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
- String constant output becomes a two-stage "decode one part, concatenate another" form (`base64_decode('p1') . base64_decode('p2')`, the split point derived from a `crc32` hash and different on every build) plus a holding variable and extremely cheap identity operations (`$s = $s ^ 0;` / `$s = (string)$s;` / `$s = $s . '';`) — higher density at negligible extra cost.
- Every rewrite is **verified byte-for-byte at build time via `eval`** (output before vs. after); failures keep the original statement — nothing risky ever ships.
- Directly breaks the "grep the string constant to locate sensitive logic" workflow.

### Logical identity transform (`logic`)
If the actual logic and output stay **byte-for-byte identical**, the code is mangled beyond recognition: numbers, comparisons, booleans and strings all become type-safe, value-identical equivalent expressions, and variables across statements are entangled (`--logic`, strength `--logic-intensity=1|2|3`):

- **Identity-brick randomisation**: the first argument of `md5` / `sha1` / `strlen` becomes a salt-derived random string (no more uniform `x` / `1`); strings are split by length and recombined from `base64_decode` / `hex2bin` / `str_rot13`; temporary variables drop from 5 to 2, with longer names and global uniqueness checks. Every rewrite is verified value-by-value at build time; failures keep the original.
- **Zero-cost masks**: every integer brick gets a random `^0` / `|0` / `0+(…)` / `-0` wrapper; the trailing always-zero noise term rotates between six shapes, one of which — `(in_array(1,[])?0:0)` — sits **outside the whitelist of static token evaluators**, so a "fold everything back to literals" tool cannot evaluate the whole expression and the identity survives.
- **Variable entanglement net** (strength 3): simple assignments / `echo` expand into "create two temporaries → compute a bit-operation chain → reuse in following statements" (printing `10086` becomes a chain of temporary computations), and the new variables are renamed again by the identifier layer.
- **Derived multi-branch decoys** (`--logic-decoy`): some `echo` statements spawn always-true / always-false branches whose `else` prints a derived fake value — a reverse engineer cannot tell the real output from "what this branch prints".
- **Dead-variable tail fake values** (`--logic-fake-tail`): temporaries are overwritten in place two statements later with random numbers / strings, simulating "still in use"; it does not append anything at end of file, so mixed HTML and bracketed namespaces stay safe.
- **Line squeezing** (`--logic-minify`): strips whitespace and comments, inserting real whitespace tokens only where lexemes would otherwise merge.
- **Per-shell aggressive transform** (`--logic-shell`): right after each shell wraps the payload, **that layer's decode logic is itself re-subjected** to logical identity restructuring — strip any layer and you see mangled identities. Inner ciphertext payloads larger than `--logic-shell-maxlen` (default 512 bytes) and byte / anchor / VM-sensitive layers are skipped automatically; strength `--logic-shell-intensity=2|3`; `php -l` is checked afterwards and failures roll back.
- **Wild brick library** (`--logic-wild`, independent switch, off by default): double base64 / `strrev` / rot13 combinations, identity empty-string noise, integer `ord` / `hexdec` / `bindec` / `abs` / shift chains, add/subtract entangling chains. Deeply encoded output slows VM compilation noticeably, so it is meant for VM-free presets such as `low` / `mid`; presets containing `shell:vm` should not enable it.
- **Extreme destruction** (`--logic-extreme`, independent switch, off by default): "save → fake → restore" on already-assigned user variables, leaving the final value intact — but it can define a previously undefined variable as null, hence off by default.

#### Loop-local light injection (no per-iteration cost)

The regular `logic` bricks are **function-call shaped** (`strlen(sha1('…'))*N`, `base64_decode(…)` chains). Semantically they are pure values, but evaluation count is multiplied by the loop: a 20000-iteration `for` really runs SHA-1 20000 times.

- **Approach**: before rewriting, every loop's iteration count is estimated statically (`for` init/bound/step, the assignment before a `while`, `foreach` array literals / `range()`). Inside loops above the threshold (default **20**), literals in per-iteration regions (`for` condition and step, `while` / `do-while` condition, loop body) become **operator-level identities** — `(20000^0)`, `(0+7)`, `'ab'.'cd'`. PHP constant-folds these at compile time, so they emit **zero runtime instructions**; the source still contains no bare literal, the obfuscation shape remains, but no function call is amplified by iteration.
- **Outside loops unchanged**: outside a function's loops, and in loops at or below the threshold, the full brick set (function-call shaped) is still used.
- **Same rule in the string layer**: `StringEncoder` / `StringRuntimeAssembler` share the loop analysis (`LoopScanTrait`), so strings inside large loops are not XOR-assembled and decoded — they stay literal or compile-time concatenated, avoiding "N rounds, N decodes".
- **Strict boundaries**: only pure value literals are touched; constant contexts (`const` / parameter defaults / property defaults / `case` / `#[…]` / `declare`), jump-level operands (`continue 2`), and function / closure / arrow-function / class bodies are skipped (those literals do not execute per iteration inside the loop). Loops whose count cannot be estimated (`while (true)`, `while ($row = f())`) are treated as large — if it cannot be proven small statically, it counts as large.
- **Switches**: `--logic-loop-light` (on by default) / `--no-logic-loop-light` to disable; threshold `--logic-loop-threshold=N`. Enabled by default in every preset and in per-shell `logic` passes.

### Virtual machine protection (VM)
Compiles program segments into **custom bytecode** executed instruction by instruction by a built-in interpreter (`--vm`):
- **Module linking** (`--vm-linker`): calls matching the manifest are inlined into the same Program, so artifacts have no `include`, no module paths, and call relationships cannot be recovered statically. **Built-in modules** are enabled by default (`--vm-link-modules`): the packaged `src/Module/builtin/` replaces **70+** native functions such as `base64_encode` / `base64_decode` with custom implementations inlined into the bytecode, so call sites no longer expose native names (including `md5` / `sha1` / `crc32` — pure PHP rewrites that are byte-identical to native). `--vm-linker=<dir>` points at a custom module directory.
  - **Native path layer `shell:module_link`**: on by default in `low` / `mid`; rewrites matched call sites to the real in-module function name (`strlen` → `__mod_strlen`, …) and appends the module's **native PHP definitions** to the artifact (leveraging function-declaration hoisting) — inlining without a VM; **transitive** inlining is supported. `high` / `max` / `ultra` keep using VM bytecode inlining, so every preset from `low` upwards is covered. Loose-key builds need an explicit `--vm-linker=<dir>` to enable the native path, so the default artifact shape does not change.
  - **No name collisions in multi-file builds**: inlined function names carry a file-derived segment (`__ml<layer><filehash>_<name>`), so every artifact keeps a private copy — whole-site builds never hit `Cannot redeclare` on two files defining the same `__mod_sha1`, and the same source plus salt still reproduces byte for byte.
- **Super instructions** (`--vm-super-isa=N`, default 3, 0=off, max 24): frequently executed 2–4 instruction combinations are merged into one new ISA instruction with operands laid out in sub-instruction order. At runtime one fetch and one dispatch execute what used to be n instructions, bypassing most of the main loop; the bytecode also contains unknown opcodes, so instruction-frequency statistics break. Absorbed sub-instructions are **deleted**, and all relative jumps (`JMP`/`JZ`/`JNZ`/`DISPATCH`) and the exception table (`[tryStart, tryEnd, handlerPc]`) are recomputed during the build; jump targets and exception-table endpoints act as uncrossable positions, so execution can never start in the middle of a group. Absolute jump tables and instructions with control flow or side effects never participate. Its role is **anti-analysis at zero cost**: on/off differences at runtime sit inside the noise band, while the instruction stream can no longer be restored from a template.
- **Bytecode junk instructions** (`--vm-junk=N`): injects opcodes that really execute but keep the stack balanced and have no side effects, drowning the real logic.
- **Decoy functions / fake-fog** (`--vm-fake-fog=N`): never-called, stack-balanced fake functions that inflate the function table.
- **Split key chain** (`--vm-keychain`): `kᵢ = sha256(kᵢ₋₁ ‖ i)` — one key cannot recover the others.
- **Constant-pool encryption** (on by default, `--no-vm-cpool` to disable): `PUSH_C` literals move into a separate constant pool (instructions reference indexes, duplicates deduplicated) encrypted with its own key — the decoded instruction stream contains only indexes, so even extracting the split keys yields no plaintext constants.
- **Symbol ciphertext + container-format randomisation** (on by default, `--no-vm-symbols` disables only symbol ciphertext): **all strings** inside the bytecode container — class / method / function / variable / property / constant names, the three exception-table fields, symbol strings in instruction operands — are stored encrypted with a key derived from the source file content and restored when unpacking at runtime; keys are **never reused across files**, so the key from artifact A cannot open artifact B. The container's **type-tag bytes are randomised per build** (previously a fixed sequence, i.e. a fixed fingerprint any generic unpacker could apply).
- **Builtin name encoding** (`--vm-builtin-replace`): plaintext call names such as `strlen` / `array_map` are salted, encoded and packed, then decoded at runtime.
- **Interpreter runtime morphing** (`--vm-morph`): a second independent scramble layer over dispatch; the dispatch maths differ per build, eliminating a fixed fingerprint; the interpreter's own integer literals become compile-time bit-operation identities, so readable opcode numbers disappear from the source (zero runtime cost, `--no-vm-runtime-logic` disables it).
- **Anti-debug / anti-tamper probe matrix** (`--vm-probe=N`): xdebug / phpdbg / uopz detection plus bytecode CRC self-check.
- **Instruction-set reduction**: 41 isomorphic opcodes merged into 6 parameterised opcodes (`BINOP` / `UNOP` / `CAST_OP` / `INC_OP` / `VAROP` / `ARRAY_BUILD`) with a hidden sub-id table, removing the "every opcode announces itself" semantic fingerprint.
- **Opcode combination execution** (`--vm-combo[=N]`): a single opcode is expanded into an **equivalent multi-instruction sequence** — `SUB→NEG+ADD`, `PUSH_C 2,MUL→DUP+ADD`, `PUSH_C 2,POW→DUP+MUL`, `BOOL_NOT→PUSH_C 0,EQ` — destroying the 1:1 opcode mapping; N=1–3 is the strength.
- **Complex-syntax fidelity**: `list()/[]` destructuring supports expression keys; generator methods / `yield from` / closure generators / Fiber generator bridges; non-virtual `parent::` dispatch; closure `use` write-back; trait method bodies; heredoc interpolation; closures / arrow functions / `match` / `yield` inside **files that also declare classes** are compiled into bytecode as well (`T_NS_SEPARATOR`-style FQCNs, `use` alias expansion, `ReflectionMethod::setAccessible` for private method calls). The few unsupported statements fall back to **EVAL_STMT statement-level execution**, with class references in the fallback text automatically FQCN-ised; the header prologue (`declare` / `namespace`) always stays first after normalisation.
- **JIT-friendly**: the operand-stack encoding is inlined into the `MCPUSH` closure and the whole interpreter stays a **flat mega-function**, which is easier for OPcache tracing-JIT to compile and saves one nested closure call per push; `--no-vm-jit` restores the older implementation with byte-identical semantics.
- **In-VM anti-debug**: inside the dispatch loop, every 512–8192 instructions (salt-derived interval) the interpreter re-checks for debuggers / hook extensions; on a hit it does **not `exit`** — it XORs a salt-derived pollution factor into opcode decoding, so the instruction stream silently derails and terminates early. No comparable branch to break on, no `exit` to patch; ≈2–3 ns/instruction, `--no-vm-inloop` disables it.
- **Advanced environment self-check**: the interpreter validates the "shell binding marker" at initialisation and inside the dispatch loop — the obfuscator `define()`s a salt-derived constant at the outermost layer, inner layers only hold its sha256 digest (`hash_equals` on digests; without the preimage it cannot be forged). Extracting the runtime closure + bytecode and running them standalone, or deleting the outer `define`, makes the self-check fail and writes the pollution factor into opcode decoding → **no output, no error**. `--no-vm-selfcheck` disables it.

### Compile-time optimisation and performance layers
- **constfold constant pre-computation** (`--const-fold-level=1|2|3`): "simple but expensive" pure literal arithmetic is evaluated **at build time** — `60*60*24 → 86400`, `'a'.'b'.'c' → 'abc'`. Nothing is computed at runtime (fewer instructions, zero overhead) and the original computation disappears from the source. Strictly literal-only evaluation honouring PHP 8 precedence and right associativity (`2+3*4=14`, `2**3**2=512`); division by zero / overflow / oversized results / type mismatches are skipped; the folded result is still a literal, so `const`, parameter defaults and other constant contexts stay legal. It runs **before `logic`**: compute first, then let the logic layer obfuscate the result.
- **Build-time loop folding** (on by default, `--no-loop-fold` disables): deterministic loops with literal bounds and pure local arithmetic (the typical 200 000-iteration `for`) are folded into a result literal at build time by `ConstEval` — a token-level symbolic executor that evaluates with native PHP operators and **never uses `eval`**. A strict token whitelist, an iteration cap and double verification keep anything uncertain untouched.
- **Aggressive pruning `aggressive_prune`** (`--aggressive-prune`, on by default in the `aggressive` preset): folds constant identities and dead branches written by the user, so the artifact does not recompute them at runtime. It is **hard-pinned before `logic`** — pruning after Logic would fold the obfuscator's own identities back into literals (protection defeating itself). "Logic cannot be accelerated away by the pruning layer" is therefore structural, with two backstops: the pipeline idles the layer if it is ever scheduled after `logic`, and it idles if the token stream already carries Logic brick fingerprints.

### Function-level splitting (`funcsplit` layer)
Extracts a statement into a function wrapper: `echo $i + $k . $str;` → `function _fs9ab12c34($i, $k, $str){echo $i + $k . $str;}` plus an in-place call `_fs9ab12c34($i, $k, $str);` (definitions land at the top of the file, calls stay where they were).

- **Level 1**: only `echo …;`, `print …;`, `$v = EXPR;` with side-effect-free expressions (variables, literals, array index reads, arithmetic / bit / comparison / logic, ternaries, array literals). Function calls, member access, `new`, references, increment/decrement, `isset/empty`, `@`, interpolated strings, heredoc, magic constants and `global/static` are refused — moving them into another stack frame changes behaviour.
- **Level 2**: adds `return`, side-effect-free expression statements (calls, `new`) and simple assignments.
- **Level 3**: also splits state-writing statements — array / property assignment, compound assignment, `unset`, `++`/`--`: the whole statement moves into the function body, **definitely-assigned** variables are passed by reference and undefined ones by value (never swallowing the original `Warning: Undefined variable`); consecutive echo/print statements merge into bigger sections (only when every variable in the group is definitely assigned before the group, otherwise one-shot argument evaluation would move the warning earlier and reorder output).
- Parameters are passed by value elsewhere: by-reference passing would silently create undefined variables as null and swallow the original warning.
- Files with multiple namespaces / bracketed namespaces / `__halt_compiler` / goto labels / control-flow headers / closures / interpolated strings / `$this` / superglobals are skipped as a whole (a function definition cannot be placed in the correct scope).
- Switches: `--func-split-level=1|2|3`, per-file cap `--func-split-max` (default 64, 0=off).

### Shell wrapping
- GZ compression shell (`--gz`), multi-layer EVAL shell (`--eval` / `--eval-layers`), target PHP version shell (`--php-version`, supporting `7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 / 8.5`, default `8.1`).
- **Newline entangling** (`--newline-poison-divisor=2`): injects roughly half the source size in newlines (10 KB source → ~5000 newlines) across token boundaries, partly inside the longest base64 payload, plus a header fallback — "strip whitespace / pretty-print" changes the ciphertext layout.
- **Guards moved inward**: anti-debug / anti-hook guards sit in the **innermost** layer of the VM / EVAL / GZ shells, wrapped into ciphertext by later shells, so stripping outer code blocks cannot reach them; self-check / licence binding need a plaintext `__FILE__` anchor and stay outermost.

### Project mode and whitelist
- **Directory builds keep class names intact**: in whole-system mode class names are not renamed (PSR-4 loads by "namespace + filename"; renaming breaks it), so no autoload patch is needed.
- **Seven-dimension whitelist**: `dirs` / `files` / `variables` / `functions` / `classes` / `constants` / `methods` (CLI: `--whitelist-dirs` / `--whitelist-files` / `--whitelist-vars` / `--whitelist-funcs` / `--whitelist-classes` / `--whitelist-consts` / `--whitelist-methods`). A match means the file is copied verbatim or the symbol keeps its name.
- **Whole-site equivalence**: the bundled complex ThinkPHP 8 fixture (500+ files) is executed file by file in separate processes; stdout and exit codes are compared byte for byte against native and match everywhere. Only mixed PHP/HTML templates additionally skip the `logic` / `decoy` layers (they would swallow inline HTML literals); everything else keeps full strength.
- **Parallel builds** (`--jobs=N`, requires `pcntl`): the global symbol table is built once, then multiple worker processes obfuscate files concurrently. Both `entry.php --jobs=N` and the one-click `other/thinkphp8-build.php --jobs=N` support it.
- **ThinkPHP 8 out-of-the-box config**: `other/thinkphp8.config.php` (equivalent to a `high` layering plus class names preserved, framework directories skipped, built-in whitelist).

### One-click build (ThinkPHP 8)
`other/thinkphp8-build.php` fuses "scan the project → generate the whitelist → call the obfuscator" into one command:

```bash
php other/thinkphp8-build.php --project=/var/www/shop --salt=team-salt-2026
```

It statically parses `app/` with the tokenizer (plus `--scan=`) and collects:

| Collected | Why the original name must stay |
|---|---|
| all classes / interfaces / traits / enums (FQCN) | PSR-4 autoloading, `class_exists`, container bindings look up by name |
| all global functions | helper functions are called by name |
| top-level `const` | constants are referenced by name |
| controller public methods + magic methods | routing dispatches by "controller/method"; renaming means 404/500 |

`--dry-run` scans and prints the whitelist, `--app-only` obfuscates only `app/` (fast iteration), `--output` sets the output directory (default `<project>_obf`). Non-PHP assets (`.env`, `.tpl` exception templates, static files, runtime SQLite databases) are copied verbatim.

### OPcache auxiliary compilation (Zend bytecode)
After the artifact is written, it can be compiled to **Zend bytecode** (`--opcache`):
- some shared hosts (e.g. EP) have OPcache enabled by default, so the artifact can be deployed and served as bytecode — raising the source-recovery bar further and improving runtime efficiency;
- if the `phpc` extension is installed, a portable `.phpc` bytecode file is produced as well;
- with OPcache only, `opcache_compile_file` pre-warms the runtime bytecode cache (the first request already hits bytecode);
- unsupported environments are skipped automatically without blocking the main pipeline — equivalent to not opting in.

### Runtime junk and execution entanglement (anti-stripping)
- **Runtime junk injection** (`--junk-runtime`, strength `--junk-intensity=N`): inserts side-effect-free `crc32` / `base64_decode` / `strrev` computations between real statements, diluting real logic density.
- **Execution entanglement** (`--junk-tangle`): junk values **take part in real assignments** and are cancelled by identities — `$v = intdiv($v * $jt, $jt)` (integer-faithful). Delete a junk definition line like `$jt = ($k|1)` and the tangle degrades to `intdiv($v * null, null)`, which throws `DivisionByZeroError` at runtime. Junk rises from "removable noise" to "a prerequisite for correctness", defeating "delete the block and you're done" stripping.
- Junk is generated in two places: `GeneralJunk` (token-level junk covering all non-VM code) and `VmBytecodeJunk` (junk at the compiled bytecode level, instruction-granular, stack always balanced). Both are forced to be extremely cheap and never slow execution down.

### Integrity protection
- **Anti-debug** (`--anti-debug`): detects xdebug / phpdbg / uopz·runkit / blackfire·tideways / vld·parsekit and debug backtraces; the `proc` group reads `TracerPid` from `/proc/self/status` (ptrace attached: gdb/lldb/strace) and `frida|xdl|substrate|libinject` from `/proc/self/maps` (injection hooking frameworks); the `env` group looks for instrumentation traces in `LD_PRELOAD` / `LD_AUDIT` (without false-positiving normal preloads such as jemalloc); the `ini` group checks `auto_prepend_file` / `auto_append_file` (classic unpacker entry points) and `opcache.jit`.
- **Probe syntax self-check**: probe groups are hand-assembled PHP source; one misplaced bracket would turn the whole artifact into a parse error. Each group is `php -l`-checked **at build time**; a failing group is dropped with an explicit warning while the rest stay active — damage is confined to one group, runnability beats probe coverage.
- **Unpacking detection (shell binding marker)**: outer `define()` of a salt-derived constant plus an inner sha256 digest check; a missing marker means "the VM inner layer was extracted and run standalone / the marker was stripped" — the anti-debug guard terminates explicitly, the VM self-check pollutes silently (two layers of depth).
- **Anti-hook eval** (`--anti-hook`): a runtime probe verifies that eval really takes effect, detecting uopz / runkit hijacking.
- **Self-check anti-tamper** (`--anti-tamper`): masked self-check + segmented hashes + total-length check + shutdown re-check.
- **Licence binding** (`--license-domain` / `--license-expire`): domain binding (web SAPI only, wildcard subdomains supported) plus expiry, off by default.
- **Structural-fingerprint poisoning shell** (`--struct-poison`): the file fingerprint (including the count of instrumentation extensions present) feeds the decryption key derivation — pretty-printing, instrumenting or hooking `gzinflate` / `eval` all end in "runs normally but decrypts to garbage", with no comparable branch and no hookable exit.
- **Newline-entangling shell** (`--newline-poison`, tunable with `--newline-poison-min=N` / `--newline-poison-ratio=F`): injects a large number of newlines (`max(original lines × 1/10, previous tier floor 2000)`), some as blank lines between statements, some embedded directly inside the longest base64 payload — entangled with the code rather than piled up at the end. Instinctively "stripping whitespace / pretty-printing" the artifact changes the ciphertext layout, and under structural poisoning it can break payload alignment; normal execution stays byte-identical to native.
- **Honeypot functions** (`--honeypot=N`): plausible high-value names such as `deriveMasterKey` / `verifyLicense` that are never called normally; when a cracker calls them they enter a deep loop and return a fake key — bait for automated "run every function" analysis.

### Anti-LLM static analysis (Confrontation-LLM) `just for fun`

> **Just for fun**: this module is a tongue-in-cheek "cognitive pollution" experiment. It is **not guaranteed to affect any real LLM or reverse-engineering tool**, does not improve confidentiality and never changes program semantics; do not count it as a security feature. Rely on VM bytecode / shell wrapping / integrity protection for real protection.

Modern reverse engineering and audits lean on LLMs for "semantic understanding + prioritisation". LLMs tend to trust doc comments, so this module manufactures misleading textual features (`--confrontation-llm`, strength `--confrontation-intensity=N`, 1–3 increasing):
- **Fake misleading comments**: inject fake `@deprecated` ("unused since v2.1, kept for backward compatibility"), `@see` (pointing at a "`CoreCrypto::decryptPayload()` replacement"), `@todo`, `@fixme` ("temporary debug code, remove before release") above real function / class declarations, labelling still-executing core logic as "deprecated / non-production".
- **Fake CHANGELOG comment blocks**: a `/** CHANGELOG … */` header claiming certain "legacy decryption entry points" were removed — while they still execute, nudging the LLM to skip prioritising the critical path.
- **Rare CJK scattering**: random rare CJK characters as `//` comment fragments across shell layers (function / class / statement boundaries), faking "contains Chinese business comments" and disturbing dictionary- or token-based statistical analysis and summarisation.
- **Mutually contradictory comments / cognitive pollution** (`--mutual-comments`, on by default; `--mutual-intensity=N`, 1–3, default 2): assertions that contradict each other inside each shell layer — the same code labelled "deprecated, safe to delete", "the only live path", "audit only" and "none of the three is trustworthy", so neither an LLM nor a human reader can decide what to believe. 22 theme groups, several forming 3-way / 4-way clusters; assertions within a cluster sit 1–3 boundaries apart (visible together within a few lines); each shell layer gets 3/6/12 clusters.
  - **Safety-boundary whitelist**: comments are only injected where the previous significant token ends with structural punctuation or a non-lexeme. Multi-layer shells convert line comments to block comments, squeeze whitespace and strip comments during string assembly; if a comment happened to be the only separator between `use X AS` and `Boom`, stripping it would glue them into a fatal `ASBoom`. Risky boundaries are rejected at the injection point — better to insert less than to insert wrongly.

> All injection produces **comments only**, never cutting into strings / heredoc / existing comments, so syntax and runtime are unaffected; the random distribution is derived deterministically from the salt, so the same salt reproduces. Injection points sit inside the self-check / poisoning shells and are included in the anti-tamper fingerprint.

### Engineering guarantees
- **Reproducible builds** (with a non-empty salt, every random value is derived through `Salt::derive/shuffle`).
- **No eval at build time**: constant pre-computation and loop folding use a token-level symbolic executor and never `eval` user-submitted source — in an online obfuscation service that would be a free webshell injector.
- **Automatic rollback**: `php -l` runs at the end of every pipeline; failures roll back to the last safe version.
- **Strict output equivalence**: tests assert "execution results identical before and after obfuscation" across the board.

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

# VM acceleration (super instructions + constant folding) + function splitting
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
php tests/v125_features.php      # super instructions / constant folding / function splitting: 54 checks
php tests/v126_features.php      # statement-level splitting / three-branch decoys / equivalent ISAs: 50 checks
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
