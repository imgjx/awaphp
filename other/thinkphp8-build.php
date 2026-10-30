<?php
/**
 * ThinkPHP 8 一键混淆构建（thinkphp8-build.php）
 * ============================================================
 * 干一件事：**自动遍历项目 → 生成白名单 → 调混淆器构建**，一条命令出可部署目录。
 *
 * 为什么需要它：
 *   ThinkPHP 应用里有一批「按名字被框架调用」的符号——控制器 public 动作方法（路由调度）、
 *   应用类（PSR-4 自动加载）、全局助手函数、常量。手工维护白名单既繁琐又容易漏，
 *   漏一个就是上线后 500。本脚本用 tokenizer 静态扫描项目，自动把它们收进白名单。
 *
 * 用法：
 *   php other/thinkphp8-build.php                          # 自动在当前目录向上找 TP8 项目
 *   php other/thinkphp8-build.php --project=/var/www/shop
 *   php other/thinkphp8-build.php --project=. --output=../shop_obf --salt=team-salt-2026
 *   php other/thinkphp8-build.php --project=. --dry-run    # 只扫描并打印白名单，不构建
 *   php other/thinkphp8-build.php --project=. --app-only    # 只混淆 app/（快速迭代）
 *
 * 参数：
 *   --project=PATH   项目根目录（含 app/ 与 think / composer.json）。默认从 CWD 逐级向上探测。
 *   --output=PATH    输出目录。默认 <project>_obf（与项目同级）。
 *   --salt=STR       混淆盐。省略则随机生成并打印（同盐可复现构建）。
 *   --config=PATH    基础配置，默认 other/thinkphp8.config.php（分层 high + 白名单 + 框架兼容项）。
 *   --scan=DIR[,DIR] 追加扫描目录（相对项目根），默认仅 app。
 *   --app-only       只把 app/ 作为输入（输出不含 public/vendor，需自行套用）。
 *   --dry-run        只扫描 + 打印白名单，不构建。
 *   --keep-config    保留生成的临时配置（排查用）。
 *   --php=BIN        指定 PHP 可执行文件（默认当前 php）。
 *
 * 扫描产出（合并进基础配置的 whitelist）：
 *   classes/interfaces/traits —— app 下声明的全部类型（FQCN）
 *   functions                 —— app 下声明的全部全局函数
 *   constants                 —— app 下声明的顶层 const
 *   methods                   —— 控制器类的 public 方法（路由按名调度，改名即 404/500）
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本仅支持命令行运行。\n");
    exit(1);
}

// ============================================================
// 0) 参数解析
// ============================================================
$opt = [
    'project' => null,
    'output'  => null,
    'salt'    => null,
    'config'  => null,
    'scan'    => [],
    'app-only' => false,
    'dry-run' => false,
    'keep-config' => false,
    'php'     => PHP_BINARY,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo usage();
        exit(0);
    }
    if (!str_starts_with($arg, '--')) {
        fwrite(STDERR, "无法识别的参数：{$arg}\n" . usage());
        exit(1);
    }
    $body = substr($arg, 2);
    $val = null;
    if (str_contains($body, '=')) {
        [$body, $val] = explode('=', $body, 2);
    }
    switch ($body) {
        case 'project':
        case 'output':
        case 'salt':
        case 'config':
        case 'php':
            $opt[$body] = (string) $val;
            break;
        case 'scan':
            $opt['scan'] = array_values(array_filter(array_map('trim', explode(',', (string) $val))));
            break;
        case 'app-only':
        case 'dry-run':
        case 'keep-config':
            $opt[$body] = true;
            break;
        default:
            fwrite(STDERR, "未知参数：--{$body}\n" . usage());
            exit(1);
    }
}

function usage(): string
{
    return <<<TXT
ThinkPHP 8 一键混淆构建

  php other/thinkphp8-build.php [选项]

  --project=PATH    项目根目录（含 app/ 与 think 或 composer.json）；默认从当前目录向上探测
  --output=PATH     输出目录；默认 <project>_obf
  --salt=STR        混淆盐；省略则随机生成（同盐可复现）
  --config=PATH     基础配置；默认 other/thinkphp8.config.php
  --scan=DIR[,DIR]  追加扫描目录（相对项目根），默认只扫 app
  --app-only        只以 app/ 为输入（快速迭代；输出不含 public/vendor）
  --dry-run         只扫描并打印白名单，不构建
  --keep-config     保留生成的临时配置
  --php=BIN         指定 PHP 可执行文件

TXT;
}

// ============================================================
// 1) 定位项目根
// ============================================================
function isTp8Root(string $dir): bool
{
    if (!is_dir($dir . '/app')) {
        return false;
    }
    return is_file($dir . '/think')
        || is_file($dir . '/composer.json')
        || is_dir($dir . '/vendor/topthink');
}

function detectProject(?string $hint): ?string
{
    if ($hint !== null) {
        $p = realpath($hint);
        return ($p !== false && isTp8Root($p)) ? $p : null;
    }
    $dir = getcwd() ?: '.';
    for ($i = 0; $i < 8; $i++) {
        $p = realpath($dir);
        if ($p === false) {
            break;
        }
        if (isTp8Root($p)) {
            return $p;
        }
        $parent = dirname($p);
        if ($parent === $p) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

$project = detectProject($opt['project']);
if ($project === null) {
    fwrite(STDERR, "✗ 没能定位 ThinkPHP 项目根（需要含 app/ 且含 think 或 composer.json）。\n"
        . "  请显式指定：--project=/path/to/tp8\n");
    exit(1);
}

$root = dirname(__DIR__); // 混淆器仓库根
$baseConfig = $opt['config'] !== null
    ? (realpath($opt['config']) ?: $opt['config'])
    : $root . '/other/thinkphp8.config.php';
if (!is_file($baseConfig)) {
    fwrite(STDERR, "✗ 基础配置不存在：{$baseConfig}\n");
    exit(1);
}

$inputDir = $opt['app-only'] ? $project . '/app' : $project;
$outDir = $opt['output'] !== null ? $opt['output'] : dirname($project) . '/' . basename($project) . '_obf';
$salt = $opt['salt'];
if ($salt === null || $salt === '') {
    $salt = 'tp8-' . bin2hex(random_bytes(6));
    $saltGenerated = true;
} else {
    $saltGenerated = false;
}

// ============================================================
// 2) 扫描目录 → 收集文件
// ============================================================
function scanPhpFiles(string $dir): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        $files[] = $f->getPathname();
    }
    sort($files);
    return $files;
}

$scanDirs = ['app'];
foreach ($opt['scan'] as $d) {
    $d = trim($d, "/\\ ");
    if ($d !== '' && !in_array($d, $scanDirs, true)) {
        $scanDirs[] = $d;
    }
}

$files = [];
foreach ($scanDirs as $d) {
    foreach (scanPhpFiles($project . '/' . $d) as $f) {
        $files[$f] = true;
    }
}
$files = array_keys($files);
sort($files);

if ($files === []) {
    fwrite(STDERR, "✗ 在 " . implode(', ', $scanDirs) . " 下没找到任何 .php 文件（项目：{$project}）\n");
    exit(1);
}

// ============================================================
// 3) 用 tokenizer 静态解析符号
// ============================================================
/**
 * @param string[] $files
 * @return array{classes:string[],interfaces:string[],traits:string[],enums:string[],functions:string[],constants:string[],methods:string[]}
 */
function analyzeFiles(array $files): array
{
    $types = ['class' => [], 'interface' => [], 'trait' => [], 'enum' => []];
    $functions = [];
    $constants = [];
    $methods = [];

    foreach ($files as $file) {
        $code = @file_get_contents($file);
        if ($code === false || !str_contains($code, '<?')) {
            continue;
        }
        try {
            $tokens = token_get_all($code);
        } catch (Throwable) {
            continue;
        }

        $ns = '';
        $depth = 0;              // 花括号深度
        $classStack = [];        // [ [fqcn, braceDepth, shortName] ]
        $pendingClass = null;    // 正在等待 '{' 的类型声明
        $pendingFunc = null;     // 'function' 之后待捕获的名字
        $funcIsMethod = false;
        $pendingConst = false;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;

            // 命名空间
            if ($id === T_NAMESPACE) {
                $name = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $tj = $tokens[$j];
                    $idt = is_array($tj) ? $tj[0] : null;
                    if ($idt === T_STRING || $idt === T_NAME_QUALIFIED || $idt === T_NAME_FULLY_QUALIFIED) {
                        $name .= $tj[1];
                    } elseif ($tj === ';' || $tj === '{') {
                        break;
                    } elseif ($idt === T_WHITESPACE) {
                        continue;
                    } else {
                        break;
                    }
                }
                $ns = trim($name, '\\');
                continue;
            }

            // 类型声明
            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT
                || (defined('T_ENUM') && $id === T_ENUM)) {
                // 跳过 `Foo::class` 与匿名类 `new class{...}`
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev])
                    && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                    continue;
                }
                $kind = strtolower($text);
                // 找名字
                $name = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    $tj = $tokens[$j];
                    if (is_array($tj) && $tj[0] === T_STRING) {
                        $name = $tj[1];
                        break;
                    }
                    if ($tj === '{' || $tj === '(') {
                        break; // 匿名类
                    }
                }
                if ($name === null) {
                    continue;
                }
                $fqcn = $ns !== '' ? $ns . '\\' . $name : $name;
                $key = $kind === 'interface' ? 'interface' : ($kind === 'trait' ? 'trait' : ($kind === 'enum' ? 'enum' : 'class'));
                $types[$key][$fqcn] = true;
                $pendingClass = [$fqcn, $name, $kind];
                continue;
            }

            // 函数 / 方法
            if ($id === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }
                $nameTok = $tokens[$j] ?? null;
                if (is_array($nameTok) && $nameTok[0] === T_STRING) {
                    $pendingFunc = $nameTok[1];
                    $funcIsMethod = $classStack !== [];
                    // 跳过名字 token，下一次迭代即遇到 '(' ——在那里记账
                    $i = $j;
                } else {
                    $pendingFunc = null; // 闭包 / 箭头函数
                }
                continue;
            }

            // 顶层 const
            if ($id === T_CONST) {
                $pendingConst = $classStack === [];
                continue;
            }
            if ($pendingConst && is_array($t) && $t[0] === T_STRING) {
                $constants[$t[1]] = true;
                $pendingConst = false;
                continue;
            }

            // 花括号深度
            if ($text === '{') {
                $depth++;
                if ($pendingClass !== null) {
                    [$fqcn, $short, $kind] = $pendingClass;
                    $classStack[] = [$fqcn, $depth, $short, $kind];
                    $pendingClass = null;
                }
                continue;
            }
            if ($text === '}') {
                if ($classStack !== []) {
                    $top = end($classStack);
                    if ($top[1] === $depth) {
                        array_pop($classStack);
                    }
                }
                $depth--;
                continue;
            }

            // 方法名：进入函数体前的 '(' 之前已捕获名字，这里按「函数声明 + 所属类型」记账
            if ($pendingFunc !== null && $text === '(') {
                $name = $pendingFunc;
                $pendingFunc = null;
                if ($funcIsMethod && $classStack !== []) {
                    $top = end($classStack);
                    $fqcn = $top[0];
                    $short = $top[2];
                    // 控制器动作方法：路由按名调度；__开头的魔术方法同样不可改名
                    if (isControllerClass($fqcn, $short) || str_starts_with($name, '__')) {
                        $methods[$name] = true;
                    }
                    // 类内方法本身不改名（method 层默认关闭），此处仅收集必要项
                } else {
                    $functions[$name] = true;
                }
                continue;
            }
        }
    }

    return [
        'classes'    => array_keys($types['class']),
        'interfaces' => array_keys($types['interface']),
        'traits'     => array_keys($types['trait']),
        'enums'      => array_keys($types['enum']),
        'functions'  => array_keys($functions),
        'constants'  => array_keys($constants),
        'methods'    => array_keys($methods),
    ];
}

/** 控制器判定：命名空间在 controller 下，或短名以 Controller 结尾（TP8 常见两种写法） */
function isControllerClass(string $fqcn, string $short): bool
{
    $fqcn = strtolower($fqcn);
    if (str_contains($fqcn, '\\controller\\') || str_starts_with($fqcn, 'controller\\')) {
        return true;
    }
    return str_ends_with(strtolower($short), 'controller');
}

$t0 = hrtime(true);
$symbols = analyzeFiles($files);
$scanMs = (hrtime(true) - $t0) / 1e6;

// ============================================================
// 4) 合并白名单 → 生成临时配置
// ============================================================
$cfg = require $baseConfig;
if (!is_array($cfg)) {
    fwrite(STDERR, "✗ 基础配置未返回数组：{$baseConfig}\n");
    exit(1);
}
$wl = is_array($cfg['whitelist'] ?? null) ? $cfg['whitelist'] : [];

$merge = static function (array $wl, string $key, array $add): array {
    $cur = is_array($wl[$key] ?? null) ? $wl[$key] : [];
    $wl[$key] = array_values(array_unique(array_merge($cur, $add)));
    return $wl;
};

$classesForWl = array_merge($symbols['classes'], $symbols['interfaces'], $symbols['traits'], $symbols['enums']);
$wl = $merge($wl, 'classes', $classesForWl);
$wl = $merge($wl, 'functions', $symbols['functions']);
$wl = $merge($wl, 'constants', $symbols['constants']);
$wl = $merge($wl, 'methods', $symbols['methods']);
// 类型分类也各填一份，便于人工核对（ClassObfuscator 会合并四类）
$wl = $merge($wl, 'interfaces', $symbols['interfaces']);
$wl = $merge($wl, 'traits', $symbols['traits']);
$cfg['whitelist'] = $wl;

$report = [
    'project'   => $project,
    'input'     => $inputDir,
    'output'    => $outDir,
    'salt'      => $salt,
    'scanned'   => count($files),
    'scan_dirs' => $scanDirs,
    'scan_ms'   => $scanMs,
    'symbols'   => $symbols,
];

if ($opt['dry-run']) {
    echo "=== 扫描结果（dry-run，不构建）===\n";
    printf("项目：%s\n扫描：%s（%d 个 .php，耗时 %.0f ms）\n\n",
        $project, implode(', ', $scanDirs), count($files), $scanMs);
    printf("类 %d / 接口 %d / trait %d / enum %d / 全局函数 %d / 常量 %d / 控制器 public 方法 %d\n\n",
        count($symbols['classes']), count($symbols['interfaces']), count($symbols['traits']),
        count($symbols['enums']), count($symbols['functions']), count($symbols['constants']),
        count($symbols['methods']));
    foreach (['classes' => '类', 'interfaces' => '接口', 'traits' => 'trait', 'enums' => 'enum',
              'functions' => '全局函数', 'constants' => '常量', 'methods' => '方法'] as $k => $label) {
        if ($symbols[$k] === []) {
            continue;
        }
        echo "--- {$label} ---\n";
        foreach ($symbols[$k] as $s) {
            echo "  {$s}\n";
        }
        echo "\n";
    }
    exit(0);
}

$tmpCfg = sys_get_temp_dir() . '/obf-tp8-' . getmypid() . '.php';
$exported = "<?php\n// 由 other/thinkphp8-build.php 自动生成（项目：" . $project . "，生成于 "
    . date('Y-m-d H:i:s') . "）\nreturn " . var_export($cfg, true) . ";\n";
if (@file_put_contents($tmpCfg, $exported) === false) {
    fwrite(STDERR, "✗ 无法写入临时配置：{$tmpCfg}\n");
    exit(1);
}

// ============================================================
// 5) 调用混淆器
// ============================================================
$cmd = [
    $opt['php'],
    $root . '/entry.php',
    '--config=' . $tmpCfg,
    '--input=' . $inputDir,
    '--output=' . $outDir,
    '--salt=' . $salt,
    '--manifest=' . rtrim($outDir, '/\\') . '/.obf-manifest.json',
];

echo "=== ThinkPHP8 一键混淆构建 ===\n";
printf("项目    : %s\n", $project);
printf("输入    : %s\n", $inputDir);
printf("输出    : %s\n", $outDir);
printf("盐      : %s%s\n", $salt, $saltGenerated ? '（自动生成，请记录以便复现）' : '');
printf("扫描    : %s → %d 个 .php，%.0f ms\n", implode(', ', $scanDirs), count($files), $scanMs);
printf("白名单  : 类 %d / 全局函数 %d / 常量 %d / 控制器方法 %d\n",
    count($classesForWl), count($symbols['functions']), count($symbols['constants']), count($symbols['methods']));
echo "构建中...\n\n";

$t1 = hrtime(true);
$out = [];
$code = 0;
exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $out, $code);
$buildMs = (hrtime(true) - $t1) / 1e6;

$log = implode("\n", $out);
echo $log, "\n";

if (!$opt['keep-config']) {
    @unlink($tmpCfg);
}

if ($code !== 0) {
    fwrite(STDERR, "\n✗ 构建失败（exit={$code}）。临时配置" . ($opt['keep-config'] ? "保留在 {$tmpCfg}" : "已删除（可加 --keep-config 保留）") . "\n");
    exit($code);
}

// ============================================================
// 6) 摘要
// ============================================================
$countFiles = 0;
$countBytes = 0;
if (is_dir($outDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $countFiles++;
            $countBytes += $f->getSize();
        }
    }
}

echo "\n=== 完成 ===\n";
printf("耗时    : %.1f s（扫描 %.0f ms + 构建 %.1f s）\n", ($scanMs + $buildMs) / 1000, $scanMs, $buildMs / 1000);
printf("产物    : %s（%d 个文件，%s）\n", $outDir, $countFiles, humanBytes($countBytes));

function humanBytes(int $b): string
{
    if ($b >= 1073741824) {
        return number_format($b / 1073741824, 2) . ' GB';
    }
    if ($b >= 1048576) {
        return number_format($b / 1048576, 2) . ' MB';
    }
    if ($b >= 1024) {
        return number_format($b / 1024, 1) . ' KB';
    }
    return $b . ' B';
}

if (!$opt['app-only']) {
    echo "\n下一步：把 Web 根指向 <输出>/public，例如 nginx：\n";
    echo "  root " . rtrim($outDir, '/') . "/public;\n";
} else {
    echo "\n注意：--app-only 只产出了 app/ 的混淆副本，需自行放回完整项目（vendor/public 等原样保留）。\n";
}
echo "同一盐重跑可复现构建：--salt={$salt}\n";
