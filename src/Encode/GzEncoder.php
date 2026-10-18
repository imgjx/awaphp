<?php
/**
 * GZ 压缩编码 / 解包还原（GzEncoder）v2
 *
 * v1 弱点：整段产物压成单个 base64 串，`eval(gzinflate(base64_decode('...')))`
 * 一行定型，正则一条即可定位，且与压缩数据的对应关系一目了然。
 *
 * v2 对策（构建期确定性，salt 可复现）：
 *   1) 载荷分片乱序：base64 串切 1~6 片，存入若干拟真命名变量，
 *      运行期按正确顺序拼接——单条 base64 提取套路失效；
 *   2) 诱饵载荷：追加 1~2 个永不使用的诱饵压缩串，毒化"解码全部
 *      gzinflate(base64_decode(...))"的批量工具；
 *   3) 还原表达式随机化：解包语句在等价形式间随机挑选
 *      （直接 eval / 先赋值再 eval / 经 call_user_func 间接执行），
 *      避免 "eval(gzinflate(base64_decode" 固定指纹。
 */

declare(strict_types=1);

final class GzEncoder
{
    private NameStyle $style;

    public function __construct(
        private Logger $logger,
        private ?Salt $salt = null
    ) {
        $this->style = new NameStyle($salt ?? new Salt(''));
    }

    /**
     * @param array{decoy?:bool,chunks?:int} $opts
     */
    public function wrap(string $code, array $opts = []): string
    {
        $decoy  = (bool) ($opts['decoy'] ?? true);
        $chunks = max(1, min(6, (int) ($opts['chunks'] ?? 3)));

        // eval() 不解析 <?php 标签，需先剥离外层开/闭标签，仅压缩"纯 PHP 语句"
        $inner = preg_replace('/^\s*<\?(php)?\s*/i', '', $code);
        $inner = preg_replace('/\?>\s*$/', '', $inner);
        $inner = (string) $inner;
        $payload = base64_encode((string) gzdeflate($inner, 9));

        // ---- 分片乱序 + 拟真变量名 ----
        $parts = $this->chunk($payload, $chunks);
        $vars = [];
        foreach ($parts as $j => $part) {
            $vars[$j] = $this->style->varName('gz-seg-' . $j);
        }
        $order = $this->shuffle(range(0, count($parts) - 1), 'gz-order');
        $src = '';
        foreach ($order as $j) {
            $src .= $vars[$j] . "='" . $parts[$j] . "';";
        }
        // ---- 诱饵载荷：永不使用 ----
        if ($decoy) {
            $n = $this->pick('gz-decoy-n', 1, 2);
            for ($d = 0; $d < $n; $d++) {
                $dv = $this->style->varName('gz-decoy-' . $d);
                $fake = base64_encode((string) gzdeflate($this->bytes('gz-decoy-' . $d, 24), 9));
                $src .= $dv . "='" . $fake . "';";
            }
        }
        // ---- 按正确顺序拼接 + 随机化还原表达式 ----
        $src .= '$p=' . $vars[0];
        for ($j = 1; $j < count($parts); $j++) {
            $src .= '.' . $vars[$j];
        }
        $src .= ';';
        $src .= $this->decodeExpr();
        $out = "<?php " . $src;
        $this->logger->debug('已应用 GZ 压缩加壳 v2（分片=' . count($parts) . ' 诱饵=' . ($decoy ? 'on' : 'off') . '）');
        return $out;
    }

    /**
     * 还原执行表达式（三等价形式随机）：
     *   A. $d=gzinflate(base64_decode($p));@eval($d);
     *   B. $d=base64_decode($p);@eval(gzinflate($d));
     *   C. $d=base64_decode($p);$f=闭包;@eval($f($d));  （gzinflate 藏进闭包）
     */
    private function decodeExpr(): string
    {
        $form = $this->pick('gz-form', 0, 2);
        switch ($form) {
            case 0:
                return '$d=gzinflate(base64_decode($p));@eval($d);';
            case 1:
                return '$d=base64_decode($p);@eval(gzinflate($d));';
            default:
                // 间接解压：gzinflate 藏进闭包，避免与 eval( 相邻的固定指纹
                return '$d=base64_decode($p);$f=function($c){return gzinflate($c);};@eval($f($d));';
        }
    }

    private function pick(string $label, int $min, int $max): int
    {
        return $this->salt === null || $this->salt->isEmpty()
            ? random_int($min, $max)
            : $this->salt->deriveInt($label, $min, $max);
    }

    private function bytes(string $label, int $n): string
    {
        return $this->salt === null || $this->salt->isEmpty()
            ? random_bytes($n)
            : $this->salt->derive($label, $n);
    }

    /** 确定性洗牌（同 salt 复现同一顺序） */
    private function shuffle(array $arr, string $label): array
    {
        if ($this->salt === null || $this->salt->isEmpty()) {
            shuffle($arr);
            return $arr;
        }
        return $this->salt->shuffle($arr, $label);
    }

    /** 把字符串切成 n 片（随机切点），保证每片非空 */
    private function chunk(string $s, int $n): array
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
            $cut = $this->pick('gz-cut-' . $i, $lo, max($lo, $hi));
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
}
