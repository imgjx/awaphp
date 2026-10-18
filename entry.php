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
define('OBFUSCATOR_VERSION', '0.1.1');
define('OBFUSCATOR_AUTHOR',  'awa');

// ============ 加载核心库 ============
$root = __DIR__;
foreach ([
    'src/Salt.php',
    'src/SemanticRewriter.php',
    'src/Support/Random.php',
    'src/Support/NameGenerator.php',
    'src/Support/NameStyle.php',
    'src/Support/DynamicSymbols.php',
    'src/Logger.php',
    'src/Config.php',
    'src/Parser.php',
    'src/Validator.php',
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
    static $LEVEL_FLAGS = ['-low' => 'low', '-mid' => 'mid', '-high' => 'high', '-max' => 'max'];
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if (isset($LEVEL_FLAGS[$a])) {
            $out['level'] = $LEVEL_FLAGS[$a];
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
  -c, --config=       指定 JSON 配置文件（普通标量配置 / 分层 JSON 均可）
      --level=        等级预设：low|mid|high|max（一键切换完整分层方案）
  -low -mid -high -max  等价 --level=<name> 的短旗标
                        （等级仅作默认，散装参数如 --level high --junk_intensity 10 可叠加覆盖）
      --salt=         混淆盐（空=不加盐，相同盐可复现）
      --repeat=        重复加密次数（默认 1）

变量 / 函数 / 类
      --var-length=    变量名混淆后长度
      --var-split=     变量值拆分段数（>1 生效）
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
      --vm-linker=     ModuleLinker 模块目录（JSON 清单）：命中清单的函数调用
                       内联进同一 Program，产物中无模块路径、无 include 指令
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
      --eval           EVAL 多层加壳
      --eval-layers=   EVAL 加壳层数（默认 3）
      --anti-debug     反调试探针（检测 phpdbg / xdebug 等）
      --anti-hook      防 hook eval（eval 完整性探针 + uopz/runkit/phpdbg 检测）
      --anti-tamper    自校验防篡改（掩码自校验 + 分段哈希 + shutdown 二次校验）
      --php-version=   目标 PHP 版本：7.4|8.0|8.1|8.2|8.3
      --junk-funcs=    诱饵函数数量（>0 生效，拟真命名，干扰逆向）

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
// CLI 标量覆盖项（除去 level/config/input/output 等非配置键）在预设/分层之上叠加。
$LEVEL_NON_OVERRIDE = ['level', 'config'];
$cliOverrides = array_diff_key($args, array_flip($LEVEL_NON_OVERRIDE));
try {
    if (!empty($args['config']) && is_file($args['config'])) {
        $raw = json_decode((string) file_get_contents($args['config']), true);
        if (is_array($raw) && isset($raw['layers'])) {
            // 分层 JSON：完全替换 layers；CLI 标量覆盖项叠加到顶层标量键
            $config = Config::fromLayeredJson($args['config']);
            if ($cliOverrides !== []) {
                $config = new Config(array_merge($config->all(), $cliOverrides));
            }
        } else {
            $fileCfg = Config::fromJsonFile($args['config'])->all();
            $configOverrides = array_merge($fileCfg, $args); // 命令行覆盖文件
            $config = new Config($configOverrides);
        }
    } elseif (!empty($args['level'])) {
        // 等级预设：CLI 标量覆盖项叠加在预设之上
        $config = Config::fromPreset((string) $args['level'], $cliOverrides);
    } else {
        $config = new Config($args);
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
} else {
    // 无输出文件则直接打印到 stdout
    echo $result;
}

if ($mode === 'json') {
    echo json_encode(['ok' => true, 'length' => strlen($result)], JSON_UNESCAPED_UNICODE) . "\n";
}
exit(0);
