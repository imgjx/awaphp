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
        private string $toolVersion,
        private ?BuildReport $report = null
    ) {
        // v1.1.8：与 Obfuscator 一致——未指定盐时用随机盐兜底，走确定性派生主路径
        if ($this->salt->isEmpty()) {
            $this->salt = Salt::random();
        }
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
        foreach ($files as $rel => $abs) {
            $tokens = Parser::tokenize((string) file_get_contents($abs));
            foreach (array_keys($collectorF->collectDeclared($tokens)) as $n) {
                $table->addFunction($n);
            }
            foreach (array_keys($collectorC->collectDeclared($tokens)) as $n) {
                $table->addClass($n);
            }
            foreach (array_keys(DynamicSymbols::fromTokens($tokens)) as $lit) {
                $dynamic[$lit] = true;
            }
        }
        $table->removeDynamic($dynamic);
        $table->build($this->salt, (int) $this->config->get('func_length'), 10);
        $this->logger->info('全局符号表：函数 ' . count($table->funcMap()) . ' 个，类/接口 ' . count($table->classMap()) . ' 个（动态引用排除 ' . count($dynamic) . ' 个候选）');

        // ---- Pass 2：统一映射混淆（文件路径保持原样，不改名）----
        // 工程模式不再生成类映射自动加载器（awaphp_autoload.php）：
        // 类名混淆会与 PSR-4「命名空间 + 文件名」约定冲突（改名后框架按原名找不到类），
        // 而自动加载器方案已废弃。改为【工程模式强制保留类名原名】，框架（ThinkPHP8 等）
        // 按命名空间/文件名自动加载照常工作；需要排除个别符号时用白名单（见下）。
        // 白名单：整目录 / 整文件跳过（原样拷贝，不参与混淆）
        $wl = $this->config->get('whitelist');
        $wlDirs = $wl['dirs'] ?? [];
        $wlFiles = $wl['files'] ?? [];
        $skip = $this->whitelistSkipSet($files, $wlDirs, $wlFiles);

        // 白名单：保留指定函数 / 类名（跨文件保持原名）
        $wlFuncs = $wl['functions'] ?? [];
        $wlClasses = array_merge($wl['classes'] ?? [], $wl['namespaces'] ?? [], $wl['interfaces'] ?? [], $wl['traits'] ?? []);
        if ($wlFuncs !== [] || $wlClasses !== []) {
            $table->preserve($wlFuncs, $wlClasses);
            $this->logger->info('白名单已保留 ' . count($wlFuncs) . ' 个函数、' . count($wlClasses) . ' 个类/接口/特质原名');
        }

        $obfConfig = new Config(array_merge($this->config->all(), ['class_obf' => false]));
        $ok = 0;
        $fail = 0;

        // ---- Pass 2 并行化（v1.2.7）----
        // 全局符号表在 Pass 1 已构建完成、此后只读，可安全地在 fork 出的子进程间共享
        // （写时复制）。每个 worker 按 rel 下标取模分到文件子集，各自独立跑 Obfuscator、
        // 直接写自己的输出文件（路径互不相交）。父进程 wait 汇总成功/失败数并写 manifest。
        // jobs<=1 或 pcntl 不可用时退回原来的串行路径，行为零变化。
        $jobs = max(1, min(256, (int) $this->config->get('jobs', 1)));
        $canFork = $jobs > 1 && function_exists('pcntl_fork');
        if ($canFork) {
            $rels = array_keys($files);
            $total = count($rels);
            $pids = [];
            for ($w = 0; $w < $jobs; $w++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // fork 失败：父进程直接串行兜底整个剩余集合，避免漏文件
                    $this->logger->warn("fork 失败，退回串行构建");
                    foreach ($rels as $ri => $rel) {
                        $r = $this->obfuscateOne($files[$rel], $outDir . '/' . $rel, $tables = [$obfConfig, $table], $skip[$rel] ?? false, $rel);
                        $ok += $r[0];
                        $fail += $r[1];
                    }
                    return $this->finishBuild($outDir, $manifestPath, $ok, $fail, $table);
                }
                if ($pid === 0) {
                    // ---- 子进程：只处理下标 % jobs === w 的文件 ----
                    $childOk = 0;
                    $childFail = 0;
                    for ($ri = $w; $ri < $total; $ri += $jobs) {
                        $rel = $rels[$ri];
                        $r = $this->obfuscateOne($files[$rel], $outDir . '/' . $rel, [$obfConfig, $table], $skip[$rel] ?? false, $rel);
                        $childOk += $r[0];
                        $childFail += $r[1];
                    }
                    // 子进程用退出码低 8 位回传成功数上限 255；这里只需父进程知道"有无失败"，
                    // 精确计数通过每个子进程写的小计文件汇总（避免共享内存）。
                    $statFile = $outDir . '/.obf-worker-' . $w . '.stat';
                    @file_put_contents($statFile, $childOk . ',' . $childFail);
                    // fork 后的内存改动不会回流到父进程：分表落盘，父进程合并
                    if ($this->report !== null && $this->report->enabled()) {
                        @file_put_contents(
                            $outDir . '/.obf-worker-' . $w . '.report.json',
                            json_encode($this->report->toArray())
                        );
                    }
                    exit($childFail > 0 ? 1 : 0);
                }
                $pids[$w] = $pid;
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            // 汇总各 worker 的小计
            for ($w = 0; $w < $jobs; $w++) {
                $statFile = $outDir . '/.obf-worker-' . $w . '.stat';
                if (is_file($statFile)) {
                    $parts = explode(',', trim((string) file_get_contents($statFile)));
                    $ok += (int) ($parts[0] ?? 0);
                    $fail += (int) ($parts[1] ?? 0);
                    @unlink($statFile);
                }
            }
            if ($this->report !== null && $this->report->enabled()) {
                for ($w = 0; $w < $jobs; $w++) {
                    $rp = $outDir . '/.obf-worker-' . $w . '.report.json';
                    if (is_file($rp)) {
                        $d = json_decode((string) file_get_contents($rp), true);
                        if (is_array($d) && isset($d['layers']) && is_array($d['layers'])) {
                            $this->report->merge($d['layers']);
                        }
                        @unlink($rp);
                    }
                }
            }
            $this->logger->info("并行构建完成：{$jobs} workers，成功 {$ok}，失败 {$fail}");
            return $this->finishBuild($outDir, $manifestPath, $ok, $fail, $table);
        }

        // ---- 串行路径（jobs<=1 / 无 pcntl）----
        $obf = new Obfuscator($obfConfig, $this->salt, $this->logger, $this->toolName, $this->toolVersion, $table);
        foreach ($files as $rel => $abs) {
            $r = $this->obfuscateOne($abs, $outDir . '/' . $rel, $obf, $skip[$rel] ?? false, $rel);
            $ok += $r[0];
            $fail += $r[1];
        }

        return $this->finishBuild($outDir, $manifestPath, $ok, $fail, $table);
    }

    /**
     * v1.2.7：混淆单个文件（串行 / 并行 worker 共用）。
     *
     * @param Obfuscator|array{0:Config,1:SymbolTable} $obfOrCtx 串行传 Obfuscator 实例；
     *        并行 worker 传 [obfConfig, table] 由其自建 Obfuscator（避免共享可变状态）。
     * @return array{int,int} [成功数, 失败数]
     */
    private function obfuscateOne(string $abs, string $dest, $obfOrCtx, bool $skip, string $rel): array
    {
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }
        try {
            if ($skip) {
                copy($abs, $dest);
                return [1, 0];
            }
            if (is_array($obfOrCtx)) {
                [$cfg, $table] = $obfOrCtx;
                $obf = new Obfuscator($cfg, $this->salt, $this->logger, $this->toolName, $this->toolVersion, $table, $this->report);
            } else {
                $obf = $obfOrCtx;
            }
            $obf->setSourceFile($abs);
            $res = $obf->run((string) file_get_contents($abs));
            file_put_contents($dest, $res);
            return [1, 0];
        } catch (Throwable $e) {
            $this->logger->warn("混淆失败 {$rel}: " . $e->getMessage());
            return [0, 1];
        }
    }

    /**
     * v1.2.7：写 manifest + 返回汇总（串行 / 并行路径共用）。
     *
     * @return array{files:int,failed:int,funcs:int,classes:int}
     */
    private function finishBuild(string $outDir, ?string $manifestPath, int $ok, int $fail, SymbolTable $table): array
    {
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
     * 计算白名单需要整文件跳过的 rel 集合（原样拷贝、不参与混淆）。
     * dirs：精确前缀或以 / 结尾的前缀匹配（支持 * 通配，如 app/Admin/*）；
     * files：与 rel 精确相等匹配。返回 rel => true 的快速查表。
     *
     * @param array<string,string> $files rel => abs
     * @param array<string> $dirs
     * @param array<string> $filesWl
     * @return array<string,true>
     */
    private function whitelistSkipSet(array $files, array $dirs, array $filesWl): array
    {
        $skip = [];
        if ($dirs === [] && $filesWl === []) {
            return $skip;
        }
        $dirPat = [];
        foreach ($dirs as $d) {
            $d = (string) $d;
            if ($d === '') {
                continue;
            }
            $d = ltrim($d, '/\\');
            $pat = '#^' . strtr(preg_quote($d, '#'), ['\*' => '[^/\\\\]*']) . '#';
            if (str_ends_with($d, '/') || str_ends_with($d, '\\')) {
                $pat = '#^' . strtr(preg_quote(rtrim($d, '/\\'), '#'), ['\*' => '[^/\\\\]*']) . '(/|\\\\)#';
            }
            $dirPat[] = $pat;
        }
        $fileExact = array_flip(array_map(static fn($f) => ltrim((string) $f, '/\\'), $filesWl));
        foreach (array_keys($files) as $rel) {
            $r = ltrim($rel, '/\\');
            if (isset($fileExact[$r])) {
                $skip[$rel] = true;
                continue;
            }
            foreach ($dirPat as $pat) {
                if (preg_match($pat, $r) === 1) {
                    $skip[$rel] = true;
                    break;
                }
            }
        }
        return $skip;
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
