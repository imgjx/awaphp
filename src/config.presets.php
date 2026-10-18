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

return [
    // ===================== low：最轻量，仅 token 级混淆 + GZ 壳 =====================
    'low' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 6]],
            ['name' => 'func',         'params' => ['length' => 8,  'call' => true, 'enc' => false]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 0, 'split' => 1]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:gz',     'params' => ['decoy' => false, 'chunks' => 2]],
        ],
    ],

    // ===================== mid：引入 VM 壳 + 控制流 + 动态垃圾 =====================
    'mid' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 10]],
            ['name' => 'func',         'params' => ['length' => 12, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'medium', 'goto' => 1, 'goto_cpx' => 4]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 8, 'split' => 2]],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 3, 'fake_b64' => true, 'fake_echo' => true]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 3]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:vm',     'params' => ['flatten' => false, 'chunks' => 3]],
            ['name' => 'shell:eval',   'params' => ['layers' => 3]],
            ['name' => 'shell:anti_debug', 'params' => ['probes' => 'ext,func,ini', 'intensity' => 2]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 3]],
        ],
    ],

    // ===================== high：VM 平坦化 + 反调试/防篡改/防hook =====================
    'high' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 14]],
            ['name' => 'func',         'params' => ['length' => 16, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 2, 'goto_cpx' => 6]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 6, 'split' => 3]],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 5, 'fake_b64' => true, 'fake_echo' => true]],
            ['name' => 'dead',         'params' => ['density' => 2]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => false, 'decoy' => 4]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:vm',     'params' => ['flatten' => true, 'chunks' => 5]],
            ['name' => 'shell:eval',   'params' => ['layers' => 4]],
            ['name' => 'shell:anti_debug',  'params' => ['probes' => 'ext,func,ini,env,sapi', 'intensity' => 3]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 4]],
            ['name' => 'shell:anti_hook',   'params' => ['strict' => true]],
            ['name' => 'shell:anti_tamper', 'params' => ['segments' => 4, 'shutdown' => true]],
        ],
    ],

    // ===================== max：VM 状态机 + 最高强度全壳 + 拟真垃圾 =====================
    'max' => [
        'layers' => [
            ['name' => 'var',          'params' => ['length' => 18]],
            ['name' => 'func',         'params' => ['length' => 20, 'call' => true, 'enc' => true]],
            ['name' => 'class',        'params' => ['on' => true]],
            ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 3, 'goto_cpx' => 10]],
            ['name' => 'number',       'params' => ['on' => true]],
            ['name' => 'string',       'params' => ['xor' => true, 'length' => 4, 'split' => 4]],
            ['name' => 'junk_runtime', 'params' => ['intensity' => 10, 'fake_b64' => true, 'fake_echo' => true]],
            ['name' => 'dead',         'params' => ['density' => 3]],
            ['name' => 'comment',      'params' => []],
            ['name' => 'stat',         'params' => []],
            ['name' => 'token',        'params' => ['case' => true, 'literal' => true, 'quote' => true, 'heredoc' => true, 'decoy' => 6]],
            ['name' => 'ws',           'params' => []],
            ['name' => 'shell:vm',     'params' => ['stateMachine' => true, 'chunks' => 8],
                                  'inject' => ['anti_debug' => true, 'anti_debug_level' => 3]],
            ['name' => 'shell:eval',   'params' => ['layers' => 6]],
            ['name' => 'shell:anti_debug',  'params' => ['probes' => 'ext,func,ini,env,sapi,trace', 'intensity' => 4]],
            ['name' => 'shell:gz',     'params' => ['decoy' => true, 'chunks' => 5]],
            ['name' => 'shell:anti_hook',   'params' => ['strict' => true]],
            ['name' => 'shell:anti_tamper', 'params' => ['segments' => 6, 'shutdown' => true]],
            ['name' => 'shell:license', 'params' => ['domain' => '', 'expire' => '']],
        ],
    ],
];
