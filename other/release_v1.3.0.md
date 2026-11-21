# v1.3.0 — Runtime environment gates, dynamic `case` labels, cheaper identity blocks

## The identity problem

`if (($lvlsock ^ $lvlsock) === 0)` is not obfuscation. A reader strikes out the `else` with a pen, and every
decoy built on the same shape dies with it. v1.3.0 replaces that whole class of predicate with questions
**only the running machine can answer**.

## 1. `envgate` — branches that depend on the runtime environment

Once per file the artifact samples four facts that are not in the file:

| probe | expression | why it cannot be folded |
|---|---|---|
| PHP version | `PHP_VERSION_ID` | varies by engine build |
| time | `time()` | varies per execution |
| debugger | `(int)(bool)(extension_loaded('xdebug') \|\| function_exists('xdebug_info') \|\| ini_get('xdebug.mode') \|\| extension_loaded('dbg'))` | varies by environment |
| include depth | `(int)(count(get_included_files()) > 1)` | varies between direct run and harness |

Those fold into one environment fingerprint, and every `echo` / `print` is wrapped in two branches selected
by it:

```php
$v=80500; $t=1763685…; $d=(int)(bool)0; $i=(int)(count(get_included_files())>1); $n=0;
if (((($v>>8)^($t&255)^$d^$i^($n=$n+1))&1)) { $n=$n-1; } else { $n=$n+1; }
```

* **Both branches emit byte-identical output** — the artifact behaves the same either way, so nothing can be
  learned by comparing runs on one machine.
* The same build **does not** behave the same on a different machine: run it under Xdebug, `include` it from a
  harness, or execute it on another PHP build, and the predicate flips.
* `$n = $n + 1` inside the predicate is deliberate — the same expression evaluated twice returns different
  values, which breaks iterative constant folding and "re-run until fixed point" analysis: round two sees a
  fingerprint it has just invalidated.
* Cost: four O(1) probes per file plus a handful of integer ops per gate. No function calls, no allocations.

Default: on in **every** preset, `min` included (4 / 6 / 8 / 12 / 12 / 16 gates for
`min / low / mid / high / max / ultra`). The version and time probes always appear at least once per file.
Switches: `--envgate`, `--no-envgate`, `--envgate-max=N`, and per-probe `--envgate-version`, `--envgate-time`,
`--envgate-debug`, `--envgate-include` (each with a `--no-` form).

## 2. The state machine has no branch table left to enumerate

`case` labels used to be integer literals — read them off the source and the dispatcher prunes to the
reachable subgraph. They are now emitted as `case $c + 3:` with `$c = (PHP_VERSION_ID>0)-1`: zero on every PHP
build that exists, but not a constant any analyser can fold without assuming the engine. The state variable is
initialised in the same form, and transitions test a bit-level opaque predicate (`(($o|1)&1) === 1`) instead of
an arithmetic tautology. Enumerating the CFG now requires assuming `$c`, and a hardened build refuses to honour
that assumption.

Switch: `--sm-dynamic-case` / `--no-sm-dynamic-case`.

## 3. Cheaper identity blocks, same reading cost

Old integer bricks were `strlen(md5('…'))` / `strlen(sha1('…'))` — one hash per evaluation (~0.3 µs) on every
literal the layer touches. They are now decimal three-digit chunks recombined with weights and a bit mask:

```php
((908)*1000000 + ((468)*1000) + (267)) ^ 0
```

One to three integer instructions, tens of bytes, and the chunk count / weight spelling / mask shape still
rotate per site, so no two blocks in one file look alike. The `logic` layer's share of runtime drops by two
orders of magnitude; the analysis cost stays where it was, because the reader still has to reduce every chunk
by hand. Switch: `--logic-cheap` / `--no-logic-cheap`.

## 4. `funcsplit` and `decoy` are wired in by default, `min` included

Both were only reachable behind explicit flags — which is why they never showed up in test builds. `min` now
runs `constfold → decoy → envgate → var → funcsplit → number → string → comment → ws`. Order matters: `var`
renames identifiers, so statements must be split into functions *after* renaming, or the parameter names and
the call sites disagree.

## 5. `--report`: where the build time actually goes

`--report` prints a per-layer timing table (share of total, token in/out, output size ratio, artifact
sha256) to stderr at the end of a build; `--report-json=PATH` writes the same numbers to JSON for CI
archiving. It never touches the artifact. Typical output on a mid-level build:

```
shell:module_link        80.64   15.2%
shell:anti_tamper        41.62    7.9%
shell:structpoison       31.45    5.9%
...
total                   529.95  100.0%
bytes  in=3.0 KB  out=49.3 KB  ratio=16.50x
```

## 6. `aggressive_prune` removed

The dead-branch pruner only ever worked on predicates it could prove — exactly the predicates this release
stops producing. Layer, CLI flags (`--aggressive-prune`, `--level=aggressive`), preset and docs are gone;
`constfold` keeps the pruning that still has meaning.

## Silent-wrong-answer fixes found while verifying the above

These are not crashes — they produced artifacts that ran and returned the wrong answer:

* **Integer blocks reassembled their chunks in reverse.** `1234567` became `567234001`, `2` became `6456448`.
  Every loop bound built from a literal was wrong; a loop could run three million times instead of two.
* **The `wild` shift form shifted twice.** `((p<<3)<<3)>>3` is `p<<3`, not `p` — `44` came out as `352`. It only
  fired on one of six shapes.
* **`wild` blocks could hang or lose precision on large literals.** The `strlen('<random>')` shape materialises
  a literal of the target length, so a 10¹⁶-ish constant tried to build a 10¹⁶-byte string at build time.
* **Conditional function declarations are no longer accepted by the VM.** `funcsplit` emits
  `if (!function_exists('f')) { function f(&$a) {…} }` (write-back through reference parameters). Treating that
  as eval-safe handed bridged frame values to by-reference parameters: warnings, silently lost writes, and a
  dispatcher that never advanced. The escape scan is conservative again (closure bodies only), so such files
  stay on the opaque-payload path.
* **`break 2;` / `continue 2;` kept their literal** — the `number` layer was expanding the level operand into an
  expression, a compile-time fatal in PHP 8.
* **The state machine lost its initial state assignment**, leaving the dispatcher reading an undefined variable.
* **`anti_debug` intensity 4 emitted a guard line without `=`**, surfacing as `syntax error, unexpected token "@"`
  on `max` / `ultra` artifacts.
* **`$argv` / `$argc` are bound into the VM globals**, so `$argv[1] ?? …` survives a `high` build.
* **The syntax gate let compile-time namespace errors through.** `token_get_all(…, TOKEN_PARSE)` is a parser gate;
  "Namespace declarations cannot be nested" is a *compile-time* error. Bracketed-namespace files passed every
  per-layer check, but once the payload was compressed `php -l` could no longer see it, the shell's rollback never
  fired, and the artifact died at runtime. `Lint` now validates namespace shape as well.
* **VM `case` labels are conservative again.** Allowing `case (<expr>):` made the VM compile labels rewritten by the
  `number` / `logic` layers with wrong jump offsets — `high` on `tests/fixtures/mixed_edge.php` printed 1.1 MB of
  repeating page and never terminated. Non-literal labels fall back to the opaque-payload path again.

## Known limitation: bytecode coverage at `high` / `max` / `ultra`

The VM compile entry refuses non-literal `case` labels. The state machine emits a dispatcher `switch`, and
the `number` layer that runs after it rewrites those labels into `(N^0)` expressions — so a normal business
file at `high` and above takes the **opaque-payload nesting** path (source encrypted, decoded and run by the
VM's `_PHPRUN`) instead of being compiled to bytecode. v1.2.14 reported this at `info` level per file, i.e.
invisibly; it is now a `warn`, deduplicated by reason, with per-file detail behind `--verbose`.

Measured impact: presets cost **3–4×** on pure compute rather than the 11.9× earlier docs quoted (that number
came from a file that did compile), while genuine bytecode execution costs **≈450×** on the same workload.
Both paths and the cause chain are written up in `docs/PERFORMANCE.md` §4. The complete fix needs two changes
that only work together — relax the label guard *and* add a runtime function-registration opcode, because
`funcsplit` / `module_link` declarations end up inside `case` bodies — and is the first item for v1.3.1.
This release does not change the behaviour; it only stops hiding it.

## Upgrading

No configuration changes are required: every new feature is on by default. If you need the old shape back for a
specific file, `--no-envgate`, `--no-sm-dynamic-case` and `--no-logic-cheap` restore the previous behaviour;
`--no-func-split` / `--no-decoy` turn off the two layers that are now default-on.

Verified on PHP 8.3: all six presets, byte-identical output against native for
`tests/fixtures/complex_app.php` and the environment-gate fixture, `php -l` clean on every artifact.
Test suite: 55/55 in `tests/run_tests.php`, 72/72 preset end-to-end, and 12 feature suites (v1.2.5 → v1.3.0)
green.
