<?php
/**
 * 默认配置模板（含全部混淆选项与默认值）
 *
 * 被 src/Config.php 读取作为归一化的基准；CLI 参数与 JSON 配置文件均可覆盖。
 */

declare(strict_types=1);

return [
    // 基础
    'input'        => null,   // 输入文件或目录（必填）
    'output'       => null,   // 输出文件或目录
    'config'       => null,   // 指定 JSON 配置文件路径
    'salt'         => '',     // 混淆盐（空 = 不加盐）
    'repeat'       => 1,      // 重复加密次数

    // 变量
    'var_length'   => 8,      // 变量名混淆后长度
    'var_enc_length' => 0,    // 变量名编码长度别名（>0 时覆盖 var_length）
    'var_split'    => 1,      // 变量值拆分段数（>1 生效）
    // 保留类实例属性名 / 参数名。裸开关模式默认 false（闭世界假设），
    // 而 min/low/mid/high/max/ultra **六档预设默认 true**（见 src/config.presets.php 的
    // $FRAMEWORK_SAFE）：属性名被框架按原文读取（Model::$name 决定表名）、
    // 参数名被路由/DI/命名实参按名匹配，改名会静默错位。--no-var-keep-props /
    // --no-var-keep-params 可对预设显式关掉（仅限封闭代码）。
    'var_keep_props' => false, // 保留类实例属性名（框架应用必须开；预设已默认开）
    'var_keep_params' => false, // 保留函数/方法参数名（框架应用必须开；预设已默认开）

    // 函数
    'func_length'  => 10,     // 函数名混淆后长度
    'func_call'    => false,  // 函数调用混淆（间接调用）
    'func_call_enc' => false, // 函数调用编码（包装名字）

    // 类
    'class_obf'    => false,  // 类名混淆

    // 字符串
    'str_length'   => 0,      // 字符串编码分段长度（0 = 关闭）
    'html'         => false,  // HTML 编码
    'num_enc'      => false,  // 数字编码

    // 编码
    'gz'           => false,  // GZ 压缩编码整段代码

    // 语义等价变换层（表达式/布尔/控制流恒等改写，破坏静态模式匹配）
    'semantic'          => false,  // 是否启用语义等价变换层
    'semantic_strength' => 2,      // 变换强度 1|2|3（越高改写越激进）

    // 优化
    'insert_dead'  => false,  // 插入无用代码
    'no_comments'  => false,  // 移除注释
    'strip_newline' => false, // 去除换行符
    'compress'     => false,  // 代码压缩（去空白/注释）

    // ===== 第三阶段：控制流 / 虚拟加壳 / 反检测 =====
    'cfg'          => '',      // 控制流混淆：low | medium | high
    'goto'         => 0,       // GOTO 混淆嵌套层数
    'goto_cpx'     => 0,       // GOTO 混淆每分发器最大语句数（>1 生效）
    'vm'           => false,   // VM 虚拟机壳
    'vm_jit'       => true,    // VM JIT 友好模式：解释器以可被 OPcache 追踪 JIT 编译的内联栈形式发射（false=回退旧闭包栈，最大兼容）
    'vm_layers'    => 1,       // VM 嵌套层数（真·嵌套 VM）：>=2 时把上一层产物再 VM 化
    'vm_combo'     => 0,       // VM opcode 组合执行强度 0-3（0=关；1-3 越高等价多指令序列越密，抗反编译）
    'eval'         => false,   // EVAL 多层加壳
    'eval_layers'  => 3,       // EVAL 加壳层数
    'anti_debug'   => false,   // 反调试探针
    'anti_hook'    => false,   // 防 hook eval（eval 完整性探针 + 劫持扩展检测）
    'anti_tamper'  => false,   // 自校验防篡改
    'php_version'  => '8.1',   // 目标 PHP 版本（7.4/8.0/8.1/8.2/8.3）
    'opcache'      => false,   // OPcache 辅助编译：产物写出后编译为 Zend 字节码（部分虚拟主机如 EP 支持）
    'str_xor'      => false,   // 字符串运行时异或装配（替代 base64）
    'junk_funcs'   => 0,       // 诱饵函数数量（>0 生效）
    'goto_trap'    => 2,       // GOTO 诱饵死状态数（0-4，每个分派器注入不可达分支）
    'struct_poison' => false,  // 结构指纹毒化壳（指纹进密钥派生；篡改即解出乱码）
    'newline_poison' => false,  // 换行缠绕壳：注入大量换行符纠缠进代码与载荷（配合结构毒化壳/自校验，删空白即出错）
    'newline_poison_divisor' => 2,   // 换行数 = ceil(输入源码字节数 / divisor)，默认 1/2（10KB → 5000 个换行）
    'newline_poison_min' => 0,       // 换行数下限（0=不设下限，仅极小文件可设个位数兜底）
    'honeypot'     => 0,       // 蜜罐函数数量（拟真高价值命名 + 深循环拖慢 + 伪密钥）
    'shell_shield' => true,    // 逐层壳自保护：每层壳的解码逻辑再被外层混淆护住（破一层要先被恶心一层）
    'shell_shield_intensity' => 2, // 自保护语义变换强度 1-3（仅影响语义层；变量重命名始终开启）

    // ===== Confrontation-LLM：反 LLM 静态分析 =====
    'mutual_comments' => true,   // 互斥注释：成对投放互相矛盾的注释（死代码 vs 唯一活路径、归属/版本/覆盖率…），藏在各层壳内部的密文里
    'mutual_intensity' => 2,     // 互斥注释强度 1~3（每层壳投放 1/2/4 对）
    'confrontation_llm'       => false, // 注入误导性注释（假 @deprecated/@see/@todo/@fixme + 伪造 CHANGELOG）并散布生僻 CJK，误导 LLM 优先级判断
    'confrontation_intensity' => 2,     // 反 LLM 注入强度 1-3（越大注释越密）

    // 运行时垃圾指令（GeneralJunk，token 层通用垃圾）：真实执行、无副作用、由 salt 确定性复现
    'junk_runtime'   => false, // 启用运行时垃圾指令插入（顶层 flag，旧管线直接调用）
    'junk_intensity' => 1,     // 插入密度 1~10（越大越密）
    'junk_fake_b64'  => true,  // 诱饵 base64（解了不用的孤立串）
    'junk_fake_echo' => true,  // 经输出缓冲执行的伪 echo（不留痕迹）
    'junk_tangle'    => false, // 执行缠结：垃圾值经恒等式缠结进真实整型赋值（删垃圾即出错）

    // 深度反AST：语句位 echo/print/return 的常量字面量改写成"累加器 + 恒等表达式"形式
    'anti_ast'    => false,   // 深度反AST 总开关（默认关，需显式开启：--anti-ast 或分层 JSON 的 anti_ast 层）

    // 白名单：不混淆的目录 / 文件 / 符号。目录模式可整文件跳过（原样拷贝）；符号保留原名。
    // dirs/files 用相对输入目录的路径（file 为精确匹配，dir 为前缀匹配，支持 * 通配）；
    // variables 带或不带 $ 均可；functions/classes 用原名（类支持 FQCN 或短名）；
    // constants/methods 用原标识符（method 形如 Class::method 或裸方法名）；
    // namespaces/interfaces/traits 命中即跳过对应声明混淆。
    'whitelist' => [
        'dirs'       => [],
        'files'      => [],
        'variables'  => [],
        'functions'  => [],
        'classes'    => [],
        'constants'  => [],
        'methods'    => [],
        'namespaces' => [],
        'interfaces' => [],
        'traits'     => [],
    ],

    // ===== 第四阶段：授权绑定 =====
    'license_domain' => '',    // 授权域名（逗号分隔，空=不校验，仅 Web SAPI 生效）
    'license_expire' => '',    // 授权到期时间（Y-m-d [H:i:s]，空=永不过期）

    // 界面 / 输出
    'copyright'    => '',     // 版权注释注入文本
    'no_banner'    => false,  // 关闭文件头引擎信息注入
    'format'       => 'text', // text | json
    'verbose'      => false,  // 详细日志
    'silence'      => false,  // 静默
];
