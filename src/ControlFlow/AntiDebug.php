<?php
/**
 * 反调试（AntiDebug）— 加强版
 *
 * 在产物顶部注入一段"运行时探针"，检测常见调试 / 分析 / hook 环境并在命中时终止执行。
 * 相比初版，检测面大幅扩展：
 *
 *   1. Xdebug 家族：扩展加载、xdebug_break / xdebug_info / xdebug_start_trace、
 *      ini 项 xdebug.mode / profiler_enable / remote_enable / start_with_request、
 *      环境变量 XDEBUG_CONFIG
 *   2. phpdbg：PHP_SAPI==='phpdbg'、phpdbg_break_file / phpdbg_start_oplog、PHPDBG 环境变量
 *   3. 可劫持函数的扩展：uopz / runkit / runkit7（与 AntiHookEval 互为纵深）
 *   4. 剖析/分析类扩展：blackfire / tideways / tideways_xhprof / xhprof
 *   5. 调用栈中出现 debug / xdebug / phpdbg 相关符号
 *
 * v1.2.0 加强（本次「反调试加强」）：
 *   6. func2 / ini2 两个新探针组：把 xdebug/phpdbg 全家族函数与 xdebug.* 全家族 ini、
 *      opcache.jit_debug / pcov.enabled 等明确调试信号纳入（全部零误报的确定性特征）；
 *   7. response=silent 静默反制：命中不再「守卫点单点 exit(1)」——先开一层输出缓冲，
 *      命中只打全局标记，业务照常跑，最终由 shutdown 丢弃全部输出缓冲。判定与反制
 *      分离，调试者在守卫函数处下断也找不到 exit，只看到「程序正常结束却没结果」；
 *   8. multipoint 关机复判：额外注册 shutdown 二次探针判定，覆盖「脚本启动后才 attach」
 *      的调试器。
 *
 * 设计约束：
 *   - 整段判定逻辑经 gzdeflate + base64 编码，'xdebug' / 'phpdbg' 等特征串不以明文出现；
 *   - 不含忙等/延时循环，不引入额外性能负担（silent 仅多一层输出缓冲）；
 *   - ini 项判定使用白名单值比较，避免 'off' 这类非空字符串造成的误报；
 *   - 探针组逐组 php -l 自检 + 守卫整体 php -l 自检，语法异常整层跳过而非产出坏文件。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/DeclareHoist.php';
require_once __DIR__ . '/../ShellBinding.php';

final class AntiDebug
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * 头部注入探针，返回注入后的完整代码。
     *
     * @param array{probes?:string|array,probes?:string,intensity?:int} $opts
     *   probes:    探针组（逗号分隔或数组）：
     *              ext  扩展加载（xdebug/uopz/runkit/blackfire/tideways/xhprof/vld）
     *              func 函数存在（xdebug_break 等）
     *              ini   ini 项（xdebug.mode / profiler_enable / remote_enable …）
     *              env   环境变量（XDEBUG_CONFIG / PHPDBG / 插桩型 LD_PRELOAD）
     *              sapi  phpdbg SAPI
     *              trace 调用栈调试痕迹（debug_backtrace 扫描）
     *              proc  进程跟踪痕迹（/proc/self/status 的 TracerPid、/proc/self/maps 注入 .so）
     *              shell 脱壳检测（校验外壳绑定标记，缺失即判「VM 内层被抠出独立运行」）
     *              func2 xdebug/phpdbg 全家族函数（零误报确定性特征）
     *              ini2  xdebug.* 全家族 ini + opcache.jit_debug + pcov.enabled
     *   intensity: 1=明文内联 2=gz+base64 3=拟真命名 4=叠加 XOR
     *   response:  exit（默认，命中即终止）| silent（命中不 exit，改由 shutdown 静默擦除输出）
     *   multipoint: 是否额外注册 shutdown 二次探针判定（覆盖运行中 attach）
     */
    public function wrap(string $code, array $opts = []): string
    {
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $this->logger->warn('AntiDebug: 未找到 PHP 开标签，跳过');
            return $code;
        }
        $probeList = $this->parseProbes($opts['probes'] ?? 'ext,func,ini,env,sapi,trace,shell');
        $intensity  = max(1, min(4, (int) ($opts['intensity'] ?? 2)));
        $response   = ($opts['response'] ?? 'exit') === 'silent' ? 'silent' : 'exit';
        $multipoint = (bool) ($opts['multipoint'] ?? false);
        if ($probeList === []) {
            $this->logger->warn('AntiDebug: 探针组为空，跳过');
            return $code;
        }
        $openPos = (int) $m[0][1];
        $openLen = strlen($m[0][0]);

        // 前导 declare(strict_types=1) 必须留在最前面：守卫行插到它【之后】，
        // 否则产物直接 "strict_types declaration must be the very first statement"。
        $split  = DeclareHoist::split($code);
        $prefix = $split['prefix'];
        $body   = $split['body'];

        // 命名拟真化（NameStyle），去除 __awa_dbg_ 指纹
        $style = new NameStyle($this->salt);
        $fn = $style->funcName('antidebug-guard');
        $lineVar = $style->varName('antidebug-line');

        // 探针块是手写拼接的 PHP 源码，一个括号错位就会让整份产物 Parse error，
        // 且这类错误在"构建期无告警、只有跑产物才暴露"。此处逐组做语法自检：
        // 出错的组整组剔除，其余组照常生效——把损失限制在出错的那一组。
        // 必须在构造 $guard **之前**执行，否则守卫里仍是含错组的旧代码。
        if ($probeList !== []) {
            $probeList = $this->filterValidProbes($probeList);
            if ($probeList === []) {
                $this->logger->warn('AntiDebug: 所有探针组语法自检失败，跳过反调试层');
                return $code;
            }
        }

        $fail   = '@trigger_error(\'fatal: cannot continue\',E_USER_ERROR);exit(1);';
        $hitKey = $style->funcName('antidebug-hit');
        $hit    = '$GLOBALS[\'' . $hitKey . '\']';
        $head   = "if(!function_exists('" . $fn . "')){function " . $fn . "(){"
            . "\$r=false;"
            . $this->probeBlock($probeList)
            . "return \$r;}}";

        if ($response === 'silent') {
            // 命中不在守卫点 exit：先开一层输出缓冲，命中只打全局标记，业务照常跑；
            // shutdown 统一丢弃全部输出缓冲。register_shutdown_function 在 eval 内注册亦生效。
            $guard = '@ob_start();' . $head
                . 'if(' . $fn . '()){' . $hit . '=1;}';
            $recheck = $multipoint ? $fn . '()||' : '';
            $guard .= 'register_shutdown_function(function(){'
                . 'if(' . $recheck . '!empty(' . $hit . ')){'
                . 'while(@ob_get_level()>0){@ob_end_clean();}' . $hit . '=1;}});';
        } else {
            $guard = $head . 'if(' . $fn . '()){' . $fail . '}';
            if ($multipoint) {
                // exit 模式的多点：shutdown 二次判定，命中即静默终止（覆盖运行中 attach）
                $guard .= 'register_shutdown_function(function(){if(' . $fn . '()){exit(1);}});';
            }
        }

        // 守卫整体语法自检：$guard 会被 gz 编码后 eval，文件级 php -l 覆盖不到它。
        // 探针组已逐组自检，这里再兜一次守卫外壳（silent/multipoint 拼接），异常则整层跳过。
        if (!$this->isSyntaxValid($guard)) {
            $this->logger->warn('AntiDebug: 守卫逻辑语法自检失败，跳过反调试层');
            return $code;
        }

        // intensity: 1=明文内联；>=2 走编码；3=拟真命名（上面已做）；4=叠加 XOR
        if ($intensity <= 1) {
            $guardLine = $guard;
        } else {
            $blob = base64_encode((string) gzdeflate($guard, 9));
            if ($intensity >= 4) {
                $k = $this->salt->deriveHex('antidebug-xor', 8);
                $blob = base64_encode($k . $this->xorStr((string) gzdeflate($guard, 9), $k));
                $guardLine = $lineVar . '=base64_decode(\'' . $blob . '\');'
                    . $lineVar . '=gzinflate(substr(' . $lineVar . ',8)^substr(str_repeat(\''
                    . $k . '\',(int)(strlen(' . $lineVar . ')/8)+2),0,strlen(' . $lineVar . ')));'
                    . '@eval(' . $lineVar . ');';
            } else {
                $guardLine = $lineVar . "=@eval(gzinflate(base64_decode('" . $blob . "')));";
            }
        }

        $this->logger->debug('已注入反调试探针（探针组=' . implode(',', $probeList)
            . ' 强度=' . $intensity . ' 反制=' . $response . ' 关机复判=' . ($multipoint ? 'on' : 'off') . '）');
        return $prefix . "\n" . $guardLine . "\n" . $body;
    }

    /**
     * 解析探针组参数为合法组名列表。
     * @param string|array $probes
     * @return array<int,string>
     */
    private function parseProbes($probes): array
    {
        $valid = ['ext', 'func', 'ini', 'env', 'sapi', 'trace', 'shell', 'proc', 'func2', 'ini2'];
        $items = is_array($probes) ? $probes : explode(',', (string) $probes);
        $out = [];
        foreach ($items as $it) {
            $it = trim((string) $it);
            if ($it !== '' && in_array($it, $valid, true) && !in_array($it, $out, true)) {
                $out[] = $it;
            } elseif ($it !== '') {
                $this->logger->warn("AntiDebug: 未知探针组 {$it}（已忽略）");
            }
        }
        return $out;
    }

    /**
     * 语法自检：把 PHP 源码片段交给 php -l 校验。
     * @param string $src
     */
    private function isSyntaxValid(string $src): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adchk') . '.php';
        file_put_contents($tmp, "<?php\n" . $src . "\n");
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        exec($cmd, $out, $code);
        @unlink($tmp);
        return $code === 0;
    }

    /**
     * 逐组筛选：只保留语法有效的探针组。
     * 手写探针块时一个括号错位就会让整份产物 Parse error；构建期无告警、
     * 只有跑产物才暴露。逐组剔除把损失限制在出错的那一组。
     *
     * @param array<int,string> $probes
     * @return array<int,string>
     */
    private function filterValidProbes(array $probes): array
    {
        $ok = [];
        foreach ($probes as $p) {
            // 用与真实守卫同构的壳做自检：探针块里若有裸语句，其在函数体内合法、
            // 在守卫壳外可能非法（如 const 声明）——必须按真实形态校验。
            $snippet = "function __awa_probe_check__(){\$r=false;"
                . $this->probeBlock([$p])
                . "return \$r;}";
            if ($this->isSyntaxValid($snippet)) {
                $ok[] = $p;
            } else {
                $this->logger->warn("AntiDebug: 探针组 '{$p}' 语法自检失败，已剔除");
            }
        }
        return $ok;
    }

    /**
     * 按探针组生成判定逻辑（拼进守卫函数体）。
     * @param array<int,string> $probes
     */
    private function probeBlock(array $probes): string
    {
        $blocks = [
            // --- 扩展加载 ---
            'ext' => "if(extension_loaded('xdebug')||extension_loaded('xdebug3'))\$r=true;"
                . "if(extension_loaded('uopz')||extension_loaded('runkit')||extension_loaded('runkit7'))\$r=true;"
                . "if(extension_loaded('blackfire')||extension_loaded('tideways')||extension_loaded('tideways_xhprof')||extension_loaded('xhprof'))\$r=true;"
                // 反汇编/字节码窥探类：VLD（vld 扩展，opcode 导出）、phpdbg 的 oplog 由 func 组覆盖
                . "if(extension_loaded('vld')||extension_loaded('parsekit'))\$r=true;"
                // 内存/执行流篡改类：禁用函数列表被改、open_basedir 被放开等常见脱壳调试前置
                . "if(extension_loaded('taint')||extension_loaded('scream'))\$r=true;"
                // 覆盖率/执行追踪类（常与调试配合）：pcov 是 PHP 8 的覆盖率扩展，phpdbg 提供 opcode 日志
                . "if(extension_loaded('pcov')||extension_loaded('phpdbg'))\$r=true;",
            // --- 函数存在 ---
            'func' => "if(function_exists('xdebug_break')||function_exists('xdebug_info')||function_exists('xdebug_start_trace'))\$r=true;"
                . "if(function_exists('phpdbg_break_file')||function_exists('phpdbg_start_oplog'))\$r=true;"
                // 实际挂载调试器才为真（区别于"装了 xdebug 但没在调试"的 CI 场景）
                . "if(function_exists('xdebug_is_debugger_active')&&@xdebug_is_debugger_active())\$r=true;"
                // 常见反编译/内存 dump 工具面：若这些函数被人临时塞回 PHP（patch ext 或 userland 替换）即判命中
                . "if(function_exists('php_strip_whitespace')&&strpos(ini_get('disable_functions'),'xdebug')===false&&function_exists('xdebug_get_code_coverage'))\$r=true;",
            // --- ini 项 ---
            'ini' => "if((\$xv=@ini_get('xdebug.mode'))!==''&&\$xv!=='off'&&\$xv!==false)\$r=true;"
                . "if(in_array((string)@ini_get('xdebug.profiler_enable'),array('1','on','true','yes'),true))\$r=true;"
                . "if(in_array((string)@ini_get('xdebug.remote_enable'),array('1','on','true','yes'),true))\$r=true;"
                . "if(in_array((string)@ini_get('xdebug.start_with_request'),array('1','yes','trigger'),true))\$r=true;"
                // 自动前/后置脚本：脱壳时常用 auto_prepend_file 把自己的解壳器挂进去，
                // 正常应用极少配置这两项（框架的 bootstrap 一般走 include 而非 ini）。
                . "if((string)@ini_get('auto_prepend_file')!==''||(string)@ini_get('auto_append_file')!=='')\$r=true;"
                // zend_extension / opcache 的 JIT 调试旁路：opcache.jit=1 常与 xdebug 调试同开
                . "if(in_array((string)@ini_get('opcache.jit'),array('1','on','true','yes'),true))\$r=true;",
            // --- 环境变量 ---
            'env' => "if(getenv('XDEBUG_CONFIG'))\$r=true;"
                . "if(isset(\$_SERVER['XDEBUG_CONFIG']))\$r=true;"
                . "if(getenv('XDEBUG_SESSION'))\$r=true;"
                . "if(getenv('PHP_IDE_CONFIG'))\$r=true;"
                . "if(getenv('PHPDBG'))\$r=true;"
                . "if(isset(\$_SERVER['PHPDBG_CONFIG']))\$r=true;"
                // 内存/执行分析器常见环境变量
                . "if(getenv('BLACKFIRE_CLIENT_ID')||getenv('TIDEWAYS_APIKEY'))\$r=true;"
                // XDEBUG_MODE=debug/coverage/trace 等：xdebug 3 的就绪信号（区别于 mode=off）
                . "if((\$xm=(string)@getenv('XDEBUG_MODE'))!==''&&preg_match('/debug|coverage|trace|profile|develop/i',\$xm))\$r=true;"
                // 动态注入类预加载（frida/xdl/拦截器）：只认明显的插桩/AOP 痕迹，
                // 不因 LD_PRELOAD 非空就判定——jemalloc 等分配器预加载在正常生产也常见。
                . "foreach(array('LD_PRELOAD','LD_AUDIT') as \$ek){"
                . "\$ev=(string)@getenv(\$ek);"
                . "if(\$ev!==''&&preg_match('/(frida|xdl|substrate|inject|intercept|hook)/i',\$ev))\$r=true;"
                . "}",
            // --- phpdbg SAPI ---
            'sapi' => "if(defined('PHP_SAPI')&&PHP_SAPI==='phpdbg')\$r=true;",
            // --- 进程跟踪痕迹（Linux /proc）---
            // 反调试的最底层信号：进程被 ptrace 附着（gdb/lldb/strace/调试器）时
            // /proc/self/status 的 TracerPid 非 0；注入式 hook 框架会把自身 .so 映射进
            // 进程地址空间，在 /proc/self/maps 留下 frida/xdl/substrate 等痕迹。
            // 全部 @ 抑制 + is_string 兜底：非 Linux / 无 /proc / open_basedir 限制下静默跳过。
            'proc' => "\$ps=@file_get_contents('/proc/self/status');"
                . "if(is_string(\$ps)&&preg_match('/^TracerPid:[ \\t]*([0-9]+)/mi',\$ps,\$tm)&&(int)\$tm[1]!==0)\$r=true;"
                . "\$mp=@file_get_contents('/proc/self/maps');"
                . "if(is_string(\$mp)&&preg_match('/(frida|xdl|substrate|libinject)/i',\$mp))\$r=true;",
            // --- 外壳绑定 / 脱壳检测（加强）---
            // 校验混淆器在最外层注入的「外壳绑定标记」：完整混淆文件携带该盐派生常量；
            // 一旦把 VM 内层抠出独立运行、或把外层标记 define 整行删掉，常量消失即判脱壳 → 终止。
            // 仅当 vm_selfcheck 开启（标记确有注入）时由混淆器把 'shell' 纳入探针组，避免无标记时误杀。
            'shell' => "if(" . ShellBinding::isUnboundExpr($this->salt) . ")\$r=true;",
            // --- 调用栈调试痕迹 ---
            // 只认真正的调试/剖析器符号（xdebug_* / phpdbg_* / Xdebug\* / Blackfire\* …），
            // **绝不用裸 'debug' 子串**：ThinkPHP 自带 think\debug\*、think\trace\TraceDebug、
            // Laravel 的 Illuminate\Support\Debug 等，业务框架里叫 debug 的命名空间遍地都是，
            // 用子串判定等于"正常应用必被判定为调试环境"，整站直接 exit(1)。
            // （v1.1.1 修正：曾导致 max/ultra 档在 ThinkPHP8 上每个请求都被守卫终止。）
            'trace' => "\$bt=@debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);"
                . "foreach(\$bt as \$f){"
                . "foreach(array('class','function') as \$k){"
                . "if(!isset(\$f[\$k])||\$f[\$k]==='')continue;"
                . "\$sy=(string)\$f[\$k];"
                . "if(preg_match('/^(xdebug|phpdbg)_/i',\$sy))\$r=true;"
                . "if(stripos(\$sy,'xdebug')!==false||stripos(\$sy,'phpdbg')!==false"
                . "||stripos(\$sy,'blackfire')!==false||stripos(\$sy,'tideways')!==false"
                . "||stripos(\$sy,'xhprof')!==false||stripos(\$sy,'uopz')!==false"
                . "||stripos(\$sy,'runkit')!==false)\$r=true;"
                . "}}",
            // --- 扩展函数全家族（零误报：这些函数只在装了 xdebug/phpdbg 时才存在）---
            // 与 'func' 组互补：func 只查最典型的几个 break/info/start_trace，这里把
            // xdebug 3 的 code_coverage / function_monitor / gcstats / filter / var_dump
            // 与 phpdbg 全套 break_* / oplog / exec 一并纳入，堵住「只补几个函数名」的绕过。
            'func2' => "foreach(array('xdebug_call_class','xdebug_call_file','xdebug_call_function','xdebug_call_line',"
                . "'xdebug_code_coverage_started','xdebug_debug_zval','xdebug_dump_superglobals','xdebug_get_code_coverage',"
                . "'xdebug_get_collected_errors','xdebug_get_declared_vars','xdebug_get_function_stack','xdebug_get_headers',"
                . "'xdebug_get_monitored_functions','xdebug_get_stack_depth','xdebug_get_tracefile_name','xdebug_info',"
                . "'xdebug_memory_usage','xdebug_peak_memory_usage','xdebug_print_function_stack','xdebug_set_filter',"
                . "'xdebug_start_code_coverage','xdebug_start_error_collection','xdebug_start_function_monitor',"
                . "'xdebug_start_gcstats','xdebug_start_trace','xdebug_stop_code_coverage','xdebug_stop_trace',"
                . "'xdebug_time_index','xdebug_var_dump','phpdbg_break_file','phpdbg_break_function','phpdbg_break_method',"
                . "'phpdbg_break_next','phpdbg_clear','phpdbg_end_oplog','phpdbg_exec','phpdbg_prompt','phpdbg_start_oplog')"
                . " as \$fx){if(function_exists(\$fx))\$r=true;}",
            // --- ini 全家族（白名单值比较，零误报：未装 xdebug 时 ini_get 返回 false）---
            // 只认显式开启值（1/on/true/yes/debug/trace/...），避开 var_display_max_*=128
            // 这类默认数值造成的误报；opcache.jit_debug 是明确的调试旁路信号。
            'ini2' => "\$ik2=array('xdebug.collect_assignments','xdebug.collect_params','xdebug.collect_return',"
                . "'xdebug.coverage_enable','xdebug.default_enable','xdebug.extended_info','xdebug.gc_stats_enable',"
                . "'xdebug.profiler_enable_trigger','xdebug.profiler_append','xdebug.remote_autostart',"
                . "'xdebug.remote_connect_back','xdebug.remote_enable','xdebug.start_upon_error',"
                . "'xdebug.start_with_request','xdebug.trace_enable_trigger','xdebug.trace_options','xdebug.mode');"
                . "foreach(\$ik2 as \$ik){if(in_array((string)@ini_get(\$ik),"
                . "array('1','on','true','yes','debug','trace','profile','coverage','develop','trigger'),true))\$r=true;}"
                . "if(in_array((string)@ini_get('opcache.jit_debug'),array('1','on','true'),true))\$r=true;",
        ];
        $out = '';
        foreach ($probes as $p) {
            $out .= $blocks[$p] ?? '';
        }
        return $out;
    }

    /** 重复密钥 XOR（与运行期解法一致） */
    private function xorStr(string $data, string $key): string
    {
        if ($data === '') {
            return '';
        }
        $ks = str_repeat($key, (int) (strlen($data) / max(1, strlen($key))) + 2);
        return $data ^ substr($ks, 0, strlen($data));
    }
}
