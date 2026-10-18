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
    'eval'         => false,   // EVAL 多层加壳
    'eval_layers'  => 3,       // EVAL 加壳层数
    'anti_debug'   => false,   // 反调试探针
    'anti_hook'    => false,   // 防 hook eval（eval 完整性探针 + 劫持扩展检测）
    'anti_tamper'  => false,   // 自校验防篡改
    'php_version'  => '8.1',   // 目标 PHP 版本（7.4/8.0/8.1/8.2/8.3）
    'str_xor'      => false,   // 字符串运行时异或装配（替代 base64）
    'junk_funcs'   => 0,       // 诱饵函数数量（>0 生效）

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
