<?php
/**
 * 字符串编码（StringEncoder）v2
 *
 * 将字符串字面量改写为"运行时解码"形式，使明文不可见。v2 引入**多编码方案**
 * （salt 派生、逐串确定性选择，同一 salt 可复现）：
 *
 *   A. base64 分段            base64_decode('...').base64_decode('...')
 *   B. XOR + base64           每串独立密钥（salt 派生），运行期 ^str_repeat 还原
 *   C. gzdeflate + base64     长串（≥24B）压缩后 base64，运行期 gzinflate 还原
 *   D. chr() 拼接             短串（≤20B）逐字节 chr(N).chr(N)，完全不像编码串
 *   E. \xHH 十六进制转移      双引号串逐字节 \xHH，体积紧凑、无 base64 特征
 *
 * 支持：
 *  - --str-length=N：按 N 字符分段编码
 *  - --var-split=M：将字符串拆为 M 段（变量值打散）
 *  - --html：对"像 HTML"的字符串额外做 htmlspecialchars 实体编码
 *
 * 多方案的意义：单一 base64 形式一条正则即可定位全部编码串；混合方案后，
 * 批量提取/批量解码工具失效，且不同串用不同方案，破一条不等于破全部。
 *
 * 安全性：仅改写简单字面量（无插值的 T_CONSTANT_ENCAPSED_STRING）。会主动跳过
 * 常量必需上下文（const 声明值、函数默认参数值、switch case 值），避免破坏可运行性。
 */

declare(strict_types=1);

final class StringEncoder
{
    private HtmlEncoder $html;

    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
        $this->html = new HtmlEncoder();
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        $strLen = (int) $config->get('str_length');
        $varSplit = (int) $config->get('var_split');
        $html = (bool) $config->get('html');

        if ($strLen <= 0 && $varSplit <= 1 && !$html) {
            return $tokens; // 未开启任何字符串相关选项
        }

        // 分段策略
        $segLen = $strLen > 0 ? $strLen : 0;
        $segCount = $varSplit > 1 ? $varSplit : 0;

        $safe = ConstExprScan::safeStringIndices($tokens); // [index => true] 表示"不可编码"
        $count = 0;
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (isset($safe[$i])) {
                continue;
            }
            $value = $this->decodeLiteral($tok[1]);
            if ($value === null) {
                continue; // 无法安全解码则跳过
            }
            // 仅当确实需要（有分段 或 需要 html 且该串像 HTML）
            $needHtml = $html && $this->html->isHtmlLike($value);
            if ($segLen <= 0 && $segCount <= 1 && !$needHtml) {
                continue;
            }
            $tokens[$i][1] = $this->encodeString($value, $segLen, $segCount, $needHtml, $count);
            $count++;
        }
        if ($count > 0) {
            $this->logger->debug('字符串编码：' . $count . ' 处字符串被改写（多方案混合）');
        }
        return $tokens;
    }

    /**
     * 多方案编码（v2）。
     *
     * @param string $value    明文字符串
     * @param int    $segLen   按长度分段（0=不按长度）
     * @param int    $segCount 拆分份数（1=不拆）
     * @param bool   $needHtml 是否先做 HTML 实体编码
     * @param int    $idx      字符串序号（salt 派生方案/密钥用，保证确定性）
     */
    private function encodeString(string $value, int $segLen, int $segCount, bool $needHtml, int $idx): string
    {
        $work = $value;
        $useHtml = false;
        if ($needHtml) {
            $enc = $this->html->encode($value);
            // htmlspecialchars 对非法 UTF-8 的二进制串（XOR 密钥、压缩 payload 等）
            // 在 PHP 8.1+ 返回空串，往返不成立——--repeat 二次编码时会静默毁掉
            // 这类串（键变空、输出缺段）。仅当 html 往返可还原才启用该路径。
            if (is_string($enc) && htmlspecialchars_decode($enc, HtmlEncoder::FLAGS) === $value) {
                $work = $enc;
                $useHtml = true;
            }
        }

        if ($segCount > 1) {
            $len = (int) ceil(strlen($work) / $segCount);
            $segs = $len > 0 ? str_split($work, max(1, $len)) : [$work];
        } elseif ($segLen > 0) {
            $segs = str_split($work, $segLen);
        } else {
            $segs = [$work];
        }

        $parts = [];
        foreach ($segs as $si => $seg) {
            $parts[] = $this->encodeSegment((string) $seg, $idx, $si);
        }
        $concat = implode('.', $parts);
        return $useHtml ? $this->html->wrapDecode($concat) : $concat;
    }

    /**
     * 编码单个分段：salt 派生方案（A base64 / B XOR+base64 / C gz+base64 /
     * D chr 拼接 / E \xHH 十六进制），并带可行性回退。
     *
     * @param int $idx 字符串序号
     * @param int $si  分段序号
     */
    private function encodeSegment(string $seg, int $idx, int $si): string
    {
        $n = strlen($seg);
        if ($n === 0) {
            return "''";
        }
        $scheme = $this->pick('str-scheme-' . $idx . '-' . $si, 0, 4);

        switch ($scheme) {
            case 1: // B. XOR + base64（每串独立密钥）
                $key = $this->segmentKey($idx, $si);
                $enc = base64_encode($this->xorStr($seg, $key));
                $keyLit = $this->phpSingleQuoted($key);
                $rep = (int) ceil($n / 8) + 1;
                return "(base64_decode('" . $enc . "')^str_repeat('" . $keyLit . "'," . $rep . '))';

            case 2: // C. gzdeflate + base64（仅长串划算，短串回退 base64）
                if ($n >= 24) {
                    $enc = base64_encode((string) gzdeflate($seg, 9));
                    return "gzinflate(base64_decode('" . $enc . "'))";
                }
                return "base64_decode('" . base64_encode($seg) . "')";

            case 3: // D. chr() 逐字节拼接（仅短串划算）
                if ($n <= 20) {
                    $chs = [];
                    for ($b = 0; $b < $n; $b++) {
                        $chs[] = 'chr(' . ord($seg[$b]) . ')';
                    }
                    return '(' . implode('.', $chs) . ')';
                }
                return "base64_decode('" . base64_encode($seg) . "')";

            case 4: // E. \xHH 十六进制转移（双引号串，逐字节）
                $hex = '';
                for ($b = 0; $b < $n; $b++) {
                    $hex .= '\\x' . bin2hex($seg[$b]);
                }
                return '"' . $hex . '"';

            default: // A. base64
                return "base64_decode('" . base64_encode($seg) . "')";
        }
    }

    /** 每串每段独立密钥（salt 派生；空 salt 用真随机） */
    private function segmentKey(int $idx, int $si): string
    {
        return $this->salt->isEmpty()
            ? random_bytes(8)
            : $this->salt->derive('str-key-' . $idx . '-' . $si, 8);
    }

    /** salt 非空时确定性选择，为空时真随机（避免同构指纹） */
    private function pick(string $label, int $min, int $max): int
    {
        return $this->salt->isEmpty()
            ? random_int($min, $max)
            : $this->salt->deriveInt($label, $min, $max);
    }

    /** 重复密钥 XOR（密钥已按数据长度重复，逐字节异或） */
    private function xorStr(string $data, string $key): string
    {
        if ($data === '' || $key === '') {
            return $data;
        }
        $ks = str_repeat($key, (int) (strlen($data) / max(1, strlen($key))) + 2);
        return $data ^ substr($ks, 0, strlen($data));
    }

    /** 把任意字节串安全地写进 PHP 单引号字面量（仅需转义 \ 与 '） */
    private function phpSingleQuoted(string $bytes): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $bytes);
    }

    /**
     * 将简单字符串字面量 token 文本解码为真实字符串值。
     * 仅处理无插值字面量；遇到无法安全处理的情况返回 null（调用方跳过）。
     */
    private function decodeLiteral(string $text): ?string
    {
        if (strlen($text) < 2) {
            return null;
        }
        $q = $text[0];
        if ($q !== '"' && $q !== "'") {
            return null;
        }
        // 兼容 heredoc 等情况：这里只处理以引号开始和结束的简单字面量
        if ($text[strlen($text) - 1] !== $q) {
            return null;
        }
        $inner = substr($text, 1, -1);

        if ($q === "'") {
            // 单引号仅 \\ 与 \' 为转义
            $out = preg_replace_callback('/\\\\([\\\\\\\'])/', fn ($m) => $m[1], $inner);
            return $out === null ? null : $out;
        }

        // 双引号
        $out = '';
        $len = strlen($inner);
        $i = 0;
        while ($i < $len) {
            $c = $inner[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $n = $inner[$i + 1];
                switch ($n) {
                    case '\\': $out .= '\\'; $i += 2; break;
                    case '"':  $out .= '"';  $i += 2; break;
                    case '$':  $out .= '$';  $i += 2; break;
                    case 'n':  $out .= "\n"; $i += 2; break;
                    case 'r':  $out .= "\r"; $i += 2; break;
                    case 't':  $out .= "\t"; $i += 2; break;
                    case 'v':  $out .= "\v"; $i += 2; break;
                    case 'e':  $out .= "\e"; $i += 2; break;
                    case 'f':  $out .= "\f"; $i += 2; break;
                    case 'x':
                        if (preg_match('/^[0-9a-fA-F]{1,2}/', substr($inner, $i + 2), $m)) {
                            $out .= chr(hexdec($m[0]));
                            $i += 2 + strlen($m[0]);
                        } else {
                            $out .= '\\x'; $i += 2;
                        }
                        break;
                    case 'u':
                        if (preg_match('/^\{([0-9a-fA-F]+)\}/', substr($inner, $i + 2), $m)) {
                            $cp = hexdec($m[1]);
                            $out .= mb_chr($cp, 'UTF-8');
                            $i += 2 + strlen($m[0]) + 2;
                        } else {
                            $out .= '\\u'; $i += 2;
                        }
                        break;
                    default:
                        // 八进制 \ddd
                        if (preg_match('/^[0-7]{1,3}/', substr($inner, $i + 1), $m)) {
                            $out .= chr(octdec($m[0]));
                            $i += 1 + strlen($m[0]);
                        } else {
                            $out .= '\\' . $n; $i += 2;
                        }
                }
            } else {
                $out .= $c;
                $i++;
            }
        }
        return $out;
    }
}
