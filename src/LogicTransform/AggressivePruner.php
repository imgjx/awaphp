<?php
/**
 * 激进恒等式压缩 + 死分支剪枝（AggressivePruner，v1.2.8 引入，v1.2.10 重新定位）
 *
 * 【定位】这是一个**性能层**：把用户自己写的恒等式（`strlen(md5('x'))`、`$x^0`、
 * `($a|0)` 这类构建期可求值的纯字面量表达式）在产物里折叠回裸值，把用户自己写的
 * 恒真/恒假分支剪成直达路径——目标是让用户脚本跑得更快，产物更小。
 *
 * 【必须排在 logic 之前】它只看用户原始代码。Logic 层之后注入的恒等式对它完全不可见，
 * 因此"Logic 的成果不会被它解析加速"是**结构保证**，不是靠约定。为防自定义 config
 * 把顺序写反，运行期还有两道兜底：
 *   1. Obfuscator 传入 setOrderSafe(false) 时整层空转（分层管线里 logic 已跑过即传 false）；
 *   2. 内容指纹：token 流里已出现 Logic 的积木特征（sha1/md5 大量成对、base64_decode +
 *      hex2bin + str_rot13 组合）时，视为已被 Logic 处理，整层空转。
 *
 * 【两个 pass】
 *   A 恒等式折叠：把参数全为字面量的纯函数调用用【ConstEval 静态符号执行】求值并替换为
 *     结果字面量（v1.2.8 起彻底零 eval，杜绝在线混淆服务器上的 webshell 注入面）；
 *     纯字面量算术再复用 ConstFolder。内层先行、多遍迭代，直到无变化。
 *   B 死分支剪枝：把可静态判定的恒真/恒假条件剪掉不可达分支；死支含 function/class/
 *     标签/goto 等编译期声明时保留整段（避免破坏声明）。
 *
 * 【安全（绝不改炸）】
 *   - 只处理"参数全为字面量或已折叠字面量"的纯函数，碰变量/成员访问/赋值一律跳过；
 *   - 每处求值走 ConstEval（白名单 token + PHP 原生运算符，零 eval），同一表达式
 *     求值两次严格比对（=== 含类型），无法求值或不一致即整处原样保留；
 *   - 只回写标量字面量（int/float/string），数组/对象/资源一律放弃。
 */

declare(strict_types=1);

require_once __DIR__ . '/ConstFolder.php';
require_once __DIR__ . '/../Support/ConstEval.php';

final class AggressivePruner
{
    private Logger $logger;
    private int $folded = 0;
    private int $pruned = 0;
    /**
     * v1.2.10：层序是否安全（本层是否确实排在 logic 之前）。
     * false 时整层空转——宁可少加速，也不把 Logic 注入的恒等式折掉。
     */
    private bool $orderSafe = true;

    /**
     * 允许在构建期求值的纯函数（无副作用、结果只由参数决定）。
     * 全部是 IdentityExpr 积木用到的函数 + 常见字符串/数值纯函数。
     */
    private const PURE_FNS = [
        'strlen' => true, 'md5' => true, 'sha1' => true, 'crc32' => true,
        'base64_decode' => true, 'hex2bin' => true, 'str_rot13' => true,
        'strrev' => true, 'strtoupper' => true, 'strtolower' => true,
        'ord' => true, 'hexdec' => true, 'bindec' => true, 'dechex' => true,
        'decbin' => true, 'abs' => true, 'str_repeat' => true, 'substr' => true,
        'intdiv' => true, 'intval' => true, 'floatval' => true, 'strval' => true,
        'trim' => true, 'ltrim' => true, 'rtrim' => true, 'str_pad' => true,
    ];

    /** Logic 层注入的积木函数名（用于"已被 Logic 处理过"的内容指纹） */
    private const LOGIC_FNS = [
        'sha1' => true, 'md5' => true, 'base64_decode' => true,
        'hex2bin' => true, 'str_rot13' => true,
    ];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /** v1.2.10：由调用方告知层序是否安全（本层是否在 logic 之前） */
    public function setOrderSafe(bool $on): void
    {
        $this->orderSafe = $on;
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        $this->folded = 0;
        $this->pruned = 0;
        if (!$cfg->get('aggressive_prune', false)) {
            return $tokens;
        }
        // 兜底 1：层序不安全（本层被排在 logic 之后）→ 整层空转。
        // 这种情况下继续折叠会把 Logic 注入的恒等式全折回字面量，等于白做 Logic。
        if (!$this->orderSafe) {
            $this->logger->debug('aggressive-prune: 跳过（本层必须在 logic 之前，否则会折叠 Logic 注入的恒等式）');
            return $tokens;
        }
        // 兜底 2：内容指纹——token 流里已布满 Logic 的积木组合，视为已被 Logic 处理过。
        // 阈值取 3，避免用户自己代码里恰好有一两处 md5/sha1 时被误判。
        if ($this->looksLikeLogicOutput($tokens)) {
            $this->logger->debug('aggressive-prune: 跳过（检测到 Logic 层积木特征，拒绝解析其注入的恒等式）');
            return $tokens;
        }

        // Pass A：纯函数调用折叠（内层优先，多遍）——先折叠 strlen(md5('x')) 的实参，
        // 下一遍再折叠外层 strlen。每遍最多处理一轮，避免一次替换打乱后续下标。
        for ($pass = 0; $pass < 8; $pass++) {
            $next = $this->foldPureCallsOnce($tokens);
            if ($next === $tokens) {
                break;
            }
            $tokens = $next;
        }
        // 纯字面量算术（恒等式里的 + - * << >> ^ 等）复用 ConstFolder 的表达式折叠，
        // 但关掉它的循环折叠（循环已在 constfold 层处理过，此处不重复）。
        $cfCfg = new Config(['const_fold' => true, 'const_fold_level' => 3, 'loop_fold' => false]);
        $tokens = (new ConstFolder($this->logger))->process($tokens, $cfCfg);

        // Pass B：死分支剪枝（多遍，剪掉一层后可能出现新的可折叠 if）
        for ($pass = 0; $pass < 6; $pass++) {
            $next = $this->pruneBranchesOnce($tokens);
            if ($next === $tokens) {
                break;
            }
            $tokens = $next;
        }

        if ($this->folded > 0 || $this->pruned > 0) {
            $this->logger->debug(sprintf(
                'aggressive-prune: 折叠 %d 处恒等式、剪枝 %d 处死分支',
                $this->folded,
                $this->pruned
            ));
        }
        return $tokens;
    }

    /**
     * 内容指纹：判断 token 流是否已经含有 Logic 层注入的积木组合。
     * Logic 的整数积木固定同时用 sha1 与 md5（`strlen(sha1(...))*n + strlen(md5(...))*m`），
     * 字符串积木用 base64_decode / hex2bin / str_rot13 的组合，且会**大量**出现。
     * 单份用户代码里三者齐备且数量可观的情形极罕见，命中即按"已被 Logic 处理"处理。
     *
     * @param array<int,mixed> $tokens
     */
    private function looksLikeLogicOutput(array $tokens): bool
    {
        $cnt = ['sha1' => 0, 'md5' => 0, 'base64_decode' => 0, 'hex2bin' => 0, 'str_rot13' => 0];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!Parser::isToken($t) || $t[0] !== T_STRING) {
                continue;
            }
            $name = (string) $t[1];
            if (!isset(self::LOGIC_FNS[$name])) {
                continue;
            }
            $open = $this->nextSignificant($tokens, $i + 1);
            if ($open !== null && is_string($tokens[$open]) && $tokens[$open] === '(') {
                $cnt[$name]++;
            }
        }
        if ($cnt['sha1'] >= 3 && $cnt['md5'] >= 3) {
            return true;
        }
        $strMix = ($cnt['base64_decode'] > 0 ? 1 : 0)
            + ($cnt['hex2bin'] > 0 ? 1 : 0)
            + ($cnt['str_rot13'] > 0 ? 1 : 0);
        return $strMix >= 2 && ($cnt['base64_decode'] + $cnt['hex2bin'] + $cnt['str_rot13']) >= 3;
    }

    // ========================== Pass A：纯函数调用折叠 ==========================

    /**
     * 单遍：找到参数全为字面量的纯函数调用并替换为结果字面量。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function foldPureCallsOnce(array $tokens): array
    {
        $n = count($tokens);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            $repl = null;
            // 形态：T_STRING '(' ... ')'，函数名在白名单
            if (Parser::isToken($t) && $t[0] === T_STRING && isset(self::PURE_FNS[(string) $t[1]])) {
                $open = $this->nextSignificant($tokens, $i + 1);
                if ($open !== null && is_string($tokens[$open]) && $tokens[$open] === '(') {
                    $close = $this->matchParen($tokens, $open);
                    if ($close !== null) {
                        // 实参必须全为字面量 token（字符串/数字），或已被上遍折叠的字面量。
                        // v1.2.8：保留 token 形态交 ConstEval 静态求值，绝不拼串 eval。
                        $argTokens = [];
                        $allLit = true;
                        for ($k = $open + 1; $k < $close; $k++) {
                            $a = $tokens[$k];
                            if (Parser::isWhitespaceOrComment($a)) {
                                continue;
                            }
                            if (is_string($a)) {
                                // 逗号/负号是实参里的合法分隔符与一元符号
                                if ($a === ',' || $a === '-' || $a === '+') {
                                    $argTokens[] = $a;
                                    continue;
                                }
                                $allLit = false;
                                break;
                            }
                            if (in_array($a[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)) {
                                $argTokens[] = $a;
                            } else {
                                $allLit = false;
                                break;
                            }
                        }
                        if ($allLit && $argTokens !== []) {
                            $callTokens = array_merge([$t, '('], $argTokens, [')']);
                            $val = $this->evalTokensTwice($callTokens);
                            if ($val !== null && (is_int($val) || is_float($val) || is_string($val))) {
                                $repl = $this->literalToken($val, $t);
                                $i = $close + 1;
                            }
                        }
                    }
                }
            }
            if ($repl !== null) {
                $out[] = $repl;
                $this->folded++;
                continue;
            }
            $out[] = $tokens[$i];
            $i++;
        }
        return $out;
    }

    // ========================== Pass B：死分支剪枝 ==========================

    /**
     * 单遍：判定 if 条件恒真/恒假并剪枝。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function pruneBranchesOnce(array $tokens): array
    {
        $n = count($tokens);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if (Parser::isToken($t) && $t[0] === T_IF) {
                $r = $this->tryPruneIf($tokens, $i);
                if ($r !== null) {
                    foreach ($r['keep'] as $kt) {
                        $out[] = $kt;
                    }
                    $i = $r['end'] + 1;
                    $this->pruned++;
                    continue;
                }
            }
            $out[] = $tokens[$i];
            $i++;
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array{keep:array<int,mixed>,end:int}|null
     */
    private function tryPruneIf(array $tokens, int $i): ?array
    {
        $n = count($tokens);
        $open = $this->nextSignificant($tokens, $i + 1);
        if ($open === null || !is_string($tokens[$open]) || $tokens[$open] !== '(') {
            return null;
        }
        $close = $this->matchParen($tokens, $open);
        if ($close === null) {
            return null;
        }
        // 条件必须是"可静态判定"的：先做自反恒等式归一（$x===$x→1, $x!==$x→0），
        // 再要求剩余全是字面量/纯函数/比较/逻辑运算符，eval 求值两次。
        $condTokens = [];
        for ($k = $open + 1; $k < $close; $k++) {
            if (Parser::isWhitespaceOrComment($tokens[$k])) {
                continue;
            }
            $condTokens[] = $tokens[$k];
        }
        $normalized = $this->normalizeCondition($condTokens);
        if ($normalized === null) {
            return null;
        }
        // v1.2.8：归一后的条件 token 交 ConstEval 静态求值（env 为空——归一化已把
        // 自反变量比较替换成字面量，剩余 token 无变量），绝不拼串 eval。
        $truth = $this->evalTokensTwice($normalized);
        if (!is_bool($truth) && !is_int($truth)) {
            return null;
        }

        // 定位 then 块：'{' ... '}' 或单语句到 ';'
        $after = $this->nextSignificant($tokens, $close + 1);
        if ($after === null) {
            return null;
        }
        if (is_string($tokens[$after]) && $tokens[$after] === '{') {
            $thenEnd = $this->matchBrace($tokens, $after);
            if ($thenEnd === null) {
                return null;
            }
            $then = array_slice($tokens, $after + 1, $thenEnd - $after - 1);
            $cursor = $thenEnd + 1;
        } else {
            // 单语句分支：到下一个顶层 ';'
            $semi = $this->findStatementEnd($tokens, $after);
            if ($semi === null) {
                return null;
            }
            $then = array_slice($tokens, $after, $semi - $after + 1);
            $cursor = $semi + 1;
        }

        // else 块（可选）
        $elseTok = $this->nextSignificant($tokens, $cursor);
        $else = [];
        $end = $cursor - 1;
        if ($elseTok !== null && Parser::isToken($tokens[$elseTok]) && $tokens[$elseTok][0] === T_ELSE) {
            $afterElse = $this->nextSignificant($tokens, $elseTok + 1);
            if ($afterElse === null) {
                return null;
            }
            if (is_string($tokens[$afterElse]) && $tokens[$afterElse] === '{') {
                $elseEnd = $this->matchBrace($tokens, $afterElse);
                if ($elseEnd === null) {
                    return null;
                }
                $else = array_slice($tokens, $afterElse + 1, $elseEnd - $afterElse - 1);
                $end = $elseEnd;
            } else {
                $semi = $this->findStatementEnd($tokens, $afterElse);
                if ($semi === null) {
                    return null;
                }
                $else = array_slice($tokens, $afterElse, $semi - $afterElse + 1);
                $end = $semi;
            }
        }

        $take = $truth ? $then : $else;
        // 死支若含编译期声明/标签，保留整段不剪（避免破坏 function/class/goto 语义）
        $dead = $truth ? $else : $then;
        if ($this->hasDeclaration($dead)) {
            return null;
        }
        return ['keep' => array_values($take), 'end' => $end];
    }

    /**
     * 自反恒等式归一：$x===$x / $x==$x → 1；$x!==$x / $x!=$x → 0。
     * 归一后若条件里仍含变量/成员访问/赋值 → 返回 null（不可静态判定）。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>|null
     */
    private function normalizeCondition(array $tokens): ?array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            // 匹配 $v (===|==|!==|!=) $v —— 运算符可能是字符串 token 也可能是数组 token
            // （PHP 8 的 === !== == != 是 T_IS_IDENTICAL 等数组 token）。
            $opText = ($i + 1 < $n) ? Parser::text($tokens[$i + 1]) : '';
            if (Parser::isToken($t) && $t[0] === T_VARIABLE
                && $i + 2 < $n && in_array($opText, ['===', '==', '!==', '!='], true)
                && Parser::isToken($tokens[$i + 2]) && $tokens[$i + 2][0] === T_VARIABLE
                && $tokens[$i + 2][1] === $t[1]) {
                $out[] = [T_LNUMBER, in_array($opText, ['===', '=='], true) ? '1' : '0', $t[2] ?? 1];
                $i += 2;
                continue;
            }
            $out[] = $t;
        }
        // 剩余 token 必须全是"字面量 / 纯函数名 / 比较与逻辑运算符 / 括号 / 一元!"
        foreach ($out as $tk) {
            if (Parser::isWhitespaceOrComment($tk)) {
                continue;
            }
            if (is_string($tk)) {
                if (!in_array($tk, ['<', '>', '<=', '>=', '===', '!==', '==', '!=', '&&', '||',
                    '!', '(', ')', '+', '-', '*', '/', '%', '&', '|', '^', ',', '='], true)) {
                    return null;
                }
                continue;
            }
            $id = $tk[0];
            if (in_array($id, [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_STRING,
                T_BOOLEAN_AND, T_BOOLEAN_OR, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL,
                T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
                T_LOGICAL_AND, T_LOGICAL_OR, T_SL, T_SR], true)) {
                if ($id === T_STRING && !isset(self::PURE_FNS[(string) $tk[1]])) {
                    return null;
                }
                continue;
            }
            return null; // 变量 / 成员访问 / 赋值等，不可静态判定
        }
        return $out;
    }

    // ========================== 通用工具 ==========================

    /**
     * v1.2.8：用 ConstEval 静态求值 token 序列两次并严格比对（含类型）。
     * 彻底弃用 eval——在线混淆服务器里 eval(用户代码 token) = webshell 注入器。
     * 无法安全求值（非白名单/除零/残留 token）→ ConstEval 返回 null → 放弃本处。
     *
     * @param array<int,mixed> $tokens
     */
    private function evalTokensTwice(array $tokens): mixed
    {
        $env = [];
        $a = ConstEval::evalExpr($tokens, $env);
        $env2 = [];
        $b = ConstEval::evalExpr($tokens, $env2);
        if ($a === null || $a !== $b) {
            return null;
        }
        return $a;
    }

    /** token 还原为源码文本 */
    private function tokenSource($tk): string
    {
        return Parser::text($tk);
    }

    private function nextSignificant(array $tokens, int $from): ?int
    {
        $n = count($tokens);
        for ($k = $from; $k < $n; $k++) {
            if (!Parser::isWhitespaceOrComment($tokens[$k])) {
                return $k;
            }
        }
        return null;
    }

    private function matchParen(array $tokens, int $open): ?int
    {
        return $this->matchPair($tokens, $open, '(', ')');
    }

    private function matchBrace(array $tokens, int $open): ?int
    {
        return $this->matchPair($tokens, $open, '{', '}');
    }

    private function matchPair(array $tokens, int $open, string $l, string $r): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($k = $open; $k < $n; $k++) {
            $t = $tokens[$k];
            if (!is_string($t)) {
                continue;
            }
            if ($t === $l) {
                $depth++;
            } elseif ($t === $r) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }
        return null;
    }

    private function findStatementEnd(array $tokens, int $from): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($k = $from; $k < $n; $k++) {
            $t = $tokens[$k];
            if (!is_string($t)) {
                continue;
            }
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($t === ';' && $depth === 0) {
                return $k;
            }
        }
        return null;
    }

    /** 片段内是否含编译期声明 / 标签 / goto（含则不剪该分支） */
    private function hasDeclaration(array $tokens): bool
    {
        $danger = [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM, T_GOTO];
        foreach ($tokens as $t) {
            if (Parser::isToken($t) && in_array($t[0], $danger, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int|string|float,1:string,2:int} */
    private function literalToken($v, $src): array
    {
        $line = 1;
        if (Parser::isToken($src) && isset($src[2])) {
            $line = (int) $src[2];
        }
        if (is_int($v)) {
            return [T_LNUMBER, (string) $v, $line];
        }
        if (is_float($v)) {
            $s = var_export($v, true);
            if (strpos($s, '.') === false && stripos($s, 'e') === false) {
                $s .= '.0';
            }
            return [T_DNUMBER, $s, $line];
        }
        return [T_CONSTANT_ENCAPSED_STRING, "'" . addcslashes((string) $v, "\\'") . "'", $line];
    }
}
