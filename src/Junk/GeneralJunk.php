<?php
/**
 * 通用垃圾指令（GeneralJunk，即"通用版垃圾插入"）
 *
 * 与 VM 字节码级垃圾（src/Vm/VmBytecodeJunk.php，"无用字节码覆盖"）并列的两大垃圾模块之一：
 * 本模块工作在【原始 PHP 源码 token 层】，把"真实执行"的垃圾语句插进 token 流——它不依赖 VM，
 * 任何档位（min 之外的 token 档、shell 档）都能用，是"通用版"而非"VM 专供版"。
 *
 * 设计约束（用户硬性要求：计算量不能太高，否则卡成傻瓜）：
 *   - 每条垃圾片段只动【本地垃圾变量】，无输出、无副作用、绝不崩溃；
 *   - 全部是【极廉价的本地变量算术 / 赋值 / 字符串长度 / 一次 base64_decode】，
 *     杜绝 md5 / crc32 / gzdeflate / while 循环这类重计算——纯"执行一下就丢弃"的垃圾；
 *   - 由 salt 确定性生成，可复现（同 salt + 同源码 + 同强度 ⇒ 产物逐字节一致）。
 *
 * 注入策略：在顶层语句边界（顶层 ';' 之后）插入若干 junk 语句。junk_intensity 1~10
 * 控制插入密度。垃圾模板池每次按 salt 挑选组合，且变量名经 NameStyle 拟真化（无 _junk 指纹）。
 */

declare(strict_types=1);

final class GeneralJunk
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
            // 序言区约束：declare(strict_types=1) 必须是首语句、namespace 必须紧随 declare，
            // junk 若插进 declare 与 namespace 之间会直接致命。声明边界（declare 的 ';' 之后）
            // 恰好是个"顶层语句边界"，必须把 headInsertIndex（declare/namespace 序言结束点）
            // 之前的边界全部滤掉，保证 junk 一律落在序言之后。
            $minAt = DeclareHoist::headInsertIndex($tokens);
            if ($minAt >= 0) {
                $bounds = array_values(array_filter($bounds, static fn (int $b): bool => $b >= $minAt));
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

    /**
     * 确定性生成极廉价 junk 源码（仅碰本地垃圾变量，纯本地算术 / 赋值 / 一次 base64_decode，
     * 无 md5/crc32/gzdeflate/while 循环，计算量可忽略——用户要求"只是执行的垃圾"，绝不卡顿）。
     */
    private function genSnippet(): string
    {
        $v = $this->style->varName('jv');
        $w = $this->style->varName('jw');
        $u = $this->style->varName('ju');
        // 所有模板均为【极廉价】本地运算；base64 字符集 [A-Za-z0-9+/=] 不含引号/反斜杠，
        // 杜绝把裸二进制拼进单引号字符串字面量导致的语法破坏。
        $b64 = base64_encode($this->salt->derive('junk-' . $this->salt->deriveInt('jk', 0, 1 << 20), 6));

        // ---- 模板池：极廉价本地变量算术（始终可用，无函数调用 / 无循环 / 无重哈希）----
        $templates = [
            // 类型 A：纯整型算术（1 次加法 + 1 次乘法 + 1 次位与），结果即丢弃
            "$v=1;$v=$v+2;$w=$v*3;$w=$w&255;",
            // 类型 B：整型减法 + 异或 + 或，结果丢弃
            "$v=5;$v=$v-1;$w=$v^7;$w=$w|3;",
            // 类型 C：字符串拼接（极廉价，无 strlen 之外的调用也可，但保留 strlen 增加噪声）
            "$v='a';$v=$v.'b';$w=strlen($v);$w=$w+0;",
            // 类型 D：整型左移 + 右移抵消（恒等噪声，纯位运算）
            "$v=4;$v=$v<<2;$v=$v>>2;$w=$v+0;",
            // 类型 E：三元无副作用（选取小整数），纯本地比较
            "$v=2;$w=($v>1?$v:0)+0;$u=$w^0;",
        ];

        // ---- fake_b64：诱饵 base64（解了不用的孤立串；一次 base64_decode 极廉价）----
        if ($this->fakeB64) {
            $d1 = $this->style->varName('jdecoy');
            $d2 = $this->style->varName('jstore');
            $fake1 = base64_encode($this->salt->derive('junk-decoy-' . $this->salt->deriveInt('jd', 0, 1 << 20), 32));
            $fake2 = base64_encode($this->salt->derive('junk-store-' . $this->salt->deriveInt('js', 0, 1 << 20), 24));
            // 诱饵：解一次、拼接自身、取长度——全程本地运算，无输出、无重计算
            $templates[] = "$d1=base64_decode('$fake1');$d1=$d1.$d1;";
            // 诱饵：解一次、取长度位与——丢弃
            $templates[] = "$d2=base64_decode('$fake2');$w=strlen((string)$d2)&0xff;";
        }

        // ---- fake_echo：经输出缓冲执行后立即清除（一次 echo 极廉价，输出被 ob 吞掉不留痕）----
        if ($this->fakeEcho) {
            $o = $this->style->varName('job');
            // 仅一次 echo + 一次 base64_decode（无 md5/crc32），输出缓冲立即清除
            $templates[] = "ob_start();echo base64_decode('$b64');$o=ob_get_clean();$w=strlen((string)$o)&0xff;";
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
