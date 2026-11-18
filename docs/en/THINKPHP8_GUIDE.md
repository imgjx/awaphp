# ThinkPHP 8 obfuscation — hands-on guide

[简体中文](../THINKPHP8_GUIDE.md) · **English**

> Scope: obfuscating an entire ThinkPHP 8 (TP8) application — everything under `app/`: controllers / models / services / validators / middleware / events.
> Sample projects: `longtmp/tp8demo/tp8` (original) and `longtmp/tp8demo/tp8_obf` (obfuscated); baseline script `tp8_baseline.sh`; packaged case `tests/tptest.zip`.
> Verification baseline: the 26 comparison lines of the TP8 sample are **byte-identical** to the original (`BASELINE SAME 26/26`).

---

## 1. Design constraints (why the config looks like this)

TP8 is a reflection-heavy, convention-heavy framework, so an obfuscation config must respect three hard rules:

| Constraint | Meaning | Countermeasure |
| --- | --- | --- |
| **File names / directory structure preserved** | The framework resolves classes via `app/controller/Xxx.php` paths and PSR-4, so renaming files breaks everything | Obfuscation changes **content only, never paths**; input and output directories are isomorphic |
| **Property / parameter names unchanged** | ORM field mapping, validator rules and DI parameter binding read and write by name (`$request->param('id')`, `Rule::name('title')`) | `--var-keep-props --var-keep-params` mandatory |
| **Class-name usability not degraded** | `class_exists` / reflection / `new $service` / container bindings match class-name strings | FQCN-aware renaming + `class_alias` re-registration (see §4) |

Also: **each file is obfuscated independently and the same salt yields global consistency** — symbol mappings derive deterministically from the salt, no shared intermediate state, so incremental rebuilds are inherently safe.

---

## 2. Quick start

```bash
# Input: the TP8 project's app/ directory; output: an isomorphic obfuscated directory (file names kept)
php entry.php \
    --input=/path/to/tp8/app \
    --output=/path/to/tp8_obf/app \
    --level=high \
    --salt=your-project-salt \
    --var-keep-props \
    --var-keep-params
```

Afterwards:

1. **Replace** the project's `app/` wholesale with the obfuscated directory (or deploy it so paths match);
2. **No `awaphp_autoload.php` is generated any more**: since v1.1.0, directory builds (whole-system mode) **force original class names** and never rename classes — renaming conflicts with the PSR-4 "namespace + filename" contract, and without the autoloader the framework's own namespace/filename loading just works, with no extra `require`;
3. `route/`, `config/`, `public/` and other non-`app/` directories need no obfuscation; `route/app.php` can stay as-is if it contains closure routes. To skip whole files/directories (config, third-party bridges), use the whitelist (see §4.2).

> **OPcache note**: if the deployment machine enables OPcache with `validate_timestamps=1`, call `opcache_reset()` after replacing files or wait out the `revalidate_freq` window (default 2 s) before verifying, to avoid the false impression of "still broken after the fix" caused by stale compiled bytecode.

---

## 3. Recommended configuration in detail

### 3.1 Choosing a preset

> **Since v1.1.1 all six presets work directly on TP8**: presets enable `var_keep_props` / `var_keep_params`
> by default (so framework-name-based properties — `Model::$name` decides the table name — and route
> parameters survive), and three defects that caused "empty body / controller not found / exit on every
> request" were fixed. Measured `min` / `low` / `mid` / `high` / `max` / `ultra` × 11 endpoints (including
> POST, cache, aggregation, and the signature middleware's 401 branch) — **byte-identical**.
>
> Framework projects should still layer a whitelist on top (see §3.4): the whitelist handles **cross-file
> dynamic symbols** (`call_user_func('helper')`, constant names, method-name strings) while presets handle
> **property and parameter names** — complementary.

| Option | When | Notes |
| --- | --- | --- |
| `--level=min` (**site-wide**) | You only want "not readable at a glance", without changing runtime characteristics | no shell, no VM; on TP8 measured **1.6 ms, faster than native (2.0 ms)**, smaller artifact |
| `--level=low` / `mid` (**recommended starting point**) | normal delivery, high-QPS APIs | no VM; same endpoint 5.7 ms / 12.4 ms (see `docs/en/PERFORMANCE.md`) |
| `--level=high` | core business logic, tens of milliseconds acceptable | includes the VM; same endpoint 44 ms |
| `thinkphp8.config.php` | large projects needing a whitelist safety net | equivalent to the `high` layers + whitelist + framework-compat options |
| One-command script `other/thinkphp8-build.php` | you want to skip manual whitelisting | scans `app/`, collects classes / functions / constants / controller methods → merges into the config → builds |
| `--level=max` / `ultra` | high-value single files | core files only, never the whole site: measured 391 / 485 ms on TP8, **9–11×** `high` (44 ms) — the fixed per-request cost of multi-layer shells |

The TP8 sample passes all 26 baseline lines with `thinkphp8.config.php`.

### 3.2 Mandatory parameters

```bash
--var-keep-props    # keep property names (ORM / validators / view models read and write by name)
--var-keep-params   # keep method parameter names (TP8 binding / named arguments / attribute matching)
--salt=<fixed salt> # any non-empty string; same salt = cross-file symbol consistency + reproducible builds
```

### 3.3 Optional hardening

```bash
--anti-debug --anti-tamper   # already implied by presets; add explicitly only with a bare --vm
--vm --vm-p8                 # minimal VM-only setup (when not using a preset)
--manifest=<path>            # directory builds produce a symbol table; incremental rebuilds reuse it
```

**Function renaming** (the `func` layer) should stay on: plain functions rarely participate in reflection in TP8. If the project has dynamic calls such as `call_user_func('function_name_string')`, conservative dynamic-symbol exclusion skips that name automatically (§1).

---

### 3.4 One-command build script (automatic whitelist)

`other/thinkphp8-build.php` = "scan the project → generate the whitelist → run the obfuscator".

```bash
# Simplest: run inside the project root; the project is detected upward
php /path/to/awaphp/other/thinkphp8-build.php

# Typical: explicit project, output directory and salt (same salt reproduces)
php other/thinkphp8-build.php \
    --project=/var/www/shop \
    --output=/var/www/shop_obf \
    --salt=team-salt-2026

# See what it would collect (no build)
php other/thinkphp8-build.php --project=/var/www/shop --dry-run

# Obfuscate app/ only (fast iteration; keep vendor/public yourself)
php other/thinkphp8-build.php --project=/var/www/shop --app-only
```

It scans `app/` (add more with `--scan=`) and writes these symbols into the whitelist:

| Collected | Source pattern | If not kept |
|---|---|---|
| Classes / interfaces / traits / enums (FQCN) | `class` / `interface` / `trait` / `enum` declarations | PSR-4 fails to find the class, container binding fails |
| Global functions | top-level `function` declarations | helper functions called by name fail |
| Top-level `const` | `const X = …` | constant references become `null` |
| Controller public methods + `__` magic methods | `public function` inside controller classes | routes 404, magic methods stop working |

Other parameters (`--config` / `--php` / `--keep-config`) are documented in the script's `--help`.

> The script only performs a **static scan**; it never executes project code. Scanning takes milliseconds (1 ms for 22 files). Build time and artifact size: `docs/en/PERFORMANCE.md`.

---

## 4. Class-name strategy (project mode keeps original names + whitelist)

### 4.1 Project mode: class names preserved

Since v1.1.0, directory builds (whole-system mode) **no longer rename classes**. Reason: class renaming fundamentally conflicts with the PSR-4 "namespace + filename" convention — after renaming, the framework cannot autoload by the original name, and the classmap autoloader (`awaphp_autoload.php`) approach has been removed. Instead, **project mode forces original class names**, and the framework's namespace/filename autoloading works unchanged, with no extra `require`.

> Single-file mode still supports `--class-obf` (FQCN-aware + `class_alias` re-registers the original name at the declaration point, so reflection / `class_exists` / string class names keep working).

Key points:

- **File names / class names preserved**: the class in `app/service/PriceCalculator.php` is still `app\service\PriceCalculator`, so PSR-4 / framework autoloading paths are unchanged;
- **Original names usable**: framework namespace autoloading, `class_exists`, and `new $className` string instantiation all still hit;
- **Namespaces untouched**: `namespace` declarations stay as-is, so controller routing (`'Shop/calc' → Shop::calc`) is unaffected;
- **VM compatible**: the class-preserving VM (ClassPreservingVm) generates real class stubs per class (method bodies delegate to VM bytecode), so framework reflection on method signatures and dependency injection keep working.

### 4.2 Whitelist: exclude individual directories / files / symbols

If some files or symbols **must never be obfuscated** (config, route callbacks, container bindings, reflection targets, framework entry points), exclude them with the whitelist:

```bash
php entry.php --input=app --output=app_obf --level=high --salt=x \
    --var-keep-props --var-keep-params \
    --whitelist-dirs='config,route' \          # copy whole directories verbatim
    --whitelist-files='app/common.php' \       # copy whole files verbatim
    --whitelist-funcs='helper,bootstrap' \     # keep function names across files
    --whitelist-classes='app\service\Order'    # keep class names (FQCN or short name)
```

- **Directories / files**: copied verbatim, not obfuscated; symbols declared inside keep their names, so cross-file references never break;
- **Symbols**: variables / functions / classes (including interfaces / traits / namespaces) / constants / methods can be kept, consistently across files in directory mode.
- JSON config supports `"whitelist": {"dirs":[...],"files":[...],"variables":[...],"functions":[...],"classes":[...],...}`.

### Files with a top-level `return` (provider / route / service registration)

Files with a **top-level `return array`** (`provider.php`, `service.php`, `event.php`, …) **are not wrapped in the VM shell** — the VM wraps code in an IIFE, which swallows the top-level return value and makes `App::bind(include …)` receive an int and crash. Such files only get token-level obfuscation, preserving return semantics byte-for-byte. In the TP8 sample, `provider.php` / `service.php` / `event.php` / `middleware.php` take this path.

---

## 5. Runtime verification (baseline comparison)

```bash
# 1) Start the original server (e.g. 8090) and the obfuscated one (e.g. 8091)
php -S 127.0.0.1:8090 -t tp8/public tp8/public/index.php
php -S 127.0.0.1:8091 -t tp8_obf/public tp8_obf/public/index.php

# 2) Run the 26-line baseline against each (absolute paths!)
bash /path/to/tp8demo/tp8_baseline.sh http://127.0.0.1:8090 /tmp/orig.txt
bash /path/to/tp8demo/tp8_baseline.sh http://127.0.0.1:8091 /tmp/obf.txt

# 3) Byte-for-byte diff
diff /tmp/orig.txt /tmp/obf.txt && echo "BASELINE SAME 26/26"
```

The baseline covers 26 comparison points: the ThinkPHP 8 entry, multi-parameter controllers, model relations, validators, events/listeners, middleware signatures, transactions, closures, and `shop/calc` (list destructuring + generators + receipt heredoc + nullsafe + call chains).

---

## 6. Known edges and behaviour

| Symptom | Cause | Behaviour |
| --- | --- | --- |
| Artifact header does **not** contain `require_once .../awaphp_autoload.php` | since v1.1.0 project mode keeps original class names | no classmap autoloader needed; the framework autoloads by namespace / filename |
| `declare(strict_types=1)` / `namespace` always first in the artifact | prologue normalisation safety net | statements mistakenly injected into the prologue are moved back after it (otherwise PHP fatals) |
| A few complex statements fall back to EVAL_STMT | syntax the VM does not support yet | statement-level fallback to `eval`, with class references FQCN-ised automatically; non-eval-able header statements (`declare` / `namespace` / `use`) never enter the fallback |
| Build determinism | same salt + same input → byte-identical artifact (only the banner timestamp differs) | friendly to incremental rebuilds and CI caches |
| Dynamically called functions / classes are not renamed | string references such as `call_user_func('f')` | conservative dynamic-symbol exclusion — better less obfuscation than a runtime crash |
| v1.2.7 official whitelist build | `other/thinkphp8.config.php` is now max + whitelist | vendor/config/route/public copied verbatim, only app/ deeply obfuscated; on a 525-file fixture 524/525 files are byte-identical in stdout under standalone execution |
| Sole remaining diff: `public/index.php` exit code | a standalone `include` of the entry loads the whole obfuscated app/, and the anti-tamper / anti-debug guards silently set rc=1 at shutdown | **stdout is byte-identical**; guards do not misfire under normal web-SAPI requests. An expected defense-shell "de-shelled standalone" edge, not an output difference |

### 6.1 Complex PHP/HTML mixed pages (v1.2.0, completed in v1.2.7)

Real TP8 views interleave PHP blocks with HTML: `<?=` short tags, alternative syntax (`if: endif;`) spanning tags, PHP embedded in HTML attributes and `<script>` / `<style>`, HTML inside heredoc, adjacent `?><?php` boundaries, PHP inside HTML comments. Earlier versions entering the eval / GZ / VM shells produced `unexpected token "<"`, or appended inline module function definitions into the HTML after `?>`.

v1.2.0 rewrites such sources **equivalently into pure PHP** at the pipeline entry with `InlineHtml::normalize()` before any later layer sees them; all six presets produce byte-identical output for complex template pages. The test project `tests/tptest.zip` now includes a ~200-line, extremely complex mixed view `tp8/view/shop_dashboard.php`, fed with business data by `app/controller/Dashboard.php` via `$GLOBALS['__awa_dashboard']` and exposed at `/shop/dashboard`; end-to-end 9/9 endpoints match byte-for-byte.

**v1.2.7 completion**: whole-site `max` byte-comparison found that the `logic` / `decoy` aggressive passes, when shells re-tokenise the payload, could swallow literals inside `echo '<HTML fragment>'` (`class="sale"` → `sale`, `(奇)` → blanks). The new `mixed_html_skip_aggressive` (on by default) skips only those two passes for files containing `T_INLINE_HTML`; all other `max` strength is unchanged. After the fix, complex templates such as `view/shop_dashboard.php` in the fixture are byte-identical. `other/thinkphp8.config.php` is upgraded to max + official whitelist, and `--jobs=N` enables 32-core parallel builds (525 files: ~12 min serial → ~3.5 min; whitelist mode ~10 s because framework dirs are copied verbatim).

The one-command build script `other/thinkphp8-build.php` now **automatically copies all missing non-`.php` resources** after a successful obfuscation (`.env`, exception-page `.tpl`, static assets, the runtime database, …). Previously it only produced `.php`, so the obfuscated copy 500'd for lack of `.env` / `.tpl`; with the old script you had to copy those manually.

---

## 7. Debug hooks (environment variables)

| Variable | Effect |
| --- | --- |
| `OBF_VM_SRC=<dir>` | Dump the VM compilation input (token-layer output) and the CPV input — "what it looks like before entering the VM" |
| `OBF_CPV_OUT=<dir>` | Dump the class-preserving VM (ClassPreservingVm) artifact |
| `OBF_VM_DEBUG=1` | Print the EVAL_STMT fallback log (which statement, which reason) |
| `OBF_SHELL_DUMP=<dir>` | Write invalid artifacts and their original input to disk |
| `OBF_LINT_DUMP=<dir>` | Dump artifacts when `php -l` fails |

Example:

```bash
OBF_VM_DEBUG=1 php entry.php --input=app --output=out --level=high --salt=x 2>&1 | grep EVAL_FALLBACK
```

---

## 8. FAQ

**Q: Should I obfuscate `vendor/`?**
No. `vendor` is third-party code: low benefit, high compatibility risk. Obfuscate only your own `app/` (plus your own extension directories).

**Q: Can I obfuscate only some controllers?**
Yes — point `--input` at a subdirectory. Note that class renaming used to depend on a per-build classmap, so when building in separate passes always keep **the same salt**.

**Q: What happens if I change the salt?**
All symbol mappings change; rebuild the whole directory. (Project mode keeps class names, so there is no classmap file to keep in sync any more.)

**Q: Why is `route/app.php` not obfuscated?**
Route files contain many closures and framework DSL constructs: low benefit, high risk; and TP8 can cache routes at runtime, so plaintext is easier to debug.

**Q: How do incremental rebuilds work?**
Directory builds write a `.obf-manifest.json` symbol table by default; repeated builds with the same salt reuse the mapping and skip files whose content hash is unchanged.
