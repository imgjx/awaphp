<?php
/**
 * 深度反AST（AntiAst）
 *
 * 在 token 层把"语句位的 echo / print / return 常量字面量"改写成**累加器 + 恒等表达式**形式，
 * 使源码里再也看不到裸数字 / 裸字符串，且改写前后语义逐字节等价。
 *
 * 示例（echo 1; echo 123;）：
 *   $z7 = strlen(sha1(1)); echo $z7;           // 40，再减到 1 见下
 *   → 实际生成：先用 strlen(md5(1)) 等积木拼出 1，再 $z7 = $z7 + (...拼出122...) 拼出 123
 *
 * 设计要点：
 *   - 仅作用于"语句位"的单一常量字面量（前有 ; }{ : 或开标签；后紧跟 ;），多参数 echo(1,2)、
 *     ternary 内的 return、赋值右侧等一律不改，避免改变语义；
 *   - 整数用 IdentityExpr::intExpr 生成（strlen(md5(1))/strlen(sha1(1)) 积木组合），同一作用域内
 *     复用同一个累加变量做加减，制造"变量被反复改写"的假象；
 *   - 字符串用 base64_decode(base64_encode(...)) 恒等还原；
 *   - 跨作用域（深度变化 / 进入函数类）自动重置累加变量，杜绝跨作用域串味；
 *   - 累加变量名由 Salt 派生且保证不与文件内已有变量重名，后续 var 层会一并重命名；
 *   - 每条生成式构建期经 IdentityExpr::verify 校验，不通过则整条原样保留（绝不引入错误）。
 */

declare(strict_types=1);

final class AntiAst
{
    private Salt $salt;
    private Logger $logger;

    /** 整数积木（用于拼装，命中率高、表达式短） */
    private IdentityExpr $id;

    /** 文件内已出现的变量名集合（用于累加变量名去重） */
    private array $usedVars = [];

    public function __construct(Salt $salt, Logger $logger)
    {
        $this->salt = $salt;
        $this->logger = $logger;
        $this->id = new IdentityExpr();
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        if (!$cfg->get('anti_ast')) {
            return $tokens;
        }
        $this->collectVars($tokens);

        $out = [];
        $depth = 0;
        $accName = null;
        $accVal = null;
        $accType = null;
        $introDepth = null;

        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $tok = $tokens[$i];
            // 作用域深度跟踪（用于跨作用域重置累加器）
            if (is_string($tok)) {
                if ($tok === '{' || $tok === '(' || $tok === '[') {
                    $depth++;
                } elseif ($tok === '}' || $tok === ')' || $tok === ']') {
                    $depth = max(0, $depth - 1);
                }
            }

            if (is_array($tok) && in_array($tok[0], [T_ECHO, T_PRINT, T_RETURN], true)
                && $this->atStatementPos($out)) {
                $j = $i + 1;
                $this->skipWs($tokens, $j);
                if ($j < $n && $this->isConstLiteral($tokens[$j])) {
                    $lit = $tokens[$j];
                    $k = $j + 1;
                    $this->skipWs($tokens, $k);
                    if ($k < $n && $tokens[$k] === ';') {
                        // 命中：语句位单一常量字面量
                        $snippet = $this->build(
                            $tok[0],
                            $lit,
                            $accName,
                            $accVal,
                            $accType,
                            $introDepth,
                            $depth
                        );
                        if ($snippet !== null) {
                            $accName = $snippet['accName'];
                            $accVal = $snippet['accVal'];
                            $accType = $snippet['accType'];
                            $introDepth = $snippet['introDepth'];
                            $this->appendSnippet($out, $snippet['code']);
                            $i = $k + 1;
                            continue;
                        }
                    }
                }
            }

            $out[] = $tok;
            $i++;
        }
        return $out;
    }

    /**
     * 构造改写片段。
     * @param array{accName?:string,accVal?:int,accType?:?string,introDepth?:?int} $accName
     * @return array{code:string,accName:?string,accVal:?int,accType:?string,introDepth:?int}|null
     */
    private function build(int $stmtKind, array $lit, ?string &$accName, ?int &$accVal, ?string &$accType, ?int &$introDepth, int $depth): ?array
    {
        $litKind = $lit[0];
        $raw = $lit[1];

        if ($litKind === T_LNUMBER || $litKind === T_DNUMBER) {
            if ($litKind === T_DNUMBER) {
                return null; // 浮点暂不处理（避免精度/格式歧义）
            }
            $v = (int) $raw;
            $expr = $this->id->intExpr($v);
            if (!$this->id->verify($v, $expr)) {
                return null;
            }
            // 作用域变化 → 重置累加器
            if ($accType !== 'int' || $depth !== $introDepth || $accName === null) {
                $accName = $this->genName();
                $accVal = $v;
                $accType = 'int';
                $introDepth = $depth;
                $code = "\${$accName} = {$expr};\n";
            } else {
                $delta = $v - $accVal;
                if ($delta === 0) {
                    $code = '';
                } else {
                    $op = $delta > 0 ? '+' : '-';
                    $mag = $delta > 0 ? $delta : -$delta;
                    $e2 = $this->id->intExpr($mag);
                    if (!$this->id->verify($mag, $e2)) {
                        return null;
                    }
                    $code = "\${$accName} = \${$accName} {$op} {$e2};\n";
                    $accVal = $v;
                }
            }
            $keyword = $stmtKind === T_RETURN ? 'return' : ($stmtKind === T_PRINT ? 'print' : 'echo');
            // 强化：累加变量再经一次极廉价的恒等运算（int ^ 0），打散 AST
            $cheap = $this->id->cheapIntOps($accName);
            $code .= "{$cheap}{$keyword} \${$accName};\n";
            return ['code' => $code, 'accName' => $accName, 'accVal' => $accVal, 'accType' => $accType, 'introDepth' => $introDepth];
        }

        if ($litKind === T_CONSTANT_ENCAPSED_STRING) {
            $s = $this->stripQuotes($raw);
            $expr = $this->id->stringExpr($s);
            if (!$this->id->verify($s, $expr)) {
                return null;
            }
            // 字符串恒等：每条独立（避免与整数累加器串味），但同名复用同一变量不强制
            $nm = $this->genName();
            $this->usedVars[$nm] = true;
            $keyword = $stmtKind === T_RETURN ? 'return' : ($stmtKind === T_PRINT ? 'print' : 'echo');
            // 强化：先解码一段、再拼接一段 → 定义变量 → 极廉价运算 → 输出
            $cheap = $this->id->cheapStringOps($nm);
            $code = "\${$nm} = {$expr};\n{$cheap}{$keyword} \${$nm};\n";
            // 字符串不改写 accVal/accType（累加器仅用于整数），且不影响整数累加器状态
            return ['code' => $code, 'accName' => $accName, 'accVal' => $accVal, 'accType' => $accType, 'introDepth' => $introDepth];
        }

        return null;
    }

    private function appendSnippet(array &$out, string $code): void
    {
        // 片段是纯 PHP 代码，必须带开标签再 tokenize —— 否则 token_get_all 会把整段
        // 当成 T_INLINE_HTML（一个 token），导致 out 尾部不再是 `;`、atStatementPos 误判、
        // 后续同作用域 echo/return 累加器改写被整段跳过。
        $toks = Parser::tokenize('<?php ' . $code);
        if (!empty($toks) && is_array($toks[0]) && $toks[0][0] === T_OPEN_TAG) {
            array_shift($toks); // 去掉前导开标签，避免破坏产物开标签唯一性
        }
        foreach ($toks as $t) {
            $out[] = $t;
        }
    }

    private function atStatementPos(array $out): bool
    {
        $prev = $this->lastSignificant($out);
        if ($prev === null) {
            return true; // 文件开头
        }
        if (is_string($prev)) {
            return in_array($prev, [';', '}', '{', ':'], true);
        }
        if (is_array($prev) && $prev[0] === T_OPEN_TAG) {
            return true;
        }
        return false;
    }

    /** @param array<int,mixed> $out */
    private function lastSignificant(array $out)
    {
        for ($i = count($out) - 1; $i >= 0; $i--) {
            $t = $out[$i];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /** @param array<int,mixed> $tokens */
    private function skipWs(array $tokens, int &$j): void
    {
        $n = count($tokens);
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
    }

    /** @param mixed $tok */
    private function isConstLiteral($tok): bool
    {
        return is_array($tok) && in_array($tok[0], [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING], true);
    }

    /** @param array<int,mixed> $tokens */
    private function collectVars(array $tokens): void
    {
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_VARIABLE) {
                $this->usedVars[ltrim($t[1], '$')] = true;
            }
        }
    }

    private function genName(): string
    {
        $seed = $this->salt->isEmpty() ? bin2hex(random_bytes(4)) : $this->salt->derive('anti-ast', 10);
        for ($try = 0; $try < 50; $try++) {
            $cand = '_z' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $seed), 0, 6) . ($try === 0 ? '' : (string) $try);
            if (!isset($this->usedVars[$cand])) {
                $this->usedVars[$cand] = true;
                return $cand;
            }
        }
        return '_z' . substr(md5($seed . microtime()), 0, 8);
    }

    private function stripQuotes(string $raw): string
    {
        $q = $raw[0];
        $inner = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        // 双引号：还原转义（\n \t \$ \\ \"" 等）
        return preg_replace_callback('/\\\\([nrt\\\\$"\'])/', static fn($m): string => match ($m[1]) {
            'n' => "\n", 't' => "\t", 'r' => "\r", '\\' => '\\', '$' => '$', '"' => '"', "'" => "'",
        }, $inner);
    }
}
