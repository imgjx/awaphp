<?php
/**
 * 自校验防篡改（AntiTamper）v3
 *
 * 演进：
 *   v1 只校验"开头"且用固定标记定位，改标记重算 sha1 即可补丁（外部测评指出）。
 *   v2 拟真标记 + 分段 sha1 + 掩码法自校验 + shutdown 二次校验。
 *   v3 在 v2 基础上做三点实质加强（本次「反篡改加强」）：
 *     1) 分段【链式】哈希：X[i] = sha1(seg[i] ‖ (i? X[i-1] : IV) ‖ i)。
 *        改任一段不仅本段失配，整条链断裂；X 数组又被守卫行掩码自校验保护，
 *        攻击者即便绕过 selfHash 也必须按序重算整条链，无法局部补丁。
 *     2) 段边界由 salt 派生的不等长权重扰动生成（不再等宽切分），攻击者难以
 *        仅凭文件长度推断 O/L 分段表；另加「链整体指纹」CH = sha1(拼接所有 X)，
 *        X 数组被局部替换时立即失配。
 *     3) 多点分散触发：启动守卫（P1）+ 独立 shutdown 主体链复检（P2）+
 *        独立 shutdown 守卫行自校验/尾区复检（P3）。三个校验点走不同代码路径，
 *        不再是一处 exit 就能被整体摘掉。
 *
 * 覆盖与开销：守卫行本身（掩码自校验）+ 开标签后全部主体（分段链式全覆盖）；
 * 启动时一次 file_get_contents + 若干 sha1，shutdown 各再读一次；无忙等、无延时。
 *
 * 安全网：$g 守卫逻辑是被 gz+base64 编码后 eval 的明文，构建期 php -l 看不到它，
 * 语法错只有跑产物才暴露。故 wrap() 在编码前对 $g 单独做一次 php -l 自检，
 * 不通过则跳过本层（正确性优先于保护强度），并记 warn。
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
     * @param array{segments?:int,shutdown?:bool,chain?:bool,multipoint?:bool} $opts
     *   segments:   受护体分段数（2-8）
     *   shutdown:   是否注册 shutdown 二次校验（P2）
     *   chain:      分段链式哈希（默认 true；false 退回 v2 的独立 sha1）
     *   multipoint: 是否注册第三个独立触发点 P3（守卫行自校验 + 尾区复检）
     * @return array{code:string, ok:bool}
     */
    public function wrap(string $code, array $opts = [], array $shieldOpts = []): array
    {
        $segCount   = max(2, min(8, (int) ($opts['segments'] ?? 4)));
        $shutdown   = (bool) ($opts['shutdown'] ?? true);
        $chain      = (bool) ($opts['chain'] ?? true);
        $multipoint = (bool) ($opts['multipoint'] ?? true);
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $this->logger->warn('AntiTamper: 未找到 PHP 开标签，跳过自校验');
            return ['code' => $code, 'ok' => false];
        }

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
        $term = '//' . $style->markerName('antitamper-term');
        $sent = self::injectZoneSentinel($this->salt);
        $body .= "\n" . $term . "\n";

        // ---- 1) 主体分段基线：salt 派生不等长边界 + 链式哈希 ----
        [$offs, $lens] = $this->segmentBounds(strlen($body), $segCount);
        $iv   = $this->salt->deriveHex('antitamper-iv', 40);
        $exps = [];
        $prev = $iv;
        foreach ($lens as $i => $len) {
            $seg = substr($body, $offs[$i], $len);
            $h   = $chain ? sha1($seg . $prev . $i) : sha1($seg);
            $exps[] = $h;
            $prev   = $h;
        }
        $chainHash = sha1(implode('', $exps));

        // ---- 2) 守卫内校验逻辑（整体编码，不以明文出现） ----
        $search = $mName . '=__FILE__;';
        $needle = str_replace("'", "\\'", $search);

        $g = '';
        // 公共闭包：链整体指纹 + 长度下限 + 分段（链式）哈希
        $g .= '$O=' . var_export($offs, true) . ';$L=' . var_export($lens, true)
            . ';$X=' . var_export($exps, true) . ';$IV=' . var_export($iv, true)
            . ';$CH=' . var_export($chainHash, true) . ';$BL=' . strlen($body) . ';';
        $g .= '$chk=function($b)use($O,$L,$X,$IV,$CH,$BL){';
        $g .= 'if(strlen($b)<$BL)return false;';
        $g .= 'if(sha1(implode(\'\',$X))!==$CH)return false;';
        $g .= '$n=count($O);for($i=0;$i<$n;$i++){';
        if ($chain) {
            $g .= '$p=($i===0)?$IV:$X[$i-1];';
            $g .= 'if(sha1(substr($b,(int)$O[$i],(int)$L[$i]).$p.$i)!==$X[$i])return false;';
        } else {
            $g .= 'if(sha1(substr($b,(int)$O[$i],(int)$L[$i]))!==$X[$i])return false;';
        }
        $g .= '}return true;};';
        // 尾区校验闭包：终结符之后只允许为空或注入哨兵开头（容忍一个前导换行）
        $sentQ = str_replace("'", "\\'", $sent);
        $g .= '$tail=function($b)use($BL){'
            . '$t=substr($b,$BL);if($t===\'\')return true;'
            . 'if(substr($t,0,1)==="\n")$t=substr($t,1);'
            . 'return $t===\'\'||substr($t,0,' . strlen($sent) . ')===\'' . $sentQ . '\';};';
        // P1 启动守卫：定位自身守卫行 → 掩码自校验 → 主体链校验 → 尾区校验
        $g .= '$c=@file_get_contents(' . $mName . ');';
        $g .= '$p=strpos($c,\'' . $needle . '\');if($p===false){' . $fail . '}';
        $g .= '$e=strpos($c,"\\n",$p);';
        $g .= '$line=($e===false)?substr($c,$p):substr($c,$p,$e-$p);';
        $g .= '$line=str_replace(' . $eName . ',\'' . $zeros . '\',$line);';
        $g .= 'if(sha1($line)!==' . $eName . '){' . $fail . '}';
        $g .= '$b=($e===false)?\'\':substr($c,$e+1);';
        $g .= 'if(!$chk($b)){' . $fail . '}';
        $g .= 'if(!$tail($b)){' . $fail . '}';
        // P2：独立 shutdown —— 重读磁盘做主体链复检（覆盖执行期被改写窗口）
        if ($shutdown) {
            $g .= '$sfa=function()use(' . $mName . ',$chk){';
            $g .= '$c=@file_get_contents(' . $mName . ');';
            $g .= '$e=strpos($c,"\\n",strpos($c,\'' . $needle . '\'));';
            $g .= '$b=($e===false)?\'\':substr($c,$e+1);';
            $g .= 'if(!$chk($b))exit(1);};';
            $g .= 'register_shutdown_function($sfa);';
        }
        // P3：独立 shutdown —— 守卫行自校验 + 尾区，走另一条代码路径（分散触发点）
        if ($shutdown && $multipoint) {
            $g .= '$sfb=function()use(' . $mName . ',$tail){';
            $g .= '$c=@file_get_contents(' . $mName . ');';
            $g .= '$p=strpos($c,\'' . $needle . '\');if($p===false)exit(1);';
            $g .= '$e=strpos($c,"\\n",$p);';
            $g .= '$line=($e===false)?substr($c,$p):substr($c,$p,$e-$p);';
            $g .= '$eh=$GLOBALS[\'' . ltrim($eName, '$') . '\']??\'\';';
            $g .= '$line=str_replace($eh,\'' . $zeros . '\',$line);';
            $g .= 'if(sha1($line)!==$eh)exit(1);';
            $g .= '$b=($e===false)?\'\':substr($c,$e+1);';
            $g .= 'if(!$tail($b))exit(1);};';
            $g .= 'register_shutdown_function($sfb);';
        }

        // 构建期自检：$g 会被编码后 eval，文件级 php -l 覆盖不到它，此处单独校验
        if (!$this->syntaxOk($g)) {
            $this->logger->warn('AntiTamper: 守卫逻辑(v3)语法自检失败，跳过自校验层');
            return ['code' => $code, 'ok' => false];
        }
        $gzBlob = (string) gzdeflate($g, 9);
        $blob   = base64_encode($gzBlob);
        $gzHash = sha1($gzBlob);

        // ---- 3) 守卫行 ----
        // 明文 blob 完整性闸（v3 新增，堵住 v2 盲区）：v2 的自校验逻辑藏在 $g（eval 内），
        // 攻击者改 blob 一个字节就能让 gzinflate 静默失败、@eval 不执行，自校验根本没机会跑。
        // 这里在 eval 之外先比对「解码后 gz 数据」的 sha1 —— 改 blob 任意字节即终止，
        // 且不依赖 eval 是否成功。之后仍走 $g 内的掩码法自校验（对守卫行整体求哈希）。
        $tpl = $mName . '=__FILE__;' . $eName . "='" . $zeros . "';"
            . "\$gb=base64_decode('" . $blob . "');"
            . "if(sha1(\$gb)!=='" . $gzHash . "'){" . $fail . "}"
            . "\$g=@eval(gzinflate(\$gb));";
        // 逐层壳自保护：norename 模式（不动变量名，否则 needle 自匹配 / 全局哈希变量名失配）
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

        $this->logger->debug('已注入（编码后）自校验防篡改外壳 v3（分段=' . count($offs)
            . ' 链式=' . ($chain ? 'on' : 'off') . ' 多点=' . ($multipoint ? 'on' : 'off') . '）');
        return ['code' => $final, 'ok' => true];
    }

    /**
     * salt 派生的不等长段边界：给每段一个 1..101 的权重按比例分配长度，
     * 保证连续覆盖、每段 >=1、总和恰为 $n。攻击者无法仅凭文件长度推出 O/L。
     * @return array{0:array<int,int>,1:array<int,int>} [offs, lens]
     */
    private function segmentBounds(int $n, int $k): array
    {
        $k = max(2, min($k, max(2, $n)));
        $w = [];
        for ($i = 0; $i < $k; $i++) {
            $w[] = 1 + $this->salt->deriveInt('antitamper-w-' . $i, 0, 100);
        }
        $sum  = array_sum($w);
        $offs = [];
        $lens = [];
        $off  = 0;
        for ($i = 0; $i < $k; $i++) {
            if ($i === $k - 1) {
                $len = $n - $off;
            } else {
                $left = $k - $i - 1; // 后面还要留 left 段，每段至少 1 字节
                $len  = (int) round($n * $w[$i] / $sum);
                $len  = max(1, min($len, $n - $off - $left));
            }
            $offs[] = $off;
            $lens[] = $len;
            $off += $len;
        }
        return [$offs, $lens];
    }

    /** 把 PHP 片段交给 php -l 校验（守卫逻辑构建期自检） */
    private function syntaxOk(string $src): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atchk') . '.php';
        file_put_contents($tmp, "<?php\n" . $src . "\n");
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        exec($cmd, $out, $code);
        @unlink($tmp);
        return $code === 0;
    }
}
