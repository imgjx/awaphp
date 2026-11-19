# PERFORMANCE.md — measured cost

[简体中文](../PERFORMANCE.md) · **English**

> Baseline: **v1.2.13**, PHP 8.3.6 CLI, single-core container, OPcache off, fixed `salt` (same salt reproduces byte-identically).
> This document describes the **current** version only; every number can be re-run with the commands in section 2.
> Absolute milliseconds only compare within one machine — **ratios** travel.

---

## 1. Summary

| Preset | Real project page (TP8 `/shop/calc`) | Pure compute | Positioning |
|---|---:|---:|---|
| `min` | **1.02×** (3.18 → 3.25 ms) | 1.0× | No anti-debug / no shell / no `eval`; safe to roll out everywhere |
| `low` | **15.8×** (→ 50 ms) | — | Full token pipeline + multi-layer shells; usable on request paths |
| `mid` | **44.6×** (→ 142 ms) | — | Above + state machine / honeypot / anti-tamper; back office and internal systems |
| `high` | **74.8×** (→ 238 ms) | **10.1×** | Single VM; core files only, or behind a cache |
| `max` | **83.6×** (→ 266 ms) | **10.8×** | Single VM + full shells; core files / license checks |
| `ultra` | **246×** (→ 782 ms) | **50.4×** | Nested VM; offline jobs and critical algorithm fragments |

Four take-aways:

1. **The VM starts at `high`.** Its cost scales with *executed statements*, not with file size.
2. **`max` and `high` cost almost the same per computation** (10.8× vs 10.1×). The 1.1× difference on the page is entirely the **per-request shell decode + integrity check**, not business code.
3. **`ultra` costs 3.3× more than `high`**: nested VM plus layered state machines — an interpreter interpreting an interpreter. Offline entry points only.
4. **The `logic` layer no longer charges per iteration** (v1.2.13). Identities in loops are hoisted out and evaluated once. See section 5.

---

## 2. How to reproduce

```bash
# A) Real project: TP8, 25 PHP files (app/ + route/ + config/, vendor left untouched)
cp -a longtmp/tp8demo/tp8 /tmp/tp8src
php entry.php --input=/tmp/tp8src --output=/tmp/tp8_low -low --whitelist-dirs=vendor --salt=tp8v1213
php8.3 -S 127.0.0.1:9202 -t /tmp/tp8_low/public          # one copy + one SQLite per preset
curl -s -o /dev/null -w '%{time_total}\n' 'http://127.0.0.1:9202/index.php/shop/calc'

# B) Pure compute microbenchmark (VM presets)
php8.3 tests/perf_bench.php --runs=5 --levels=high,max,ultra --workload=tests/perf_workload_vm.php

# C) Loop-invariant hoisting: on/off (section 5)
php entry.php --input=/tmp/bench4.php --output=/tmp/on.php  -low --no-loop-fold
php entry.php --input=/tmp/bench4.php --output=/tmp/off.php -low --no-loop-fold --no-logic-loop-hoist
```

Metrics:

| Metric | Meaning |
|---|---|
| Cold start | Wall clock of `php <artifact>`, including process start (≈ 24.3 ms CLI on this box) |
| Pure compute | Measured with `hrtime` inside the workload — excludes process start and decode |
| Decode / compile | Cold start − pure compute − empty-script baseline ≈ shell decrypt + `eval` compile (once per request) |
| Peak memory | `memory_get_peak_usage(false)` — exact bytes; `true` rounds to allocation blocks and hides anything below a few MB |
| Page latency | `curl` `time_total`, median of 15 **interleaved** rounds (all presets take turns, removing order drift) |

Every variant **must** print the same checksum as native, otherwise the row is void — performance numbers are meaningless once semantics break.
All six presets returned **byte-identical** `/shop/calc` bodies in this run.

---

## 3. Real project: ThinkPHP 8 whole site (25 files — ORM, cache, middleware, validators, events)

Single endpoint `/shop/calc` (price calculation: DB → discount → buckets → FX → JSON), 15 interleaved rounds.
Size is the whole site directory, including the 4.2 MB `vendor` copied verbatim:

| Preset | Median | p90 | vs native | Size |
|---|---:|---:|---:|---:|
| native | 3.18 ms | 3.42 ms | 1.00× | — |
| `min` | 3.25 ms | 3.83 ms | **1.02×** | 3.9 MB |
| `low` | 50.4 ms | 55.3 ms | **15.8×** | 4.6 MB |
| `mid` | 141.7 ms | 149.9 ms | **44.6×** | 5.3 MB |
| `high` | 237.9 ms | 324.8 ms | **74.8×** | 7.0 MB |
| `max` | 265.8 ms | 271.8 ms | **83.6×** | 7.3 MB |
| `ultra` | 782.0 ms | 791.6 ms | **246×** | 15 MB |

Reading the numbers:

- `min` at 1.02× is the net effect of build-time constant folding plus renaming — effectively native speed.
- `low` / `mid` are dominated by the **per-request decode chain** (`low`: 2 eval layers + gz; `mid`: 5 eval layers + anti-tamper + state machine), independent of business volume.
- `high`'s p90 is well above its median (325 vs 238 ms): the VM compiles bytecode on first load, so cold requests cost more. With OPcache enabled the curve flattens.
- `ultra`'s 15 MB is a cost by itself (disk + IO on every `include`). Offline only.

---

## 4. Pure compute microbenchmark: VM presets

Workload: integer/string-heavy computation plus generators, fixed checksum. Median of 5 runs:

| Variant | Pure compute | vs native | Peak memory | Decode/compile | Build time |
|---|---:|---:|---:|---:|---:|
| native | 1.3 ms | 1.00× | 0.64 MB | — | — |
| `high` | 12.6 ms | **10.1×** | 6.44 MB | 23.1 ms | 1.8 s |
| `max` | 13.5 ms | **10.8×** | 7.14 MB | 26.0 ms | 1.9 s |
| `ultra` | 63.0 ms | **50.4×** | 13.84 MB | 63.8 ms | 1.7 s |

- The VM's cost is **interpretation**: one dispatch per statement. 10× matches "each statement becomes ~10 VM ops".
- `ultra` is a VM inside a VM — the outer interpreter has to interpret the inner interpreter's loop, hence 5× `high` rather than 2×.
- Memory growth comes from the **bytecode constant pool and symbol table**; it scales with source size, not runtime.

---

## 5. Loop-invariant hoisting (v1.2.13)

### 5.1 The problem

The `logic` layer rewrites every literal into a **function-call identity**:

```php
// source
for ($i = 0; $i < 20000; $i++) { $acc += strlen('abcdefghijklmnop'); }

// after logic — both fragments are really evaluated on every iteration
for ($i = 0; $i < ((strlen(sha1('Q5KtYDi'))*500)-0); $i++) {
    $acc += strlen(hex2bin('616263').str_rot13('def'));
}
```

Semantically these are pure values, but **the loop multiplies their evaluation count**: 20 000 rounds = 20 000 SHA-1 calls plus 20 000 string decodes.
That is why `low` / `mid` were two orders of magnitude slower than native on loop-heavy code.

### 5.2 The fix

Before rewriting, `logic` now runs a **loop-invariant code motion** pass:

1. Statically estimate each loop's trip count (`for` init/bound/step, the assignment right before a `while`, a `foreach` over an array literal or `range()`);
2. When the count is **> threshold (default 20)**, lift every hoistable literal from the per-iteration regions (condition, step, body) to **in front of** the loop, each into a temporary variable;
3. Conditions and bodies then only read variables, and the identity is built exactly once, outside the loop.

```php
// after hoisting
$_90962bb54 = (((strlen(sha1('qfOBSxw'))*500)^0) + (in_array(1,[])?0:0));   // evaluated once
for ($i = (0); ((($i) <=> ($_90962bb54)) === -1); $i++) { $acc += $i; }
```

### 5.3 Measured

Workload: 20 000 rounds, trip count from runtime (**not** foldable at build time), body holds one string literal and one comparison.
Median of 7 runs:

| Build | Runtime | vs native |
|---|---:|---:|
| native | 0.2 ms | 1× |
| `low` (default, hoisting on) | **4.1 ms** | 20× |
| `low` (`--no-logic-loop-hoist`) | **669 ms** | 3345× |
| `mid` (default, hoisting on) | **3.7 ms** | 18× |
| `mid` (`--no-logic-loop-hoist`) | **506 ms** | 2530× |

**Hoisting removes the 25–34 µs per-iteration identity cost entirely**; the remaining 4 ms is shell decoding (once per request, independent of trip count).
With hoisting off, every extra round pays for another hash/decode — the gap widens with the trip count.

### 5.4 Boundaries

Only **literals** move (pure values, no side effects, no evaluation-order dependency). Everything below is left untouched:

- constant contexts: `const` / parameter defaults / property defaults / `case` labels / `#[…]` / `declare(...)`;
- jump-level operands (`continue 2` / `break 3` must stay integer literals);
- function, closure, arrow-function and class bodies — those literals do **not** run per iteration, and hoisting them would make the body reference a variable it cannot see;
- loops at or below the threshold — spending a variable to save a few microseconds is a bad trade, so their original obfuscated shape stays;
- loops whose trip count cannot be estimated (`while (true)`, `while ($row = f())`) — treated as large, always hoisted.

When the loop body is a single statement, or the token before the loop is not a statement boundary (`else`, brace-less `if`), the whole segment is wrapped in `{ … }` so the prologue is not captured by `else` / `if`.

**Disable** with `--no-logic-loop-hoist`; tune with `--logic-loop-threshold=N` (0 = hoist every loop).
Enabled by default in all six presets and in the per-shell `logic` pass.

---

## 6. Where the cost actually is

| Switch | Order of magnitude | Notes |
|---|---|---|
| `shell:vm` | **Order of magnitude (10–50×)** | The only switch that scales with executed statements; function/method calls are the most expensive operation |
| `logic` (`--logic-intensity`) | **≈ 0** (since v1.2.13) | Literal identities are hoisted out of loops and evaluated once; short loops (≤ 20 rounds) keep their shape at negligible cost |
| `shell:gz` / `shell:eval` / `shell:anti_*` | **15–50 ms per request, flat** | Decode + integrity checks, independent of workload |
| `--vm-junk` | ~ +20% | Junk instructions are **really executed**, not skipped |
| `morph` / `keychain` / `builtinReplace` / `probe` / `fakeFog` | ≈ 0 at runtime | Size and build time only — the best value in the tool |
| `constfold` | **Negative cost** | Folds literals at build time; that computation disappears at runtime |
| `funcsplit` | +0.1–0.3 µs per call | Turns inline statements into a call; `level=3` inside a hot loop is measurable |
| `var` / `func` / `class` renaming | ≈ 0 | Pure static substitution |

`--vm-super-isa`: on/off differences within the same version fall inside the 15-run noise band (±1.5 ms wall).
It reduces dispatch count, but the composite handler still decodes each sub-instruction, so the saved dispatches are offset.
It is **anti-analysis at zero cost** — do not expect a speed-up.

---

## 7. Known characteristics and workarounds

1. **Decoding happens once per request.** Without OPcache, `eval`'d code is not cached as bytecode. At high QPS, enable OPcache (`--opcache` warm-up), otherwise every request re-decrypts and recompiles the outer shells.
2. **`ultra` is for low-frequency entry points only.** Nested VM: 50× on pure compute, 246× on the page, 15 MB of artifacts. Fine for a single core algorithm, not for a whole site.
3. **Under the VM, user functions become bytecode**, not PHP functions: `function_exists()` and string callbacks are resolved inside the VM. Code that walks user functions via reflection should use `low` / `mid`.
4. **Generators / large arrays cost more memory under the VM.** Microbenchmark peak 6.4 MB vs 0.6 MB native (`high`), 13.8 MB for `ultra`.
5. **Function names are unique per file.** The `__mod_*` identities injected by `logic`, once inlined by `module_link`, carry a **file-derived segment** (`__ml<layer><file>_<name>`), so multi-file projects never hit `Cannot redeclare`. Same source + same salt reproduces byte-identically.
6. **Loop-heavy custom algorithms.** `logic` no longer charges per iteration, but `funcsplit` (level 3) and `statemachine` still do. Prefer `high` for this code (the VM folds constants at compile time), or whitelist the hot functions.
7. **Turning individual switches off**: `--no-logic-loop-hoist`, `--no-vm-super-isa`, `--vm-junk=0`, `--no-vm-cpool`, `--no-newline-poison`, `--no-shell-shield` (the last has the biggest effect on size and decode time).

---

## 8. Choosing a preset

| Scenario | Preset | Why |
|---|---|---|
| High-QPS APIs, internal systems | `low` or `mid` | 16–45×, still tens of milliseconds; `logic` no longer drags loops down |
| Custom algorithms / loop-heavy code | `high` | The VM folds constants at compile time: 10× pure compute, more predictable than `mid`'s 45× page cost |
| Distributable components / SDKs | `high` | Single VM, bounded cost, plenty of resistance |
| License checks, billing core | `max` (on that file only) | VM + full shells, cost confined to a few files |
| CLI batch jobs / offline | `max` / `ultra` | Runtime does not matter; maximum protection |
| Framework projects (ThinkPHP / Laravel) | Use a preset as-is | All six presets are byte-identical to native; property and parameter names are preserved |

**Mixed layering** is the most practical setup: `max` for core files, `mid` for the rest.

```bash
php entry.php --input=app/service/PriceCalculator.php \
    --output=dist/service/PriceCalculator.php --level=max --salt=$SALT

php entry.php --input=app/controller --output=dist/controller --level=mid --salt=$SALT
```
