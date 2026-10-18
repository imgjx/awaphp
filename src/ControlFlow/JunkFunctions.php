<?php
/**
 * 诱饵函数注入（JunkFunctions）
 *
 * 在产物末尾追加 N 个永不调用、行为无害的诱饵函数，用于膨胀体积、干扰逆向者
 * 对真实函数调用关系的判断。
 *
 * 拟真化（v2）：
 *   - 命名：由 NameStyle 从业务词汇池生成（validate_handler / getCacheStore ...），
 *     不再使用 __junk_ 前缀这类一眼假的指纹；
 *   - 函数体：从多种"像真实工具函数"的形态中按盐随机挑选（含参数、默认值、
 *     常见标准库调用），而非 $x=0x9; md5($x) 这类模板；
 *   - 纯函数、无副作用、永不调用 -> 零运行期开销。
 *
 * 诱饵函数整体被编码进 gzinflate/base64 壳，不以明文暴露。
 */

declare(strict_types=1);

final class JunkFunctions
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    public function inject(string $code, int $count): string
    {
        $count = max(1, $count);
        $style = new NameStyle($this->salt);
        $used = [];
        $fns = '';
        for ($i = 0; $i < $count; $i++) {
            $name = $style->funcName('junk-fn-' . $i);
            // 避免与原代码/内置函数/已生成的诱饵重名
            $n = 0;
            while (isset($used[$name]) || function_exists($name) || str_contains($code, $name)) {
                $name = $style->funcName('junk-fn-' . $i . '-' . (++$n));
            }
            $used[$name] = true;
            $fns .= $this->plausibleBody($name, $style, $i) . "\n";
        }
        // 将诱饵函数整体编码进 gzinflate/base64 壳，避免明文暴露；运行期定义后永不调用。
        $blob = base64_encode((string) gzdeflate($fns, 9));
        $shell = "eval(gzinflate(base64_decode('" . $blob . "')));\n";
        $code = preg_replace('/\?>\s*$/', '', $code);
        $code .= $shell;
        $this->logger->debug('已注入 ' . $count . ' 个（拟真 + 编码）诱饵函数');
        return $code;
    }

    /**
     * 生成一个"看起来像真实业务工具函数"的纯函数体。
     * 全部为无副作用纯函数：不写全局、不输出、不 touch 外部状态。
     */
    private function plausibleBody(string $name, NameStyle $style, int $i): string
    {
        $p1 = ltrim($style->varName('junk-p1-' . $i), '$');
        $p2 = ltrim($style->varName('junk-p2-' . $i), '$');
        $form = $this->salt->deriveInt('junk-form-' . $i, 0, 5);
        switch ($form) {
            case 0: // 路径规整类
                return "function {$name}(\${$p1}){"
                    . "\${$p1}=trim((string)\${$p1});"
                    . "if(\${$p1}===''){return null;}"
                    . "return rtrim(str_replace('\\\\','/',\${$p1}),'/'); }";
            case 1: // 数值钳制类
                return "function {$name}(\${$p1},\${$p2}=0){"
                    . "return max(0,(int)\${$p1})+(int)\${$p2}; }";
            case 2: // 摘要指纹类
                return "function {$name}(\${$p1}){"
                    . "return substr(sha1((string)\${$p1}),0,12); }";
            case 3: // 定长裁剪类
                return "function {$name}(\${$p1},\${$p2}){"
                    . "\$n=(int)\${$p2};if(\$n<=0){return '';}"
                    . "return str_pad(mb_substr((string)\${$p1},0,\$n),\$n,' ',STR_PAD_RIGHT); }";
            case 4: // 键值映射类
                return "function {$name}(\${$p1},\${$p2}=null){"
                    . "static \$m_{$p1}=null;"
                    . "if(\$m_{$p1}===null){\$m_{$p1}=array_flip(str_split('abcdefghijklmnopqrstuvwxyz'));}"
                    . "if(\${$p2}===null){return \$m_{$p1};}"
                    . "return isset(\$m_{$p1}[(string)\${$p2}])?\$m_{$p1}[(string)\${$p2}]:-1; }";
            default: // 序列化比对类
                return "function {$name}(\${$p1},\${$p2}){"
                    . "return json_encode([\${$p1},\${$p2}])===json_encode([\${$p2},\${$p1}])?1:0; }";
        }
    }
}
