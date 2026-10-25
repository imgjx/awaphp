<?php
/**
 * VM try/catch/finally 专项探测：逐条对比 原生执行 vs 参考运行期 vs 生成运行期（三模式）。
 *
 * 之所以独立成探针（而非并入 vm_syntax_probe / vm_gen_probe）：
 *   异常表（exTab）依赖【绝对 pc】，而 flatten / stateMachine 会重排、插入指令，
 *   使 pc 失真；因此含异常表的段整段跳过这两层（其余混淆层仍生效）。
 *   本探针固定覆盖 try/catch/finally 的全部关键语义组合，防止该机制被后续改动破坏。
 *
 * 三态：
 *   PASS       —— 输出与原生一致
 *   MISMATCH   —— 输出不一致
 *   VM-ERR     —— VM 侧抛出未捕获异常
 * 后缀 real / fallback 表示编译产物是否含语句级回退（EVAL_STMT=113 / INCLUDE_RT=112）。
 */
declare(strict_types=1);
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Support/StatementSplitter.php';
require __DIR__ . '/../src/Vm/Op.php';
require __DIR__ . '/../src/Vm/Compiler.php';
require __DIR__ . '/../src/Vm/runtime.php';
require __DIR__ . '/../src/Vm/IncludeResolver.php';
require __DIR__ . '/../src/Vm/RuntimeGenerator.php';
require __DIR__ . '/../src/Salt.php';
require __DIR__ . '/../src/Support/NameStyle.php';

function tcRunOrig(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'tco') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), [
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    @unlink($tmp);
    return $err !== '' ? "ERR:" . $err : $out;
}

/** 统计编译产物中的语句级回退指令数（EVAL_STMT=113 / INCLUDE_RT=112） */
function tcFallbackCount(array $program): int {
    $n = 0;
    $walk = function ($ins) use (&$walk, &$n) {
        if (is_array($ins) && isset($ins[0])) {
            if ($ins[0] === 113 || $ins[0] === 112) { $n++; }
        }
    };
    foreach (($program['main'] ?? []) as $i) { $walk($i); }
    foreach (($program['funcs'] ?? []) as $f) { foreach (($f['code'] ?? $f) as $i) { $walk($i); } }
    foreach (($program['classes'] ?? []) as $c) {
        foreach (($c['methods'] ?? []) as $m) { foreach (($m['code'] ?? $m) as $i) { $walk($i); } }
    }
    return $n;
}

$cases = [
    // —— 基本 catch / finally ——
    'exception_try'        => 'try { throw new Exception("e"); } catch (Exception $e) { echo $e->getMessage(); }',
    'exception_finally'    => 'try { echo "t"; } finally { echo "f"; }',
    'exception_type'       => 'try { throw new TypeError("te"); } catch (TypeError $e) { echo "caught"; }',
    // —— 跨函数 / 跨方法边界：exTab 必须透传给被调用帧 ——
    'exception_rethrow'    => 'function g1() { throw new Exception("x"); } try { g1(); } catch (Exception $e) { echo $e->getMessage(); }',
    'try_func'             => 'function f($x){ try { if($x<0) throw new Exception("x"); return "ok"; } catch (Exception $e) { return "caught"; } } echo f(1); echo f(-1);',
    'try_method'           => 'class C { public function m($x){ try { if($x<0) throw new Exception("x"); return "ok"; } catch (Exception $e) { return "caught"; } } } $c=new C(); echo $c->m(1); echo $c->m(-1);',
    // —— catch 内的 return / 延后返回 ——
    'try_finally_ret'      => 'function f(){ try { return "t"; } finally { echo "f"; } } echo f();',
    'finally_return'       => 'function f43() { try { return "t"; } finally { return "f"; } } echo f43();',
    // —— 多 catch / 类型匹配 ——
    'exception_multi_catch'=> 'try { throw new RuntimeException("r"); } catch (LogicException $e) { echo "L"; } catch (RuntimeException $e) { echo "R"; }',
    'multi_catch_finally'  => 'try { throw new Exception("m"); } catch (Throwable $e) { echo $e->getMessage(); } finally { echo "|"; }',
    'catch_without_var'    => 'try { throw new Exception("nv"); } catch (Exception) { echo "caught"; }',
    'multi_catch_same_var' => 'try { throw new Exception("s"); } catch (RuntimeException $e) { echo "r"; } catch (Exception $e) { echo "e"; }',
    // —— 嵌套：内层 finally 必须先于外层 catch 执行 ——
    'try_finally_throw'    => 'try { try { throw new Exception("x"); } finally { echo "fin"; } } catch (Exception $e) { echo "caught"; }',
    'nested_try'           => 'try { try { throw new Exception("in"); } catch (Exception $e) { throw new RuntimeException($e->getMessage()); } } catch (RuntimeException $e) { echo $e->getMessage(); }',
    // —— 运行期错误（Error 而非 Exception）——
    'error_try'            => 'try { $x = 1 / 0; } catch (DivisionByZeroError $e) { echo "dz"; }',
    'error_suppress'       => 'try { @$x = 1/0; echo "ok"; } catch (Throwable $t) { echo "th"; }',
];

$gen = new VmRuntimeGenerator(new Salt('trycatch-probe-seed'));
$total = 0; $fail = 0;
foreach ([
    'ref'      => null, // 参考运行期（明文 runtime.php）
    'flat_off' => ['flatten' => false, 'stateMachine' => false],
    'flat_on'  => ['flatten' => true,  'stateMachine' => false],
    'sm_on'    => ['flatten' => false, 'stateMachine' => true],
] as $mode => $opts) {
    echo "===== mode=$mode =====\n";
    foreach ($cases as $name => $code) {
        $total++;
        $orig = tcRunOrig($code);
        try {
            if ($opts === null) {
                $compiler = new VmCompiler();
                $program = $compiler->compile($code);
                $fbN = tcFallbackCount($program);
                ob_start();
                __VMRUN__($program);
                $vm = (string) ob_get_clean();
            } else {
                $vm = $gen->execute($code, $opts);
                $compiler = new VmCompiler();
                $fbN = tcFallbackCount($compiler->compile($code));
            }
        } catch (Throwable $e) {
            $fail++;
            echo "  [$name] VM-ERR(" . get_class($e) . ") " . $e->getMessage() . "\n";
            continue;
        }
        if ($vm === $orig) {
            echo "  [$name] PASS (" . ($fbN > 0 ? 'fallback' : 'real') . ")\n";
        } else {
            $fail++;
            echo "  [$name] MISMATCH\n    orig: " . json_encode($orig)
                . "\n    vm  : " . json_encode($vm) . "\n";
        }
    }
}
echo "\nTOTAL=$total FAIL=$fail\n";
