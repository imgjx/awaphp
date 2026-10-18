<?php
/**
 * 动态垃圾指令（RuntimeJunk）
 *
 * 把"死代码"升级为"真实执行"的垃圾指令流：定义无用变量、执行无用运算、解无用 base64，
 * 全部进入真实执行流（不输出、不改动真实变量），由 salt 确定性生成，可复现。
 *
 * 注入策略：在顶层语句边界（顶层 ';' 之后）插入若干 junk 语句。junk_intensity 1~10
 * 控制插入密度。垃圾模板池每次按 salt 挑选组合，且变量名经 NameStyle 拟真化（无 _junk 指纹）。
 */

declare(strict_types=1);

final class RuntimeJunk
{
    private NameStyle $style;
    private Salt $salt;
    private bool $fakeB64;
    private bool $fakeEcho;

    public function __construct(Salt $salt, private Logger $logger)
    {
        $this->salt = $salt;
        $this->style = new NameStyle($salt);
        $this->fakeB64 = true;
        $this->fakeEcho = true;
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        $intensity = (int) $cfg->get('junk_intensity');
        $tangle = (bool) $cfg->get('junk_tangle');
        if ($intensity < 1 && !$tangle) {
            return $tokens;
        }
        $this->fakeB64 = (bool) $cfg->get('junk_fake_b64');
        $this->fakeEcho = (bool) $cfg->get('junk_fake_echo');

        $out = $tokens;
        if ($intensity >= 1) {
            // 找出所有"顶层语句边界"位置（bracket/brace/paren depth == 0 的 ';' 之后）
            $bounds = [];
            $depth = 0;
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (is_string($t)) {
                    if ($t === '(' || $t === '[' || $t === '{') { $depth++; }
                    elseif ($t === ')' || $t === ']' || $t === '}') { $depth = max(0, $depth - 1); }
                    elseif ($t === ';' && $depth === 0) {
                        $bounds[] = $i + 1; // 在该 ';' 之后插入
                    }
                } elseif (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
            }
            if ($bounds !== []) {
                // 按强度决定插入步长（intensity 越大越密）
                $step = max(1, (int) ceil(10 / $intensity));
                $o = [];
                $bi = 0;
                foreach ($bounds as $pos) {
                    // 把当前已处理 token 复制过去
                    while ($bi < $pos) {
                        $o[] = $tokens[$bi];
                        $bi++;
                    }
                    if (count($o) % $step === 0 || $step <= 1) {
                        // 在边界插入一段 junk
                        $this->spliceJunk($o);
                    }
                }
                // 剩余 token
                while ($bi < $n) {
                    $o[] = $tokens[$bi];
                    $bi++;
                }
                $out = $o;
            }
        }

        // 执行缠结：把真实顶层整型赋值与垃圾值用恒等式缠结（删垃圾即出错）
        if ($tangle) {
            $out = $this->tanglePass($out, $cfg);
        }
        return $out;
    }

    /** 生成一段真实执行的 junk 源码并 token 化后拼入 $out */
    private function spliceJunk(array &$out): void
    {
        $snippet = $this->genSnippet();
        $tk = Parser::tokenize('<?php ' . $snippet);
        foreach ($tk as $t) {
            if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $out[] = $t;
        }
    }

    /** 确定性生成 junk 源码（仅碰本地垃圾变量，无输出、无副作用、绝不崩溃） */
    private function genSnippet(): string
    {
        $v = $this->style->varName('jv');
        $w = $this->style->varName('jw');
        $u = $this->style->varName('ju');
        $seed = $this->salt->derive('junk-' . $this->salt->deriveInt('jk', 0, 1 << 20), 6);
        // 一律用 base64 承载随机载荷：base64 字符集 [A-Za-z0-9+/=] 不含引号/反斜杠，
        // 杜绝把裸二进制拼进单引号字符串字面量导致的语法破坏。
        $b64 = base64_encode($seed);

        // ---- 模板池：真实运算类（始终可用）----
        $templates = [
            // 类型 A：base64 -> strrev -> md5 -> crc32（字符串/整数运算，全安全）
            // 注意 ${v}[0] 用花括号复杂语法：若写成 $v[0]，双引号会把 [$v][0] 当成对 $v 字符串取首字符，
            // 从而把 "变量名[0]" 塌缩成一个孤零零的 '$'，生成 ($??'0') 造成语法错误（salt 相关、偶发）。
            "$v=base64_decode('$b64');$v=strrev((string)$v).md5((string)(${v}[0]??'0'));$w=crc32((string)$v);",
            // 类型 A'：gzdeflate -> unpack -> crc32 整数异或（杜绝 string^array 的 PHP8 TypeError）
            "$v=gzdeflate((string)base64_decode('$b64'));$w=(unpack('N',substr((string)$v,0,4))[1]??0);$w=($w^crc32((string)$v))&0x7fffffff;",
            // 类型 A''：str_rot13 -> bin2hex -> hex2bin -> strlen
            "$v=str_rot13((string)base64_decode('$b64'));$w=hex2bin(bin2hex((string)$v));$w=strlen((string)$w);",
            // 类型 E：无用小循环（整数累加，固定上限 8 次，专用垃圾变量，绝不碰撞真实变量）
            // 注意：双引号内 "{ $w" 的 '{' 后必须留一个空格，否则 "{$" 会触发 PHP 的花括号复杂插值导致源码解析错误。
            // $w / $u 均未转义，运行时插值为拟真变量名；'{' 后的空格打断复杂插值。
            "$w=0;$u=8;while($u-->0){ $w=($w*31+crc32((string)$w))&0x7fffffff;}",
        ];

        // ---- fake_b64：诱饵 base64（永不解码 / 解了不用的孤立串）----
        if ($this->fakeB64) {
            $d1 = $this->style->varName('jdecoy');
            $d2 = $this->style->varName('jstore');
            $fake1 = base64_encode($this->salt->derive('junk-decoy-' . $this->salt->deriveInt('jd', 0, 1 << 20), 32));
            $fake2 = base64_encode($this->salt->derive('junk-store-' . $this->salt->deriveInt('js', 0, 1 << 20), 24));
            $templates[] = "$d1='$fake1';";                                  // 纯诱饵：永不读取
            $templates[] = "$d2=base64_decode('$fake2');";                  // 解了不用的孤立变量
            $templates[] = "$d1=base64_decode('$fake1');$d2=strlen((string)$d1)&0xff;"; // 解出来只做位运算丢弃
        }

        // ---- fake_echo：经输出缓冲执行后立即清除，不留任何痕迹 ----
        if ($this->fakeEcho) {
            $o = $this->style->varName('job');
            $templates[] = "ob_start();echo base64_decode('$b64');$o=ob_get_clean();$w=strlen((string)$o)&0xffff;";
            $templates[] = "ob_start();echo strrev(md5((string)base64_decode('$b64')));$o=ob_get_clean();$w=crc32((string)$o);";
        }

        $idx = $this->salt->deriveInt('junk-tpl', 0, count($templates) - 1);
        return $templates[$idx];
    }

    /**
     * 执行缠结模式（主意 2）：在真实顶层整型字面量赋值处注入
     *   $jt = (K|1);                 // 真实执行、保证非零奇数
     *   $v = N;                     // 原赋值
     *   $v = intdiv($v * $jt, $jt); // 恒等式：N*jt/jt = N（整型保真）
     * 删除 $jt 赋值行 → intdiv($v*null,null) 触发 DivisionByZeroError，程序自杀。
     * 故垃圾从"可剔除噪音"升级为"正确性的组成部分"——攻击者无法靠删垃圾加速分析。
     *
     * 仅作用于字面量整型 RHS（排除变量 / 浮点 / 溢出风险），保证语义恒等；
     * 缠结行本身引用 $jt，删掉即引用未定义变量并触发致命错误。
     *
     * @param array<int,mixed> $tk
     * @return array<int,mixed>
     */
    private function tanglePass(array $tk, Config $cfg): array
    {
        $jVal = $this->salt->deriveInt('tangle-j', 2, 1 << 20);
        $jVar = $this->style->varName('jt');
        $jLine = $this->tokenizeSnippet($jVar . '=(' . $jVal . '|1);');
        $n = count($tk);
        $out = [];
        $depth = 0;
        $i = 0;
        $done = 0;
        while ($i < $n) {
            $t = $tk[$i];
            if (is_array($t) && $t[0] === T_VARIABLE && $depth === 0 && $done < 8) {
                $eq = $this->sigTok($tk, $i + 1);
                if ($eq >= 0 && $this->isCharTok($tk[$eq], '=')) {
                    $num = $this->sigTok($tk, $eq + 1);
                    if ($num >= 0 && is_array($tk[$num]) && $tk[$num][0] === T_LNUMBER) {
                        $semi = $this->sigTok($tk, $num + 1);
                        if ($semi >= 0 && $this->isCharTok($tk[$semi], ';')) {
                            $val = (int) $tk[$num][1];
                            if ($val >= 0 && $val <= 1000000) {
                                // 前插垃圾行
                                foreach ($jLine as $jt) {
                                    if (is_array($jt) && in_array($jt[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                                        continue;
                                    }
                                    $out[] = $jt;
                                }
                                // 原赋值
                                for ($k = $i; $k <= $semi; $k++) {
                                    $out[] = $tk[$k];
                                }
                                // 缠结行：$v = intdiv($v * $jVar, $jVar);
                                $tangle = $this->tokenizeSnippet(
                                    $t[1] . '=intdiv(' . $t[1] . '*' . $jVar . ',' . $jVar . ');'
                                );
                                foreach ($tangle as $tt) {
                                    if (is_array($tt) && in_array($tt[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                                        continue;
                                    }
                                    $out[] = $tt;
                                }
                                $done++;
                                $i = $semi + 1;
                                continue;
                            }
                        }
                    }
                }
            }
            $out[] = $tk[$i];
            $depth = $this->trackDepthTok($t, $depth);
            $i++;
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $srcTokens
     * @return array<int,mixed>
     */
    private function tokenizeSnippet(string $src): array
    {
        return Parser::tokenize('<?php ' . $src);
    }

    private function sigTok(array $tk, int $i): int
    {
        $n = count($tk);
        for ($j = $i; $j < $n; $j++) {
            $t = $tk[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return -1;
    }

    private function isCharTok($t, string $c): bool
    {
        return is_string($t) && $t === $c;
    }

    private function trackDepthTok($t, int $depth): int
    {
        if (is_string($t)) {
            if ($t === '(' || $t === '[' || $t === '{') {
                return $depth + 1;
            }
            if ($t === ')' || $t === ']' || $t === '}') {
                return max(0, $depth - 1);
            }
        } elseif (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            return $depth + 1;
        }
        return $depth;
    }
}
