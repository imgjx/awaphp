<?php
/**
 * 动态 VM 运行时生成器（RuntimeGenerator）
 *
 * 与旧版"一份固定的 runtime.php + 明文操作码"不同，本类在【每次混淆构建时】动态生成一份
 * 专属的 VM 解释器，核心性质：
 *
 *   1. 操作码编号随机化：每个文件把"逻辑操作码"映射到一组被打乱的随机编号（再叠加一个
 *      scramble 异或），因此两个文件的操作码数值完全不同 —— 特征库（按固定 1=ADD 之类）
 *      直接失效。
 *   2. 选择性发射：只有源码【真正用到】的操作码才会进入运行时；像 _PHPRUN 这种指令，源码
 *      不出现就根本不会生成它的 handler（与"其他指令也差不多"的要求一致）。
 *   3. 混淆分发：解释器循环以随机变量名编写，switch 的 case 顺序被打乱并注入诱饵 case，
 *      操作码以"被异或后的随机数字"作标签，源码里看不到任何 VmOp::ADD=10 这类明文。
 *   4. 字节码内联：程序以 gzip+base64(JSON) 形式内联，操作码数字藏在二进制串里。
 *
 * 此外提供 flattenSegment()：把一段线性指令【在编译层】重写为状态机（通用 JMP/JZ/JNZ +
 * 状态变量），不引入任何新的专用操作码 —— 满足"控制流混淆应在编译层做、而非新增指令"的要求。
 *
 * __VMRUN__ 会被 VirtualMachine 替换为拟真函数名，消除指纹。
 */

declare(strict_types=1);

final class VmRuntimeGenerator
{
    private const ST_KEY = "\x00vm_st\x00";   // 平坦化状态变量（非法 PHP 变量名，绝不碰撞）
    private const FLATTEN_CAP = 220;           // 超过该指令数的段不做平坦化，避免 O(n^2) 失控

    private Salt $salt;
    private NameStyle $style;
    // P8 架构方向运行时开关（buildArtifact 入口统一写入）
    private bool $builtinReplace = false;   // arch_builtin_replace：natTbl 名编码
    private bool $morph = false;            // arch_morph_runtime：第二层派发 scramble
    private string $natKey = '';            // natTbl 名编码密钥（盐派生）

    public function __construct(?Salt $salt = null)
    {
        $this->salt = $salt ?? new Salt(bin2hex(random_bytes(8)));
        $this->style = new NameStyle($this->salt);
    }

    /** 仅用于调试：查看当前 salt 派生出的映射，不影响产物 */
    public function getSalt(): Salt
    {
        return $this->salt;
    }

    /**
     * 编译源码并返回自包含产物（完整 PHP 文件源码）。
     *
     * @param array<string,mixed> $opts 选项：flatten(bool 默认 false)、stateMachine(bool)、
     *   chunks(int 分片数)、baseDir(string include 解析基目录)、curFile(string 主文件路径，供 __DIR__)、
     *   moduleDir(string ModuleLinker 清单目录，P6；空=不启用)
     * @return array{php:string,program:array,used:array,mapping:array<int,int>,includes:array<int,string>,linker:array{hits:array,skips:array}}
     */
    public function buildArtifact(string $source, array $opts = []): array
    {
        $flatten = $opts['flatten'] ?? false;
        $stateMachine = $opts['stateMachine'] ?? false;
        $nChunks = (int) ($opts['chunks'] ?? 3);
        $baseDir = (string) ($opts['baseDir'] ?? '');
        $curFile = (string) ($opts['curFile'] ?? '');
        $moduleDir = (string) ($opts['moduleDir'] ?? '');
        // —— P8 各架构方向开关（默认全关，不回退既有行为）——
        $keychain       = (bool) ($opts['keychain'] ?? false);         // arch_key_chain
        $builtinReplace = (bool) ($opts['builtinReplace'] ?? false);   // arch_builtin_replace
        $morph          = (bool) ($opts['morph'] ?? false);            // arch_morph_runtime
        $fakeFog        = (int) ($opts['fakeFog'] ?? 0);               // arch_fake_fog
        $probe          = (int) ($opts['probe'] ?? 0);                 // arch_probe_matrix
        // 供 emitRuntimeBody/packProgram 读取（natTbl 编码 / 第二层 scramble）
        $this->builtinReplace = $builtinReplace;
        $this->morph          = $morph;
        // natTbl 名编码密钥：盐派生（空盐时仍为确定性值，仅用于"非常量明文"，非保密）
        $this->natKey         = $this->salt->derive('vm-natkey', 64);
        // include/require 解析：构建期静态求值可达的文件编入嵌入表；
        // 计划（有序）注入编译器，编译期按遇到顺序消费。
        $resolver = new IncludeResolver();
        $resolver->setMainFile($curFile);
        $plan = $resolver->scan($source, $baseDir, $curFile);
        $compiler = new VmCompiler();
        $compiler->setIncludePlan($plan);
        $compiler->setSourceContext($curFile, $baseDir);
        // PHP 语义：被包含文件声明的函数全局可见。嵌入段（构建期 include）的函数是
        // VM 字节码函数，主程序调用点若分流到 CALL_NATIVE 会在运行期致命（真实
        // PHP 函数表里没有它）——把嵌入段的顶层函数名预登记进主编译器，强制走 CALL。
        $knownFns = [];
        foreach ($resolver->embeds() as $e) {
            foreach (array_keys($e['funcs']) as $fn) {
                $knownFns[$fn] = true;
            }
        }
        if ($knownFns !== []) {
            $compiler->addKnownFunctions(array_keys($knownFns));
        }
        // —— P6: ModuleLinker 模块链接（设计文档 §6）——
        // 源码调用位命中清单的函数 → 模块 PHP 代码编译进同一 Program（函数表/类表
        // 合并 + 别名重写），产物中无模块路径、无 include 指令，只有普通 CALL。
        // 模块内的 include 复用主 resolver：嵌入 id 直接落在主程序 id 空间。
        $linker = null;
        $linked = [];   // matchedName => linkModule 返回值（非 null）
        $matches = [];  // matchedName => spec（块外可见，供下方原生兜底内嵌）
        if ($moduleDir !== '' && is_dir($moduleDir)) {
            if (!class_exists(ModuleLinker::class, false)) {
                require_once __DIR__ . '/ModuleLinker.php';
            }
            $linker = new ModuleLinker();
            $linker->loadDir($moduleDir);
            $matches = $linker->matchSource($source);
            $mainTopFns = $this->scanTopLevelFunctionNames($source);
            // 传递式链接：以主源码命中点为种子，递归链接被引用到的其他模块
            // （依赖优先编译），保证跨模块调用走 CALL 而非运行期致命的 CALL_NATIVE。
            $linked = $linker->linkModules($matches, $resolver, $mainTopFns);
            // 命中的调用点改走 CALL（VM 函数表），由合并后的别名表承接
            if ($linked !== []) {
                $compiler->addKnownFunctions(array_keys($linked));
            }
        }
        $program = $compiler->compile($source);

        // 合并被包含文件的符号表（PHP 中 include 的函数/类本就是全局可见的）。
        // 冲突时主程序优先（主程序顶层声明先于任何 include 执行）。
        $program['embeds'] = [];
        $program['srcBase'] = $baseDir;
        // 原生函数名表（name => idx）：先灌入主编译器分配序，再把 embed 段的
        // CALL_NATIVE 操作数按各自子表重映射进来（保证全程序 idx 唯一一致）。
        $natTbl = [];
        $natAdd = static function (string $n) use (&$natTbl): int {
            if (!isset($natTbl[$n])) {
                $natTbl[$n] = count($natTbl);
            }
            return $natTbl[$n];
        };
        $mainNat = $compiler->natTbl();
        asort($mainNat); // 按 idx（分配序）灌入，保持主段操作数不变
        foreach (array_keys($mainNat) as $n) {
            $natAdd($n);
        }
        // 把一段指令里的 CALL_NATIVE 操作数从子表编号重映射到主表
        $remapNat = function (array &$seg, array $subNat) use (&$natTbl, $natAdd): void {
            foreach ($seg as &$ins) {
                if (is_array($ins) && ($ins[0] ?? 0) === VmOp::CALL_NATIVE) {
                    $old = (int) ($ins[1] ?? 0);
                    $name = '';
                    foreach ($subNat as $sn => $si) {
                        if ($si === $old) { $name = (string) $sn; break; }
                    }
                    if ($name !== '') {
                        $ins[1] = $natAdd($name);
                    }
                }
            }
            unset($ins);
        };
        foreach ($resolver->embeds() as $e) {
            $subNat = $e['natTbl'] ?? [];
            $program['embeds'][] = $e['seg'];
            // 嵌入段自身与其函数/类/参数段内的 CALL_NATIVE 全部重映射
            $remapNat($program['embeds'][count($program['embeds']) - 1], $subNat);
            foreach ($e['funcs'] as $n => $s) {
                if (!isset($program['funcs'][$n])) {
                    $program['funcs'][$n] = $s;
                    $remapNat($program['funcs'][$n], $subNat);
                }
            }
            foreach ($e['funcParams'] as $n => $sp) {
                if (!isset($program['funcParams'][$n])) {
                    $program['funcParams'][$n] = $sp;
                }
            }
            foreach ($e['classes'] as $cn => $cls) {
                if (!isset($program['classes'][$cn])) {
                    $program['classes'][$cn] = $cls;
                    // 类方法内的 CALL_NATIVE 同样是子表编号，一并重映射
                    foreach ($cls['methods'] as $mn => $mc) {
                        $remapNat($program['classes'][$cn]['methods'][$mn], $subNat);
                    }
                }
            }
            foreach ($e['classParams'] as $cn => $sp) {
                if (!isset($program['classParams'][$cn])) {
                    $program['classParams'][$cn] = $sp;
                }
            }
            if (!empty($e['globals'])) {
                $program['globals'] = array_values(array_unique(array_merge($program['globals'], $e['globals'])));
            }
        }
        // —— P6: 合并已链接模块的符号表（函数/类按别名注册，主程序优先）——
        // 模块的 include 嵌入段已由上面的统一循环按原始名并入（PHP 全局函数语义）；
        // 模块自身的顶层函数则按 sha256 别名注册，字节码里不出现模块原始函数名。
        if ($linked !== []) {
            foreach ($linked as $lm) {
                $subNat = $lm['natTbl'];
                foreach ($lm['funcs'] as $fn => $code) {
                    $alias = $lm['aliases'][$fn];
                    if (!isset($program['funcs'][$alias])) {
                        $program['funcs'][$alias] = $code;
                        $remapNat($program['funcs'][$alias], $subNat);
                        if (isset($lm['funcParams'][$fn])) {
                            $program['funcParams'][$alias] = $lm['funcParams'][$fn];
                        }
                    }
                }
                foreach ($lm['classes'] as $cn => $cls) {
                    if (!isset($program['classes'][$cn])) {
                        $program['classes'][$cn] = $cls;
                        foreach ($cls['methods'] as $mn => $mc) {
                            $remapNat($program['classes'][$cn]['methods'][$mn], $subNat);
                        }
                        if (isset($lm['classParams'][$cn])) {
                            $program['classParams'][$cn] = $lm['classParams'][$cn];
                        }
                    }
                }
                if (!empty($lm['globals'])) {
                    $program['globals'] = array_values(array_unique(array_merge($program['globals'], $lm['globals'])));
                }
            }
            // 调用点重写：命中名/模块原始函数名 → 别名（CALL 操作数；
            // HOF 字符串回调不在重写范围——边界场景，见文档说明）。
            // 已存在于 funcs 的原始名（主程序/嵌入段定义）不重写——主程序优先。
            $renameMap = $linker->renameMap($linked);
            foreach (array_keys($renameMap) as $orig) {
                if (isset($program['funcs'][$orig])) {
                    unset($renameMap[$orig]);
                }
            }
            if ($renameMap !== []) {
                $this->renameCallTargets($program, $renameMap);
            }
        }
        $program['natTbl'] = $natTbl;

        // —— P7: VM 字节码级真实垃圾指令（VmJunk）——
        // 在合并完所有段之后、collectUsed/remap/控制流平坦化之前注入：这些 opcode 会被
        // 纳入"已用操作码集合"（分配随机编号 + 生成 handler），并被随后的 flatten/stateMachine
        // 进一步打散。junk=0（默认）时不注入。
        $junkCount = (int) ($opts['junk'] ?? 0);
        if ($junkCount > 0) {
            if (!class_exists(VmJunk::class, false)) {
                require_once __DIR__ . '/VmJunk.php';
            }
            (new VmJunk($this->salt))->inject($program, $junkCount);
        }

        // 控制流平坦化（flatten）与状态机混淆（stateMachine）互斥：状态机是更激进的独立层，
        // 在基本块划分之上进一步做状态 ID 随机化 + 诱饵状态，二者取其一即可。
        if ($stateMachine) {
            $this->stateMachineProgram($program);
        } elseif ($flatten) {
            $this->flattenProgram($program);
        }

        // —— P8 arch_fake_fog：诱饵函数（永不调用，栈平衡无副作用）——
        // 在 flatten/stateMachine 之后、collectUsed 之前注入：诱饵段的 opcode 会被纳入
        // "已用操作码集合"（分配随机编号 + 生成 handler），使真实 opcode 集合被淹没，
        // 静态分析难以从 handler 集合反推源码实际用到的指令。fakeFog=0（默认）不注入。
        if ($fakeFog > 0) {
            if (!class_exists(VmFakeFog::class, false)) {
                require_once __DIR__ . '/VmFakeFog.php';
            }
            (new VmFakeFog($this->salt))->inject($program, $fakeFog);
        }

        // —— ISA 精简（见 src/Vm/VmIsa.php）：把族内旧 opcode 升级为参数化 opcode + sub-id。
        // 指令条数不变（[ADD] -> [BINOP, subId]），故放在 flatten + fakeFog 之后也绝不破坏
        // 相对跳转偏移。真实码 + VmJunk + VmFakeFog 三类来源一并收口。
        if (!class_exists(VmIsa::class, false)) {
            require_once __DIR__ . '/VmIsa.php';
        }
        VmIsa::rewriteProgram($program);

        $used = $this->collectUsed($program);
        $mapping = $this->assignCodes($used);
        $this->remap($program, $mapping);

        $body = $this->emitRuntimeBody($mapping);
        // 二进制容器（varint + 操作数类型标记）+ gzip + 分片独立加密
        $blob = gzencode($this->packProgram($program), 9);
        // P8 arch_key_chain：分片密钥改为哈希链（k0=master seed，ki=sha256(k[i-1]‖i)），
        // 运行期顺序派生——静态提取单点密钥无法还原其它分片。默认关（每片独立派生）。
        $enc = $this->encryptChunks($blob, $nChunks, $keychain);
        $chunksPhp = var_export(array_map('base64_encode', $enc['chunks']), true);
        $keysPhp = var_export(array_map('base64_encode', $enc['keys']), true);
        $pVar = $body['pVar'];
        $runVar = $body['runVar'];

        $decodeHelpers = $this->bytecodeHelpersTemplate();
        if ($keychain) {
            // 链式解码：keys 仅存 master seed，逐片推进 k=sha256(k‖i)
            $decodePrologue = '$___CHUNKS___=' . $chunksPhp . ';'
                . '$___KEYS___=' . $keysPhp . ';'
                . '$___K___=base64_decode($___KEYS___[0]);$___RAW___=\'\';'
                . 'foreach($___CHUNKS___ as $___I___=>$___C___){$___RAW___.=$___XOR___(base64_decode($___C___),$___K___);$___K___=hash(\'sha256\',$___K___.\':\'.$___I___,true);}'
                . '$' . $pVar . '=$___UNPACK___(gzdecode($___RAW___));';
        } else {
            $decodePrologue = '$___CHUNKS___=' . $chunksPhp . ';'
                . '$___KEYS___=' . $keysPhp . ';'
                . '$___RAW___=\'\';'
                . 'foreach($___CHUNKS___ as $___I___=>$___C___){$___RAW___.=$___XOR___(base64_decode($___C___),base64_decode($___KEYS___[$___I___]));}'
                . '$' . $pVar . '=$___UNPACK___(gzdecode($___RAW___));';
        }

        // —— P8 arch_probe_matrix：反调试 / 反篡改探针序章 ——
        // 在解码序章之后、解释器定义之前注入：探针自包含（不依赖 $P），正常运行全部为假
        // （无副作用）；一旦命中（调试器/回溯过深/字节码被改）即静默 exit。probe=0 不注入。
        $probePrelude = '';
        if ($probe > 0) {
            if (!class_exists(VmProbeMatrix::class, false)) {
                require_once __DIR__ . '/VmProbeMatrix.php';
            }
            // crc 期望值：对压缩后的字节码 blob 取 crc32，与盐派生整数异或后内嵌
            $expectCrc = (crc32($blob) ^ ($this->salt->deriveInt('vm-probe-crc', 1, PHP_INT_MAX)));
            $probePrelude = (new VmProbeMatrix($this->salt))->build($probe, $expectCrc, $this->salt->deriveInt('vm-probe-crc', 1, PHP_INT_MAX));
        }

        // P6 兜底：匹配但未被 VM 内联的模块（replace=false / 链接失败）以原生源码形式
        // 内嵌产物顶层，保证调用走 CALL_NATIVE 时函数仍存在（产物自包含，不致命）。
        // 同模块被多个名命中（如 match_regex 同时命中 rx_tag/rx_wrap）只内嵌一次。
        $inlineNative = [];
        if ($moduleDir !== '' && is_dir($moduleDir)) {
            foreach ($matches as $mname => $spec) {
                if (isset($linked[$mname])) {
                    continue;   // 已成功内联为 VM 字节码，无需原生兜底
                }
                $specName = (string) $spec['name'];
                if (isset($inlineNative[$specName])) {
                    continue;
                }
                $src = $linker->inlineSource($spec);
                if ($src !== null && $src !== '') {
                    $inlineNative[$specName] = $src;
                }
            }
        }
        $nativePre = $inlineNative !== [] ? implode("\n", $inlineNative) . "\n" : '';

        $php = "<?php\n" . $nativePre . "(function(){\n"
            . $decodeHelpers . "\n"
            . $decodePrologue . "\n"
            . $probePrelude . "\n"
            . $body['php'] . "\n"
            . '$__vmv=[];$' . $runVar . '($' . $pVar . "['main'],\$__vmv,null,[],true);\n"
            . "})();\n";

        return [
            'php'      => $php,
            'program'  => $program,
            'used'     => $used,
            'mapping'  => $mapping,
            'includes' => $resolver->warnings(),
            'linker'   => [
                'hits'  => $linker !== null ? $linker->hits() : [],
                'skips' => $linker !== null ? $linker->skips() : [],
            ],
        ];
    }

    /**
     * P6：把 Program 内所有 CALL 操作数按重写表换成目标名（模块函数别名化）。
     * 覆盖 main / funcs / classes.methods / embeds 各段；嵌套段结构在本阶段
     * （flatten/stateMachine 之前）尚未产生，线性遍历即可。
     *
     * @param array<string,mixed> $program
     * @param array<string,string> $map 原名 => 新名
     */
    private function renameCallTargets(array &$program, array $map): void
    {
        $walk = static function (array &$seg) use ($map): void {
            foreach ($seg as &$ins) {
                if (is_array($ins) && ($ins[0] ?? 0) === VmOp::CALL
                    && isset($ins[1]) && is_string($ins[1]) && isset($map[$ins[1]])) {
                    $ins[1] = $map[$ins[1]];
                }
            }
            unset($ins);
        };
        $walk($program['main']);
        foreach ($program['funcs'] as &$fc) {
            $walk($fc);
        }
        unset($fc);
        foreach ($program['classes'] as &$cls) {
            foreach ($cls['methods'] as &$mc) {
                $walk($mc);
            }
            unset($mc);
        }
        unset($cls);
        foreach ($program['embeds'] as &$es) {
            $walk($es);
        }
        unset($es);
    }

    /**
     * P6：扫描主程序顶层函数声明名（供模块代码跨单元调用时路由到 CALL 而非
     * CALL_NATIVE——与嵌入段函数预登记同一思路）。
     *
     * @return array<string,true>
     */
    private function scanTopLevelFunctionNames(string $source): array
    {
        $names = [];
        $toks = Parser::tokenize('<?php ' . $source);
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                $names[(string) $toks[$j][1]] = true;
            }
        }
        return $names;
    }

    /**
     * 直接执行并返回标准输出（供测试 / 校验使用）。
     */
    public function execute(string $source, array $opts = []): string
    {
        $art = $this->buildArtifact($source, $opts)['php'];
        $tmp = tempnam(sys_get_temp_dir(), 'vmar') . '.php';
        file_put_contents($tmp, $art);
        ob_start();
        (static function () use ($tmp): void {
            require $tmp;
        })();
        $out = (string) ob_get_clean();
        @unlink($tmp);
        return $out;
    }

    // ============================================================
    //  操作码随机化 + 选择性发射
    // ============================================================

    /** @return array<int,int> 逻辑操作码集合 */
    private function collectUsed(array $program): array
    {
        $used = [];
        $visit = function (array $seg) use (&$used): void {
            foreach ($seg as $ins) {
                if (is_array($ins) && isset($ins[0])) {
                    $used[$ins[0]] = true;
                }
            }
        };
        $visit($program['main'] ?? []);
        foreach ($program['funcs'] ?? [] as $seg) {
            $visit($seg);
        }
        // include 嵌入段同样是可执行字节码，必须参与操作码收集
        foreach ($program['embeds'] ?? [] as $seg) {
            $visit($seg);
        }
        // 函数/方法参数默认值段也是可执行小段（调用前补齐时运行），必须参与操作码收集
        foreach ($program['funcParams'] ?? [] as $spec) {
            foreach ($spec as $p) {
                if (is_array($p[1] ?? null)) {
                    $visit($p[1]);
                }
            }
        }
        foreach ($program['classParams'] ?? [] as $specs) {
            foreach ($specs as $spec) {
                foreach ($spec as $p) {
                    if (is_array($p[1] ?? null)) {
                        $visit($p[1]);
                    }
                }
            }
        }
        foreach ($program['classes'] ?? [] as $cls) {
            foreach (($cls['props'] ?? []) as $def) {
                if (is_array($def)) {
                    $visit($def);
                }
            }
            foreach (($cls['consts'] ?? []) as $cseg) {
                if (is_array($cseg)) {
                    $visit($cseg);
                }
            }
            foreach (($cls['methods'] ?? []) as $seg) {
                $visit($seg);
            }
        }
        return array_keys($used);
    }

    /** 把已用逻辑操作码映射到随机编号（打乱 + 与 scramble 异或由调用方处理） */
    private function assignCodes(array $used): array
    {
        $pool = range(1, 250);
        $pool = $this->salt->shuffle($pool, 'vm-opcodes');
        $mapping = [];
        foreach (array_values($used) as $i => $logical) {
            $mapping[$logical] = $pool[$i];
        }
        return $mapping;
    }

    /** @param array<int,int> $mapping */
    private function remap(array &$program, array $mapping): void
    {
        $re = function (array &$seg) use ($mapping): void {
            foreach ($seg as &$ins) {
                if (is_array($ins) && isset($ins[0]) && isset($mapping[$ins[0]])) {
                    $ins[0] = $mapping[$ins[0]];
                }
            }
            unset($ins);
        };
        $re($program['main']);
        foreach ($program['funcs'] as &$seg) {
            $re($seg);
        }
        unset($seg);
        // include 嵌入段（被包含文件编译产物）同样需要重映射操作码
        if (isset($program['embeds']) && is_array($program['embeds'])) {
            foreach ($program['embeds'] as &$seg) {
                if (is_array($seg)) {
                    $re($seg);
                }
            }
            unset($seg);
        }
        // 参数默认值段同样需要重映射操作码（运行期经 $___PAD___ 执行）。
        // 注意：不能用 `?? []` 做引用 foreach—— coalesce 结果是副本，修改写不回 program。
        if (isset($program['funcParams']) && is_array($program['funcParams'])) {
            foreach ($program['funcParams'] as &$spec) {
                foreach ($spec as &$p) {
                    if (is_array($p[1] ?? null)) {
                        $re($p[1]);
                    }
                }
                unset($p);
            }
            unset($spec);
        }
        if (isset($program['classParams']) && is_array($program['classParams'])) {
            foreach ($program['classParams'] as &$specs) {
                foreach ($specs as &$spec) {
                    foreach ($spec as &$p) {
                        if (is_array($p[1] ?? null)) {
                            $re($p[1]);
                        }
                    }
                    unset($p);
                }
                unset($spec);
            }
            unset($specs);
        }
        foreach ($program['classes'] as &$cls) {
            // 属性默认值也是可运行的小段（NEW 时执行），必须一并重映射。
            // 注意：必须用引用取 props，否则 ($cls['props'] ?? []) 会生成临时副本，修改无法回写。
            if (isset($cls['props'])) {
                foreach ($cls['props'] as &$seg) {
                    if (is_array($seg)) {
                        $re($seg);
                    }
                }
                unset($seg);
            }
            if (isset($cls['consts'])) {
                foreach ($cls['consts'] as &$seg) {
                    if (is_array($seg)) {
                        $re($seg);
                    }
                }
                unset($seg);
            }
            foreach ($cls['methods'] as &$seg) {
                $re($seg);
            }
            unset($seg);
        }
        unset($cls);
    }

    // ============================================================
    //  运行时体生成（随机变量名 + 打乱 case + 诱饵）
    // ============================================================

    /** @param array<int,int> $mapping 逻辑 => 随机编号 */
    private function emitRuntimeBody(array $mapping): array
    {
        $scramble = $this->salt->deriveInt('vm-scramble', 1, 250);
        // P8 arch_morph_runtime：第二层派发 scramble（morph 开时启用；关时为 0，等于无操作，
        // 默认产物与旧行为逐字节等价）。它与第一层 scramble 相互独立，使每构建的"派发数学"不同。
        $scramble2 = $this->morph ? $this->salt->deriveInt('vm-scramble2', 1, 250) : 0;
        $nm = $this->genNames();

        // 跳转表：每个逻辑操作码分配一个"随机 case 标签"，并以 (随机编号 ^ scramble ^ scramble2)
        // 为键建立 opcode -> 标签 的映射。分发时先查表再 switch，彻底打散"编号=标签"的规律。
        $caseLabel = [];
        $jt = [];
        foreach ($mapping as $logical => $code) {
            $eff = $code ^ $scramble ^ $scramble2;              // 运行期派发键
            $cl = ($logical * 100003 + $this->salt->deriveInt('jmpt-' . $logical, 1, 100000)) ^ $scramble2;
            $caseLabel[$logical] = $cl;
            $jt[$eff] = $cl;
        }
        $labelSet = array_fill_keys(array_values($caseLabel), true);

        // 生成 case（顺序打乱 + 诱饵）
        $cases = [];
        foreach ($mapping as $logical => $code) {
            $cases[] = 'case ' . $caseLabel[$logical] . ': ' . $this->caseBody($logical) . ' break;';
        }
        // 诱饵 case：随机标签，保证不与真实标签撞车
        $decoyLabelPool = [];
        $guard = 0;
        while (count($decoyLabelPool) < 32 && $guard < 200) {
            $d = $this->salt->deriveInt('vm-decoy-l' . $guard, 1, 999999);
            $guard++;
            if (!isset($labelSet[$d])) {
                $labelSet[$d] = true;
                $decoyLabelPool[] = $d;
            }
        }
        $decoyLabelPool = $this->salt->shuffle($decoyLabelPool, 'vm-decoyl');
        $decoyCount = min(4 + $this->salt->deriveInt('vm-decoync', 0, 4), count($decoyLabelPool));
        for ($i = 0; $i < $decoyCount; $i++) {
            $cases[] = 'case ' . $decoyLabelPool[$i] . ': break;';
        }
        $cases = $this->salt->shuffle($cases, 'vm-cases');
        $switch = implode("\n", $cases) . "\n";

        $template = $this->runtimeTemplate();
        $runtime = str_replace('___SWITCH___', $switch, $template);
        $runtime = strtr($runtime, $this->placeholderMap($nm, $scramble, $jt));

        // —— P8 arch_builtin_replace：natTbl 名运行时解码注入 ——
        // packProgram 已把 natTbl 名盐化编码；这里在 $___CALLFN___ 定义前插入密钥 + 解码闭包，
        // 并把取名字的那一处包一层解码。仅 builtinReplace 开时注入，默认产物不含任何额外代码。
        if ($this->builtinReplace) {
            $natVar = $this->genName('natkey');
            $ndecVar = $this->genName('ndec');
            $keyB64 = base64_encode($this->natKey);
            $defs = '$' . $natVar . "=base64_decode('" . $keyB64 . "');"
                . '$' . $ndecVar . '=function($s)use($' . $natVar . '){$d=base64_decode($s);if($d===false){return $s;}'
                . '$r=\'\';for($j=0,$n=strlen($d);$j<$n;$j++){$r.=chr(ord($d[$j])^ord($' . $natVar
                . '[$j%strlen($' . $natVar . ')]));}return $r;};';
            // 在 $___CALLFN___ 定义前插入密钥 + 解码闭包
            $marker = '$' . $nm['callFn'] . '=function($idx,$args)use(';
            $pos = strpos($runtime, $marker);
            if ($pos !== false) {
                $runtime = substr($runtime, 0, $pos) . $defs . substr($runtime, $pos);
            }
            // CALLFN 是闭包：必须把解码器纳入其 use 列表，否则闭包内看不到外层 $ndecVar
            $runtime = str_replace('$idx,$args)use(', '$idx,$args)use($' . $ndecVar . ',', $runtime);
            // natTbl 名唯一取值点（CALLFN 内）包一层解码
            $runtime = str_replace('(string)$nt[$idx]', '(string)$' . $ndecVar . '((string)$nt[$idx])', $runtime);
        }

        return [
            'php'    => $runtime,
            'pVar'   => $nm['P'],
            'runVar' => $nm['run'],
        ];
    }

    /** @return array<string,string> role => php 变量名（不含 $，在占位符替换时统一加 $） */
    private function genNames(): array
    {
        $g = fn (string $label): string => $this->genName($label);
        $raw = [
            'stack'   => $g('stack'),
            'pc'      => $g('pc'),
            'vars'    => $g('vars'),
            'this'    => $g('this'),
            'ins'     => $g('ins'),
            'ret'     => $g('ret'),
            'retSet'  => $g('retSet'),
            'op'      => $g('op'),
            'n'       => $g('n'),
            'init'    => $g('init'),
            'code'    => $g('code'),
            'idxGet'  => $g('idxGet'),
            'propGet' => $g('propGet'),
            'propSet' => $g('propSet'),
            'newObj'  => $g('newObj'),
            'callFunc' => $g('callFunc'),
            'callMethod' => $g('callMethod'),
            'vmcb'    => $g('vmcb'),
            'pathGet' => $g('pathGet'),
            'run'     => $g('run'),
            'meta'    => $g('meta'),
            'P'       => $g('P'),
            'gframe'  => $g('gframe'),
            'isG'     => $g('isG'),
            'emptyV'  => $g('emptyV'),
            'incTrack' => $g('incTrack'),
            'isNap'   => $g('isNap'),
            'iRetV'   => $g('iRetV'),
            'iKey'    => $g('iKey'),
            'iVal'    => $g('iVal'),
            'iNm'     => $g('iNm'),
            'iPth'    => $g('iPth'),
            'callFn'  => $g('callFn'),
        ];
        // 去重：极少数情况下 salt 派生可能撞名，这里追加 Unicode 数字直至唯一
        $seen = [];
        $out = [];
        $suffix = 0;
        foreach ($raw as $role => $name) {
            while (isset($seen[$name])) {
                $name .= self::UNI_DIGITS[$suffix % 10];
                $suffix++;
            }
            $seen[$name] = true;
            $out[$role] = $name;
        }
        return $out;
    }

    /**
     * 生成"非标准化"的 PHP 变量名：首字符与后续字符均取自 Unicode 字母表（CJK 等）。
     * PHP 词法仅要求变量名首字符为"字母"（含 0x80–0xFF 的 UTF-8 字节），因此 CJK
     * 等 Unicode 字符是合法的变量名，且对人类/正则都极不友好（可读性与可搜性大幅降低）。
     */
    private const CJK_MIN = 0x4E00;   // CJK 统一表意文字起
    private const CJK_MAX = 0x9FFF;   // 约 2 万个可用码点
    private const UNI_DIGITS = '零一二三四五六七八九';

    private function uniChar(string $label): string
    {
        $cp = $this->salt->deriveInt($label, self::CJK_MIN, self::CJK_MAX);
        $c = mb_chr($cp, 'UTF-8');
        return ($c === false || $c === '') ? '变' : $c;
    }

    private function genName(string $label): string
    {
        $first = $this->uniChar($label . '|c');
        $n = 3 + $this->salt->deriveInt($label . '|n', 0, 6);
        $name = $first;
        for ($i = 0; $i < $n; $i++) {
            // 适当混入 Unicode 数字，进一步破坏"字母+数字"的可预测结构
            if ($this->salt->deriveInt($label . '|d' . $i, 0, 3) === 0) {
                $name .= self::UNI_DIGITS[$this->salt->deriveInt($label . '|dn' . $i, 0, 9)];
            } else {
                $name .= $this->uniChar($label . '|r' . $i);
            }
        }
        return $name;
    }

    /** @param array<string,string> $nm @return array<string,string> */
    private function placeholderMap(array $nm, int $scramble, array $jt): array
    {
        return [
            '___STACK___'    => $nm['stack'],
            '___PC___'       => $nm['pc'],
            '___VARS___'     => $nm['vars'],
            '___THIS___'     => $nm['this'],
            '___INS___'      => $nm['ins'],
            '___RET___'      => $nm['ret'],
            '___RETSET___'   => $nm['retSet'],
            '___OP___'       => $nm['op'],
            '___JT___'       => $this->genName('jt'),   // 派发临时变量（随机化，消除固定指纹）
            '___N___'        => $nm['n'],
            '___INIT___'     => $nm['init'],
            '___CODE___'     => $nm['code'],
            '___IDXGET___'   => $nm['idxGet'],
            '___PROPGET___'  => $nm['propGet'],
            '___PROPSET___'  => $nm['propSet'],
            '___NEWOBJ___'   => $nm['newObj'],
            '___CALLFUNC___' => $nm['callFunc'],
            '___CALLMETHOD___' => $nm['callMethod'],
            '___VMCB___'     => $nm['vmcb'],
            '___PATHGET___'  => $nm['pathGet'],
            '___RUN___'      => $nm['run'],
            '___META___'     => $nm['meta'],
            '___P___'        => $nm['P'],
            '___GFRAME___'   => $nm['gframe'],
            '___ISG___'      => $nm['isG'],
            '___SCRAMBLE___' => (string) $scramble,
            // 第二层 scramble：必须与 emitRuntimeBody 中 jt 键 / case 标签所用值一致
            '___SCRAMBLE2___' => $this->morph ? (string) $this->salt->deriveInt('vm-scramble2', 1, 250) : '0',
            '___JTARR___'    => var_export($jt, true),
            '___EMPTYV___'   => $nm['emptyV'],
            '___INCTRACK___' => $nm['incTrack'],
            '___ISNAP___'    => $nm['isNap'],
            '___IRETV___'    => $nm['iRetV'],
            '___IKEY___'     => $nm['iKey'],
            '___IVAL___'     => $nm['iVal'],
            '___INM___'      => $nm['iNm'],
            '___IPTH___'     => $nm['iPth'],
            '___CALLFN___'   => $nm['callFn'],
        ];
    }

    /**
     * 运行时模板（nowdoc，不插值）。___SWITCH___ 处注入生成的 case 串，
     * 其余 ___X___ 占位符在 placeholderMap 中替换为随机变量名 / scramble。
     */
    private function runtimeTemplate(): string
    {
        return <<<'TPL'
$___META___='__vm_cls__';
$___IDXGET___=function($arr,$k){if(is_array($arr)){return $arr[$k]??null;}if(is_string($arr)){return $k===(int)$k&&$k>=0&&$k<strlen($arr)?$arr[$k]:null;}if(is_object($arr)){return $arr->$k??null;}return null;};
$___PROPGET___=function($obj,$p)use($___META___){if(is_object($obj)){return $obj->$p??null;}if(is_array($obj)){return $obj[$p]??null;}return null;};
$___PROPSET___=function($obj,$p,$v)use($___META___){if(is_object($obj)){$obj->$p=$v;return;}if(is_array($obj)){$obj[$p]=$v;return;}};
$___PATHGET___=function(&$vars,$name,$keys,&$found){if($name==='$GLOBALS'){$v=$GLOBALS;}elseif(!array_key_exists($name,$vars)){$found=false;return null;}else{$v=$vars[$name];}foreach($keys as $k){if(is_array($v)){if(!array_key_exists($k,$v)){$found=false;return null;}$v=$v[$k];}elseif(is_object($v)){if(!isset($v->$k)){$found=false;return null;}$v=$v->$k;}else{$found=false;return null;}}$found=true;return $v;};
$___PAD___=function($spec,$args)use(&$___RUN___){$n=count($spec);for($i=count($args);$i<$n;$i++){$d=$spec[$i][1];$___EMPTYV___=[];$args[]=($d===null)?null:$___RUN___($d,$___EMPTYV___,null,[]);}return $args;};
$___CALLMETHOD___=function($obj,$m,$args)use(&$___RUN___,$___P___,$___META___,&$___PAD___){if(is_object($obj)&&isset($obj->$___META___)&&isset($___P___['classes'][$obj->$___META___]['methods'][$m])){$args=$___PAD___($___P___['classParams'][$obj->$___META___][$m]??[],$args);$___EMPTYV___=['$this'=>$obj];return $___RUN___($___P___['classes'][$obj->$___META___]['methods'][$m],$___EMPTYV___,$obj,$args);}if(is_object($obj)&&method_exists($obj,$m)){return call_user_func_array([$obj,$m],$args);}return null;};
$___VMCB___=function($cb)use(&$___RUN___,$___P___,$___META___,&$___CALLMETHOD___,&$___PAD___){if(is_string($cb)&&isset($___P___['funcs'][$cb])){$s=$___P___['funcs'][$cb];$sp=$___P___['funcParams'][$cb]??[];return function(...$a)use(&$___RUN___,&$___PAD___,$s,$sp){$___EMPTYV___=[];return $___RUN___($s,$___EMPTYV___,null,$___PAD___($sp,$a));};}if(is_array($cb)&&count($cb)===2){$o=$cb[0];$m=$cb[1];if(is_object($o)&&isset($o->$___META___)&&isset($___P___['classes'][$o->$___META___]['methods'][$m])){return function(...$a)use(&$___CALLMETHOD___,$o,$m){return $___CALLMETHOD___($o,$m,$a);};}if(is_string($o)&&isset($___P___['classes'][$o]['methods'][$m])){$s=$___P___['classes'][$o]['methods'][$m];$sp=$___P___['classParams'][$o][$m]??[];return function(...$a)use(&$___RUN___,&$___PAD___,$s,$sp){$___EMPTYV___=[];return $___RUN___($s,$___EMPTYV___,null,$___PAD___($sp,$a));};}}if(is_string($cb)&&strpos($cb,'::')!==false){$p=explode('::',$cb,2);if(isset($___P___['classes'][$p[0]]['methods'][$p[1]])){$s=$___P___['classes'][$p[0]]['methods'][$p[1]];$sp=$___P___['classParams'][$p[0]][$p[1]]??[];return function(...$a)use(&$___RUN___,&$___PAD___,$s,$sp){$___EMPTYV___=[];return $___RUN___($s,$___EMPTYV___,null,$___PAD___($sp,$a));};}}return $cb;};
$___NEWOBJ___=function($cls,$args)use(&$___RUN___,$___P___,$___META___,&$___CALLMETHOD___){if(isset($___P___['classes'][$cls])){$o=new \stdClass();$o->$___META___=$cls;foreach(($___P___['classes'][$cls]['props']??[]) as $pn=>$def){$___EMPTYV___=[];$o->$pn=($def===null)?null:$___RUN___($def,$___EMPTYV___,null,[]);}if(isset($___P___['classes'][$cls]['methods']['__construct'])){$___CALLMETHOD___($o,'__construct',$args);}return $o;}$rc=new \ReflectionClass($cls);return $rc->newInstanceArgs($args);};
$___CALLFUNC___=function($name,$args)use(&$___RUN___,$___P___,$___VMCB___,&$___PAD___){if(isset($___P___['funcs'][$name])){$args=$___PAD___($___P___['funcParams'][$name]??[],$args);$___EMPTYV___=[];return $___RUN___($___P___['funcs'][$name],$___EMPTYV___,null,$args);}if(($name==='call_user_func'||$name==='call_user_func_array'||$name==='forward_static_call'||$name==='forward_static_call_array'||$name==='array_map')&&isset($args[0])){$args[0]=$___VMCB___($args[0]);return call_user_func_array($name,$args);}if(($name==='array_filter'||$name==='array_reduce'||$name==='array_walk'||$name==='usort'||$name==='uasort'||$name==='uksort'||$name==='preg_replace_callback')&&isset($args[1])){$args[1]=$___VMCB___($args[1]);return call_user_func_array($name,$args);}return call_user_func_array($name,$args);};
$___GFRAME___=[];
$___INCTRACK___=[];
// 原生函数间接分发：nativeIdx → 真实函数名（natTbl 与字节码一同打包，可编码/置换），
// 静态分析看不到 strlen/array_map 等调用点。高阶函数（array_map/usort/...）的 VM
// 回调参数在此包装为原生闭包，保留原生语义。
$___CALLFN___=function($idx,$args)use($___P___,$___VMCB___){$nt=$___P___['natTbl']??[];$name=is_int($idx)&&isset($nt[$idx])?(string)$nt[$idx]:(string)$idx;if(($name==='call_user_func'||$name==='call_user_func_array'||$name==='forward_static_call'||$name==='forward_static_call_array'||$name==='array_map')&&isset($args[0])){$args[0]=$___VMCB___($args[0]);return call_user_func_array($name,$args);}if(($name==='array_filter'||$name==='array_reduce'||$name==='array_walk'||$name==='usort'||$name==='uasort'||$name==='uksort'||$name==='preg_replace_callback')&&isset($args[1])){$args[1]=$___VMCB___($args[1]);return call_user_func_array($name,$args);}return call_user_func_array($name,$args);};
$___RUN___=function($___CODE___,&$___VARS___,$___THIS___,$___INIT___,$___ISG___=false)use(&$___RUN___,$___P___,$___META___,$___IDXGET___,$___PROPGET___,$___PROPSET___,$___NEWOBJ___,$___CALLFUNC___,$___CALLMETHOD___,$___CALLFN___,$___PATHGET___,&$___GFRAME___,&$___PAD___,&$___INCTRACK___){
$___STACK___=$___INIT___;$___PC___=0;$___N___=count($___CODE___);$___RET___=null;$___RETSET___=false;
while($___PC___<$___N___){$___INS___=$___CODE___[$___PC___++];$___OP___=$___INS___[0]^___SCRAMBLE___;$___JT___=___JTARR___[$___OP___^___SCRAMBLE2___]??0;switch($___JT___){___SWITCH___
default:break 2;}}return $___RETSET___?$___RET___:(count($___STACK___)?array_pop($___STACK___):null);};
TPL;
    }

    /** 各逻辑操作码对应的 case 体（占位符需带 $ 前缀，最终被替换为随机名） */
    private function caseBody(int $logical): string
    {
        $bin = [
            VmOp::ADD => '($a+$b)', VmOp::SUB => '($a-$b)', VmOp::MUL => '($a*$b)',
            VmOp::DIV => '((int)$b===0?0:($a/$b))', VmOp::MOD => '((int)$b===0?0:($a%$b))', VmOp::POW => '($a**$b)',
            VmOp::CONCAT => '((string)$a.(string)$b)', VmOp::BW_AND => '($a&$b)', VmOp::BW_OR => '($a|$b)', VmOp::BW_XOR => '($a^$b)',
            VmOp::BW_SHL => '($a<<$b)', VmOp::BW_SHR => '($a>>$b)', VmOp::EQ => '($a==$b)', VmOp::NE => '($a!=$b)',
            VmOp::LT => '($a<$b)', VmOp::GT => '($a>$b)', VmOp::LE => '($a<=$b)', VmOp::GE => '($a>=$b)',
            VmOp::IDENT => '($a===$b)', VmOp::NIDENT => '($a!==$b)', VmOp::SPACESHIP => '($a<=>$b)', VmOp::BOOL_AND => '($a&&$b)', VmOp::BOOL_OR => '($a||$b)',
        ];
        switch ($logical) {
            case VmOp::PUSH_C:   return '$___STACK___[]=$___INS___[1];';
            case VmOp::PUSH_V:   return '$___STACK___[]=$___VARS___[$___INS___[1]]??null;';
            case VmOp::PUSH_REF: return '$___STACK___[]=[\'__vmref__\',$___INS___[1]];';
            case VmOp::POP_V:    return '$___VARS___[$___INS___[1]]=array_pop($___STACK___);';
            case VmOp::DUP:      return '$___STACK___[]=end($___STACK___);';
            case VmOp::DROP:     return 'array_pop($___STACK___);';
            case VmOp::LOAD_THIS:return '$___STACK___[]=$___THIS___;';
            case VmOp::PUSH_NULL:return '$___STACK___[]=null;';
            case VmOp::NEG:      return '$___STACK___[]=-array_pop($___STACK___);';
            case VmOp::BW_NOT:   return '$___STACK___[]=~array_pop($___STACK___);';
            case VmOp::BOOL_NOT: return '$___STACK___[]=!array_pop($___STACK___);';
            case VmOp::JMP:      return '$___PC___+=$___INS___[1];';
            case VmOp::JZ:       return '$v=array_pop($___STACK___);if(!$v){$___PC___+=$___INS___[1];}';
            case VmOp::JNZ:      return '$v=array_pop($___STACK___);if($v){$___PC___+=$___INS___[1];}';
            case VmOp::CALL:     return '$argc=$___INS___[2];$args=[];for($i=0;$i<$argc;$i++){$args[]=array_pop($___STACK___);}$args=array_reverse($args);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___VARS___)){$___GFRAME___[$k]=$___VARS___[$k];}}}$___STACK___[]=$___CALLFUNC___($___INS___[1],$args);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___GFRAME___)){$___VARS___[$k]=$___GFRAME___[$k];}}}';
            case VmOp::CALL_VAR: return '$argc=$___INS___[1];$args=[];for($i=0;$i<$argc;$i++){$args[]=array_pop($___STACK___);}$args=array_reverse($args);$fn=array_pop($___STACK___);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___VARS___)){$___GFRAME___[$k]=$___VARS___[$k];}}}$___STACK___[]=(!is_string($fn)&&is_callable($fn))?call_user_func_array($fn,$args):$___CALLFUNC___((string)$fn,$args);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___GFRAME___)){$___VARS___[$k]=$___GFRAME___[$k];}}}';
            case VmOp::MECALL:   return '$argc=$___INS___[1];$args=[];$m=array_pop($___STACK___);for($i=0;$i<$argc;$i++){$args[]=array_pop($___STACK___);}$args=array_reverse($args);$obj=array_pop($___STACK___);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___VARS___)){$___GFRAME___[$k]=$___VARS___[$k];}}}$___STACK___[]=$___CALLMETHOD___($obj,(string)$m,$args);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___GFRAME___)){$___VARS___[$k]=$___GFRAME___[$k];}}}';
            case VmOp::NEW:      return '$argc=$___INS___[2];$args=[];for($i=0;$i<$argc;$i++){$args[]=array_pop($___STACK___);}$args=array_reverse($args);$cls=$___INS___[1];if($cls===\'__VAR__\'){$cls=array_pop($___STACK___);}$___STACK___[]=$___NEWOBJ___((string)$cls,$args);';
            case VmOp::PROPGET:  return '$o=array_pop($___STACK___);$___STACK___[]=$___PROPGET___($o,$___INS___[1]);';
            case VmOp::PROPSET:  return '$v=array_pop($___STACK___);$o=array_pop($___STACK___);$___PROPSET___($o,$___INS___[1],$v);';
            case VmOp::RET:      return '$___RET___=array_pop($___STACK___);$___RETSET___=true;break 2;';
            case VmOp::RET_VOID: return '$___RET___=null;$___RETSET___=true;break 2;';
            case VmOp::ECHO:     return '$v=array_pop($___STACK___);echo is_array($v)?\'Array\':$v;';
            case VmOp::PRINT_:   return '$v=array_pop($___STACK___);echo is_array($v)?\'Array\':$v;$___STACK___[]=1;';
            case VmOp::HALT:     return '$___RETSET___=true;$___RET___=null;break 2;';
            case VmOp::ARRAY_MAKE: return '$c=$___INS___[1];$arr=[];for($i=0;$i<$c;$i++){$arr[]=array_pop($___STACK___);}$arr=array_reverse($arr);$___STACK___[]=$arr;';
            case VmOp::ARRAY_KV: return '$c=$___INS___[1];$arr=[];$pairs=[];for($i=0;$i<$c;$i++){$k=array_pop($___STACK___);$v=array_pop($___STACK___);$pairs[]=[$k,$v];}$pairs=array_reverse($pairs);foreach($pairs as $p){$arr[$p[0]]=$p[1];}$___STACK___[]=$arr;';
            case VmOp::INDEX_GET: return '$k=array_pop($___STACK___);$arr=array_pop($___STACK___);$___STACK___[]=$___IDXGET___($arr,$k);';
            case VmOp::INDEX_SET: return '$v=array_pop($___STACK___);$k=array_pop($___STACK___);$arr=array_pop($___STACK___);if(!is_array($arr)&&!is_object($arr)){$arr=[];}if(is_array($arr)){$arr[$k]=$v;}else{$arr->$k=$v;}$___STACK___[]=$arr;';
            case VmOp::COUNT:    return '$arr=array_pop($___STACK___);$___STACK___[]=is_countable($arr)?count($arr):0;';
            case VmOp::ARRAY_PUSH:return '$v=array_pop($___STACK___);$arr=array_pop($___STACK___);if(is_array($arr)){$arr[]=$v;}$___STACK___[]=$arr;';
            case VmOp::PRE_INC:  return '$c=($___VARS___[$___INS___[1]]??0)+1;$___VARS___[$___INS___[1]]=$c;$___STACK___[]=$c;';
            case VmOp::PRE_DEC:  return '$c=($___VARS___[$___INS___[1]]??0)-1;$___VARS___[$___INS___[1]]=$c;$___STACK___[]=$c;';
            case VmOp::POST_INC: return '$c=$___VARS___[$___INS___[1]]??0;$___VARS___[$___INS___[1]]=$c+1;$___STACK___[]=$c;';
            case VmOp::POST_DEC: return '$c=$___VARS___[$___INS___[1]]??0;$___VARS___[$___INS___[1]]=$c-1;$___STACK___[]=$c;';
            case VmOp::SCALL:    return '$argc=$___INS___[3];$args=[];for($i=0;$i<$argc;$i++){$args[]=array_pop($___STACK___);}$args=array_reverse($args);$cls=$___INS___[1];$m=$___INS___[2];if(isset($___P___[\'classes\'][$cls][\'methods\'][$m])){if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___VARS___)){$___GFRAME___[$k]=$___VARS___[$k];}}}$args=$___PAD___($___P___[\'classParams\'][$cls][$m]??[],$args);$___EMPTYV___=[];$___STACK___[]=$___RUN___($___P___[\'classes\'][$cls][\'methods\'][$m],$___EMPTYV___,null,$args);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___GFRAME___)){$___VARS___[$k]=$___GFRAME___[$k];}}}}elseif(is_string($cls)&&class_exists($cls)&&method_exists($cls,$m)){$___STACK___[]=call_user_func_array([$cls,$m],$args);}else{$___STACK___[]=null;}';
            case VmOp::SPROP:    return '$cls=$___INS___[1];$pr=$___INS___[2];if(isset($___P___[\'classes\'][$cls][\'consts\'][$pr])){$cs=$___P___[\'classes\'][$cls][\'consts\'][$pr];$___EMPTYV___=[];$___STACK___[]=($cs===null)?null:$___RUN___($cs,$___EMPTYV___,null,[]);}elseif(defined($cls.\'::\'.$pr)){$___STACK___[]=constant($cls.\'::\'.$pr);}elseif(is_string($cls)&&class_exists($cls)){$rp=new \ReflectionClass($cls);$ps=$rp->getStaticProperties();$pn=ltrim((string)$pr,\'$\');$___STACK___[]=array_key_exists($pn,$ps)?$ps[$pn]:null;}else{$___STACK___[]=null;}';
            case VmOp::ISSET_:    return '$nk=$___INS___[2];$keys=[];for($i=0;$i<$nk;$i++){$keys[]=array_pop($___STACK___);}$keys=array_reverse($keys);$found=false;$v=$___PATHGET___($___VARS___,$___INS___[1],$keys,$found);$___STACK___[]=$found&&$v!==null;';
            case VmOp::EMPTY_:    return '$nk=$___INS___[2];$keys=[];for($i=0;$i<$nk;$i++){$keys[]=array_pop($___STACK___);}$keys=array_reverse($keys);$found=false;$v=$___PATHGET___($___VARS___,$___INS___[1],$keys,$found);$___STACK___[]=(!$found)||$v==false;';
            case VmOp::UNSET_:    return '$nk=$___INS___[2];$keys=[];for($i=0;$i<$nk;$i++){$keys[]=array_pop($___STACK___);}$keys=array_reverse($keys);if($___INS___[1]===\'$GLOBALS\'){if($nk===1){unset($GLOBALS[$keys[0]]);}}elseif($nk===0){unset($___VARS___[$___INS___[1]]);}else{$ref=&$___VARS___[$___INS___[1]];$ok=true;for($i=0;$i<$nk-1;$i++){$k=$keys[$i];if(is_array($ref)&&isset($ref[$k])){$ref=&$ref[$k];}elseif(is_object($ref)){$ref=&$ref->$k;}else{$ok=false;break;}}if($ok){$lk=$keys[$nk-1];if(is_array($ref)){unset($ref[$lk]);}elseif(is_object($ref)){unset($ref->$lk);}}}';
            case VmOp::EXIT_:     return '$v=array_pop($___STACK___);if(is_int($v)){exit($v);}if(is_string($v)){echo $v;}exit(0);';
            case VmOp::EXIT_VOID: return 'exit(0);';
            case VmOp::PHPRUN:    return '$argc=$___INS___[1];$code2=null;for($i=0;$i<$argc;$i++){$code2=array_pop($___STACK___);}if(is_array($___VARS___)){extract($___VARS___);}eval($code2);';
            // include（构建期内嵌）：嵌入段与当前帧【共享变量表】（PHP 包含作用域语义：
            // 被包含文件能看到 includer 的变量，其新变量在返回后也对 includer 可见）。
            // once=1 且同一 embed 已执行过 → 直接压 true 跳过。
            case VmOp::INCLUDE_EMBED: return '$e=$___INS___[1];if($___INS___[2]&&isset($___INCTRACK___[$e])){$___STACK___[]=true;}else{$___INCTRACK___[$e]=true;$___STACK___[]=$___RUN___($___P___[\'embeds\'][$e]??[],$___VARS___,$___THIS___,[],$___ISG___);}';
            // include（运行期真实包含）：路径弹栈；flags bit0=once bit1=require。
            // 经"帧变量表 ⇄ 真实局部变量"双向桥接保住作用域：先把帧变量导出为
            // 真实局部变量（被包含文件可见），快照后真实 include/require，再把
            // 新增/被修改的变量差分捕获回帧表（get_defined_vars 键名无 $ 前缀）。
            case VmOp::INCLUDE_RT: return '$fl=$___INS___[1];$___IPTH___=(string)array_pop($___STACK___);$rp=realpath($___IPTH___);if($rp===false&&!empty($___P___[\'srcBase\'])){$cand=rtrim((string)$___P___[\'srcBase\'],\'/\\\\\').\'/\'.$___IPTH___;if(is_file($cand)){$___IPTH___=$cand;$rp=realpath($cand);}}$k=\'r\'.($rp!==false?$rp:$___IPTH___);if(($fl&1)&&isset($___INCTRACK___[$k])){$___STACK___[]=true;}else{$___INCTRACK___[$k]=true;foreach($___VARS___ as $___IKEY___=>$___IVAL___){if($___IKEY___===\'$GLOBALS\'||$___IKEY___===\'$this\'){continue;}$___INM___=substr($___IKEY___,1);$$___INM___=$___IVAL___;}$___ISNAP___=get_defined_vars();$___IRETV___=($fl&2)?@require $___IPTH___:(($fl&4)?@include $___IPTH___:include $___IPTH___);foreach(get_defined_vars() as $___IKEY___=>$___IVAL___){if($___IKEY___===\'this\'||!array_key_exists($___IKEY___,$___ISNAP___)||$___ISNAP___[$___IKEY___]!==$___IVAL___){$___VARS___[\'$\'.$___IKEY___]=$___IVAL___;}}$___STACK___[]=$___IRETV___;}';
            // 语句级回退：源码串弹栈，帧变量导出为真实局部变量 → 快照 → eval →
            // 差分写回（与 INCLUDE_RT 桥接同构）。eval 内的函数/类声明进入真实
            // 符号表（PHP 语义：动态编译的声明对后续调用可见）。
            case VmOp::EVAL_STMT: return '$es=array_pop($___STACK___);foreach($___VARS___ as $___IKEY___=>$___IVAL___){if($___IKEY___===\'$GLOBALS\'||$___IKEY___===\'$this\'){continue;}$___INM___=substr($___IKEY___,1);$$___INM___=$___IVAL___;}$___ISNAP___=get_defined_vars();eval($es);foreach(get_defined_vars() as $___IKEY___=>$___IVAL___){if($___IKEY___===\'this\'||!array_key_exists($___IKEY___,$___ISNAP___)||$___ISNAP___[$___IKEY___]!==$___IVAL___){$___VARS___[\'$\'.$___IKEY___]=$___IVAL___;}}';
            // 原生函数间接调用：nativeIdx 索引 natTbl（构建期生成的函数名表），
            // 经 $___CALLFN___ 分发（保留高阶函数回调包装语义）。
            // refMask（第 3 操作数）标记 by-ref 参数位：对应实参是 PUSH_REF 压入的
            // 引用句柄，运行期绑定为帧变量真实引用后传入（sort/usort/array_push 等）。
            case VmOp::CALL_NATIVE: return '$argc=$___INS___[2];$args=[];for($i=0;$i<$argc;$i++){$args[]=array_pop($___STACK___);}$args=array_reverse($args);$rm=$___INS___[3]??0;for($i=0;$i<$argc;$i++){if(($rm>>$i)&1){$h=$args[$i];if(is_array($h)&&($h[0]??\'\')===\'__vmref__\'){$n=$h[1];if(!array_key_exists($n,$___VARS___)){$___VARS___[$n]=null;}$args[$i]=&$___VARS___[$n];}}}if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___VARS___)){$___GFRAME___[$k]=$___VARS___[$k];}}}$___STACK___[]=$___CALLFN___((int)$___INS___[1],$args);if($___ISG___&&!empty($___P___[\'globals\'])){foreach($___P___[\'globals\'] as $g){$k=\'$\'.$g;if(array_key_exists($k,$___GFRAME___)){$___VARS___[$k]=$___GFRAME___[$k];}}}';
            case VmOp::CAST_INT:    return '$v=array_pop($___STACK___);$___STACK___[]=(int)$v;';
            case VmOp::CAST_FLOAT:  return '$v=array_pop($___STACK___);$___STACK___[]=(float)$v;';
            case VmOp::CAST_STRING: return '$v=array_pop($___STACK___);$___STACK___[]=(string)$v;';
            case VmOp::CAST_BOOL:   return '$v=array_pop($___STACK___);$___STACK___[]=(bool)$v;';
            case VmOp::CAST_ARRAY:  return '$v=array_pop($___STACK___);$___STACK___[]=(array)$v;';
            case VmOp::CAST_OBJECT: return '$v=array_pop($___STACK___);$___STACK___[]=(object)$v;';
            case VmOp::PUSH_GLOBALS: return '$___STACK___[]=$GLOBALS;';
            case VmOp::GLOBALS_SET:  return '$v=array_pop($___STACK___);$k=array_pop($___STACK___);$GLOBALS[$k]=$v;array_pop($___STACK___);';
            case VmOp::GLOBAL_REF:  return '$n=$___INS___[1];if(!array_key_exists($n,$___GFRAME___)){$___GFRAME___[$n]=null;}$___VARS___[$n]=&$___GFRAME___[$n];';
            case VmOp::DEFINE:      return '$v=array_pop($___STACK___);define($___INS___[1],$v);';
            case VmOp::CONST_FETCH: return '$___STACK___[]=defined($___INS___[1])?constant($___INS___[1]):null;';

            // —— ISA 精简：参数化 opcode 的表驱动 handler（sub-id 在 $___INS___[1]）——
            // 顶层仅 6 个 case，族内子操作经 switch 分发；旧 41 条同构 opcode 的语义指纹被收口。
            case VmOp::BINOP:
                $arms = [];
                foreach (VmIsa::BIN as $i => $op) {
                    if (!isset($bin[$op])) { continue; }
                    $arms[] = "case $i:\$___STACK___[]={$bin[$op]};break;";
                }
                return '$b=array_pop($___STACK___);$a=array_pop($___STACK___);switch($___INS___[1]){' . implode('', $arms) . '}';
            case VmOp::UNOP:
                $un = [VmOp::NEG => '-', VmOp::BW_NOT => '~', VmOp::BOOL_NOT => '!'];
                $arms = [];
                foreach (VmIsa::UN as $i => $op) {
                    $arms[] = "case $i:\$___STACK___[]={$un[$op]}\$v;break;";
                }
                return '$v=array_pop($___STACK___);switch($___INS___[1]){' . implode('', $arms) . '}';
            case VmOp::CAST_OP:
                $cast = [VmOp::CAST_INT => '(int)', VmOp::CAST_FLOAT => '(float)', VmOp::CAST_STRING => '(string)', VmOp::CAST_BOOL => '(bool)', VmOp::CAST_ARRAY => '(array)', VmOp::CAST_OBJECT => '(object)'];
                $arms = [];
                foreach (VmIsa::CAST as $i => $op) {
                    $arms[] = "case $i:\$___STACK___[]={$cast[$op]}\$v;break;";
                }
                return '$v=array_pop($___STACK___);switch($___INS___[1]){' . implode('', $arms) . '}';
            case VmOp::INC_OP:
                return 'switch($___INS___[1]){'
                    . 'case 0:$c=($___VARS___[$___INS___[2]]??0)+1;$___VARS___[$___INS___[2]]=$c;$___STACK___[]=$c;break;'
                    . 'case 1:$c=($___VARS___[$___INS___[2]]??0)-1;$___VARS___[$___INS___[2]]=$c;$___STACK___[]=$c;break;'
                    . 'case 2:$c=$___VARS___[$___INS___[2]]??0;$___VARS___[$___INS___[2]]=$c+1;$___STACK___[]=$c;break;'
                    . 'case 3:$c=$___VARS___[$___INS___[2]]??0;$___VARS___[$___INS___[2]]=$c-1;$___STACK___[]=$c;break;'
                    . '}';
            case VmOp::VAROP:
                return 'switch($___INS___[1]){'
                    . 'case 0:$nk=$___INS___[3];$keys=[];for($i=0;$i<$nk;$i++){$keys[]=array_pop($___STACK___);}$keys=array_reverse($keys);$found=false;$v=$___PATHGET___($___VARS___,$___INS___[2],$keys,$found);$___STACK___[]=$found&&$v!==null;break;'
                    . 'case 1:$nk=$___INS___[3];$keys=[];for($i=0;$i<$nk;$i++){$keys[]=array_pop($___STACK___);}$keys=array_reverse($keys);$found=false;$v=$___PATHGET___($___VARS___,$___INS___[2],$keys,$found);$___STACK___[]=(!$found)||$v==false;break;'
                    . "case 2:\$nk=\$___INS___[3];\$keys=[];for(\$i=0;\$i<\$nk;\$i++){\$keys[]=array_pop(\$___STACK___);}\$keys=array_reverse(\$keys);if(\$___INS___[2]==='\$GLOBALS'){if(\$nk===1){unset(\$GLOBALS[\$keys[0]]);}}elseif(\$nk===0){unset(\$___VARS___[\$___INS___[2]]);}else{\$ref=&\$___VARS___[\$___INS___[2]];\$ok=true;for(\$i=0;\$i<\$nk-1;\$i++){\$k=\$keys[\$i];if(is_array(\$ref)&&isset(\$ref[\$k])){\$ref=&\$ref[\$k];}elseif(is_object(\$ref)){\$ref=&\$ref->\$k;}else{\$ok=false;break;}}if(\$ok){\$lk=\$keys[\$nk-1];if(is_array(\$ref)){unset(\$ref[\$lk]);}elseif(is_object(\$ref)){unset(\$ref->\$lk);}}}break;"
                    . '}';
            case VmOp::ARRAY_BUILD:
                return 'switch($___INS___[1]){'
                    . 'case 0:$c=$___INS___[2];$arr=[];for($i=0;$i<$c;$i++){$arr[]=array_pop($___STACK___);}$arr=array_reverse($arr);$___STACK___[]=$arr;break;'
                    . 'case 1:$c=$___INS___[2];$arr=[];$pairs=[];for($i=0;$i<$c;$i++){$k=array_pop($___STACK___);$v=array_pop($___STACK___);$pairs[]=[$k,$v];}$pairs=array_reverse($pairs);foreach($pairs as $p){$arr[$p[0]]=$p[1];}$___STACK___[]=$arr;break;'
                    . '}';
        }
        if (isset($bin[$logical])) {
            $expr = $bin[$logical];
            return '$b=array_pop($___STACK___);$a=array_pop($___STACK___);$___STACK___[]=' . $expr . ';';
        }
        return '';
    }

    // ============================================================
    //  编译期控制流平坦化（通用 JMP/JZ/JNZ + 状态变量，不新增专用操作码）
    // ============================================================

    /** @param array<string,mixed> $program */
    private function flattenProgram(array &$program): void
    {
        if (count($program['main'] ?? []) <= self::FLATTEN_CAP) {
            $program['main'] = $this->flattenSegment($program['main'] ?? []);
        }
        // 注意：`?? []` 的引用 foreach 是副本、写不回 program，必须先判存在再直接遍历
        if (!empty($program['funcs']) && is_array($program['funcs'])) {
            foreach ($program['funcs'] as &$seg) {
                if (is_array($seg) && count($seg) <= self::FLATTEN_CAP) {
                    $seg = $this->flattenSegment($seg);
                }
            }
            unset($seg);
        }
        if (!empty($program['classes']) && is_array($program['classes'])) {
            foreach ($program['classes'] as &$cls) {
                if (!empty($cls['methods']) && is_array($cls['methods'])) {
                    foreach ($cls['methods'] as &$seg) {
                        if (is_array($seg) && count($seg) <= self::FLATTEN_CAP) {
                            $seg = $this->flattenSegment($seg);
                        }
                    }
                    unset($seg);
                }
            }
            unset($cls);
        }
        // include 嵌入段自包含（相对跳转段内有效），一并平坦化
        if (!empty($program['embeds']) && is_array($program['embeds'])) {
            foreach ($program['embeds'] as &$seg) {
                if (is_array($seg) && count($seg) <= self::FLATTEN_CAP) {
                    $seg = $this->flattenSegment($seg);
                }
            }
            unset($seg);
        }
    }

    /**
     * 把一段线性指令（相对跳转）重写为状态机：
     *   - 段首是"分发序章"：逐块比较状态变量，匹配则跳到该基本块；都不匹配则落入块 0（入口）。
     *   - 每个基本块末尾：写入"下一基本块 id"到状态变量，再 JMP 回分发序章（idx 0）。
     *   - 分支（JZ/JNZ/JMP）被拆成"设置状态 + JMP 分发"，全部用通用操作码，不引入新指令。
     * 这样逆向者看到的是一堆 PUSH_V/PUSH_C/EQ/JZ/JMP 的乱序流，无法直接线性还原。
     *
     * @param array<int,array<int,mixed>> $code
     * @return array<int,array<int,mixed>>
     */
    private function flattenSegment(array $code): array
    {
        $n = count($code);
        if ($n === 0) {
            return $code;
        }
        $isBranch = static fn ($op): bool => $op === VmOp::JMP || $op === VmOp::JZ || $op === VmOp::JNZ;
        $isTerm = static fn ($op): bool => $op === VmOp::RET || $op === VmOp::RET_VOID
            || $op === VmOp::HALT || $op === VmOp::EXIT_ || $op === VmOp::EXIT_VOID;

        // 若段不以终结指令收尾，补一条 RET。否则像 for(...) 这种"循环后即函数结束"的结构，
        // 其循环退出 JZ 的跳转目标会落在段尾之后；平坦化后该目标无对应基本块，会被错误回退到
        // 入口块，造成死循环。
        if ($n > 0 && !$isTerm($code[$n - 1][0])) {
            $code[] = [VmOp::RET];
            $n = count($code);
        }

        // 1) 计算基本块起点（leaders）
        $leaders = [0];
        for ($i = 0; $i < $n; $i++) {
            $op = $code[$i][0];
            if ($isBranch($op)) {
                $leaders[] = $i + 1 + ($code[$i][1] ?? 0);
            }
            if ($isTerm($op) || $isBranch($op)) {
                $leaders[] = $i + 1;
            }
        }
        $leaders = array_values(array_unique($leaders));
        sort($leaders);
        // 注意必须保留入口基本块 leader 0（x>=0），否则整张状态机会错位。
        $leaders = array_values(array_filter($leaders, static fn ($x): bool => $x >= 0 && $x < $n));

        // 2) 每条指令所属基本块 id（按指令顺序线性划分：遇到 leader 开启新块）
        $blockOf = [];
        $bid = -1;
        for ($i = 0; $i < $n; $i++) {
            if (in_array($i, $leaders, true)) {
                $bid++;
            }
            $blockOf[$i] = $bid;
        }
        $numBlocks = $bid + 1;
        if ($numBlocks <= 1) {
            return $code; // 单块无需平坦化（避免无谓开销）
        }

        // 3) 基本块在原序列中的起点
        $blockStartOrig = [];
        foreach ($blockOf as $i => $b) {
            if (!isset($blockStartOrig[$b])) {
                $blockStartOrig[$b] = $i;
            }
        }

        // 4) 生成新指令流
        $new = [];
        $dispatchJmps = []; // 这些 JMP 的偏移最终固定为"跳回分发（idx 0）"
        // 分发序章：每块 [PUSH_V st, PUSH_C b, EQ, JZ skip, JMP blockStart(b)]
        for ($b = 0; $b < $numBlocks; $b++) {
            $new[] = [VmOp::PUSH_V, self::ST_KEY];
            $new[] = [VmOp::PUSH_C, $b];
            $new[] = [VmOp::EQ];
            $new[] = [VmOp::JZ, 1];          // 不匹配 -> 跳过紧随的 JMP（偏移 1，落到下一块检查 / 块 0）
            $new[] = [VmOp::JMP, 0];          // 匹配 -> 跳到 blockStart(b)（偏移稍后回填）
        }
        $newBlockStart = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $newBlockStart[$b] = count($new);
            $start = $blockStartOrig[$b];
            $end = ($b + 1 < $numBlocks) ? $blockStartOrig[$b + 1] : $n;
            for ($i = $start; $i < $end; $i++) {
                $ins = $code[$i];
                $op = $ins[0];
                if ($op === VmOp::JMP) {
                    $tb = $blockOf[$i + 1 + ($ins[1] ?? 0)] ?? 0;
                    $new[] = [VmOp::PUSH_C, $tb];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                } elseif ($op === VmOp::JZ) {
                    $tb = $blockOf[$i + 1 + ($ins[1] ?? 0)] ?? 0;   // JZ 跳转目标（条件为假时进入）
                    $fb = $blockOf[$i + 1] ?? 0;                     // 顺序落入（条件为真时进入）
                    // JZ 原语义：条件为假(零) -> 跳转 tb；条件为真 -> 顺序落入 fb
                    $new[] = [VmOp::JZ, 0];            // 占位：条件为假时跳到 tb 分支
                    $jzIdx = count($new) - 1;
                    $new[] = [VmOp::PUSH_C, $fb];      // 条件为真 -> 顺序落入 fb
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                    $tbLabel = count($new);            // tb 分支起点（条件为假）
                    $new[$jzIdx][1] = $tbLabel - ($jzIdx + 1);
                    $new[] = [VmOp::PUSH_C, $tb];      // 条件为假 -> 跳到 tb
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                } elseif ($op === VmOp::JNZ) {
                    $tb = $blockOf[$i + 1 + ($ins[1] ?? 0)] ?? 0;
                    $fb = $blockOf[$i + 1] ?? 0;
                    // JNZ：真 -> tb，假 -> fb
                    $new[] = [VmOp::JNZ, 0];           // 占位：跳到 true 分支
                    $jnzIdx = count($new) - 1;
                    $new[] = [VmOp::PUSH_C, $fb];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                    $trueLabel = count($new);           // true 分支起点
                    $new[$jnzIdx][1] = $trueLabel - ($jnzIdx + 1);
                    $new[] = [VmOp::PUSH_C, $tb];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                } else {
                    $new[] = $ins; // 普通指令 / 终结指令原样保留
                }
            }
        }

        // 5) 回填偏移
        // 序章 JMP：跳到各自 blockStart(b)
        for ($b = 0; $b < $numBlocks; $b++) {
            $jmpIdx = $b * 5 + 4;
            $new[$jmpIdx][1] = $newBlockStart[$b] - ($jmpIdx + 1);
        }
        // 块内 JMP 回分发（idx 0）
        foreach ($dispatchJmps as $jmpIdx) {
            $new[$jmpIdx][1] = 0 - ($jmpIdx + 1);
        }
        return $new;
    }

    /**
     * 状态机混淆模块（独立层）。在基本块平坦化之外进一步做：
     *   1) 状态 ID 随机化：每个基本块的"状态值"由 salt 派生为不可预测的大整数（非 0..n 顺序），
     *      逆向者无法仅凭状态变量的取值反推基本块的执行顺序；
     *   2) 诱饵状态：插入若干"永不达"的虚假状态，其派发入口指向一段自转死块，
     *      撑大状态空间、干扰控制流图（CFG）重建。
     * 与 flattenSegment 共用基本块划分与偏移回填逻辑，但状态语义完全不同，是独立可切换的混淆层。
     * @param array<string,mixed> $program
     */
    private function stateMachineProgram(array &$program): void
    {
        if (count($program['main'] ?? []) <= self::FLATTEN_CAP) {
            $program['main'] = $this->stateMachineSegment($program['main'] ?? []);
        }
        // 同 flattenProgram：`?? []` 引用 foreach 写不回，必须先判存在再直接遍历
        if (!empty($program['funcs']) && is_array($program['funcs'])) {
            foreach ($program['funcs'] as &$seg) {
                if (is_array($seg) && count($seg) <= self::FLATTEN_CAP) {
                    $seg = $this->stateMachineSegment($seg);
                }
            }
            unset($seg);
        }
        if (!empty($program['classes']) && is_array($program['classes'])) {
            foreach ($program['classes'] as &$cls) {
                if (!empty($cls['methods']) && is_array($cls['methods'])) {
                    foreach ($cls['methods'] as &$seg) {
                        if (is_array($seg) && count($seg) <= self::FLATTEN_CAP) {
                            $seg = $this->stateMachineSegment($seg);
                        }
                    }
                    unset($seg);
                }
            }
            unset($cls);
        }
        // include 嵌入段自包含，一并状态机化
        if (!empty($program['embeds']) && is_array($program['embeds'])) {
            foreach ($program['embeds'] as &$seg) {
                if (is_array($seg) && count($seg) <= self::FLATTEN_CAP) {
                    $seg = $this->stateMachineSegment($seg);
                }
            }
            unset($seg);
        }
    }

    /**
     * 单段状态机混淆（随机状态 ID + 诱饵状态）。
     * @param array<int,array<int,mixed>> $code
     * @return array<int,array<int,mixed>>
     */
    private function stateMachineSegment(array $code): array
    {
        $n = count($code);
        if ($n === 0) {
            return $code;
        }
        $isBranch = static fn ($op): bool => $op === VmOp::JMP || $op === VmOp::JZ || $op === VmOp::JNZ;
        $isTerm = static fn ($op): bool => $op === VmOp::RET || $op === VmOp::RET_VOID
            || $op === VmOp::HALT || $op === VmOp::EXIT_ || $op === VmOp::EXIT_VOID;

        // 段尾补 RET：避免循环退出 JZ 目标落段外导致死循环（同 flattenSegment）。
        if ($n > 0 && !$isTerm($code[$n - 1][0])) {
            $code[] = [VmOp::RET];
            $n = count($code);
        }

        // 1) 基本块起点（leaders）
        $leaders = [0];
        for ($i = 0; $i < $n; $i++) {
            $op = $code[$i][0];
            if ($isBranch($op)) {
                $leaders[] = $i + 1 + ($code[$i][1] ?? 0);
            }
            if ($isTerm($op) || $isBranch($op)) {
                $leaders[] = $i + 1;
            }
        }
        $leaders = array_values(array_unique($leaders));
        sort($leaders);
        $leaders = array_values(array_filter($leaders, static fn ($x): bool => $x >= 0 && $x < $n));

        // 2) 每条指令所属基本块 id
        $blockOf = [];
        $bid = -1;
        for ($i = 0; $i < $n; $i++) {
            if (in_array($i, $leaders, true)) {
                $bid++;
            }
            $blockOf[$i] = $bid;
        }
        $numBlocks = $bid + 1;
        if ($numBlocks <= 1) {
            return $code;
        }

        $blockStartOrig = [];
        foreach ($blockOf as $i => $b) {
            if (!isset($blockStartOrig[$b])) {
                $blockStartOrig[$b] = $i;
            }
        }

        // 3) 每个基本块的随机状态 ID（salted、唯一、非顺序）
        $blockState = [];
        $usedStates = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $st = $this->salt->deriveInt('sm-state-' . $b, 1, 1000000);
            while (isset($usedStates[$st])) {
                $st = ($st * 1103515245 + 12345) & 0x7FFFFFFF;
            }
            $usedStates[$st] = true;
            $blockState[$b] = $st;
        }

        // 诱饵状态 ID（永不达）
        $decoyCount = min(2 + $this->salt->deriveInt('sm-decoy-n', 0, 4), $numBlocks);
        $decoys = [];
        for ($d = 0; $d < $decoyCount; $d++) {
            $st = $this->salt->deriveInt('sm-decoy-' . $d, 1, 1000000);
            while (isset($usedStates[$st])) {
                $st = ($st * 1103515245 + 12345) & 0x7FFFFFFF;
            }
            $usedStates[$st] = true;
            $decoys[] = $st;
        }

        // 4) 生成新指令流
        $new = [];
        $dispatchJmps = [];
        // 派发序章：真实块 + 诱饵块，各 5 条（PUSH_V st / PUSH_C state / EQ / JZ 1 / JMP）
        for ($b = 0; $b < $numBlocks; $b++) {
            $new[] = [VmOp::PUSH_V, self::ST_KEY];
            $new[] = [VmOp::PUSH_C, $blockState[$b]];
            $new[] = [VmOp::EQ];
            $new[] = [VmOp::JZ, 1];
            $new[] = [VmOp::JMP, 0];   // 回填 -> newBlockStart[b]
        }
        foreach ($decoys as $dy) {
            $new[] = [VmOp::PUSH_V, self::ST_KEY];
            $new[] = [VmOp::PUSH_C, $dy];
            $new[] = [VmOp::EQ];
            $new[] = [VmOp::JZ, 1];
            $new[] = [VmOp::JMP, 0];   // 回填 -> decoyStart[d]
        }

        $newBlockStart = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $newBlockStart[$b] = count($new);
            $start = $blockStartOrig[$b];
            $end = ($b + 1 < $numBlocks) ? $blockStartOrig[$b + 1] : $n;
            for ($i = $start; $i < $end; $i++) {
                $ins = $code[$i];
                $op = $ins[0];
                if ($op === VmOp::JMP) {
                    $tb = $blockOf[$i + 1 + ($ins[1] ?? 0)] ?? 0;
                    $new[] = [VmOp::PUSH_C, $blockState[$tb]];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                } elseif ($op === VmOp::JZ) {
                    $tb = $blockOf[$i + 1 + ($ins[1] ?? 0)] ?? 0;   // 条件为假
                    $fb = $blockOf[$i + 1] ?? 0;                     // 顺序落入（条件为真）
                    $new[] = [VmOp::JZ, 0];
                    $jzIdx = count($new) - 1;
                    $new[] = [VmOp::PUSH_C, $blockState[$fb]];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                    $tbLabel = count($new);
                    $new[$jzIdx][1] = $tbLabel - ($jzIdx + 1);
                    $new[] = [VmOp::PUSH_C, $blockState[$tb]];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                } elseif ($op === VmOp::JNZ) {
                    $tb = $blockOf[$i + 1 + ($ins[1] ?? 0)] ?? 0;
                    $fb = $blockOf[$i + 1] ?? 0;
                    $new[] = [VmOp::JNZ, 0];
                    $jnzIdx = count($new) - 1;
                    $new[] = [VmOp::PUSH_C, $blockState[$fb]];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                    $trueLabel = count($new);
                    $new[$jnzIdx][1] = $trueLabel - ($jnzIdx + 1);
                    $new[] = [VmOp::PUSH_C, $blockState[$tb]];
                    $new[] = [VmOp::POP_V, self::ST_KEY];
                    $new[] = [VmOp::JMP, 0];
                    $dispatchJmps[] = count($new) - 1;
                } else {
                    $new[] = $ins;
                }
            }
        }

        // 诱饵块：自转回派发（永不达，纯干扰）
        $decoyStart = [];
        foreach ($decoys as $dy) {
            $decoyStart[] = count($new);
            $new[] = [VmOp::JMP, 0];
            $dispatchJmps[] = count($new) - 1;
        }

        // 5) 回填偏移
        for ($b = 0; $b < $numBlocks; $b++) {
            $jmpIdx = $b * 5 + 4;
            $new[$jmpIdx][1] = $newBlockStart[$b] - ($jmpIdx + 1);
        }
        foreach ($decoys as $d => $dy) {
            $jmpIdx = ($numBlocks + $d) * 5 + 4;
            $new[$jmpIdx][1] = $decoyStart[$d] - ($jmpIdx + 1);
        }
        foreach ($dispatchJmps as $jmpIdx) {
            $new[$jmpIdx][1] = 0 - ($jmpIdx + 1);
        }
        return $new;
    }

    // ============================================================
    //  二进制字节码容器（替代 json）：varint 指令边界 + 操作数类型标记
    // ============================================================

    private const TAG_NULL = 0;
    private const TAG_INT  = 1;
    private const TAG_STR  = 2;
    private const TAG_BOOL = 3;
    private const TAG_FLT  = 4;
    private const TAG_SEG  = 5;   // 内嵌段（属性默认值）

    private function bWriteVarint(string &$b, int $v): void
    {
        // 注意：不可对 $v 做 & 0x7FFFFFFF 截断——zigzag 会把常数翻倍，
        // |n| >= 2^30 时翻倍后即越过 2^31，截断会静默损坏高位（乘积低 32 位
        // 偶然蒙混、高位全错）。调用点均为非负值（zigzag 结果/计数/长度）。
        if ($v < 0) {
            // 防御性护栏：负值不应到达此处（调用方需先过 zigzag）。
            // 取低 63 位避免 >> 7 算术移位死循环，同时不影响任何正值路径。
            $v &= PHP_INT_MAX;
        }
        do {
            $c = $v & 0x7f;
            $v >>= 7;
            if ($v) {
                $c |= 0x80;
            }
            $b .= chr($c);
        } while ($v);
    }

    private function bReadVarint(string $b, int &$p): int
    {
        $v = 0;
        $s = 0;
        while (true) {
            $c = ord($b[$p++]);
            $v |= ($c & 0x7f) << $s;
            if (!($c & 0x80)) {
                break;
            }
            $s += 7;
        }
        return $v;
    }

    private function bWriteZig(string &$b, int $n): void
    {
        $z = ($n < 0) ? (((-$n) << 1) - 1) : ($n << 1);
        $this->bWriteVarint($b, $z);
    }

    private function bReadZig(string $b, int &$p): int
    {
        $z = $this->bReadVarint($b, $p);
        return ($z & 1) ? -(($z + 1) >> 1) : ($z >> 1);
    }

    /** @param mixed $v */
    private function bWriteVal(string &$b, $v): void
    {
        if ($v === null) {
            $b .= chr(self::TAG_NULL);
        } elseif (is_bool($v)) {
            $b .= chr(self::TAG_BOOL) . chr($v ? 1 : 0);
        } elseif (is_int($v)) {
            $b .= chr(self::TAG_INT);
            $this->bWriteZig($b, $v);
        } elseif (is_float($v)) {
            $b .= chr(self::TAG_FLT) . pack('d', $v);
        } elseif (is_string($v)) {
            $b .= chr(self::TAG_STR);
            $this->bWriteVarint($b, strlen($v));
            $b .= $v;
        } else {
            $b .= chr(self::TAG_NULL);
        }
    }

    /** @return mixed */
    private function bReadVal(string $b, int &$p)
    {
        $t = ord($b[$p++]);
        switch ($t) {
            case self::TAG_NULL:
                return null;
            case self::TAG_BOOL:
                return ord($b[$p++]) === 1;
            case self::TAG_INT:
                return $this->bReadZig($b, $p);
            case self::TAG_FLT:
                $x = unpack('d', substr($b, $p, 8));
                $p += 8;
                return $x === false ? 0.0 : $x[1];
            case self::TAG_STR:
                $n = $this->bReadVarint($b, $p);
                $s = substr($b, $p, $n);
                $p += $n;
                return $s;
            default:
                return null;
        }
    }

    /** @param array<int,array<int,mixed>> $seg */
    private function bWriteSeg(string &$b, array $seg): void
    {
        $this->bWriteVarint($b, count($seg));
        foreach ($seg as $ins) {
            $this->bWriteVarint($b, (int) ($ins[0] ?? 0));
            $argc = count($ins) - 1;
            $this->bWriteVarint($b, $argc);
            for ($k = 1; $k <= $argc; $k++) {
                $this->bWriteVal($b, $ins[$k] ?? null);
            }
        }
    }

    /** @return array<int,array<int,mixed>> */
    private function bReadSeg(string $b, int &$p): array
    {
        $n = $this->bReadVarint($b, $p);
        $seg = [];
        for ($i = 0; $i < $n; $i++) {
            $op = $this->bReadVarint($b, $p);
            $argc = $this->bReadVarint($b, $p);
            $ins = [$op];
            for ($k = 0; $k < $argc; $k++) {
                $ins[] = $this->bReadVal($b, $p);
            }
            $seg[] = $ins;
        }
        return $seg;
    }

    /**
     * 将整个程序（main/funcs/classes，含已重映射的操作码与属性默认值段）序列化为二进制串。
     * 操作码与偏移用 varint（边界不可推算），操作数带类型标记。
     * @param array<string,mixed> $program
     */
    private function packProgram(array $program): string
    {
        $b = '';
        $this->bWriteSeg($b, $program['main'] ?? []);
        $funcs = $program['funcs'] ?? [];
        $this->bWriteVarint($b, count($funcs));
        foreach ($funcs as $name => $seg) {
            $this->bWriteVal($b, $name);
            $this->bWriteSeg($b, $seg);
        }
        $classes = $program['classes'] ?? [];
        $this->bWriteVarint($b, count($classes));
        foreach ($classes as $cname => $cls) {
            $this->bWriteVal($b, $cname);
            $props = $cls['props'] ?? [];
            $this->bWriteVarint($b, count($props));
            foreach ($props as $pn => $def) {
                $this->bWriteVal($b, $pn);
                if ($def === null) {
                    $b .= chr(self::TAG_NULL);
                } else {
                    $b .= chr(self::TAG_SEG);
                    $this->bWriteSeg($b, $def);
                }
            }
            $methods = $cls['methods'] ?? [];
            $this->bWriteVarint($b, count($methods));
            foreach ($methods as $mn => $seg) {
                $this->bWriteVal($b, $mn);
                $this->bWriteSeg($b, $seg);
            }
            // 类常量（值同为可执行小段，SPROP 时惰性求值）
            $consts = $cls['consts'] ?? [];
            $this->bWriteVarint($b, count($consts));
            foreach ($consts as $cn => $cseg) {
                $this->bWriteVal($b, $cn);
                if ($cseg === null) {
                    $b .= chr(self::TAG_NULL);
                } else {
                    $b .= chr(self::TAG_SEG);
                    $this->bWriteSeg($b, $cseg);
                }
            }
        }
        // global 声明清单（供运行期在顶层帧调用边界同步全局帧）
        $gl = $program['globals'] ?? [];
        $this->bWriteVarint($b, count($gl));
        foreach ($gl as $g) {
            $this->bWriteVal($b, (string) $g);
        }
        // 函数参数规格（默认值段，供运行期补齐缺失实参）
        $fp = $program['funcParams'] ?? [];
        $this->bWriteVarint($b, count($fp));
        foreach ($fp as $fname => $spec) {
            $this->bWriteVal($b, (string) $fname);
            $this->bWriteParamSpec($b, $spec);
        }
        // 类方法参数规格（默认值段）
        $cp = $program['classParams'] ?? [];
        $this->bWriteVarint($b, count($cp));
        foreach ($cp as $cname => $specs) {
            $this->bWriteVal($b, (string) $cname);
            $this->bWriteVarint($b, count($specs));
            foreach ($specs as $mn => $spec) {
                $this->bWriteVal($b, (string) $mn);
                $this->bWriteParamSpec($b, $spec);
            }
        }
        // include 嵌入段（被包含文件编译产物，INCLUDE_EMBED 时按 id 取用）
        $embeds = $program['embeds'] ?? [];
        $this->bWriteVarint($b, count($embeds));
        foreach ($embeds as $seg) {
            $this->bWriteSeg($b, $seg);
        }
        // 源文件基目录：运行期动态 include 的相对路径回退基准
        // （原生按"调用脚本所在目录"解析，单文件产物已无该目录，退而求其次保意图）
        $this->bWriteVal($b, (string) ($program['srcBase'] ?? ''));
        // 原生函数名表（CALL_NATIVE 间接调用用）：count + 各名字
        // P8 arch_builtin_replace：开时把函数名盐化 XOR + base64 编码后再写入二进制包，
        // 静态分析在解包数据里看不到 strlen/array_map 等明文（运行期 CALLFN 内解码还原）。
        $natTbl = $program['natTbl'] ?? [];
        $this->bWriteVarint($b, count($natTbl));
        foreach ($natTbl as $n => $idx) {
            $this->bWriteVal($b, $this->builtinReplace ? $this->natNameEncode((string) $n) : (string) $n);
        }
        return $b;
    }

    /**
     * P8 arch_builtin_replace：natTbl 名的编码（与运行期 $___NDEC___ 解码互为逆操作）。
     * base64( name XOR keystream(natKey) ) —— keystream 由盐派生，空盐下仍为确定性值
     * （目的在于"非常量明文"，非保密；真正的不可还原由分片加密层承担）。
     */
    private function natNameEncode(string $name): string
    {
        return base64_encode($this->xorKeystream($name, $this->natKey));
    }

    /**
     * 序列化一组参数规格：[ [参数名, 默认值段|null], ... ]
     * @param array<int,array{0:string,1:array<int,array<int,mixed>>|null}> $spec
     */
    private function bWriteParamSpec(string &$b, array $spec): void
    {
        $this->bWriteVarint($b, count($spec));
        foreach ($spec as $p) {
            $this->bWriteVal($b, (string) $p[0]);
            if ($p[1] === null) {
                $b .= chr(self::TAG_NULL);
            } else {
                $b .= chr(self::TAG_SEG);
                $this->bWriteSeg($b, $p[1]);
            }
        }
    }

    /**
     * 分片 + 独立加密：把二进制串切成 $n 片，每片用密钥流 XOR。
     *
     * $chain=false（默认）：每片密钥由 salt 独立派生（'vm-chunk-i'），与旧行为一致。
     * $chain=true（P8 arch_key_chain）：密钥构成哈希链——k0 = salt 派生的 master seed，
     *   k[i] = sha256(k[i-1] ‖ i)。返回的 keys 只含 seed 一个元素；运行期必须从 seed 起
     *   逐片顺序派生，静态提取单点密钥无法还原其它分片（前向链 + 盐种子）。
     * @return array{chunks:string[],keys:string[]}
     */
    private function encryptChunks(string $blob, int $n, bool $chain = false): array
    {
        $n = max(1, $n);
        $len = strlen($blob);
        $size = max(1, (int) ceil($len / $n));
        $chunks = [];
        $keys = [];
        $pos = 0;
        $i = 0;
        $prev = $chain ? $this->salt->derive('vm-keymaster', 32) : '';
        while ($pos < $len) {
            $part = substr($blob, $pos, $size);
            if ($chain) {
                $key = $prev;                 // 本片用当前链值
                if ($i === 0) {
                    $keys[] = $prev;          // 仅存 master seed
                }
            } else {
                $key = $this->salt->derive('vm-chunk-' . $i, 32);
                $keys[] = $key;
            }
            $chunks[] = $this->xorKeystream($part, $key);
            if ($chain) {
                // 推进到下一片密钥：sha256(当前链值 ‖ 片序)
                $prev = hash('sha256', $prev . ':' . $i, true);
            }
            $pos += $size;
            $i++;
        }
        // 兜底：空 blob（理论上不会）也保证 keys[0] 存在，供链式解码读取 seed
        if ($chain && $keys === []) {
            $keys[] = $this->salt->derive('vm-keymaster', 32);
        }
        return ['chunks' => $chunks, 'keys' => $keys];
    }

    private function xorKeystream(string $data, string $key): string
    {
        $n = strlen($data);
        $m = strlen($key);
        if ($m === 0) {
            return $data;
        }
        $r = '';
        for ($j = 0; $j < $n; $j++) {
            $r .= chr(ord($data[$j]) ^ ord($key[$j % $m]));
        }
        return $r;
    }

    /**
     * 字节码解码辅助（nowdoc）：在生成文件中定义 $___XOR___ 与 $___UNPACK___ 两个闭包。
     * 与上方 bRead* 系列一一对应，保证往返一致。
     */
    private function bytecodeHelpersTemplate(): string
    {
        return <<<'TPL'
$___XOR___=function(string $d,string $k):string{if(($m=strlen($k))===0){return $d;}$r='';for($j=0,$n=strlen($d);$j<$n;$j++){$r.=chr(ord($d[$j])^ord($k[$j%$m]));}return $r;};
$___UNPACK___=function(string $b){
$___rv___=function(string $bb,int &$p){$v=0;$s=0;while(true){$c=ord($bb[$p++]);$v|=($c&0x7f)<<$s;if(!($c&0x80)){break;}$s+=7;}return $v;};
$___rz___=function(string $bb,int &$p)use($___rv___){$z=$___rv___($bb,$p);return ($z&1)?-(($z+1)>>1):($z>>1);};
$___val___=function(string $bb,int &$p)use($___rv___,$___rz___){$t=ord($bb[$p++]);if($t===0){return null;}if($t===3){return ord($bb[$p++])===1;}if($t===1){return $___rz___($bb,$p);}if($t===4){$x=unpack('d',substr($bb,$p,8));$p+=8;return ($x===false)?0.0:$x[1];}$n=$___rv___($bb,$p);$s=substr($bb,$p,$n);$p+=$n;return $s;};
$___seg___=function(string $bb,int &$p)use($___rv___,$___val___){$n=$___rv___($bb,$p);$seg=[];for($i=0;$i<$n;$i++){$op=$___rv___($bb,$p);$argc=$___rv___($bb,$p);$ins=[$op];for($k=0;$k<$argc;$k++){$ins[]=$___val___($bb,$p);}$seg[]=$ins;}return $seg;};
$___ps___=function(string $bb,int &$p)use($___rv___,$___val___,$___seg___){$n=$___rv___($bb,$p);$spec=[];for($i=0;$i<$n;$i++){$pn=$___val___($bb,$p);$tt=ord($bb[$p++]);$spec[]=[$pn,($tt===0?null:$___seg___($bb,$p))];}return $spec;};
$p=0;$main=$___seg___($b,$p);$nF=$___rv___($b,$p);$funcs=[];for($i=0;$i<$nF;$i++){$name=$___val___($b,$p);$funcs[$name]=$___seg___($b,$p);}$nC=$___rv___($b,$p);$classes=[];for($i=0;$i<$nC;$i++){$cname=$___val___($b,$p);$nP=$___rv___($b,$p);$props=[];for($j=0;$j<$nP;$j++){$pn=$___val___($b,$p);$tt=ord($b[$p++]);if($tt===0){$props[$pn]=null;}else{$props[$pn]=$___seg___($b,$p);}}$nM=$___rv___($b,$p);$methods=[];for($j=0;$j<$nM;$j++){$mn=$___val___($b,$p);$methods[$mn]=$___seg___($b,$p);}$nK=$___rv___($b,$p);$consts=[];for($j=0;$j<$nK;$j++){$cn=$___val___($b,$p);$tt=ord($b[$p++]);if($tt===0){$consts[$cn]=null;}else{$consts[$cn]=$___seg___($b,$p);}}$classes[$cname]=['props'=>$props,'methods'=>$methods,'consts'=>$consts];}$nG=$___rv___($b,$p);$globals=[];for($i=0;$i<$nG;$i++){$globals[]=$___val___($b,$p);}$nFP=$___rv___($b,$p);$funcParams=[];for($i=0;$i<$nFP;$i++){$name=$___val___($b,$p);$funcParams[$name]=$___ps___($b,$p);}$nCP=$___rv___($b,$p);$classParams=[];for($i=0;$i<$nCP;$i++){$cname=$___val___($b,$p);$nM=$___rv___($b,$p);$sp=[];for($j=0;$j<$nM;$j++){$mn=$___val___($b,$p);$sp[$mn]=$___ps___($b,$p);}$classParams[$cname]=$sp;}$nE=$___rv___($b,$p);$embeds=[];for($i=0;$i<$nE;$i++){$embeds[]=$___seg___($b,$p);}$srcBase=$___val___($b,$p);$nN=$___rv___($b,$p);$natTbl=[];for($i=0;$i<$nN;$i++){$natTbl[]=$___val___($b,$p);}return ['main'=>$main,'funcs'=>$funcs,'classes'=>$classes,'globals'=>$globals,'funcParams'=>$funcParams,'classParams'=>$classParams,'embeds'=>$embeds,'srcBase'=>$srcBase,'natTbl'=>$natTbl];};
TPL;
    }
}
