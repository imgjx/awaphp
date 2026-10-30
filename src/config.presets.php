<?php
/**
 * 等级预设（Level Presets）
 *
 * 四档等级 = 四份"完整分层定义"。读取方式：Config::fromPreset($name) 加载后，
 * 再与 CLI 覆盖项合并。每层结构：
 *   { "name": "<层名>", "params": { ... }, "inject": { ... } }
 *
 * 层名路由见 src/Layer/LayerRegistry.php；每层可自定义参数见
 * src/Layer/LayerDescriptor.php（默认值、取值范围、选项都在那里，
 * preset 里的 params 缺项会自动取默认）。
 *
 * token 级层（var/func/class/controlflow/number/string/comment/stat/ws/dead/junk_runtime）
 *   作用于同一 token 流，按序累积；
 * shell:* 层（shell:vm/shell:eval/shell:gz/shell:anti_debug/shell:anti_hook/
 *   shell:anti_tamper/shell:license/inject）在 token 流水线结束后按层顺序包裹源码。
 *
 * 外壳顺序约定（数组越靠后 = 越外层）：shell:anti_debug → shell:anti_hook →
 *   shell:vm → shell:eval → shell:gz → shell:anti_tamper → shell:license
 * （anti_debug/anti_hook 置于编码壳之前被裹入密文藏匿；anti_tamper/license 因明文锚点须留最外层，
 *   实际顺序由 Obfuscator::orderShellLayers 强制规整）
 */

/**
 * 全档共用的"可运行性"基线（v1.1.1 起）。
 *
 * `var` 层的属性重命名只在**单文件封闭世界**里成立：属性声明被改名后，任何按原名读取
 * 该属性的代码都必须同步改。这个前提在真实应用里几乎从不成立：
 *
 *   1. 框架基类按属性名读写子类属性 —— ThinkPHP 的 `Model::$name`（表名）、
 *      `Model::$table` / `$pk` / `$json` / `$hidden` 由 vendor 代码用 `$this->name`
 *      读取，而 vendor 不在混淆范围内；属性一改名，表名退化成类名派生的 `product`，
 *      直接 `no such table`。
 *   2. 链式访问末段归属别的对象 —— `$this->app->request` 里的 `request` 属于
 *      `think\App`（容器绑定名），与本文件声明的同名属性毫无关系。旧实现只看 `->`
 *      就改写，导致整站 500（v1.1.1 已单独修复，见 VariableObfuscator::isThisAccess）。
 *   3. 跨文件继承 —— 改名的碰撞退避是逐文件进行的，父子类分处两文件时可能拿到
 *      不同新名，父类读到 null、子类写入动态属性。
 *
 * 代价评估：只放弃「属性名 / 参数名」两类标识符，局部变量、函数、类、方法名照旧全改，
 * 各档的强度差异（VM / 壳层 / 控制流）完全不受影响。属性名本身信息量低（多为
 * `$name` `$data` `$id`），为它冒"整站跑不起来"的风险不划算。
 *
 * 确知目标是封闭代码（纯库、单文件脚本、无框架继承）时，可用 `--no-var-keep-props`
 * / `--no-var-keep-params` 关掉，把属性名与参数名也纳入重命名。
 */
$FRAMEWORK_SAFE = [
    'var_keep_props'  => true,
    'var_keep_params' => true,
];

return [
    // ===================== low：大部分混淆（无 VM）—— token 级全量 + EVAL/GZ 双层壳 =====================
    // 定位：**能日常上线、又真能挡人**的入门档。VM 壳把每条语句交给解释器逐条执行，是唯一
    // 「开销与业务语句数成正比」的开关，故 low 不用 VM；除此之外的 token 级变换与轻量壳全开：
    // 变量/函数/类名、数字编码、字符串 XOR 装配、控制流（含 GOTO）、语义恒等改写、运行时垃圾、
    // 死代码、token 形态、语句拆分、压缩，外挂 EVAL 多层壳 + GZ 壳 + 反调试探针。
    // 运行时成本仅剩「加载时一次解码」，跑起来与原生同速。
    'low' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 10]],
            ['name' => 'func',         'params' => ['length' => 12, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 8, 'split' => 2]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 2, 'goto_cpx' => 6]],
            ['name' => 'semantic',     'params' => ['strength' => 1]],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 2, 'fake_b64' => true, 'fake_echo' => true]],
            ['name' => 'dead',         'params' => ['density' => 2]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 4]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:eval',   'params' => ['layers' => 2]],
            ['name' => 'shell:anti_debug', 'params' => ['probes' => 'ext,func', 'intensity' => 1]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 2]],
        ],
        'shell_shield' => true,
        'shell_shield_intensity' => 1,
    ] + $FRAMEWORK_SAFE,

    // ===================== mid：几乎所有混淆（唯一缺的只有 VM）=====================
    // 相对 low 补上：深度反AST、语义变换加强、字符串切更碎、GOTO 更深、token 诱饵更多，
    // 以及 low 没有的四个壳 —— 防 hook、蜜罐、换行缠绕、结构毒化、自校验防篡改，
    // EVAL 层数 2→5。
    // 不用 VM 仍然是性能决定：mid 要在「随时可上线」的前提下尽量难啃，故把不随语句数
    // 增长的壳全部堆满，运行时开销集中在一次解码 + 启动探针。
    'mid' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 16]],
            ['name' => 'func',         'params' => ['length' => 18, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 3, 'goto_cpx' => 8]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 6, 'split' => 4]],
            ['name' => 'semantic',     'params' => ['strength' => 2]],
            ['name' => 'anti_ast',     'params' => []],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 8, 'fake_b64' => true, 'fake_echo' => true, 'tangle' => true]],
            ['name' => 'dead',         'params' => ['density' => 3]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 8]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:eval',   'params' => ['layers' => 5]],
            ['name' => 'shell:anti_debug',  'params' => ['probes' => 'ext,func,ini,env,sapi', 'intensity' => 3]],
            ['name' => 'shell:anti_hook',   'params' => ['strict' => true]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 4]],
            ['name' => 'shell:honeypot',    'params' => ['count' => 2]],
            ['name' => 'shell:newline_poison', 'params' => ['divisor' => 8, 'string_ratio' => 0.4]],
            ['name' => 'shell:structpoison', 'params' => ['rounds' => 2]],
            ['name' => 'shell:anti_tamper', 'params' => ['segments' => 4, 'shutdown' => true]],
        ],
        'shell_shield' => true,
        'shell_shield_intensity' => 2,
    ] + $FRAMEWORK_SAFE,

    // ===================== high：VM 状态机 + 全 P8 架构方向 + 深层 GOTO + 语义变换 + 全反检测 =====================
    'high' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 16]],
            ['name' => 'func',         'params' => ['length' => 18, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 3, 'goto_cpx' => 8]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 6, 'split' => 4]],
            ['name' => 'semantic',     'params' => ['strength' => 2]],
            ['name' => 'anti_ast',     'params' => []],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 8, 'fake_b64' => true, 'fake_echo' => true]],
            ['name' => 'dead',         'params' => ['density' => 3]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 6]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:vm',     'params' => ['stateMachine' => true, 'chunks' => 8, 'morph' => true, 'fakeFog' => 80, 'probe' => 12, 'keychain' => true, 'builtinReplace' => true, 'junk' => 80]],
            ['name' => 'shell:eval',   'params' => ['layers' => 6]],
            ['name' => 'shell:anti_debug',  'params' => ['probes' => 'ext,func,ini,env,sapi', 'intensity' => 3]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 4]],
            ['name' => 'shell:anti_hook',   'params' => ['strict' => true]],
            ['name' => 'shell:anti_tamper', 'params' => ['segments' => 6, 'shutdown' => true]],
        ],
        'shell_shield' => true,
        'shell_shield_intensity' => 2,
    ] + $FRAMEWORK_SAFE,

    // ===================== max：VM 状态机全参数拉满 + 深层 GOTO + 语义变换 + 全反检测矩阵 =====================
    'max' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 20]],
            ['name' => 'func',         'params' => ['length' => 24, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 4, 'goto_cpx' => 12]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 4, 'split' => 6]],
            ['name' => 'semantic',     'params' => ['strength' => 3]],
            ['name' => 'anti_ast',     'params' => []],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 10, 'fake_b64' => true, 'fake_echo' => true, 'tangle' => true]],
            ['name' => 'dead',         'params' => ['density' => 5]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 10]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:vm',     'params' => ['stateMachine' => true, 'chunks' => 12, 'morph' => true, 'fakeFog' => 160, 'probe' => 30, 'keychain' => true, 'builtinReplace' => true, 'junk' => 160]],
            ['name' => 'shell:eval',   'params' => ['layers' => 8]],
            ['name' => 'shell:anti_debug',  'params' => ['probes' => 'ext,func,ini,env,sapi,trace', 'intensity' => 4]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 5]],
            ['name' => 'shell:anti_hook',   'params' => ['strict' => true]],
            ['name' => 'shell:anti_tamper', 'params' => ['segments' => 8, 'shutdown' => true]],
            ['name' => 'shell:honeypot',    'params' => ['count' => 4]],
            ['name' => 'shell:structpoison','params' => ['rounds' => 3]],
        ],
        'shell_shield' => true,
        'shell_shield_intensity' => 3,
        // Confrontation-LLM：反 LLM 静态分析（误导注释 + 生僻 CJK 散布）
        'confrontation_llm' => true,
        'confrontation_intensity' => 2,
    ] + $FRAMEWORK_SAFE,

    // ===================== ultra：真·嵌套 VM（layers=2）+ 状态机/垃圾字节码/深层 GOTO/语义变换全顶格 =====================
    'ultra' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 24]],
            ['name' => 'func',         'params' => ['length' => 28, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 5, 'goto_cpx' => 16]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 4, 'split' => 8]],
            ['name' => 'semantic',     'params' => ['strength' => 3]],
            ['name' => 'anti_ast',     'params' => []],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 10, 'fake_b64' => true, 'fake_echo' => true, 'tangle' => true]],
            ['name' => 'dead',         'params' => ['density' => 10]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 50]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:vm',     'params' => ['stateMachine' => true, 'chunks' => 16, 'layers' => 2, 'morph' => true, 'fakeFog' => 200, 'probe' => 50, 'keychain' => true, 'builtinReplace' => true, 'junk' => 200, 'cpool' => true, 'combo' => 3]],
            ['name' => 'shell:eval',   'params' => ['layers' => 10]],
            ['name' => 'shell:anti_debug',  'params' => ['probes' => 'ext,func,ini,env,sapi,trace', 'intensity' => 4]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 6]],
            ['name' => 'shell:anti_hook',   'params' => ['strict' => true]],
            ['name' => 'shell:anti_tamper', 'params' => ['segments' => 8, 'shutdown' => true]],
            ['name' => 'shell:honeypot',    'params' => ['count' => 6]],
            ['name' => 'shell:structpoison','params' => ['rounds' => 5]],
        ],
        'shell_shield' => true,
        'shell_shield_intensity' => 3,
        // Confrontation-LLM：反 LLM 静态分析（误导注释 + 生僻 CJK 散布，强度拉满）
        'confrontation_llm' => true,
        'confrontation_intensity' => 3,
        // VM opcode 组合执行（抗反编译）强度 3
        'vm_combo' => 3,
    ] + $FRAMEWORK_SAFE,
];
