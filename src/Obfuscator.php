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
 *   - 蜜罐函数（钓鱼假函数，落在毒化壳受护区内）
 *   - 结构指纹毒化壳（受护区指纹进密钥派生；美化/篡改/插桩 → 解出乱码）
 *   - 自校验防篡改（守卫逻辑本身编码进 gzinflate/base64 壳，不再明文暴露，且无忙等开销）
 *
 * 最外层：授权绑定守卫（如配置）。文件头"引擎名 + 版本号"注入在毒化壳之内。
 */

declare(strict_types=1);

final class Obfuscator
{
    /**
     * 本工具 VM 产物的"构建标记"：追加在产物末尾的短注释。
     *
     * 用途：真·嵌套 VM（vm_layers>=2）时识别"上一层已经是 VM 产物"。这种产物绝不能
     * 再走一遍普通编译——那等于把运行期解释器本身编译成字节码（VM 类语义、$GLOBALS
     * 桥、by-ref 形参数组都没有等价表达），实测会静默挂死。带标记时改走不透明载荷嵌套。
     *
     * 标记本身不含工具指纹，删掉它也不会改变产物语义（只是退化为"重新编译"）。
     */
    private const VM_ARTIFACT_MARK = '/*@vmb:2f9c*/';

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
        // 换行缠绕配额：= 输入源码字节数 / divisor（默认 5），按"每一层壳"平均分摊
        $this->mcActive = true;
        $this->nlInit(
            $this->config->get('newline_poison') ? strlen($code) : 0,
            $this->nlPlanSingle()
        );
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
        // 说明：每层壳包裹前先注入"本层分内的换行"（poisonTick），使换行散落在每一层壳里，
        // 而不是集中成一段——越内层的换行会被后续壳一并加密进密文，成为载荷的一部分。
        $current = $this->injectTicks($current, 'phpver');
        $phpVer = new PhpVersion($this->logger, $this->salt);
        $phpVer->setTarget((string) $this->config->get('php_version'));
        $current = $this->shell($current, '目标PHP版本外壳', fn ($c) => $phpVer->wrap($c), 'phpver');

        // 反调试 / 防 hook eval：置于编码壳之前（最内层），被 vm/eval/gz 裹入密文，
        // 攻击者须先破壳才能看到、更无从"整段删除代码块"让保护蒸发。
        if ($this->config->get('anti_debug')) {
            $adOpts = [
                'probes'    => (string) $this->config->get('ad_probes'),
                'intensity' => (int) $this->config->get('ad_intensity'),
            ];
            $current = $this->injectTicks($current, 'anti_debug');
            $current = $this->shell($current, '反调试探针', fn ($c) => (new AntiDebug($this->salt, $this->logger))->wrap($c, $adOpts), 'anti_debug');
        }
        if ($this->config->get('anti_hook')) {
            $ahOpts = ['strict' => (bool) $this->config->get('ah_strict')];
            $current = $this->injectTicks($current, 'anti_hook');
            $current = $this->shell($current, '防hook eval守卫', fn ($c) => (new AntiHookEval($this->salt, $this->logger))->wrap($c, $ahOpts)['code'], 'anti_hook');
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
                // VM 嵌套层数（真·嵌套 VM）：把上一层产物再喂给 VM 编译，实现多层 VM 壳。
                // 默认 1（单层）；>=2 时逐层回灌自己，每层先剥 PHP 开闭标签。
                'layers'         => max(1, (int) $this->config->get('vm_layers')),
                // VM opcode 组合执行（--vm-combo）：单 opcode 拆等价多指令序列，抗反编译。0=关。
                'combo'          => (int) $this->config->get('vm_combo'),
            ];
            $current = $this->injectTicks($current, 'vm');
        $current = $this->shell($current, 'VM虚拟机壳', fn ($c) => $this->shellVm($vmOpts, $c), 'vm');
        }
        if ($this->config->get('eval')) {
            $current = $this->injectTicks($current, 'eval');
            $current = $this->shell($current, 'EVAL加壳', fn ($c) => (new EvalShell($this->salt, $this->logger))->wrap($c, (int) $this->config->get('eval_layers')), 'eval');
        }
        if ($this->config->get('gz')) {
            $gzOpts = [
                'decoy'  => (bool) $this->config->get('gz_decoy'),
                'chunks' => (int) $this->config->get('gz_chunks'),
            ];
            $current = $this->injectTicks($current, 'gz');
            $current = $this->shell($current, 'GZ压缩加壳', fn ($c) => (new GzEncoder($this->logger, $this->salt))->wrap($c, $gzOpts), 'gz');
        }
        // 诱饵函数：拟真命名 + 拟真函数体，整体编码进 gzinflate/base64 壳（不暴露明文），
        // 永不调用、零运行期开销；追加在加壳之后、自校验之前，使防篡改基线包含该壳行。
        $junk = (int) $this->config->get('junk_funcs');
        if ($junk > 0) {
            $current = $this->injectTicks($current, 'junk');
            $current = $this->shell($current, '诱饵函数', fn ($c) => (new JunkFunctions($this->salt, $this->logger))->inject($c, $junk), 'junk');
        }

        // 文件头引擎信息：须在自校验前注入，使防篡改基线包含该头注释，
        // 否则运行期 __FILE__ 含头注释而基线不含，会误报"被篡改"。
        $current = (new Copyright($this->logger))->inject($current, $this->config, $this->toolName, $this->toolVersion);

        // Confrontation-LLM：反 LLM 静态分析。须在自校验/毒化壳之前注入（本行位于其内，
        // 使伪造注释与生僻 CJK 一并纳入防篡改指纹），且仅产生纯注释，绝不影响运行。
        if ($this->config->get('confrontation_llm')) {
            if (!class_exists(LlmConfrontation::class, false)) {
                require_once __DIR__ . '/Confrontation/LlmConfrontation.php';
            }
            $current = (new LlmConfrontation($this->salt))->inject($current, (int) $this->config->get('confrontation_intensity'));
        }

        // 防 hook eval 守卫已在编码壳之前（最内层）注入，此处不再单独处理；
        // 其行被 vm/eval/gz 裹入密文，自校验防篡改的分段哈希覆盖的是该密文区段。

        // 蜜罐函数（钓鱼陷阱）：拟真高价值命名，正常永不调用（零影响）；
        // 必须注入在毒化壳【之前】：毒化壳只对自己标记区段做指纹，蜜罐落在区内。
        $hp = (int) $this->config->get('honeypot');
        if ($hp > 0) {
            if (!class_exists(Honeypot::class, false)) {
                require_once __DIR__ . '/ControlFlow/Honeypot.php';
            }
            $current = $this->injectTicks($current, 'honeypot');
            $current = $this->shell($current, '蜜罐函数', fn ($c) => (new Honeypot($this->logger, $this->salt))->inject($c, $hp), 'honeypot');
        }

        // 换行缠绕壳：配额已在前面的每一层壳前按 1/N 分摊注入（poisonTick），
        // 此处补上"换行层自己那一份"，并把它压在毒化壳之前——
        // 使换行随内层代码一同被加密进密文（配合毒化壳：删空白 → 密文/指纹错位）。
        if ($this->config->get('newline_poison')) {
            $current = $this->injectTicks($current, 'newline_poison');
        }

        // 结构指纹毒化壳（真最外层）：指纹对象 = 除自身载荷外的完整最终文件，
        // 文件被美化/篡改 → 密钥错位解出乱码（校验结果进数据流，无分支可绕）。
        if ($this->config->get('struct_poison')) {
            $current = $this->injectTicks($current, 'structpoison');
            if (!class_exists(StructPoison::class, false)) {
                require_once __DIR__ . '/Encode/StructPoison.php';
            }
            $current = $this->shell($current, '结构指纹毒化壳', fn ($c) => (new StructPoison($this->logger, $this->salt))->wrap($c, ['shield' => true, 'intensity' => (int) $this->config->get('shell_shield_intensity')]), 'structpoison');
        }

        // 自校验防篡改置于最外层：掩码自校验 + 分段哈希 + shutdown 二次校验（逻辑全部编码）
        if ($this->config->get('anti_tamper')) {
            $atOpts = [
                'segments' => (int) $this->config->get('at_segments'),
                'shutdown' => (bool) $this->config->get('at_shutdown'),
            ];
            $current = $this->shell($current, '自校验防篡改', fn ($c) => (new AntiTamper($this->salt, $this->logger))->wrap($c, $atOpts, $this->shieldOpts())['code'], 'anti_tamper');
        }

        // 授权绑定守卫（域名 + 有效期）：置于最外层（anti_tamper 之外），未配置时完全不注入
        $dom = (string) $this->config->get('license_domain');
        $exp = (string) $this->config->get('license_expire');
        if ($dom !== '' || $exp !== '') {
            $current = $this->shell($current, '授权绑定守卫', fn ($c) => (new LicenseGuard($this->salt, $this->logger))->wrap($c, $dom, $exp)['code'], 'license');
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
        $rawLayers = $this->config->getLayers();
        // --newline-poison 在分层/预设模式下按需注入换行缠绕层：
        // 插在 shell:structpoison 之前（有则紧邻，无则追加）。orderShellLayers 会把它归入
        // preTail，与 honeypot/structpoison 同级——在无 structpoison 的等级（mid/high）里它
        // 落在所有编码壳之后，换行在最终文件中【可见】；在 max/ultra（含 structpoison）里
        // 换行随内层代码被加密进密文（配合毒化壳）。
        if ($this->config->get('newline_poison')) {
            $hasNp = false;
            foreach ($rawLayers as $l) {
                if (($l['name'] ?? '') === 'shell:newline_poison') {
                    $hasNp = true;
                    break;
                }
            }
            if (!$hasNp) {
                $npLayer = [
                    'name'   => 'shell:newline_poison',
                    'params' => [
                        'divisor'      => (int) $this->config->get('newline_poison_divisor'),
                        'min'          => (int) $this->config->get('newline_poison_min'),
                        'string_ratio' => 0.4,
                    ],
                    'inject' => [],
                ];
                $at = null;
                foreach ($rawLayers as $i => $l) {
                    if (($l['name'] ?? '') === 'shell:structpoison') {
                        $at = $i;
                        break;
                    }
                }
                if ($at === null) {
                    $rawLayers[] = $npLayer;
                } else {
                    array_splice($rawLayers, $at, 0, [$npLayer]);
                }
            }
        }
        $layers = $this->orderShellLayers($rawLayers);
        // 换行缠绕配额：输入源码字节数 / divisor，按"每一层壳"平均分摊（见 poisonTick）
        $this->mcActive = true;
        $this->nlInit(
            $this->config->get('newline_poison') ? strlen($code) : 0,
            $this->nlPlanLayered($layers)
        );
        $current = rtrim($code);
        $tokens = null; // 连续 token 层累积于此，遇 shell 层再 emit

        // 文件头 banner 必须位于【毒化壳之内】：毒化壳的指纹对象是除载荷外的完整最终
        // 文件，若 banner 在壳之后追加，构建期骨架与运行期 __FILE__ 永久不等 → 全量毒化。
        $needBanner = !$this->config->get('no_banner');
        $hasPoison = false;
        foreach ($layers as $l) {
            if (($l['name'] ?? '') === 'shell:structpoison') {
                $hasPoison = true;
                break;
            }
        }
        $bannerDone = false;

        foreach ($layers as $layer) {
            if ($needBanner && !$bannerDone && $hasPoison && ($layer['name'] ?? '') === 'shell:structpoison') {
                if ($tokens !== null) {
                    $current = $this->emit($tokens);
                    $tokens = null;
                }
                $current = (new Copyright($this->logger))->inject($current, $this->config, $this->toolName, $this->toolVersion);
                // Confrontation-LLM：反 LLM 注入（须位于毒化/自校验壳之内，纳入指纹）
                if ($this->config->get('confrontation_llm')) {
                    if (!class_exists(LlmConfrontation::class, false)) {
                        require_once __DIR__ . '/Confrontation/LlmConfrontation.php';
                    }
                    $current = (new LlmConfrontation($this->salt))->inject($current, (int) $this->config->get('confrontation_intensity'));
                }
                $bannerDone = true;
            }
            $current = $this->applyLayer($layer, $tokens, $current);
        }
        if ($tokens !== null) {
            $current = $this->emit($tokens);
        }
        // 文件头引擎信息（除非关闭）—— 与旧管线一致
        if ($needBanner && !$bannerDone) {
            $current = (new Copyright($this->logger))->inject($current, $this->config, $this->toolName, $this->toolVersion);
            if ($this->config->get('confrontation_llm')) {
                if (!class_exists(LlmConfrontation::class, false)) {
                    require_once __DIR__ . '/Confrontation/LlmConfrontation.php';
                }
                $current = (new LlmConfrontation($this->salt))->inject($current, (int) $this->config->get('confrontation_intensity'));
            }
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
        // 次外层：蜜罐钓鱼区 + 结构毒化壳。二者必须在 anti_tamper【之内】：
        //  - anti_tamper 的守卫行以明文锚点自定位，必须位于文件最外层；
        //  - 毒化壳只对自己标记区段做指纹（见 StructPoison），anti_tamper 前置的
        //    守卫行 / 尾部终结符落在标记之外，因此两层可叠加互不破坏。
        $preTail = ['shell:honeypot' => true, 'shell:structpoison' => true];
        // 最外层（最后应用）：anti_tamper/license 明文锚点须在文件里可见
        $outermost = ['shell:anti_tamper' => true, 'shell:license' => true];
        $head = [];
        $keep = [];
        $pre = [];
        $tail = [];
        foreach ($layers as $layer) {
            $name = $layer['name'] ?? '';
            if (isset($innermost[$name])) {
                $head[] = $layer;
            } elseif (isset($outermost[$name])) {
                $tail[] = $layer;
            } elseif (isset($preTail[$name])) {
                $pre[] = $layer;
            } else {
                $keep[] = $layer;
            }
        }
        return array_merge($head, $keep, $pre, $tail);
    }

    // ============================================================
    //  换行缠绕配额分摊（NewlinePoison）
    // ============================================================
    //
    //  规则（1.0.9）：换行总数 = ceil(输入源码字节数 / newline_poison_divisor)，默认 1/5。
    //  这些换行**全部插进代码内部**，并按"每一层壳"平均分摊：每层壳包裹前先注入自己那一份，
    //  于是换行散落在 vm/eval/gz/毒化/自校验…各层之间，而不是集中成一段可被整体删除的死区。
    //  越内层的换行会被后续壳一并加密进密文，删掉即改动密文/指纹 → 触发毒化。
    //  未能落地的余量（候选边界不足等）兜底到**文件头部**（紧跟 <?php 之后）：
    //  头部是美化/清空行时最先被删的位置，比尾部死区更容易被误删。

    /** @var array<int,string> 尚未消费的分摊点（按管线顺序） */
    private array $nlPoints = [];
    private int $nlBase = 0;
    private int $nlTick = 0;
    private int $nlRem = 0;
    private int $nlCarry = 0;
    private bool $nlActive = false;

    /**
     * 计算本次运行的分摊计划。
     *
     * @param int                 $bytes  输入源码字节数（0 = 不启用）
     * @param array<int,string>   $points 分摊点（按管线顺序，须全部位于指纹/自校验壳之前）
     */
    private function nlInit(int $bytes, array $points): void
    {
        $this->nlPoints = array_values($points);
        $this->nlBase = 0;
        $this->nlTick = 0;
        $this->nlRem = 0;
        $this->nlCarry = 0;
        $this->nlActive = false;
        if ($bytes <= 0 || $this->nlPoints === []) {
            return;
        }
        if (!class_exists(NewlinePoison::class, false)) {
            require_once __DIR__ . '/Encode/NewlinePoison.php';
        }
        $total = NewlinePoison::targetFor($bytes, [
            'divisor' => (int) $this->config->get('newline_poison_divisor'),
            'min'     => (int) $this->config->get('newline_poison_min'),
        ]);
        if ($total <= 0) {
            return;
        }
        $cnt = count($this->nlPoints);
        $this->nlBase = intdiv($total, $cnt);
        $this->nlRem = $total % $cnt;
        $this->nlActive = true;
        $this->logger->debug(sprintf(
            '换行缠绕配额：输入 %d 字节 → 共 %d 个换行，分摊到 %d 层壳（每层 %d%s）',
            $bytes,
            $total,
            $cnt,
            $this->nlBase,
            $this->nlRem > 0 ? " + 前 {$this->nlRem} 层各多 1" : ''
        ));
    }

    /**
     * 散装（非分层）管线的分摊点，顺序须与下面的包裹顺序一致。
     * @return array<int,string>
     */
    private function nlPlanSingle(): array
    {
        $pts = ['phpver'];
        if ($this->config->get('anti_debug')) {
            $pts[] = 'anti_debug';
        }
        if ($this->config->get('anti_hook')) {
            $pts[] = 'anti_hook';
        }
        if ($this->config->get('vm')) {
            $pts[] = 'vm';
        }
        if ($this->config->get('eval')) {
            $pts[] = 'eval';
        }
        if ($this->config->get('gz')) {
            $pts[] = 'gz';
        }
        if ((int) $this->config->get('junk_funcs') > 0) {
            $pts[] = 'junk';
        }
        if ((int) $this->config->get('honeypot') > 0) {
            $pts[] = 'honeypot';
        }
        if ($this->config->get('newline_poison')) {
            $pts[] = 'newline_poison';
        }
        if ($this->config->get('struct_poison')) {
            $pts[] = 'structpoison';
        }
        return $pts;
    }

    /**
     * 分层管线的分摊点：所有 shell:* 层，但排除 anti_tamper / license
     * （它们位于最外层且自带区段哈希，其后再插换行会让运行期哈希失配）。
     *
     * @param array<int,array{name:string,params:array,inject:array}> $layers 已排序的层
     * @return array<int,string>
     */
    private function nlPlanLayered(array $layers): array
    {
        $pts = [];
        foreach ($layers as $l) {
            $name = (string) ($l['name'] ?? '');
            if (LayerRegistry::kind($name) !== 'shell') {
                continue;
            }
            $key = substr($name, 6);
            if ($key === 'anti_tamper' || $key === 'license') {
                continue;
            }
            $pts[] = $key;
        }
        return $pts;
    }

    /**
     * 在某一层壳包裹前注入"本层分内的换行"。
     * 返回值：注入了换行的代码；未命中分摊点 / 注入失败则原样返回（配额转入后续层）。
     */
    private function poisonTick(string $code, string $key): string
    {
        // 封死壳：结构指纹毒化 / 自校验防篡改 / 授权绑定会把"当时的文件字节"写进指纹或
        // 区段哈希，其后哪怕只多一个换行，运行期校验也会失配（解出乱码 / 误报被篡改）。
        // 故在它们之前把未落地的配额一次性兜到文件头部，然后彻底停止注入。
        $sealing = ($key === 'structpoison' || $key === 'anti_tamper' || $key === 'license');
        if ($sealing) {
            $code = $this->poisonFlushHead($code, $key);
        }
        if (!$this->nlActive) {
            return $code;
        }
        $idx = array_search($key, $this->nlPoints, true);
        if ($idx === false) {
            if ($sealing) {
                $this->nlActive = false;
            }
            return $code; // 不是分摊点（或已消费过）
        }
        // 该点消费掉：即便本层注入失败也不再重复（避免同一层被注入两份）
        array_splice($this->nlPoints, $idx, 1);
        $isLast = $this->nlPoints === [];   // 最后一个点：余量兜到头部

        $share = $this->nlBase + ($this->nlTick < $this->nlRem ? 1 : 0);
        $this->nlTick++;
        $carry = $this->nlCarry;
        $this->nlCarry = 0;
        $head = 0;
        if ($isLast) {
            $head = $carry;           // 兜底：头部块
        } else {
            $share += $carry;         // 其余点：把上一层的余量就地吸收
        }
        if ($share <= 0 && $head <= 0) {
            if ($sealing) {
                $this->nlActive = false;
            }
            return $code;
        }

        if (!class_exists(NewlinePoison::class, false)) {
            require_once __DIR__ . '/Encode/NewlinePoison.php';
        }
        $np = new NewlinePoison($this->logger, $this->salt);
        $out = $np->wrap($code, [
            'count'        => max(0, $share),
            'head'         => max(0, $head),
            'string_ratio' => 0.4,
            'seed_tag'     => $key,
        ]);
        if ($np->lastCount <= 0 || $out === $code) {
            $this->nlCarry += $share + $head;
            if ($sealing) {
                $this->nlActive = false;
            }
            return $code;
        }
        $check = (new Validator($this->logger))->checkSyntaxString($out);
        if (!$check['ok']) {
            $this->logger->debug("[换行缠绕:{$key}] 产物未通过 php -l，本层注入回滚（配额转后续层）");
            $this->nlCarry += $share + $head;
            if ($sealing) {
                $this->nlActive = false;
            }
            return $code;
        }
        $this->nlCarry += max(0, $share + $head - $np->lastCount);
        if ($sealing) {
            $this->nlActive = false; // 已进指纹/哈希区，之后一个字节都不能再动
        }
        $this->logger->debug(sprintf('换行缠绕[%s]：本层注入 %d 个', $key, $np->lastCount));
        return $out;
    }

    /**
     * 任意 shell 层包裹前的统一注入点：先投互斥注释，再注换行缠绕。
     * 两者都必须发生在【指纹/自校验壳之前】——那之后任何字节改动都会让运行期校验失配。
     */
    private function injectTicks(string $code, string $key): string
    {
        $code = $this->mutualTick($code, $key);
        return $this->poisonTick($code, $key);
    }

    /** 互斥注释的投放开关（结构毒化壳之后必须关闭，否则指纹失配） */
    private bool $mcActive = true;

    /**
     * 互斥注释（Confrontation 家族）：把"互相打脸"的成对注释投进【壳内部】。
     * 越往后投放的注释会被更外层的壳再加密一层，明文文件里看不到、解开才读到。
     */
    private function mutualTick(string $code, string $key): string
    {
        if (!$this->mcActive || !$this->config->get('mutual_comments')) {
            return $code;
        }
        $sealing = ($key === 'structpoison' || $key === 'anti_tamper' || $key === 'license');
        $intensity = max(1, min(3, (int) $this->config->get('mutual_intensity')));
        $pairs = [1 => 1, 2 => 2, 3 => 4][$intensity];
        if (!class_exists(MutualComments::class, false)) {
            require_once __DIR__ . '/Confrontation/MutualComments.php';
        }
        try {
            $out = (new MutualComments($this->logger, $this->salt))->inject($code, $pairs, $key);
        } catch (Throwable $e) {
            $this->logger->debug("[互斥注释:{$key}] 注入失败，跳过：" . $e->getMessage());
            $out = $code;
        }
        if ($sealing) {
            $this->mcActive = false; // 已进指纹区，此后一个字节都不能再动
        }
        return $out;
    }

    /**
     * 兜底：把所有未落地的换行配额一次性补到文件头部（紧跟 <?php 之后）。
     * 必须在指纹/自校验壳之前调用，否则会破坏其区段哈希。
     *
     * @param string $tag 日志标记（说明是在哪个壳之前兜底的）
     */
    private function poisonFlushHead(string $code, string $tag = ''): string
    {
        if (!$this->nlActive || $this->nlCarry <= 0) {
            return $code;
        }
        $n = $this->nlCarry;
        $this->nlCarry = 0;
        if (!class_exists(NewlinePoison::class, false)) {
            require_once __DIR__ . '/Encode/NewlinePoison.php';
        }
        $np = new NewlinePoison($this->logger, $this->salt);
        $out = $np->wrap($code, ['count' => 0, 'head' => $n, 'seed_tag' => 'head:' . $tag]);
        if ($np->lastCount <= 0) {
            return $code;
        }
        $check = (new Validator($this->logger))->checkSyntaxString($out);
        if (!$check['ok']) {
            return $code;
        }
        $this->logger->debug(sprintf('换行缠绕兜底：头部补 %d 个（%s 之前）', $np->lastCount, $tag !== '' ? $tag : '末层'));
        return $out;
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
                $key = substr($name, 6);
                // 换行缠绕：每层壳包裹前注入本层分内的一份（散落各层，而非集中于尾部）
                $code = $this->injectTicks($code, $key);
                if ($name === 'shell:newline_poison' && $this->nlActive) {
                    // 本层配额已由 poisonTick 注入，避免重复注入
                } else {
                    $code = $this->applyShellLayer($name, $params, $code);
                }
                // 序言归一：前序壳层若把 declare(strict_types=1)/namespace 推离首位（守卫代码
                // 插到声明之前），在此把序言语句提到 <?php 之后最前，避免
                // "strict_types declaration must be the very first statement"。
                $code = DeclareHoist::reconcile($code);
                // 逐层壳自保护：每层壳解码逻辑再被外层混淆护住（破解需先破外层混淆）
                $code = $this->shieldIfNeeded($code, $name);
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
            case 'anti_ast':
                $tokens = (new AntiAst($this->salt, $this->logger))->process($tokens, $cfg);
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
                // 类名混淆的【唯一总开关】是全局 class_obf：
                //  - 工程模式（ProjectBuilder）强制 class_obf=false → 即便预设含 class 层
                //    on:true 也不改名（改名会破坏 PSR-4「命名空间+文件名」约定，框架按原名
                //    找不到类）；
                //  - 单文件模式：--class-obf 显式开启，或预设含 class 层 on:true 时由
                //    Config::fromPreset / fromLayeredJson 提升为全局 true（见对应注释）。
                // 两者以全局 class_obf 为准，避免"预设层 on 覆盖工程模式强制关闭"的回归。
                $map['class_obf'] = (bool) ($params['on'] ?? false) && (bool) $this->config->get('class_obf');
                break;
            case 'controlflow':
                $map['cfg'] = $params['cfg'] ?? 'medium';
                $map['goto'] = (int) ($params['goto'] ?? 0);
                $map['goto_cpx'] = (int) ($params['goto_cpx'] ?? 0);
                $map['goto_trap'] = (int) ($params['trap'] ?? 2);
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
            case 'anti_ast':
                $map['anti_ast'] = true;
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
                ], $this->shieldOpts())['code'];
            case 'license':
                $dom = (string) ($params['domain'] ?? '');
                $exp = (string) ($params['expire'] ?? '');
                if ($dom === '' && $exp === '') {
                    return $code;
                }
                return (new LicenseGuard($this->salt, $this->logger))->wrap($code, $dom, $exp)['code'];
            case 'newline_poison':
                if (!class_exists(NewlinePoison::class, false)) {
                    require_once __DIR__ . '/Encode/NewlinePoison.php';
                }
                // 独立使用（未走 Obfuscator 的每层分摊）时，按本层代码字节数算配额
                return (new NewlinePoison($this->logger, $this->salt))->wrap($code, [
                    'bytes'        => strlen($code),
                    'divisor'      => (int) ($params['divisor'] ?? $this->config->get('newline_poison_divisor')),
                    'min'          => (int) ($params['min'] ?? 0),
                    'string_ratio' => (float) ($params['string_ratio'] ?? 0.4),
                    'seed_tag'     => 'layer:newline_poison',
                ]);
            case 'structpoison':
                if (!class_exists(StructPoison::class, false)) {
                    require_once __DIR__ . '/Encode/StructPoison.php';
                }
            return (new StructPoison($this->logger, $this->salt))->wrap($code, [
                'rounds'    => (int) ($params['rounds'] ?? 3),
                'shield'    => (bool) $this->config->get('shell_shield'),
                'intensity' => (int) $this->config->get('shell_shield_intensity'),
            ]);
            case 'honeypot':
                $hpCount = (int) ($params['count'] ?? 0);
                if ($hpCount <= 0) {
                    return $code;
                }
                if (!class_exists(Honeypot::class, false)) {
                    require_once __DIR__ . '/ControlFlow/Honeypot.php';
                }
                return (new Honeypot($this->logger, $this->salt))->inject($code, $hpCount);
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
        $layers = max(1, (int) ($params['layers'] ?? 1));
        $current = $code;
        for ($i = 0; $i < $layers; $i++) {
            $next = $this->shellVmOnce($params, $current);
            // 本层未改变内容（编译失败回退到原样），再套无意义，提前终止。
            if ($i > 0 && $next === $current) {
                break;
            }
            $current = $next;
        }
        return $current;
    }

    /**
     * 单层 VM 壳：剥 <?php/?> 后编译为 VM 产物。
     * 嵌套 VM 由 shellVm() 循环回灌本方法实现（每层产物本身以 <?php 开头，可再被剥标签）。
     */
    private function shellVmOnce(array $params, string $code): string
    {
        // 顶层 return（配置 / provider / route / service 等"return 数组"的文件）：VM 壳把整份
        // 代码包进 (function(){ ... })(); ，顶层 return 只会从该 IIFE 返回、被丢弃，include 该项目
        // 文件于是返回 1 而非数组（框架 App::bind(include provider.php) 收到 int → 致命）。这类
        // 文件不走 VM 壳，保留原样（仍经过 token 级混淆），确保顶层 return 语义正确。
        if (TokenScan::hasTopLevelReturn($code)) {
            return $code;
        }
        // 类保留 VM：含类声明的文件走"真实类桩 + 方法体 VM"，保持框架（ThinkPHP 等）
        // 的 class_exists / new $class / 反射 / 依赖注入兼容，同时方法体仍被 VM 保护。
        if (!class_exists(ClassPreservingVm::class, false)) {
            require_once __DIR__ . '/Vm/ClassPreservingVm.php';
        }
        // 已经是"上一层 VM/类保留壳的产物"时，绝不能再把它当成普通源码重新编译：那会把
        // 运行期解释器本身编译成字节码（VM 类语义、$GLOBALS 桥、by-ref 形参数组全都没有
        // 等价表达），实测会静默挂死。嵌套层的正确做法是【不透明载荷嵌套】——整段产物作为
        // 密文载荷，由外层 VM 解码后 _PHPRUN 执行，语义 100% 原生。
        if (str_contains($code, self::VM_ARTIFACT_MARK)
            || str_contains($code, '__awa_vmmethod_') || str_contains($code, '__awa_realcls_')
            || str_contains($code, '__awa_run_') || str_contains($code, '__awa_prog_')) {
            $nested = $this->vmPayloadNest($code, $this->vmOpts($params));
            if ($nested !== null) {
                $this->logger->info('shell:vm 嵌套层：上一层产物作为不透明载荷编入 VM');
                return $nested;
            }
        }
        if (ClassPreservingVm::hasClasses($code)) {
            $dumpCpv = getenv('OBF_VM_SRC');
            if (is_string($dumpCpv) && $dumpCpv !== '') {
                @mkdir($dumpCpv, 0777, true);
                @file_put_contents(rtrim($dumpCpv, '/') . '/' . date('His') . '_cpv_' . substr(md5($code), 0, 6) . '.php', $code);
            }
            $cp = ClassPreservingVm::wrap($code, $this->vmOpts($params), $this->salt);
            if ($cp !== null && trim($cp) !== '' && $cp !== $code) {
                $dbgOut = getenv('OBF_CPV_OUT');
                if (is_string($dbgOut) && $dbgOut !== '') {
                    @mkdir($dbgOut, 0777, true);
                    @file_put_contents(rtrim($dbgOut, '/') . '/' . date('His') . '_cpvo_' . substr(md5($code), 0, 6) . '.php', $cp);
                }
                return $cp;
            }
        }
        $src = preg_replace('/^\s*<\?php/i', '', $code, 1);
        $src = preg_replace('/\?>\s*$/', '', $src);
        if (trim($src) === '') {
            return $code;
        }
        $opts = $this->vmOpts($params);
        $dumpSrc = getenv('OBF_VM_SRC');
        if (is_string($dumpSrc) && $dumpSrc !== '') {
            @mkdir($dumpSrc, 0777, true);
            @file_put_contents(rtrim($dumpSrc, '/') . '/' . date('His') . '_' . substr(md5($src), 0, 6) . '.php', $src);
            @file_put_contents(rtrim($dumpSrc, '/') . '/' . date('His') . '_' . substr(md5($src), 0, 6) . '.opts.json', json_encode($opts, JSON_UNESCAPED_SLASHES));
        }
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
            return $this->markArtifact($art['php']);
        } catch (Throwable $e) {
            // 直接编译失败（典型：带 `namespace X { ... }` 块 / 真实类声明的产物，
            // 这类结构无法等价表达成 VM 字节码）。绝不能"原样返回"——那等于这一层
            // 完全没有保护。改为【不透明载荷嵌套】：把整份源码 base64+gz 后编进 VM，
            // 程序只做 decode + _PHPRUN 执行，语义 100% 原生（namespace/类声明在
            // eval 单元里依旧合法），而字节码仍只有 VM 能跑。
            $nested = $this->vmPayloadNest($code, $opts);
            if ($nested !== null) {
                $this->logger->info('shell:vm 改为不透明载荷嵌套（源码不直接编译）：' . $e->getMessage());
                return $nested;
            }
            $this->logger->warn('shell:vm 编译失败，回退到未 VM 化代码: ' . $e->getMessage());
            return $code;
        }
    }

    /**
     * 把整份源码作为【不透明载荷】编进 VM：外层字节码只负责 gzinflate+base64 解码与
     * `_PHPRUN` 执行。适用于无法直接编译为 VM 字节码的源码（带 bracketed 命名空间块、
     * 或在 namespace 块里声明真实类的"类保留 VM"产物）——这些结构在 VM 的分类/方法
     * 语义里没有等价表达，但作为字符串载荷执行时与原生完全一致。
     *
     * @param array<string,mixed> $opts 与普通 VM 相同的构建选项（分片/常量池/垃圾指令等照常生效）
     */
    private function vmPayloadNest(string $code, array $opts): ?string
    {
        $src = preg_replace('/^\s*<\?php/i', '', $code, 1);
        $src = preg_replace('/\?>\s*$/', '', (string) $src);
        if (trim((string) $src) === '') {
            return null;
        }
        $dumpNest = getenv('OBF_VM_NEST');
        if (is_string($dumpNest) && $dumpNest !== '') {
            @mkdir($dumpNest, 0777, true);
            @file_put_contents(rtrim($dumpNest, '/') . '/' . date('His') . '_' . substr(md5((string) $src), 0, 6) . '.php', (string) $src);
        }
        $payload = base64_encode((string) gzdeflate((string) $src, 9));
        // 载荷切成若干段再在运行期拼接：单条超长字面量既不利常量池分片，也容易在
        // 编辑器/审计工具里被截断；分段后每段都只是池里的一条密文。
        $pieces = str_split($payload, 8192);
        $expr = '';
        foreach ($pieces as $p) {
            $expr .= ($expr === '' ? '' : '.') . "base64_decode('" . $p . "')";
        }
        $loader = "<?php\n_PHPRUN(gzinflate(" . $expr . "));";
        $loaderSrc = preg_replace('/^\s*<\?php/i', '', $loader, 1);
        // 这段 loader 是【混淆器自己生成的解码管线】，不是用户源码：
        //   - 模块链接（ModuleLinker）只服务于"隐藏用户代码里的内置函数调用"，
        //     对 loader 里的 base64_decode/gzinflate 毫无隐藏收益；
        //   - 代价却极大：内置模块是 PHP 版重实现（base64 逐字符解码），把几万字
        //     节的密文交给它解，运行期从毫秒级劣化到数十分钟（O(n²) 量级），
        //     表现出来就是"嵌套层产物跑不动"。
        // 故 loader 一律不挂模块目录；载荷本身（上一层产物）已在它自己那层完成过
        // 模块内联，这里不损失任何混淆强度。
        $loaderOpts = $opts;
        $loaderOpts['moduleDir'] = '';
        try {
            $art = (new VmRuntimeGenerator($this->salt))->buildArtifact((string) $loaderSrc, $loaderOpts);
        } catch (Throwable $e) {
            return null;
        }
        return $this->markArtifact($art['php']);
    }

    /** 给 VM 产物打上构建标记（供嵌套层识别"这已经是产物"，见 VM_ARTIFACT_MARK）。 */
    private function markArtifact(string $php): string
    {
        if (str_contains($php, self::VM_ARTIFACT_MARK)) {
            return $php;
        }
        return rtrim($php) . "\n" . self::VM_ARTIFACT_MARK . "\n";
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

    /** 组装 VM 壳的编译选项（类保留 VM 与普通 VM 共用）。 */
    private function vmOpts(array $params): array
    {
        return [
            'flatten'      => (bool) ($params['flatten'] ?? false),
            'stateMachine' => (bool) ($params['stateMachine'] ?? false),
            'chunks'      => (int) ($params['chunks'] ?? 3),
            'baseDir'     => $this->srcFile !== '' ? dirname($this->srcFile) : '',
            'curFile'     => $this->srcFile,
            'moduleDir'   => $this->resolveModuleDir($this->effectiveModuleDir(
                (string) ($params['modules'] ?? $params['moduleDir'] ?? '')
            )),
            'junk'         => (int) ($params['junk'] ?? 0),
            'keychain'       => (bool) ($params['keychain'] ?? false),
            'builtinReplace' => (bool) ($params['builtinReplace'] ?? false),
            'morph'          => (bool) ($params['morph'] ?? false),
            'fakeFog'        => (int) ($params['fakeFog'] ?? 0),
            'probe'          => (int) ($params['probe'] ?? 0),
            'cpool'          => (bool) ($params['cpool'] ?? true),
            'combo'          => max(0, (int) ($params['combo'] ?? (int) $this->config->get('vm_combo'))),
        ];
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
    private function shell(string $code, string $label, callable $fn, string $layerKey = ''): string
    {
        try {
            $next = $fn($code);
        } catch (Throwable $e) {
            $this->logger->warn("{$label}应用失败，回滚：" . $e->getMessage());
            return $code;
        }
        // 序言归一：壳层 wrap 可能把 declare/namespace 推离首位，先修复再校验（完整保留壳，不回退）
        $next = DeclareHoist::reconcile($next);
        $check = (new Validator($this->logger))->checkSyntaxString($next);
        if (!$check['ok']) {
            $this->logger->warn("{$label}产物未通过 php -l，回滚");
            return $code;
        }
        // 逐层壳自保护：对刚包裹好的壳解码逻辑再施加混淆
        $next = $this->shieldIfNeeded($next, $layerKey);
        $this->logger->debug("已应用{$label}");
        return $next;
    }

    /**
     * 逐层壳自保护：把刚包裹好的一层壳的解码逻辑再施加一层混淆（ShellProtector），
     * 使攻击者必须先解开【外层壳的混淆】才能读到【内层壳的明文解码逻辑】——
     * 而不是"破一层就直接拿到下一层的源码"。
     *
     * 跳过 / 模式规则（避免破坏自校验）：
     *   - structpoison：壳内自行保护（指纹计算前对自身施保护，见 StructPoison），此处不二次处理；
     *   - anti_tamper：自带区段哈希 + needle 变量名自匹配，禁用"变量重命名"模式，
     *     改用 norename（仅 token 形态 + 语义变换，不动变量名），否则守卫行自定位失配。
     *
     * @param string $layerKey 分层层名（shell:xxx）或散装键（vm/gz/...）
     */
    private function shieldIfNeeded(string $code, string $layerKey): string
    {
        if (!$this->config->get('shell_shield')) {
            return $code;
        }
        // phpver 是"运行时版本守卫"，并非解码壳——它把【整段载荷】裹在最外层，
        // 对其施加 ShellProtector 等于把用户整段源码再混淆一遍（既无意义、又拖慢、且风险高），
        // 故跳过。真正需要"破一层先被恶心一层"的是各解码壳（vm/eval/gz/anti_*/...）。
        if ($layerKey === 'phpver') {
            return $code;
        }
        if ($layerKey === 'shell:structpoison' || $layerKey === 'structpoison') {
            return $code; // 自行保护，跳过
        }
        // newline_poison：壳内仅是换行符，无意义再施保护；且必须保留已注入的换行（否则特征被抹平）
        if ($layerKey === 'shell:newline_poison' || $layerKey === 'newline_poison') {
            return $code;
        }
        // anti_tamper 自带区段哈希（构建期已对【整段主体】求哈希写入守卫），若在封装后再对
        // 整段施加 ShellProtector，主体字节会变 → 运行期区段哈希失配误报篡改。其守卫行已由
        // AntiTamper 内部以 norename 模式自保护，故此处跳过整段保护。
        if ($layerKey === 'shell:anti_tamper' || $layerKey === 'anti_tamper') {
            return $code;
        }
        $mode = ($layerKey === 'shell:anti_tamper' || $layerKey === 'anti_tamper')
            ? 'norename' : 'full';
        $strength = (int) $this->config->get('shell_shield_intensity');
        return ShellProtector::protect($code, $this->salt, $this->logger, $mode, $strength);
    }

    /**
     * 供自保护性壳（StructPoison / AntiTamper）读取"是否对自身施保护 + 强度"的配置，
     * 与 shieldIfNeeded 共享同一组开关，保证行为一致。
     * @return array{shield:bool,intensity:int}
     */
    private function shieldOpts(): array
    {
        return [
            'shield'    => (bool) $this->config->get('shell_shield'),
            'intensity' => (int) $this->config->get('shell_shield_intensity'),
        ];
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

        // 深度反AST：语句位 echo/print/return 的常量字面量改写为"累加器 + 恒等表达式"。
        // 须置于数字/字符串编码之前，才能命中原始裸常量（编码后字面量形态已变，无法识别）。
        if ($this->config->get('anti_ast')) {
            $antiAst = new AntiAst($this->salt, $this->logger);
            $tokens = $antiAst->process($tokens, $this->config);
        }

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
        // 序言归一安全网：任何注入器把语句错插进 declare/namespace 序言区的，
        // 在 token 流出口统一搬回序言之后（否则产物 "namespace must be the very
        // first statement" 直接致命）。
        $tokens = DeclareHoist::normalizeTokens($tokens);
        $out = '';
        $prevChar = '';
        $prevTok = null;
        foreach ($tokens as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if ($text === '') {
                continue;
            }
            if ($prevChar !== '' && $this->needsSpace($prevTok, $prevChar, $text, $tok)) {
                $out .= ' ';
            }
            $out .= $text;
            $prevChar = $text[strlen($text) - 1];
            $prevTok = $tok;
        }
        return $out;
    }

    private function needsSpace($prevTok, string $prevChar, string $text, $tok = null): bool
    {
        $wordy = fn (string $c) => $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80);
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
        // 词素单词后紧跟 `\` 起头的命名空间名（如 `new \stdClass()`）：压缩去空白后
        // 变成 `new\stdClass`，PHP 词法器误判为函数调用而报
        // "Call to undefined function new\stdClass()"，必须补空格。
        // 仅对【名字类 token】生效：字符串插值里的转义字面量（T_ENCAPSED_AND_WHITESPACE
        // 的 "\n" / "\t" / "\x41"）文本同样以反斜杠开头，但补空格会直接改掉字符串内容
        // （"$v\n" → "$v \n"），运行期输出就多了个空格——这是语义破坏，不是压缩需要。
        if ($wordy($prevChar) && $text[0] === '\\' && self::isNameToken($tok)) {
            return true;
        }
        return false;
    }

    /** 是否为"名字类" token（命名空间名 / 类名 / 裸分隔符），用于判断补空格的必要性。 */
    private static function isNameToken($tok): bool
    {
        if (!is_array($tok)) {
            return false; // 裸字符 token（含字符串插值的裸 `"`、`\`）
        }
        $id = $tok[0];
        $types = [T_STRING, T_NS_SEPARATOR];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
            if (defined($c)) {
                $types[] = constant($c);
            }
        }
        return in_array($id, $types, true);
    }
}
