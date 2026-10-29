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
     *              ext  扩展加载（xdebug/uopz/runkit/blackfire/tideways/xhprof）
     *              func 函数存在（xdebug_break 等）
     *              ini   ini 项（xdebug.mode / profiler_enable / remote_enable …）
     *              env   环境变量（XDEBUG_CONFIG / PHPDBG）
     *              sapi  phpdbg SAPI
     *              trace 调用栈调试痕迹（debug_backtrace 扫描）
     *   intensity: 1=明文内联 2=gz+base64 3=拟真命名 4=叠加 XOR
     */
    public function wrap(string $code, array $opts = []): string
    {
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $this->logger->warn('AntiDebug: 未找到 PHP 开标签，跳过');
            return $code;
        }
        $probeList = $this->parseProbes($opts['probes'] ?? 'ext,func,ini,env,sapi,trace');
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
        $valid = ['ext', 'func', 'ini', 'env', 'sapi', 'trace'];
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
                . "if(extension_loaded('blackfire')||extension_loaded('tideways')||extension_loaded('tideways_xhprof')||extension_loaded('xhprof'))\$r=true;",
            // --- 函数存在 ---
            'func' => "if(function_exists('xdebug_break')||function_exists('xdebug_info')||function_exists('xdebug_start_trace'))\$r=true;"
                . "if(function_exists('phpdbg_break_file')||function_exists('phpdbg_start_oplog'))\$r=true;",
            // --- ini 项 ---
            'ini' => "if((\$xv=@ini_get('xdebug.mode'))!==''&&\$xv!=='off'&&\$xv!==false)\$r=true;"
                . "if(in_array((string)@ini_get('xdebug.profiler_enable'),array('1','on','true','yes'),true))\$r=true;"
                . "if(in_array((string)@ini_get('xdebug.remote_enable'),array('1','on','true','yes'),true))\$r=true;"
                . "if(in_array((string)@ini_get('xdebug.start_with_request'),array('1','yes','trigger'),true))\$r=true;",
            // --- 环境变量 ---
            'env' => "if(getenv('XDEBUG_CONFIG'))\$r=true;"
                . "if(isset(\$_SERVER['XDEBUG_CONFIG']))\$r=true;"
                . "if(getenv('PHPDBG'))\$r=true;"
                . "if(isset(\$_SERVER['PHPDBG_CONFIG']))\$r=true;",
            // --- phpdbg SAPI ---
            'sapi' => "if(defined('PHP_SAPI')&&PHP_SAPI==='phpdbg')\$r=true;",
            // --- 调用栈调试痕迹 ---
            'trace' => "\$bt=@debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);"
                . "foreach(\$bt as \$f){"
                . "foreach(array('class','function') as \$k){"
                . "if(isset(\$f[\$k])&&(stripos(\$f[\$k],'debug')!==false||stripos(\$f[\$k],'xdebug')!==false||stripos(\$f[\$k],'phpdbg')!==false))\$r=true;"
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
