<?php
/**
 * VmCompiler 碎片：字面量与复合结构碎片：match / 插值串 / 数组 / 数字 / 字符串 / heredoc。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性/方法；拆分的只是源码组织，不影响行为。
 */

declare(strict_types=1);

trait VmLitTrait
{

    /**
     * match 表达式：match ($subj) { c1,c2 => r1; default => r2 }。
     * 编译为：把 $subj 存临时变量，对每个非 default 分支依次做 ($subj == ci) 比较，
     * 命中则跳到该分支结果块；全部未命中且存在 default 则落到 default 结果块；
     * 否则压入 null。结果块末尾 JMP 到 match 结束点。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseMatch(Cursor $c, array &$code): void
    {
        $c->next(); // 消费 T_MATCH
        $c->expect('(');
        $subjToks = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($subjToks, $code);
        $subj = '$__m' . ($this->tempSeq++);
        $code[] = [VmOp::POP_V, $subj];
        $c->expect('{');

        $endJmps = [];          // 各结果块的 JMP → match 结束
        $defaultRes = null;      // default 分支的结果 token

        while (!$c->is('}') && !$c->eof()) {
            // 收集本分支的条件（可能有多个，逗号分隔），直到顶层 '=>'
            $conds = [];
            $conds[] = $c->collectUntilTop(T_DOUBLE_ARROW, ',');
            while ($c->is(',')) {
                $c->next();
                $conds[] = $c->collectUntilTop(T_DOUBLE_ARROW, ',');
            }
            $c->expect(T_DOUBLE_ARROW);
            $resToks = $c->collectMatchArmResult();

            $isDefault = false;
            if (count($conds) === 1) {
                $ct = $this->trimWs($conds[0]);
                if (count($ct) === 1 && is_array($ct[0]) && $ct[0][0] === T_DEFAULT) {
                    $isDefault = true;
                }
            }

            if ($isDefault) {
                $defaultRes = $resToks;
            } else {
                $jnzIdx = [];
                foreach ($conds as $ct) {
                    $code[] = [VmOp::PUSH_V, $subj];
                    $this->compileExpr($ct, $code);
                    $code[] = [VmOp::EQ];          // $subj == cond
                    $code[] = [VmOp::JNZ, 0];     // 命中 → 跳到结果块
                    $jnzIdx[] = count($code) - 1;
                }
                // 未命中：跳过结果块
                $code[] = [VmOp::JMP, 0];
                $skipIdx = count($code) - 1;
                // 结果块
                $resStart = count($code);
                foreach ($jnzIdx as $ji) {
                    $code[$ji][1] = $resStart - ($ji + 1);
                }
                $this->compileExpr($resToks, $code);
                $code[] = [VmOp::JMP, 0];
                $endJmps[] = count($code) - 1;
                // skip 目标（下一分支起点）
                $code[$skipIdx][1] = count($code) - ($skipIdx + 1);
            }

            if ($c->is(',')) { $c->next(); }
        }
        $c->expect('}');

        if ($defaultRes !== null) {
            $this->compileExpr($defaultRes, $code);
        } else {
            $code[] = [VmOp::PUSH_NULL];
        }
        $endPos = count($code);
        foreach ($endJmps as $jmpIdx) {
            $code[$jmpIdx][1] = $endPos - ($jmpIdx + 1);
        }
    }

    /**
     * 双引号插值字符串："a{$x}b{$arr['k']}c$d"。
     * 展开为若干段字面量 / 表达式的 CONCAT 链。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseInterpolatedString(Cursor $c, array &$code): void
    {
        $c->expect('"');
        $parts = [];   // ['lit', string] | ['expr', tokens]
        $buf = '';
        while (!$c->eof() && !$c->is('"')) {
            $tk = $c->peek();
            if (is_array($tk) && $tk[0] === T_ENCAPSED_AND_WHITESPACE) {
                $buf .= $this->decodeEncapsed($tk[1]);
                $c->next();
                continue;
            }
            if (is_array($tk) && $tk[0] === T_VARIABLE) {
                if ($buf !== '') { $parts[] = ['lit', $buf]; $buf = ''; }
                $c->next();
                $expr = [$tk];
                // 简单下标：$a[0] / $a[key]（键内再嵌 [] 时跟踪深度，防提前截断）
                if ($c->is('[')) {
                    $c->next();
                    $keyToks = [];
                    $bdepth = 1;
                    while (!$c->eof()) {
                        if ($c->is(']')) {
                            $bdepth--;
                            if ($bdepth === 0) {
                                break;
                            }
                            $keyToks[] = $c->next();
                            continue;
                        }
                        if ($c->is('[')) {
                            $bdepth++;
                        }
                        $keyToks[] = $c->next();
                    }
                    $c->expect(']');
                    $expr[] = '[';
                    foreach ($this->normalizeStringKey($keyToks) as $kt) { $expr[] = $kt; }
                    $expr[] = ']';
                } elseif ($c->is(T_OBJECT_OPERATOR)) {
                    $c->next();
                    $pn = $c->peek();
                    $pname = is_array($pn) ? $pn[1] : (string) $pn;
                    $c->next();
                    $expr[] = T_OBJECT_OPERATOR;
                    $expr[] = [T_STRING, $pname];
                }
                $parts[] = ['expr', $expr];
                continue;
            }
            if (is_array($tk) && $tk[0] === T_CURLY_OPEN) {
                if ($buf !== '') { $parts[] = ['lit', $buf]; $buf = ''; }
                $c->next(); // 消费开头的 '{'（T_CURLY_OPEN）
                $inner = [];
                $depth = 0; // 仅追踪内嵌表达式里的 () [] {} 深度，遇到最外层 '}' 即收尾
                while (!$c->eof()) {
                    $x = $c->peek();
                    if (is_string($x)) {
                        if ($x === '}') {
                            if ($depth === 0) { $c->next(); break; }
                            $depth--;
                        } elseif ($x === '{' || $x === '(' || $x === '[') {
                            $depth++;
                        } elseif ($x === ')' || $x === ']') {
                            // 闭合同类括号必须配对递减，否则 "{$arr['k']}tail" 的
                            // 内层 ] 不降深度 → 外层 } 被当作内层括号吞掉，尾部字面量全丢
                            if ($depth > 0) { $depth--; }
                        }
                    }
                    $inner[] = $c->next();
                }
                $parts[] = ['expr', $inner];
                continue;
            }
            if (is_array($tk) && $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                if ($buf !== '') { $parts[] = ['lit', $buf]; $buf = ''; }
                $c->next();
                $inner = [];
                while (!$c->is('}') && !$c->eof()) { $inner[] = $c->next(); }
                $c->expect('}');
                $parts[] = ['expr', $inner];
                continue;
            }
            // 其它（如注释）跳过
            $c->next();
        }
        if ($c->is('"')) { $c->next(); }
        if ($buf !== '') { $parts[] = ['lit', $buf]; }

        if ($parts === []) {
            $code[] = [VmOp::PUSH_C, ''];
            return;
        }
        $emitted = 0;
        foreach ($parts as $p) {
            if ($p[0] === 'lit') {
                $code[] = [VmOp::PUSH_C, $p[1]];
            } else {
                $this->compileExpr($p[1], $code);
            }
            $emitted++;
            if ($emitted > 1) { $code[] = [VmOp::CONCAT]; }
        }
    }

    /**
     * 插值字符串中的下标 token（T_STRING / T_NUM_STRING）转成可编译表达式 token。
     * @param array<int,mixed> $toks
     * @return array<int,mixed>
     */
    private function normalizeStringKey(array $toks): array
    {
        $out = [];
        foreach ($toks as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_STRING, T_NUM_STRING], true)) {
                $out[] = [T_CONSTANT_ENCAPSED_STRING, "'" . $tk[1] . "'"];
                continue;
            }
            $out[] = $tk;
        }
        return $out;
    }

    /** 解析双引号 / 插值片段中的转义序列 */
    private function decodeEncapsed(string $s): string
    {
        $map = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch !== '\\' || $i + 1 >= $len) { $out .= $ch; continue; }
            $nx = $s[$i + 1];
            if (isset($map[$nx])) { $out .= $map[$nx]; $i++; continue; }
            if ($nx === 'x' && preg_match('/^[0-9a-fA-F]{1,2}/', substr($s, $i + 2), $m)) {
                $out .= chr(hexdec($m[0])); $i += 1 + strlen($m[0]); continue;
            }
            if (preg_match('/^[0-7]{1,3}/', substr($s, $i + 1), $m)) {
                $out .= chr(octdec($m[0])); $i += strlen($m[0]); continue;
            }
            if ($nx === 'u' && preg_match('/^u\{([0-9a-fA-F]+)\}/', substr($s, $i + 1), $m)) {
                $cp = hexdec($m[1]);
                $out .= $this->utf8Chr($cp);
                $i += strlen($m[0]);
                continue;
            }
            $out .= $nx; $i++;
        }
        return $out;
    }

    private function utf8Chr(int $cp): string
    {
        if ($cp < 0x80) { return chr($cp); }
        if ($cp < 0x800) { return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F)); }
        if ($cp < 0x10000) { return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)); }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /**
     * [ ... ] 短数组语法。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseArrayShort(Cursor $c, array &$code): void
    {
        $c->expect('[');
        $elems = $this->collectArrayElems($c, ']');
        $c->expect(']');
        $this->emitArray($elems, $code);
    }

    /**
     * array( ... ) 旧语法。
     * @param array<int,array<int,mixed>> $code
     */
    private function parseArrayLegacy(Cursor $c, array &$code): void
    {
        $c->expect('(');
        if ($c->is(')')) {
            $c->next();
            $code[] = [VmOp::ARRAY_MAKE, 0];
            return;
        }
        $elems = $this->collectArrayElems($c, ')');
        $c->expect(')');
        $this->emitArray($elems, $code);
    }

    /**
     * 收集数组的所有元素（key => val 或纯 val）。
     *
     * 历史 bug：原先用 collectUntilTop(',', $close) 收集元素，其"先减后查"语义导致
     * 元素含内层括号对时（如 array(foo($a), 2)）在第一个内层 ) 处提前停止，收集到
     * 残缺表达式；且箭头探测存在运算符优先级错误（A && B && C || D）。
     * 现改为专用扫描器：在数组自身顶层（pd=bd=d=0）遇 ',' / $close / '=>' 才停止，
     * 并正确忽略闭包（fn/function）形参表后的 '=>'。
     *
     * @return array<int,array{key:?array,val:array}>
     */
    private function collectArrayElems(Cursor $c, string $close): array
    {
        $elems = [];
        $c->skipWs();
        if ($c->is($close)) {
            return $elems;
        }
        while (!$c->eof()) {
            [$toks, $stop] = $this->collectArrayElem($c, $close);
            $key = null;
            $val = $toks;
            if ($stop === '=>') {
                $key = $toks;
                // 消费 '=>'（T_DOUBLE_ARROW 单 token；个别 tokenize 路径给 '=' '>' 两 token 时兼容）
                if ($c->is(T_DOUBLE_ARROW)) {
                    $c->next();
                } elseif ($c->is('=') && $c->peek2() === '>') {
                    $c->next();
                    $c->next();
                }
                [$vt, $stop] = $this->collectArrayElem($c, $close);
                $val = $vt;
            }
            if ($this->trimWs($val) !== [] || ($key !== null && $this->trimWs($key) !== [])) {
                $elems[] = ['key' => $key, 'val' => $val];
            }
            if ($stop === ',') {
                $c->next(); // 消费逗号
                $c->skipWs();
                if ($c->is($close)) {
                    break; // 尾逗号
                }
                continue;
            }
            break; // $close 或 EOF
        }
        return $elems;
    }

    /**
     * 收集数组的一个元素 token 序列（不含分隔符/结束符/'=>'，均不消费）。
     * 仅在数组自身顶层（pd=bd=d=0）停止；闭包形参表后的 '=>' 属于闭包自身。
     *
     * @return array{0:array<int,mixed>,1:string} [tokens, stop]，stop ∈ {',', $close, '=>', ''}
     */
    private function collectArrayElem(Cursor $c, string $close): array
    {
        $out = [];
        $pd = 0;
        $bd = 0;
        $d = 0;
        /** @var array<int,array{0:int,1:int,2:int}> 闭包栈：fn/function 出现时的 (pd,bd,d) */
        $closures = [];
        while (!$c->eof()) {
            $t = $c->peek();
            if (is_string($t)) {
                if ($pd === 0 && $bd === 0 && $d === 0) {
                    if ($t === ',') {
                        return [$out, ','];
                    }
                    if ($t === $close) {
                        return [$out, $close];
                    }
                }
                if ($t === '(') {
                    $pd++;
                } elseif ($t === ')') {
                    $pd = max(0, $pd - 1);
                }
                if ($t === '[') {
                    $bd++;
                } elseif ($t === ']') {
                    $bd = max(0, $bd - 1);
                }
                if ($t === '{') {
                    $d++;
                } elseif ($t === '}') {
                    $d = max(0, $d - 1);
                }
                $out[] = $c->next();
                continue;
            }
            if (is_array($t)) {
                if ($t[0] === T_FN || $t[0] === T_FUNCTION) {
                    $closures[] = [$pd, $bd, $d];
                    $out[] = $c->next();
                    continue;
                }
                if ($t[0] === T_DOUBLE_ARROW && $pd === 0 && $bd === 0 && $d === 0) {
                    if ($closures !== []) {
                        $top = end($closures);
                        if ($top[0] === $pd && $top[1] === $bd && $top[2] === $d) {
                            // 闭包自身的 =>：属于元素内部，消费后继续收集
                            array_pop($closures);
                            $out[] = $c->next();
                            continue;
                        }
                    }
                    return [$out, '=>'];
                }
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $d++;
                }
                $out[] = $c->next();
                continue;
            }
            $out[] = $c->next();
        }
        return [$out, ''];
    }

    /**
     * @param array<int,array{key:?array,val:array}> $elems
     * @param array<int,array<int,mixed>> $code
     */
    private function emitArray(array $elems, array &$code): void
    {
        $hasKey = false;
        foreach ($elems as $e) {
            if ($e['key'] !== null) { $hasKey = true; break; }
        }
        if (!$hasKey) {
            foreach ($elems as $e) {
                $this->emitArrayVal($e['val'], $code);
            }
            $code[] = [VmOp::ARRAY_MAKE, count($elems)];
        } else {
            foreach ($elems as $e) {
                $this->emitArrayVal($e['val'], $code);
                if ($e['key'] !== null) {
                    $this->parseExpr($e['key'], $code);
                } else {
                    $code[] = [VmOp::PUSH_NULL];
                }
            }
            $code[] = [VmOp::ARRAY_KV, count($elems)];
        }
    }

    /**
     * 发射数组单个元素的「值」压栈。支持引用元素 [&$x] / ["k"=>&$x[0]]：
     * 先把引用目标链别名为临时槽（复用引用赋值链式桥接），再压引用元素句柄
     * （运行期 ARRAY_MAKE/ARRAY_KV 将其构造为真实数组元素引用）。
     *
     * @param array<int,mixed> $val
     * @param array<int,array<int,mixed>> $code
     */
    private function emitArrayVal(array $val, array &$code): void
    {
        $vt = $this->trimWs($val);
        if ($vt !== [] && $this->isAmpFollowedByVar($vt[0])) {
            $rf = '$__rf' . ($this->tempSeq++);
            $this->compileRefAssignRHS(new Cursor($this->trimWs(array_slice($vt, 1))), $rf, $code);
            $code[] = [VmOp::PUSH_REF_EL, $rf];
            return;
        }
        $this->parseExpr($val, $code);
    }

    // ========================================================================
    // 工具
    // ========================================================================
    private function parseNumber(string $text): int
    {
        $text = trim($text);
        // 数字字面量分隔符（PHP 7.4+）：tokenizer 原样保留下划线，
        // 1_000_000 必须剔除后再解析，否则 (int) 转换只取到 "1"。
        $text = str_replace('_', '', $text);
        if (strlen($text) > 2 && (stripos($text, '0x') === 0)) {
            return (int) base_convert(substr($text, 2), 16, 10);
        }
        if (strlen($text) > 2 && (stripos($text, '0b') === 0)) {
            return (int) base_convert(substr($text, 2), 2, 10);
        }
        // 八进制 0o 前缀（PHP 8.1+）：必须在「0 前缀八进制」之前判，
        // 否则 "0o17" 的 ctype_digit 为假虽不误判，但明确分支更稳。
        if (strlen($text) > 2 && (stripos($text, '0o') === 0)) {
            return (int) base_convert(substr($text, 2), 8, 10);
        }
        // 八进制：0 前缀且全为 0-7（NumberEncoder 会生成 0ddd 形式，必须正确解析，
        // 否则 036 会被当成十进制 36，造成静默错值）
        if (strlen($text) > 1 && $text[0] === '0' && ctype_digit($text)) {
            return (int) base_convert(substr($text, 1), 8, 10);
        }
        return (int) $text;
    }

    private function parseString(string $text): string
    {
        $len = strlen($text);
        $q = $text[0];
        $inner = substr($text, 1, $len - 2);
        if ($q === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        // 双引号：理论上混淆后不会出现插值，但这里仍做安全处理
        if (strpos($inner, '$') !== false) {
            throw new VmUnsupported('VM 不支持双引号字符串插值（已回退）');
        }
        // 双引号转义全集（与 StringEncoder 方案 E 的 \xHH 输出对齐）
        return $this->unescapeDoubleQuoted($inner);
    }

    /**
     * heredoc / nowdoc 编译期折值为常量字符串。
     *
     * PHP 语义（token_get_all 实测）：
     *  - T_START_HEREDOC 文本形如 "<<<EOT\n" / "<<<'NOW'\n"（含起行换行）；
     *  - 正文是若干 T_ENCAPSED_AND_WHITESPACE 片，插值变量是独立 token
     *    （T_VARIABLE / T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES），
     *    转义的 \$ 则留在正文片内——据此区分"可折叠"与"需运行期求值"；
     *  - 闭合标识前恰好一个换行不属于串值；tokenizer 已把闭合行的缩进从
     *    各体行剥离（PHP 7.3+ flexible heredoc）；
     *  - heredoc 处理双引号转义集，nowdoc 全字面（反斜杠不转义）。
     *
     * @param array{0:int,1:string} $startTok T_START_HEREDOC token
     */
    private function parseHeredoc(Cursor $c, array $startTok): string
    {
        $nowdoc = str_contains($startTok[1], "'"); // <<<'ID' 为 nowdoc
        $body = '';
        $closed = false;
        while (!$c->eof()) {
            $t = $c->next();
            if (is_array($t) && $t[0] === T_END_HEREDOC) {
                $closed = true;
                break;
            }
            if (!is_array($t)) {
                continue; // 裸字符不会出现在 heredoc 体内，防御性跳过
            }
            if ($t[0] === T_VARIABLE || $t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                throw new VmUnsupported('VM 不支持带插值的 heredoc（已回退）');
            }
            if ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $body .= $t[1];
            }
            // T_WHITESPACE / T_COMMENT 忽略（注释不会出现在 heredoc 体内）
        }
        if (!$closed) {
            throw new VmUnsupported('VM heredoc 未闭合（已回退）');
        }
        // 闭合标识前的一个换行不属于串值
        if (str_ends_with($body, "\r\n")) {
            $body = substr($body, 0, -2);
        } elseif (str_ends_with($body, "\n")) {
            $body = substr($body, 0, -1);
        }
        return $nowdoc ? $body : $this->unescapeDoubleQuoted($body);
    }

    /** 双引号串转义还原：\" \\ \n \t \r \v \f \e \xHH \u{XXXX} 八进制\ddd */
    private function unescapeDoubleQuoted(string $inner): string
    {
        $out = '';
        $len = strlen($inner);
        $i = 0;
        while ($i < $len) {
            $c = $inner[$i];
            if ($c !== '\\' || $i + 1 >= $len) {
                $out .= $c;
                $i++;
                continue;
            }
            $n = $inner[$i + 1];
            switch ($n) {
                case 'n': $out .= "\n"; $i += 2; break;
                case 't': $out .= "\t"; $i += 2; break;
                case 'r': $out .= "\r"; $i += 2; break;
                case 'v': $out .= "\v"; $i += 2; break;
                case 'f': $out .= "\f"; $i += 2; break;
                case 'e': $out .= "\e"; $i += 2; break;
                case '\\': $out .= '\\'; $i += 2; break;
                case '"': $out .= '"'; $i += 2; break;
                case '$': $out .= '$'; $i += 2; break;
                case '`': $out .= '`'; $i += 2; break;
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
                        $out .= $this->utf8Chr(hexdec($m[1]));
                        $i += 2 + strlen($m[0]) + 2;
                    } else {
                        $out .= '\\u'; $i += 2;
                    }
                    break;
                default:
                    if (preg_match('/^[0-7]{1,3}/', substr($inner, $i + 1), $m)) {
                        $out .= chr(octdec($m[0]));
                        $i += 1 + strlen($m[0]);
                    } else {
                        $out .= '\\' . $n; $i += 2;
                    }
            }
        }
        return $out;
    }
}
