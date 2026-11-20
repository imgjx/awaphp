# Command-line reference

[简体中文](../CLI_OPTIONS.md) · **English**

> Applies to **AWAPHP** v1.2.8. This document tracks releases; the authoritative output is always `php entry.php --help`.

**Usage**

```
php entry.php --input=<file|dir> [--output=<file|dir>] [options]
```

---

## Basics / I/O

| Option | Description |
|------|------|
| `-i, --input=` | Input file or directory (required; directories are processed recursively) |
| `-o, --output=` | Output file or directory |
| `-c, --config=` | Config file (plain scalar config, layered JSON, or a PHP array config; a `.php` file must `return` an array and may use PHP expressions and comments) |
| `--level=` | Preset: `min\|low\|mid\|high\|max\|ultra\|aggressive`. Strength ladder: **`min` = light obfuscation (lexical only, no shell, near-zero runtime cost) → `low` = most obfuscation → `mid` = almost all** (these three exclude the VM); `high` / `max` / `ultra` / `aggressive` enable the VM. All presets keep property / parameter names by default so frameworks keep working (see `--no-var-keep-props`). Cost comparison: `docs/en/PERFORMANCE.md` |
| `-min -low -mid -high -max -ultra -aggressive` | Short flags equivalent to `--level=<name>` (the preset is only a default; individual flags override it) |
| `--salt=` | Obfuscation salt (empty = no salt; the same salt reproduces the same output) |
| `--repeat=` | Number of repeated encryption passes (default 1) |

## Identifier obfuscation (variables / functions / classes)

| Option | Description |
|------|------|
| `--var-length=` | Length of renamed variables |
| `--var-split=` | Number of segments to split variable values into (>1 to take effect) |
| `--var-keep-props` | Keep property names unchanged (mandatory for frameworks/DI/reflection/ORM that read properties by name; required by ThinkPHP 8). **On by default in all six presets since v1.1.2** |
| `--var-keep-params` | Keep method parameter names unchanged (mandatory for frameworks that bind by parameter name or use named arguments; required by ThinkPHP 8). **On by default in all six presets since v1.1.2** |
| `--no-var-keep-props` | Disable the above and rename properties too. **Closed code only** (pure libraries / single-file scripts / nothing extending a framework base class): frameworks reading those names silently misbehave (ThinkPHP `Model::$name` selects the table — after renaming you get `no such table`) |
| `--no-var-keep-params` | Same for parameter names. Route injection by name and named-argument calls stop working |

> **The size cost of keeping these two switches is smaller than intuition suggests.** Renamed
> identifiers are ~10 random characters (`$_n652ec9`), longer than business names such as
> `status` / `$count`, so **keeping the original names actually makes the artifact slightly
> smaller** (measured on the ThinkPHP 8 sample: `low` 199 KB → 200 KB and `mid` 361 KB → 365 KB
> with `--no-var-keep-props --no-var-keep-params`, i.e. +0.5%–1.0%). The real cost is **semantic
> leakage** — names like `$autoWriteTimestamp` / `$batchValidate` / `$scene` spell out business
> intent. The compromise only affects property and parameter names; local variables, function
> names, class names, method names and strings are still fully obfuscated. Conclusion: framework
> projects have no reason to disable them for size; closed-code projects may disable them for a
> little extra semantic masking.
| `--func-length=` | Length of renamed functions |
| `--func-call` | Function-call obfuscation (indirect calls) |
| `--func-call-enc` | Function-call encoding (wrapper names) |
| `--class-obf` | Enable class renaming in single-file mode (FQCN-aware: namespace untouched, short name rewritten, `class_alias` re-registers the original at the declaration point). **Directory builds (whole-system mode) never rename classes by default** — renaming conflicts with the PSR-4 "namespace + filename" contract, so project mode forces original class names and relies on the framework autoloader; individual classes/functions can be excluded with `--whitelist-classes` / `--whitelist-funcs` (see `docs/en/THINKPHP8_GUIDE.md`) |

## Encoding and optimisation

| Option | Description |
|------|------|
| `--str-length=` | Segment length for string encoding (>0 to take effect) |
| `--str-xor` | Runtime XOR assembly for strings (replaces base64; stronger anti-scanning) |
| `--html` | HTML entity encoding for HTML strings |
| `--num-enc` | Number encoding |
| `--token-obf` | Token-form obfuscation (master switch): keyword case randomisation + equivalent literal forms + safe quote flipping — kills keyword-based grep / regex signatures |
| `--token-no-case` | Disable keyword case randomisation (on by default) |
| `--token-no-literal` | Disable literal-form rewriting (`true→!0` / cast aliases / `array()→[]`) |
| `--token-no-quote` | Disable quote flipping (`'abc' ↔ "abc"`) |
| `--token-heredoc` | Rewrite pure literal strings into nowdoc (`<<<'ID'`) form (off by default) |
| `--token-decoy=` | Number of top-level decoy comments (default 3, 0 = off; only with comments retained) |
| `--gz` | GZ compression shell |
| `--insert-dead` | Insert dead code |
| `--no-comments` | Remove comments |
| `--strip-newline` | Strip newlines |
| `--compress` | Compress the code (strip whitespace/comments) |
| `--copyright=` | Text for the copyright comment |
| `--no-banner` | Do not inject the "engine name + version" file header |

## Whitelists (exclude directories / files / symbols)

A whitelist keeps **directories, files or symbols that must never be renamed** out of the obfuscation, preventing broken framework loading / reflection / DI binding / dynamic dispatch. Express it as `"whitelist": {...}` in JSON, in a PHP array config (see the ThinkPHP 8 example `other/thinkphp8.config.php`), or via the CLI flags below (comma separated, `*` wildcards supported).

| Option | Description |
|------|------|
| `--whitelist-dirs=` | Directories to skip (relative to the input dir; `app/Admin` or `app/Admin/*`); the whole directory is **copied verbatim** |
| `--whitelist-files=` | Files to skip (exact relative path, e.g. `config/app.php`); **copied verbatim** |
| `--whitelist-vars=` | Variable names not to rename (`$` optional, e.g. `\$db,\$cfg`) |
| `--whitelist-funcs=` | Function names not to rename (e.g. `helper,format_money`) — kept consistently across files in directory mode |
| `--whitelist-classes=` | Class names not to rename (FQCN or short name, e.g. `App\Service\Order`); `--whitelist-namespaces` / `--whitelist-interfaces` / `--whitelist-traits` fold into the same set |
| `--whitelist-consts=` | Constant names not to rename |
| `--whitelist-methods=` | Method names not to rename (`Class::method` or a bare method name) |

> Skipping a directory/file means "copy verbatim, not obfuscated" — symbols declared inside keep their names and cross-file references never break. Keeping a symbol means "same name across files". Both work; choose by exclusion granularity.

## Control flow and VM protection

| Option | Description |
|------|------|
| `--cfg=` | Control-flow obfuscation: `low\|medium\|high` |
| `--goto=` | GOTO obfuscation nesting depth (>0 to take effect) |
| `--goto-cpx=` | Max statements per GOTO dispatcher (>0 to take effect) |
| `--sm-complexity=` | Native state machine: max statements per dispatcher (2–32, default 8) — consecutive statements are chunked, one dispatcher per chunk |
| `--sm-passes=` | Native state machine: nesting depth (1–5, default 2) — each dispatcher of the previous pass is dispatched again, forming a dispatch tree |
| `--sm-traps=` | Native state machine: decoy dead states (0–4, default 2) — unreachable labels; forcing a jump into one locks the program |
| `--no-sm-opaque` | Disable the opaque predicate on transitions (default on: `if(($o^$o)===0)`, with an `else` leading to a decoy dead end) |
| `--vm` | VM shell (custom instruction set + interpreter) |
| `--vm-layers=` | VM nesting depth (true nested VM): `>=2` feeds the previous VM artifact back into the VM compiler, stacking layers; default `1` |
| `--vm-linker=` | ModuleLinker module directory (JSON manifest): calls matching the manifest are inlined into the same Program, so artifacts contain no module path and no `include`. **Since v1.1.9**, passing this directory in a loose-key build also enables the `shell:module_link` **native path** (inlining outside VM presets: call-site rewriting + module native definitions appended); the `low` / `mid` presets already enable that layer without any flag |
| `--vm-link-modules` / `--no-vm-link-modules` | Built-in module directory (`src/Module/builtin/`, 70+ functions) is **on by default**; disabling it stops automatic module loading and call sites keep native dispatch. **Since v1.1.9 every preset from `low` upwards is covered**: VM presets (`high`/`max`/`ultra`) inline at bytecode level, non-VM presets (`low`/`mid`) inline natively |
| `--vm-junk=` | VM bytecode-level junk: N runs of "really executed but side-effect free" opcodes (stack-balanced, no output, no variable writes) |
| `--vm-keychain` | Split key hash chain: `k0 = master`, `ki = sha256(k[i-1] ‖ i)`, derived sequentially at runtime — a statically extracted single key cannot recover the others |
| `--vm-builtin-replace` | Builtin function names in `natTbl` are salted and encoded into the binary package, decoded at runtime; no plaintext `strlen` / `array_map` call names inside |
| `--vm-morph` | Interpreter runtime morphing: a second independent scramble layer over dispatch, different maths per build (removes the fixed dispatch fingerprint) |
| `--vm-fake-fog=` | VM decoy functions: N never-called, stack-balanced fake functions that swamp the real opcode set and inflate the function table (>0 to take effect) |
| `--vm-probe=` | VM anti-debug / anti-tamper probe matrix: N probes (xdebug/phpdbg/uopz detection + bytecode CRC self-check) that stop silently when hit (>0 to take effect) |
| `--vm-p8` | One-shot P8 switch: enables keychain + builtin-replace + morph, fake-fog=6, probe=4 (each can be overridden) |
| `--no-vm-symbols` | Disable VM symbol ciphertext (**on by default**): **every string** inside the VM bytecode container (class / method / function / variable / property / constant names, the three exception-table fields, symbol strings in operands) is stored encrypted with a salt key **derived from the source file content** and restored when unpacking — even if an attacker extracts the split keys and opens the container, `strings` reveals no symbol names. Keys are not reused across files (A's key cannot open B). The accompanying **container-format randomisation is always on** (type-tag bytes differ per build, no separate switch): the original fixed `NULL=0/INT=1/STR=2/BOOL=3/FLT=4/SEG=5` sequence was a format fingerprint any generic unpacker could apply, so it is now mapped to per-build random bytes while the same salt stays reproducible. Disabling this falls back to v1.1.7 behaviour (plaintext symbols inside the container) |
| `--no-vm-cpool` | Disable VM constant-pool encryption (on by default): `PUSH_C` literals are moved into a separate constant pool (instructions reference indexes) encrypted with its own key, so static analysis sees no plaintext constants |
| `--no-vm-jit` | Disable the JIT-friendly interpreter mode (on by default, `vm_jit=true`): when on, the operand-stack encoding is **inlined** into the `MCPUSH` closure and the interpreter stays a flat mega-function, easier for OPcache tracing-JIT to compile and saving one nested closure call per push (≈26 ns/instruction, hundreds of ms over a million pushes). Disabling falls back to the older "`MCENC` encoding closure + `MCPUSH` calls it" implementation for maximum compatibility. Both modes are byte-for-byte equivalent (objects use the plaintext slot `$___MCPLAIN___`, property write-back is lossless) |
| `--no-vm-inloop` | Disable in-VM anti-debug (on with `--vm-probe` by default, `vm_inloop=true`): inside the dispatch loop, every 512–8192 instructions the interpreter re-checks for debuggers / hook extensions (phpdbg SAPI, uopz, runkit7, xdebug actually attached); on a hit it does **not exit** but XORs a salt-derived pollution factor into opcode decoding, derailing the instruction stream and ending early — no comparable branch, no patchable exit; zero semantic impact in normal environments (factor stays 0), ≈2–3 ns/instruction. The `inloop` parameter of `shell:vm` can override |
| `--no-vm-selfcheck` | Disable the advanced VM environment self-check (on by default, `vm_selfcheck=true`): the "shell binding marker" is verified at **two points** (interpreter init + inside the dispatch loop) — the obfuscator defines a salt-derived constant at the outermost layer, inner layers hold only its sha256 digest compared with `hash_equals`; extracting the VM inner layer and running it standalone, or deleting the outer define, fails the check and writes the pollution factor into opcode decoding → zero output, zero error (no breakpoint-able, no patchable exit). Only effective when the artifact has a VM layer; when `shell:anti_debug`'s `probes` include the `shell` group, the guard additionally performs an explicit-termination check |
| `--vm-combo[=N]` | VM opcode combination execution (anti-decompilation): a single opcode is split into an equivalent multi-instruction sequence (`SUB→NEG+ADD`, `PUSH_C 2,MUL→DUP,ADD`, `BOOL_NOT→PUSH_C 0,EQ`, …), destroying the 1:1 opcode mapping. N=1–3 is the strength (default 2); `--no-vm-combo` disables |
| `--mutual-comments` | Mutually contradictory comments / cognitive pollution (**on by default**, enhanced in v1.1.7): inside each shell, clusters of **contradictory** assertions — the same code labelled "deprecated, safe to delete / the only live path / for audit only / none of the three is trustworthy", so neither an LLM nor a human knows what to believe. 22 theme groups (including 3-way and 4-way clusters); the N assertions of a cluster sit within 1–3 token boundaries and are visible side by side. Comments are only injected at safe boundaries where the preceding significant token ends with structural punctuation, eliminating `ASBoom`-style fusion after multi-layer comment stripping. `--no-mutual-comments` disables |
| `--mutual-intensity=N` | Contradictory-comment strength `1–3` (default 2): **3 / 6 / 12 clusters per shell** (2–4 compact contradictory assertions each), with the cap relaxed as more safe boundaries are available. Only effective with `--mutual-comments`; cluster themes, positions and text are derived deterministically from the salt |
| `--confrontation-llm` | Confrontation-LLM anti-LLM analysis: injects misleading comments (fake `@deprecated`/`@see`/`@todo`/`@fixme` plus a fake CHANGELOG implying "already removed" while the code still runs) and scatters rare CJK comments inside shells to mislead LLM prioritisation; density via `--confrontation-intensity=N` (1–3, default 2); `--no-confrontation-llm` disables |
| `--newline-poison` | Newline-poisoning shell (companion to the struct-poison shell): injects many newlines (count = `ceil(source bytes / divisor)`, divisor default 2, i.e. 10 KB → 5000 newlines), some as blank lines between statements, some embedded in the longest base64 payloads — entangled into the code rather than piled at the end; visible in `mid`/`high`/standalone mode, encrypted into the payload under `max`/`ultra` (which include struct-poison); `--newline-poison-divisor=N` changes the byte divisor (default 2), `--newline-poison-min=N` the floor (default 0), `--newline-poison-ratio=F` the in-string ratio (default 0.1); `--no-newline-poison` disables |
| `--eval` | Multi-layer EVAL packing |
| `--eval-layers=` | Number of EVAL layers (default 3) |
| `--anti-debug` | Anti-debug probes (phpdbg / xdebug, …). Probe groups are selected with the `ad_probes` config key (comma separated): `ext` (loaded extensions: xdebug/uopz/runkit/blackfire/tideways/xhprof/vld/parsekit/taint/scream/pcov), `func` (function existence + xdebug actually attached), `ini` (xdebug.mode/profiler/remote + `auto_prepend_file`/`auto_append_file`/`opcache.jit`), `env` (XDEBUG_CONFIG / XDEBUG_MODE / PHPDBG / instrumentation-style LD_PRELOAD·LD_AUDIT), `sapi` (phpdbg), `trace` (call stack), `proc` (`TracerPid` in `/proc/self/status`, injected `.so` in `/proc/self/maps`), `shell` (unpack detection: terminate when the shell binding marker is missing). Under presets the `shell` group is merged in automatically per `vm_selfcheck`; **it is dropped when VM self-check is off** (no marker to compare, otherwise artifacts would kill themselves on start). Every probe group is syntax-checked with `php -l` at build time; a broken group is dropped entirely with a warning |
| `--anti-hook` | Anti eval-hook (eval integrity probe + uopz/runkit/phpdbg detection) |
| `--anti-tamper` | Self-checking anti-tamper (masked self-check + segmented hashes + a second check at shutdown) |
| `--shell-shield-intensity=` | Per-shell self-protection strength `1-3` (preset default: min,low=1 / mid,high=2 / max,ultra=3; `min` has `shell_shield` off, so this has no effect there); controls only the aggressiveness of the semantic transforms — variable renaming is always on |
| `--no-shell-shield` | Disable per-shell self-protection (each shell's decode logic is no longer protected by outer obfuscation — easier to crack) |
| `--php-version=` | Target PHP version (generic): `7.4\|8.0\|8.1\|8.2\|8.3` (default `8.1`) |
| `--opcache` | OPcache-assisted compilation: compile artifacts to Zend bytecode after writing (some hosts such as EP enable OPcache and can run bytecode directly; skipped automatically when unsupported) |
| `--junk-funcs=` | Number of decoy functions (>0 to take effect; plausible naming) |
| `--inline-each-layer` / `--no-inline-each-layer` | **v1.2.0**: per-layer inlining. After each shell (including **every sub-layer** of a multi-layer EVAL) wraps the payload, module linking runs once more, with a per-layer `__ml<layer><salt>_` prefix to avoid cross-layer redefinition; fingerprint- and plaintext-anchor-sensitive layers (VM, anti-debug, anti-tamper, …) are skipped automatically. Layered JSON accepts a top-level `"inline_each_layer": true`. Off by default |
| Layer `shell:anti_strip` | **v1.2.0 anti-stripping interlock shell**: parameters `depth` (2–6, default 3), `response` (`silent` refuse quietly / `exit`). Each of the K EVAL layers `define`s a salt-derived anchor constant and the innermost layer validates the full anchor grid before evaluating; extracting any inner layer and running it standalone refuses to execute the real source. Built into the `mid` / `high` / `max` / `ultra` presets |
| Layer `shell:anti_tamper` new params | **v1.2.0 v3**: `chain` (segmented chained hashes `X[i]=sha1(seg[i]‖prev‖i)`), `multipoint` (P1 at start + P2/P3 checks at shutdown), `segments`, `shutdown`; the guard line gained a **plaintext blob integrity gate**, closing the bypass "corrupt the ciphertext → `gzinflate` fails silently → the in-eval self-check never runs" |
| Layer `shell:anti_debug` new params | **v1.2.0**: probe groups `func2` (`xdebug_call_*` / `phpdbg_*` families) and `ini2` (`xdebug.*` / `opcache.jit_debug` families); new `response` (`exit` terminate directly / `silent` separate detection from counter-measure, wiping output buffers at shutdown), `multipoint` (second judgement at shutdown, covering runtime attach), `intensity` |

> **Advanced PHP/HTML mixed templates (v1.2.0)**: pages with `<?=` short tags, alternative
> syntax (`if(): endif;`), HTML inside heredoc, `?><?php` boundaries, PHP embedded in
> attributes / `<script>` / `<style>` are rewritten equivalently into pure PHP at the pipeline
> entry by `InlineHtml::normalize()` before entering any shell, and all six presets produce
> byte-identical output. No extra switch is needed; pure PHP sources are untouched.

## Licence binding

| Option | Description |
|------|------|
| `--license-domain=` | Licensed domains, comma separated (e.g. `a.com,b.com`); checked only under a web SAPI |
| `--license-expire=` | Expiry (`Y-m-d` or `Y-m-d H:i:s`); execution stops after it |

## Whole-system / directory mode

| Option | Description |
|------|------|
| `--manifest=` | Path to the symbol-mapping manifest JSON: written on the first build and reused on incremental rebuilds, keeping cross-file symbol names compatible across iterations |

## UI and logging

| Option | Description |
|------|------|
| `--format=` | `text\|json` (json for GUI / script parsing) |
| `--verbose` | Verbose logging |
| `--silence` | Silent |
| `-h, --help` | Show help |
| `--info` | Show tool information |

## Aggressive logical transform (v1.2.2)

| Switch | Description |
| --- | --- |
| `--logic` | Enable the aggressive logical transform (a superset of anti-AST): type-safe identity restructuring of expressions anywhere; strength 3 additionally expands simple assignments / `echo` into a variable net of "create two temporaries → chain computation → reuse across statements", with the temporaries renamed again by the variable layer |
| `--logic-intensity=1\|2\|3` | Strength (default 2): 1 = numbers / unary `!`, 2 = + comparisons / booleans, 3 = + temporary-variable entanglement. Only effective with `--logic` or the layered `logic` layer |
| Layer `logic` | `intensity` parameter 1–3; preset values `low=1 / mid=2 / high=2 / max=3 / ultra=3`, not enabled in `min`. The layer must precede `var` so new variables are renamed too |

> Every rewrite is validated by substituting sample values; failures keep the original. In
> constant-expression contexts (`const` / enum case / parameter defaults / property defaults /
> `#[...]` / `static` initialisers) only pure bitwise forms are used, and `declare()` is skipped.
> See `docs/en/VM_FEATURES.md` §11.27 and `CHANGELOG.md` v1.2.2.

## Per-shell aggressive transform / wild bricks (v1.2.4)

| Switch | Description |
| --- | --- |
| `--logic-shell` / `--no-logic-shell` | **Per-shell aggressive transform**: immediately after each `shell:*` wraps the payload, that layer's freshly generated decode logic is subjected to another logical identity restructuring (numbers / comparisons / booleans / short strings all mangled) — strip any layer and the decode code is just as unreadable. Inner ciphertext payloads (long strings above `maxlen`) and byte / anchor / interpreter-sensitive layers (VM, struct-poison, self-check, licence, honeypot, newline-poison, anti-strip) are skipped automatically. **On by default in `ultra`** |
| `--logic-shell-intensity=2\|3` | Per-layer strength (default 2; passing `1` is promoted to `2` — unary `!` restructuring can produce illegal code inside multi-layer eval decode chains): 2 = numbers/comparisons/booleans/strings, 3 = + temporary-variable entanglement (slower and more aggressive) |
| `--logic-shell-maxlen=N` | Byte cap for rewriting string literals (default 512): longer literals are skipped to protect inner base64/gz/bytecode ciphertext payloads from inflation or alteration; `0` = unlimited (not recommended; may break structural fingerprints) |
| `--logic-wild` / `--no-logic-wild` | **Wild identity-brick library** (independent switch, off by default): double base64 / strrev / rot13 combinations for strings, identity empty-string noise, integer `ord`/`hexdec`/`bindec`/`abs`/shift chains, add/subtract entanglement chains — all verified value by value. Deep encoding slows VM bytecode compilation, so it is **meant for VM-free presets such as low/mid**; presets containing `shell:vm` (high/max/ultra) should not enable it |
| `--logic-loop-light` / `--no-logic-loop-light` | **Loop-local light injection** (on by default, performance): before rewriting, every loop's iteration count is estimated statically (`for` init/bound/step, the assignment before a `while`, `foreach` array literals / `range()`). In loops above the threshold, literals in the condition / step / body become **operator-level identities** (`(N^0)` / `(0+N)` / `'ab'.'cd'`) — constant-folded by PHP at compile time, zero runtime instructions, still no bare literal in the source, and no function call amplified by iteration. Outside loops and in short loops the full brick set stays. Constant contexts / jump-level operands / function / closure / class bodies are skipped; loops with an unknown count are treated as large |
| `--logic-loop-threshold=N` | Large-loop threshold (default 20): only loops with **more than N** iterations are downgraded; `0` treats every loop as large. The string layer (`StringEncoder` / `StringRuntimeAssembler`) shares the same decision |

> `logic_shell` output is `php -l`-checked and rolls back on failure, so no unrunnable file is
> produced. Regression: `tests/v124_layer_logic.php`; see `CHANGELOG.md` v1.2.4.

## Zero-eval build safety / Logic-first pruning / VM interpreter morphing (v1.2.8)

| Flag / config | Description |
| --- | --- |
| `--no-loop-fold` | **Disable build-time pure-compute loop folding** (on by default). `loop_fold` uses `ConstEval` (a token-level symbolic evaluator with PHP native operators, **zero `eval`**) to fold literal-bound, pure-local-arithmetic deterministic loops (the classic 200k `for`) into result literals — zero runtime iterations. Evaluating *user-uploaded* tokens with real eval is a free webshell injector; that surface is now removed. The 200k loop drops from ~12 ms to ~8.5 ms (faster than native), byte-identical. Strict whitelist + iteration cap + double check; uncertain loops are left untouched |
| `--aggressive-prune` | **Aggressive identity compression + dead-branch pruning** (a *performance* layer; on by default in `aggressive`): folds the identities and dead branches **you** wrote so the artifact stops recomputing them — smaller and faster. **Hard-pinned before `logic`**; a preset declaring it later is rewritten, because a pruner running after Logic would fold the obfuscator's own identities back to literals (protection destroying itself). Two fallbacks: the layer idles when the pipeline reports it ran after `logic`, and idles when the token stream already carries Logic's building-block fingerprint |
| `--level=aggressive` | **aggressive performance tier**: full ultra protection + the `aggressive_prune` layer; byte-identical end-to-end |
| `--no-vm-runtime-logic` | **Disable VM interpreter Logic morphing** (on by default). When on, the interpreter's integer literals become compile-time bitwise identity expressions `N → ((Ka) ^ (Kb))` — opcode numbers vanish from the interpreter source at zero runtime cost; off restores the v1.2.6 hexadecimal form. `break`/`continue` level operands are auto-skipped |
| `struct_poison rounds` | StructPoison KDF rounds keep the original algorithm (default 3, ultra/aggressive 5); the earlier downgrade was reverted |

> Stability: the VM per-instruction main-loop semantics are unchanged (the interpreter only gets an
> equivalent compile-time literal form); all min–ultra tiers keep their protection strength and
> behaviour, and the aggressive compression layer is off by default. See `CHANGELOG.md` v1.2.8.

## Whole-site `max` byte-identity / parallel builds / mixed-template guard (v1.2.7)

| Flag / config | Description |
| --- | --- |
| `--jobs=N` | **Parallel project builds** (needs `pcntl`; 1 = serial default, max 256). The global symbol table is built once, then N worker processes obfuscate distinct files concurrently; 32 cores build a 525-file `max` project in ~3.5 min (vs ~12 min serial). `other/thinkphp8-build.php` accepts `--jobs=N` too |
| `mixed_html_skip_aggressive` | **Mixed-template guard** (default `true`, set `false` to force off): PHP/HTML interleaved files (containing `T_INLINE_HTML` / `<?=`) skip the aggressive `logic` / `decoy` passes — on shell re-tokenisation those could swallow inline HTML literals (`class="sale"` → `sale`, `(奇)` → blanks). All other `max` strength is unchanged |
| `--config=other/thinkphp8.config.php` | **Official whitelist safe build**: the config now carries the full `max` layer stack plus `whitelist.dirs` (vendor/config/route/public/runtime… copied verbatim), deeply obfuscating only business code. See THINKPHP8_GUIDE |

> This release also fixes several runtime-correctness edges in the layered pipeline: top-level `return`
> propagation through every shell, CLI shebang preservation, `class_alias` insertion under PHP 8
> attributes, dynamic member access `$obj->{$p}`, assignment-lvalue protection, `continue N` /
> `break N` operands, `array()` method names, `function_exists` guards for repeated includes,
> heredoc/interpolation-safe comment injection, and `DeclareHoist` locating the open tag by token
> rather than regex (avoiding false matches on inline short-echo tags). See `CHANGELOG.md` v1.2.7.

## Whole-statement splitting / 3-way decoys / equivalent ISA (v1.2.6)

| Switch | Meaning |
| --- | --- |
| `--vm-isa-split[=N]` / `--no-vm-isa-split` | **Bytecode disassociation** (default 2, 1 = off, max 4): create N functionally identical physical opcodes for one semantic opcode and use them **in rotation** in the bytecode. Each alias has its own dispatch label but shares one handler body; dispatch stays a single jump-table lookup — **zero runtime cost**. Layer key: `isaSplit` param of the `shell:vm` layer |
| `--decoy[=N]` / `--no-decoy` | **Multi-shaped always-true 3-way decoy master switch** (v1.2.6): `=N` enables and sets density. A true statement becomes `if/elseif/else`; the true condition is picked by salt from 12 business-looking always-true checks, the other two paths use look-alike values and are unreachable. The true branch applies same-kind substitution (variable→variable, string→string). **Only echo/print are decoyed**; assignments/write-backs are skipped (substitution copies variables into locals and would drop writes). |
| `--decoy-level=1\|2\|3` | Decoy density: 1≈12%, 2≈25% (default), 3≈45% of eligible statements. Layer key: `level` param of the `decoy` layer |
| `--func-split-level=3` | From v1.2.6, level 3 also splits **state-changing statements**: array/property writes, compound assignments, `unset`, `++`/`--`. Only variables provably assigned before the statement are passed by reference; undefined ones stay by-value. goto labels, control-flow heads, closures, interpolation, `$this` and superglobals are skipped. |

> The decoy probe is **normalised to a scalar first** (non-scalars become 0), so objects / Closures never
> trigger a `__toString` error. Condition shapes and structural variants are salt-derived — reproducible
> for a given salt, different across builds.

---

## VM acceleration / function splitting (v1.2.5)

| Switch | Description |
| --- | --- |
| `--vm-super-isa=N` | **Super instructions**: merge N frequently occurring 2–4 instruction runs into new composite ISA instructions. `0` = off, default **3**, max 24. **Absorbed sub-instructions are deleted outright** (no more `NOP` padding): the segment shrinks, and the build recomputes every relative jump offset plus the exception table's `[tryStart, tryEnd, handlerPc]` in that segment (jump targets and exception-table endpoints are marked un-crossable, so a merge never spans one). Positions where a jump target lands inside the merged range are skipped; a segment containing `JMP_B` is abandoned entirely; only instructions without control-flow side effects are merged. `--no-vm-super-isa` equals `0` |
| `--const-fold[=N]` / `--no-const-fold` | **Compile-time folding master switch** (v1.2.5): literal pre-computation only runs when this is on. Passing `--const-fold-level=N` alone enables it automatically |
| `--const-fold-level=1\|2\|3` | **Compile-time folding (`constfold` layer)**: pure literal arithmetic is computed at build time (`60*60*24 → 86400`, `'a'.'b' → 'ab'`). 1 = `+ - *` and string concatenation; 2 = adds `/ % **` and bitwise; 3 = everything. Honours PHP 8 precedence and right associativity; division by zero / overflow / oversized results / type mismatches are skipped. **Must run before `logic`**. Layered key: `level` of the `constfold` layer |
| `--func-split[=N]` / `--no-func-split` | **Function splitting master switch** (v1.2.5): `=N` enables it and sets the level. Passing `--func-split-level=N` / `--func-split-max=N` alone enables it automatically |
| `--func-split-level=1\|2\|3` | **Function-type code splitting (`funcsplit` layer)**: extract a block of code into a function and call it in place. 1 = `echo`/`print` only; 2 = adds `$v = EXPR;` assignments; 3 = also merges consecutive `echo`/`print` into larger blocks. Arguments are always by value and expressions must be side-effect free; files with multiple namespaces / bracketed namespaces / `__halt_compiler` are skipped entirely. Layered key: `level` of the `funcsplit` layer |
| `--func-split-max=N` | Per-file splitting cap (default 64, `0` = off). Layered key: `max` of the `funcsplit` layer |

> All three capabilities fall back conservatively ("keep it as-is when unsure") and preserve
> byte-identical output. Whole-site tests (WordPress / ThinkPHP 8) produced artifacts that all
> pass `php -l`. See `CHANGELOG.md` v1.2.5.

---

> Note: these switches combine freely. Every protection class is independent — the stronger the combination, the higher the reverse-engineering bar, but runtime performance and debuggability may suffer. Weigh them against your actual scenario.
