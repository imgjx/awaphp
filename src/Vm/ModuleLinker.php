<?php
/**
 * VM 模块链接器（ModuleLinker，P6）
 *
 * 设计目标（见设计文档 §6）：独立功能模块以 JSON 清单管理在一个目录下。VM 构建时若
 * 源码用到某函数且命中清单，则把该模块对应的 PHP 代码【自动引入并一起打包进字节码】
 * ——源码里"调用模块函数"变成"调用同一 Program 内的联函数"，原始路径上的间接调用
 * 指令消失，调用关系无从分析：
 *
 *   1. 产物中没有模块文件路径、没有 include 指令，只有"某函数体内部的字节码 +
 *      一个普通 CALL"；
 *   2. `__FILE__` / `realpath` / `debug_backtrace` 里都不会出现模块文件名；
 *   3. 模块代码经过符号表重命名（NameStyle 别名）+ 字符串/数字编码后再进 VM，
 *      与主程序融合度极高。
 *
 * 与 IncludeResolver（P4）的分工（设计文档 §6.6）：
 *   - 模块链接优先：命中的模块 → 内联进 Program（本类）；
 *   - 未命中但路径可静态解析的 include → IncludeResolver 静态内联；
 *   - 两者都做不动的（变量路径）→ INCLUDE_EMBED 运行期嵌入。
 *
 * 清单格式（modules/xxx.json）：
 * {
 *   "name": "strlen",
 *   "match": ["strlen", "mb_strlen"],        // 精确名匹配（函数调用位）
 *   "match_regex": ["/^strlen/", "/^mb_/"],  // 正则匹配（可选）
 *   "entry": "strlen.php",                   // 模块入口文件（相对本 json 所在目录）
 *   "deps": ["strlen_helper.php"],           // 依赖文件，一并打包（可选）
 *   "replace": true,                          // 命中后把源码调用替换为内联模块（默认 true）
 *   "priority": 10,                           // 多清单命中时的优先级（大者优先）
 *   "target": "my_strlen"                     // 可选：调用点改指向模块内的该函数
 * }
 */

declare(strict_types=1);

final class ModuleLinker
{
    /** @var array<string,array> name => spec（含 dir/priority 等归一化字段） */
    private array $specs = [];

    /** @var array<string,string> matchedName => specName（命中日志） */
    private array $hits = [];

    /** @var array<string,string> matchedName => 跳过原因 */
    private array $skips = [];

    /** @var array<string,array> specName => linkModule 结果（同模块多命中名复用） */
    private array $linkedResult = [];

    /** @var array<string,string> 已链接模块的原始函数名 => 别名（跨模块互相调用用） */
    private array $linkedAlias = [];

    /** @var array<string,true> 已链接的模块名（同名模块只链一次） */
    private array $linkedModules = [];

    /**
     * 加载目录下全部 *.json 清单，建立索引。返回加载的清单数。
     */
    public function loadDir(string $dir): int
    {
        $this->specs = [];
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }
        $files = glob(rtrim($dir, '/\\') . '/*.json') ?: [];
        sort($files);
        foreach ($files as $f) {
            $raw = json_decode((string) file_get_contents($f), true);
            if (!is_array($raw)) {
                continue;
            }
            $name = (string) ($raw['name'] ?? '');
            if ($name === '' || isset($this->specs[$name])) {
                continue;
            }
            $this->specs[$name] = [
                'name'        => $name,
                'match'       => array_values(array_map('strval', (array) ($raw['match'] ?? []))),
                'match_regex' => array_values(array_filter(array_map('strval', (array) ($raw['match_regex'] ?? [])), static fn ($r) => $r !== '')),
                'entry'       => (string) ($raw['entry'] ?? ''),
                'deps'        => array_values(array_map('strval', (array) ($raw['deps'] ?? []))),
                'replace'     => (bool) ($raw['replace'] ?? true),
                'priority'    => (int) ($raw['priority'] ?? 0),
                'target'      => (string) ($raw['target'] ?? ''),
                'dir'         => dirname($f),
            ];
        }
        return count($this->specs);
    }

    /** @return array<string,array> */
    public function specs(): array
    {
        return $this->specs;
    }

    /**
     * 扫描源码 token，找出命中清单的【函数调用位】函数名。
     * 返回 [matchedName => spec]，同名多清单命中时取优先级高者（同级取先加载者）。
     *
     * @return array<string,array>
     */
    public function matchSource(string $source): array
    {
        $out = [];
        if ($this->specs === [] || trim($source) === '') {
            return $out;
        }
        $toks = Parser::tokenize('<?php ' . $source);
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t) || $t[0] !== T_STRING) {
                continue;
            }
            // 必须处于函数调用位：下一个有效 token 是左括号
            if (!$this->nextIsCall($toks, $i + 1)) {
                continue;
            }
            // 排除声明/方法调用等非函数调用位
            $prev = $this->prevSig($toks, $i);
            if (is_array($prev) && in_array($prev[0], [
                T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS,
                T_CONST, T_NS_SEPARATOR, T_USE, T_INSTEADOF, T_GOTO, T_NAMESPACE,
                T_STRING, // use Foo\strlen; 之类限定名的尾段
            ], true)) {
                continue;
            }
            $name = (string) $t[1];
            $spec = $this->matchName($name);
            if ($spec !== null) {
                $out[$name] = $spec;
            }
        }
        return $out;
    }

    /**
     * 链接一个模块：读取 entry + deps → 经 IncludeResolver（允许模块内互相 include）
     * → 子 VmCompiler 编译 → 返回可合并进主 Program 的符号表。
     *
     * 模块内的 include 复用【主程序的 IncludeResolver】：嵌入 id 直接落在主程序
     * id 空间（无需偏移重写），嵌入段的函数/类由 buildArtifact 的统一合并循环
     * 按原始名并入主符号表（与 PHP 的全局函数语义一致）。
     *
     * 失败（文件缺失 / 编译期整体不支持 / target 函数不存在）返回 null 并记 skips。
     *
     * @param array<string,true> $knownFns 主程序顶层函数名（模块代码跨单元调用时路由到 CALL）
     * @return array{seg:array,funcs:array,classes:array,funcParams:array,classParams:array,globals:array,natTbl:array,aliases:array,target:string}|null
     *         aliases: [原始函数名 => 别名]，由调用方（buildArtifact）做调用点重写
     */
    public function linkModule(array $spec, string $matchedName, IncludeResolver $resolver, array $knownFns = []): ?array
    {
        $specName = (string) $spec['name'];
        if (isset($this->linkedModules[$specName])) {
            // 同模块被多个函数名命中（正则清单常如此，如 /^rx_/ 同时命中 rx_tag/rx_wrap）：
            // 只链一次，后续命中名复用已链接结果——调用点经 renameMap 改指向同一
            // 别名表，并记命中（便于统计）。
            $this->hits[$matchedName] = $specName;
            return $this->linkedResult[$specName] ?? null;
        }
        $entryRel = (string) $spec['entry'];
        if ($entryRel === '') {
            $this->skips[$matchedName] = "模块 {$specName} 未声明 entry";
            return null;
        }
        $entryAbs = $this->resolvePath($entryRel, (string) $spec['dir']);
        if (!is_file($entryAbs)) {
            $this->skips[$matchedName] = "模块入口不存在: {$entryAbs}";
            return null;
        }
        // 读取 entry + deps，拼成一份模块源码（去开/闭标签）
        $src = $this->readStrip($entryAbs);
        foreach ((array) $spec['deps'] as $dep) {
            $depAbs = $this->resolvePath((string) $dep, (string) $spec['dir']);
            if (is_file($depAbs)) {
                $src .= "\n" . $this->readStrip($depAbs);
            } else {
                $this->skips[$matchedName] = "模块依赖不存在: {$depAbs}";
                return null;
            }
        }
        if (trim($src) === '') {
            $this->skips[$matchedName] = "模块 {$specName} 源码为空";
            return null;
        }

        // 模块内互相 include：复用主 resolver（嵌入 id 落在主程序 id 空间）
        $plan = $resolver->scan($src, (string) $spec['dir'], $entryAbs);
        $child = new VmCompiler();
        $child->setIncludePlan($plan);
        $child->setSourceContext($entryAbs, (string) $spec['dir']);
        // 已链接模块的函数 + 主程序顶层函数 + 嵌入段（含本模块 include 目标）
        // 的函数对当前模块可见：预登记强制走 CALL，避免被误分流到 CALL_NATIVE
        // 后运行期致命（嵌入函数只存在于 VM 函数表，真实 PHP 函数表里没有）。
        $pre = $knownFns;
        foreach (array_keys($this->linkedAlias) as $fn) {
            $pre[$fn] = true;
        }
        foreach ($resolver->embeds() as $e) {
            foreach (array_keys($e['funcs']) as $fn) {
                $pre[$fn] = true;
            }
        }
        if ($pre !== []) {
            $child->addKnownFunctions(array_keys($pre));
        }
        try {
            $prog = $child->compile($src);
        } catch (Throwable $e) {
            // 模块代码含 VM 整体不支持的语法：跳过该模块（调用点保持原生函数分发），
            // 由上层记 skips；源码级若同样无此函数，运行期才可能暴露——与 P6 前一致。
            $this->skips[$matchedName] = "模块编译失败: " . $e->getMessage();
            return null;
        }

        // target 校验：命中名缺省指向同名函数；声明了 target 则指向模块内该函数
        $target = (string) $spec['target'] !== '' ? (string) $spec['target'] : $matchedName;
        if (!isset($prog['funcs'][$target])) {
            $this->skips[$matchedName] = "模块 {$specName} 未定义目标函数 {$target}";
            return null;
        }

        // 模块顶层语句（main 段非空）不属于"函数库"语义：告警并忽略，
        // 只合并函数表/类表/参数表/全局表（与设计 §6.4c 一致）。
        if ($this->moduleHasTopLevel($prog['main'])) {
            $this->skips[$matchedName] = "模块 {$specName} 含顶层语句，已忽略（仅链接函数/类）";
        }

        // 函数别名（sha256 派生，稳定可复现）：字节码里不出现模块原始函数名
        $aliases = [];
        foreach (array_keys($prog['funcs']) as $fn) {
            $aliases[$fn] = '_ml' . substr(hash('sha256', $specName . '|' . $fn), 0, 12);
        }

        $this->linkedModules[$specName] = true;
        foreach ($aliases as $fn => $al) {
            $this->linkedAlias[$fn] = $al;
        }
        $this->hits[$matchedName] = $specName;

        $result = [
            'seg'         => $prog['main'],
            'funcs'       => $prog['funcs'],
            'classes'     => $prog['classes'],
            'funcParams'  => $prog['funcParams'],
            'classParams' => $prog['classParams'],
            'globals'     => $prog['globals'],
            'natTbl'      => $child->natTbl(),
            'aliases'     => $aliases,
            'target'      => $target,
        ];
        // 同模块被多个函数名命中（如 match_regex /^rx_/ 同时命中 rx_tag/rx_wrap）
        // 时，后续命中名复用本次编译结果；必须落库，否则早期返回取到 null
        // 导致第二个函数未内联（退化为原生调用，破坏"全字节码融合"语义）。
        $this->linkedResult[$specName] = $result;
        return $result;
    }

    /**
     * 主程序调用点 + 模块内部引用的统一重写表。
     * 规则：
     *   - 每个已链接模块的原始函数名 → 其别名（先链接者优先，与主程序/嵌入段的
     *     "主程序优先"合并策略一致）；
     *   - 命中名与 target 不同名时，命中名 → target 函数别名（调用点改指向）。
     *
     * @param array<string,array> $linked matchedName => linkModule 返回值（非 null）
     * @return array<string,string>
     */
    public function renameMap(array $linked): array
    {
        $map = [];
        foreach ($linked as $lm) {
            foreach ($lm['aliases'] as $fn => $al) {
                if (!isset($map[$fn])) {
                    $map[$fn] = $al;   // 先链接者优先
                }
            }
        }
        foreach ($linked as $matchedName => $lm) {
            $target = (string) $lm['target'];
            if ($target !== $matchedName && isset($lm['aliases'][$target])) {
                $map[$matchedName] = $lm['aliases'][$target];
            }
        }
        return $map;
    }

    /** @return array<string,string> matchedName => specName */
    public function hits(): array
    {
        return $this->hits;
    }

    /**
     * 传递式链接入口：以主源码的命中点为种子，递归发现并链接【被链接模块源码所引用的其他模块】
     * （设计 §6.5 跨模块互相调用），依赖方优先编译，保证被调用方先进入 linkedAlias，
     * 调用点才会走 CALL 而非 CALL_NATIVE（否则运行期在真实函数表里找不到而致命）。
     *
     * 例：cross_run（crossmod）调用 mathx_double（mathx）。仅主源码出现 cross_run 时，
     * mathx 不会因主源码扫描被命中 —— 必须以 crossmod 源码为种子再扫一次，才能把 mathx
     * 也链进来。
     *
     * @param array<string,array> $seeds matchedName => spec（通常来自 matchSource($source)）
     * @return array<string,array> matchedName => linkModule 返回值（非 null）
     */
    public function linkModules(array $seeds, IncludeResolver $resolver, array $mainTopFns = []): array
    {
        $linked = [];
        $this->linkRecursive($seeds, $resolver, $mainTopFns, $linked, []);
        return $linked;
    }

    /**
     * @param array<string,array> $matches
     * @param array<string,array> $linked   matchedName => linkModule 结果（跨递归共享）
     * @param array<string,true>  $visiting 正在递归链接的 specName（循环依赖守卫）
     */
    private function linkRecursive(array $matches, IncludeResolver $resolver, array $mainTopFns, array &$linked, array $visiting): void
    {
        foreach ($matches as $mname => $spec) {
            $specName = (string) $spec['name'];
            if (isset($this->linkedModules[$specName])) {
                $this->hits[$mname] = $specName;   // 仍记录命中（统计）
                // 同模块被多个名命中（如 match_regex 同时命中 rx_tag/rx_wrap）：
                // 把已链接结果也登记到本命中名，使主编译器预登记该名走 CALL
                // 而非 CALL_NATIVE（否则运行期在真实函数表找不到而致命）。
                if (isset($this->linkedResult[$specName])) {
                    $linked[$mname] = $this->linkedResult[$specName];
                }
                continue;
            }
            if (isset($visiting[$specName])) {
                continue;   // 循环依赖：本次跳过，由外层链接（best-effort）
            }
            if (!($spec['replace'] ?? true)) {
                $this->linkedModules[$specName] = true;
                $this->skips[$mname] = "模块 {$specName} replace=false，未内联（调用点保持原生分发）";
                continue;
            }
            // 依赖优先：先递归链接本模块源码引用的其他模块
            $modSrc = $this->moduleSourceForScan($spec);
            if ($modSrc !== '') {
                $sub = $this->matchSource($modSrc);
                if ($sub !== []) {
                    $this->linkRecursive($sub, $resolver, $mainTopFns, $linked, $visiting + [$specName => true]);
                }
            }
            $lm = $this->linkModule($spec, $mname, $resolver, $mainTopFns);
            if ($lm !== null) {
                $linked[$mname] = $lm;
            }
        }
    }

    /**
     * 取出一个模块的【拼接源码】（entry + deps，去标签）供 matchSource 再扫描其引用的
     * 其他模块。include 指令不影响函数调用位判定，故无需递归扁平化。
     */
    private function moduleSourceForScan(array $spec): string
    {
        $entryRel = (string) $spec['entry'];
        if ($entryRel === '') {
            return '';
        }
        $entryAbs = $this->resolvePath($entryRel, (string) $spec['dir']);
        if (!is_file($entryAbs)) {
            return '';
        }
        $src = $this->readStrip($entryAbs);
        foreach ((array) $spec['deps'] as $dep) {
            $depAbs = $this->resolvePath((string) $dep, (string) $spec['dir']);
            if (is_file($depAbs)) {
                $src .= "\n" . $this->readStrip($depAbs);
            }
        }
        return $src;
    }

    /** @return array<string,string> matchedName => 原因 */
    public function skips(): array
    {
        return $this->skips;
    }

    /**
     * 模块 main 段除隐式结构外是否含顶层语句。
     * 子编译器不为模块补 PUSH_C 1/RET（那是 IncludeResolver::embedFile 为主程序
     * 嵌入表补的），因此 main 非空即可能有顶层语句——但模块内的 include（如
     * require_once __DIR__ . '/helper.php'）被编译为 INCLUDE_EMBED 指令落在 main
     * 段，不属于"业务顶层语句"，必须忽略，否则会误报"含顶层语句"而污染 skips。
     *
     * @param array<int,mixed> $main
     */
    private function moduleHasTopLevel(array $main): bool
    {
        $embedOp = VmOp::INCLUDE_EMBED;
        foreach ($main as $ins) {
            if (!is_array($ins) || $ins === []) {
                continue;
            }
            if (($ins[0] ?? null) === $embedOp) {
                continue;   // 模块内 include 不算业务顶层语句
            }
            return true;
        }
        return false;
    }

    /**
     * 单个函数名是否命中某清单：先精确 match，再 match_regex。
     * 多清单命中取优先级高者（同级取先加载者——specs 已按文件名排序）。
     */
    private function matchName(string $name): ?array
    {
        $best = null;
        foreach ($this->specs as $spec) {
            if (in_array($name, $spec['match'], true)) {
                if ($best === null || $spec['priority'] > $best['priority']) {
                    $best = $spec;
                }
                continue;
            }
            foreach ($spec['match_regex'] as $re) {
                if (@preg_match($re, $name)) {
                    if ($best === null || $spec['priority'] > $best['priority']) {
                        $best = $spec;
                    }
                    break;
                }
            }
        }
        return $best;
    }

    /**
     * @param array<int,mixed> $toks
     */
    private function nextIsCall(array $toks, int $from): bool
    {
        $n = count($toks);
        for ($j = $from; $j < $n; $j++) {
            $t = $toks[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_string($t) && $t === '(';
        }
        return false;
    }

    /**
     * @param array<int,mixed> $toks
     * @return mixed
     */
    private function prevSig(array $toks, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $toks[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    private function resolvePath(string $p, string $baseDir): string
    {
        if ($p === '') {
            return $p;
        }
        if ($p[0] === '/' || $p[0] === '\\' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p)) {
            return $p;   // 绝对路径（含 Windows 盘符）
        }
        $base = rtrim($baseDir, '/\\');
        return ($base === '' ? '.' : $base) . '/' . $p;
    }

    private function readStrip(string $abs): string
    {
        $s = (string) file_get_contents($abs);
        $s = (string) preg_replace('/^\s*<\?php/i', '', $s, 1);
        $s = (string) preg_replace('/\?>\s*$/', '', $s);
        return $s;
    }

    /**
     * 把模块的【原生源码】拼成一份可嵌入产物顶层的可执行字符串：entry + deps +
     * 模块内静态 include 全部递归内联，去开/闭标签，include 指令移除（已内联）。
     *
     * 用途（设计 §6.6 / 测试 badmod_skip、noreplace）：模块【匹配但未被 VM 内联】
     * （replace=false 或链接失败）时，调用点仍走 CALL_NATIVE；若产物里没有该函数，
     * 运行期会致命。本方法把模块源码以原生形式兜底进产物，保证自包含、不致命。
     * 文件缺失返回 null。
     *
     * @param array<string,mixed> $spec
     */
    public function inlineSource(array $spec): ?string
    {
        $entryRel = (string) $spec['entry'];
        if ($entryRel === '') {
            return null;
        }
        $entryAbs = $this->resolvePath($entryRel, (string) $spec['dir']);
        if (!is_file($entryAbs)) {
            return null;
        }
        $parts = [];
        $seen = [];
        $this->collectInline($entryAbs, dirname($entryAbs), $parts, $seen);
        foreach ((array) $spec['deps'] as $dep) {
            $depAbs = $this->resolvePath((string) $dep, (string) $spec['dir']);
            if (is_file($depAbs)) {
                $this->collectInline($depAbs, dirname($depAbs), $parts, $seen);
            }
        }
        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * 递归收集一个文件的可内联内容到 $parts（去标签 + 静态 include 内联）。
     *
     * @param array<int,string> $parts
     * @param array<string,true> $seen
     */
    private function collectInline(string $abs, string $baseDir, array &$parts, array &$seen): void
    {
        $key = realpath($abs) ?: $abs;
        if (isset($seen[$key])) {
            return;   // 同一文件被多个模块互相 include 时只展开一次
        }
        $seen[$key] = true;
        $src = $this->readStrip($abs);
        // 递归扁平化静态 include（__DIR__ . '/x' / 相对或绝对值字面量），移除 include
        // 指令（已内联）；变量/函数调用路径无法静态解析则原样保留，运行期 INCLUDE_RT 兜底。
        $src = (string) preg_replace_callback(
            '/^\s*(?:require|require_once|include|include_once)\b(.*?);/m',
            function (array $m) use ($baseDir, &$parts, &$seen): string {
                $path = $this->resolveIncludeExpr(trim($m[1]), $baseDir);
                if ($path !== null && is_file($path)) {
                    $this->collectInline($path, dirname($path), $parts, $seen);
                }
                return '';
            },
            $src
        );
        $parts[] = $src;
    }

    /**
     * 解析 include 路径表达式：支持 'x' / "x" / __DIR__ . '/x'（变量路径 / 函数调用
     * / __FILE__ 返回 null，交给运行期兜底）。
     */
    private function resolveIncludeExpr(string $expr, string $baseDir): ?string
    {
        $expr = trim($expr);
        if ($expr === '' || $expr === '__FILE__') {
            return null;
        }
        // 纯字符串字面量
        if (preg_match("/^['\"]([^'\"]*)['\"]$/", $expr, $mm)) {
            $p = $mm[1];
            if ($p === '') {
                return null;
            }
            return $p[0] === '/' || $p[0] === '\\' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p)
                ? $p
                : rtrim($baseDir, '/\\') . '/' . $p;
        }
        // __DIR__ . '/x' 或 __DIR__."/x"
        if (preg_match("/^__DIR__\s*\.\s*['\"]([^'\"]*)['\"]$/", $expr, $mm)) {
            $p = $mm[1];
            if ($p === '') {
                return null;
            }
            return $p[0] === '/' || $p[0] === '\\' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p)
                ? $p
                : rtrim($baseDir, '/\\') . '/' . $p;
        }
        return null;   // 变量/函数调用路径：静态无法解析
    }
}
