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
 * 设计约束：
 *   - 整段判定逻辑经 gzdeflate + base64 编码，'xdebug' / 'phpdbg' 等特征串不以明文出现；
 *   - 命中后直接终止，不含忙等/延时循环，不引入额外性能负担；
 *   - ini 项判定使用白名单值比较，避免 'off' 这类非空字符串造成的误报。
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
     *   intensity: 1=明文内联 2=gz+base64 3=拟真命名 4=叠加 XOR
     */
    public function wrap(string $code, array $opts = []): string
    {
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $this->logger->warn('AntiDebug: 未找到 PHP 开标签，跳过');
            return $code;
        }
        $probeList = $this->parseProbes($opts['probes'] ?? 'ext,func,ini,env,sapi,trace,shell');
        $intensity = max(1, min(4, (int) ($opts['intensity'] ?? 2)));
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

        $guard = "if(!function_exists('" . $fn . "')){function " . $fn . "(){"
            . "\$r=false;"
            . $this->probeBlock($probeList)
            . "return \$r;}}"
            // --- 命中即终止（无忙等）---
            . "if(" . $fn . "()){@trigger_error('fatal: cannot continue',E_USER_ERROR);exit(1);}";

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

        $this->logger->debug('已注入反调试探针（探针组=' . implode(',', $probeList) . ' 强度=' . $intensity . '）');
        return $prefix . "\n" . $guardLine . "\n" . $body;
    }

    /**
     * 解析探针组参数为合法组名列表。
     * @param string|array $probes
     * @return array<int,string>
     */
    private function parseProbes($probes): array
    {
        $valid = ['ext', 'func', 'ini', 'env', 'sapi', 'trace', 'shell', 'proc'];
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
                . "if(extension_loaded('taint')||extension_loaded('scream'))\$r=true;",
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
                . "if(in_array((string)@ini_get('xdebug.start_with_request'),array('1','yes','trigger'),true))\$r=true;",
            // --- 环境变量 ---
            'env' => "if(getenv('XDEBUG_CONFIG'))\$r=true;"
                . "if(isset(\$_SERVER['XDEBUG_CONFIG']))\$r=true;"
                . "if(getenv('XDEBUG_SESSION'))\$r=true;"
                . "if(getenv('PHP_IDE_CONFIG'))\$r=true;"
                . "if(getenv('PHPDBG'))\$r=true;"
                . "if(isset(\$_SERVER['PHPDBG_CONFIG']))\$r=true;"
                // 内存/执行分析器常见环境变量
                . "if(getenv('BLACKFIRE_CLIENT_ID')||getenv('TIDEWAYS_APIKEY'))\$r=true;"
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
