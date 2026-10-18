<?php
/**
 * 字符串运行时异或装配（StringRuntimeAssembler）
 *
 * 替代默认的 base64 字符串编码：将字符串字面量切分为若干片段，每段与密钥做 XOR，
 * 再以 base64 存储，运行时通过 `base64_decode(...) ^ $GLOBALS['<拟真键名>']` 还原并拼接。
 *
 * 相比纯 base64，该方式：
 *  - 明文不出现在源码中（且 XOR 后内容也不可读）；
 *  - 各片段以"乱序拼接"形式出现，无法通过简单 grep 还原；
 *  - 密钥在运行期由 $GLOBALS 提供，静态分析难以一次定位全部片段。
 *
 * v2：密钥不再明写，只存 seed，运行期 key = sha256(seed) 前 8 字节派生。
 * v3（本次）：**每串独立密钥** —— 每条字符串使用"全局密钥循环串"的不同偏移切片。
 *     旧版所有字符串共用一个密钥，攻击者只要猜中/拿到任意一条明文，即可一次解出全部；
 *     现在每条字符串的密钥偏移不同，破一条不等于破全部。
 *     且偏移切片只是一次 substr(str_repeat(...))，无额外哈希开销。
 *
 * 由 --str-xor 开启；与 --str-length/--html 互斥（开启时优先使用本模块）。
 */

declare(strict_types=1);

final class StringRuntimeAssembler
{
    /** $GLOBALS 键名（拟真命名，去除 __awa_k 指纹） */
    private string $keyName = '_str_crypt_key';

    /** 字符串序号：用于派生"每串独立"的密钥偏移 */
    private int $strIdx = 0;

    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        if (!$config->get('str_xor')) {
            return $tokens;
        }
        $safe = $this->scanSafeContexts($tokens);
        // ---- 密钥（v2）：不再存储密钥本身，只存 seed；运行期 key = sha256(seed) 前 8 字节 ----
        $style = new NameStyle($this->salt);
        $this->keyName = $style->globalKey('str-xor-key');
        $seedStr = bin2hex(
            $this->salt->isEmpty()
                ? random_bytes(8)
                : $this->salt->derive('str-xor-seed', 8)
        );
        $key = substr(hash('sha256', $seedStr, true), 0, 8);

        $out = [];
        $keyInserted = false;
        $n = count($tokens);
        $injected = 0;

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            $out[] = $tok;
            if (!$keyInserted && is_array($tok) && $tok[0] === T_OPEN_TAG) {
                $out[] = "\$GLOBALS['" . $this->keyName . "']=substr(hash('sha256','" . $seedStr . "',true),0,8);";
                $keyInserted = true;
            }
            if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && !isset($safe[$i])) {
                $val = $this->decodeLiteral($tok[1]);
                if ($val === null || $val === '') {
                    continue;
                }
                $out[count($out) - 1] = $this->encode($val, $key, $this->strIdx++);
                $injected++;
            }
        }

        if ($injected > 0) {
            $this->logger->debug("字符串运行时异或装配：改写 {$injected} 处字符串");
        }
        return $out;
    }

    /**
     * @param string $value 明文
     * @param string $key   构建期密钥（与运行期派生结果一致）
     * @param int    $idx   字符串序号：决定本串使用哪个密钥切片
     */
    private function encode(string $value, string $key, int $idx): string
    {
        $len = max(1, (int) ceil(strlen($value) / 2));
        $segs = str_split($value, $len);
        // 每串独立密钥：取"全局密钥 ×3"循环串的不同偏移切片（0..16，共 17 种）。
        // 旧版所有字符串共用同一把钥匙 —— 破一条即破全部；现在各串钥匙不同。
        $off = $this->salt->isEmpty()
            ? random_int(0, 16)
            : $this->salt->deriveInt('str-xor-off-' . $idx, 0, 16);
        $slice = substr(str_repeat($key, 3), $off, 8); // 构建期实际使用的密钥
        $keyExpr = "substr(str_repeat(\$GLOBALS['" . $this->keyName . "'],3)," . $off . ',8)';
        $parts = [];
        foreach ($segs as $seg) {
            $enc = base64_encode($this->xorStr($seg, $slice));
            // 运行期用 str_repeat 把 8 字节密钥切片重复到数据长度，避免 PHP 字符串 XOR 截断
            $parts[] = "(base64_decode('" . $enc . "')^str_repeat(" . $keyExpr . ',' . ((int) strlen($seg) + 8) . '))';
        }
        return '(' . implode('.', $parts) . ')';
    }

    /**
     * 字符串按密钥做等长 XOR。PHP 的字符串 ^ 运算会截断到较短操作数长度，
     * 故这里把密钥按数据长度重复，确保逐字节异或。
     */
    private function xorStr(string $data, string $key): string
    {
        if ($data === '') {
            return '';
        }
        $k = str_repeat($key, (int) (strlen($data) / max(1, strlen($key))) + 2);
        return $data ^ $k;
    }

    /** @return array<int,true> */
    private function scanSafeContexts(array $tokens): array
    {
        $safe = [];
        $constMode = false;
        $caseMode = false;
        $inParam = false;
        $paramNest = 0;
        $awaitParam = false;
        $defaultMode = false;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            $isArr = is_array($tok);
            $type = $isArr ? $tok[0] : null;
            $t = is_string($tok) ? $tok : null;

            if ($type === T_CONST) {
                $constMode = true;
            } elseif ($type === T_CASE || $type === T_DEFAULT) {
                $caseMode = true;
            } elseif ($t === ':' && $caseMode) {
                $caseMode = false;
            } elseif ($t === ';' && $constMode) {
                $constMode = false;
            } elseif ($type === T_FUNCTION) {
                $awaitParam = true;
            } elseif ($t === '(') {
                if ($awaitParam) {
                    $inParam = true;
                    $paramNest = 1;
                    $awaitParam = false;
                } elseif ($inParam) {
                    $paramNest++;
                }
            } elseif ($t === ')') {
                if ($inParam && $paramNest > 1) {
                    $paramNest--;
                } elseif ($inParam && $paramNest === 1) {
                    $inParam = false;
                    $defaultMode = false;
                }
            } elseif ($t === '=' && $inParam) {
                $defaultMode = true;
            } elseif ($t === ',' && $defaultMode) {
                $defaultMode = false;
            }

            if ($isArr && $type === T_CONSTANT_ENCAPSED_STRING && ($constMode || $caseMode || $defaultMode)) {
                $safe[$i] = true;
            }
        }
        return $safe;
    }

    private function decodeLiteral(string $text): ?string
    {
        if (strlen($text) < 2) {
            return null;
        }
        $q = $text[0];
        if ($q !== '"' && $q !== "'") {
            return null;
        }
        if ($text[strlen($text) - 1] !== $q) {
            return null;
        }
        $inner = substr($text, 1, -1);
        if ($q === "'") {
            $out = preg_replace_callback('/\\\\([\\\\\\\'])/', fn ($m) => $m[1], $inner);
            return $out === null ? null : $out;
        }
        // 双引号：完整转义集（与 StringEncoder::decodeLiteral 逐分支对齐）。
        // 早期只处理 n/r/t/\\/" 的最小集，漏掉 \xHH——--repeat 二次编码时
        // pass1 的 "\x79" 被解码成字面 4 字符 \x79 再编码，运行期得到
        // 字面文本而非 y，数组键/输出全面静默损坏。repeat 场景必须全套。
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
                        if (preg_match('/^\{([0-9a-fA-F]+)\}/', substr($inner, $i + 2), $m) && function_exists('mb_chr')) {
                            $out .= mb_chr(hexdec($m[1]), 'UTF-8');
                            $i += 2 + strlen($m[0]);
                        } else {
                            $out .= '\\u'; $i += 2; // 无 mb_chr：保留原样（调用方会跳过不可解码串）
                        }
                        break;
                    default:
                        // 八进制 \ddd 或未知转义（原样保留两字符）
                        if ($n >= '0' && $n <= '7'
                            && preg_match('/^[0-7]{1,3}/', substr($inner, $i + 1), $m)) {
                            $out .= chr(octdec($m[0]));
                            $i += 1 + strlen($m[0]);
                        } else {
                            $out .= $c . $n; $i += 2;
                        }
                }
                continue;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }
}
