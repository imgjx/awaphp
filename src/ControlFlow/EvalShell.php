<?php
/**
 * EVAL 虚拟加壳（EvalShell）v2
 *
 * v1 弱点（外部测评指出）：多层 eval 只是"压缩 + base64"，嵌套纯静态，
 * 攻击者用一条正则提取所有 base64_decode('...') 即可逐层拆开。
 *
 * v2 对策（每层随机组合，构建时可复现）：
 *   1) 载荷方案随机：
 *        A. gzdeflate + base64（传统）
 *        B. XOR 流加密（密钥 = sha256(seed) 前 8 字节，seed 前置随载荷存储，
 *           密钥本身不再单独明写）+ base64
 *        C. gzdeflate 后再 XOR（双变换）+ base64
 *        D. strrev + gzdeflate + base64
 *   2) 载荷分片乱序：base64 串切成 3~5 片，存入若干"拟真命名"变量，
 *      运行期按正确顺序拼接。正则提取单条 base64 的套路直接失效；
 *   3) 诱饵变量：插入 1 个永不适用的随机 base64 串，毒化"解码全部 base64"的批量工具。
 *
 * 随机性来源：salt 非空时全部由 salt 派生（同一 salt + 同一输入 => 逐字节可复现）；
 * salt 为空时使用真随机（避免"全用户同构"的指纹）。
 */

declare(strict_types=1);

final class EvalShell
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    public function wrap(string $code, int $layers): string
    {
        $layers = max(1, $layers);
        $style = new NameStyle($this->salt);
        $cur = $code;
        for ($i = 0; $i < $layers; $i++) {
            $inner = preg_replace('/^\s*<\?php/i', '', $cur, 1);
            $inner = preg_replace('/\?>\s*$/', '', $inner);
            if ($inner === '') {
                $inner = ' ';
            }
            $cur = "<?php " . $this->buildLayer($inner, $i, $style);
        }
        $this->logger->debug("已应用 EVAL 加壳 v2（{$layers} 层）");
        return $cur;
    }

    private function buildLayer(string $inner, int $idx, NameStyle $style): string
    {
        $scheme = $this->pick('eval-scheme-' . $idx, 0, 3);
        $seed = $this->bytes('eval-seed-' . $idx, 8);

        switch ($scheme) {
            case 0: // gzdeflate + base64
                $payload = base64_encode((string) gzdeflate($inner, 9));
                $decode = '$d=gzinflate(base64_decode($p));';
                break;
            case 1: // XOR 流加密（密钥由 seed 派生，不单独存储）
                $k = substr(hash('sha256', $seed, true), 0, 8);
                $payload = base64_encode($seed . $this->xorStr($inner, $k));
                $decode = '$r=base64_decode($p);'
                    . '$k=substr(hash(\'sha256\',substr($r,0,8),true),0,8);'
                    . '$x=substr($r,8);'
                    . '$d=$x^substr(str_repeat($k,(int)(strlen($x)/8)+2),0,strlen($x));';
                break;
            case 2: // gzdeflate 后再 XOR（双变换）
                $k = substr(hash('sha256', $seed, true), 0, 8);
                $payload = base64_encode($seed . $this->xorStr((string) gzdeflate($inner, 9), $k));
                $decode = '$r=base64_decode($p);'
                    . '$k=substr(hash(\'sha256\',substr($r,0,8),true),0,8);'
                    . '$x=substr($r,8);'
                    . '$x=$x^substr(str_repeat($k,(int)(strlen($x)/8)+2),0,strlen($x));'
                    . '$d=gzinflate($x);';
                break;
            default: // strrev + gzdeflate + base64
                $payload = base64_encode(strrev((string) gzdeflate($inner, 9)));
                $decode = '$d=gzinflate(strrev(base64_decode($p)));';
                break;
        }

        // ---- 分片乱序 + 诱饵变量 ----
        $parts = $this->chunk($payload, $this->pick('eval-chunks-' . $idx, 3, 5), $idx);
        $vars = [];
        foreach ($parts as $j => $part) {
            $vars[$j] = $style->varName('eval-seg-' . $idx . '-' . $j);
        }
        $decoy = $style->varName('eval-decoy-' . $idx);

        $src = '';
        // 确定性洗牌：同 salt 复现同一顺序（空 salt 才真随机）
        $order = $this->salt->shuffle(range(0, count($parts) - 1), 'eval-order-' . $idx);
        foreach ($order as $j) {
            $src .= $vars[$j] . "='" . $parts[$j] . "';";
        }
        // 诱饵：随机 base64 串，永不使用，毒化"解码全部 base64"的批量工具
        $src .= $decoy . "='" . base64_encode($this->bytes('eval-decoy-' . $idx, 48)) . "';";
        $src .= '$p=' . $vars[0];
        for ($j = 1; $j < count($parts); $j++) {
            $src .= '.' . $vars[$j];
        }
        $src .= ';' . $decode . '@eval($d);';
        return $src;
    }

    /** salt 非空时确定性选择，为空时真随机（避免同构指纹） */
    private function pick(string $label, int $min, int $max): int
    {
        return $this->salt->isEmpty()
            ? random_int($min, $max)
            : $this->salt->deriveInt($label, $min, $max);
    }

    private function bytes(string $label, int $n): string
    {
        return $this->salt->isEmpty()
            ? random_bytes($n)
            : $this->salt->derive($label, $n);
    }

    /** 把字符串切成 n 片（随机切点），保证每片非空 */
    private function chunk(string $s, int $n, int $idx = 0): array
    {
        $n = max(1, $n);
        if ($n === 1 || strlen($s) < $n * 2) {
            return [$s];
        }
        $cuts = [];
        $prev = 0;
        for ($i = 0; $i < $n - 1; $i++) {
            $lo = $prev + 1;
            $hi = strlen($s) - ($n - 1 - $i) - 1;
            $cut = $this->pick('eval-cut-' . $idx . '-' . $i, $lo, max($lo, $hi));
            $cuts[] = $cut;
            $prev = $cut;
        }
        $parts = [];
        $last = 0;
        foreach ($cuts as $c) {
            $parts[] = substr($s, $last, $c - $last);
            $last = $c;
        }
        $parts[] = substr($s, $last);
        return $parts;
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
