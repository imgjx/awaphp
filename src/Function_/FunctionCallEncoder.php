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
