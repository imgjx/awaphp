<?php
/**
 * ThinkPHP 8 专用混淆配置（白名单模式）
 * ============================================================
 * 适用场景：对整套 ThinkPHP 8 应用做工程模式混淆（--input 指向项目根目录，
 *           或只指向 app/ 目录）。本文件已内嵌「等价 high 的分层混淆 + 白名单
 *           + 框架兼容选项」，因此无需再传 --level（--config 与 --level 互斥）。
 *
 * 用法示例：
 *   php entry.php \
 *       --config=other/thinkphp8.config.php \
 *       --input=/path/to/tp8 \
 *       --output=/path/to/tp8_obf \
 *       --salt=change-me-salt \
 *       --manifest=/path/to/tp8_obf/.obf-manifest.json
 *
 * 为什么 ThinkPHP 需要专门的白名单 / 兼容项：
 *   - 工程模式强制保留类名原名（PSR-4 按「命名空间 + 文件名」自动加载，改名即找不到类）；
 *     故本配置把 class 层 on 显式设为 false，即便误用于单文件模式也不改名。
 *   - var_keep_props=true：ThinkPHP 模型/实体靠属性名映射字段、获取器、toArray() 序列化，
 *     属性名一改，ORM 与接口序列化全部错位——最典型的是 Model::$name（表名）：它由 vendor
 *     代码用 $this->name 读取，改名后表名退化成类名派生的 product，直接 no such table。
 *     v1.1.1 起五档预设已默认开启此项，本文件显式写出只为意图清晰（不依赖默认值）。
 *   - var_keep_params=true：路由按方法名 + 参数名注入请求、依赖注入、验证器按名取参，
 *     参数名一改，入参绑定整体失效。同上，v1.1.1 起预设默认开启。
 *   - whitelist.dirs：框架(vendor)、配置(config)、路由(route)、运行时(runtime)、
 *     Web 根(public) 等基础设施整目录原样跳过，绝不混淆。
 *
 * dirs/files 路径说明：相对【输入目录】。dir 为前缀匹配并支持 * 通配；
 *   file 为精确匹配。命中即整文件原样拷贝。
 */

return [
    // ===================== 分层混淆（基于 high 预设，关闭类名混淆以兼容 PSR-4）=====================
    'layers' => [
        ['name' => 'var',          'params' => ['length' => 16]],
        ['name' => 'func',         'params' => ['length' => 18, 'call' => true, 'enc' => true]],
        // 类名混淆显式关闭：工程模式强制保留原名；单文件模式误用时也不改名，避免 TP 按原名找不到类。
        ['name' => 'class',        'params' => ['on' => false]],
        ['name' => 'controlflow',  'params' => ['cfg' => 'high', 'goto' => 3, 'goto_cpx' => 8]],
        ['name' => 'number',       'params' => ['on' => true]],
        ['name' => 'string',       'params' => ['xor' => true, 'length' => 6, 'split' => 4]],
        ['name' => 'semantic',     'params' => ['strength' => 2]],
        ['name' => 'anti_ast',     'params' => []], // 深度反AST：echo/print/return 常量改写为「累加器 + 恒等表达式」
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

    // ===================== 框架兼容关键选项 =====================
    // 保留类实例属性名：模型/实体字段映射、获取器、toArray() 序列化都依赖属性名
    'var_keep_props'  => true,
    // 保留方法/函数参数名：路由注入、依赖注入、验证器按名取参都依赖参数名
    'var_keep_params' => true,

    // ===================== 白名单：不混淆的目录 / 文件 / 符号 =====================
    // 假设 --input 指向 ThinkPHP 项目根目录；若 --input 只指向 app/，则下列框架目录不会进入
    // 混淆范围，dirs 中的对应项可删减。命中即整文件/整目录原样拷贝跳过。
    'whitelist' => [
        // —— 整目录跳过（框架与基础设施绝不混淆）——
        'dirs' => [
            'vendor',   // 框架 topthink/* 与全部第三方依赖
            'config',   // 配置文件：以数组返回，键名/变量被改会破坏配置加载
            'route',    // 路由定义：闭包路由与 控制器::方法 字符串引用
            'runtime',  // 运行时生成（缓存/日志/会话），通常不进输入
            'public',   // Web 根：index.php + 静态资源
            'tests',    // 测试目录
            'database', // 迁移与种子
            'extend',   // 扩展目录
        ],

        // —— 精确文件跳过（相对输入目录）——
        'files' => [
            // 'app/provider.php',  // provider 数组里的类名若被改会破坏服务注册，必要时跳过
            // 'app/event.php',     // 事件/监听器映射，必要时跳过
        ],

        // —— 变量白名单（带不带 $ 均可）——
        // 通常已由 var_keep_props / var_keep_params 兜底；此处列超级全局与约定变量作防御性声明
        'variables' => [
            '_GET', '_POST', '_REQUEST', '_SESSION', '_COOKIE', '_SERVER', '_ENV', '_FILES',
        ],

        // —— 函数白名单（原函数名保留）——
        // ThinkPHP 助手函数（app/request/response/view/db/cache/...）多为全局函数、按名调用；
        // 仅当显式开启函数改名时才需在此列全。默认函数层只改本地定义的函数，全局助手不受影响。
        'functions' => [
            // 'app', 'request', 'response', 'view', 'cookie', 'session', 'cache', 'db', 'model', 'validate',
        ],

        // —— 类名白名单（FQCN 或短名；namespaces/interfaces/traits 一并归入 classes）——
        // 工程模式类名默认保留（class_obf=false），此处主要防御「单文件模式」或「显式开启类改名」时使用。
        'classes' => [
            'think\\App', 'think\\Container', 'think\\Facade',
            'think\\facade\\Route', 'think\\facade\\Cache', 'think\\facade\\Db', 'think\\facade\\Lang',
            'think\\Request', 'think\\Response', 'think\\exception\\Handle', 'think\\exception\\HttpException',
            // 你的应用基类 / 服务 / 模型如需在改名前建立原名对照，可在此补充，例如：
            // 'app\\BaseController', 'app\\model\\User',
        ],

        'constants' => [],

        // —— 方法名白名单（形如 Class::method 或裸方法名）——
        // 方法名默认不改名，仅在显式开启方法改名时才需列出。控制器 public 动作方法由路由按名调度，必须保留。
        'methods' => [
            'index', 'hello',            // 示例：Index 控制器动作
            // 把你的全部控制器 public 动作方法名列全，例如：
            // 'seed', 'products', 'place', 'order', 'calc', 'search', 'reviewStats',
        ],

        'namespaces' => [],
        'interfaces' => [],
        'traits'     => [],
    ],
];
