# PERFORMANCE.md — Measured Overhead

[简体中文](../PERFORMANCE.md) · **English**

> Scope: **v1.2.11**, PHP 8.5.12 CLI, single-core container, OPcache off, fixed `salt`
> (same salt ⇒ byte-identical artifacts). This document describes **only the current
> version**; every number can be reproduced with the commands in §2.
> Absolute milliseconds only compare on the same machine — **ratios** travel.

---

## 1. Summary

| Preset | Real project page (TP8 `/shop/calc`) | Pure-compute microbench | Positioning |
|---|---:|---:|---|
| `min` | **1.4×** (3.4 → 4.8 ms) | 1.0× | no anti-debug / no shell / no `eval`; deploy everywhere |
| `low` | **9.2×** (→ 31 ms) | — | full token layer stack + multi-layer shells |
| `mid` | **25×** (→ 83 ms) | — | + state machine / honeypot / anti-tamper |
| `high` | **52×** (→ 174 ms) | **12.9×** | single-layer VM; core files only |
| `max` | **73×** (→ 245 ms) | **12.9×** | VM + full shell stack |
| `ultra` | **212×** (→ 713 ms) | **286×** | nested VM; offline / key algorithms |

Three takeaways:

1. **VM starts at `high`.** Its cost scales with *executed statements*, not file size.
2. **`max` and `high` have identical pure-compute cost (12.9×).** The 1.4× gap between them is
   per-request shell decoding and integrity checks, not business code.
3. **`ultra` is 20×+ more expensive than `high`** — interpreter inside interpreter.

---

## 2. How to reproduce

```bash
# A) Real project: 25 PHP files of the TP8 demo (app/ + route/ + view/)
unzip -q tests/tptest.zip -d /tmp/tp8 && cd /tmp/tp8/tp8
php /workspace/entry.php --input=app   --output=app_obf   --level=high --salt=tp8perf
php /workspace/entry.php --input=route --output=route_obf --level=high --salt=tp8perf
php /workspace/entry.php --input=view  --output=view_obf  --level=high --salt=tp8perf
rm -rf app route view && mv app_obf app && mv route_obf route && mv view_obf view
php -S 127.0.0.1:8090 -t public public/router.php   # one copy + one SQLite per preset
curl -s -o /dev/null -w '%{time_total}\n' 'http://127.0.0.1:8090/shop/calc?base=100&qty=5'

# B) Pure-compute microbench (VM presets)
php tests/perf_bench.php --runs=5 --levels=high,max,ultra --workload=tests/perf_workload_vm.php

# C) Super-instruction on/off
php entry.php --input=tests/perf_workload_vm.php --output=/tmp/off.php -high --salt=perf-bench-v1 --no-vm-super-isa
```

Metric definitions:

| Metric | Meaning |
|---|---|
| Cold start | Wall-clock median of `php <artifact>`, including process start-up (≈ 28.7 ms here) |
| Pure compute | `hrtime` self-measured inside the workload — excludes start-up and decoding |
| Decode/compile | Cold start − pure compute − empty-script baseline ≈ shell decrypt + `eval` compile, **per request** |
| Peak memory | `memory_get_peak_usage(false)` (exact bytes; `true` rounds to allocation blocks and hides anything under a few MB) |

Every variant must emit the **same checksum** as native, otherwise the row is void — performance
numbers are meaningless when semantics are broken.

---

## 3. Real project: ThinkPHP 8 (25 files / 16 classes, ORM, cache, middleware, validators, events)

Native and each preset run from **their own copy with their own SQLite database**, same seed,
`php -S` single process, one warm-up request then median of 5:

| Variant | Build | Artifact size | `/shop/calc` | vs native | `/shop/dashboard` | vs native |
|---|---:|---:|---:|---:|---:|---:|
| native | — | — | 3.36 ms | 1.00× | 2.75 ms | 1.00× |
| `min` | 0.24 s | 132 KB | 4.8 ms | 1.43× | 4.0 ms | 1.45× |
| `low` | 13.2 s | 780 KB | 31.0 ms | 9.2× | 26.1 ms | 9.5× |
| `mid` | 27.7 s | 1.26 MB | 83.2 ms | 24.8× | 65.0 ms | 23.6× |
| `high` | 31.1 s | 3.19 MB | 173.5 ms | 51.6× | 123 ms | 44.7× |
| `max` | 33.1 s | 4.66 MB | 245 ms | 72.9× | 178 ms | 64.7× |
| `ultra` | 44.8 s | 13.25 MB | 713 ms | 212× | 553 ms | 201× |

`/shop/calc` is a compute-heavy endpoint (sorting, de-duplication, FX bucketing, null-safe chains);
`/shop/dashboard` renders a ~200-line PHP/HTML template. All six presets are **byte-identical** to
native on both endpoints.

**The `low` / `mid` overhead comes from the `logic` layer's identity building blocks, not from shell
decoding.** `logic` rewrites literals and comparisons into identities built from `__mod_sha1()` /
`__mod_strlen()` to defeat static evaluation; once such a block lands in a **loop condition or loop
body**, it becomes one hash per iteration:

| `$sum += $i;` × 2000 | Loop-only time |
|---|---:|
| native | 0.02 ms |
| `low` | **180 ms** (90 µs / iteration) |
| `mid` | **295 ms** |
| `low` without the `logic` layer | 0.02 ms |

This is why the pure-compute microbench can show thousands of times for `low` while the real page
shows 9×: business code loops far less. **Loop-heavy algorithms belong on `high`** (the VM folds
those constant blocks at compile time — 12.9×), or drop `logic` intensity to 1 on `low` / `mid`.

---

## 4. Pure-compute microbench: VM presets

`tests/perf_workload_vm.php` (1.9 KB: arithmetic loops, functions, class methods, arrays, string
hashing, recursion, closures, generators), native pure compute **1.31 ms**, median of 5:

| Variant | Build | Size | Cold start | Pure compute | Decode | Peak memory | vs native |
|---|---:|---:|---:|---:|---:|---:|---:|
| native | — | 1.9 KB | 28.8 ms | 1.31 ms | — | 716 KB | 1.00× |
| `high` | 1.78 s | 72.8 KB | 59.3 ms | 16.4 ms | 14.2 ms | 4.42 MB | **12.9×** |
| `max` | 1.73 s | 85.7 KB | 64.8 ms | 16.4 ms | 19.7 ms | 5.20 MB | **12.9×** |
| `ultra` | 1.62 s | 195.6 KB | 443.8 ms | 364.8 ms | 50.3 ms | 11.47 MB | **286×** |

- `high` and `max` have **identical pure compute**; `max`'s extra 5.5 ms of cold start is shell
  decoding + integrity checks — once per request, independent of workload.
- `ultra` is a nested VM (outer interpreter dispatching an inner one): 22× `high`, and 195 KB vs 73 KB.
- The gap to the real page (12.9× vs 52×) shows where time actually goes: framework boot, DB, templating.

---

## 5. Super instructions: on/off and v1.2.10 → v1.2.11

`--vm-super-isa` merges 2–4 hot instructions into one opcode. Since v1.2.11 the absorbed
sub-instructions are **really deleted** (v1.2.10 and earlier padded with `NOP`), and the builder
recomputes in-segment jump offsets plus exception-table pcs.

**Bytecode instruction count** (same workload, same salt, builder-side probe):

| | Before merge | After merge |
|---|---:|---:|
| v1.2.10 | 565 | 565 (NOP padding, count unchanged) |
| v1.2.11 | 565 | **454 (−19.6%)** |

**Runtime** (`salt=perf-bench-v1`, median of 15 interleaved runs, two independent experiments):

| Version | Super ISA | Cold start `high` | Pure compute `high` | Peak mem `high` | Cold start `max` | Peak mem `max` |
|---|---|---:|---:|---:|---:|---:|
| v1.2.10 | on | 60.9 ms | 16.96 ms | 3.96 MB | 62.7 ms | 4.75 MB |
| v1.2.10 | off | 61.3 ms | 17.02 ms | 3.96 MB | 62.6 ms | 4.74 MB |
| v1.2.11 | on | 61.8 ms | 16.73 ms | 4.64 MB | 66.1 ms | 5.45 MB |
| v1.2.11 | off | 62.1 ms | 16.78 ms | 4.62 MB | 64.1 ms | 5.44 MB |

**Conclusions — this is the answer to "is the new super-instruction slower?"**:

1. **Super instructions cost nothing.** Within a version, on/off differs by −0.4…+0.4 ms cold start
   and −0.1…+0.3 ms compute — **all inside the noise band** (the same artifact varies ±1.5 ms wall
   across 15 runs). The dispatches saved are offset by per-sub-instruction operand decoding inside the
   composite handler. That matches its purpose: **anti-analysis at zero cost**, not a speed-up.
2. **The +17% memory difference between versions (3.96 → 4.64 MB) has nothing to do with super
   instructions**: with super instructions disabled, v1.2.11 still sits at 4.62 MB. It is the price of
   the VM semantic fixes in §5.1 — statements that used to degrade to `EVAL_STMT` (one string handed
   to native `eval`) are now genuinely compiled into dozens of opcode arrays, which cost far more.
3. **`max` cold start +2–3 ms is stable and reproducible** (66.1 / 64.1 vs 62.7 / 62.6) and equally
   unrelated to super instructions (off still +1.5 ms) — it comes from unpacking a larger instruction array.
4. **gz size grows slightly**: `NOP` is a two-byte, highly repetitive `[199][0]` that gzip nearly
   eliminates. Enabling super instructions costs `high` +56…+184 B and `max` +176…+964 B; v1.2.11 vs
   v1.2.10 totals +376 B (`high`) / +1,448 B (`max`) = **+0.5% / +1.7%**.

### 5.1 VM semantic bugs fixed in the same release (their price is the memory above)

| Bug | Fix | Cost |
|---|---|---|
| Top-level variables in the opaque payload (`PHPRUN`) landed in closure locals; `global $x` read `null` | scan `global` / `$GLOBALS['k']` before execution and pre-bind as references | one scan per payload |
| Bitwise `$a & $b` failed to compile | accept all three `&` token forms | none |
| Bare block `{ ... }` unsupported | compile as a statement group | none |
| `__NAMESPACE__` unsupported | fold at build time | none |
| `funcsplit` extracted statements with `->` / `?->` / `::` into **native** functions while VM classes are `stdClass` proxies → `Call to undefined method` | stop extracting those | one less split pass |

The cost concentrates in "more statements really go into the VM": `high` peak memory +17%,
`max` +15%, pure compute within noise. In exchange `preset_e2e` went from "fails at random with a
different salt" to a stable 72/72.

---

## 6. Where the money goes

| Switch | Order of magnitude | Notes |
|---|---|---|
| `shell:vm` | **10–50×** | the only switch that scales with executed statements; calls are the most expensive op |
| `logic` (`--logic-intensity`) | **0 to thousands×**, depends on placement | in a loop condition/body = one hash per iteration; in the init section it is free |
| `shell:gz` / `shell:eval` / `shell:anti_*` | **5–50 ms per request, fixed** | decoding + integrity checks, independent of workload |
| `--vm-junk` | ~+20% | junk instructions are really executed |
| `morph` / `keychain` / `builtinReplace` / `probe` / `fakeFog` | ≈ 0 runtime | size and build time only — best value for money |
| `constfold` | **negative** | the computation disappears at build time |
| `funcsplit` | +0.1–0.3 µs per call | replaces inline statements with a call; `level=3` on a hot loop is measurable |
| `var` / `func` / `class` renaming | ≈ 0 | pure static substitution |

---

## 7. Known characteristics and mitigations

1. **`logic` blocks in hot loops.** `low` / `mid` identities can land in loop conditions
   (`for ($i = 0; $i < (__mod_strlen(...)*50); $i++)`), re-hashing every iteration. Mitigate with
   `--logic-intensity=1`, a custom config without the `logic` layer, or `high` (VM folds them at compile time).
2. **Decoding happens once per request.** Without OPcache, `eval`'d code is not cached — enable OPcache
   (`--opcache`) for high QPS, otherwise every request re-decrypts and recompiles the outer shells.
3. **`ultra` is for low-frequency entry points.** 286× compute, 212× page, 13 MB artifacts.
4. **Under VM presets user functions are bytecode, not PHP functions**: `function_exists()` and string
   callbacks are resolved internally; code relying on reflection over user functions should use `low` / `mid`.
5. **Generators / large arrays cost more memory under VM**: microbench peak 4.4 MB vs 0.7 MB native
   (`high`), 11.5 MB on `ultra`.
6. **Function names are unique per file.** `__mod_*` blocks injected by `logic` and inlined by
   `module_link` carry a file-derived segment (`__ml<layer><file>_<name>`), so multi-file projects never
   hit `Cannot redeclare`. Reproducible for the same source and salt.
7. To disable a slow switch: `--no-vm-super-isa`, `--vm-junk=0`, `--no-vm-cpool`,
   `--no-newline-poison`, `--no-shell-shield` (the last has the biggest effect on size and decode time).

---

## 8. Selection guide

| Scenario | Recommendation | Why |
|---|---|---|
| High-QPS web APIs, internal systems | `low` or `mid` | 9–25×; absolute latency is still tens of milliseconds — avoid loop-heavy algorithms |
| Loop-heavy in-house algorithms | `high` | the VM folds constant blocks: 12.9× beats `low`'s 90 µs/iteration |
| Distributed components / SDKs | `high` | single-layer VM, bounded cost, enough anti-analysis |
| License checks, billing cores | `max` (that file only) | VM + full shells, cost confined to a few files |
| CLI batch / offline jobs | `max` / `ultra` | runtime is irrelevant, protection is not |
| Frameworks (ThinkPHP / Laravel) | use the presets | all six are byte-identical to native; property/parameter names are preserved by default |

**Mixed layering** is the practical answer: `max` on the few core files, `mid` everywhere else.

```bash
php entry.php --input=app/service/PriceCalculator.php \
    --output=dist/service/PriceCalculator.php --level=max --salt=$SALT

php entry.php --input=app/controller --output=dist/controller --level=mid --salt=$SALT
```
