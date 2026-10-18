<?php
/**
 * 控制流混淆（ControlFlowObfuscator）
 *
 * 对 if/else 结构做等价改写，提升逆向难度：
 *  - low   ：为每个条件注入"不透明谓词"(opaque predicate，编译期恒真表达式)，并加冗余括号。
 *  - medium：在 low 基础上，对 if/else 做"条件取反 + 分支交换"（语义完全等价）。
 *  - high  ：在 medium 基础上，额外插入"永不执行的诱饵分支"(opaque false) 干扰分析。
 *
 * 仅处理形如 `if (cond) {..} else {..}` / `if (cond) stmt;` / 含 else 的结构；
 * 遇到 elseif / 替代语法则跳过该 if，保证正确性。
 *
 * @note 仅在管线最后一次重复（isLast）时执行。
 */

declare(strict_types=1);

final class ControlFlowObfuscator
{
    public function __construct(private Logger $logger, private ?Salt $salt = null)
    {
        // 空 salt 时内部退化为真随机；非空时全部随机量由 salt 派生（可复现构建）
        $this->salt ??= new Salt('');
        $this->seq = 0;
    }

    private int $seq = 0;

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config, bool $isLast = false): array
    {
        $mode = $config->get('cfg');
        if (!in_array($mode, ['low', 'medium', 'high'], true)) {
            return $tokens;
        }
        if (!$isLast) {
            return $tokens;
        }

        $result = [];
        $n = count($tokens);
        $i = 0;
        $applied = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_IF) {
                $new = $this->transformIf($tokens, $i, $mode);
                if ($new !== null) {
                    foreach ($new as $c) {
                        $result[] = $c;
                    }
                    $applied++;
                    // 跳过整个 if 语句（通过匹配结束位置）
                    $i = $this->ifEndIndex($tokens, $i) + 1;
                    continue;
                }
            }
            $result[] = $t;
            $i++;
        }
        if ($applied > 0) {
            $this->logger->debug("控制流混淆（{$mode}）：改写 {$applied} 处 if");
        }
        return $result;
    }

    /**
     * 改写从 $idx (T_IF) 起始的 if 语句；成功返回全新的 token 序列，失败返回 null。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>|null
     */
    private function transformIf(array $tokens, int $idx, string $mode): ?array
    {
        // 找条件 '('
        $condOpen = Parser::nextSignificant($tokens, $idx, 1);
        if ($condOpen < 0 || $tokens[$condOpen] !== '(') {
            return null;
        }
        $condClose = $this->findClose($tokens, $condOpen, '(', ')');
        if ($condClose < 0) {
            return null;
        }
        $condInner = array_slice($tokens, $condOpen + 1, $condClose - $condOpen - 1);

        // then 主体
        $after = Parser::nextSignificant($tokens, $condClose, 1);
        if ($after < 0) {
            return null;
        }
        [$thenTokens, $thenEnd] = $this->extractBody($tokens, $after);
        if ($thenEnd < 0) {
            return null;
        }

        // else？
        $elseAfter = Parser::nextSignificant($tokens, $thenEnd, 1);
        $hasElse = ($elseAfter >= 0 && is_array($tokens[$elseAfter])
            && ($tokens[$elseAfter][0] === T_ELSE || $tokens[$elseAfter][0] === T_ELSEIF));
        $elseTokens = [];
        $elseEnd = $thenEnd;
        if ($hasElse) {
            if ($tokens[$elseAfter][0] === T_ELSEIF) {
                return null; // 不支持 elseif，跳过
            }
            // T_ELSE 之后
            $eBody = Parser::nextSignificant($tokens, $elseAfter, 1);
            if ($eBody < 0) {
                return null;
            }
            [$elseTokens, $elseEnd] = $this->extractBody($tokens, $eBody);
            if ($elseEnd < 0) {
                return null;
            }
        }

        $opaque = $this->opaqueTrueTokens();
        $out = [];
        $out[] = [T_IF, 'if', 0];
        $out[] = '(';

        if ($mode === 'low') {
            // ( condInner ) && ( opaque )
            $out[] = '(';
            foreach ($condInner as $c) {
                $out[] = $c;
            }
            $out[] = ')';
            $out[] = '&&';
            $out[] = '(';
            foreach ($opaque as $c) {
                $out[] = $c;
            }
            $out[] = ')';
        } else {
            // !( condInner ) && ( opaque )
            $out[] = '!';
            $out[] = '(';
            foreach ($condInner as $c) {
                $out[] = $c;
            }
            $out[] = ')';
            $out[] = '&&';
            $out[] = '(';
            foreach ($opaque as $c) {
                $out[] = $c;
            }
            $out[] = ')';
        }
        $out[] = ')';

        if ($mode === 'low') {
            foreach ($thenTokens as $c) {
                $out[] = $c;
            }
            if ($hasElse) {
                $out[] = [T_ELSE, 'else', 0];
                foreach ($elseTokens as $c) {
                    $out[] = $c;
                }
            }
        } else {
            // 分支交换：新 then = 旧 else（无则空块），新 else = 旧 then
            $newThen = $hasElse ? $elseTokens : ['{', '}'];
            foreach ($newThen as $c) {
                $out[] = $c;
            }
            $out[] = [T_ELSE, 'else', 0];
            foreach ($thenTokens as $c) {
                $out[] = $c;
            }
            if ($mode === 'high') {
                // 末尾追加永不执行的诱饵分支
                $out = array_merge($out, $this->decoyBranch());
            }
        }

        return $out;
    }

    /**
     * 找到从 $idx (T_IF) 起始的 if 语句最后一个 token 的索引（含）。
     * @param array<int,mixed> $tokens
     */
    private function ifEndIndex(array $tokens, int $idx): int
    {
        $condOpen = Parser::nextSignificant($tokens, $idx, 1);
        $condClose = $this->findClose($tokens, $condOpen, '(', ')');
        $after = Parser::nextSignificant($tokens, $condClose, 1);
        [$thenTokens, $thenEnd] = $this->extractBody($tokens, $after);
        $elseAfter = Parser::nextSignificant($tokens, $thenEnd, 1);
        if ($elseAfter >= 0 && is_array($tokens[$elseAfter]) && $tokens[$elseAfter][0] === T_ELSE) {
            $eBody = Parser::nextSignificant($tokens, $elseAfter, 1);
            [$elseTokens, $elseEnd] = $this->extractBody($tokens, $eBody);
            return $elseEnd;
        }
        return $thenEnd;
    }

    /**
     * 提取一个语句主体：{...} 块或单条以深度0 ';' 结尾的语句。
     * 返回 [tokens, endIndex]，失败返回 [[], -1]。
     * @param array<int,mixed> $tokens
     * @return array{0:array,int:int}
     */
    private function extractBody(array $tokens, int $start): array
    {
        $t = $tokens[$start];
        if ($t === '{') {
            $close = $this->findClose($tokens, $start, '{', '}');
            if ($close < 0) {
                return [[], -1];
            }
            return [array_slice($tokens, $start, $close - $start + 1), $close];
        }
        // 单条语句：直到深度0 的 ';'
        $depth = 0;
        $pdepth = 0;
        $bdepth = 0;
        $n = count($tokens);
        for ($i = $start; $i < $n; $i++) {
            $tk = $tokens[$i];
            if ($tk === '(') {
                $pdepth++;
            } elseif ($tk === ')') {
                if ($pdepth > 0) {
                    $pdepth--;
                }
            } elseif ($tk === '[') {
                $bdepth++;
            } elseif ($tk === ']') {
                if ($bdepth > 0) {
                    $bdepth--;
                }
            } elseif ($tk === '{') {
                $depth++;
            } elseif (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                // "{$expr}" 起始是数组 token 但闭合是裸 '}'：计入花括号深度，
                // 否则插值串会让语句在第一个 '}' 后被误判为顶层 ';'
                $depth++;
            } elseif ($tk === '}') {
                if ($depth > 0) {
                    $depth--;
                }
            } elseif ($tk === ';') {
                if ($depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    return [array_slice($tokens, $start, $i - $start + 1), $i];
                }
            }
        }
        return [[], -1];
    }

    /**
     * 配对定界符。花括号配对时需把 T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES
     * （"{$expr}" 起始，数组 token）计入深度——其闭合是裸 '}' 字符，
     * 不计会让块范围在第一个插值串处提前结束。
     */
    private function findClose(array $tokens, int $open, string $openCh, string $closeCh): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $tk = $tokens[$i];
            if ($tk === $openCh) {
                $depth++;
            } elseif ($openCh === '{' && is_array($tk)
                && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($tk === $closeCh) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /** @return array<int,mixed> */
    private function opaqueTrueTokens(): array
    {
        $a = $this->salt->isEmpty()
            ? random_int(2, 200)
            : $this->salt->deriveInt('cf-pred-' . (++$this->seq), 2, 200);
        $b = $a - 1;
        return [
            '(',
            [T_LNUMBER, (string) $a, 0],
            '-',
            [T_LNUMBER, (string) $b, 0],
            ')',
            '===',
            [T_LNUMBER, '1', 0],
        ];
    }

    /** @return array<int,mixed> */
    private function decoyBranch(): array
    {
        // if (0x0===0x1) { <死代码> } —— 永不执行
        // 变量名由 NameStyle 从业务词池生成（原 $__dc_xxxx 是典型"混淆器生成"指纹）
        $v = (new NameStyle($this->salt))->varName('cf-decoy-' . (++$this->seq));
        return [
            [T_IF, 'if', 0],
            '(',
            [T_LNUMBER, '0', 0],
            '===',
            [T_LNUMBER, '1', 0],
            ')',
            '{',
            [T_VARIABLE, $v, 0],
            '=',
            [T_LNUMBER, '0', 0],
            ';',
            [T_VARIABLE, $v, 0],
            '++',
            ';',
            '}',
        ];
    }
}
