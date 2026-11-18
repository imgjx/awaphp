# PERFORMANCE.md — performance cost comparison

[简体中文](../PERFORMANCE.md) · **English**

> Goal: turn "how much does obfuscation cost" from a feeling into **reproducible numbers**, plus preset-selection advice.
> The data below is mostly measured on **v1.1.1**, extended with **v1.1.3**'s JIT-friendly mode, **v1.1.4**'s in-VM anti-debug, **v1.1.5**'s advanced VM environment self-check, **v1.1.6**'s native state-machine layer and probe syntax self-check cost, **v1.1.7**'s `low` preset without the state machine and its adjusted anti-LLM comment density, **v1.1.8**'s VM symbol ciphertext cost (§4), and **v1.2.5**'s super instructions / constant folding / function splitting (§10).
> Local CLI, single-core container, PHP 8.5.12, OPcache off (the JIT comparison says otherwise). Reproduction commands are in §2.

---

## 1. One-line conclusion

| Preset | Runtime cost (real TP8 endpoint) | Verdict |
|---|---|---|
| `min` | **close to native** (1.01× pure computation; TP8 2.0 → 1.6 ms) | lexical rewriting + multi-scheme string encoding, **no anti-debug / no shell**, decoding uses native PHP functions |
| `low` | **~2.8×** (→ 5.7 ms) | safe on production request paths |
| `mid` | **~6.2×** (→ 12.4 ms) | safe on production request paths |
| `high` | **~22×** (→ 44 ms) | usable; prefer core files only, or put a page cache in front |
| `max` / `ultra` | **~196× / ~242×** (→ 391 / 485 ms) | offline jobs, licence checks, critical algorithm fragments; never on high-QPS paths |

**The root cause is simple**: only `high` and above enable the **VM shell** — it hands every statement to a PHP-written interpreter, so cost is proportional to **the number of statements executed**, not to file size. `min` / `low` / `mid` only do token-level transforms (`min` has no encoding shell at all), so their cost is constant.

> Three premises to read together:
> 1. `min` / `low` / `mid` **contain no VM** (performance first). For the VM, go to `high`.
> 2. Since v1.1.1 the token-level transforms in `low` / `mid` were **substantially strengthened** (function/class names, number and string encoding, control flow + GOTO, semantic rewriting, runtime junk, decoy comments, token forms, EVAL/GZ multi-shell), so their multipliers are higher than the "light" 1.1.0 versions — yet they still land in the **single-digit to low-tens-of-milliseconds** absolute range.
> 3. `min` is the "closest to native" preset: variable names / number forms / multi-scheme string encoding / comment stripping / whitespace squeezing + compile-time constant folding, **no anti-debug probes, no shell, no expensive derived decryption** — string decoding uses `base64_decode` / `gzuncompress` / `chr` / `"\xHH"`, all native PHP capabilities, with no per-character PHP loop at runtime. Measured 1.01× pure computation and 1.05× cold start: the same speed as native.

---

## 2. Measurement method (reproducible)

```bash
# A) Mixed workload: token-level presets (low / mid)
php tests/perf_bench.php --runs=5 --levels=min,low,mid

# B) Light workload: VM presets (high / max) — the mixed workload takes minutes under the VM
php tests/perf_bench.php --runs=5 --levels=high,max --workload=tests/perf_workload_vm.php

# C) Repeat with CLI OPcache enabled (closer to FPM + OPcache production)
php tests/perf_bench.php --runs=5 --levels=low,mid --opcache
```

The workload script `tests/perf_workload.php` (3.2 KB) covers: arithmetic loops, user function calls, class method calls, array construction/sorting/associative iteration, string concatenation and hashing, recursion, closures, generators. The artifact and the native run **must produce the same checksum**, otherwise the row is marked `✗` (performance numbers are meaningless when semantics are broken).

Metric definitions:

| Metric | Meaning |
|---|---|
| Cold-start total | Median wall-clock of `php <artifact>`, including PHP process start (~23 ms on this CLI) |
| Pure computation | The workload's internal `hrtime` measurement, **excluding** process start and decoding — the best signal for runtime cost |
| Decode/compile cost | Cold-start total − pure computation − empty-script baseline ≈ shell decryption + eval compilation (paid on every request under FPM) |
| Peak memory | `memory_get_peak_usage(true)` |

**Absolute numbers only compare within the same machine**; multipliers are portable.

---

## 3. Runtime cost: mixed workload (token-level presets)

Source file 3.2 KB, native pure computation 8.27 ms. Median of 3 samples:

| Variant | Artifact size | Cold-start total | Pure computation | vs. native | Semantics equal |
|---|---:|---:|---:|---:|:---:|
| Native (unobfuscated) | 3.2 KB | 32 ms | 8.27 ms | 1.00× | ✓ |
| `min` | 2.8 KB | 32.4 ms | 8.4 ms | **1.01×** | ✓ |
| `low` | 8.0 KB | 39 ms | 13.54 ms | **1.64×** | ✓ |
| `mid` | 16 KB | 38 ms | 14.43 ms | **1.74×** | ✓ |
| `high` | 82 KB | — | 167.8 s (single run) | ~20,000× | ✓ |

`high` cannot be sampled reliably on this workload (call- and string-dense), but that **is the conclusion, not a timeout** — measure VM presets with the light workload in the next section. In practice VM presets should only be applied to core files with a bounded number of calls.

> 1.1.0's `low` / `mid` were 1.17× / 1.49×, but those presets had almost no token-level transforms. v1.1.1 added all the "does not grow with statement count" protections and reached 1.64× / 1.74× — under 1 ms of extra pure computation buys the full set of function/class/string/number/control-flow/form obfuscation plus multi-layer shells.

---

## 4. Runtime cost: VM presets (including the 1.1.0 → 1.1.1 interpreter speed-up)

Light workload `tests/perf_workload_vm.php` (1.8 KB, native pure computation **0.919 ms**), median of 3 samples:

| Variant | 1.1.0 pure computation | 1.1.1 pure computation | vs. native | Semantics equal |
|---|---:|---:|---:|:---:|
| Native | 0.919 ms | 0.919 ms | 1.00× | — |
| `high` | 10,244 ms | **5,676 ms** | 6,176× | ✓ |
| `max` | 14,476 ms | **8,120 ms** | 8,836× | ✓ |

The same artifact on a **dispatch-dense** workload ("200k-iteration while loop", native 1.20 ms):

| Preset | 1.1.0 | 1.1.1 | Speed-up |
|---|---:|---:|---:|
| `high` | 5,655 ms | **2,198 ms** | **2.6×** |
| `max` | 6,769 ms | **2,678 ms** | **2.5×** |

The speed-up comes from two hot-path changes in the interpreter:

1. **Typed operand-stack encoding** (~75% of the old VM's total time): the old version `serialize()`d **every** pushed value and then computed the XOR mask with `str_repeat` + `substr`, `unserialize()`ing on pop. The new version branches by type — ints use a single integer XOR, strings a single string XOR (mask taken from an 8 KB pre-padded budget), float/bool/null are stored directly, only arrays are serialized, and non-serialisable values go to a plaintext slot. Decoding is inlined into `MCPOP`/`MCPEEK`, saving one closure call (≈26 ns/instruction).
2. **State-machine dispatch compression**: two dedicated instructions `DISPATCH` / `SET_ST` collapse `PUSH_V st; PUSH_C s; EQ; JZ 1; JMP` (five instructions) into one, and the state write into one.

The multipliers look extreme (thousands of times) because this workload is **almost entirely pure computation and function calls** (no I/O, no framework overhead). In real business code, framework startup, DB access and template rendering dominate, diluting the multiplier — see the next section.

**v1.1.3 JIT-friendly mode (same stack architecture, one less closure call)**: since 1.1.3 the operand-stack encoding is **inlined into the `MCPUSH` closure** and the interpreter stays a flat mega-function, removing the old `MCPUSH → MCENC` nested closure call. Measured (isolated stack-encoding path, 2M-push dense loop, CLI with OPcache JIT off, median of 5):

| Mode | Pure computation (2M pushes) | vs. nojit | Semantics equal |
|---|---:|---:|:---:|
| jit inlined (on by default) | **64.05 s** | **1.00×** | ✓ |
| nojit closure (fallback) | 66.82 s | 1.04× | ✓ |

The jit-inlined mode is **~4% faster**, and a flat function is easier for OPcache tracing-JIT to compile. Both modes send objects through the plaintext slot `$___MCPLAIN___` (flag1, returned by reference), so property write-back is lossless. `--no-vm-jit` restores the old closure stack (byte-identical semantics). Note: this VM is a PHP implementation with "one closure call per instruction", so OPcache tracing-JIT helps only marginally overall (deep inlining across closures is hard). The jit mode's value is mainly "a flat function the JIT accepts more readily + one less closure call"; **its effect on the end-to-end totals of §1 (real TP8 latency) is within single-digit percent and does not change the order-of-magnitude conclusion**, which is why §1 has no separate jit column.

**v1.1.4 in-VM anti-debug cost**: inside the dispatch loop, one re-check every 512–8192 instructions (one increment + one bitwise AND + skip when not hit) — **no extra branch** when not hit; the detection itself (`PHP_SAPI` comparison / `extension_loaded`) amortises over thousands of instructions and is negligible. Overall share of VM total time: **<3–5%** (≈2–3 ns/instruction). In a normal environment the pollution factor stays 0, opcode decoding is unchanged, and **the order-of-magnitude conclusions of §1 are unaffected**; `--no-vm-inloop` disables it entirely.

**v1.1.5 advanced VM environment self-check cost**: one `defined` + `constant` + `hash('sha256')` at initialisation (≈0.3 µs, imperceptible across a process); the periodic re-check inside the dispatch loop **reuses the v1.1.4 checkpoints** (no new branches, no new counters), so the only extra cost is "one more sha256 every 512–8192 instructions" ≈0.3 µs per few thousand instructions, **< 0.1%**. In a normal environment `PZ0` stays 0 and opcode decoding is byte-identical to previous releases; `--no-vm-selfcheck` disables it. Note: the extra `is_array(...)&&count(...)` in the polluted-state teardown runs once **before the interpreter exits**, off the hot path.

**v1.1.6 native state-machine layer cost**: the state machine is a **statement-level** rewrite (not per instruction), so cost sits in two places — ① **build time**: one token split + reorder per wrapped statement segment (`StatementSplitter::splitRanges`), a one-off cost linear in source size; ② **runtime**: the dispatcher turns "execute N statements sequentially" into "N state transitions", i.e. one extra integer comparison + one assignment per statement (~5–15 ns/statement). For a typical business file (hundreds of statements) that is **microseconds**, negligible against total execution time. With `--sm-passes=1` there is **no nested dispatch tree** (a single layer), the cheapest option; larger `--sm-complexity` means more statements per dispatcher and fewer jumps. Suggestion: use the `mid` defaults (8/2/2); on performance-sensitive servers, compromise with `--sm-passes=1 --sm-complexity=16`.

**v1.1.6 probe syntax self-check cost**: each probe group runs `php -l` at build time (subprocess, ~15–30 ms/group). At most 8 groups → **~0.1–0.25 s** extra at build time, one-off, no runtime impact. This is the necessary price for eliminating "zero build-time warnings, parse error only when the artifact runs".

**v1.1.7 changes**:

1. **`low` drops the `statemachine` layer** — one less token split/reorder at build time, and no more "N state transitions" (integer comparison + assignment, ~5–15 ns/statement) at runtime, so `low` sits even closer to native. `mid` / `max` / `ultra` keep their state-machine parameters (`high` / `min` never had it), so §1's numbers for those three are unaffected.
2. **Higher anti-LLM contradictory-comment density** (3/6/12 clusters per shell) — comments **produce no runtime instructions**, so runtime cost is exactly 0; only one extra `token_get_all` plus safe-boundary scanning and text filling at build time, milliseconds for a typical file, and the actual volume is bounded by the number of safe boundaries after whitelist filtering.
3. **VM runtime private / protected methods use `ReflectionMethod::setAccessible(true)`** — one extra reflection object per real class-method call (microseconds, off the hot path); the bytecode interpreter main loop is unchanged, so §1's order-of-magnitude conclusions hold.

**v1.1.8 VM symbol ciphertext cost**: measured (same fixture, same salt, alternating order, 15 runs each) — artifact size **54,092 → 54,260 bytes (+0.31%)**, runtime **on 112.4 ms/run vs off 112.4 ms/run** (difference **< 1%**, buried in measurement noise; not distinguishable).

The reason is that the feature's cost is one-off and off the hot path:
- **Build time**: one `base64(XOR)` per symbol, a few hundred symbols total **< 1 ms**;
- **Runtime**: one `base64_decode` + one XOR loop per symbol while unpacking, also **< 1 ms**; afterwards the program is field-identical to the previous release and the interpreter main loop **did not change by a single line**;
- **Size**: symbol strings are a small share of the container (instruction operands dominate), and outer gzip eats part of the base64 redundancy, so the net increase is only ~0.3%.

Conclusion: **this release is essentially imperceptible in performance and size**, safe to leave on by default.

**v1.1.8 container-format randomisation cost**: negligible to unmeasurable —
- **Build time**: 6 `deriveInt` calls + one 6-element array assembly (**nanoseconds**);
- **Runtime**: one extra 6-element mapping table (`$___TM___`) in the artifact, one extra **array lookup** (hash lookup, ~50 ns) per type-tag read. Against the per-instruction cost of the VM interpreter (hundreds of ns to microseconds) that is **< 0.1%**;
- **Size**: the table itself is < 200 bytes and compresses well under outer gzip — net increase **< 0.1%**.

**v1.1.8 "random salt fallback when no salt" cost**: negligible — one extra `random_bytes(16)` per build (nanoseconds), in exchange for making the no-salt path **share the same code path** as the explicit-salt path (same deterministic derivation branch, eliminating the long-untested `random_*` fallback). With `--salt` given, none of this triggers.

---

## 5. Real project: ThinkPHP 8 page latency (v1.1.1, five presets)

The same TP8 sample (22 files / 16 classes, with ORM / transactions / cache / middleware / validators / events), each variant served by its own `php -S` with **its own SQLite database** and the same seed data. `GET /shop/products` (cache + accessors + JSON fields + relation counts), median of 5:

| Variant | Latency | vs. native | Artifact size |
|---|---:|---:|---:|
| Native | 2.0 ms | 1.0× | — |
| `min` | **1.6 ms** | **0.8× (faster)** | 104 KB |
| `low` | 5.7 ms | 2.8× | 228 KB |
| `mid` | 12.4 ms | 6.2× | 412 KB |
| `high` | 44 ms | 22× | 2.1 MB |
| `max` | 391 ms | 196× | 3.3 MB |
| `ultra` | 485 ms | 242× | 6.8 MB |

> `min` being **faster than native** is not a measurement error: it strips comments and squeezes
> whitespace, so PHP parses and opcache-compiles fewer bytes, while the added cost is one native
> `base64_decode` / `gzuncompress` / `chr` assembly per string (multi-scheme, no per-character PHP
> loop) — roughly cancelling the saved compile bytes, for a net gain. It also means `min` is the
> **only preset you can roll out site-wide without thinking** — **no anti-debug probes, no `eval`,
> no `gzinflate`, no output buffering**; decoding is entirely native and the artifact is still a
> plain PHP file.

> Two independent re-runs (separate databases + seeds + warm-up) confirm stability: `mid` 11.12 /
> 11.13, `high` 44.4 / 45.7, `max` 395 / 409, `ultra` 473 / 476 (ms); `low` varies more (6.3 / 8.2)
> because its decode cost is already close to PHP start-up jitter. **Early `max` / `ultra` numbers
> were extrapolated from `high`'s multiplier (~46× / ~60×) and the reality was 5× slower** — the
> extra `anti_ast` / honeypot / newline poison / struct poison / anti-tamper shells each run a
> decode and an integrity check **per request**, a **fixed per-request cost** independent of
> business code size.

Three observations:

1. **Absolute latency is milliseconds, not minutes** — the real cost of a TP8 page (framework startup, DB, rendering) sits outside the VM; the VM only interprets the business-code part. A 50 ms delay is perfectly acceptable for back-office / internal systems.
2. **`min` matches or beats native**, `low` / `mid` land at **6–12 ms**, the same order of magnitude as native, and can go straight onto production request paths. `high`'s 44 ms is still acceptable; `max` / `ultra`'s 391–485 ms is a **fixed per-request cost** (multi-layer shell decoding + integrity checks), suitable for low-frequency high-value entry points, not high-QPS APIs — their purpose is "single file / core algorithm", not whole sites.
3. All 11 endpoints are **byte-identical across all six presets** (including POST, cache, aggregation, and the signature middleware's 401 branch), which means these numbers correspond to **semantically correct** artifacts. The early high/max empty-body bug was fixed in 1.1.1 (see CHANGELOG).

---

## 6. Cost attribution: which switch spends the money

The same "200k arithmetic loop" workload (native pure computation 1.4 ms, process start baseline ~23 ms), switches enabled one at a time:

| Variant | Cold-start total | vs. native | Note |
|---|---:|---:|---|
| Native | 24 ms | 1.0× | Baseline (includes 23 ms process start) |
| Variable renaming only `--var-length=8` | 25 ms | ~1.0× | **essentially free** |
| Variable renaming + `--junk-runtime` (intensity 8) | 25 ms | ~1.0× | runtime junk is negligible |
| `--vm` (bare VM) | 1,640 ms | ~68× | **the VM jumps two orders of magnitude on its own** |
| `--vm --vm-junk=80` | 1,991 ms | ~83× | bytecode junk really executes, +21% |
| `--vm --vm-p8` | 1,596 ms | ~66× | morph/keychain/builtinReplace add almost no runtime cost |
| `--vm --vm-junk=80 --vm-p8` | 2,240 ms | ~93× | ~+37% combined |
| Preset `low` | 25 ms | ~1.0× | no VM |
| Preset `mid` | 26 ms | ~1.1× | no VM |

Conclusions:

- **The VM is the only order-of-magnitude switch**; everything else (variable/function/class renaming, string XOR, number encoding, control flow + GOTO, semantic rewriting, runtime junk, per-layer shells) is **constant or negligible** — the proof is that 1.1.0 → 1.1.1 pushed `low`/`mid` to "almost everything on" and pure computation only went from 1.17× to 1.64×.
- `--vm-junk` is the only VM-internal parameter that clearly adds runtime cost (junk instructions **really execute**, they are not skipped).
- `morph` / `keychain` / `builtinReplace` / `probe` / `fakeFog` mostly add **size and build time**, with almost no runtime change — the best value-for-money hardening.
- `--vm-probe` probes and `anti_debug` / `anti_tamper` self-checks are **once-per-request** fixed costs that do not grow with business volume.

---

## 7. Build time and artifact size (TP8 sample, 22 files)

| Preset | Build time | Artifact size |
|---|---:|---:|
| `low` | 5.1 s | 228 KB |
| `mid` | 10.3 s | 412 KB |
| `high` | 9.0 s | 2.1 MB |
| `max` | 14.2 s | 3.3 MB |
| `ultra` | 15.0 s | 6.9 MB |

- Size inflation comes mainly from VM bytecode + decoy functions + encoding shells (base64 is ~1.33×, GZ compresses part of it back).
- Build time grows with `fakeFog` / `junk` / `layers`; `ultra` (nested VM) is the slowest and should be run offline.
- Different `salt` values change the random amounts for `fakeFog` / decoys, so size for the same preset can fluctuate **±40%** (existing behaviour).

---

## 8. Selection advice

| Scenario | Suggestion | Why |
|---|---|---|
| High-QPS web APIs, internal systems | `low` or `mid` | millisecond cost, yet already full function/class/string/number/control-flow obfuscation plus multi-layer shells |
| Distributed components / SDKs | `high` | worth tens of times the cost for VM protection |
| Licence checks, billing algorithms and other critical fragments | `max` (only those files; `mid` elsewhere) | confines the VM cost to a few files |
| CLI batch jobs / offline tasks | `max` / `ultra` | runtime hardly matters; maximum protection |
| Framework projects (ThinkPHP / Laravel, …) | presets as-is (all five verified) | since v1.1.1 presets keep property/parameter names by default, so framework name-based property access and route parameters survive |

**Layered mixing** is the most practical approach: apply `max` to a handful of core files and use `mid` for everything else.

```bash
# Strong protection for a core file
php entry.php --input=app/service/PriceCalculator.php \
    --output=dist/service/PriceCalculator.php --level=max --salt=$SALT

# Low-cost obfuscation for the rest
php entry.php --input=app/controller --output=dist/controller --level=mid --salt=$SALT
```

---

## 9. Known performance characteristics (design, not bugs)

1. **VM cost ∝ statements executed**, largely independent of file size — a small function called 100k times is far more expensive than a large file loaded once.
2. **Function/method calls are the most expensive VM operation** (frame setup + argument binding + reference write-back). Call-dense code is amplified noticeably under `high`.
3. **Generators / large arrays** use more memory under the VM (measured peak 6 MB vs 2 MB native).
4. **Decoding happens once per request**: without OPcache, eval'd code does not enter the bytecode cache, so under FPM every request re-decrypts and re-compiles the outer shells. At high QPS, enable OPcache and consider `--opcache` warm-up.
5. To turn off a costly switch: `--vm-junk=0`, `--no-vm-cpool`, `--no-newline-poison`, `--no-shell-shield` (the last one affects size and decode time the most).
6. **Under VM presets user functions become bytecode**, not PHP functions: `function_exists()` / string callbacks (`call_user_func('name')`) are resolved inside the VM (since v1.1.1 including absolute names such as `App\Util\name`). Code that reflects over user functions will not see them under VM presets — use `low` / `mid` for that.

---

## 10. v1.2.5: super instructions, constant folding, function splitting

**Super instructions (`--vm-super-isa=N`)** reduce main-loop iterations: a composite instruction executes 2–4 sub-instructions in one fetch + one dispatch. Measured on a 400k-iteration arithmetic loop (bare `--vm`, best of 3 per setting):

| `--vm-super-isa` | Pure computation | vs. off | Artifact correct |
|---|---:|---:|:---:|
| 0 (off) | 1.96 s | 1.00× | ✓ |
| 3 (default) | 1.93 s | 0.98× | ✓ |
| 8 | 1.94 s | 0.99× | ✓ |
| 16 | 1.97 s | 1.01× | ✓ |

**The gain is at the measurement-noise level (±2%)**: what is saved is one main-loop iteration per sub-instruction (array read + two XORs + jump-table lookup + `switch` dispatch, tens of ns), while the composite handler still performs each sub-instruction's operand decoding and stack access — and that is where the VM actually spends its time. So the point is not "speed" but **anti-analysis at zero cost**: unfamiliar opcodes appear and instruction-frequency statistics fail, while merging adds no runtime cost and no size (the `NOP` padding keeps segment lengths unchanged). Hence on by default (3). Build time only adds one n-gram pass and per-segment replacement — measured 0.27 s → 0.29 s on `mixed_advanced.php`.

**Constant folding (`constfold`)** only affects **build time**: a pure literal expression is evaluated once with `eval`-free arithmetic and replaced by its literal. Artifacts get **smaller** (fewer tokens), build time increases by milliseconds, and runtime cost is **negative** (the computation no longer happens at all). On `min` this comes for free, which is why the preset includes it.

**Function splitting (`funcsplit`)** shifts a little work from "inline statements" to "one function call":
- Runtime: one extra user-function call per extracted block (~0.1–0.3 µs, dominated by argument binding); PHP's call overhead is small but not zero, so `func_split_level=3` on a hot inner loop is measurable.
- Build time: one token pass + scope analysis per file (linear); negligible.
- Size: slightly larger (function definition + parameter list), typically **+2–4%** for statement-heavy files.
- Practical advice: for request-path code use level 1–2; level 3 suits offline scripts and licensing/algorithm fragments where readability matters more than microseconds.

**Whole-site builds (not recommended in production)** are measured in `CHANGELOG.md` v1.2.5: WordPress (1516 PHP files / 119 MB) at `min` builds in ~15 s and produces artifacts that all pass `php -l`; ThinkPHP 8 (hundreds of files) behaves the same. Nothing here changes the §1 conclusion: **the VM is the only order-of-magnitude switch**, and the v1.2.5 additions are all constant-level.


---

## 11. v1.2.6: equivalent-ISA splitting / 3-way decoys / interpreter morphing

**Equivalent-ISA splitting (`--vm-isa-split=N`)** is the one genuinely zero-cost item here. 400k-iteration arithmetic loop (bare `--vm`, best of 3 per setting):

| `--vm-isa-split` | Pure computation | vs. 1 | case labels |
|---|---:|---:|---:|
| 1 (off) | 1.95 s | 1.00× | 28 |
| 2 (default) | 1.95 s | 1.00× | 36 |
| 3 | 2.00 s | 1.03× | 40 |

The trick is that **multiple case labels of one semantic opcode share a single handler body** (`case A: case B: body`) instead of duplicating it: the bytecode really contains several functionally identical opcodes that are all used in rotation, yet the generated PHP barely grows and the I-cache footprint is unchanged. Dispatch remains one jump-table lookup, so split=2 lands inside measurement noise. split=3/4 enlarge the table further and show ~2–3% noise-level variance; the default of 2 balances effect and cost.

**Three-way decoys (`decoy` layer)** cost one `is_scalar()` plus a few pure functions (`strlen` / `crc32` / `count`) and one comparison **per rewritten statement** — typically tens of ns. The same-kind substitutions in the real branch only add a few local assignments and do not change algorithmic complexity. Build time is one linear token pass. Density is controlled by `--decoy-level` (12% / 25% / 45%); `high` / `max` / `ultra` enable it by default.

**Function splitting (`funcsplit` level 3 stmt mode)** now also moves state-changing statements into top-level functions, adding one userland call (~0.1–0.3 µs) per extracted block. The v1.2.5 advice still applies: levels 1–2 for request-path code, level 3 for offline scripts and licence/algorithm fragments.

**Interpreter morphing** is entirely compile-time-equivalent (decimal → hexadecimal, ASCII strings → `\xHH`) plus unreachable constant-false blocks; it changes neither the hot-path opcodes nor their order, so runtime cost is zero and build time only gains one token pass.

Whole-site max-level measurements (lint only, no execution) are in `CHANGELOG.md` v1.2.6.
