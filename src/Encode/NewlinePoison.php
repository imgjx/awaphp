<?php
/**
 * 结构指纹毒化壳 · 换行缠绕模块（NewlinePoison）
 *
 * 在最终源码中注入大量换行符（\n）。配额规则（1.0.9 起）：
 *
 *     换行总数 = ceil(输入源码字节数 ÷ divisor)，divisor 默认 2（即 1/2），
 *     并受 min 下限保护（默认 0，仅用于极小文件兜底）。例如 10KB 源码注入约 5000 个换行。
 *
 * 这些换行**全部插入代码内部**（token 与 token 之间的安全边界），不留"尾部死区"：
 *   - 主体：均匀散布在整份源码的每个 token 边界上（语句间、表达式内、括号里），
 *     使换行数远超"行"的数量级，任何"按行美化 / 去空行 / 压缩空白"的批量处理都会
 *     改动大量字节；
 *   - 串内：按 string_ratio 把一部分换行嵌进【base64_decode() 的长载荷字面量】，
 *     成为密文数据的一部分（base64 解码忽略空白 → 运行行为不变）；
 *   - 兜底：候选边界不足或未落地时，剩余配额补到**文件头部**（紧跟 <?php 之后），
 *     而不是补到文件尾部——头部是"美化/清理"最先动手、也最容易整段删掉的位置，
 *     删掉即改动自校验/指纹区段，比尾部死区更能触发毒化。
 *
 * 与结构指纹毒化壳（StructPoison）、自校验（AntiTamper）配合：本模块须在二者【之前】
 * 应用，使换行被纳入指纹/区段哈希——删掉任何一个换行，解密密钥或区段哈希即错位。
 *
 * 分层/散装管线中，配额按"每一层壳"平均分摊（见 Obfuscator::poisonTick()）：
 * 每层壳各自注入一份，越内层的换行越被后续壳加密进密文，形成层层纠缠。
 *
 * 设计要点：
 *   - 只在 token 边界插入，绝不改动 token 内部（字符串/注释/heredoc 一律跳过），
 *     php -l 必然通过；
 *   - 串内嵌入仅针对 base64_decode() 的纯 base64 载荷（不碰普通长字符串常量，
 *     避免把"看着像 base64"的有效数据插坏——那类错误 php -l 查不出来）；
 *   - 插入位置由 Salt 派生的确定性 RNG 选取（seed_tag 逐层区分，可复现构建）。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Salt.php';
require_once __DIR__ . '/../Support/TokenScan.php';

final class NewlinePoison
{
    /** 上一次 wrap() 实际注入的换行数（供调用方核对配额是否落地）。 */
    public int $lastCount = 0;

    public function __construct(
        private Logger $logger,
        private ?Salt $salt = null
    ) {
    }

    /**
     * 由"输入源码字节数"计算目标换行数：ceil(bytes / divisor)，且不小于 min。
     *
     * @param array{divisor?:int, min?:int, ratio?:float, bytes?:int} $opts
     */
    public static function targetFor(int $bytes, array $opts = []): int
    {
        $min = max(0, (int) ($opts['min'] ?? 0));
        $divisor = (int) ($opts['divisor'] ?? 2);
        if ($bytes > 0 && $divisor >= 1) {
            return max((int) ceil($bytes / $divisor), $min);
        }
        // 兼容旧语义（仅给按行数比例时）：bytes 未知则退回 min
        return $min;
    }

    /**
     * @param array{
     *     count?:int, bytes?:int, divisor?:int, min?:int, ratio?:float,
     *     head?:int, string_ratio?:float, seed_tag?:string
     * } $opts
     */
    public function wrap(string $code, array $opts = []): string
    {
        $this->lastCount = 0;

        $count = array_key_exists('count', $opts)
            ? (int) $opts['count']
            : self::targetFor((int) ($opts['bytes'] ?? 0), $opts);
        $count = max(0, $count);
        $head = max(0, (int) ($opts['head'] ?? 0));
        if ($count === 0 && $head === 0) {
            return $code;
        }

        $stringRatio = (float) ($opts['string_ratio'] ?? 0.4);
        if ($stringRatio < 0.0 || $stringRatio > 1.0) {
            $stringRatio = 0.4;
        }

        $rng = $this->makeRng((string) ($opts['seed_tag'] ?? ''));
        $tokens = token_get_all($code);
        $n = count($tokens);
        if ($n === 0) {
            return $code;
        }

        // ---- 1) 候选插入点：所有"安全的 token 边界"（见 TokenScan 的安全规则） ----
        $scan = TokenScan::safeBoundaries($tokens);
        $cands = $scan['bounds'];
        $openIdx = $scan['open'];

        // ---- 2) 串内嵌入：只挑 base64_decode() 的长载荷字面量 ----
        $stringLens = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $len = strlen($t[1]);
            if ($len < 256) {
                continue;
            }
            $inner = substr($t[1], 1, -1);
            if ($inner === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $inner) !== 1) {
                continue;
            }
            if (!$this->isBase64DecodeArg($tokens, $i)) {
                continue; // 非 base64_decode 载荷 → 插入会改坏数据
            }
            $stringLens[$i] = $len;
        }
        arsort($stringLens);

        $placed = 0;
        $stringInserts = [];   // tokenIndex => [offset...]（升序）
        $stringBudget = (int) ($count * $stringRatio);
        foreach ($stringLens as $i => $len) {
            if ($placed >= $stringBudget) {
                break;
            }
            $cap = min((int) ($len / 3), 60, $stringBudget - $placed);
            if ($cap <= 0) {
                continue;
            }
            $offs = $this->pickOffsets($len, $cap, $rng);
            if ($offs !== []) {
                $stringInserts[$i] = $offs;
                $placed += count($offs);
            }
        }

        // ---- 3) 主体：把剩余配额均匀撒到全部 token 边界 ----
        $body = $count - $placed;
        $counts = [];   // tokenIndex => 该处插入的换行数
        $cn = count($cands);
        if ($body > 0 && $cn > 0) {
            $base = intdiv($body, $cn);
            $rem = $body % $cn;
            if ($base > 0) {
                foreach ($cands as $ti) {
                    $counts[$ti] = $base;
                }
            }
            if ($rem > 0) {
                $order = $this->shuffle(range(0, $cn - 1), $rng);
                for ($k = 0; $k < $rem; $k++) {
                    $ti = $cands[$order[$k]];
                    $counts[$ti] = ($counts[$ti] ?? 0) + 1;
                }
            }
            $placed = $count;
        }

        // ---- 4) 重建（串内换行 + 边界换行 + 头部兜底） ----
        $headLeft = $head + max(0, $count - $placed); // 未落地的配额全部归入头部兜底
        $headAt = ($openIdx >= 0) ? $openIdx : ($n - 1);
        $headPlaced = 0;

        $out = '';
        foreach ($tokens as $i => $tok) {
            if (isset($stringInserts[$i])) {
                $out .= $this->embedInString($tok[1], $stringInserts[$i]);
            } else {
                $out .= is_array($tok) ? $tok[1] : $tok;
            }
            if (isset($counts[$i])) {
                $out .= str_repeat("\n", $counts[$i]);
            }
            if ($i === $headAt && $headLeft > 0) {
                $out .= str_repeat("\n", $headLeft);
                $headPlaced = $headLeft;
                $headLeft = 0;
            }
        }
        if ($headLeft > 0) {
            // 极端情况（无 token / 无开标签）：退化为尾部追加，保证配额达标
            $out .= str_repeat("\n", $headLeft);
            $headPlaced = $headLeft;
        }

        $this->lastCount = $this->countInserted($stringInserts) + array_sum($counts) + $headPlaced;

        $this->logger->debug(sprintf(
            '已注入换行缠绕：目标=%d 实际=%d（串内=%d 边界=%d 头部兜底=%d 边界点=%d）',
            $count + $head,
            $this->lastCount,
            $this->countInserted($stringInserts),
            array_sum($counts),
            $headPlaced,
            $cn
        ));
        return $out;
    }

    /** @param array<int,array<int,int>> $ins */
    private function countInserted(array $ins): int
    {
        $c = 0;
        foreach ($ins as $offs) {
            $c += count($offs);
        }
        return $c;
    }

    /**
     * 判断 $i 处的字符串是否直接作为 base64_decode(...) 的实参。
     * 这类串的解码器忽略空白，串内嵌换行不影响运行；其它长串一律不碰。
     *
     * @param array<int,mixed> $tokens
     */
    private function isBase64DecodeArg(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($k = $i - 1; $k >= 0; $k--) {
            $t = $tokens[$k];
            $tx = is_array($t) ? $t[1] : $t;
            if ($tx === ')') {
                $depth++;
                continue;
            }
            if ($tx === '(') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                // 找到包住该字面量的左括号：看它前面的函数名
                for ($j = $k - 1; $j >= 0; $j--) {
                    $p = $tokens[$j];
                    if (!is_array($p)) {
                        return false;
                    }
                    if ($p[0] === T_WHITESPACE || $p[0] === T_COMMENT || $p[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    if ($p[0] === T_STRING || $p[0] === T_NAME_FULLY_QUALIFIED || $p[0] === T_NAME_QUALIFIED) {
                        $name = strtolower(ltrim($p[1], '\\'));
                        return $name === 'base64_decode' || str_ends_with($name, '\\base64_decode');
                    }
                    return false;
                }
                return false;
            }
        }
        return false;
    }

    /**
     * 在字符串字面量（含引号）的指定字符偏移处插入换行。
     * 偏移按降序处理以避免索引漂移；offsets 均落在 [1, len-1]，绝不插在引号外侧。
     * @param array<int,int> $offs 升序偏移
     */
    private function embedInString(string $literal, array $offs): string
    {
        $res = $literal;
        for ($j = count($offs) - 1; $j >= 0; $j--) {
            $o = $offs[$j];
            $res = substr($res, 0, $o) . "\n" . substr($res, $o);
        }
        return $res;
    }

    /**
     * 在 [1, len-1] 内选取 $cap 个互不相同的偏移（升序返回）。
     * 采用拒绝采样：$cap 至多 60，避免对超大 base64 载荷构建 range(1, len-1) 数组（内存 O(cap)）。
     * @return array<int,int>
     */
    private function pickOffsets(int $len, int $cap, callable $rng): array
    {
        if ($len < 3) {
            return [];
        }
        $cap = min($cap, $len - 2);
        $seen = [];
        $offs = [];
        $guard = $cap * 40 + 100;
        while (count($offs) < $cap && $guard-- > 0) {
            $o = 1 + (int) ($rng() * ($len - 1));
            if ($o < 1) {
                $o = 1;
            } elseif ($o > $len - 1) {
                $o = $len - 1;
            }
            if (!isset($seen[$o])) {
                $seen[$o] = true;
                $offs[] = $o;
            }
        }
        sort($offs);
        return $offs;
    }

    /** 完整 Fisher-Yates 洗牌，返回新数组。 */
    private function shuffle(array $arr, callable $rng): array
    {
        $count = count($arr);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = (int) (($i + 1) * $rng());
            if ($j > $i) {
                $j = $i;
            }
            $tmp = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }
        return $arr;
    }

    /**
     * 由 Salt + 层标签派生确定性种子，构造 mulberry32 随机数发生器（返回 [0,1) 闭包）。
     * seed_tag 使同一文件的不同壳层得到不同分布形态（避免各层换行位置完全重合）。
     */
    private function makeRng(string $tag = ''): callable
    {
        $label = 'newline-poison' . ($tag !== '' ? ':' . $tag : '');
        $seedHex = ($this->salt !== null && !$this->salt->isEmpty())
            ? $this->salt->derive($label, 8)
            : bin2hex(random_bytes(4));
        $seed = (int) hexdec(substr($seedHex, 0, 8)) & 0x7fffffff;
        return function () use (&$seed): float {
            $seed = ($seed + 0x6D2B79F5) & 0xffffffff;
            $t = $seed;
            $t = (($t ^ ($t >> 15)) * (1 | $t)) & 0xffffffff;
            $t = (($t ^ ($t >> 7)) * (61 | $t)) & 0xffffffff;
            $t = ($t ^ ($t >> 14)) & 0xffffffff;
            return ($t >> 0) / 4294967296;
        };
    }
}
