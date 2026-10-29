<?php
/**
 * 工程构建器（ProjectBuilder）—— 整套系统模式
 *
 * 单文件逐个混淆时存在两个跨文件兼容缺陷（均已在设计上修复）：
 *   a) 碰撞回退各自为政：A 文件撞名退化成 name#1、B 文件没撞用 name，同一符号两副面孔；
 *   b) 声明/调用不对称：B 文件调用 A 文件声明的函数，B 的调用点不在映射内，
 *      不会被重命名，而 A 的声明已改名 —— 运行即崩。
 *
 * 本构建器对整个目录采用"两遍扫描"：
 *   Pass 1  解析全部 PHP 文件，收集所有函数/类声明 + 所有以字符串字面量出现的名字
 *           （动态调用符号，改名必断链，整体排除）；
 *           用同一 salt 生成一张全局符号映射（碰撞回退全局一致）；
 *   Pass 2  逐文件混淆，每个文件的重命名都查同一张全局映射 ——
 *           跨文件调用天然对齐，同名符号全系统一个名字。
 *
 * manifest：构建后把 salt + 全局符号映射落盘为 JSON。下次构建（增量改动后）读入并沿用
 * 旧映射，只给新增符号起新名字 —— 保证多次迭代后整套系统的符号名依然互相兼容。
 */

declare(strict_types=1);

final class ProjectBuilder
{
    public function __construct(
        private Config $config,
        private Salt $salt,
        private Logger $logger,
        private string $toolName,
        private string $toolVersion
    ) {
    }

    /**
     * 构建整个工程。
     *
     * @param string      $srcDir       源码目录（递归）
     * @param string      $outDir       输出目录
     * @param string|null $manifestPath manifest JSON 路径（null = 不读写）
     * @return array{files:int,failed:int,funcs:int,classes:int}
     */
    public function build(string $srcDir, string $outDir, ?string $manifestPath = null): array
    {
        $srcDir = rtrim($srcDir, '/\\');
        $outDir = rtrim($outDir, '/\\');
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        $files = $this->collect($srcDir, $outDir);
        if ($files === []) {
            $this->logger->warn('工程目录中未找到 PHP 文件');
            return ['files' => 0, 'failed' => 0, 'funcs' => 0, 'classes' => 0];
        }

        $table = new SymbolTable();
        // manifest 复用：增量重建沿用旧映射，保持多次迭代间符号名兼容
        // （version 2 = 类名混淆 v2 的 FQCN 键；旧版短名键不可混用，忽略重建）
        if ($manifestPath !== null && is_file($manifestPath)) {
            try {
                $m = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                if ((int) ($m['version'] ?? 0) >= 2 && isset($m['symbols']) && is_array($m['symbols'])) {
                    $table = SymbolTable::fromArray($m['symbols']);
                    $this->logger->info('已载入 manifest，沿用既有符号映射');
                } else {
                    $this->logger->info('manifest 版本过旧（或缺失），重建符号表');
                }
            } catch (Throwable $e) {
                $this->logger->warn('manifest 读取失败，将重建符号表：' . $e->getMessage());
            }
        }

        // ---- Pass 1：全量收集声明符号 + 动态调用符号 ----
        $collectorF = new FunctionObfuscator($this->salt, $this->logger, $table);
        $collectorC = new ClassObfuscator($this->salt, $this->logger, $table);
        $dynamic = [];
        $declByFile = []; // rel => list<FQCN>（供 PSR-4 文件重命名）
        foreach ($files as $rel => $abs) {
            $tokens = Parser::tokenize((string) file_get_contents($abs));
            foreach (array_keys($collectorF->collectDeclared($tokens)) as $n) {
                $table->addFunction($n);
            }
            $declC = array_keys($collectorC->collectDeclared($tokens));
            foreach ($declC as $n) {
                $table->addClass($n);
            }
            $declByFile[$rel] = $declC;
            foreach (array_keys(DynamicSymbols::fromTokens($tokens)) as $lit) {
                $dynamic[$lit] = true;
            }
        }
        $table->removeDynamic($dynamic);
        $table->build($this->salt, (int) $this->config->get('func_length'), 10);
        $this->logger->info('全局符号表：函数 ' . count($table->funcMap()) . ' 个，类/接口 ' . count($table->classMap()) . ' 个（动态引用排除 ' . count($dynamic) . ' 个候选）');

        // ---- Pass 2：统一映射混淆（文件路径保持原样，不改名）----
        $obf = new Obfuscator($this->config, $this->salt, $this->logger, $this->toolName, $this->toolVersion, $table);
        $ok = 0;
        $fail = 0;
        $classMap = $table->classMap();
        $autoloadName = 'awaphp_autoload.php';
        // 类映射自动加载器：类改名后 PSR-4 按「命名空间 + 新短名」找不到原文件，
        // 同时外部动态类名（路由字符串 / 容器绑定）仍按原名查找 —— 两个名字都映射到原文件，
        // 文件内 class_alias 回挂把原名接到新类上。仅工程模式生成（跨文件改名才需要）。
        $fileMap = [];   // FQCN（新名与原名）=> rel 路径
        foreach ($declByFile as $rel => $fqcns) {
            foreach ($fqcns as $fqcn) {
                $fqcn = (string) $fqcn;
                $newShort = $classMap[$fqcn] ?? null;
                if ($newShort === null || $newShort === ClassObfuscator::shortOf($fqcn)) {
                    continue; // 未改名：PSR-4 原样命中，无需映射
                }
                $ns = str_contains($fqcn, '\\') ? substr($fqcn, 0, (int) strrpos($fqcn, '\\')) : '';
                $newFqcn = $ns !== '' ? $ns . '\\' . $newShort : $newShort;
                $fileMap[$newFqcn] = $rel;
                $fileMap[$fqcn] = $rel;
            }
        }
        if ($fileMap !== []) {
            $this->writeAutoloadMap($outDir . '/' . $autoloadName, $fileMap);
        }
        foreach ($files as $rel => $abs) {
            $dest = $outDir . '/' . $rel;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            try {
                // 登记主文件路径：供 VM include 解析（baseDir）与 __DIR__/__FILE__ 折值
                $obf->setSourceFile($abs);
                $res = $obf->run((string) file_get_contents($abs));
                if ($fileMap !== []) {
                    $res = $this->injectAutoloadRequire($res, $rel, $autoloadName);
                }
                file_put_contents($dest, $res);
                $ok++;
                $this->logger->info("已混淆: {$rel}");
            } catch (Throwable $e) {
                $fail++;
                $this->logger->warn("混淆失败 {$rel}: " . $e->getMessage());
            }
        }

        // ---- manifest 沉淀 ----
        if ($manifestPath !== null) {
            file_put_contents($manifestPath, json_encode([
                'version'      => 2,
                'tool'         => $this->toolName,
                'tool_version' => $this->toolVersion,
                'salt'         => $this->salt->getSalt(),
                'generated_at' => date('c'),
                'files'        => ['ok' => $ok, 'failed' => $fail],
                'symbols'      => $table->toArray(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->logger->info("manifest 已写入: {$manifestPath}");
        }

        return ['files' => $ok, 'failed' => $fail, 'funcs' => count($table->funcMap()), 'classes' => count($table->classMap())];
    }

    /**
     * 落盘类映射自动加载器：新名与原名都映射到原文件路径（文件不改名），
     * require 之后由文件内 class_alias 把原名接到新类上。
     *
     * @param array<string,string> $fileMap FQCN（新名 + 原名）=> rel 路径
     */
    private function writeAutoloadMap(string $path, array $fileMap): void
    {
        ksort($fileMap, SORT_STRING);
        $rows = [];
        foreach ($fileMap as $fqcn => $rel) {
            $rows[] = '        ' . var_export((string) $fqcn, true) . ' => ' . var_export(str_replace('\\', '/', (string) $rel), true) . ',';
        }
        $code = "<?php\n"
            . "// 嗷呜awaのphp混淆 · 类映射自动加载器（构建自动生成，请勿手改）\n"
            . "// 类名混淆后 PSR-4 按「命名空间 + 新短名」找不到原文件（文件名保持原样），\n"
            . "// 这里把新名/原名都映射到原文件；文件内 class_alias 负责原名到新类的衔接。\n"
            . "spl_autoload_register(function (\$__c) {\n"
            . "    static \$__m = [\n"
            . implode("\n", $rows) . "\n"
            . "    ];\n"
            . "    if (isset(\$__m[\$__c])) {\n"
            . "        require_once __DIR__ . '/' . \$__m[\$__c];\n"
            . "    }\n"
            . "});\n";
        file_put_contents($path, $code);
        $this->logger->info('类映射自动加载器已写入: ' . $path . '（' . count($fileMap) . ' 条映射）');
    }

    /**
     * 在产物头部注入 `require_once` 类映射自动加载器。
     * 注入点由 DeclareHoist::headInsertIndex 给出：declare/namespace 之后、
     * 括号式 namespace 时落在块内第一条语句位——不会把 declare/namespace 挤成非首语句，
     * 也早于文件内任何类声明的链接（extends/接口的跨文件自动加载需要先注册）。
     */
    private function injectAutoloadRequire(string $res, string $rel, string $autoloadName): string
    {
        $tokens = token_get_all($res);
        $idx = DeclareHoist::headInsertIndex($tokens);
        if ($idx < 0) {
            return $res; // 无开标签的片段流：不注入
        }
        $dir = str_replace('\\', '/', dirname($rel));
        $depth = ($dir === '.' || $dir === '') ? 0 : substr_count($dir, '/') + 1;
        $prefix = str_repeat('../', $depth);
        $line = "require_once __DIR__ . '/{$prefix}{$autoloadName}';";
        array_splice($tokens, $idx, 0, [$line]);
        return implode('', array_map(static fn($t): string => is_array($t) ? (string) $t[1] : (string) $t, $tokens));
    }

    /** @return array<string,string> rel => abs（按相对路径排序，保证可复现） */
    private function collect(string $srcDir, string $outDir): array
    {
        $out = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $abs = $f->getPathname();
            // 输出目录位于输入目录内时，跳过输出侧产物
            if ($outDir !== '' && str_starts_with($abs, $outDir . '/')) {
                continue;
            }
            $rel = ltrim(substr($abs, strlen($srcDir)), '/\\');
            $out[$rel] = $abs;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    // 字符串字面量收集已统一到 DynamicSymbols::fromTokens()（含双引号插值片段），
    // 单文件模式与工程模式共用同一套"动态符号保守排除"策略，避免行为分叉。
}
