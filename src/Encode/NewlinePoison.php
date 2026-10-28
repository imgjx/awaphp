<?php
/**
 * 结构指纹毒化壳 · 换行缠绕模块（NewlinePoison）
 *
 * 在最终源码中注入大量换行符（\n），数量为"原始代码行数的 1/10，且不少于 min 个（默认 2000）"，
 * 并把这些换行"纠缠"进代码——
 *   - 一部分作为语句之间的空行（分散在代码各处，而非集中在文件尾部）；
 *   - 一部分直接嵌进最大的字符串字面量（含被 VM / 结构毒化壳加密前的载荷），成为载荷数据的一部分。
 *
 * 与结构指纹毒化壳（StructPoison）配合：本模块须在 StructPoison【之前】应用，
 * 使换行随内层代码一同被加密进密文；运行期读出密文仍含这些换行，
 * 任何"下意识删空格 / 美化去换行 / 自动化空白清理"的编辑都会改动密文 → 解密错位。
 * （载荷多为 base64，对空白宽容；故本模块的主要价值是结构层面扰乱 + 诱导误删，
 *   真正"删即失效"由 StructPoison 的文件指纹承接。）
 *
 * 设计要点：
 *   - 仅对纯 ASCII 的字符串字面量做串内嵌入（跳过含多字节字符的串，避免截断 UTF-8）；
 *   - 换行插入位置由 Salt 派生的确定性 RNG 选取（可复现构建）；
 *   - 只在内层/空白处插入，绝不改动 token 结构，php -l 必然通过；运行期仅"多几个换行"，
 *     字符串内容随之变化但自洽，故产物运行行为与原代码完全一致。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Salt.php';

final class NewlinePoison
{
    public function __construct(
        private Logger $logger,
        private ?Salt $salt = null
    ) {
    }

    /**
     * @param array{ratio?:float, min?:int, string_ratio?:float} $opts
     */
    public function wrap(string $code, array $opts = []): string
    {
        $min = max(1, (int) ($opts['min'] ?? 2000));
        $ratio = (float) ($opts['ratio'] ?? 0.1);
        $stringRatio = (float) ($opts['string_ratio'] ?? 0.4);
        if ($stringRatio < 0.0 || $stringRatio > 1.0) {
            $stringRatio = 0.4;
        }

        $origLines = substr_count($code, "\n") + 1;
        $target = max((int) ceil($origLines * $ratio), $min);
        if ($target <= 0) {
            return $code;
        }

        $rng = $this->makeRng();
        $tokens = token_get_all($code);
        $n = count($tokens);

        // ---- 1) 收集"可控"的字符串字面量：仅限【长 base64 载荷】
        //     只对纯 base64 字符集（[A-Za-z0-9+/=]）且足够长的串做串内嵌入：
        //       - base64_decode 默认忽略空白，插入换行不影响解码出的字节 → 运行行为不变；
        //       - 长度阈值 + 字符集限制，天然排除常量名/函数名/错误文案/正则等"承载语义"的短串，
        //         避免把 'PHP_VERSION_ID'、'runtime mismatch' 这类破坏掉。
        $stringLens = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $lit = $t[1];
            $len = strlen($lit);
            if ($len < 256) {
                continue;
            }
            $inner = substr($lit, 1, -1); // 去掉首尾引号
            if ($inner === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $inner) !== 1) {
                continue; // 非纯 base64 载荷（含语义字符/插值/中文）→ 跳过
            }
            $stringLens[$i] = $len;
        }
        arsort($stringLens);

        $stringBudget = (int) ($target * $stringRatio);
        $stringInserts = [];   // tokenIndex => [offsetInLiteral, ...]（升序）
        $used = 0;
        foreach ($stringLens as $i => $len) {
            if ($used >= $stringBudget) {
                break;
            }
            $cap = min((int) ($len / 3), 60, $stringBudget - $used);
            if ($cap <= 0) {
                continue;
            }
            $offs = $this->pickOffsets($len, $cap, $rng);
            if ($offs !== []) {
                $stringInserts[$i] = $offs;   // pickOffsets 返回升序
                $used += count($offs);
            }
        }

        // ---- 2) 候选空行位置 = 语句结束符 token 的下标 ----
        $candidates = [];
        for ($i = 0; $i < $n; $i++) {
            if ($this->isStmtEnder($tokens[$i])) {
                $candidates[] = $i;
            }
        }
        $blankBudget = max(0, $target - $used);
        $chosen = [];
        if ($blankBudget > 0 && $candidates !== []) {
            $pick = min($blankBudget, count($candidates));
            $shuffled = $this->shuffle($candidates, $rng);
            $chosen = array_flip(array_slice($shuffled, 0, $pick));
            $blankBudget = $pick; // 实际能插的空行数
        }

        // ---- 3) 重建：串内换行 + 语句间空行 ----
        $out = '';
        foreach ($tokens as $i => $tok) {
            if (isset($stringInserts[$i])) {
                $out .= $this->embedInString($tok[1], $stringInserts[$i]);
                continue;
            }
            $out .= is_array($tok) ? $tok[1] : $tok;
            if (isset($chosen[$i])) {
                $out .= "\n";
            }
        }

        // 兜底：若候选语句结束符不足，剩余换行补到段尾，保证数量达标
        $added = $used + $blankBudget;
        $remain = $target - $added;
        for ($r = 0; $r < $remain; $r++) {
            $out .= "\n";
        }

        $this->logger->debug(sprintf(
            '已注入换行缠绕：目标=%d 实际新增=%d（串内=%d 空行=%d）',
            $target, $added + max(0, $remain), $used, $blankBudget + max(0, $remain)
        ));
        return $out;
    }

    /**
     * 在字符串字面量（含引号）的指定字符偏移处插入真实换行字节。
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
     * 语句结束符：在其后插入空行对 PHP 语义完全无害。
     */
    private function isStmtEnder($tok): bool
    {
        if (is_array($tok)) {
            return $tok[0] === T_CLOSE_TAG;
        }
        return $tok === ';' || $tok === '}' || $tok === ')' || $tok === ',' || $tok === ']';
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

    /** 由 Salt 派生确定性种子，构造 mulberry32 随机数发生器（返回 [0,1) 闭包）。 */
    private function makeRng(): callable
    {
        $seedHex = ($this->salt !== null && !$this->salt->isEmpty())
            ? $this->salt->derive('newline-poison', 8)
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
