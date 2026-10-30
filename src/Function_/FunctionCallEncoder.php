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
        // 被调函数的**声明命名空间**（混淆名 => 命名空间，全局为 ''）。
        //
        // 为什么必须有：`call_user_func('name', …)` 里的字符串是**绝对**函数名，PHP 不会
        // 按当前命名空间回退解析。而 `name(…)` 这种直接调用会。于是命名空间文件里
        //   namespace app\demo; function make_gen(){…}  …  make_gen(2)
        // 被改成 `call_user_func('_o36…', 2)` 后，会在全局找 `_o36…` 而失败：
        //   TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback,
        //              function "_o36…" not found
        // 字符串必须写成 `app\demo\_o36…`（或全局函数的前缀 `\`）。
        $declNs = $this->declFuncNamespaces($tokens);

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
                            $fqn = $this->qualifiedFuncName($tok[1], $declNs);
                            $nameExpr = $enc
                                ? "base64_decode('" . base64_encode($fqn) . "')"
                                : "'" . $fqn . "'";
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
     * 收集「命名空间级函数声明」所在的命名空间：函数名 => 命名空间（全局命名空间为 ''）。
     *
     * 判定要点：
     *  - 进入 T_NAMESPACE 后记录当前命名空间（支持 `namespace A\B;` 与 `namespace A\B { }`）；
     *  - 只有花括号深度 <=1 的 T_FUNCTION 才算命名空间级函数：分号式 namespace 的顶层
     *    深度为 0，花括号式 namespace 的顶层为 1，类方法的深度必然 >=2，借此天然排除方法。
     *
     * @param array<int,mixed> $tokens
     * @return array<string,string>
     */
    private function declFuncNamespaces(array $tokens): array
    {
        $map = [];
        $ns = '';
        $depth = 0;
        $n = count($tokens);
        $nameToks = [T_STRING];
        if (defined('T_NAME_QUALIFIED')) {
            $nameToks[] = T_NAME_QUALIFIED;
        }
        if (defined('T_NAME_FULLY_QUALIFIED')) {
            $nameToks[] = T_NAME_FULLY_QUALIFIED;
        }
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_string($tok)) {
                if ($tok === '{') {
                    $depth++;
                } elseif ($tok === '}') {
                    $depth--;
                }
                continue;
            }
            if (!is_array($tok)) {
                continue;
            }
            if ($tok[0] === T_NAMESPACE) {
                $name = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $tokens[$j];
                    if (is_string($t) && ($t === ';' || $t === '{')) {
                        break;
                    }
                    if (is_array($t) && (in_array($t[0], $nameToks, true) || $t[0] === T_NS_SEPARATOR)) {
                        $name .= $t[1];
                    }
                }
                $ns = trim($name, '\\');
                continue;
            }
            if ($tok[0] === T_FUNCTION && $depth <= 1) {
                $next = Parser::nextSignificant($tokens, $i);
                if ($next >= 0 && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $map[$tokens[$next][1]] = $ns;
                }
            }
        }
        return $map;
    }

    /**
     * 把函数短名补成「可直接当字符串回调」的绝对名：`name` → `A\B\name`。
     * @param array<string,string> $declNs
     */
    private function qualifiedFuncName(string $name, array $declNs): string
    {
        $ns = $declNs[$name] ?? '';
        return $ns === '' ? $name : $ns . '\\' . $name;
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
