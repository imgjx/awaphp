<?php
/**
 * 配置解析 / 校验
 *
 * 统一归一化：命令行参数、JSON 配置文件、数组，三者最终都收敛为同一个配置数组。
 * 提供默认值（来自 config.default.php）与基础校验。
 */

declare(strict_types=1);

final class Config
{
    /** @var array<string,mixed> */
    private array $data;

    /**
     * @param array<string,mixed> $overrides 来自 CLI / JSON 的覆盖项
     */
    public function __construct(array $overrides = [])
    {
        $defaults = require __DIR__ . '/../config.default.php';
        $this->data = array_merge($defaults, $overrides);
        $this->normalize();
    }

    private function normalize(): void
    {
        $b = fn ($v) => is_bool($v) ? $v : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $i = fn ($v) => is_numeric($v) ? (int) $v : 0;

        $this->data['repeat']        = max(1, $i($this->data['repeat']));
        $this->data['var_length']    = max(4, $i($this->data['var_length']));
        if (!empty($this->data['var_enc_length'])) {
            $this->data['var_length'] = max(4, $i($this->data['var_enc_length']));
        }
        $this->data['func_length']   = max(4, $i($this->data['func_length']));
        $this->data['var_split']     = max(1, $i($this->data['var_split']));
        $this->data['str_length']    = max(0, $i($this->data['str_length']));

        $this->data['func_call']     = $b($this->data['func_call']) ?? false;
        $this->data['func_call_enc'] = $b($this->data['func_call_enc']) ?? false;
        $this->data['class_obf']     = $b($this->data['class_obf']) ?? false;
        $this->data['html']          = $b($this->data['html']) ?? false;
        $this->data['num_enc']       = $b($this->data['num_enc']) ?? false;
        $this->data['gz']            = $b($this->data['gz']) ?? false;
        $this->data['insert_dead']   = $b($this->data['insert_dead']) ?? false;
        $this->data['no_comments']   = $b($this->data['no_comments']) ?? false;
        $this->data['strip_newline'] = $b($this->data['strip_newline']) ?? false;
        $this->data['compress']      = $b($this->data['compress']) ?? false;

        // Token 形态混淆（大小写/字面量/引号/heredoc/诱饵注释）
        // 注意：config.default.php 不含 token_* 键（散装 CLI 模式按需覆盖），
        // 读取一律带 ?? 默认，避免 PHP 8 的 undefined array key 警告。
        $this->data['token_obf']     = $b($this->data['token_obf'] ?? false) ?? false;
        $this->data['token_case']    = $b($this->data['token_case'] ?? true) ?? true;
        $this->data['token_literal'] = $b($this->data['token_literal'] ?? true) ?? true;
        $this->data['token_quote']   = $b($this->data['token_quote'] ?? true) ?? true;
        $this->data['token_heredoc'] = $b($this->data['token_heredoc'] ?? false) ?? false;
        $this->data['token_decoy']   = max(0, min(50, $i($this->data['token_decoy'] ?? 3)));
        // 语义等价变换层
        $this->data['semantic']          = $b($this->data['semantic'] ?? false) ?? false;
        $this->data['semantic_strength'] = max(1, min(3, $i($this->data['semantic_strength'] ?? 2)));
        // 否定旗标折叠：CLI 的 --token-no-case / --token-no-literal / --token-no-quote
        // 被解析为 token_no_* 键，统一折算到对应正开关上（分层模式不经此路径）
        foreach (['case', 'literal', 'quote'] as $flag) {
            if (!empty($this->data['token_no_' . $flag])) {
                $this->data['token_' . $flag] = false;
            }
        }

        // 第三阶段开关
        $this->data['vm']            = $b($this->data['vm']) ?? false;
        // VM 常量池加密（默认开）：PUSH_C 字面量抽入独立加密常量池；--no-vm-cpool 关
        $this->data['vm_cpool']      = $b($this->data['vm_cpool'] ?? true) ?? true;
        $this->data['eval']          = $b($this->data['eval']) ?? false;
        $this->data['anti_debug']    = $b($this->data['anti_debug']) ?? false;
        $this->data['anti_hook']     = $b($this->data['anti_hook']) ?? false;
        $this->data['anti_tamper']   = $b($this->data['anti_tamper']) ?? false;
        $this->data['str_xor']       = $b($this->data['str_xor']) ?? false;
        $this->data['no_banner']     = $b($this->data['no_banner']) ?? false;
        $this->data['goto']          = max(0, $i($this->data['goto']));
        $this->data['goto_cpx']      = max(0, $i($this->data['goto_cpx']));
        // GOTO 诱饵死状态数：每个分派器注入 N 个不可达 case（调试器强跳即自锁），默认 2
        $this->data['goto_trap']     = max(0, min(4, $i($this->data['goto_trap'] ?? 2)));
        $this->data['eval_layers']   = max(1, $i($this->data['eval_layers']));
        $this->data['junk_funcs']    = max(0, $i($this->data['junk_funcs']));
        $this->data['cfg']           = in_array($this->data['cfg'], ['low', 'medium', 'high'], true) ? $this->data['cfg'] : '';
        $this->data['php_version']   = in_array($this->data['php_version'], ['7.4', '8.0', '8.1', '8.2', '8.3'], true)
            ? $this->data['php_version'] : '8.1';
        $this->data['opcache']        = $b($this->data['opcache'] ?? false) ?? false;

        // 分层 / VM 层相关字段
        $this->data['vm_flatten']     = $b($this->data['vm_flatten'] ?? false) ?? false;
        $this->data['vm_state_machine'] = $b($this->data['vm_state_machine'] ?? false) ?? false;
        $this->data['vm_chunks']      = max(1, $i($this->data['vm_chunks'] ?? 3));
        $this->data['vm_linker']      = (string) ($this->data['vm_linker'] ?? '');
        $this->data['vm_link_modules'] = $b($this->data['vm_link_modules'] ?? true) ?? true;
        $this->data['vm_junk']         = max(0, $i($this->data['vm_junk'] ?? 0));
        // P8 架构方向（默认全关，不回退既有行为）；--vm-p8 为总开关（聚合预设，单项可覆盖）
        $p8 = $b($this->data['vm_p8'] ?? false) ?? false;
        $this->data['vm_keychain']      = $b($this->data['vm_keychain'] ?? $p8) ?? false;
        $this->data['vm_builtin_replace'] = $b($this->data['vm_builtin_replace'] ?? $p8) ?? false;
        $this->data['vm_morph']         = $b($this->data['vm_morph'] ?? $p8) ?? false;
        $this->data['vm_fake_fog']      = max(0, min(200, $i($this->data['vm_fake_fog'] ?? ($p8 ? 6 : 0))));
        $this->data['vm_probe']         = max(0, min(50, $i($this->data['vm_probe'] ?? ($p8 ? 4 : 0))));
        // VM 嵌套层数（真·嵌套 VM）：>=2 时把上一层 VM 产物再喂给 VM 编译。默认 1（单层）。
        $this->data['vm_layers']        = max(1, $i($this->data['vm_layers'] ?? 1));
        // VM opcode 组合执行（--vm-combo）：把单 opcode 拆成等价多指令序列，打破 1:1 映射，
        // 抗反编译。0=关；>0 为强度 1~3（越高等价指令序列越密）。CLI 仅给 --vm-combo 时默认 2。
        $combo = $this->data['vm_combo'] ?? 0;
        $combo = ($combo === true) ? 2 : (int) $combo;
        $this->data['vm_combo']         = max(0, min(3, $combo));
        $this->data['junk_runtime']   = $b($this->data['junk_runtime'] ?? false) ?? false;
        $this->data['junk_intensity'] = max(1, min(10, $i($this->data['junk_intensity'] ?? 1)));
        $this->data['junk_fake_b64']  = $b($this->data['junk_fake_b64'] ?? true) ?? true;
        $this->data['junk_fake_echo'] = $b($this->data['junk_fake_echo'] ?? true) ?? true;
        $this->data['junk_tangle']    = $b($this->data['junk_tangle'] ?? false) ?? false;
        $this->data['dead_density']   = max(1, min(10, $i($this->data['dead_density'] ?? 3)));
        $this->data['ad_probes']      = (string) ($this->data['ad_probes'] ?? '');
        $this->data['ad_intensity']   = max(1, min(4, $i($this->data['ad_intensity'] ?? 2)));
        $this->data['ah_strict']      = $b($this->data['ah_strict'] ?? true) ?? true;
        $this->data['at_segments']    = max(2, min(8, $i($this->data['at_segments'] ?? 4)));
        $this->data['at_shutdown']    = $b($this->data['at_shutdown'] ?? true) ?? true;
        $this->data['gz_decoy']       = $b($this->data['gz_decoy'] ?? true) ?? true;
        $this->data['gz_chunks']      = max(1, min(6, $i($this->data['gz_chunks'] ?? 3)));
        // 结构指纹毒化壳：文件指纹参与解密密钥派生（美化/篡改 → 解出乱码，无分支可绕）
        $this->data['struct_poison']  = $b($this->data['struct_poison'] ?? false) ?? false;
        // 蜜罐函数：拟真高价值命名的钓鱼假函数，被调用即拖慢调试并返回诱饵密钥
        $this->data['honeypot']       = max(0, $i($this->data['honeypot'] ?? 0));
        // 逐层壳自保护：每层壳解码逻辑再被外层混淆护住；强度 1-3 控制语义变换激进度
        $this->data['shell_shield']          = $b($this->data['shell_shield'] ?? true) ?? true;
        $this->data['shell_shield_intensity'] = max(1, min(3, $i($this->data['shell_shield_intensity'] ?? 2)));
        // Confrontation-LLM：反 LLM 静态分析（误导性注释 + 生僻 CJK 散布）。默认关。
        $this->data['confrontation_llm']        = $b($this->data['confrontation_llm'] ?? false) ?? false;
        $this->data['confrontation_intensity']  = max(1, min(3, $i($this->data['confrontation_intensity'] ?? 2)));
        $this->data['level']          = (string) ($this->data['level'] ?? '');
        // layers：分层执行模型的核心。存在时 Obfuscator::run() 走分层分支。
        $this->data['layers']         = is_array($this->data['layers'] ?? null) ? $this->data['layers'] : [];

        $this->data['verbose']       = $b($this->data['verbose']) ?? false;
        $this->data['silence']       = $b($this->data['silence']) ?? false;

        $this->data['salt']     = (string) $this->data['salt'];
        $this->data['copyright'] = (string) $this->data['copyright'];
        $this->data['license_domain'] = (string) ($this->data['license_domain'] ?? '');
        $this->data['license_expire'] = (string) ($this->data['license_expire'] ?? '');
        $this->data['format']   = in_array($this->data['format'], ['text', 'json'], true) ? $this->data['format'] : 'text';

        // 依赖关系修正
        if ($this->data['func_call_enc']) {
            $this->data['func_call'] = true;
        }
        if ($this->data['compress']) {
            $this->data['strip_newline'] = true;
            $this->data['no_comments'] = true;
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** 从命令行参数（已 parsed 的关联数组）构造配置 */
    public static function fromCli(array $parsed): self
    {
        return new self($parsed);
    }

    /** 从 JSON 配置文件构造配置 */
    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("配置文件不存在: {$path}");
        }
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException("配置文件内容必须是 JSON 对象: {$path}");
        }
        return new self($json);
    }

    /**
     * 校验必填项与选项依赖关系，返回错误信息数组（空数组 = 通过）。
     *
     * 除必填的 --input 外，重点拦截"配了却不生效/配错了静默降级"的组合：
     *  - --config 指向的文件必须存在（缺失时不能静默退化成散装 CLI 模式）
     *  - --vm 必须配 --salt：空盐时 VM 密钥由公开公式派生，等同无密钥
     *  - VM 层专属选项（--vm-p8 / --vm-keychain / ...）未开 --vm 时不生效
     *  - --vm-linker 指向的模块目录必须存在
     *  - --license-expire 必须可被 strtotime 解析
     */
    public function validate(): array
    {
        $errors = [];
        $input = (string) ($this->data['input'] ?? '');
        if ($input === '') {
            $errors[] = '缺少必填参数 --input';
        } elseif (!file_exists($input)) {
            $errors[] = '输入路径不存在: ' . $input;
        }
        // --config 指向的文件必须存在：缺失时不能静默按散装参数构建
        if (!empty($this->data['config']) && !is_file((string) $this->data['config'])) {
            $errors[] = '配置文件不存在: ' . $this->data['config'];
        }
        // --vm 必须配 --salt：空盐下 VM 密钥 = sha256("|vm-key|…")，可被离线推导
        if (!empty($this->data['vm']) && trim((string) $this->data['salt']) === '') {
            $errors[] = '启用 --vm 时必须同时指定 --salt（空盐时 VM 密钥按公开公式派生，保护强度等同于无密钥）';
        }
        // VM 层专属选项未开 --vm 时静默不生效，容易误判为已加固
        if (empty($this->data['vm'])) {
            $stray = [];
            foreach (['vm_p8', 'vm_keychain', 'vm_builtin_replace', 'vm_morph', 'vm_flatten', 'vm_state_machine'] as $k) {
                if (!empty($this->data[$k])) {
                    $stray[] = '--' . str_replace('_', '-', $k);
                }
            }
            foreach (['vm_junk', 'vm_fake_fog', 'vm_probe'] as $k) {
                if ((int) ($this->data[$k] ?? 0) > 0) {
                    $stray[] = '--' . str_replace('_', '-', $k);
                }
            }
            if ((string) ($this->data['vm_linker'] ?? '') !== '') {
                $stray[] = '--vm-linker';
            }
            if ($stray !== []) {
                $errors[] = '以下选项依赖 --vm，未开启 --vm 时不会生效: ' . implode('、', $stray);
            }
        }
        // --vm-linker 指向的模块目录必须存在
        if (!empty($this->data['vm_linker']) && !is_dir((string) $this->data['vm_linker'])) {
            $errors[] = '--vm-linker 目录不存在: ' . $this->data['vm_linker'];
        }
        // --license-expire 必须可解析（解析失败时授权守卫会静默跳过有效期校验）
        if (!empty($this->data['license_expire'])
            && strtotime((string) $this->data['license_expire']) === false) {
            $errors[] = '--license-expire 时间无法解析: ' . $this->data['license_expire'];
        }
        return $errors;
    }

    /**
     * 从等级预设构造配置。预设是一份"完整分层定义"（见 src/config.presets.php），
     * 加载后把 $overrides（CLI 覆盖项）合并到 data；预设的 layers 原样落进 data['layers']。
     */
    public static function fromPreset(string $name, array $overrides = []): self
    {
        $presets = require __DIR__ . '/config.presets.php';
        if (!isset($presets[$name])) {
            throw new RuntimeException("未知等级预设: {$name}（可用: " . implode(', ', array_keys($presets)) . '）');
        }
        $preset = $presets[$name];
        $layers = $preset['layers'] ?? [];
        // 把 preset 里的标量元数据（如有）并入 defaults，再叠加 CLI 覆盖
        $meta = array_diff_key($preset, ['layers' => true]);
        $data = array_merge($meta, $overrides);
        $data['layers'] = $layers;
        $data['level'] = $name;
        return new self($data);
    }

    /**
     * 从自定义分层 JSON 构造配置。JSON 顶层需含 "layers" 数组（每项 {name,params,inject}），
     * 可选 "salt" / 其它标量覆盖项。校验每层 name 在注册表中，否则抛错；
     * 每层 params 经 LayerDescriptor::validate() 检查（未知参数/越界值记入
     * layer_warnings，构建时由 Logger 输出，不阻断）。
     */
    public static function fromLayeredJson(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("分层配置文件不存在: {$path}");
        }
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json) || !isset($json['layers']) || !is_array($json['layers'])) {
            throw new RuntimeException("分层配置文件必须包含顶层 layers 数组: {$path}");
        }
        $known = LayerRegistry::names();
        $warnings = [];
        foreach ($json['layers'] as $i => $layer) {
            $ln = $layer['name'] ?? null;
            if (!is_string($ln) || !isset($known[$ln])) {
                throw new RuntimeException("分层配置第 {$i} 层 name 无效或未知: " . var_export($ln, true));
            }
            $params = is_array($layer['params'] ?? null) ? $layer['params'] : [];
            foreach (LayerDescriptor::validate($ln, $params) as $w) {
                $warnings[] = $w;
            }
        }
        $data = array_diff_key($json, ['layers' => true]);
        $data['layers'] = $json['layers'];
        $data['layer_warnings'] = $warnings;
        return new self($data);
    }

    /** @return array<int,array{name:string,params:array,inject:array}> */
    public function getLayers(): array
    {
        $out = [];
        foreach ($this->data['layers'] ?? [] as $layer) {
            $out[] = [
                'name'   => (string) ($layer['name'] ?? ''),
                'params' => is_array($layer['params'] ?? null) ? $layer['params'] : [],
                'inject' => is_array($layer['inject'] ?? null) ? $layer['inject'] : [],
            ];
        }
        return $out;
    }

    public function hasLayers(): bool
    {
        return count($this->data['layers'] ?? []) > 0;
    }
}
