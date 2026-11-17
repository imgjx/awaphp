<?php
/**
 * 恒等表达式生成器（IdentityExpr）
 *
 * 把一个常量（整数 / 字符串）变换成"语义恒等、但看不出原值"的 PHP 表达式，
 * 供深度反AST / 超激进逻辑变换使用。核心积木：
 *   - strlen(sha1('随机串')) === 40   （sha1 恒为 40 个十六进制字符，与入参无关）
 *   - strlen(md5('随机串'))  === 32   （md5 恒为 32 个十六进制字符）
 *   - strlen('随机串')       === N    （定长随机串长度）
 *   - base64_decode / hex2bin / str_rot13 （字符串恒等还原；多段拼接）
 * 整数由上述积木做加减组合；所有生成式在构建期经 eval 校验，确保与原值逐字节/逐值一致。
 *
 * 随机化：所有积木里的字符串实参由 seed 派生（可复现），不再是全 'x' / '1' 这种一眼可辨的
 * 占位串；同一 seed 下重复构建结果稳定，便于调试与逐字节回归。
 *
 * @see docs/VM_FEATURES.md 深度反AST / 逻辑变换章节
 */

declare(strict_types=1);

final class IdentityExpr
{
    private int $seed;
    /** 内部计数器：让同一文件内多个积木取到不同的随机串（否则 md5(同一串) 可被模式识别） */
    private int $ctr = 0;
    /** v1.2.4 天马行空加强：扩张恒等积木编码库（默认关，保持既有档位行为） */
    private bool $wild = false;

    public function __construct(int $seed = 0)
    {
        $this->seed = $seed;
    }

    /** 重新设定随机种子并重置计数器（每个文件/作用域开始时调用，保证同 salt 可复现） */
    public function setSeed(int $seed): void
    {
        $this->seed = $seed;
        $this->ctr = 0;
    }

    /** v1.2.4：开启天马行空加强档，扩张恒等积木的形态库 */
    public function setWild(bool $on): void
    {
        $this->wild = $on;
    }

    /**
     * 由 seed + 计数器派生一个指定长度的随机可打印串（字母数字）。
     * 结果在单次构建内稳定（同一 ctr 恒定），跨调用递增以免重复。
     */
    private function randStr(int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        $seed = $this->seed;
        $ctr = $this->ctr;
        for ($i = 0; $i < $len; $i++) {
            $h = (int) (crc32($seed . '|' . $ctr . '|' . $i . '|' . $len) & 0x7fffffff);
            $out .= $alphabet[$h % 62];
        }
        $this->ctr++;
        return $out;
    }

    /**
     * 生成等于 $value 的整数表达式（结果 `(int)` 后恒等于 $value）。
     * 优先用 sha1/md5 长度积木做主体，余下用定长随机串长度补全，整体加括号。
     */
    public function intExpr(int $value): string
    {
        if ($value === 0) {
            return '(0)';
        }
        if ($value < 0) {
            return '(-' . $this->intPosExpr(-$value) . ')';
        }
        return $this->intPosExpr($value);
    }

    private function intPosExpr(int $v): string
    {
        // wild 档：走扩张积木库（ord/hexdec/bindec/abs/移位/strlen 变体随机组合），
        // 每个片段都恒等于其标称值，整条仍由 verify() 兜底校验。
        if ($this->wild) {
            return $this->intPosExprWild($v);
        }
        // 主积木取 40（sha1 长度）整除，余下再用 32（md5 长度）整除，最后用定长随机串补零头。
        // 关键：md5/sha1 的首参必须是**字符串字面量**且用随机串，既避免 strict_types 下
        // int→string 的 TypeError，也让每个积木长得不一样（不再是清一色 'x'/'1'）。
        $b = intdiv($v, 40);
        $r = $v % 40;
        $parts = [];
        if ($b > 0) {
            $parts[] = "strlen(sha1('" . $this->randStr(7) . "'))*" . $b;
        }
        if ($r > 0) {
            $a = intdiv($r, 32);
            $rr = $r % 32;
            if ($a > 0) {
                $parts[] = "strlen(md5('" . $this->randStr(7) . "'))*" . $a;
            }
            if ($rr > 0) {
                $parts[] = "strlen('" . $this->randStr($rr) . "')";
            }
        }
        if ($parts === []) {
            $parts[] = '(' . $v . ')';
        }
        // 结尾再叠加一个恒 0 的随机扰动项，打乱「几项相加」的固定形状（如 strlen('')*0）。
        if ($v > 0) {
            $parts[] = "(strlen('" . $this->randStr(3) . "')*0)";
        }
        return '(' . implode(' + ', $parts) . ')';
    }

    /**
     * wild 档整数积木：把一个正整数拆成若干片段，每个片段用**不同形态**的恒等表达式。
     * 可用形态（片段值 p）：
     *   - strlen('<p 个随机字符>')           直接定长串
     *   - ord('<chr(p)>')                    单字符 ASCII（p<256）
     *   - hexdec('<p 的十六进制>')           十六进制字面量
     *   - bindec('<p 的二进制>')             二进制字面量
     *   - abs(-p)                            绝对值
     *   - ((K << s) >> s)  where K>>s==p     移位恒等（K=p<<s）
     * 每片经 ctr 轮换，整条经 verify 兜底。
     */
    private function intPosExprWild(int $v): string
    {
        if ($v === 0) {
            return '(0)';
        }
        // 随机把 v 拆成 1~3 片（大数多拆），每片独立编码
        $pieces = [];
        $remain = $v;
        $nSeg = $v > 40 ? 3 : ($v > 8 ? 2 : 1);
        for ($i = 0; $i < $nSeg; $i++) {
            if ($i === $nSeg - 1) {
                $p = $remain;
            } else {
                // 确定性派生拆片大小（同 seed 可复现），不用 random_int
                $span = max(1, intdiv($remain, 2));
                $pick = (int) (crc32($this->seed . '|split|' . $this->ctr . '|' . $i) & 0x7fffffff);
                $p = $remain > 1 ? (1 + ($pick % $span)) : 0;
            }
            $remain -= $p;
            if ($p > 0) {
                $pieces[] = $this->intPieceWild($p);
            }
        }
        $remain > 0 && $pieces[] = $this->intPieceWild($remain);
        if ($pieces === []) {
            $pieces[] = '(' . $v . ')';
        }
        // 追加恒 0 扰动
        $pieces[] = $this->zeroExprWild();
        return '(' . implode(' + ', $pieces) . ')';
    }

    /** 单个正整数片段的 wild 恒等形态 */
    private function intPieceWild(int $p): string
    {
        $k = $this->ctr % 6;
        $this->ctr++;
        if ($k === 1 && $p >= 32 && $p < 127) {
            // 只用可打印 ASCII，避免 chr() 产出 \0 / 换行等控制字节落进字符串字面量
            return "ord('" . addcslashes(chr($p), "\\'") . "')";
        }
        if ($k === 2) {
            return 'hexdec(\'' . dechex($p) . '\')';
        }
        if ($k === 3) {
            return 'bindec(\'' . decbin($p) . '\')';
        }
        if ($k === 4) {
            return 'abs(-' . $p . ')';
        }
        if ($k === 5) {
            $s = 3;
            $K = $p << $s;
            return '((' . $K . ' << ' . $s . ') >> ' . $s . ')';
        }
        return "strlen('" . $this->randStr($p) . "')";
    }

    /** wild 档恒 0 扰动表达式 */
    private function zeroExprWild(): string
    {
        $k = $this->ctr % 4;
        $this->ctr++;
        if ($k === 1) {
            return "({$k} - {$k})";
        }
        if ($k === 2) {
            return "(strlen('" . $this->randStr(3) . "') * 0)";
        }
        if ($k === 3) {
            $ch = $this->randStr(1); // 必须同一字符相减才恒为 0
            return "(ord('" . $ch . "') - ord('" . $ch . "'))";
        }
        return '(0)';
    }

    /**
     * 生成等于 $str 的字符串表达式（结果恒等于 $str）。
     * 强化：按长度拆成 1~3 段，每段用**随机编码策略**（base64 / hex2bin / str_rot13）
     * 各自编码、运行期各自解码，再全部拼接回来——源码里既没有明文，也没有统一的
     * "清一色 base64" 特征。偶尔追加空串拼接噪音。
     */
    public function stringExpr(string $str): string
    {
        if ($str === '') {
            return "''";
        }
        $len = strlen($str);
        // wild 档：拆得更碎（最多 5 段），段间插入恒等空串噪声，形态库更丰富
        if ($this->wild) {
            $n = $len <= 2 ? 1 : ($len <= 5 ? 2 : ($len <= 12 ? 3 : ($len <= 24 ? 4 : 5)));
            $segs = $this->splitN($str, $n);
            $parts = [];
            foreach ($segs as $seg) {
                $parts[] = $this->encodeSeg($seg);
                // 每段之间随机插入一个恒等空串积木（'' / str_repeat('x',0) / substr('ab',5)）
                if (($this->ctr % 3) === 0) {
                    $parts[] = $this->emptyStringExpr();
                }
            }
            $expr = implode(' . ', $parts);
            // 尾部再套一层 strrev(strrev(...)) 或 substr(...,0) 恒等包裹（随机）
            if (($this->ctr % 2) === 0) {
                $expr = 'substr(' . $expr . ', 0)';
            }
            return $expr;
        }
        $n = $len <= 2 ? 1 : ($len <= 7 ? 2 : 3);
        $segs = $this->splitN($str, $n);
        $parts = [];
        foreach ($segs as $seg) {
            $parts[] = $this->encodeSeg($seg);
        }
        $expr = implode(' . ', $parts);
        if ($this->ctr % 4 === 0) {
            $expr .= " . ''";
        }
        return $expr;
    }

    /** 生成一个恒等于空串的表达式（wild 档段间噪声用） */
    private function emptyStringExpr(): string
    {
        $k = $this->ctr % 3;
        $this->ctr++;
        if ($k === 1) {
            return "str_repeat('" . $this->randStr(2) . "', 0)";
        }
        if ($k === 2) {
            return "substr('" . $this->randStr(4) . "', 99)";
        }
        return "''";
    }

    /** 把字符串均匀切成最多 $n 段（保证每段非空；长度不足时退化） */
    private function splitN(string $s, int $n): array
    {
        $len = strlen($s);
        if ($n <= 1 || $len < $n) {
            return [$s];
        }
        $base = intdiv($len, $n);
        $out = [];
        $p = 0;
        for ($i = 0; $i < $n - 1; $i++) {
            $out[] = substr($s, $p, $base);
            $p += $base;
        }
        $out[] = substr($s, $p);
        return array_values(array_filter($out, static fn ($x) => $x !== ''));
    }

    /** 单段编码：base64 / hex2bin / str_rot13；wild 档再加入 strrev / 双层 base64 / 组合编码 */
    private function encodeSeg(string $seg): string
    {
        $k = $this->ctr % ($this->wild ? 6 : 3);
        $this->ctr++;
        if ($k === 1) {
            return "hex2bin('" . bin2hex($seg) . "')";
        }
        if ($k === 2) {
            // str_rot13 只转 ASCII 字母，其余原样；对单引号/反斜杠做 addcslashes 转义，
            // 运行期由 PHP 单引号解析还原，再 str_rot13 还原原文。
            return "str_rot13('" . addcslashes(str_rot13($seg), "\\'") . "')";
        }
        if ($k === 3 && $this->wild) {
            // strrev 反转：把段的字节序倒过来存，运行期再反转还原。
            return "strrev('" . addcslashes(strrev($seg), "\\'") . "')";
        }
        if ($k === 4 && $this->wild) {
            // 双层 base64：base64_encode 两次，运行期 base64_decode 两次。
            $dbl = base64_encode(base64_encode($seg));
            return "base64_decode(base64_decode('" . $dbl . "'))";
        }
        if ($k === 5 && $this->wild) {
            // 组合编码：先 rot13 再 base64，运行期先 base64_decode 再 rot13。
            return "str_rot13(base64_decode('" . base64_encode(str_rot13($seg)) . "'))";
        }
        return "base64_decode('" . base64_encode($seg) . "')";
    }

    /**
     * 常量安全形态的整数恒等表达式：仅用位运算（^），**绝不调用任何函数**，
     * 因此可在常量表达式上下文（const / case 标签 / 参数默认值 / 属性默认值 /
     * define() 值）安全使用。把 $v 拆成 Ka ^ Kb（Ka 随机、Kb = Ka ^ v），源码中
     * 不再出现 $v 明文，且等号两侧恒等式经 verify() 校验。
     * @return string|null 失败返回 null（保持原样）
     */
    public function intExprConstSafe(string $raw, int $seed = 0): ?string
    {
        $digits = str_replace('_', '', $raw);
        if ($digits === '' || !ctype_digit($digits)) {
            return null;
        }
        // 八进制 / 十六进制（0x… / 0b…）由 Number 层或保持原样处理，这里只认十进制
        if (strlen($digits) > 1 && $digits[0] === '0') {
            return null;
        }
        $v = (int) $digits;
        // 取 32 位范围内的随机基底（$seed 由调用方以 salt 派生，可复现且随 salt 变化）
        $ka = $this->constKa($v, $seed);
        $kb = $ka ^ $v;
        // 必须整体再包一层括号：`^` 在 PHP 中优先级低于 <= / == / + / . / === 等，
        // 只写 (Ka) ^ (Kb) 时参与运算会被解析成 (A <= Ka) ^ Kb 之类的错误语义。
        return '((' . $ka . ') ^ (' . $kb . '))';
    }

    /** 由 $v 与 salt 派生种子共同决定一个稳定的 Ka（避免恒为 0 / 与 v 过近） */
    private function constKa(int $v, int $seed = 0): int
    {
        $h = (int) (crc32((string) $v . '|ka|' . $seed) & 0x7fffffff);
        $ka = ($h % 0x7fffffff) | 0x1000; // 保证非零且高位有值
        return $ka;
    }

    /**
     * 字符串恒等"廉价运算"：在已定义变量 $var 上追加几行极廉价、不改变值的运算，
     * 进一步打散 AST（先解码拼接、再运算、再输出）。均为逐字节恒等：
     *   - `$var = $var . ''` 拼接空串（字符串不变）
     *   - `$var = (string)$var` 强制字符串态（字符串不变）
     * 返回可直接拼进代码片段的 PHP 语句序列（末尾含换行）。
     */
    public function cheapStringOps(string $var): string
    {
        return "\${$var} = \${$var} . '';\n\${$var} = (string)\${$var};\n";
    }

    /**
     * 整数恒等"廉价运算"：在已定义累加变量 $var 上追加一行极廉价、不改变值的运算
     * （整数异或 0 恒等于自身），用于强化整数路径的反AST。末尾含换行。
     */
    public function cheapIntOps(string $var): string
    {
        return "\${$var} = \${$var} ^ 0;\n";
    }

    /**
     * 构建期自检：生成式是否与原值等价（整数按值、字符串按内容）。
     * 返回 true 表示安全可用。
     * @param int|string $expected
     */
    public function verify($expected, string $expr): bool
    {
        try {
            $got = eval('return ' . $expr . ';');
        } catch (Throwable $e) {
            return false;
        }
        if (is_int($expected)) {
            return is_int($got) && $got === $expected;
        }
        return is_string($got) && $got === $expected;
    }
}
