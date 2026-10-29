<?php
/**
 * 语法游标（Cursor）：在一段 token 数组上移动，提供前瞻/收集/匹配括号等能力。
 *
 * 原内嵌于 src/Vm/Compiler.php，拆分 VM 编译器主体时独立成文件，
 * 由 Compiler.php 顶部 require_once 加载（对既有 require Compiler.php 的调用方透明）。
 */

declare(strict_types=1);

/**
 * 语法游标：在一段 token 数组上移动，提供前瞻/收集/匹配括号等能力。
 */
final class Cursor
{
    /** @var array<int,mixed> */
    private array $t;
    private int $p = 0;

    /** @param array<int,mixed> $tokens */
    public function __construct(array $tokens)
    {
        $this->t = $tokens;
    }

    public function eof(): bool
    {
        return $this->p >= count($this->t);
    }

    /** 跳过空白/注释 */
    public function skipWs(): void
    {
        while (!$this->eof()) {
            $tok = $this->t[$this->p];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $this->p++;
            } else {
                break;
            }
        }
    }

    /** 当前 token（含空白） */
    public function peek()
    {
        return $this->t[$this->p] ?? null;
    }

    /** 下一个有效（跳过空白）token */
    public function peekSig()
    {
        $i = $this->p;
        while ($i < count($this->t)) {
            $tok = $this->t[$i];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            return $tok;
        }
        return null;
    }

    /** 当前 token 类型（字符串或 token 常量） */
    public function type()
    {
        $tok = $this->peek();
        return $tok === null ? null : (is_array($tok) ? $tok[0] : $tok);
    }

    public function is($what): bool
    {
        $this->skipWs();
        $tok = $this->peek();
        if ($tok === null) {
            return false;
        }
        if (is_int($what)) {
            return is_array($tok) && $tok[0] === $what;
        }
        return is_string($tok) && $tok === $what;
    }

    /** 第二个 token（含空白） */
    public function peek2()
    {
        return $this->t[$this->p + 1] ?? null;
    }

    /**
     * 返回当前位置之后第 $offset 个【有效】（跳过空白/注释）token，不消费光标。
     * 用于 list 解构里前瞻判断「键 => 目标」形态。
     * @return mixed
     */
    public function peekSigAt(int $offset)
    {
        $i = $this->p;
        $n = 0;
        while ($i < count($this->t)) {
            $tok = $this->t[$i];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            if ($n === $offset) {
                return $tok;
            }
            $n++;
            $i++;
        }
        return null;
    }

    /** 当前光标位置（供前瞻失败时回退） */
    public function pos(): int
    {
        return $this->p;
    }

    /** 恢复光标位置 */
    public function seek(int $p): void
    {
        $this->p = max(0, min($p, count($this->t)));
    }

    public function next()
    {
        return $this->t[$this->p++] ?? null;
    }

    /**
     * 期望某个 token（int 类型或字符），否则抛异常。
     * @return mixed
     */
    public function expect($what)
    {
        $this->skipWs();
        $tok = $this->peek();
        if ($tok === null) {
            throw new VmUnsupported('VM 编译期望 ' . (is_int($what) ? token_name($what) : $what) . ' 但已到末尾');
        }
        if (is_int($what)) {
            if (!(is_array($tok) && $tok[0] === $what)) {
                throw new VmUnsupported('VM 编译期望 ' . token_name($what) . '，得到 ' . (is_array($tok) ? token_name($tok[0]) : $tok));
            }
        } else {
            if (!is_string($tok) || $tok !== $what) {
                throw new VmUnsupported('VM 编译期望 ' . $what);
            }
        }
        return $this->next();
    }

    /**
     * 同 expect()，但返回 token 文本（数组型返回 $tok[1]，符号型返回原字符）。
     * 便于调用方直接拿到标识符名/操作符字符。
     * @return string
     */
    public function expectText($what)
    {
        $this->skipWs();
        $tok = $this->peek();
        if ($tok === null) {
            throw new VmUnsupported('VM 编译期望 ' . (is_int($what) ? token_name($what) : $what) . ' 但已到末尾');
        }
        if (is_int($what)) {
            if (!(is_array($tok) && $tok[0] === $what)) {
                throw new VmUnsupported('VM 编译期望 ' . token_name($what) . '，得到 ' . (is_array($tok) ? token_name($tok[0]) : $tok));
            }
        } else {
            if (!is_string($tok) || $tok !== $what) {
                throw new VmUnsupported('VM 编译期望 ' . $what);
            }
        }
        $this->next();
        return is_array($tok) ? $tok[1] : $tok;
    }

    /**
     * 收集直到【顶层】遇到分隔符 $stop（不被括号内的分隔符打断）。
     * 返回含分隔符之前的所有 token（不含分隔符）。
     * @return array<int,mixed>
     */
    /**
     * 收集直到【顶层】遇到分隔符 $stop（不被括号内的分隔符打断）。
     * 返回含分隔符之前的所有 token（不含分隔符）。
     * @param string|int $stop  分隔符（字符串符号或 token 常量，如 ')' / ';' / T_AS）
     * @param string|int|null $stop2 第二个可选分隔符
     * @param int $initPdepth 调用方已消费的圆括号层数（用于正确匹配"包裹"的右括号）
     * @return array<int,mixed>
     */
    public function collectUntilTop($stop, $stop2 = null, int $initPdepth = 0): array
    {
        $out = [];
        $depth = 0;          // 花括号深度
        $pdepth = $initPdepth; // 圆括号深度（调用方已消费的 '(' 计入初始深度）
        $bdepth = 0;         // 方括号深度
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($tok === '(') { $pdepth++; }
                elseif ($tok === ')') { $pdepth = max(0, $pdepth - 1); }
                if ($tok === '[') { $bdepth++; }
                elseif ($tok === ']') { $bdepth = max(0, $bdepth - 1); }
                if ($tok === '{') { $depth++; }
                elseif ($tok === '}') { $depth = max(0, $depth - 1); }
            } elseif (is_array($tok)) {
                if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
            }
            $isStop = false;
            if ($depth === 0 && $pdepth === 0 && $bdepth === 0) {
                if (is_int($stop) && is_array($tok) && $tok[0] === $stop) { $isStop = true; }
                elseif (is_string($stop) && is_string($tok) && $tok === $stop) { $isStop = true; }
                if (!$isStop && $stop2 !== null) {
                    if (is_int($stop2) && is_array($tok) && $tok[0] === $stop2) { $isStop = true; }
                    elseif (is_string($stop2) && is_string($tok) && $tok === $stop2) { $isStop = true; }
                }
            }
            if ($isStop) {
                break;
            }
            $out[] = $this->next();
        }
        return $out;
    }

    /**
     * 收集一条 match 分支的结果表达式 token，直到【真正顶层】的 ','（下一分支）
     * 或 '}'（match 体结束）。关键在于：结果表达式内部可能再嵌套 match / 函数调用 / 数组，
     * 其中的 '{'..'}'、'('..')'、'['..']' 都需要成对平衡，其内部的 ',' 与 '}' 都不能当作停止符；
     * 只有在大括号/圆括号/方括号全部归零时的 ',' 或 '}' 才停止，且停止符本身不纳入返回。
     * @return array<int,mixed>
     */
    public function collectMatchArmResult(): array
    {
        $out = [];
        $bd = 0; // 大括号深度
        $pd = 0; // 圆括号深度
        $ad = 0; // 方括号深度
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($tok === '{') { $bd++; }
                elseif ($tok === '}') {
                    if ($bd === 0 && $pd === 0 && $ad === 0) { break; }
                    if ($bd > 0) { $bd--; }
                } elseif ($tok === '(') { $pd++; }
                elseif ($tok === ')') { if ($pd > 0) { $pd--; } }
                elseif ($tok === '[') { $ad++; }
                elseif ($tok === ']') { if ($ad > 0) { $ad--; } }
                elseif ($tok === ',') {
                    if ($bd === 0 && $pd === 0 && $ad === 0) { break; }
                }
            } elseif (is_array($tok)) {
                if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $bd++; }
            }
            $out[] = $this->next();
        }
        return $out;
    }

    /**
     * 收集直到【顶层】终止符（用于 return/echo/throw/属性默认值等"取剩余表达式"）。
     *
     * 深度感知是必须的：闭包/箭头函数的函数体里也带 `;`（`return array_map(function($x){
     * return $x; }, $a);`），naive 版本会在闭包体内的第一个 `;` 处截断，导致外层调用的
     * 右括号丢失、编译器报"期望 ) 但已到末尾"。括号/方括号/花括号内的 `;` 从来不是
     * 语句终止符，故一律跳过；只有全部归零时的 term 才停（不消费）。
     *
     * @return array<int,mixed>
     */
    public function untilTerminator(string $term): array
    {
        $out = [];
        $pd = 0; // 圆括号
        $bd = 0; // 方括号
        $d  = 0; // 花括号（含字符串插值的 T_CURLY_OPEN）
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                // 块结束：所在语句（如 if 体内的 return）到 '}' 为止，不消费
                if ($d === 0 && $pd === 0 && $bd === 0 && $tok === '}') {
                    break;
                }
                if ($pd === 0 && $bd === 0 && $d === 0 && $tok === $term) {
                    break;
                }
                if ($tok === '(') {
                    $pd++;
                } elseif ($tok === ')') {
                    $pd = max(0, $pd - 1);
                } elseif ($tok === '[') {
                    $bd++;
                } elseif ($tok === ']') {
                    $bd = max(0, $bd - 1);
                } elseif ($tok === '{') {
                    $d++;
                } elseif ($tok === '}') {
                    $d = max(0, $d - 1);
                }
            } elseif (is_array($tok) && ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $d++;
            }
            $out[] = $this->next();
        }
        return $out;
    }

    /**
     * 收集 yield / yield from 的操作数 token：直到【顶层】的 ';' ',' ')' ']' '}'
     * 或（$allowArrow 时）'=> '。返回 [tokens, 是否命中顶层 '=>']。
     * 停止符本身不消费，由调用方决定（命中 '=>' 时调用方需自行 next()）。
     *
     * @return array{0:array<int,mixed>,1:bool}
     */
    public function collectYieldOperand(bool $allowArrow): array
    {
        $out = [];
        $pd = 0;
        $bd = 0;
        $d  = 0;
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($pd === 0 && $bd === 0 && $d === 0
                    && ($tok === ';' || $tok === ',' || $tok === ')' || $tok === ']' || $tok === '}')) {
                    break;
                }
                if ($tok === '(') {
                    $pd++;
                } elseif ($tok === ')') {
                    $pd = max(0, $pd - 1);
                } elseif ($tok === '[') {
                    $bd++;
                } elseif ($tok === ']') {
                    $bd = max(0, $bd - 1);
                } elseif ($tok === '{') {
                    $d++;
                } elseif ($tok === '}') {
                    $d = max(0, $d - 1);
                }
            } elseif (is_array($tok)) {
                if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $d++;
                } elseif ($allowArrow && $pd === 0 && $bd === 0 && $d === 0 && $tok[0] === T_DOUBLE_ARROW) {
                    break;
                }
            }
            $out[] = $this->next();
        }
        return [$out, $this->is(T_DOUBLE_ARROW)];
    }

    public function untilEnd(): array
    {
        $out = [];
        while (!$this->eof()) {
            $out[] = $this->next();
        }
        return $out;
    }

    /** 收集直到末尾（与 collectUntilTop 命名风格一致） */
    public function collectUntilEnd(): array
    {
        return $this->untilEnd();
    }

    /**
     * 从当前位置（应位于 '{' 之后）匹配到右大括号，返回【内部】token。
     *
     * 插值修正："{$expr}" 的起始是数组 token T_CURLY_OPEN（文本 "{"），
     * 闭合却是裸字符 '}'——若只数字符 '{'/'}'，插值串的闭合括号会被
     * 误认为块结束，把函数/类/闭包体在第一个插值处拦腰截断
     * （曾致 "a{$x}b" 之后的语句整体丢失）。故 T_CURLY_OPEN /
     * T_DOLLAR_OPEN_CURLY_BRACES 也必须计入深度。
     * @return array<int,mixed>
     */
    public function untilMatchBrace(): array
    {
        // 当前应为 '{'
        if ($this->is('{')) {
            $this->next();
        }
        $out = [];
        $depth = 1;
        while (!$this->eof()) {
            $tok = $this->next();
            if ($tok === '{') {
                $depth++;
            } elseif (is_array($tok) && ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($tok === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $out[] = $tok;
        }
        return $out;
    }

    /**
     * 从当前位置收集直到顶层 ',' 或 ')' 的 token（用于参数默认值表达式）。
     * 括号 / 方括号 / 花括号内部的逗号不终止；不消耗终止符本身。
     * 插值修正同 untilMatchBrace：T_CURLY_OPEN 的闭合是裸 '}'，
     * 必须计入深度，否则默认值里的插值串会提前终止参数收集。
     * @return array<int,mixed>
     */
    public function untilParamEnd(): array
    {
        $out = [];
        $depth = 0;
        while (!$this->eof()) {
            $tok = $this->peek();
            if (is_string($tok)) {
                if ($tok === '(' || $tok === '[' || $tok === '{') {
                    $depth++;
                } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                    if ($depth === 0 && $tok === ')') {
                        break; // 所属参数列表的右括号
                    }
                    if ($depth > 0) {
                        $depth--;
                    }
                } elseif ($tok === ',' && $depth === 0) {
                    break; // 下一个参数
                }
            } elseif (is_array($tok) && ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            }
            $out[] = $this->next();
        }
        return $out;
    }

    public function cloneThis(): Cursor
    {
        $c = new Cursor($this->t);
        $c->p = $this->p;
        return $c;
    }
}
