<?php
/**
 * 自校验防篡改（AntiTamper）v2
 *
 * v1 弱点（外部测评指出）：只校验"开头"，且以固定标记 $__awa_p= 定位截取，
 * 攻击者可修改标记后重新计算 sha1 完成补丁。
 *
 * v2 方案：
 *   1) 拟真标记：守卫行变量名由 NameStyle 生成（$log_chk_state 风格，含下划线，
 *      故绝不落入 base64 密文），不再暴露保护壳指纹；
 *   2) 分段校验：主体按长度切分为 2~6 个连续区段，每段独立 sha1 并嵌入守卫，
 *      攻击者必须全部重算才能补丁；
 *   3) 自校验（掩码法）：守卫行自己的 sha1 以"占位符参与哈希"的方式嵌入 ——
 *      构建端对"自身哈希字段填 0 的守卫行"求 sha1；运行期读到守卫行后把哈希值
 *      掩码回 0 再哈希比对。篡改守卫行本身（包括改标记、改密文、改校验逻辑）
 *      都会使自校验失配；要补丁必须精确复刻整套掩码流程；
 *   4) shutdown 二次校验：注册 register_shutdown_function，在脚本结束前重读文件
 *      再验一遍分段哈希，覆盖"执行期间被改写"的窗口。
 *
 * 覆盖范围：守卫行本身（自校验）+ 开标签后全部主体（分段连续覆盖）。
 * 开销：启动时一次 file_get_contents + 若干 sha1（总计约等于一遍文件哈希），
 * 无忙等、无延时循环。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/DeclareHoist.php';

require_once __DIR__ . '/../ShellProtector.php';

final class AntiTamper
{
    /**
     * 注入区哨兵（AntiTamper 与 CodeInjector 共享的尾区边界约定）。
     * AntiTamper 只保护到"终结符"为止；终结符之后允许出现且仅允许出现
     * 以本哨兵开头的注入区（inject 层 after_shell 注入的代码）。
     * 这样既放行合法的分层注入，又能拦截执行期尾部追加/改写。
     */
    public static function injectZoneSentinel(?Salt $salt): string
    {
        $hex = ($salt !== null && !$salt->isEmpty()) ? $salt->deriveHex('inject-zone', 8) : '00000000';
        return '//awa_inject_zone_' . $hex;
    }

    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * 生成"带自校验"的成品。
     *
     * @param array{segments?:int,shutdown?:bool} $opts
     *   segments: 受护体分段数（2-8）
     *   shutdown: 是否注册 shutdown 二次校验
     * @return array{code:string, ok:bool}
     */
    public function wrap(string $code, array $opts = [], array $shieldOpts = []): array
    {
        $segCount  = max(2, min(8, (int) ($opts['segments'] ?? 4)));
        $shutdown  = (bool) ($opts['shutdown'] ?? true);
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $this->logger->warn('AntiTamper: 未找到 PHP 开标签，跳过自校验');
            return ['code' => $code, 'ok' => false];
        }
        $openPos = (int) $m[0][1];
        $openLen = strlen($m[0][0]);

        // 前导 declare(strict_types=1) 必须仍在最前面（守卫行插到它之后）
        $split  = DeclareHoist::split($code);
        $prefix = $split['prefix'];
        $body   = $split['body'];
        if ($body === '') {
            return ['code' => $code, 'ok' => false];
        }

        $style = new NameStyle($this->salt);
        // 两个标记都必须含下划线（保证绝不落入 base64 密文）
        $mName = $style->markerName('antitamper-marker'); // 守卫行首变量
        $eName = $style->markerName('antitamper-self');   // 自身哈希变量
        $zeros = str_repeat('0', 40);
        $fail  = '@trigger_error(\'fatal: cannot continue\',E_USER_ERROR);exit(1);';

        // ---- 0) 终结符：受护体的合法终点 ----
        // 主体末尾追加注释终结符（纳入分段哈希覆盖）。运行期要求终结符之后
        // 只能为空或指向注入哨兵（见 injectZoneSentinel），从而在放行
        // inject 层 after_shell 注入的同时拦截执行期尾部追加/改写。
        $term = '//' . $style->markerName('antitamper-term');
        $sent = self::injectZoneSentinel($this->salt);
        $body .= "\n" . $term . "\n";

        // ---- 1) 主体分段基线（连续区段，全覆盖） ----
        $k = max(2, min(8, $segCount));
        $segLen = (int) ceil(strlen($body) / $k);
        $offs = [];
        $lens = [];
        $exps = [];
        for ($i = 0; $i < $k; $i++) {
            $off = $i * $segLen;
            $len = min($segLen, strlen($body) - $off);
            if ($len <= 0) {
                break;
            }
            $offs[] = $off;
            $lens[] = $len;
            $exps[] = sha1(substr($body, $off, $len));
        }

        // ---- 2) 守卫内校验逻辑（整体编码，不以明文出现） ----
        $search = $mName . '=__FILE__;';   // 含 $ 与 _，绝不出现在 base64 密文中
        $needle = str_replace("'", "\\'", $search);
        $g = '$c=@file_get_contents(' . $mName . ');';
        $g .= '$p=strpos($c,\'' . $needle . '\');';
        $g .= 'if($p===false){' . $fail . '}';
        $g .= '$e=strpos($c,"\\n",$p);';
        $g .= '$line=($e===false)?substr($c,$p):substr($c,$p,$e-$p);';
        // 掩码法自校验：把自身哈希字段掩回 0 串再比对
        $g .= '$line=str_replace(' . $eName . ',\'' . $zeros . '\',$line);';
        $g .= 'if(sha1($line)!==' . $eName . '){' . $fail . '}';
        // 主体分段校验 + 总长度下限校验（分段按构建期长度切，连续覆盖受保护区段；
        // 尾部合法追加——如 inject 层 after_shell 注入——落在区段外，只校验"不短于"，
        // 截断与区段内篡改分别由长度下限与分段 sha1 拦截）
        $g .= '$b=($e===false)?\'\':substr($c,$e+1);';
        $g .= '$BL=' . strlen($body) . ';if(strlen($b)<$BL){' . $fail . '}';
        $g .= '$O=' . var_export($offs, true) . ';$L=' . var_export($lens, true) . ';$X=' . var_export($exps, true) . ';';
        $g .= '$n=count($O);for($i=0;$i<$n;$i++){';
        $g .= 'if(sha1(substr($b,(int)$O[$i],(int)$L[$i]))!==$X[$i]){' . $fail . '}}';
        // 尾区校验：终结符之后只允许为空或注入哨兵开头（拦截尾部追加/改写，
        // 放行 inject 层 after_shell 注入）。容忍一个前导换行。
        $sentQ = str_replace("'", "\\'", $sent);
        $tailChk = '$t=substr($b,$BL);if($t!==\'\'){if(substr($t,0,1)==="\n"){$t=substr($t,1);}'
            . 'if($t!==\'\'&&substr($t,0,' . strlen($sent) . ')!==\'' . $sentQ . '\'){';
        $g .= $tailChk . $fail . '}}';
        // shutdown 二次校验（覆盖执行期间被改写的窗口）
        if ($shutdown) {
            $g .= '$sf=function()use(' . $mName . ',$O,$L,$X,$BL){';
            $g .= '$c=@file_get_contents(' . $mName . ');';
            $g .= '$e=strpos($c,"\\n",strpos($c,\'' . $needle . '\'));';
            $g .= '$b=($e===false)?\'\':substr($c,$e+1);';
            $g .= 'if(strlen($b)<$BL){exit(1);}';
            $g .= 'foreach($O as $i=>$o){if(sha1(substr($b,(int)$o,(int)$L[$i]))!==$X[$i]){exit(1);}}';
            $g .= $tailChk . 'exit(1);}}};';
            $g .= 'register_shutdown_function($sf);';
        }

        $blob = base64_encode((string) gzdeflate($g, 9));

        // ---- 3) 守卫行：自身哈希以"占位符参与哈希"嵌入 ----
        $tpl = $mName . '=__FILE__;' . $eName . "='" . $zeros . "';\$g=@eval(gzinflate(base64_decode('" . $blob . "')));";
        // 逐层壳自保护：对守卫行施加混淆。必须【不动变量名】（用 norename 模式），
        // 否则运行时 $needle = $mName.'=__FILE__;' 的自匹配会失配、自校验误报篡改。
        // token 大小写 + 语义变换足以藏匿明文守卫逻辑（核心校验逻辑已 gz 编码进 blob）。
        if (!empty($shieldOpts['shield'])) {
            $tpl = ShellProtector::protect(
                $tpl, $this->salt, $this->logger, 'norename',
                max(1, min(3, (int) ($shieldOpts['intensity'] ?? 2)))
            );
        }
        $selfHash = sha1($tpl);
        $line = str_replace("'" . $zeros . "'", "'" . $selfHash . "'", $tpl);

        // 开标签后保留换行（<?php 后必须跟空白才被识别为标签）
        $final = $prefix . "\n" . $line . "\n" . $body;

        $this->logger->debug('已注入（编码后）自校验防篡改外壳 v2（分段=' . count($offs) . '）');
        return ['code' => $final, 'ok' => true];
    }
}
