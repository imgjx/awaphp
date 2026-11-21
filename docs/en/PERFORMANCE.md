# PERFORMANCE.md — measured overhead

[简体中文](../PERFORMANCE.md) · **English**

> Scope: PHP 8.3.6 CLI, single-core container, OPcache off, fixed `salt` (the same salt reproduces the same bytes).
> This document describes **the current version only**; every number can be re-measured with the commands in section 2.
> Absolute milliseconds only compare within one machine — **ratios** travel.

---

## 1. Summary

| Preset | Real project page (TP8 `/shop/calc`) | Pure-compute micro-benchmark | Role |
|---|---:|---:|---|
| `min` | **1.05×** (3.14 → 3.29 ms) | 0.8× | no anti-debug / no shell / no `eval`; safe across a whole site |
| `low` | **18.3×** (→ 57.5 ms) | — | full token-level set + multi-layer shells; fine on request paths |
| `mid` | **36.6×** (→ 115 ms) | — | plus state machine / honeypots / anti-tamper; admin and internal systems |
| `high` | **89.5×** (→ 281 ms) | **3.0×** † | single-layer VM; core files only, or put a cache in front |
| `max` | **75.4×** (→ 237 ms) | **2.9×** † | single-layer VM + all shells; core files / licence checks |
| `ultra` | **258×** (→ 811 ms) | **3.8×** † | nested VM; offline jobs and critical algorithm fragments |

> † For `high`/`max`/`ultra` the "pure compute" column measures the **current default path**:
> the dispatcher `switch` emitted by the state-machine layer sends these files down
> "opaque payload nesting" (source encrypted and executed by the VM, no bytecode), so the
> ratio is only 3–4×. **Real bytecode execution costs one to two orders of magnitude more**
> (24× cold start / ≈450× pure compute on the same workload). See section 4 for both paths,
> the cause, and the follow-up plan.

Four points:

1. **The VM starts at `high`.** Its cost scales with the number of executed statements, not with file size.
2. **`max` and `high` cost almost the same on pure computation** (2.9× vs 3.0×). The page-level gap is entirely the **per-request shell decoding**, not business code. `max` measures slightly faster than `high` because the two paths chain different numbers of shells, so this fixed cost is not monotonic in preset strength.
3. **`ultra` is roughly 1.3× `high`**: more shells, finer payload chunking. Meant for low-frequency entry points only.
4. **The `logic` layer does not charge per iteration**: loop bricks become operator-level identities that emit no runtime instructions. See section 5.

---

## 2. How to measure (reproducible)

```bash
# A) Real project: TP8 site (app/ + route/ + config/ obfuscated, vendor copied verbatim)
php entry.php --input=longtmp/tp8demo/tp8 --output=/tmp/tp8_low -low \
    --whitelist-dirs=vendor --salt=tp8v14
# The entry engine only emits .php artifacts; copy non-PHP assets (.env / .tpl / static files /
# runtime databases) yourself, or use other/thinkphp8-build.php, which does it for you.
php8.3 -S 127.0.0.1:9202 -t /tmp/tp8_low/public          # one copy + one SQLite per preset
curl -s -o /dev/null -w '%{time_total}\n' 'http://127.0.0.1:9202/index.php/shop/calc'

# B) Pure-compute micro-benchmark (VM presets)
php8.3 tests/perf_bench.php --runs=5 --levels=high,max,ultra --workload=tests/perf_workload_vm.php

# C) Loop-heavy code: injection-shape comparison (section 5)
php entry.php --input=/tmp/bench6.php --output=/tmp/on.php  -low --no-loop-fold
php entry.php --input=/tmp/bench6.php --output=/tmp/off.php -low --no-loop-fold --no-logic-loop-light
```

Metric definitions:

| Metric | Meaning |
|---|---|
| Cold-start wall time | median of `php <artifact>`, including process start (≈25 ms on this machine) |
| Pure compute | measured with `hrtime` inside the workload — **excludes** process start and decoding |
| Decode/compile cost | cold start − pure compute − empty-script baseline ≈ shell decryption + `eval` compilation (once per request) |
| Peak memory | `memory_get_peak_usage(false)` (exact bytes; `true` rounds to allocation blocks and hides differences within a few MB) |
| Page latency | `curl` `time_total`, median of 21 **alternating** samples (all presets in rotation, removing drift) |

Every variant **must print the same checksum** as native, otherwise the row is void — performance numbers mean nothing if semantics broke.
All six presets returned a **byte-identical** `/shop/calc` body (184 bytes, sha1 `c87187d0f3ff`).

---

## 3. Real project: ThinkPHP 8 site (ORM, cache, middleware, validators, events)

Single endpoint `/shop/calc` (price calculation: DB lookup → discount → bucketing → FX → JSON), 21 alternating samples.
Size is the whole site directory, including the verbatim `vendor` copy:

| Preset | Median | p90 | vs native | Size |
|---|---:|---:|---:|---:|
| native | 3.14 ms | 3.43 ms | 1.00× | 4.7 MB |
| `min` | 3.29 ms | 3.62 ms | **1.05×** | 4.7 MB |
| `low` | 57.5 ms | 58.9 ms | **18.3×** | 5.3 MB |
| `mid` | 115.1 ms | 119.1 ms | **36.6×** | 5.4 MB |
| `high` | 281.1 ms | 286.9 ms | **89.5×** | 6.9 MB |
| `max` | 236.9 ms | 244.6 ms | **75.4×** | 7.7 MB |
| `ultra` | 811.1 ms | 845.2 ms | **258×** | 18 MB |

Reading the numbers:

- `min`'s 1.05× is the net effect of compile-time literal folding plus renaming — same speed as native, same size.
- The fixed cost of `low` / `mid` is mostly the **per-request decode chain** (`low`: 2 eval layers + gz; `mid`: 5 eval layers + anti-tamper + state machine), independent of business volume.
- `high` / `max` split into shell decoding (fixed) and bytecode interpretation (grows with executed statements). p90 sits close to the median, so the curve flattens once caches warm up.
- `ultra`'s 18 MB is a burden in itself (disk + IO on every `include`); offline only.

---

## 4. Pure-compute micro-benchmark: the two VM paths

Workload: integer/string-heavy computation plus generators, fixed checksum. Median of 5 runs (`tests/perf_bench.php`).

### 4.1 Default path: opaque payload nesting

| Variant | Pure compute | vs native | Peak memory | Decode/compile | Build time |
|---|---:|---:|---:|---:|---:|
| native | 1.0 ms | 1.00× | 0.64 MB | — | — |
| `high` | 3.1 ms | **2.97×** | 3.93 MB | 13.4 ms | 974 ms |
| `max` | 3.1 ms | **2.91×** | 4.49 MB | 15.2 ms | 1,008 ms |
| `ultra` | 4.0 ms | **3.78×** | 10.14 MB | 45.2 ms | 864 ms |

On this path the bytecode only does "decode + `_PHPRUN`", so the business statements still
run inside the PHP engine and the ratio stays at 3–4×. What you pay for is the **per-request
shell decoding** (13–45 ms) and the memory.

### 4.2 Bytecode path: what it really costs

The same workload built with bare `--vm` (no state-machine layer), so statements are compiled
to VM bytecode and interpreted one by one:

| Variant | Cold start | Pure compute (derived) | vs native |
|---|---:|---:|---:|
| native | 21.8 ms | 1.0 ms | 1.00× |
| bytecode VM | **490 ms** | ≈450 ms | **≈450×** |

"Pure compute" is derived by subtracting process start-up (19–22 ms) and shell decoding
(≈15 ms). One dispatch per statement — the more granular the loop body, the worse it gets.
This path belongs on a handful of core files only.

### 4.3 Why `high` lands on 4.1 by default (known limitation)

The VM compile entry has a conservative guard: a `case` label must be a compile-time literal.
The chain is: `statemachine` emits a dispatcher `switch` → the `number` layer that runs after
it rewrites labels into `(N^0)` expressions → the guard decides "label is not a literal" →
the whole file falls back to `vmPayloadNest`. The build log shows it (once per reason per
build; per-file detail with `--verbose`):

```
[WARN] shell:vm 未做字节码编译，改走不透明载荷嵌套（…）：case 标签为关键字名
```

`compileSwitch()` already compiles each label as a **comparison chain**, so it does not need
constants; accepting expression labels is feasible at the compile level. But right after that
comes a second problem: the function declarations injected by `funcsplit` / `module_link` end
up wrapped in `case` bodies, and the VM has no "register a function at runtime" instruction
(`DEF_FUNC`), so the file still falls back. Both have to be fixed **together**; that is the
first item for v1.3.1.

This release **does not change** the behaviour — it only turns a silent `info` into a
`warn` deduplicated by reason: protection strength is part of the product promise and does
not belong behind a log level.

---

## 5. Loop-heavy code: what the `logic` layer costs

### 5.1 The problem

Regular `logic` bricks are **function-call shaped** (`strlen(sha1('…'))*N`, `base64_decode(…)` chains).
Semantically they are pure values, but the evaluation count is multiplied by the loop:

```php
for ($i = 0; $i < 20000; $i++) { $acc += strlen('abcdefghijklmnop'); }
```

Put that in a loop body and it runs 20000 times; the larger the iteration count, the worse it gets.

### 5.2 The approach

Before rewriting, every loop's iteration count is estimated statically (`for` init/bound/step, the assignment before a `while`, `foreach` array literals / `range()`). Inside loops above the threshold (default **20**), literals in per-iteration regions (`for` condition and step, `while` / `do-while` condition, loop body) become **operator-level identities**:

```php
// inside the loop: compile-time constant folding, zero runtime instructions
for ($_i = ((0-0)); $_i < $_n; $_i++) {
    $_acc += strlen('abcdefgh' . 'ijklmnop');   // concatenation happens at compile time
    if ('y' . 'es' === 'y' . 'es') { $_acc += ((1-0)^(00)); }
}
```

The source contains no bare literal and no constant readable at a glance, yet no function call is amplified by iteration.
Outside loops, and in loops at or below the threshold, the full brick set is still used.

### 5.3 Measurements

Workload: 20000 iterations with a runtime-determined count (**cannot** be folded at build time), body containing one string literal and one always-true comparison. Median of 7 runs:

| Build | Runtime | vs native |
|---|---:|---:|
| native | 0.20 ms | 1× |
| `low` (default) | **3.10 ms** | 15.5× |
| `low` (`--no-logic-loop-light`) | **40.8 ms** | 204× |
| `mid` (default) | **3.11 ms** | 15.6× |
| `mid` (`--no-logic-loop-light`) | **61.0 ms** | 305× |
| `high` (payload nesting) | **0.54 ms** | 2.7× |

The default shape spends 3.1 ms on 20000 iterations (≈0.15 µs per iteration, mostly the one-time shell decode and the `strlen` call itself). With light injection off, every iteration recomputes the bricks, and the gap widens with the iteration count.
v1.3.0 also replaced the brick itself — `strlen(md5('…'))` (≈0.3 µs per evaluation) became decimal chunking plus bit masks (≈0.02 µs) — so the "default" row is another ~18% cheaper than v1.2.14.

### 5.4 Boundaries

Only **pure value literals** are touched. Everything below is left alone:

- constant contexts: `const` / parameter defaults / property defaults / `case` labels / `#[…]` / `declare(...)`;
- jump-level operands (`continue 2` / `break 3` must stay integer literals);
- function bodies, closures, arrow functions and class bodies — those literals do **not** execute per iteration;
- loops at or below the threshold — they keep the full brick set and higher obfuscation density;
- loops whose count cannot be estimated (`while (true)`, `while ($row = f())`) — treated as large.

The string layer follows the same rule: `StringEncoder` / `StringRuntimeAssembler` share the loop analysis (`LoopScanTrait`), so strings inside large loops are not XOR-assembled and decoded — they stay literal or compile-time concatenated.

**Switches**: `--no-logic-loop-light` to disable; `--logic-loop-threshold=N` to move the threshold (0 = treat every loop as large). Enabled by default in all six presets and in per-shell `logic` passes.

---

## 6. Cost attribution: where the money goes

| Switch | Magnitude | Notes |
|---|---|---|
| `shell:vm` | **3–4× (payload nesting), up to ≈450× (bytecode)** | the only switch that grows with executed statements; function/method calls are the most expensive operation |
| `logic` (`--logic-intensity`) | **≈ 0** | inside loops only operator-level identities (compile-time folded, zero runtime instructions); outside loops a one-off value |
| `shell:gz` / `shell:eval` / `shell:anti_*` | **15–60 ms fixed per request** | decoding + integrity checks, independent of business volume |
| `--vm-junk` | +20% or so | junk instructions really execute; they are not skipped |
| `morph` / `keychain` / `builtinReplace` / `probe` / `fakeFog` | ≈ 0 at runtime | size and build time only — the best value in the toolbox |
| `constfold` | **negative cost** | literals are computed at build time and the runtime computation disappears |
| `funcsplit` | +0.1–0.3 µs per call | turns an inline statement into a function call; `level=3` inside a hot loop is measurable |
| `var` / `func` / `class` renaming | ≈ 0 | pure static replacement |

`--vm-super-isa`: on/off differences sit inside the noise band of 21 samples (±2 ms wall time).
It reduces dispatch count, but the composite handler still decodes each sub-instruction, cancelling the saving.
Its role is **anti-analysis at zero cost** — do not expect a speed-up.

---

## 7. Known performance characteristics and workarounds

1. **Decoding is once per request**: without OPcache, `eval`ed code does not enter the bytecode cache. At high QPS, enable OPcache (`--opcache` pre-warm) or every request re-decrypts and recompiles the outer shells.
2. **`ultra` is for low-frequency entry points only**: pages reach 258× and artifacts reach 18 MB. Fine for a single critical algorithm file; not for a whole site.
3. **Under the VM, user functions become bytecode**, not PHP functions: `function_exists()` / string callbacks are resolved inside the VM. Code that walks user functions via reflection should stay on `low` / `mid`.
4. **Generators / large arrays use more memory under the VM**: micro-benchmark peak 3.9 MB vs 0.6 MB native (`high`), 10.1 MB for `ultra`.
5. **Function names are made unique across a whole site**: `logic`'s injected `__mod_*` bricks, once inlined by `module_link`, carry a **file-derived segment** (`__ml<layer><file>_<name>`), so multi-file projects never hit `Cannot redeclare`. Same source plus salt stays reproducible.
6. **Hand-written loop-heavy algorithms**: `logic` no longer charges per iteration, but `funcsplit` (level 3) and `statemachine` still do. Prefer `high` (VM folds constants at compile time) or whitelist the hot function.
7. **The entry engine only emits `.php`**: for whole-site builds, copy non-PHP assets (`.env` / `.tpl` / static files / runtime databases) yourself or use the one-click script, which does it. A missing `.tpl` breaks ThinkPHP's exception page — invisible on the happy path, fatal when something actually throws.
8. **`case` labels push a file onto "payload nesting"**: see 4.3. The build log (a `warn`, deduplicated by reason) names those files; use `--verbose` for per-file detail.
9. To switch off a specific cost: `--no-logic-loop-light`, `--no-vm-super-isa`, `--vm-junk=0`, `--no-vm-cpool`, `--no-newline-poison`, `--no-shell-shield` (the last one affects size and decode time the most).

---

## 8. Choosing a preset

| Situation | Preset | Why |
|---|---|---|
| High-QPS web APIs, internal systems | `low` or `mid` | 18–37×, still tens of milliseconds; `logic` no longer punishes loops |
| Hand-written algorithms / loop-heavy code | `high` | constants folded at compile time; 3× pure compute, more predictable than `mid`'s page cost |
| Distributed components / SDKs | `high` | single-layer VM, controlled cost, enough anti-analysis |
| Licence checks, billing core fragments | `max` (on those files only) | VM + all shells, cost confined to a few files |
| CLI batch jobs / offline tasks | `max` / `ultra` | runtime does not matter, maximum protection |
| Framework projects (ThinkPHP / Laravel) | use the presets as-is | all six are byte-identical to native; property and parameter names are preserved by default |

**Mixing layers** is the most practical setup: `max` for core files, `mid` for the rest.

```bash
php entry.php --input=app/service/PriceCalculator.php \
    --output=dist/service/PriceCalculator.php --level=max --salt=$SALT

php entry.php --input=app/controller --output=dist/controller --level=mid --salt=$SALT
```
