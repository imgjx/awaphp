<?php
/**
 * 超级激进逻辑变换（LogicRewriter / 逻辑层）
 *
 * 在 token 层把源码的「数字字面量 / 一元 ! / 二元比较 / 二元布尔」改写成类型安全、逐值恒等
 * 但外观完全不同的等价表达式，使逆向者无法按原结构阅读（源码里看不到任何裸数字、
 * 所有 < / > / <= / >= 都被拆成 <=> / === / !==、 && / || / xor 被拆成 (… != false)+(…) 求和）。
 * 这是「反 AST」能力的超集：相比 AntiAst（仅语句位常量字面量），本层覆盖**任意位置**
 * 的表达式，并且（因置于 var 层之前运行）引入的临时变量会被变量名混淆层一并重命名，
 * 混乱度最大化。
 *
 * 安全性（绝不引入语义偏差）：
 *   - 数字：runtime 上下文用 IdentityExpr::intExpr（strlen(md5()) 积木，经 eval 校验）；
 *     常量上下文（const / case / 参数默认值 / 属性默认值）用 intExprConstSafe（纯位运算 ^，
 *     函数调用非法处也保真）；declare() 内完全跳过（strict_types 等必须字面量）。
 *   - 表达式重组全部依据数学恒等律（见各 try* 方法的恒等式注释），且只对「不含 ++/--/赋值/
 *     `->`/`::`/嵌套逻辑运算符的危险操作数」施加；构造后整条经样本值代入 eval 校验，
 *     不通过则整条原样保留。
 *   - 比较只改写 < > <= >=（用 <=> 恒等式，对对象与原算子一样抛异常，故不会引入新异常）；
 *     == / != / === / !== 一律不改写（<=> 对对象抛异常，会破坏 == 的语义）。
 *   - 操作数抽取基于括号深度平衡 + 优先级边界（precOf），正确处理嵌套与带括号操作数。
 *
 * 强度分级（logic_intensity）：
 *   1 = 数字恒等展开 + 一元 ! 重组
 *   2 = 上述 + 比较（< > <= >=）/ 布尔（&& || and or xor）重组
 *   3 = 上述 + 临时变量缠结（创建/复用临时变量做串计算，把简单赋值与 echo 展开成
 *       互相牵连的变量网）——刻意不做控制流结构重建，以保证与状态机 / VM 壳链正交
 *
 * 注：所有「常量表达式上下文」（const / enum case / 参数默认值 / 属性默认值 /
 * static 初始化 / 属性参数 #[...] / switch case 标签）一律使用纯位运算形态，
 * 绝不生成函数调用（函数调用在常量表达式中非法）；declare() 内完全跳过。
 */

declare(strict_types=1);

final class LogicRewriter
{
    private Salt $salt;
    private Logger $logger;
    private IdentityExpr $id;
    private int $intensity;
    private array $usedVars = [];
    private int $constSeed = 0;

    public function __construct(Salt $salt, Logger $logger, int $intensity = 2)
    {
        $this->salt = $salt;
        $this->logger = $logger;
        $this->id = new IdentityExpr();
        $this->intensity = max(1, min(3, $intensity));
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        $this->intensity = max(1, min(3, (int) $cfg->get('logic_intensity', 2)));
        if ($this->intensity < 1) {
            return $tokens;
        }
        $this->constSeed = $this->salt->isEmpty()
            ? 0
            : (int) hexdec(substr(bin2hex($this->salt->derive('logic-const', 4)), 0, 7));
        $this->collectVars($tokens);
        // 先缠结（此时右值仍是原始字面量，可识别整数走位运算恒等链），
        // 再做表达式级递归变换（把剩余数字一并展开）。
        if ($this->intensity >= 3) {
            $tokens = $this->tanglePass($tokens);
        }
        return $this->transformRange($tokens, true);
    }

    /**
     * 表达式级递归变换：对区间内的 token 单遍收集变换点（数字 / ! / 比较 / 布尔 / 顶层赋值），
     * 按起始位置重建（重叠时取覆盖更大者）。
     *
     * 关键：改写布尔/比较时，先对**操作数子区间递归**调用本方法得到变换后的 token，
     * 再以变换后的源码嵌入目标表达式——从而正确处理任意嵌套（外层布尔不会把内层比较
     * 当原始源串吸收而破坏优先级）。
     *
     * $transformNumbers=true 仅在「顶层整段」开启：数字只在能正确判断上下文（const/运行时）
     * 的位置改写；递归子调用传 false，避免常量上下文内误用函数形态（且内层数字保持字面量亦合法）。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function transformRange(array $tokens, bool $transformNumbers): array
    {
        $n = count($tokens);
        $ctx = new LogicCtx();
        /** @var array<int,array{end:int,start:int,repl:array<int,mixed>}> $matches */
        $matches = [];
        for ($i = 0; $i < $n; $i++) {
            $ctx->step($tokens, $i);
            $tok = $tokens[$i];

            // —— 数字字面量恒等展开（仅顶层）——
            if ($transformNumbers && $this->isIntLiteral($tok)) {
                if (!$ctx->inDeclare()) {
                    $expr = $ctx->isConstContext()
                        ? $this->id->intExprConstSafe($tok[1], $this->constSeed)
                        : $this->id->intExpr((int) $tok[1]);
                    if ($expr !== null && $this->id->verify((int) $tok[1], $expr)) {
                        $matches[$i] = ['start' => $i, 'end' => $i, 'repl' => $this->exprTokens($expr)];
                    }
                }
                continue;
            }

            // —— 一元 ! 重组 ——
            if ($this->intensity >= 1 && $this->isChar($tok, '!') && !$this->isBangOf($tokens, $i)) {
                $r = $this->tryUnaryNot($tokens, $i);
                if ($r !== null) {
                    $matches[$r['start']] = ['start' => $r['start'], 'end' => $r['end'], 'repl' => $r['repl']];
                    continue;
                }
            }

            // —— 比较重组（intensity >= 2，仅 < > <= >=）——
            $kind = $this->compareKind($tok);
            if ($this->intensity >= 2 && $kind !== null) {
                $r = $this->tryCompare($tokens, $i, $kind);
                if ($r !== null) {
                    $matches[$r['start']] = ['start' => $r['start'], 'end' => $r['end'], 'repl' => $r['repl']];
                    continue;
                }
            }

            // —— 布尔重组（intensity >= 2）——
            if ($this->intensity >= 2 && $this->isBoolOp($tok)) {
                $r = $this->tryBool($tokens, $i, $tok[0]);
                if ($r !== null) {
                    $matches[$r['start']] = ['start' => $r['start'], 'end' => $r['end'], 'repl' => $r['repl']];
                    continue;
                }
            }

        }
        return $this->rebuild($tokens, $matches);
    }

    // ====================================================================
    // 一元 ! 重组（对任意类型恒真的真值取反）：
    //   !X  ≡  (X == false)
    //   注：不能用 (X<=>false)!==0 —— 数组/对象经 <=> 会得到非真值结果甚至抛异常；
    //       (X == false) 对所有类型（含空数组、对象）都正确给出「X 是否为 falsy」。
    // ====================================================================
    /**
     * @param array<int,mixed> $tokens
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function tryUnaryNot(array $tokens, int $i): ?array
    {
        $right = $this->rightOperand($tokens, $i, 70);
        if ($right === null) {
            return null;
        }
        [$rs, $re] = $right;
        if (!$this->safeOperands($tokens, $rs, $re)) {
            return null;
        }
        $x = $this->tokensToSource($this->transformRange(array_slice($tokens, $rs, $re - $rs + 1), false));
        $target = '((' . $x . ') == false)';
        $repl = $this->exprTokens($target);
        if (!$this->verifyExpr("!$x", $target, [$x])) {
            return null;
        }
        return ['start' => $i, 'end' => $re, 'repl' => $repl];
    }

    // ====================================================================
    // 比较重组（类型安全恒等式，仅 < > <= >=；==/!=/===/!== 不改写）：
    //   A <  B ≡ (A<=>B) === -1      A >  B ≡ (A<=>B) === 1
    //   A <= B ≡ (A<=>B) !==  1      A >= B ≡ (A<=>B) !== -1
    //   （<=> 对对象、与原 < 等算子行为一致——都抛异常，故不会引入新异常。）
    // ====================================================================
    /**
     * @param array<int,mixed> $tokens
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function tryCompare(array $tokens, int $i, string $kind): ?array
    {
        $left = $this->leftOperand($tokens, $i, 30);
        $right = $this->rightOperand($tokens, $i, 30);
        if ($left === null || $right === null) {
            return null;
        }
        [$lS, $lE] = $left;
        [$rS, $rE] = $right;
        if (!$this->safeOperands($tokens, $lS, $lE) || !$this->safeOperands($tokens, $rS, $rE)) {
            return null;
        }
        // 左操作数若仍被某个前缀（is_int( / isset( / array( / -> / :: 等）挂住，
        // 说明抽取把调用名留在了区间外，重组会生成 count(true) 之类的错误语义。
        if ($this->leftOperandDetached($tokens, $lS)) {
            return null;
        }
        $a = $this->tokensToSource($this->transformRange(array_slice($tokens, $lS, $lE - $lS + 1), false));
        $b = $this->tokensToSource($this->transformRange(array_slice($tokens, $rS, $rE - $rS + 1), false));
        $glyph = ['lt' => '<', 'gt' => '>', 'lte' => '<=', 'gte' => '>='][$kind];
        switch ($kind) {
            case 'lt':  $target = '(((' . $a . ') <=> (' . $b . ')) === -1)'; break;
            case 'gt':  $target = '(((' . $a . ') <=> (' . $b . ')) === 1)'; break;
            case 'lte': $target = '(((' . $a . ') <=> (' . $b . ')) !== 1)'; break;
            case 'gte': $target = '(((' . $a . ') <=> (' . $b . ')) !== -1)'; break;
            default:    return null;
        }
        if (!$this->verifyExpr("($a) " . $glyph . " ($b)", $target, [$a, $b])) {
            return null;
        }
        return ['start' => $lS, 'end' => $rE, 'repl' => $this->exprTokens($target)];
    }

    // ====================================================================
    // 布尔重组（类型安全、对任意类型恒真的真值恒等式）：
    //   真值位 truthy(X) ≡ (X != false)
    //   A && B ≡ ((A != false) + (B != false)) === 2
    //   A || B ≡ ((A != false) + (B != false)) !== 0
    //   A xor B ≡ ((A != false) + (B != false)) === 1
    //   （X != false 对任何类型都成立且绝不抛异常，包括数组/对象。）
    // ====================================================================
    /**
     * @param array<int,mixed> $tokens
     * @return array{start:int,end:int,repl:array<int,mixed>}|null
     */
    private function tryBool(array $tokens, int $i, int $kind): ?array
    {
        $left = $this->leftOperand($tokens, $i, 5);
        $right = $this->rightOperand($tokens, $i, 5);
        if ($left === null || $right === null) {
            return null;
        }
        [$lS, $lE] = $left;
        [$rS, $rE] = $right;
        if (!$this->safeOperands($tokens, $lS, $lE) || !$this->safeOperands($tokens, $rS, $rE)) {
            return null;
        }
        // 左操作数若仍被某个前缀（is_int( / isset( / array( / -> / :: 等）挂住，
        // 说明抽取把调用名留在了区间外，重组会生成 count(true) 之类的错误语义。
        if ($this->leftOperandDetached($tokens, $lS)) {
            return null;
        }
        $a = $this->tokensToSource($this->transformRange(array_slice($tokens, $lS, $lE - $lS + 1), false));
        $b = $this->tokensToSource($this->transformRange(array_slice($tokens, $rS, $rE - $rS + 1), false));
        if ($kind === T_BOOLEAN_OR || $kind === T_LOGICAL_OR) {
            $target = '((((' . $a . ') != false) + ((' . $b . ') != false)) !== 0)';
            $src = "($a) || ($b)";
        } elseif ($kind === T_LOGICAL_XOR) {
            $target = '((((' . $a . ') != false) + ((' . $b . ') != false)) === 1)';
            $src = "($a) xor ($b)";
        } else { // T_BOOLEAN_AND / T_LOGICAL_AND
            $target = '((((' . $a . ') != false) + ((' . $b . ') != false)) === 2)';
            $src = "($a) && ($b)";
        }
        if (!$this->verifyExpr($src, $target, [$a, $b])) {
            return null;
        }
        return ['start' => $lS, 'end' => $rE, 'repl' => $this->exprTokens($target)];
    }

    // ====================================================================
    // 临时变量缠结 pass（intensity >= 3）
    //
    // 把「语句列表」中的简单赋值 / echo 语句展开成一串语句：先创建两个由 salt
    // 派生的临时变量，做一串彼此复用的恒等运算，最后仍得到原值再赋值 / 输出。
    // 变量随后被 var 层一并重命名，逆向者看到的是一团互相牵连、看不出原意的
    // 变量网 —— 即「输出 10086，先造两个变量，串算一通，变量还能复用」。
    //
    // 绝对安全约束：
    //   * 原右值只求值一次（先存进 $vk），后续计算只引用 $vk，绝不重复求值；
    //   * 整数右值走位运算恒等链（全程 int，无类型歧义）；其它右值走
    //     「恒真三元 + 数组包装」（对任意类型都不改变值）；
    //   * 只在语句列表中的完整独立语句上改写：语句起点前一个有效 token 必须是
    //     { / } / ; / 开标签，从而排除 if/while/for 的无花括号单语句体、for 头、
    //     case 标签后首句、类属性声明等；
    //   * 跨语句复用用 `?? null` 守卫产生的「恒 0」表达式，即便上一条语句被
    //     goto 跳过也不会触发未定义变量 notice；遇到 { / } 清空复用链。
    // ====================================================================

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function tanglePass(array $tokens): array
    {
        $n = count($tokens);
        $matches = [];
        $carry = null;      // 上一条改写语句产生的 [a,b] 变量名，供跨语句复用
        $prevSig = null;    // 上一个有效 token 的边界标记
        $stmtStart = -1;
        $paren = 0;
        $bracket = 0;

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($this->isWs($t)) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                $prevSig = 'tag';
                $stmtStart = -1;
                $carry = null;
                continue;
            }
            if ($stmtStart < 0 && $this->isStmtListBoundary($prevSig)) {
                $stmtStart = $i;
            }
            if (is_string($t)) {
                if ($t === '(') {
                    $paren++;
                } elseif ($t === ')') {
                    $paren--;
                } elseif ($t === '[') {
                    $bracket++;
                } elseif ($t === ']') {
                    $bracket--;
                } elseif ($t === '{' || $t === '}') {
                    $stmtStart = -1;
                    $carry = null;
                    $prevSig = $t;
                    continue;
                } elseif ($t === ';' && $paren === 0 && $bracket === 0) {
                    if ($stmtStart >= 0) {
                        $m = $this->tryTangleStmt($tokens, $stmtStart, $i, $carry);
                        if ($m !== null) {
                            $matches[$stmtStart] = $m;
                            $carry = $m['carry'];
                        }
                    }
                    $stmtStart = -1;
                    $prevSig = ';';
                    continue;
                }
                $prevSig = $t;
            } else {
                $prevSig = 'T' . $t[0];
            }
        }
        return $this->rebuild($tokens, $matches);
    }

    /** 语句列表边界：只有这些前趋之后的语句才可安全展开成多语句 */
    private function isStmtListBoundary($prevSig): bool
    {
        return $prevSig === null || $prevSig === '{' || $prevSig === '}'
            || $prevSig === ';' || $prevSig === 'tag';
    }

    /**
     * 尝试把 [s, e]（e 为结尾 ';'）的语句展开成临时变量缠结块。
     * @return array{start:int,end:int,repl:array<int,mixed>,carry:array}|null
     */
    private function tryTangleStmt(array $tokens, int $s, int $e, ?array $carry): ?array
    {
        $first = $tokens[$s];
        $isAssign = is_array($first) && $first[0] === T_VARIABLE;
        $isEcho = is_array($first) && $first[0] === T_ECHO;
        if (!$isAssign && !$isEcho) {
            return null;
        }
        if ($isAssign) {
            $eq = Parser::nextSignificant($tokens, $s);
            if ($eq < 0 || !$this->isChar($tokens[$eq], '=')) {
                return null;
            }
            $afterEq = Parser::nextSignificant($tokens, $eq);
            // 排除引用赋值 =& / = &：PHP 8.1 把 & 分成 FOLLOWED_BY_VAR 与
            // NOT_FOLLOWED_BY_VAR 两种 token（&slot() 属于后者），漏掉任一种都会把
            // "$ref = &f()" 拆成 "$tmp = &f(); $ref = $tmp;"，引用绑定被值拷贝打断。
            if ($afterEq >= 0) {
                $ae = $tokens[$afterEq];
                $isAmp = (is_string($ae) && $ae === '&') || (is_array($ae) && (
                    (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') && $ae[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)
                    || (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $ae[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)
                ));
                if ($isAmp) {
                    return null;
                }
            }
            $rhsStart = $afterEq;
            $lhs = $first[1];
        } else {
            $rhsStart = Parser::nextSignificant($tokens, $s);
            $lhs = null;
        }
        if ($rhsStart < 0 || $rhsStart >= $e) {
            return null;
        }
        for ($k = $s; $k <= $e; $k++) {
            $t = $tokens[$k];
            if (is_string($t)) {
                if ($t === ',' || $t === ':' || $t === '?' || $t === '`' || $t === '&') {
                    return null; // & 出现在语句内一律跳过（引用赋值/引用传参不能拆）
                }
            } elseif (is_array($t)) {
                static $bad = null;
                if ($bad === null) {
                    $bad = [T_START_HEREDOC, T_END_HEREDOC, T_COMMENT, T_DOC_COMMENT,
                        T_RETURN, T_THROW, T_YIELD, T_YIELD_FROM, T_GOTO, T_GLOBAL,
                        T_STATIC, T_UNSET, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT,
                        T_ENUM, T_NAMESPACE, T_CASE, T_DEFAULT, T_ISSET, T_EMPTY,
                        T_INCLUDE, T_REQUIRE, T_EVAL, T_EXIT, T_BREAK, T_CONTINUE,
                        T_DO, T_WHILE, T_FOR, T_FOREACH, T_IF, T_ELSE, T_SWITCH,
                        T_TRY, T_CATCH, T_FINALLY, T_DECLARE];
                }
                if (in_array($t[0], $bad, true)) {
                    return null;
                }
            }
        }
        $rhs = trim($this->tokensToString($tokens, $rhsStart, $e - 1));
        if ($rhs === '') {
            return null;
        }
        // 单一整数右值判定（只有 1 个有效 token 且是十进制整数字面量）
        $isIntRhs = false;
        $intVal = 0;
        $sigCnt = 0;
        for ($k = $rhsStart; $k < $e; $k++) {
            if ($this->isWs($tokens[$k])) {
                continue;
            }
            $sigCnt++;
            if ($sigCnt === 1 && $this->isIntLiteral($tokens[$k])) {
                $isIntRhs = true;
                $intVal = (int) str_replace('_', '', $tokens[$k][1]);
            }
        }
        if ($sigCnt !== 1) {
            $isIntRhs = false;
        }

        $vk = $this->genName();  // 原值（只求值一次）
        $a = $this->genName();
        $b = $this->genName();
        $c = $this->genName();
        $d = $this->genName();

        if ($isIntRhs) {
            // Ka ^ Kb === v；a、b 反复参与，最终仍得 v
            $ka = $this->tangleKa($intVal);
            $kb = $ka ^ $intVal;
            $block = '$' . $vk . ' = ' . $rhs . ";\n"
                . '$' . $a . ' = ' . $this->id->intExpr($ka) . ";\n"
                . '$' . $b . ' = ' . $this->id->intExpr($kb) . ";\n"
                . '$' . $c . ' = $' . $a . ' ^ $' . $b . ";\n"
                . '$' . $d . ' = ($' . $c . ' ^ $' . $a . ') ^ $' . $b . ";\n";
            if ($carry !== null) {
                // 跨语句复用上一条语句的变量：恒 0（任意类型安全），加和不改变值
                $block .= '$' . $c . ' = ((($' . $carry[0] . ' ?? null) === ($' . $carry[1] . ' ?? null)) ? 0 : 0) + $' . $c . ";\n";
            }
            $block .= '$' . $vk . ' = $' . $c . ' + $' . $d . ";\n";
        } else {
            $block = '$' . $vk . ' = ' . $rhs . ";\n"
                . '$' . $a . ' = $' . $vk . ";\n"
                . '$' . $b . ' = (($' . $a . ' === $' . $a . ') ? $' . $a . ' : $' . $vk . ");\n"
                . '$' . $c . ' = [ $' . $b . " ];\n"
                . '$' . $d . ' = ($' . $c . '[0] === $' . $a . ') ? $' . $c . '[0] : $' . $b . ";\n";
            if ($carry !== null) {
                // 跨语句复用：恒真三元（两分支同值），任意类型安全
                $block .= '$' . $c . ' = (($' . $carry[0] . ' ?? null) === ($' . $carry[1] . ' ?? null))'
                    . ' ? $' . $vk . ' : $' . $vk . ";\n";
            }
            $block .= '$' . $vk . ' = $' . $d . ";\n";
        }
        $tail = $isAssign
            ? '$' . ltrim((string) $lhs, '$') . ' = $' . $vk . ';'
            : 'echo $' . $vk . ';';
        return [
            'start' => $s,
            'end'   => $e,
            'repl'  => $this->exprTokens($block . $tail),
            'carry' => [$a, $b],
        ];
    }

    /** 由 v 与 salt 派生一个足够大、非零的 Ka，保证 Ka ^ Kb === v 且不明显 */
    private function tangleKa(int $v): int
    {
        $h = $this->salt->isEmpty()
            ? crc32('logic-tangle|' . $v)
            : (int) hexdec(substr(bin2hex($this->salt->derive('logic-tangle|' . $v, 4)), 0, 7));
        return ($h & 0x3fffffff) | 0x100000;
    }

    // ====================================================================
    // 通用工具
    // ====================================================================

    /**
     * 抽取二元运算符右侧操作数（到优先级 ≤ $prec 的 token 或 depth0 闭合为止）。
     * @param array<int,mixed> $tokens
     * @return array{0:int,1:int}|null
     */
    private function rightOperand(array $tokens, int $opIdx, int $prec): ?array
    {
        $n = count($tokens);
        $j = $opIdx + 1;
        while ($j < $n && $this->isWs($tokens[$j])) {
            $j++;
        }
        if ($j >= $n) {
            return null;
        }
        $depth = 0;
        $start = $j;
        for (; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    if ($depth === 0) {
                        return [$start, $j - 1];
                    }
                    $depth--;
                    if ($depth === 0) {
                        return [$start, $j]; // 此 ) 是操作数自身的右括号，含入区间
                    }
                } elseif ($t === '{' || $t === '}') {
                    if ($depth === 0) {
                        return [$start, $j - 1]; // 块边界：终止操作数
                    }
                } elseif ($depth === 0 && $this->precOf($t) <= $prec) {
                    return [$start, $j - 1];
                }
            } elseif (is_array($t)) {
                if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($depth === 0 && $this->precOf($t) <= $prec) {
                    return [$start, $j - 1];
                }
            }
        }
        return [$start, $n - 1];
    }

    /**
     * 抽取二元运算符左侧操作数（反向扫描）。操作数自身的括号须含入区间：
     * 扫描到使 depth 回归 0 的 '(' 时，它正是左操作数的起点。
     * @param array<int,mixed> $tokens
     * @return array{0:int,1:int}|null
     */
    private function leftOperand(array $tokens, int $opIdx, int $prec): ?array
    {
        $n = count($tokens);
        $j = $opIdx - 1;
        while ($j >= 0 && $this->isWs($tokens[$j])) {
            $j--;
        }
        if ($j < 0) {
            return null;
        }
        $depth = 0;
        $end = $j;
        for (; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === ')' || $t === ']' || $t === '}') {
                    $depth++;
                } elseif ($t === '(' || $t === '[') {
                    if ($depth === 0) {
                        return [$j, $end];
                    }
                    $depth--;
                    if ($depth === 0) {
                        // 这个 '(' 属于左操作数自身。若它前面是函数名 / 变量 / 命名空间名
                        // （foo(...) 调用），必须把整个调用名一并纳入；否则 is_int($v)
                        // 会被切成 ($v)，函数名留在区间外 —— 布尔/比较重组就会把
                        // is_int( / count( / isset( 挂在外面，生成 count(true) 之类的错语义。
                        $p = Parser::prevSignificant($tokens, $j);
                        if ($p >= 0 && $this->isCallableNameToken($tokens[$p])) {
                            $j = $p + 1; // 下一轮 j-- 后从 $p 继续向前扫描整个调用
                            continue;
                        }
                        return [$j, $end]; // 此 ( 是操作数自身的左括号，含入区间
                    }
                } elseif ($t === '{') {
                    if ($depth === 0) {
                        return [$j + 1, $end]; // 块边界：操作数起点在 { 之后
                    }
                } elseif ($depth === 0 && $this->precOf($t) <= $prec) {
                    return [$j + 1, $end];
                }
            } elseif (is_array($t)) {
                if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($depth === 0 && $this->precOf($t) <= $prec) {
                    return [$j + 1, $end];
                }
            }
        }
        return [0, $end];
    }

    /**
     * 运算符优先级（数字越大绑定越紧）。
     * 关键：语句级边界（; , ? : => = 及各类赋值 / 合并 / 语句关键字 / 逻辑运算符）一律判为
     * 最低优先级（0），使操作数抽取在语句/赋值/三元/箭头/逻辑边界处终止，绝不吞并左邻语句。
     * 位运算/移位设定低于比较的优先级，保证跨运算符抽取符合 PHP 结合规则。
     */
    private function precOf($tok): int
    {
        if (is_string($tok)) {
            return match ($tok) {
                '(', ')', '[', ']', '{', '}' => -1,
                // 硬边界：语句分隔、三元、箭头、赋值、位赋值、合并
                ';', ',', '?', ':', '=>', '=', '&=', '|=', '^=', '??' => 0,
                '+', '-' => 40,
                '*', '/', '%', '.' => 50,
                '&' => 25,
                '^' => 20,
                '|' => 15,
                '<', '>' => 30,
                '!' => 70,
                default => 100,
            };
        }
        if (is_array($tok)) {
            return match ($tok[0]) {
                T_OBJECT_OPERATOR, T_DOUBLE_COLON => 200,
                T_POW => 60,
                T_SL, T_SR => 35,
                T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL,
                T_CONCAT_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_COALESCE_EQUAL => 0,
                // 逻辑运算符皆为边界（含并列 && 不可穿越）
                T_BOOLEAN_AND, T_BOOLEAN_OR => 5,
                T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR => 5,
                T_COALESCE => 0,
                T_DOUBLE_ARROW => 0,
                T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
                T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
                T_SPACESHIP => 30,
                // 语句级关键字：终止操作数抽取
                T_ECHO, T_PRINT, T_RETURN, T_THROW, T_YIELD, T_YIELD_FROM, T_EXIT,
                T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE => 0,
                default => 100,
            };
        }
        return 100;
    }

    /** 该 token 是否可作为调用名（foo( / $cb( / \foo( / ns\foo( 的名字位） */
    private function isCallableNameToken($t): bool
    {
        if (!is_array($t)) {
            return false;
        }
        static $names = null;
        if ($names === null) {
            $names = [T_STRING => true, T_VARIABLE => true, T_NS_SEPARATOR => true];
            foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
                if (defined($c)) {
                    $names[constant($c)] = true;
                }
            }
        }
        return isset($names[$t[0]]);
    }

    /**
     * 左操作数是否被「留在区间外的前缀」挂住。
     * leftOperand() 修好后会纳入 foo(...) 整体，正常起点前应是 = ; ( { 或运算符
     * 这类边界；若起点前仍是函数名 / isset / array / -> / :: 等，说明抽取被
     * 截断，此时重组会把前缀留在原地而只替换参数，生成错误语义，一律跳过。
     */
    private function leftOperandDetached(array $tokens, int $lS): bool
    {
        $p = Parser::prevSignificant($tokens, $lS);
        if ($p < 0) {
            return false;
        }
        $t = $tokens[$p];
        if (is_string($t)) {
            return false; // = ; ( { , ? : 等均为正常表达式边界
        }
        static $bad = null;
        if ($bad === null) {
            $bad = [T_STRING => true, T_ISSET => true, T_EMPTY => true, T_UNSET => true,
                T_ARRAY => true, T_LIST => true, T_EVAL => true, T_EXIT => true,
                T_PRINT => true, T_ECHO => true, T_RETURN => true, T_NEW => true,
                T_CLONE => true, T_OBJECT_OPERATOR => true, T_DOUBLE_COLON => true,
                T_NS_SEPARATOR => true, T_INCLUDE => true, T_REQUIRE => true];
            foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
                if (defined($c)) {
                    $bad[constant($c)] = true;
                }
            }
        }
        return isset($bad[$t[0]]);
    }

    /** 操作数是否安全（不含 ++/--/赋值/`->`/`::`/嵌套逻辑运算符/需要左值的函数） */
    private function safeOperands(array $tokens, int $s, int $e): bool
    {
        for ($k = $s; $k <= $e; $k++) {
            $t = $tokens[$k];
            if (is_string($t) && ($t === '++' || $t === '--')) {
                return false;
            }
            if (is_array($t)) {
                if (in_array($t[0], [T_INC, T_DEC, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    return false;
                }
                if (in_array($t[0], [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR], true)) {
                    // 操作数内部若含逻辑运算符，重组会嵌套，跳过以求稳健
                    return false;
                }
                // isset/empty/unset 要求可写变量引用（lvalue），不能把其参数卷进表达式重组
                if (in_array($t[0], [T_ISSET, T_EMPTY, T_UNSET], true)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** 数字字面量判定：十进制 T_LNUMBER（排除八进制 / 十六进制 / 浮点）。 */
    private function isIntLiteral($tok): bool
    {
        if (!is_array($tok) || $tok[0] !== T_LNUMBER) {
            return false;
        }
        $raw = $tok[1];
        if (strlen($raw) > 1 && $raw[0] === '0') {
            return false; // 八进制
        }
        if (stripos($raw, '0x') === 0 || stripos($raw, '0b') === 0) {
            return false;
        }
        return ctype_digit(str_replace('_', '', $raw));
    }

    /** 该裸 '!' 是否为 != / !== 的组成部分 */
    private function isBangOf(array $tokens, int $i): bool
    {
        $nx = $this->nextSignificant($tokens, $i, 1);
        if ($nx < 0) {
            return false;
        }
        return is_array($tokens[$nx]) && in_array($tokens[$nx][0], [T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL], true);
    }

    private function isCompare($tok): bool
    {
        return $this->compareKind($tok) !== null;
    }

    /**
     * 比较运算符归一化：token_get_all 对单字符 < > 返回裸字符串（无对应常量），
     * 仅 <= >= == != === !== <=> 是多字符才对应 T_* 常量。
     * 这里只接受 < > <= >=（==/!=/===/!== 不改写，因 <=> 对对象抛异常会破坏 == 语义）。
     * @param mixed $tok
     */
    private function compareKind($tok): ?string
    {
        if (is_string($tok)) {
            if ($tok === '<') {
                return 'lt';
            }
            if ($tok === '>') {
                return 'gt';
            }
            return null;
        }
        if (is_array($tok)) {
            return match ($tok[0]) {
                T_IS_SMALLER_OR_EQUAL => 'lte',
                T_IS_GREATER_OR_EQUAL => 'gte',
                default => null,
            };
        }
        return null;
    }

    private function isBoolOp($tok): bool
    {
        return is_array($tok) && in_array($tok[0], [
            T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
        ], true);
    }

    private function isCompoundAssign(array $tokens, int $i): bool
    {
        // 裸 '=' 是赋值；复合赋值（+= 等）是数组 token，不会以裸 '=' 到达此处
        return false;
    }

    /** 把表达式字符串 tokenize 成 token 序列（剥离开标签与尾空白） */
    private function exprTokens(string $expr): array
    {
        $toks = Parser::tokenize('<?php ' . $expr);
        $out = [];
        $started = false;
        foreach ($toks as $t) {
            if (!$started) {
                if (is_array($t) && $t[0] === T_OPEN_TAG) {
                    continue;
                }
                $started = true;
            }
            $out[] = $t;
        }
        while (!empty($out) && is_array(end($out)) && end($out)[0] === T_WHITESPACE) {
            array_pop($out);
        }
        return $out;
    }

    /** token 序列 → 源码字符串（含括号等，用于重组整体） */
    private function tokensToString(array $tokens, int $s = 0, int $e = -1): string
    {
        if ($e < 0) {
            $e = count($tokens) - 1;
        }
        $s = max(0, $s);
        $e = min(count($tokens) - 1, $e);
        $src = '';
        for ($k = $s; $k <= $e; $k++) {
            $t = $tokens[$k];
            $src .= is_array($t) ? $t[1] : $t;
        }
        return $src;
    }

    /** token 序列 → 源码字符串（用于把操作数嵌入重组目标） */
    private function tokensToSource(array $tokens): string
    {
        return $this->tokensToString($tokens, 0, count($tokens) - 1);
    }

    /**
     * 整条重组前后逐值等价校验。
     * 优先直接 eval（字面量场景，最严格）；当操作数含未定义变量/函数时改用「样本值代入」：
     * 把每个操作数源码串替换为代表性真值/假值字面量，覆盖真值类的全部组合，
     * 由于所有恒等式只依赖操作数的真值或大小关系，样本覆盖即等价于全量验证。
     * @param string[] $operands 需替换的操作数源码串（按长度降序替换以避免前缀串误伤）
     */
    private function verifyExpr(string $srcExpr, string $targetExpr, array $operands = []): bool
    {
        $ev = static function (string $code) {
            try {
                $r = @eval('return ' . $code . ';');
                if ($r === null && error_get_last() !== null
                    && (stripos(error_get_last()['message'], 'undefined') !== false
                        || stripos(error_get_last()['message'], 'not found') !== false)) {
                    return null; // 无法字面验证，信任已证明的恒等式
                }
                return $r;
            } catch (\Throwable $e) {
                return null;
            }
        };
        if ($operands === []) {
            $a = $ev($srcExpr);
            $b = $ev($targetExpr);
            if ($a === null || $b === null) {
                return true; // 含未定义变量/函数等，信任恒等式
            }
            return $a === $b;
        }
        if (count($operands) === 1) {
            $samples = [true, false, 0, 1, '', '0', 'x', [], null, 2.0, -1];
            $op = $operands[0];
            foreach ($samples as $va) {
                $s = str_replace($op, $this->lit($va), $srcExpr);
                $t = str_replace($op, $this->lit($va), $targetExpr);
                $sv = $ev($s);
                $tv = $ev($t);
                if ($sv === null || $tv === null || $sv !== $tv) {
                    return false;
                }
            }
            return true;
        }
        $samples = [true, false, 0, 1, '', 'x', []];
        // 长操作数优先替换，避免 $x 误伤 $xy / $x[0]
        $ops = $operands;
        usort($ops, static fn ($x, $y) => strlen($y) <=> strlen($x));
        foreach ($samples as $va) {
            foreach ($samples as $vb) {
                $s = $srcExpr;
                $t = $targetExpr;
                foreach ($ops as $idx => $op) {
                    $val = $idx === 0 ? $this->lit($va) : $this->lit($vb);
                    $s = str_replace($op, $val, $s);
                    $t = str_replace($op, $val, $t);
                }
                $sv = $ev($s);
                $tv = $ev($t);
                if ($sv === null || $tv === null || $sv !== $tv) {
                    return false;
                }
            }
        }
        return true;
    }

    private function lit($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if ($v === true) {
            return 'true';
        }
        if ($v === false) {
            return 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            return '[]';
        }
        if (is_string($v)) {
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
        }
        return 'null';
    }

    private function lastIsUndefined(): bool
    {
        $e = error_get_last();
        return $e !== null && (stripos($e['message'], 'undefined') !== false
            || stripos($e['message'], 'not found') !== false);
    }

    /** 收集匹配 → 重建（重叠时取覆盖更大者） */
    private function rebuild(array $tokens, array $matches): array
    {
        if ($matches === []) {
            return $tokens;
        }
        $best = [];
        foreach ($matches as $start => $m) {
            if (!isset($best[$start]) || $m['end'] > $best[$start]['end']) {
                $best[$start] = $m;
            }
        }
        ksort($best);
        $out = [];
        $skipUntil = 0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if ($i < $skipUntil) {
                continue;
            }
            if (isset($best[$i])) {
                foreach ($best[$i]['repl'] as $t) {
                    $out[] = $t;
                }
                $skipUntil = $best[$i]['end'] + 1;
                continue;
            }
            $out[] = $tokens[$i];
        }
        return $out;
    }

    private function matchBracket(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $j;
                    }
                }
            } elseif (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            }
        }
        return -1;
    }

    /** 提取语句主体：{...} 块或单条到深度0 ';' 的语句。返回 [tokens, end]，失败 [[], -1]。 */
    private function extractBody(array $tokens, int $start): array
    {
        $t = $tokens[$start];
        if ($this->isChar($t, '{')) {
            $close = $this->matchBracket($tokens, $start);
            if ($close < 0) {
                return [[], -1];
            }
            return [array_slice($tokens, $start + 1, $close - $start - 1), $close];
        }
        $depth = 0;
        $pdepth = 0;
        $bdepth = 0;
        $n = count($tokens);
        for ($i = $start; $i < $n; $i++) {
            $tk = $tokens[$i];
            if (is_string($tk)) {
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
                } elseif ($tk === '}') {
                    if ($depth > 0) {
                        $depth--;
                    }
                } elseif ($tk === ';' && $depth === 0 && $pdepth === 0 && $bdepth === 0) {
                    return [array_slice($tokens, $start, $i - $start + 1), $i];
                }
            } elseif (is_array($tk) && ($tk[0] === T_CURLY_OPEN || $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            }
        }
        return [[], -1];
    }

    private function prevSignificant(array $tokens, int $i): int
    {
        return Parser::nextSignificant($tokens, $i, -1);
    }

    private function nextSignificant(array $tokens, int $i, int $dir): int
    {
        return Parser::nextSignificant($tokens, $i, $dir);
    }

    private function isWs($t): bool
    {
        return is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function isChar($t, string $c): bool
    {
        return is_string($t) && $t === $c;
    }

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
        $seed = $this->salt->isEmpty() ? bin2hex(random_bytes(4)) : $this->salt->derive('logic-rw', 10);
        for ($try = 0; $try < 50; $try++) {
            $cand = '_m' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $seed), 0, 6) . ($try === 0 ? '' : (string) $try);
            if (!isset($this->usedVars[$cand])) {
                $this->usedVars[$cand] = true;
                return $cand;
            }
        }
        return '_m' . substr(md5($seed . microtime()), 0, 8);
    }
}

/**
 * 逻辑变换的上下文状态机：跟踪 declare / const / case 标签 / 参数默认值 / 属性默认值，
 * 以决定数字字面量是用「函数调用形态（runtime）」还是「位运算形态（const 安全）」或「跳过（declare）」，
 * 并暴露括号/花括号深度供结构判定。
 */
final class LogicCtx
{
    public int $parenDepth = 0;
    public int $braceDepth = 0;
    private int $declareParen = -1;
    private bool $constActive = false;
    private bool $caseActive = false;
    private int $paramsParen = -1;
    private int $paramDefaultLevel = -1;
    private bool $propDefault = false;
    private int $classBraceDepth = -1;
    private bool $funcPending = false;
    private bool $classPending = false;
    private bool $propPending = false;
    private bool $declarePending = false;
    private bool $attrActive = false;   // 属性参数 #[...]：只许常量表达式
    private int $attrDepth = 0;
    private bool $staticActive = false; // 静态变量初始化 static $x = ...：只许常量表达式

    /** @param array<int,mixed> $tokens */
    public function step(array $tokens, int $i): void
    {
        $t = $tokens[$i];
        if (is_string($t)) {
            if ($t === '(') {
                $this->parenDepth++;
                if ($this->funcPending) {
                    $this->paramsParen = $this->parenDepth;
                    $this->funcPending = false;
                } elseif ($this->declarePending) {
                    $this->declareParen = $this->parenDepth;
                    $this->declarePending = false;
                }
            } elseif ($t === ')') {
                $this->parenDepth--;
                if ($this->declareParen >= 0 && $this->parenDepth < $this->declareParen) {
                    $this->declareParen = -1;
                }
                if ($this->paramsParen >= 0 && $this->parenDepth < $this->paramsParen) {
                    $this->paramsParen = -1;
                    $this->paramDefaultLevel = -1;
                }
                if ($this->paramDefaultLevel >= 0 && $this->parenDepth < $this->paramDefaultLevel) {
                    $this->paramDefaultLevel = -1;
                }
            } elseif ($t === '{') {
                $this->braceDepth++;
                if ($this->classPending) {
                    $this->classBraceDepth = $this->braceDepth;
                    $this->classPending = false;
                }
            } elseif ($t === '}') {
                $this->braceDepth--;
            } elseif ($t === '[') {
                if ($this->attrActive) {
                    $this->attrDepth++;
                }
            } elseif ($t === ']') {
                if ($this->attrActive) {
                    $this->attrDepth--;
                    if ($this->attrDepth <= 0) {
                        $this->attrActive = false;
                    }
                }
            } elseif ($t === ';') {
                if ($this->constActive) {
                    $this->constActive = false;
                }
                // enum `case Low = 1;` 以 ; 结束标签，必须在此清除 caseActive，
                // 否则会一直残留、把后续运行时数字误判为常量上下文。
                $this->caseActive = false;
                $this->staticActive = false;
                if ($this->propDefault) {
                    $this->propDefault = false;
                }
                if ($this->propPending) {
                    $this->propPending = false;
                }
            } elseif ($t === '=') {
                if ($this->paramsParen > 0 && $this->parenDepth === $this->paramsParen) {
                    $this->paramDefaultLevel = $this->parenDepth;
                }
                if ($this->propPending && $this->braceDepth === $this->classBraceDepth) {
                    $this->propDefault = true;
                    $this->propPending = false;
                }
            } elseif ($t === ':') {
                if ($this->caseActive) {
                    $this->caseActive = false;
                }
            }
        } elseif (is_array($t)) {
            switch ($t[0]) {
                case T_DECLARE:
                    $this->declarePending = true;
                    break;
                case T_CONST:
                    $this->constActive = true;
                    break;
                case T_ATTRIBUTE:
                    $this->attrActive = true;
                    $this->attrDepth = 1;
                    break;
                case T_STATIC:
                    $this->staticActive = true;
                    break;
                case T_CASE:
                case T_DEFAULT:
                    $this->caseActive = true;
                    break;
                case T_DOUBLE_ARROW:
                    // match 的 arm（条件 => 结果）与 switch 的 `case x:` 不同，
                    // 用 => 结束标签；同时普通数组 ['k' => v] 走到这里也只是清个
                    // 本就为 false 的标志，无副作用。
                    $this->caseActive = false;
                    break;
                case T_FUNCTION:
                case T_FN:
                    $this->funcPending = true;
                    break;
                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    $this->classPending = true;
                    break;
                case T_VARIABLE:
                    if ($this->braceDepth === $this->classBraceDepth && $this->classBraceDepth >= 0) {
                        $this->propPending = true;
                    }
                    break;
            }
        }
    }

    public function inDeclare(): bool
    {
        return $this->declareParen >= 0;
    }

    public function isConstContext(): bool
    {
        return $this->constActive || $this->caseActive
            || $this->paramDefaultLevel >= 0 || $this->propDefault
            || $this->attrActive || $this->staticActive;
    }

    public function depth(): int
    {
        return $this->parenDepth + $this->braceDepth;
    }
}
