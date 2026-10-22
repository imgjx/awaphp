<?php
/**
 * 混淆调度器（Obfuscator）
 *
 * 按配置顺序编排各混淆模块，支持重复加密（--repeat）。
 *
 * 内部单次管线（token 级）：
 *   1. 变量名混淆
 *   2. 函数名混淆（命名）
 *   3. 函数调用混淆（仅最后一次重复执行）
 *   4. 类名混淆
 *   5. 控制流混淆 / GOTO 混淆（仅最后一次重复执行）
 *   6. 数字编码
 *   7. 字符串编码（--str-xor 时改用运行时异或装配）
 *   8. 移除注释
 *   9. 插入无用代码
 *   10. 序列化钩子
 *   11. 空白 / 压缩
 *
 * 重复结束后（字符串级，按序包裹，每步 php -l 校验，失败回滚）：
 *   - 目标 PHP 版本外壳
 *   - 反调试探针
 *   - VM 虚拟机壳 / EVAL 加壳
 *   - GZ 压缩加壳（最终外壳）
 *   - 诱饵函数（整体编码进 gzinflate/base64 壳，不暴露明文，永不调用、零运行期开销）
 *   - 文件头版权注释（须在自校验前，使基线包含该注释）
 *   - 自校验防篡改（守卫逻辑本身编码进 gzinflate/base64 壳，不再明文暴露，且无忙等开销）
 *
 * 最外层：注入文件头"混淆引擎名 + 引擎版本号"。
 */

declare(strict_types=1);

final class Obfuscator
{
    public function __construct(
        private Config $config,
        private Salt $salt,
        private Logger $logger,
        private string $toolName = '嗷呜awaのphp混淆器',
        private string $toolVersion = 'dev',
        private ?SymbolTable $symbols = null
    ) {
        $this->inj = null;
    }

    private ?CodeInjector $inj = null;

    /**
     * 登记当前被混淆的主文件路径（供 VM include 解析用）：
     *  - baseDir = dirname(path)：运行期动态 include 的相对路径回退基准
     *  - curFile = path：__DIR__ / __FILE__ 的编译期折值来源（产物是单文件，
     *    运行期自身路径与源无关，必须忠实保义折值为原始源码路径）
     * 多文件场景（ProjectBuilder）每个文件混淆前重新登记即可。
     */
    public function setSourceFile(string $path): void
    {
        $this->srcFile = $path;
    }

    private string $srcFile = '';

    /**
     * 执行完整混淆，返回最终源码字符串。
     *
     * 若配置含分层定义（--level 预设或自定义分层 JSON），走分层执行模型 runLayered()；
     * 否则走旧版顺序管线（保持向后兼容，ProjectBuilder 等调用方不受影响）。
     */
    public function run(string $code): string
    {
        if ($this->config->hasLayers()) {
            return $this->runLayered($code);
        }
        $repeat = max(1, (int) $this->config->get('repeat'));
        $current = $code;
        $lastGood = $code;

        for ($pass = 1; $pass <= $repeat; $pass++) {
            $isLast = $pass === $repeat;
            try {
                $result = $this->pipelineOnce($current, $isLast);
            } catch (Throwable $e) {
                $this->logger->warn("第 {$pass} 次混淆异常，回滚到第 {$pass} 次之前的安全版本：" . $e->getMessage());
                $current = $lastGood;
                break;
            }
            $check = (new Validator($this->logger))->checkSyntaxString($result);
            if (!$check['ok']) {
                $this->logger->warn("第 {$pass} 次混淆产物未通过 php -l，回滚到第 {$pass} 次之前的安全版本");
                $current = $lastGood;
                break;
            }
            $lastGood = $result;
            $current = $result;
        }

        // ===== 字符串级外壳（依次包裹；每步 php -l 校验，失败回滚） =====
        $phpVer = new PhpVersion($this->logger, $this->salt);
        $phpVer->setTarget((string) $this->config->get('php_version'));
        $current = $this->shell($current, '目标PHP版本外壳', fn ($c) => $phpVer->wrap($c));

        // 反调试 / 防 hook eval：置于编码壳之前（最内层），被 vm/eval/gz 裹入密文，
        // 攻击者须先破壳才能看到、更无从"整段删除代码块"让保护蒸发。
        if ($this->config->get('anti_debug')) {
            $adOpts = [
                'probes'    => (string) $this->config->get('ad_probes'),
                'intensity' => (int) $this->config->get('ad_intensity'),
            ];
            $current = $this->shell($current, '反调试探针', fn ($c) => (new AntiDebug($this->salt, $this->logger))->wrap($c, $adOpts));
        }
        if ($this->config->get('anti_hook')) {
            $ahOpts = ['strict' => (bool) $this->config->get('ah_strict')];
            $current = $this->shell($current, '防hook eval守卫', fn ($c) => (new AntiHookEval($this->salt, $this->logger))->wrap($c, $ahOpts)['code']);
        }
        if ($this->config->get('vm')) {
            // 新方案：统一走自定义指令集 VM（VmRuntimeGenerator），替代旧版 XOR+eval 壳。
            // 旧 VirtualMachine（XOR+eval）对含类/HTML 的样例覆盖度差、产物运行不一致，已弃用。
            $vmOpts = [
                'flatten'      => (bool) $this->config->get('vm_flatten'),
                'stateMachine' => (bool) $this->config->get('vm_state_machine'),
                'chunks'       => (int) $this->config->get('vm_chunks'),
                // P6: ModuleLinker 模块目录（命中清单的函数调用内联进同一 Program）
                // 默认使用内置模块目录（内置 base64 等"原生函数替换"模块），
                // 若显式指定 vm_linker 则以其为准。
                'moduleDir'    => $this->resolveModuleDir($this->effectiveModuleDir()),
                'junk'         => (int) $this->config->get('vm_junk'),
                // P8: 五个架构方向（默认全关）
                'keychain'       => (bool) $this->config->get('vm_keychain'),
                'builtinReplace' => (bool) $this->config->get('vm_builtin_replace'),
                'morph'          => (bool) $this->config->get('vm_morph'),
                'fakeFog'        => (int) $this->config->get('vm_fake_fog'),
                'probe'          => (int) $this->config->get('vm_probe'),
                // VM 常量池加密（默认开，--no-vm-cpool 关）
                'cpool'          => (bool) $this->config->get('vm_cpool'),
            ];
            $current = $this->shell($current, 'VM虚拟机壳', fn ($c) => $this->shellVm($vmOpts, $c));
        }
        if ($this->config->get('eval')) {
            $current = $this->shell($current, 'EVAL加壳', fn ($c) => (new EvalShell($this->salt, $this->logger))->wrap($c, (int) $this->config->get('eval_layers')));
        }
        if ($this->config->get('gz')) {
            $gzOpts = [
                'decoy'  => (bool) $this->config->get('gz_decoy'),
                'chunks' => (int) $this->config->get('gz_chunks'),
            ];
            $current = $this->shell($current, 'GZ压缩加壳', fn ($c) => (new GzEncoder($this->logger, $this->salt))->wrap($c, $gzOpts));
        }
        // 诱饵函数：拟真命名 + 拟真函数体，整体编码进 gzinflate/base64 壳（不暴露明文），
        // 永不调用、零运行期开销；追加在加壳之后、自校验之前，使防篡改基线包含该壳行。
        $junk = (int) $this->config->get('junk_funcs');
        if ($junk > 0) {
            $current = $this->shell($current, '诱饵函数', fn ($c) => (new JunkFunctions($this->salt, $this->logger))->inject($c, $junk));
        }

        // 文件头引擎信息：须在自校验前注入，使防篡改基线包含该头注释，
        // 否则运行期 __FILE__ 含头注释而基线不含，会误报"被篡改"。
        $current = (new Copyright($this->logger))->inject($current, $this->config, $this->toolName, $this->toolVersion);

        // 防 hook eval 守卫已在编码壳之前（最内层）注入，此处不再单独处理；
        // 其行被 vm/eval/gz 裹入密文，自校验防篡改的分段哈希覆盖的是该密文区段。

        // 自校验防篡改置于最外层：掩码自校验 + 分段哈希 + shutdown 二次校验（逻辑全部编码）
        if ($this->config->get('anti_tamper')) {
            $atOpts = [
                'segments' => (int) $this->config->get('at_segments'),
                'shutdown' => (bool) $this->config->get('at_shutdown'),
            ];
            $current = $this->shell($current, '自校验防篡改', fn ($c) => (new AntiTamper($this->salt, $this->logger))->wrap($c, $atOpts)['code']);
        }

        // 授权绑定守卫（域名 + 有效期）：置于最后，未配置时完全不注入
        $dom = (string) $this->config->get('license_domain');
        $exp = (string) $this->config->get('license_expire');
        if ($dom !== '' || $exp !== '') {
            $current = $this->shell($current, '授权绑定守卫', fn ($c) => (new LicenseGuard($this->salt, $this->logger))->wrap($c, $dom, $exp)['code']);
        }

        return $current;
    }

    // ============================================================
    //  分层执行模型（等级预设 / 自定义分层 JSON）
    // ============================================================

    /**
     * 分层执行：layers 按顺序执行。token 级层累积作用于同一 token 流，
     * shell:* 层包裹源码字符串。每层独立 try/catch + php -l 校验，失败回滚到上一层产物。
     */
    private function runLayered(string $code): string
    {
        foreach ((array) $this->config->get('layer_warnings', []) as $w) {
            $this->logger->warn((string) $w);
        }
        $layers = $this->orderShellLayers($this->config->getLayers());
        $current = rtrim($code);
        $tokens = null; // 连续 token 层累积于此，遇 shell 层再 emit

        foreach ($layers as $layer) {
            $current = $this->applyLayer($layer, $tokens, $current);
        }
        if ($tokens !== null) {
            $current = $this->emit($tokens);
        }
        // 文件头引擎信息（除非关闭）—— 与旧管线一致
        if (!$this->config->get('no_banner')) {
            $current = (new Copyright($this->logger))->inject($current, $this->config, $this->toolName, $this->toolVersion);
        }
        return $current;
    }

    /**
     * shell 层顺序规整（防"外壳可被整体剥离"）：
     *  - 反调试（shell:anti_debug）/ 防 hook eval（shell:anti_hook）无明文锚点依赖，
     *    移到最内层（数组最前、最先应用），让 gz / eval / vm 等编码壳把它们裹进密文——
     *    攻击者必须先破壳才能看到，更无从"整段删除代码块"让保护蒸发；
     *  - 自校验防篡改（shell:anti_tamper）与授权绑定（shell:license）必须位于所有编码壳
     *    之外（数组最后、最后应用）：anti_tamper 守卫行以明文锚点（$x=__FILE__;）定位自身，
     *    若被编码壳吞进密文，运行期 strpos 找不到锚点会误报"被篡改"并终止，故明文锚点须可见。
     *
     * 已知约束：anti_tamper 因上述设计无法内埋，是相对薄弱的一环；如需彻底藏匿，
     * 须重构其自定位机制（如改为校验内存中已解码区段，而非读 __FILE__ 明文锚点）。
     *
     * 数组越靠后 = 越外层（每层把整段 $code 再包一层），故 head 最内、tail 最外。
     *
     * @param array<int,array{name:string,params:array,inject:array}> $layers
     * @return array<int,array{name:string,params:array,inject:array}>
     */
    private function orderShellLayers(array $layers): array
    {
        // 最内层（最先应用，被后续编码壳包裹藏入密文）
        $innermost = ['shell:anti_debug' => true, 'shell:anti_hook' => true];
        // 最外层（最后应用，明文锚点须可见）
        $outermost = ['shell:anti_tamper' => true, 'shell:license' => true];
        $head = [];
        $keep = [];
        $tail = [];
        foreach ($layers as $layer) {
            $name = $layer['name'] ?? '';
            if (isset($innermost[$name])) {
                $head[] = $layer;
            } elseif (isset($outermost[$name])) {
                $tail[] = $layer;
            } else {
                $keep[] = $layer;
            }
        }
        return array_merge($head, $keep, $tail);
    }

    /**
     * @param array{name:string,params:array,inject:array} $layer
     * @param array<int,mixed>|null $tokens 引用：连续 token 层在此累积
     */
    private function applyLayer(array $layer, ?array &$tokens, string $code): string
    {
        $name = $layer['name'];
        $kind = LayerRegistry::kind($name);
        if ($kind === null) {
            $this->logger->warn("未知分层层名，跳过: {$name}");
            return $code;
        }
        // 每层参数经 LayerDescriptor 归一化（补默认值/夹取范围）+ 校验（未知参数告警）
        $params = LayerDescriptor::normalize($name, $layer['params']);
        foreach (LayerDescriptor::validate($name, $layer['params']) as $w) {
            $this->logger->warn($w);
        }
        $inject = $layer['inject'];
        $before = $code; // 安全点：任何失败都回退到这一层开始前的已知有效源码
        try {
            if ($kind === 'token') {
                if ($tokens === null) {
                    $tokens = Parser::tokenize($code);
                }
                $this->applyTokenLayer($name, $params, $tokens);
                return $code; // token 流继续累积，暂不落地
            }
            // shell / inject 层：先把累积 token 落地为源码
            if ($tokens !== null) {
                $code = $this->emit($tokens);
                $tokens = null;
            }
            if ($kind === 'shell') {
                $code = $this->applyShellLayer($name, $params, $code);
            }
            $code = $this->applyInject($inject, $code);
        } catch (Throwable $e) {
            $this->logger->warn("[层 {$name}] 执行异常，回滚到上一层: " . $e->getMessage());
            $tokens = null;
            return $before;
        }
        $check = (new Validator($this->logger))->checkSyntaxString($code);
        if (!$check['ok']) {
            $this->logger->warn("[层 {$name}] 产物未通过 php -l，回滚到上一层");
            $tokens = null;
            return $before;
        }
        return $code;
    }

    /** @param array<int,mixed> $tokens */
    private function applyTokenLayer(string $name, array $params, array &$tokens): void
    {
        $cfg = $this->layerConfig($name, $params);
        $ctx = [];
        switch ($name) {
            case 'var':
                $tokens = (new VariableObfuscator($this->salt, $this->logger))->process($tokens, $cfg);
                break;
            case 'func':
                $tokens = (new FunctionObfuscator($this->salt, $this->logger, $this->symbols))->process($tokens, $cfg, $ctx);
                if ($cfg->get('func_call_enc')) {
                    $tokens = (new FunctionCallEncoder($this->logger))->process($tokens, $cfg, $ctx);
                }
                break;
            case 'class':
                $tokens = (new ClassObfuscator($this->salt, $this->logger, $this->symbols))->process($tokens, $cfg, $ctx);
                break;
            case 'controlflow':
                $tokens = (new ControlFlowObfuscator($this->logger, $this->salt))->process($tokens, $cfg, true);
                $tokens = (new GotoObfuscator($this->logger, $this->salt))->process($tokens, $cfg, true);
                break;
            case 'number':
                $tokens = (new NumberEncoder($this->salt, $this->logger))->process($tokens, $cfg);
                break;
            case 'string':
                if ($cfg->get('str_xor')) {
                    $tokens = (new StringRuntimeAssembler($this->salt, $this->logger))->process($tokens, $cfg);
                } else {
                    $tokens = (new StringEncoder($this->salt, $this->logger))->process($tokens, $cfg);
                }
                break;
            case 'comment':
                $tokens = (new CommentRemover($this->logger))->process($tokens, $cfg);
                break;
            case 'stat':
                $tokens = (new StatHook($this->logger))->process($tokens, $cfg);
                break;
            case 'ws':
                $tokens = (new Whitespace($this->logger))->process($tokens, $cfg);
                break;
            case 'junk_runtime':
                $tokens = (new RuntimeJunk($this->salt, $this->logger))->process($tokens, $cfg);
                break;
            case 'dead':
                $tokens = (new DeadCodeInjector($this->salt, $this->logger))->process($tokens, $cfg);
                break;
            case 'semantic':
                $tokens = (new SemanticRewriter($this->salt, $this->logger))->process($tokens, $cfg);
                break;
            case 'token':
                $tokens = (new TokenObfuscator($this->salt, $this->logger))->process($tokens, $cfg);
                break;
        }
    }

    /**
     * 由层 params 派生该层的临时 Config（合并全局配置 + 本层参数映射后的配置键）。
     */
    private function layerConfig(string $name, array $params): Config
    {
        $map = [];
        switch ($name) {
            case 'var':
                $map['var_length'] = (int) ($params['length'] ?? 8);
                break;
            case 'func':
                $map['func_length'] = (int) ($params['length'] ?? 10);
                $map['func_call'] = (bool) ($params['call'] ?? false);
                $map['func_call_enc'] = (bool) ($params['enc'] ?? false);
                break;
            case 'class':
                $map['class_obf'] = (bool) ($params['on'] ?? false);
                break;
            case 'controlflow':
                $map['cfg'] = $params['cfg'] ?? 'medium';
                $map['goto'] = (int) ($params['goto'] ?? 0);
                $map['goto_cpx'] = (int) ($params['goto_cpx'] ?? 0);
                break;
            case 'number':
                $map['num_enc'] = (bool) ($params['on'] ?? false);
                break;
            case 'string':
                $map['str_xor'] = (bool) ($params['xor'] ?? false);
                $map['str_length'] = (int) ($params['length'] ?? 0);
                $map['var_split'] = (int) ($params['split'] ?? 1);
                break;
            case 'comment':
                $map['no_comments'] = true;
                break;
            case 'stat':
                $map['stat_hook'] = true;
                break;
            case 'ws':
                $map['strip_newline'] = true;
                $map['compress'] = true;
                break;
            case 'junk_runtime':
                $map['junk_intensity'] = (int) ($params['intensity'] ?? 1);
                $map['junk_fake_b64']  = (bool) ($params['fake_b64'] ?? true);
                $map['junk_fake_echo'] = (bool) ($params['fake_echo'] ?? true);
                break;
            case 'dead':
                $map['insert_dead']  = true;
                $map['dead_density'] = (int) ($params['density'] ?? 3);
                break;
            case 'semantic':
                $map['semantic']          = true;
                $map['semantic_strength'] = (int) ($params['strength'] ?? 2);
                break;
            case 'token':
                $map['token_obf']     = true;
                $map['token_case']    = (bool) ($params['case'] ?? true);
                $map['token_literal'] = (bool) ($params['literal'] ?? true);
                $map['token_quote']   = (bool) ($params['quote'] ?? true);
                $map['token_heredoc'] = (bool) ($params['heredoc'] ?? false);
                $map['token_decoy']   = (int) ($params['decoy'] ?? 3);
                break;
        }
        return new Config(array_merge($this->config->all(), $map));
    }

    private function applyShellLayer(string $name, array $params, string $code): string
    {
        $key = LayerRegistry::shellKey($name);
        switch ($key) {
            case 'vm':
                return $this->shellVm($params, $code);
            case 'eval':
                return (new EvalShell($this->salt, $this->logger))->wrap($code, (int) ($params['layers'] ?? 3));
            case 'gz':
                return (new GzEncoder($this->logger, $this->salt))->wrap($code, [
                    'decoy'  => (bool) ($params['decoy'] ?? true),
                    'chunks' => (int) ($params['chunks'] ?? 3),
                ]);
            case 'anti_debug':
                return (new AntiDebug($this->salt, $this->logger))->wrap($code, [
                    'probes'    => (string) ($params['probes'] ?? 'ext,func,ini,env,sapi,trace'),
                    'intensity' => (int) ($params['intensity'] ?? 2),
                ]);
            case 'anti_hook':
                return (new AntiHookEval($this->salt, $this->logger))->wrap($code, [
                    'strict' => (bool) ($params['strict'] ?? true),
                ])['code'];
            case 'anti_tamper':
                return (new AntiTamper($this->salt, $this->logger))->wrap($code, [
                    'segments' => (int) ($params['segments'] ?? 4),
                    'shutdown' => (bool) ($params['shutdown'] ?? true),
                ])['code'];
            case 'license':
                $dom = (string) ($params['domain'] ?? '');
                $exp = (string) ($params['expire'] ?? '');
                if ($dom === '' && $exp === '') {
                    return $code;
                }
                return (new LicenseGuard($this->salt, $this->logger))->wrap($code, $dom, $exp)['code'];
            default:
                return $code;
        }
    }

    /**
     * shell:vm 层：把源码编译进自定义 ISA VM（替代旧 VirtualMachine 的 XOR+eval 壳）。
     * 失败时优雅回退（保留未 VM 化的源码，继续后续外壳）。
     */
    private function shellVm(array $params, string $code): string
    {
        $src = preg_replace('/^\s*<\?php/i', '', $code, 1);
        $src = preg_replace('/\?>\s*$/', '', $src);
        if (trim($src) === '') {
            return $code;
        }
        $opts = [
            'flatten'      => (bool) ($params['flatten'] ?? false),
            'stateMachine' => (bool) ($params['stateMachine'] ?? false),
            'chunks'      => (int) ($params['chunks'] ?? 3),
            // include 解析：基目录取主文件所在目录；未登记（库内直调）时退化为空
            'baseDir'     => $this->srcFile !== '' ? dirname($this->srcFile) : '',
            'curFile'     => $this->srcFile,
            // P6: ModuleLinker 模块目录（分层参数键 modules；散装键 vm_linker 已折算）。
            // 未显式指定时，若启用内置模块（vm_link_modules），回退到内置模块目录。
            'moduleDir'   => $this->resolveModuleDir($this->effectiveModuleDir(
                (string) ($params['modules'] ?? $params['moduleDir'] ?? '')
            )),
            // P7/P8: 字节码垃圾 + 五个架构方向（分层参数键直通）
            'junk'         => (int) ($params['junk'] ?? 0),
            'keychain'       => (bool) ($params['keychain'] ?? false),
            'builtinReplace' => (bool) ($params['builtinReplace'] ?? false),
            'morph'          => (bool) ($params['morph'] ?? false),
            'fakeFog'        => (int) ($params['fakeFog'] ?? 0),
            'probe'          => (int) ($params['probe'] ?? 0),
            // VM 常量池加密（默认开；分层参数键 cpool）
            'cpool'          => (bool) ($params['cpool'] ?? true),
        ];
        try {
            $art = (new VmRuntimeGenerator($this->salt))->buildArtifact($src, $opts);
            foreach ($art['includes'] as $w) {
                $this->logger->warn('shell:vm include: ' . $w);
            }
            // P6: ModuleLinker 命中/跳过统计（命中=已内联进 Program，无路径痕迹）
            foreach ($art['linker']['hits'] as $fn => $specName) {
                $this->logger->info("shell:vm module-link: {$fn} -> 模块[{$specName}]（已内联）");
            }
            foreach ($art['linker']['skips'] as $fn => $reason) {
                $this->logger->warn("shell:vm module-link 跳过 {$fn}: {$reason}");
            }
            return $art['php'];
        } catch (Throwable $e) {
            $this->logger->warn('shell:vm 编译失败，回退到未 VM 化代码: ' . $e->getMessage());
            return $code;
        }
    }

    /**
     * P6：折算 ModuleLinker 模块目录。相对路径优先按主文件所在目录解析
     * （与 include 的 baseDir 一致），未登记主文件时按调用方 cwd 原样使用。
     */
    private function resolveModuleDir(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return '';
        }
        if ($dir[0] === '/' || $dir[0] === '\\' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $dir)) {
            return $dir;
        }
        if ($this->srcFile !== '') {
            $base = dirname($this->srcFile);
            return rtrim($base, '/\\') . '/' . $dir;
        }
        return $dir;
    }

    /**
     * P6：折算最终用于 ModuleLinker 的模块目录。
     * 优先级：分层参数 override > 散装键 vm_linker > 内置模块目录（vm_link_modules 开启时）。
     * 全部为空/关闭则返回 ''（不启用模块链接）。
     */
    private function effectiveModuleDir(string $override = ''): string
    {
        if (trim($override) !== '') {
            return $this->resolveModuleDir($override);
        }
        $custom = (string) $this->config->get('vm_linker');
        if (trim($custom) !== '') {
            return $this->resolveModuleDir($custom);
        }
        if ((bool) $this->config->get('vm_link_modules')) {
            return $this->defaultModuleDir();
        }
        return '';
    }

    /**
     * P6：包内置模块目录（随产品发布，含 base64 等"原生函数替换"模块）。
     */
    private function defaultModuleDir(): string
    {
        return __DIR__ . '/Module/builtin';
    }

    /**
     * 处理一层的 inject：反调试探针 + 代码片段 / probe 文件注入。
     * @param array<string,mixed> $inject
     */
    private function applyInject(array $inject, string $code): string
    {
        if (($inject['anti_debug'] ?? false) && class_exists('AntiDebug')) {
            $lvl = max(1, min(4, (int) ($inject['anti_debug_level'] ?? 2)));
            $code = (new AntiDebug($this->salt, $this->logger))->wrap($code, ['intensity' => $lvl]);
        }
        return $this->codeInjector()->apply($code, $inject);
    }

    private function codeInjector(): CodeInjector
    {
        if ($this->inj === null) {
            $this->inj = new CodeInjector($this->logger, $this->salt);
        }
        return $this->inj;
    }

    /**
     * 应用一个字符串级外壳；失败时回滚到传入的 $code（上一安全态）。
     */
    private function shell(string $code, string $label, callable $fn): string
    {
        try {
            $next = $fn($code);
        } catch (Throwable $e) {
            $this->logger->warn("{$label}应用失败，回滚：" . $e->getMessage());
            return $code;
        }
        $check = (new Validator($this->logger))->checkSyntaxString($next);
        if (!$check['ok']) {
            $this->logger->warn("{$label}产物未通过 php -l，回滚");
            return $code;
        }
        $this->logger->debug("已应用{$label}");
        return $next;
    }

    private function pipelineOnce(string $code, bool $isLast): string
    {
        $tokens = Parser::tokenize($code);

        $var = new VariableObfuscator($this->salt, $this->logger);
        $func = new FunctionObfuscator($this->salt, $this->logger, $this->symbols);
        $funcCall = new FunctionCallEncoder($this->logger);
        $cls = new ClassObfuscator($this->salt, $this->logger, $this->symbols);
        $num = new NumberEncoder($this->salt, $this->logger);
        $comment = new CommentRemover($this->logger);
        $dead = new DeadCodeInjector($this->salt, $this->logger);
        $stat = new StatHook($this->logger);
        $ws = new Whitespace($this->logger);

        $ctx = [];

        $tokens = $var->process($tokens, $this->config);
        $tokens = $func->process($tokens, $this->config, $ctx);
        if ($isLast) {
            $tokens = $funcCall->process($tokens, $this->config, $ctx);
        }
        $tokens = $cls->process($tokens, $this->config, $ctx);

        // 第三阶段（token 级）：控制流 / GOTO 仅在最后一次重复执行
        if ($isLast) {
            $cf = new ControlFlowObfuscator($this->logger, $this->salt);
            $tokens = $cf->process($tokens, $this->config, true);
            $go = new GotoObfuscator($this->logger, $this->salt);
            $tokens = $go->process($tokens, $this->config, true);
        }

        $tokens = $num->process($tokens, $this->config);

        // 字符串编码：--str-xor 时改用运行时异或装配，否则走 base64 分段
        if ($isLast && $this->config->get('str_xor')) {
            $strXor = new StringRuntimeAssembler($this->salt, $this->logger);
            $tokens = $strXor->process($tokens, $this->config);
        } else {
            $str = new StringEncoder($this->salt, $this->logger);
            $tokens = $str->process($tokens, $this->config);
        }

        $tokens = $comment->process($tokens, $this->config);
        $tokens = $dead->process($tokens, $this->config);
        $tokens = $stat->process($tokens, $this->config);
        // 运行时垃圾指令（含执行缠结）：与 dead/stat 一致，由 junk_runtime flag 直接触发，
        // 旧管线（无 layers）走此分支；分层路径由 junk_runtime 层负责，两条路径互斥。
        if ($this->config->get('junk_runtime')) {
            $junk = new RuntimeJunk($this->salt, $this->logger);
            $tokens = $junk->process($tokens, $this->config);
        }
        // 语义等价变换层（结构化改写：算术/布尔恒等、控制流等价），置于 Token 形态混淆之前
        if ($this->config->get('semantic')) {
            $sem = new SemanticRewriter($this->salt, $this->logger);
            $tokens = $sem->process($tokens, $this->config);
        }
        // Token 形态混淆置于末端：上游所有模块（含死代码）产出的关键字一并获得形态变换
        $tok = new TokenObfuscator($this->salt, $this->logger);
        $tokens = $tok->process($tokens, $this->config);
        $tokens = $ws->process($tokens, $this->config);

        return $this->emit($tokens);
    }

    /**
     * 将 token 数组重建为源码字符串，必要时在两个"词素"相邻处补一个空格，
     * 避免压缩/去空白后标识符粘连。
     * @param array<int,mixed> $tokens
     */
    private function emit(array $tokens): string
    {
        $out = '';
        $prevChar = '';
        $prevTok = null;
        foreach ($tokens as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if ($text === '') {
                continue;
            }
            if ($prevChar !== '' && $this->needsSpace($prevTok, $prevChar, $text)) {
                $out .= ' ';
            }
            $out .= $text;
            $prevChar = $text[strlen($text) - 1];
            $prevTok = $tok;
        }
        return $out;
    }

    private function needsSpace($prevTok, string $prevChar, string $text): bool
    {
        $wordy = fn (string $c) => ctype_alnum($c) || $c === '_' || $c === '$';
        if ($wordy($prevChar) && $wordy($text[0])) {
            return true;
        }
        // 数字 token 后紧跟 '.' 会被词法器并成浮点字面量（`1 . 'a'` 压缩为
        // `1.'a'` → `1.` + `'a'` 语法错误）。Token 形态混淆的 true→!0 会产生
        // 新的 T_LNUMBER，同样落入该陷阱，必须补空格。
        if ($text[0] === '.' && is_array($prevTok)
            && in_array($prevTok[0], [T_LNUMBER, T_DNUMBER], true)) {
            return true;
        }
        return false;
    }
}
