<?php
/**
 * 授权绑定守卫（LicenseGuard）—— 域名绑定 + 有效期
 *
 * 商业交付场景常见诉求：混淆后的代码只允许在指定域名上运行、且在授权到期后失效。
 * 本模块在产物最外层注入一个轻量守卫：
 *
 *   1) 域名绑定：仅在 Web SAPI（fpm/apache2handler/cgi-fcgi…）下生效。
 *      CLI / phpdbg 下不校验 —— 否则命令行脚本、定时任务会被误杀。
 *      Host 取自 HTTP_HOST / SERVER_NAME，与授权域名做"后缀匹配"（支持泛子域）。
 *   2) 有效期：任何 SAPI 都生效，超过到期时间戳即停止执行。
 *
 * 设计约束（与其它守卫一致）：
 *   - 守卫逻辑整体编码进 gzdeflate + base64 壳，不以明文暴露；
 *   - 变量/函数名由 NameStyle 从业务词池生成，不留"这是授权校验"的指纹；
 *   - 无忙等、无网络请求、无额外依赖，运行期开销为常数级；
 *   - 未配置（空域名 + 空有效期）时完全不注入，对产物零影响。
 */

declare(strict_types=1);

final class LicenseGuard
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param string $code    源码
     * @param string $domains 授权域名，逗号分隔（如 "a.com,b.com"），空=不校验
     * @param string $expire  到期时间，Y-m-d 或 Y-m-d H:i:s，空=永不过期
     * @return array{code:string,applied:bool}
     */
    public function wrap(string $code, string $domains, string $expire): array
    {
        $list = array_values(array_filter(array_map(
            static fn ($d) => strtolower(trim($d)),
            explode(',', $domains)
        ), static fn ($d) => $d !== ''));

        $ts = 0;
        $expire = trim($expire);
        if ($expire !== '') {
            $t = strtotime($expire);
            if ($t === false) {
                $this->logger->warn("授权到期时间无法解析：'{$expire}'，已忽略有效期校验");
            } else {
                $ts = $t;
            }
        }

        if ($list === [] && $ts === 0) {
            return ['code' => $code, 'applied' => false];
        }

        $style = new NameStyle($this->salt);
        $vSapi = $style->varName('lic-sapi');
        $vHost = $style->varName('lic-host');
        $vOk   = $style->varName('lic-ok');
        $vD    = $style->varName('lic-domain');

        $g = '';
        // ---- 有效期校验（全 SAPI 生效）----
        if ($ts > 0) {
            // 时间戳以十六进制字面量（0x…）写入，避免一眼看出到期日
            $g .= 'if(time()>0x' . dechex($ts) . '){exit(0);}';
        }
        // ---- 域名校验（仅 Web SAPI）----
        if ($list !== []) {
            // 注意：这里必须用单引号拼接 —— 双引号会把 $_SERVER[...] 当成变量插值掉
            $g .= $vSapi . '=php_sapi_name();';
            $g .= 'if(' . $vSapi . '!==\'cli\'&&' . $vSapi . '!==\'phpdbg\'){';
            // 去掉端口：HTTP_HOST 形如 "a.com:8080"，不剥离会导致带端口的正常访问被误杀
            $g .= $vHost . '=explode(\':\',strtolower((string)($_SERVER[\'HTTP_HOST\']??($_SERVER[\'SERVER_NAME\']??\'\'))))[0];';
            $g .= $vOk . '=false;';
            foreach ($list as $d) {
                $g .= 'if(str_ends_with(' . $vHost . ',' . $this->quote(strtolower($d)) . ')){' . $vOk . '=true;}';
            }
            // 兜底：也接受显式 localhost 直连调试（避免本地集成测试误杀）
            $g .= 'if(' . $vHost . '===\'localhost\'||' . $vHost . '===\'127.0.0.1\'){' . $vOk . '=true;}';
            $g .= 'if(!' . $vOk . '){http_response_code(403);exit(0);}';
            $g .= '}';
        }

        $blob = base64_encode((string) gzdeflate($g, 9));
        $stmt = "@eval(gzinflate(base64_decode('" . $blob . "')));\n";
        $body = (string) preg_replace('/^\s*<\?(php)?/i', '', $code, 1);

        // declare(strict_types=1) 必须是文件第一条语句，守卫若插在它前面会直接致命错误，
        // 因此遇到首条 declare 时把守卫放到它之后。
        if (preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/i', $body, $m)) {
            $head = $m[0];
            $rest = substr($body, strlen($m[0]));
            $out = '<?php ' . $head . $stmt . $rest;
        } else {
            $out = '<?php ' . $stmt . $body;
        }

        $this->logger->debug('已注入授权绑定守卫（域名 ' . count($list) . ' 个，有效期 ' . ($ts > 0 ? date('Y-m-d H:i:s', $ts) : '不限') . '）');
        return ['code' => $out, 'applied' => true];
    }

    private function quote(string $s): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
    }
}
