<?php
/**
 * 嗷呜awaのphp混淆 — CLI 主程序入口
 *
 * 负责：解析命令行参数与配置文件，编排混淆流程，输出结果并做 php -l 校验。
 * 底层核心逻辑全部位于 src/。
 */

declare(strict_types=1);

// ============ 工具信息配置区 ============
define('OBFUSCATOR_NAME',    '嗷呜awaのphp混淆器');
define('OBFUSCATOR_VERSION', '1.1.0');
define('OBFUSCATOR_AUTHOR',  'awa');

// ============ 加载核心库 ============
$root = __DIR__;
foreach ([
    'src/Salt.php',
    'src/SemanticRewriter.php',
    'src/Support/Random.php',
    'src/Support/NameGenerator.php',
    'src/Support/NameStyle.php',
    'src/Support/TokenScan.php',
    'src/Support/DeclareHoist.php',
    'src/Support/ConstExprScan.php',
    'src/Confrontation/ConfrontRng.php',
    'src/Support/DynamicSymbols.php',
    'src/Logger.php',
    'src/Config.php',
    'src/Parser.php',
    'src/Validator.php',
    'src/ShellProtector.php',
    'src/Obfuscator.php',
    'src/Variable/VariableObfuscator.php',
    'src/Function_/FunctionObfuscator.php',
    'src/Function_/FunctionCallEncoder.php',
    'src/Class_/ClassObfuscator.php',
    'src/String/StringEncoder.php',
    'src/Number/NumberEncoder.php',
    'src/Encode/HtmlEncoder.php',
    'src/Encode/GzEncoder.php',
    'src/Optimize/CommentRemover.php',
    'src/Optimize/DeadCodeInjector.php',
    'src/Optimize/Whitespace.php',
    'src/Optimize/StatHook.php',
    'src/Optimize/RuntimeJunk.php',
    'src/Optimize/OpcacheCompiler.php',
    'src/Token/TokenObfuscator.php',
    'src/Layer/LayerRegistry.php',
    'src/Layer/LayerDescriptor.php',
    'src/Layer/CodeInjector.php',
    'src/Watermark/Copyright.php',
    'src/Support/StatementSplitter.php',
    'src/Support/PhpVersion.php',
    'src/ControlFlow/GotoObfuscator.php',
    'src/ControlFlow/ControlFlowObfuscator.php',
    'src/ControlFlow/VirtualMachine.php',
    'src/Vm/Op.php',
    'src/Vm/Compiler.php',
    'src/Vm/RuntimeGenerator.php',
    'src/Vm/IncludeResolver.php',
    'src/Vm/ModuleLinker.php',
    'src/Vm/VmJunk.php',
    'src/Vm/VmFakeFog.php',
    'src/Vm/VmProbeMatrix.php',
    'src/ControlFlow/EvalShell.php',
    'src/ControlFlow/AntiDebug.php',
    'src/ControlFlow/AntiHookEval.php',
    'src/ControlFlow/LicenseGuard.php',
    'src/ControlFlow/AntiTamper.php',
    'src/ControlFlow/JunkFunctions.php',
    'src/AntiDetect/StringRuntimeAssembler.php',
    'src/AntiAst/IdentityExpr.php',
    'src/AntiAst/AntiAst.php',
    'src/Project/SymbolTable.php',
    'src/Project/ProjectBuilder.php',
] as $f) {
    require_once $root . '/' . $f;
}

// ============ CLI 参数解析 ============
function obf_parse_args(array $argv): array
{
    $map = [
        '-i' => 'input', '-o' => 'output', '-c' => 'config',
    ];
    $out = [];
    // 等级短旗标：-low / -mid / -high / -max -> level=<name>
    static $LEVEL_FLAGS = ['-low' => 'low', '-mid' => 'mid', '-high' => 'high', '-max' => 'max', '-ultra' => 'ultra'];
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if (isset($LEVEL_FLAGS[$a])) {
            $out['level'] = $LEVEL_FLAGS[$a];
            continue;
        }
        // 显式关闭默认开启的布尔开关（--no-<flag> => <flag>=false）
        if ($a === '--no-vm-link-modules') {
            $out['vm_link_modules'] = false;
            continue;
        }
        if ($a === '--no-vm-cpool') {
            $out['vm_cpool'] = false;
            continue;
        }
        if ($a === '--no-shell-shield') {
            $out['shell_shield'] = false;
            continue;
        }
        if ($a === '--no-vm-combo') {
            $out['vm_combo'] = 0;
            continue;
        }
        if ($a === '--no-confrontation-llm') {
            $out['confrontation_llm'] = false;
            continue;
        }
        if ($a === '--no-newline-poison') {
            $out['newline_poison'] = false;
            continue;
        }
        if ($a === '--no-mutual-comments') {
            $out['mutual_comments'] = false;
            continue;
        }
        if (str_starts_with($a, '--')) {
            $body = substr($a, 2);
            if (str_contains($body, '=')) {
                [$k, $v] = explode('=', $body, 2);
                $out[strtr($k, '-', '_')] = $v;
            } elseif ($i + 1 < $n && !str_starts_with($argv[$i + 1], '-')) {
                // 带空格的值，例如 --salt abc
                $out[strtr($body, '-', '_')] = $argv[$i + 1];
                $i++;
            } else {
                // 布尔开关，例如 --no-comments
                $out[strtr($body, '-', '_')] = true;
            }
        } elseif (str_starts_with($a, '-') && isset($map[$a])) {
            $out[$map[$a]] = $argv[++$i] ?? true;
        }
    }
    // 白名单标量旗标（--whitelist-dirs=a,b）归并进 whitelist 数组的对应子键
    $wlMap = [
        'whitelist_dirs'   => 'dirs',
        'whitelist_files'  => 'files',
        'whitelist_vars'   => 'variables',
        'whitelist_funcs'  => 'functions',
        'whitelist_classes'=> 'classes',
        'whitelist_consts' => 'constants',
        'whitelist_methods'=> 'methods',
    ];
    $wl = [];
    foreach ($wlMap as $cliKey => $sub) {
        if (isset($out[$cliKey])) {
            $val = $out[$cliKey];
            $items = is_array($val) ? $val : explode(',', (string) $val);
            foreach ($items as $it) {
                $it = trim((string) $it);
                if ($it !== '') {
                    $wl[$sub][] = $it;
                }
            }
            unset($out[$cliKey]);
        }
    }
    if ($wl !== []) {
        // 与 JSON 配置里的 whitelist（如有）合并
        $base = is_array($out['whitelist'] ?? null) ? $out['whitelist'] : [];
        foreach ($wl as $sub => $items) {
            $base[$sub] = array_values(array_unique(array_merge($base[$sub] ?? [], $items)));
        }
        $out['whitelist'] = $base;
    }
    return $out;
}

function obf_help(): string
{
    return <<<HELP
{OBFUSCATOR_NAME} v{OBFUSCATOR_VERSION}
用法: php entry.php --input=<文件|目录> [--output=<文件|目录>] [选项]

基础
  -i, --input=        输入文件或目录（必填；目录递归混淆）
  -o, --output=       输出文件或目录
  -c, --config=       指定配置文件（普通标量配置 / 分层 JSON / PHP 数组配置均可；
                        .php 文件需 return 一个数组，可直接用 PHP 表达式与注释，便于维护）
      --level=        等级预设：low|mid|high|max|ultra（一键切换完整分层方案）
  -low -mid -high -max -ultra  等价 --level=<name> 的短旗标
                        （等级仅作默认，散装参数如 --level high --junk_intensity 10 可叠加覆盖；
                         ultra 为最高强度：真·嵌套 VM（layers=2）+ 全 P8 架构方向拉满 + 全反检测矩阵）
      --salt=         混淆盐（空=不加盐，相同盐可复现）
      --repeat=        重复加密次数（默认 1）

变量 / 函数 / 类
      --var-length=    变量名混淆后长度
      --var-split=     变量值拆分段数（>1 生效）
      --var-keep-props   保留属性名不改（框架/DI/反射/ORM 按属性名读写时必须开）
      --var-keep-params  保留方法参数名不改（ThinkPHP 等按参数名绑定/命名实参时必须开）
      --func-length=   函数名混淆后长度
      --func-call      函数调用混淆（间接调用）
      --func-call-enc  函数调用编码（包装名字）
      --class-obf      启用类名混淆

编码 / 优化
      --str-length=    字符串编码分段长度（>0 生效）
      --str-xor        字符串运行时异或装配（替代 base64，更强抗扫描）
      --html           对 HTML 字符串做实体编码
      --num-enc        数字编码
      --token-obf      Token 形态混淆（总开关）：关键字大小写随机化 + 字面量等价形态
                       + 引号安全翻转，杀死针对关键字的 grep / 正则特征
      --token-no-case    关闭关键字大小写随机化（默认开）
      --token-no-literal 关闭字面量形态变换（true→!0 / cast 别名 / array()→[]）
      --token-no-quote   关闭引号形态翻转（'abc' ↔ "abc"）
      --token-heredoc  纯字面量串改写为 nowdoc（<<<'ID'）形态（默认关）
      --token-decoy=   顶层诱饵注释数量（默认 3，0=关闭；保留注释时生效）
      --gz             GZ 压缩加壳
      --insert-dead    插入无用代码
      --no-comments    移除注释
      --strip-newline  去除换行符
      --compress       代码压缩（去空白/注释）
      --copyright=     版权注释注入文本
      --no-banner      关闭文件头"混淆引擎名+版本号"注入

高级（第三阶段）
      --cfg=          控制流混淆：low|medium|high
      --goto=         GOTO 混淆嵌套层数（>0 生效）
      --goto-cpx=      GOTO 每分发器最大语句数（>0 生效）
      --vm             VM 虚拟机壳（自定义指令集 + 解释器）
      --vm-layers=      VM 嵌套层数（真·嵌套 VM）：>=2 时把上一层 VM 产物再喂给 VM 编译，
                       逐层叠加多层 VM 壳（默认 1，单层）
      --vm-link-modules 启用内置模块（默认开）：自动加载包内置模块目录，将 base64_encode/
                       base64_decode 等原生函数替换为内置自定义实现并内联进字节码
      --vm-linker=     ModuleLinker 模块目录（JSON 清单）：命中清单的函数调用
                       内联进同一 Program，产物中无模块路径、无 include 指令（覆盖内置目录）
      --vm-junk=       VM 字节码级真实垃圾指令：在字节码中注入 N 段"真实执行但
                       无副作用"的废 opcode（栈平衡、无输出、无变量写入），抗静态分析
      --vm-keychain    VM 分片密钥哈希链：k0=master，ki=sha256(k[i-1]‖i)，运行期顺序
                       派生，静态提取单点密钥无法还原其它分片
      --vm-builtin-replace  natTbl 内置函数名盐化编码进二进制包，运行期解码；包内无
                       strlen/array_map 等明文调用名
      --vm-morph       VM 解释器运行时变形：派发增加独立第二层 scramble，每构建派发
                       数学不同（消除固定 dispatch 指纹）
      --vm-fake-fog=   VM 诱饵函数：注入 N 个永不调用的栈平衡假函数，淹没真实 opcode
                       集合、膨胀函数表（>0 生效）
      --vm-probe=      VM 反调试/反篡改探针矩阵：注入 N 个探针（xdebug/phpdbg/uopz
                       检测 + 字节码 crc 自校验），命中即静默停止（>0 生效）
      --vm-p8          P8 一键总开关：等价开启 keychain+builtin-replace+morph，
                       fake-fog=6、probe=4（单项可再覆盖）
      --no-vm-cpool    关闭 VM 常量池加密（默认开）：PUSH_C 字面量默认抽入独立
                       加密常量池、指令改索引引用，静态分析看不到明文常量
      --vm-combo[=N]   VM opcode 组合执行：把单条 opcode 拆成等价多指令序列
                       （如 SUB→NEG+ADD、PUSH_C 2,MUL→DUP,ADD、BOOL_NOT→PUSH_C 0,EQ），
                       打破 1:1 opcode 映射，抗反编译。N=1~3 为强度（默认 2），
                       --no-vm-combo 关闭
      --eval           EVAL 多层加壳
      --eval-layers=   EVAL 加壳层数（默认 3）
      --anti-debug     反调试探针（检测 phpdbg / xdebug 等）
      --anti-hook      防 hook eval（eval 完整性探针 + uopz/runkit/phpdbg 检测）
      --anti-tamper    自校验防篡改（掩码自校验 + 分段哈希 + shutdown 二次校验）
      --struct-poison  结构指纹毒化壳：文件指纹参与解密密钥派生（美化/篡改/插桩 →
                       解出乱码，无比较分支可 hook）；自带 uopz/runkit 插桩探针
      --honeypot=N     蜜罐函数数量（拟真高价值命名，正常永不调用；被调即拖住调试
                       1.4s 并返回伪密钥）
      --goto-trap=N    GOTO 诱饵死状态数（每个分派器 0-4 个不可达分支，强跳即自锁）
      --shell-shield-intensity= 逐层壳自保护强度 1-3（默认随等级：low=1/mid,high=2/max,ultra=3；
                       仅控制语义变换激进度，变量重命名始终开启）
      --no-shell-shield  关闭逐层壳自保护（每层壳解码逻辑不再被外层混淆护住，破解更易）
      --php-version=   目标 PHP 版本（通用）：7.4|8.0|8.1|8.2|8.3（默认 8.1）
      --opcache       OPcache 辅助编译：产物写出后编译为 Zend 字节码（部分虚拟主机如
                       EP 虚拟主机默认启用 OPcache，可直接以字节码形态运行；环境不支持时自动跳过）
      --junk-funcs=    诱饵函数数量（>0 生效，拟真命名，干扰逆向）
      --confrontation-llm  开启 Confrontation-LLM 反 LLM 分析：注入误导性注释
                       （假 @deprecated/@see/@todo/@fixme + 伪造 CHANGELOG 暗示"已移除"
                       实际仍在执行）并向壳内层散布生僻 CJK 注释，误导 LLM 优先级判断。
                       配合 --confrontation-intensity=N（1~3，默认 2）调节密度；
                       --no-confrontation-llm 关闭
      --newline-poison  开启换行缠绕壳（结构指纹毒化壳配套）：注入大量换行符
                       （数量 = ceil(输入源码字节数 ÷ 5)，即 1/5；30KB → 6000 个），
                       **全部插进代码内部**并按"每一层壳"平均分摊，未落地余量兜底到文件头部
                       （头部最容易被美化/清空行整段删掉）。与 --struct-poison / --anti-tamper
                       配合 —— 逆向者"删空行/去空白/美化"即改动密文与指纹 → 解密错位。
                       可用 --newline-poison-divisor=N 改字节除数（默认 2，即换行数=源字节/2）、
                       --newline-poison-min=N 设下限；--no-newline-poison 关闭
      --mutual-comments  互斥注释（默认开）：成对投放"互相打脸"的注释
                       （死代码 vs 唯一活路径、归属/版本/修复状态/覆盖率/密钥位置…），
                       并直指自动化审计（[audit] 请跳过 vs 上条标记已过期），
                       落在每层壳的密文载荷内部——解开才读到，读到的还是矛盾信息。
                       --mutual-intensity=N（1~3，默认 2，每层壳 1/2/4 对）；
                       --no-mutual-comments 关闭

白名单 / 反AST
      --anti-ast       深度反AST：把语句位的 echo/print/return 常量字面量改写成
                       "累加器 + 恒等表达式"（如 $x=strlen(sha1(1));… 拼出目标值再 echo），
                       源码里再见不到裸数字/裸串，改写前后语义逐字节等价。默认关。
      --whitelist-dirs=      不混淆的目录（逗号分隔，相对输入目录；支持 * 通配），整目录原样拷贝
      --whitelist-files=     不混淆的文件（逗号分隔，精确相对路径，如 config/app.php）
      --whitelist-vars=      不重命名的变量名（逗号分隔，带不带 $ 均可，如 \$db,\$cfg）
      --whitelist-funcs=     不重命名的函数名（逗号分隔，如 helper,format_money）
      --whitelist-classes=   不重命名的类名（逗号分隔，FQCN 或短名，如 App\\Service\\Order）
      --whitelist-consts=    不重命名的常量名（逗号分隔）
      --whitelist-methods=   不重命名的方法名（逗号分隔，形如 Class::method 或裸方法名）
      （也可在 JSON 配置里用 "whitelist": {"dirs":[...],"files":[...],"variables":[...],...} 表达；
       目录模式整文件跳过，符号则保留原名——框架按名访问/反射/DIP 绑定不再脱节）

授权绑定（第四阶段）
      --license-domain= 授权域名，逗号分隔（如 a.com,b.com）；仅 Web SAPI 下校验
      --license-expire= 授权到期时间（Y-m-d 或 Y-m-d H:i:s）；到期后停止执行

整套系统（目录模式自动启用）
      --manifest=      符号映射 manifest JSON 路径：首次构建落盘，
                       增量重建沿用旧映射，保证多次迭代后跨文件符号名兼容

界面
      --format=        text|json（json 便于 GUI/脚本解析）
      --verbose        详细日志
      --silence        静默
  -h, --help           显示本帮助
      --info           显示工具信息
HELP;
}

// ============ 主流程 ============
$args = obf_parse_args($argv);

if (isset($args['help']) || isset($args['h'])) {
    echo obf_help() . "\n";
    exit(0);
}
if (isset($args['info'])) {
    echo json_encode([
        'name' => OBFUSCATOR_NAME,
        'version' => OBFUSCATOR_VERSION,
        'author' => OBFUSCATOR_AUTHOR,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// ============ 配置加载：等级预设 / 分层 JSON / 散装参数 ============
// 优先级：--level 预设 或 --config 分层 JSON（二者互斥，分层 JSON 完全替换 layers）
//         -> 普通 JSON 配置（标量覆盖） -> 纯散装 CLI 参数
// CLI 标量覆盖项（除去 level/config 等非配置键；input/output 是合法配置键，
// 允许命令行覆盖 JSON 中的取值）在预设/分层/JSON 配置之上叠加。
$LEVEL_NON_OVERRIDE = ['level', 'config'];
$cliOverrides = array_diff_key($args, array_flip($LEVEL_NON_OVERRIDE));
try {
    if (!empty($args['config']) && is_file($args['config'])) {
        $cfgPath = $args['config'];
        if (str_ends_with(strtolower($cfgPath), '.php')) {
            // PHP 数组配置：文件需 return 一个数组（可直接用 PHP 表达式 / 注释，便于维护）
            $config = Config::fromPhpFile($cfgPath);
            if ($cliOverrides !== []) {
                $config = new Config(array_merge($config->all(), $cliOverrides));
            }
        } else {
            $raw = json_decode((string) file_get_contents($cfgPath), true);
            if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("JSON 配置文件解析失败: " . json_last_error_msg());
            }
            if (is_array($raw) && isset($raw['layers'])) {
                // 分层 JSON：完全替换 layers；CLI 标量覆盖项叠加到顶层标量键
                $config = Config::fromLayeredJson($cfgPath);
                if ($cliOverrides !== []) {
                    $config = new Config(array_merge($config->all(), $cliOverrides));
                }
            } else {
                // 普通 JSON 配置：文件提供基础配置，CLI 标量覆盖项叠加。
                // 与分层 JSON / 预设路径保持一致，只叠加 $cliOverrides
                // （已剔除 level/config 等非配置键）—— 早期实现直接
                // array_merge($fileCfg, $args)，会把 --config 的路径本身、
                // --level 等原始 CLI 键混进配置数组，语义脆弱。
                $fileCfg = Config::fromJsonFile($cfgPath)->all();
                $config = new Config(array_merge($fileCfg, $cliOverrides));
            }
        }
    } elseif (!empty($args['level'])) {
        // 等级预设：CLI 标量覆盖项叠加在预设之上
        $config = Config::fromPreset((string) $args['level'], $cliOverrides);
    } else {
        $config = Config::fromCli($args);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "配置加载失败: " . $e->getMessage() . "\n");
    exit(1);
}
$errors = $config->validate();
if (!empty($errors)) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

$mode = $config->get('format') === 'json' ? 'json' : 'text';
$level = $config->get('silence') ? Logger::LEVEL_ERROR
    : ($config->get('verbose') ? Logger::LEVEL_DEBUG : Logger::LEVEL_INFO);
$logger = new Logger($level, $mode);

$salt = new Salt((string) $config->get('salt'));
$obfuscator = new Obfuscator($config, $salt, $logger, OBFUSCATOR_NAME, OBFUSCATOR_VERSION);

$input = $config->get('input');
$output = $config->get('output');

if (is_dir($input)) {
    if (empty($output)) {
        $logger->error('目录混淆需要指定 --output 目录');
        exit(1);
    }
    // 整套系统模式：两遍扫描 + 全局符号表 + manifest，跨文件调用与同名符号全系统一致
    $builder = new ProjectBuilder($config, $salt, $logger, OBFUSCATOR_NAME, OBFUSCATOR_VERSION);
    $res = $builder->build($input, $output, $config->get('manifest') ?: null);
    $logger->info("目录混淆完成：成功 {$res['files']} 个，失败 {$res['failed']} 个（函数 {$res['funcs']}，类 {$res['classes']}）");
    // OPcache 辅助编译（后处理：目录模式下对每个产物 .php 编译为 Zend 字节码；环境不支持时自动跳过）
    if ($config->get('opcache')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                OpcacheCompiler::compile($f->getPathname(), $logger);
            }
        }
    }
    if ($mode === 'json') {
        echo json_encode(['ok' => true, 'files' => $res['files'], 'failed' => $res['failed'], 'funcs' => $res['funcs'], 'classes' => $res['classes']], JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(0);
}

if (!is_file($input)) {
    $logger->error("输入文件不存在: {$input}");
    exit(1);
}
$src = file_get_contents($input);
// 登记主文件路径：供 VM 的 include 解析（baseDir）与 __DIR__/__FILE__ 编译期折值
$obfuscator->setSourceFile($input);
$result = $obfuscator->run($src);

if ($output !== null && $output !== '') {
    file_put_contents($output, $result);
    $logger->info("已写入: {$output}");
    // OPcache 辅助编译（后处理：将产物编译为 Zend 字节码；环境不支持时自动跳过，不阻断主流程）
    if ($config->get('opcache')) {
        OpcacheCompiler::compile($output, $logger);
    }
} else {
    // 无输出文件则直接打印到 stdout
    echo $result;
}

if ($mode === 'json') {
    echo json_encode(['ok' => true, 'length' => strlen($result)], JSON_UNESCAPED_UNICODE) . "\n";
}
exit(0);
