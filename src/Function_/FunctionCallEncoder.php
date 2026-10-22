<?php
/**
 * 函数调用编码（FunctionCallEncoder）
 *
 * 依赖 FunctionObfuscator 写入 $ctx['func']（原始名 => 混淆名）。
 * - --func-call：将直接调用 `name(args)` 改写为 `call_user_func('name', args)`，
 *   形成间接调用，提升逆向难度（按值传参，对绝大多数场景行为一致）。
 * - --func-call-enc：进一步将名字串用 base64_decode 包装，
 *   `call_user_func(base64_decode('...'), args)`。
 *
 * 仅作用于顶层用户函数调用点；跳过方法调用（-> / ::）与声明点（function 之后）。
 *
 * B5 安全排除（call_user_func 语义不等价的位置，宁可少混淆也不破坏）：
 *  - 被调函数声明了引用参数（function f(&$x)）：call_user_func 按值传参，
 *    引用不会回写（实测 warning + 语义丢失）；
 *  - 调用参数列表含命名参数（name: $v）：部分 PHP 8.x 版本行为不一，保守跳过；
 *  - 一等公民可调用语法（f(...)）：改写后 `call_user_func('f', ...)` 是解析错误；
 *  - 调用参数含 `&$x`（遗留 call-time 传引用）：现代 PHP 本就致命，一并跳过。
 */

declare(strict_types=1);

final class FunctionCallEncoder
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,mixed> $ctx
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config, array $ctx): array
    {
        if (!$config->get('func_call') || empty($ctx['func'])) {
            return $tokens;
        }
        $obfNames = array_fill_keys(array_values($ctx['func']), true);
        $enc = (bool) $config->get('func_call_enc');
        // B5a：本文件中声明了引用参数的函数，其调用点不编码
        $byRefFuncs = $this->byRefFunctions($tokens);

        $n = count($tokens);
        $i = 0;
        $out = [];
        while ($i < $n) {
            $tok = $tokens[$i];
            if (is_array($tok) && $tok[0] === T_STRING && isset($obfNames[$tok[1]])) {
                $next = Parser::nextSignificant($tokens, $i);
                if ($next >= 0 && is_string($tokens[$next]) && $tokens[$next] === '(') {
                    $prev = Parser::prevSignificant($tokens, $i);
                    $isMethod = $prev >= 0 && is_array($tokens[$prev]) &&
                        in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                    $isDecl = $prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION;
                    if (!$isMethod && !$isDecl) {
                        $close = $this->findClosing($tokens, $next);
                        if ($close > $next) {
                            // B5：以下调用形态一律不编码
                            if (isset($byRefFuncs[$tok[1]]) || $this->argsUnsafe($tokens, $next, $close)) {
                                $out[] = $tok;
                                $i++;
                                continue;
                            }
                            $nameExpr = $enc
                                ? "base64_decode('" . base64_encode($tok[1]) . "')"
                                : "'" . $tok[1] . "'";
                            $inner = $this->emitRange($tokens, $next + 1, $close - 1);
                            $call = $inner === ''
                                ? "call_user_func({$nameExpr})"
                                : "call_user_func({$nameExpr}, {$inner})";
                            $out[] = $call; // 作为原始文本 token
                            $i = $close + 1;
                            continue;
                        }
                    }
                }
            }
            $out[] = $tok;
            $i++;
        }

        $this->logger->debug('函数调用混淆：调用点已改写为间接调用');
        return $out;
    }

    /**
     * B5a：收集本文件中「声明了引用参数」的函数名。
     * 判定：函数声明的参数列表内，'&'（字符串 token 或 PHP 8.1+ 的两种
     * T_AMPERSAND_* 变体）之后紧跟 T_VARIABLE / T_ELLIPSIS。
     * @param array<int,mixed> $tokens
     * @return array<string,true>
     */
    private function byRefFunctions(array $tokens): array
    {
        $set = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            $nameIdx = Parser::nextSignificant($tokens, $i);
            // function &foo() 返回引用形式：跳过 '&'（字符串或 T_AMPERSAND 变体）
            if ($nameIdx >= 0 && ((is_string($tokens[$nameIdx]) && $tokens[$nameIdx] === '&')
                || (is_array($tokens[$nameIdx]) && defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
                    && $tokens[$nameIdx][0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG))) {
                $nameIdx = Parser::nextSignificant($tokens, $nameIdx);
            }
            if ($nameIdx < 0 || !is_array($tokens[$nameIdx]) || $tokens[$nameIdx][0] !== T_STRING) {
                continue;
            }
            $open = Parser::nextSignificant($tokens, $nameIdx);
            if ($open < 0 || !is_string($tokens[$open]) || $tokens[$open] !== '(') {
                continue;
            }
            $close = $this->findClosing($tokens, $open);
            if ($close < 0) {
                continue;
            }
            for ($j = $open + 1; $j < $close; $j++) {
                $t = $tokens[$j];
                $isAmp = (is_string($t) && $t === '&')
                    || (is_array($t) && defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
                        && $t[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)
                    || (is_array($t) && defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
                        && $t[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG);
                if (!$isAmp) {
                    continue;
                }
                $nx = Parser::nextSignificant($tokens, $j);
                if ($nx >= 0 && $nx < $close && is_array($tokens[$nx])
                    && in_array($tokens[$nx][0], [T_VARIABLE, T_ELLIPSIS], true)) {
                    $set[$tokens[$nameIdx][1]] = true;
                    break;
                }
            }
        }
        return $set;
    }

    /**
     * B5：调用参数列表（$open..$close 括号内）是否含不宜编码的形态：
     *  - 命名参数（T_STRING 后紧跟 ':'，实参顶层）——三元 else 分支的类名后也跟 ':'，
     *    属保守误排除（少混淆，不破坏）；
     *  - '&' 处于实参起始位置（前一个有效 token 是 '(' 或 ','）——传引用实参；
     *    按位与（$a & $b）的 & 前是表达式，不在起始位，不算；
     *  - 参数列表恰为一个 '...'——一等公民可调用语法（f(...)），编码成
     *    call_user_func('f', ...) 是解析错误。
     * @param array<int,mixed> $tokens
     */
    private function argsUnsafe(array $tokens, int $open, int $close): bool
    {
        $depth = 0;      // 实参列表内的嵌套括号深度（0 = 实参顶层）
        $sigCount = 0;   // 有效 token 数
        $onlyEllipsis = true;
        for ($j = $open + 1; $j < $close; $j++) {
            $t = $tokens[$j];
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sigCount++;
                if ($t[0] !== T_ELLIPSIS) {
                    $onlyEllipsis = false;
                }
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                    continue;
                }
                if ($depth === 0) {
                    if ($t[0] === T_STRING) {
                        $nx = Parser::nextSignificant($tokens, $j);
                        if ($nx >= 0 && $nx < $close && is_string($tokens[$nx]) && $tokens[$nx] === ':') {
                            return true; // 命名参数
                        }
                    }
                    // 传引用实参：& 位于实参起始位（前一个是 '(' 或 ','）
                    $isAmpVar = defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
                        && $t[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
                    $isAmpAny = defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
                        && $t[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
                    if ($isAmpVar || $isAmpAny) {
                        $pv = Parser::prevSignificant($tokens, $j);
                        if ($pv >= 0 && $pv > $open && is_string($tokens[$pv])
                            && ($tokens[$pv] === '(' || $tokens[$pv] === ',')) {
                            return true;
                        }
                    }
                }
                continue;
            }
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
                $sigCount++;
                $onlyEllipsis = false;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $depth--;
                $sigCount++;
                $onlyEllipsis = false;
            } elseif ($t === '&') {
                // PHP 8.1- 词法：& 为裸字符；同样只在实参起始位才算传引用
                $pv = Parser::prevSignificant($tokens, $j);
                if ($pv >= 0 && $pv > $open && is_string($tokens[$pv])
                    && ($tokens[$pv] === '(' || $tokens[$pv] === ',')) {
                    return true;
                }
                $sigCount++;
                $onlyEllipsis = false;
            } elseif ($t === '...') {
                $sigCount++; // onlyEllipsis 保持
            } else {
                $sigCount++;
                $onlyEllipsis = false;
            }
        }
        // 一等公民可调用：参数列表恰为一个 '...'
        return $sigCount === 1 && $onlyEllipsis;
    }

    private function findClosing(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $t = is_string($tokens[$j]) ? $tokens[$j] : null;
            if ($t === '(') {
                $depth++;
            } elseif ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return -1;
    }

    private function emitRange(array $tokens, int $from, int $to): string
    {
        if ($to < $from) {
            return '';
        }
        $s = '';
        for ($j = $from; $j <= $to; $j++) {
            $s .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        return trim($s);
    }
}
