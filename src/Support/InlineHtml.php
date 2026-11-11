<?php
/**
 * 高级 PHP/HTML 混写规范化（InlineHtml）
 *
 * 背景：PHP 模板页里 PHP 块与 HTML 块交错（T_INLINE_HTML / T_CLOSE_TAG /
 * T_OPEN_TAG_WITH_ECHO）。这类源码进了混淆管线会踩三个坑：
 *   1) VM / EVAL / GZ 壳把整段载荷塞进 eval()，HTML 段被当 PHP 解析 →
 *      `unexpected token "<"` 直接 fatal；
 *   2) shell:module_link 把内联模块函数定义追加到文件末尾，若末尾是 `?>` 之后的
 *      HTML，定义变成输出文本且语法损坏；
 *   3) 文件末尾 HTML 的换行被 runLayered 的 rtrim 吃掉，输出逐字节不一致。
 *
 * 做法：在管线入口把混写源码【等价改写为纯 PHP】——每个 HTML 段变成
 * `echo '<字面量>';`，`<?=` 变成 `echo <expr> ... ;`，`?>` 变成隐式语句结束
 * 符 `;`。此后所有 token 层、壳层、模块链接看到的都是普通 PHP，无需各自适配。
 *
 * 保真要点（逐条对齐 PHP 词法/输出语义）：
 *   - T_OPEN_TAG 文本（含其吞掉的尾随空白）不进入输出流，直接丢弃；
 *   - T_CLOSE_TAG 文本形如 `?>\n`，其后紧跟的一个换行被 PHP 吞掉、不输出，
 *     故只补一个 `;`（空语句，对 if/endif、函数体等上下文均合法）；
 *   - T_INLINE_HTML 文本用 var_export 生成单引号字面量，原样 echo；
 *   - `<?= expr ?>` → `echo expr;`（T_OPEN_TAG_WITH_ECHO 补 `echo `，
 *     T_CLOSE_TAG 补 `;`）；
 *   - 替代语法跨标签（if/endif、foreach/endforeach）在纯 PHP 下结构完整保留，
 *     HTML 段只是分支里的 echo，语义不变；
 *   - `__halt_compiler()` 之后是数据区，原样保留、绝不转换；
 *   - CLI shebang 行保留在 `<?php` 之前，不当作输出；
 *   - declare(strict_types=1) / namespace 必须为首语句，规范化后统一交给
 *     DeclareHoist::reconcile() 提升，避免 "strict_types declaration must be
 *     the very first statement"。
 *
 * 纯 PHP 源码（无 T_INLINE_HTML / T_CLOSE_TAG / T_OPEN_TAG_WITH_ECHO）原样返回，
 * 零改动、零风险。
 */

declare(strict_types=1);

require_once __DIR__ . '/DeclareHoist.php';

final class InlineHtml
{
    /** 源码是否含混写结构（供外部快速判断，normalize 内部自带同样判定） */
    public static function needs(string $code): bool
    {
        $toks = @token_get_all($code);
        if (!is_array($toks)) {
            return false;
        }
        foreach ($toks as $t) {
            if (is_array($t) && in_array($t[0], [T_INLINE_HTML, T_CLOSE_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                return true;
            }
        }
        return false;
    }

    /** 把混写源码规范化为等价纯 PHP；非混写源码原样返回。 */
    public static function normalize(string $code): string
    {
        $toks = @token_get_all($code);
        if (!is_array($toks) || $toks === []) {
            return $code;
        }
        $need = false;
        foreach ($toks as $t) {
            if (is_array($t) && in_array($t[0], [T_INLINE_HTML, T_CLOSE_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                $need = true;
                break;
            }
        }
        if (!$need) {
            // 纯 PHP 也做替代语法归一：混写之外的模板文件同样可能用 if/endif 写法
            return self::normalizeAltSyntax($code);
        }

        // CLI shebang：PHP CLI 会跳过 <?php 之前第一行的 `#!`，不能 echo 出去。
        $lead = '';
        $start = 0;
        $first = $toks[0];
        if (is_array($first) && $first[0] === T_INLINE_HTML && str_starts_with($first[1], '#!')) {
            $p = strpos($first[1], "\n");
            if ($p === false) {
                $lead  = $first[1];
                $start = 1;
            } else {
                $lead  = substr($first[1], 0, $p + 1);
                $rest  = substr($first[1], $p + 1);
                if ($rest !== '') {
                    $toks[0] = [T_INLINE_HTML, $rest, $first[2] ?? 1];
                } else {
                    $start = 1;
                }
            }
        }

        $out = '';
        $halt = false;
        $n = count($toks);
        for ($i = $start; $i < $n; $i++) {
            $t = $toks[$i];
            if ($halt) {
                $out .= self::text($t);
                continue;
            }
            if (is_array($t)) {
                switch ($t[0]) {
                    case T_HALT_COMPILER:
                        // 其后的全部 token 属数据区（含 `();` 与 T_INLINE_HTML），原样拼回
                        $halt = true;
                        $out .= $t[1];
                        continue 2;
                    case T_INLINE_HTML:
                        if ($t[1] === '') {
                            continue 2;
                        }
                        $out .= 'echo ' . self::literal($t[1]) . ';';
                        continue 2;
                    case T_OPEN_TAG:
                        // 开标签及其吞掉的尾随空白不产生输出
                        continue 2;
                    case T_OPEN_TAG_WITH_ECHO:
                        $out .= 'echo ';
                        continue 2;
                    case T_CLOSE_TAG:
                        // 闭合标签隐含语句结束；其文本里被 PHP 吞掉的换行不输出
                        $out .= ';';
                        continue 2;
                }
            }
            $out .= self::text($t);
        }

        $final = $lead . '<?php ' . $out;
        // declare(strict_types=1) / namespace 必须是首语句：把被 HTML echo 推后的
        // 序言统一提到最前（无 declare/namespace 时 reconcile 原样返回）。
        return self::normalizeAltSyntax(DeclareHoist::reconcile($final));
    }

    /**
     * 把 PHP 替代语法（if/endif、foreach/endforeach、for/endfor、while/endwhile、
     * switch/endswitch）等价改写为花括号语法。
     *
     * 混写模板页大量使用替代语法跨 PHP 标签，但控制流平坦化 / GOTO / 状态机 / 壳层
     * 自保护都是按 `{}` 语句块解析的：遇到 `if(...): ... endif;` 会把 `:` 当成单条语句
     * 起点、在第一个 `;` 处截断，产物变成 `unexpected token ":"` / `unexpected token
     * "endif"` 的 fatal。统一改写后所有下游模块看到的都是标准语法，语义完全等价。
     *
     * 只改「条件右括号 / else 之后的那个 `:`」与配对的 end* 关键字；
     * 三元 `?:`、case 标签 `case 1:`、返回类型 `: int`、命名参数 `foo:`、
     * match 分支、enum 等位置的 `:` 一概不动。纯花括号源码原样返回（幂等）。
     */
    public static function normalizeAltSyntax(string $code): string
    {
        $toks = @token_get_all($code);
        if (!is_array($toks) || $toks === []) {
            return $code;
        }
        $begin = [
            T_IF => T_ENDIF, T_FOR => T_ENDFOR, T_FOREACH => T_ENDFOREACH,
            T_WHILE => T_ENDWHILE, T_SWITCH => T_ENDSWITCH,
        ];
        $ends = [T_ENDIF => true, T_ENDFOR => true, T_ENDFOREACH => true,
                 T_ENDWHILE => true, T_ENDSWITCH => true];

        $has = false;
        foreach ($toks as $t) {
            if (is_array($t) && isset($ends[$t[0]])) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            return $code;
        }

        $n = count($toks);
        $colon = [];     // token 下标 => true：该 ':' 改写为 '{'
        $endMap = [];    // token 下标 => true：该 end* 关键字改写为 '}'
        $preClose = [];  // token 下标 => true：该 else/elseif 前补一个 '}'
        $stack = [];     // 期望的 end* token 类型栈

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t)) {
                continue;
            }
            $id = $t[0];
            if (isset($begin[$id])) {
                $j = self::condColonIndex($toks, $i, $n);
                if ($j !== null) {
                    $colon[$j] = true;
                    $stack[] = $begin[$id];
                }
                continue;
            }
            if ($id === T_ELSEIF) {
                $j = self::condColonIndex($toks, $i, $n);
                if ($j !== null && $stack !== [] && end($stack) === T_ENDIF) {
                    $colon[$j] = true;
                    $preClose[$i] = true;   // 闭合 then 分支
                }
                continue;
            }
            if ($id === T_ELSE) {
                $j = self::nextSig($toks, $i + 1, $n);
                if ($j !== null && $toks[$j] === ':' && $stack !== [] && end($stack) === T_ENDIF) {
                    $colon[$j] = true;
                    $preClose[$i] = true;
                }
                continue;
            }
            if (isset($ends[$id])) {
                if ($stack !== [] && end($stack) === $id) {
                    array_pop($stack);
                    $endMap[$i] = true;
                }
            }
        }

        if ($colon === [] && $endMap === []) {
            return $code;
        }

        $out = '';
        for ($i = 0; $i < $n; $i++) {
            if (isset($preClose[$i])) {
                $out .= '}';
            }
            if (isset($endMap[$i])) {
                $out .= '}';       // end* 后的 ';' 保留为空语句，合法
                continue;
            }
            if (isset($colon[$i])) {
                $out .= '{';
                continue;
            }
            $t = $toks[$i];
            $out .= is_array($t) ? (string) $t[1] : (string) $t;
        }
        return $out;
    }

    /** 关键字后「条件右括号之后的第一个有效 token」是 ':' 时返回其下标，否则 null。 */
    private static function condColonIndex(array $toks, int $kw, int $n): ?int
    {
        $open = self::nextSig($toks, $kw + 1, $n);
        if ($open === null || $toks[$open] !== '(') {
            return null;
        }
        $depth = 0;
        for ($i = $open; $i < $n; $i++) {
            $t = $toks[$i];
            if ($t === '(') {
                $depth++;
            } elseif ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    $j = self::nextSig($toks, $i + 1, $n);
                    return ($j !== null && $toks[$j] === ':') ? $j : null;
                }
            }
        }
        return null;
    }

    /** 下一个非空白非注释 token 下标；单字符 token 原样比较用。 */
    private static function nextSig(array $toks, int $from, int $n): ?int
    {
        for ($i = $from; $i < $n; $i++) {
            $t = $toks[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** @param array<int,string>|string $t */
    private static function text($t): string
    {
        return is_array($t) ? (string) $t[1] : (string) $t;
    }

    /** 生成单引号字面量；var_export 负责转义 `'` 与 `\`。 */
    private static function literal(string $s): string
    {
        return var_export($s, true);
    }
}
