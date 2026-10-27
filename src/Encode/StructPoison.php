<?php
/**
 * 结构指纹毒化壳（StructPoison）
 *
 * 与 anti_tamper 的本质区别：anti_tamper 的校验结果是【控制流】（命中 → exit，
 * 破解者 hook 掉 exit / NOP 掉跳转即可绕过）；本壳的校验结果是【数据流】——
 * 「文件是否被改动」「运行环境是否被插桩」直接参与解密密钥流的派生，不存在任何
 * 可绕过的校验分支：
 *
 *   构建期：指纹 h = sha256(骨架全文)，骨架中载荷密文位置为【定长占位串】；
 *           密钥流 = KDF(h, probe=0)（h 首尾拼接迭代 rounds 轮）；
 *           payload = base64( gzdeflate(inner) XOR keystream )，定长替换占位。
 *   运行期：读自身文件 → 用 payload 实际值把密文替换回等长占位串 → 重算指纹 h'；
 *           probe = uopz / runkit 等"动态改函数"扩展的在场计数；
 *           密钥流 = KDF(h' · h' · probe)。文件未改动且无插桩时 h' === h、probe === 0，
 *           两条 KDF 路径逐字节一致；否则密钥错位 → gzinflate 得到乱码 → eval 报错
 *           或静默错乱。
 *
 * 对破解者的效果：
 *   - hook eval 抓明文：hook 需改文件 → 指纹变 → eval 收到乱码，且【没有任何
 *     报错指向本壳】（没有比较、没有分支、没有退出点可下断点）；
 *   - 用 uopz / runkit 在【不改文件】的前提下挂 gzinflate / eval：probe 计数非 0
 *     → 密钥同样错位 → 拿到的只有乱码（这条是最常见的绕过路径）；
 *   - 想定位耦合点：只有拟真命名的 hash 调用链，删除/改写任何一环 = 密钥必错；
 *   - 文件未改动时首层可被正常脱壳（与所有外壳一致），但脱壳后在【本壳层】的
 *     任何修改回写都会让后续 dump 全部毒化。
 *
 * 指纹对象是【除载荷密文外的完整最终文件】，因此本壳必须是【最外层】壳
 * （orderShellLayers 中位于 license 之后），且文件头 banner 必须注入在它之内。
 */

declare(strict_types=1);

final class StructPoison
{
    public function __construct(
        private Logger $logger,
        private ?Salt $salt = null
    ) {
    }

    public function wrap(string $code, array $opts = []): string
    {
        $rounds = max(1, min(8, (int) ($opts['rounds'] ?? 3)));

        // eval() 不解析 <?php 标签，剥离外层开/闭标签得到纯 PHP 语句
        $inner = preg_replace('/^\s*<\?(php)?\s*/i', '', $code);
        $inner = preg_replace('/\?>\s*$/', '', $inner);
        $inner = (string) $inner;

        $deflated = (string) gzdeflate($inner, 9);
        // base64 密文定长（(n+2)/3*4），据此生成等长占位串
        $b64len = (int) ((strlen($deflated) + 2) / 3) * 4;
        $placeholder = str_repeat('A', max(8, $b64len));

        // ---- 壳变量命名：$__sp 前缀独立命名空间 ----
        // 不用 NameStyle 拟真名池：eval 出的内层代码变量同池会撞名（壳变量被内层
        // 代码读到 → 大量未定义变量警告）。$__sp* 前缀不在混淆拟真词池中，天然隔离。
        $v = [];
        foreach (['load', 'src', 'ph', 'fp', 'seed', 'ks', 'raw', 'fn', 'i', 'n', 'pr', 'chk', 'p', 'q'] as $k) {
            $suffix = ($this->salt !== null && !$this->salt->isEmpty())
                ? substr(bin2hex($this->salt->derive('sp-var-' . $k, 3)), 0, 6)
                : dechex(crc32($k));
            $v[$k] = '$__sp' . $suffix;
        }
        $vLoad = $v['load'];

        // ---- 受护区边界标记 ----
        // 指纹只覆盖【本壳自起点标记到终点标记】这一段，而非整个文件：
        // 这样 anti_tamper 可以在本壳【之外】再包一层守卫（它自己的明文锚点必须在
        // 文件里可见），它前置的守卫行 / 尾部终结符落在标记之外，不会破坏指纹恒等式；
        // 反过来，本壳保护自己的整段（含载荷与全部解码逻辑），任何人改动这段即毒化。
        $mk = ($this->salt !== null && !$this->salt->isEmpty())
            ? substr(bin2hex($this->salt->derive('sp-mark', 6)), 0, 10)
            : dechex(crc32('sp-mark'));
        $sm = '/*sp' . $mk . '*/';
        $em = '/*sx' . $mk . '*/';

        // ---- 骨架：载荷位置为占位串 ----
        // 注意：$vLoad 的值本身已带 '$' 前缀，这里绝不能写成 "\${$vLoad}"
        // （会拼出 $$__spXXX 可变变量 → 壳体变量永不赋值）
        $skel = "<?php {$sm}{$vLoad}='{$placeholder}';" . $this->runtime($rounds, $v, $sm, $em) . $em;

        // ---- 构建期指纹与密钥流（probe 恒为 0）----
        $bp = (int) strpos($skel, $sm);
        // 终点标记在运行期代码里也以字符串字面量出现一次，必须取【最后一次】出现，
        // 否则受护区会被截断到字面量处（后半段解码逻辑不受保护）。
        $bq = (int) strrpos($skel, $em);
        $h = hash('sha256', substr($skel, $bp, $bq - $bp + strlen($em)));
        $seed = hash('sha256', $h . $h . '0');
        for ($i = 1; $i < $rounds; $i++) {
            $seed = hash('sha256', $seed . $h . $i . '0');
        }
        $stream = (string) hex2bin($seed); // 64 hex = 32 字节，与 XOR 的 i%32 索引匹配

        $cipher = $deflated;
        for ($i = 0, $n = strlen($cipher); $i < $n; $i++) {
            $cipher[$i] = $cipher[$i] ^ $stream[$i % 32];
        }
        $payload = base64_encode($cipher);

        // ---- 定长替换：骨架其余部分逐字节不变，指纹恒等式成立 ----
        $out = str_replace($placeholder, $payload, $skel);

        $this->logger->debug('已应用结构指纹毒化壳（KDF 轮数=' . $rounds . ' 载荷=' . strlen($payload) . 'B）');
        return $out;
    }

    /**
     * 运行期还原逻辑（拟真命名 + 间接函数调用，避免显眼指纹）。
     * 指纹 h' 与插桩探针只进密钥派生：未篡改且无插桩时与构建期 KDF 路径逐字节一致；
     * 否则密钥错位（毒化），全程无比较、无分支、无退出点。
     *
     * @param array<string,string> $v 拟真变量名表
     */
    private function runtime(int $rounds, array $v, string $sm, string $em): string
    {
        $code = '';
        // 读自身文件（函数名拆串 + 变量间接调用，规避 grep 指纹）
        $code .= "{$v['fn']}='file" . "_get_contents';{$v['src']}={$v['fn']}(__FILE__);";
        // 用载荷实际值把密文替换回等长占位串，还原构建期骨架
        $code .= "{$v['ph']}=str_repeat('A',strlen({$v['load']}));";
        $code .= "{$v['src']}=str_replace({$v['load']},{$v['ph']},{$v['src']});";
        // 运行期指纹 h'：仅覆盖【受护区】（起点标记 → 终点标记），不把外层壳算进来
        $code .= "{$v['p']}=strpos({$v['src']},'{$sm}');{$v['q']}=strrpos({$v['src']},'{$em}');";
        $code .= "{$v['fp']}=hash('sha256',substr({$v['src']},{$v['p']},{$v['q']}-{$v['p']}+" . strlen($em) . "));";
        // 插桩探针：uopz / runkit 等"运行期改写函数"扩展的在场计数。
        // 这类扩展可在【不改文件】的前提下挂住 gzinflate/eval —— 最省事的绕过路径，
        // 这里把结果直接并入密钥派生，命中即解出乱码（无检测分支、无声失败）。
        $code .= "{$v['pr']}=(int)function_exists('uopz_set_return')+(int)function_exists('uopz_backup')"
            . "+(int)function_exists('uopz_redefine')+(int)function_exists('runkit7_function_redefine')"
            . "+(int)function_exists('runkit7_function_rename')+(int)function_exists('override_function');";
        // KDF：k0 = sha256(h'.h'.probe)，ki = sha256(k(i-1).h'.i.probe)
        $code .= "{$v['seed']}=hash('sha256',{$v['fp']}.{$v['fp']}.{$v['pr']});";
        for ($i = 1; $i < $rounds; $i++) {
            $code .= "{$v['seed']}=hash('sha256',{$v['seed']}.{$v['fp']}.'{$i}'.{$v['pr']});";
        }
        // 干扰项：看着像"完整性校验值"的派生链，结果永不参与任何判断（钓逆向者下断点）
        $code .= "{$v['chk']}=crc32(substr({$v['src']},-4096));";
        $code .= "{$v['chk']}=hash('sha256',(string){$v['chk']});";
        // 密钥流 + XOR + 解压执行（形式随机化防固定指纹）
        $form = $this->pick('sp-form', 0, 2);
        $code .= "{$v['ks']}=hex2bin({$v['seed']});";
        $code .= "{$v['raw']}=base64_decode({$v['load']});";
        $code .= "for({$v['i']}=0,{$v['n']}=strlen({$v['raw']});{$v['i']}<{$v['n']};{$v['i']}++){{$v['raw']}[{$v['i']}]={$v['raw']}[{$v['i']}]^{$v['ks']}[{$v['i']}%32];}";
        switch ($form) {
            case 0:
                $code .= "@eval(gzinflate({$v['raw']}));";
                break;
            case 1:
                $code .= "{$v['fn']}='gzin'.'flate';@eval({$v['fn']}({$v['raw']}));";
                break;
            default:
                $code .= "{$v['fn']}=function(\$x){return gzinflate(\$x);};@eval({$v['fn']}({$v['raw']}));";
        }
        return $code;
    }

    private function pick(string $label, int $min, int $max): int
    {
        return $this->salt === null || $this->salt->isEmpty()
            ? random_int($min, $max)
            : $this->salt->deriveInt($label, $min, $max);
    }
}
