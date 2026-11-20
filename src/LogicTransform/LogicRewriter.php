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

require_once __DIR__ . '/../Support/LoopScanTrait.php';

final class LogicRewriter
{
    use LoopScanTrait;

    private Salt $salt;
    private Logger $logger;
    private IdentityExpr $id;
    private int $intensity;
    private array $usedVars = [];
    private int $constSeed = 0;
    private int $genCtr = 0;
    /** @var array<int,string> 本层创建的所有临时变量名（用于末尾赋假值） */
    private array $tempVars = [];
    private bool $decoyOn = false;      // 派生多分支诱饵（普通档，默认 ultra 开）
    private bool $fakeTailOn = false;   // 末尾给临时变量赋随机假值（普通档，默认 ultra 开）
    private bool $extremeOn = false;    // 高级档：用户变量保存→假值→恢复（默认关，可能破坏）
    private bool $minifyOn = false;     // 压行
    private bool $wildOn = false;       // v1.2.4 天马行空加强档：扩张恒等积木编码库
    private int $strMaxLen = 0;         // 字符串字面量改写长度上限（0=不限；逐壳层用于保护密文载荷）
    private bool $hoistOn = false;      // 循环内轻量注入（大循环不用函数调用积木，默认开）
    private int $hoistThreshold = 20;   // 迭代数超过此值才降级（小循环保留最强形态）

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
        $ultra = $this->intensity >= 3;
        // 普通档（默认 ultra 开，100% 安全）：多分支诱饵 / 临时变量末尾假值 / 压行。
        // 高级档（独立开关，默认关，可能有破坏性）：用户变量保存→假值→恢复。
        $this->decoyOn    = (bool) $cfg->get('logic_decoy', $ultra);
        $this->fakeTailOn = (bool) $cfg->get('logic_fake_tail', $ultra);
        $this->extremeOn  = (bool) $cfg->get('logic_extreme', false);
        $this->minifyOn   = (bool) $cfg->get('logic_minify', $ultra);
        // v1.2.4 天马行空加强 + 字符串长度上限（逐壳层保护密文载荷）
        $this->wildOn     = (bool) $cfg->get('logic_wild', false);
        $this->strMaxLen  = max(0, (int) $cfg->get('logic_str_maxlen', 0));
        // 循环内轻量注入：大循环里的字面量只用「运算符 + 字面量」级恒等（编译期折叠、
        // 运行期零指令），避免函数调用积木被迭代数放大成 N 倍开销。默认开。
        $this->hoistOn = (bool) $cfg->get('logic_loop_light', $cfg->get('logic_loop_hoist', true));
        $this->hoistThreshold = max(0, (int) $cfg->get('logic_loop_threshold', 20));
        $this->constSeed = $this->salt->isEmpty()
            ? 0
            : (int) hexdec(substr(bin2hex($this->salt->derive('logic-const', 4)), 0, 7));
        // 让恒等积木里的随机串随 salt 变化（可复现），不再清一色 'x'/'1'
        $this->id->setSeed($this->constSeed);
        // v1.2.4 天马行空加强：扩张恒等积木编码库（双层 base64 / strrev / ord / 移位链等）
        $this->id->setWild($this->wildOn);
        $this->genCtr = 0;
        $this->tempVars = [];
        $this->collectVars($tokens);
        // 先把大循环内的字面量降级成轻量形态（此时还是裸字面量，位置最容易识别），
        // 再进入常规变换——被标记的 token 不会再被展开成函数调用积木。
        if ($this->hoistOn) {
            $tokens = $this->hoistPass($tokens);
        }
        // 先缠结（此时右值仍是原始字面量，可识别整数走位运算恒等链），
        // 再做表达式级递归变换（把剩余数字/字符串一并展开）。
        if ($this->intensity >= 3) {
            $tokens = $this->tanglePass($tokens);
        }
        $tokens = $this->transformRange($tokens, true);
        if ($this->fakeTailOn || $this->extremeOn) {
            $tokens = $this->fakeTailPass($tokens);
        }
        if ($this->minifyOn) {
            $tokens = $this->minifyTokens($tokens);
        }
        return $tokens;
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
                // 大循环内已被替换成轻量形态（运算符 + 字面量），不再展开成函数调用积木
                if (!empty($tok[3])) {
                    continue;
                }
                // v1.2.7：`continue 2;` / `break 3;` 的操作数必须是【整数字面量】，
                // 展开成恒等表达式（如 (2===2?2:2)）会触发
                // "'continue' operator with non-integer operand is no longer supported"。
                // 这类跳转层级操作数一律保持原样。
                if (!$this->isJumpLevelOperand($tokens, $i) && !$ctx->inDeclare()) {
                    $expr = $ctx->isConstContext()
                        ? $this->id->intExprConstSafe($tok[1], $this->constSeed)
                        : $this->id->intExpr((int) $tok[1]);
                    if ($expr !== null && $this->id->verify((int) $tok[1], $expr)) {
                        $matches[$i] = ['start' => $i, 'end' => $i, 'repl' => $this->exprTokens($expr)];
                    }
                }
                continue;
            }

            // —— 字符串字面量多编码改写（仅顶层；排除常量表达式上下文 / declare）——
            // 常量上下文（const / case / 参数默认值 / 属性默认值 / static 初始化 / #[...]
            // 属性参数）里的字符串必须是字面量，调用 base64_decode 等会致命；
            // heredoc/nowdoc 与插值字符串不是单个 T_CONSTANT_ENCAPSED_STRING，天然不在此列。
            if ($transformNumbers && is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                // 大循环内已被替换成轻量形态（拼接或原样），不再展开成函数调用积木
                if (!empty($tok[3])) {
                    continue;
                }
                // 逐壳层模式：超过长度上限的字符串一律不碰——那几乎必定是内层壳的
                // base64/gz/字节码密文载荷，改写它会膨胀产物且与结构指纹冲突。
                $tooLong = $this->strMaxLen > 0 && strlen($tok[1]) > $this->strMaxLen;
                if (!$tooLong && !$ctx->inDeclare() && !$ctx->isConstContext()) {
                    $s = $this->stripQuotes($tok[1]);
                    $expr = $s === null ? null : $this->id->stringExpr($s);
                    if ($s !== null && $expr !== null && $this->id->verify($s, $expr)) {
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
        // v1.2.7：`!$x = expr` 中右操作数 $x 实为短路赋值的左值（`!($x = expr)` 语义）。
        // 改写 `!$x` → `($x)==false` 后，后面的 `= expr` 悬空 → `($x==false)=expr`
        // 给表达式赋值，Parse error。此类写回一律放弃。
        if ($this->nextIsAssignment($tokens, $re)) {
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
        // v1.2.7：`0x80 > $code %= 0x200000` 中右操作数 $code 是复合赋值左值
        // （PHP 语义为 0x80 > ($code %= ...)）。比较改写后 `<=>` 表达式后紧跟
        // `%= expr` 悬空 → Parse error。写回左值一律放弃比较重组。
        if ($this->nextIsAssignment($tokens, $rE)) {
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
        // v1.2.7：右操作数若紧跟赋值运算符，说明它其实是「短路赋值」的左值——
        // 典型 `$id && $attr = "..."`（语义为 `$id && ($attr = "...")`）。
        // 本 pass 把 `$id && $attr` 改写成 `((($id)!=false)+(($attr)!=false))===2`，
        // 但后面的 `= "..."` 落在替换区间之外，产出 `((cond)===2) = "..."` —— 给
        // 表达式赋值，Parse error。此类写回语句一律放弃布尔重组（宁可少变换）。
        if ($this->nextIsAssignment($tokens, $rE)) {
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
        $dead = null;       // 上上句的 [a,b]（本句已不再使用），在本句末尾赋假值
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
                        $m = $this->tryTangleStmt($tokens, $stmtStart, $i, $carry, $dead);
                        if ($m !== null) {
                            $matches[$stmtStart] = $m;
                            $dead = $carry;
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
    private function tryTangleStmt(array $tokens, int $s, int $e, ?array $carry, ?array $older = null): ?array
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

        // 只用两个寄存器：$a（累加/结果）、$b（异或/副本）。相比旧版 5 个临时变量
        // 大幅降低运行期开销与代码体量；跨语句复用仍保留（借用上一句的 a,b 做恒 0 运算）。
        $a = $this->genName();
        $b = $this->genName();

        if ($isIntRhs) {
            // Ka OP Kb === v。wild 档随机在 XOR / 加法 / 减法 / 位掩码链之间切换，
            // 让缠结运算本身也不再是清一色的 `^`，进一步打散模式匹配。
            $ka = $this->tangleKa($intVal);
            $mode = $this->wildOn ? ($this->genCtr % 3) : 0;
            if ($mode === 1) {
                // 加法链：Ka + Kb === v  => Kb = v - Ka
                $kb = $intVal - $ka;
                $op = '+';
            } elseif ($mode === 2) {
                // 减法链：Ka - Kb === v  => Kb = Ka - v
                $kb = $ka - $intVal;
                $op = '-';
            } else {
                // 位运算 XOR（默认/经典形态）
                $kb = $ka ^ $intVal;
                $op = '^';
            }
            $block = '$' . $a . ' = ' . $this->id->intExpr($ka) . ";\n"
                . '$' . $b . ' = ' . $this->id->intExpr($kb) . ";\n"
                . '$' . $a . ' = $' . $a . ' ' . $op . ' $' . $b . ";\n";
            if ($carry !== null) {
                // 跨语句复用上一条语句的变量：恒 0（任意类型安全），加和不改变值
                $block .= '$' . $a . ' = ((($' . $carry[0] . ' ?? null) === ($' . $carry[1] . ' ?? null)) ? 0 : 0) + $' . $a . ";\n";
            }
            $res = $a;
        } else {
            $block = '$' . $a . ' = ' . $rhs . ";\n"
                . '$' . $b . ' = (($' . $a . ' === $' . $a . ') ? $' . $a . ' : $' . $a . ");\n";
            if ($carry !== null) {
                // 跨语句复用：恒真三元（两分支同值），任意类型安全
                $block .= '$' . $b . ' = (($' . $carry[0] . ' ?? null) === ($' . $carry[1] . ' ?? null))'
                    . ' ? $' . $b . ' : $' . $a . ";\n";
            }
            $res = $b;
        }
        if ($older !== null) {
            // 上上句的临时变量本句已不再使用：就地赋随机假值（数字/字符串交替），
            // 既不影响任何后续计算（后面只 carry 本句的 a,b），又制造"仍在用"的假象。
            $block .= '$' . $older[0] . ' = ' . $this->fakeValue($this->genCtr + 7) . ';'
                . ' $' . $older[1] . ' = ' . $this->fakeValue($this->genCtr + 11) . ";\n";
        }
        if ($isAssign) {
            $tail = '$' . ltrim((string) $lhs, '$') . ' = $' . $res . ';';
        } elseif ($this->decoyOn) {
            // 派生多分支诱饵：恒真条件（对 NAN 也成立：`===` 与 `!==` 至少一个为真）
            // 选择真输出，else 分支输出一个派生假值（多编码的随机串/数），
            // 让逆向者无法仅凭"这条分支输出什么"确定真实输出。
            $decoy = $this->fakeValue(10000 + $this->genCtr);
            $tail = 'if (($' . $res . ' === $' . $res . ') || ($' . $res . ' !== $' . $res . ')) {'
                . ' echo $' . $res . '; } else { echo ' . $decoy . '; }';
        } else {
            $tail = 'echo $' . $res . ';';
        }
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
    /**
     * v1.2.7：$idx 之后最近的有效 token 是否为赋值运算符（含复合赋值 / 引用赋值）。
     * 用于识别 `$a && $b = expr` 这类「布尔右侧是写回左值」的语句，避免 BoolOp 变换
     * 把左值抽走后留下悬空的 `= expr`。
     * @param array<int,mixed> $tokens
     */
    private function nextIsAssignment(array $tokens, int $idx): bool
    {
        $n = count($tokens);
        for ($j = $idx + 1; $j < $n; $j++) {
            if ($this->isWs($tokens[$j])) {
                continue;
            }
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === '=' || $t === '=>') {
                    return true;
                }
            } elseif (is_array($t)) {
                if (in_array($t[0], [
                    T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
                    T_CONCAT_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL,
                    T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
                ], true)) {
                    return true;
                }
            }
            // 遇到的第一个有效 token 不是赋值 → 不是写回左值
            return false;
        }
        return false;
    }

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
                        // 括号只是更大表达式的一段：`$i + (3) < $n` 的左操作数是
                        // `$i + (3)` 而不是 `(3)`。早先在这里直接返回，导致重组后变成
                        // `$i + ((3 <=> $n) === -1)` —— 循环条件恒真，运行期死循环。
                        // 前一个 token 是二元运算符（precOf > 0）或数组下标闭括号时，
                        // 必须继续向左扫描，把整段算术表达式纳入。
                        if ($p >= 0) {
                            $pt = $tokens[$p];
                            if ($this->precOf($pt) > 0 || (is_string($pt) && $pt === ']')) {
                                $j = $p + 1;
                                continue;
                            }
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
        // v1.2.7：变换产出的 target 若语法非法，必须拒绝本次变换。旧实现把 @eval 的
        // ParseError 一并吞成 null，再走「无法验证则信任恒等式」分支放行，导致
        // `php -l` 外壳合法、内层 eval 炸的整站 fatal（三元 + 多重 || 边界）。
        if (!$this->isExprSyntaxValid($targetExpr)) {
            return false;
        }
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

    /**
     * v1.2.7：判断 $i 处整数是否为 continue/break 的跳转层级操作数。
     * 这类操作数必须是字面量整数，绝不能展开成表达式。
     * @param array<int,mixed> $tokens
     */
    private function isJumpLevelOperand(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($this->isWs($t)) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_CONTINUE, T_BREAK], true)) {
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * v1.2.7：纯语法检查表达式（不执行、无副作用）。
     *
     * 把表达式包进一个【永不调用】的唯一命名函数体：eval 只编译函数体（触发语法检查），
     * 函数体内的 return 表达式不会被执行。用递增序号保证函数名唯一，避免重复声明。
     * 这样能可靠拦截 `((...)) = expr` / 括号失配 等变换产物语法错误。
     */
    private function isExprSyntaxValid(string $expr): bool
    {
        static $seq = 0;
        $fn = '__awa_syn_' . (++$seq);
        $code = 'if(false){function ' . $fn . '(){return (' . $expr . ');}}';
        try {
            $r = @eval($code);
            return $r !== false || error_get_last() === null
                || stripos((string) (error_get_last()['message'] ?? ''), 'syntax error') === false;
        } catch (\ParseError $e) {
            return false;
        } catch (\Throwable $e) {
            return true; // 非语法错误（如重复声明）不影响语法有效性判断
        }
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

    private function prevSignificant(array $tokens, int $i): int
    {
        return Parser::nextSignificant($tokens, $i, -1);
    }

    /**
     * 字符串字面量的【真实值】。无法静态求值时返回 null（调用方跳过不碰）。
     *
     * 必须完整解析转义：`"\x00"` 是 1 个字节，不是反斜杠加三个字符。此前直接把 token
     * 文本去引号当值，`"\x00"` 会被编码成求值等于 4 字符文本的表达式——字节数从 1 变 4。
     * 这类字符串一旦落进 sha1 的填充循环（它按「长度 % 64 命中 56」决定追加多少个 0 字节），
     * 步长就从 1 变成 4，长度序列永远命不中目标值，运行期无限追加 → CPU 100%。
     *
     * 跳过的情况：含变量插值（`$`）、`\u{...}`（stripcslashes 不认）——静态求不出值就不碰。
     */
    private function stripQuotes(string $raw): ?string
    {
        if ($raw === '' || strlen($raw) < 2) {
            return $raw;
        }
        $q = $raw[0];
        $inner = substr($raw, 1, -1);
        if ($q === "'") {
            // 单引号只认 \\ 与 \'
            return preg_replace_callback('/\\\\([\\\\\'])/', static fn ($m): string => $m[1], $inner);
        }
        if (str_contains($inner, '$') || str_contains($inner, '\\u{')) {
            return null;
        }
        return stripcslashes($inner);
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
        $this->genCtr++;
        $material = $this->salt->isEmpty()
            ? bin2hex(random_bytes(6))
            : bin2hex($this->salt->derive('logic-rw|' . $this->genCtr, 8));
        $material = preg_replace('/[^a-zA-Z0-9]/', '', $material);
        if ($material === '' || $material === null) {
            $material = bin2hex(random_bytes(6));
        }
        // 名字形如 _<9位随机>：长度足够、前缀固定 '_'，并与已收集变量全局查重，
        // 碰撞时递增十六进制后缀，基本不可能与用户变量重名（旧版 6 位短名相对更易撞）。
        for ($try = 0; $try < 200; $try++) {
            $cand = '_' . substr($material, 0, 9) . ($try === 0 ? '' : dechex($this->genCtr * 131 + $try));
            if (!isset($this->usedVars[$cand])) {
                $this->usedVars[$cand] = true;
$this->tempVars[] = $cand;
                return $cand;
            }
        }
        do {
            $cand = '_' . bin2hex(random_bytes(8));
        } while (isset($this->usedVars[$cand]));
        $this->usedVars[$cand] = true;
        $this->tempVars[] = $cand;
        return $cand;
    }

    /**
     * 末尾假值：给本层创建的所有临时变量在文件末尾赋随机数字/字符串（多编码）。
     * 这些变量在真实逻辑里已不再被读取，末尾覆盖不影响输出；作用是让逆向者看到
     * 一堆"还在被赋值"的变量，误以为仍有未读逻辑。高级档（extreme）额外对用户
     * 普通变量做「保存→假值→恢复」，最终值不变（但可能定义原本未定义的变量）。
     */
    private function fakeTailPass(array $tokens): array
    {
        // 临时变量的"末尾假值"已改为 tanglePass 内的「延迟两句就地覆盖」（更安全，
        // 不受 namespace / HTML 结构影响）。此处只处理 extreme 档的用户变量：
        // 在文件末尾对"出现过赋值"的普通变量做「保存→假值→恢复」，最终值不变。
        // 为避免把代码插到命名空间块外 / HTML 区，遇到 bracketed namespace 或
        // 内联 HTML 时整体跳过（extreme 本就是"可能有破坏性"的独立开关）。
        $code = '';
        $n = 0;
        if ($this->extremeOn && !$this->hasBracketNamespace($tokens) && !$this->hasInlineHtml($tokens)) {
            $tempSet = [];
            foreach ($this->tempVars as $tv) {
                $tempSet[$tv] = true;
            }
            $assigned = $this->collectAssignedVars($tokens);
            $skipSG = ['this' => 1, 'GLOBALS' => 1, '_GET' => 1, '_POST' => 1, '_COOKIE' => 1,
                '_SERVER' => 1, '_FILES' => 1, '_ENV' => 1, '_REQUEST' => 1, '_SESSION' => 1];
            $cnt = 0;
            foreach ($assigned as $v => $_) {
                if (isset($skipSG[$v]) || isset($tempSet[$v]) || $cnt >= 20) {
                    continue;
                }
                $save = $this->genName();
                $code .= '$' . $save . ' = $' . $v . '; $' . $v . ' = ' . $this->fakeValue($n) . '; $' . $v . ' = $' . $save . ";\n";
                $n++;
                $cnt++;
            }
        }
        if ($code === '') {
            return $tokens;
        }
        $ins = $this->exprTokens($code);
        // 插到最后一个 T_CLOSE_TAG 之前；无 close tag 则追加到末尾。
        $pos = count($tokens);
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLOSE_TAG) {
                $pos = $i;
                break;
            }
        }
        array_splice($tokens, $pos, 0, $ins);
        return $tokens;
    }

    /** 是否使用花括号式命名空间（`namespace X { ... }`）—— 末尾插代码会落到命名空间外 */
    private function hasBracketNamespace(array $tokens): bool
    {
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NAMESPACE) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_string($t) && $t === ';') {
                    break;
                }
                if (is_string($t) && $t === '{') {
                    return true;
                }
                if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    break;
                }
            }
        }
        return false;
    }

    /** 是否存在内联 HTML（混写文件末尾插代码会变成 HTML 文本） */
    private function hasInlineHtml(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_INLINE_HTML && trim($t[1]) !== '') {
                return true;
            }
        }
        return false;
    }

    /** 生成一个随机假值表达式（数字或字符串交替，均为多编码/恒等展开形态） */
    private function fakeValue(int $i): string
    {
        $seed = $this->salt->isEmpty()
            ? bin2hex(random_bytes(4))
            : bin2hex($this->salt->derive('logic-fake|' . $i, 6));
        $seed = preg_replace('/[^a-zA-Z0-9]/', '', $seed) ?? 'x';
        if ($seed === '') {
            $seed = 'x';
        }
        if ($i % 2 === 0) {
            $s = substr($seed . 'fake', 0, 8);
            return $this->id->stringExpr($s);
        }
        $num = (int) (hexdec(substr($seed, 0, 6)) % 900000) + 1000;
        return $this->id->intExpr($num);
    }

    /** 收集"出现过赋值"的用户变量名（T_VARIABLE 后紧跟 '='），供 extreme 档使用 */
    private function collectAssignedVars(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $nx = Parser::nextSignificant($tokens, $i);
            if ($nx >= 0 && is_string($tokens[$nx]) && $tokens[$nx] === '=') {
                $out[ltrim($t[1], '$')] = true;
            }
        }
        return $out;
    }

    /**
     * 压行：删除空白与注释 token，仅在两个"词素"相邻会粘连时补一个空格。
     * heredoc 体是 T_ENCAPSED_AND_WHITESPACE（非空白 token），原样保留。
     * 行注释删除后补一个换行，避免吞掉后续代码。
     */
    private function minifyTokens(array $tokens): array
    {
        $out = [];
        $prevText = '';
        $prevTok = null;
        foreach ($tokens as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($tok[0] === T_COMMENT && (substr($text, 0, 2) === '//' || ($text[0] ?? '') === '#')) {
                    // 必须用真正的 T_WHITESPACE token（而非裸字符串），否则后续层
                    // 的 Parser::prevSignificant 不认它是空白，导致关键字上下文误判。
                    $out[] = [T_WHITESPACE, "\n", 0];
                    $prevText = "\n";
                    $prevTok = null;
                }
                continue;
            }
            if ($text === '') {
                continue;
            }
            if ($prevText !== '' && $this->needSpaceBetween($prevTok, $prevText, $tok, $text)) {
                $out[] = [T_WHITESPACE, ' ', 0];
            }
            $out[] = $tok;
            $prevText = $text;
            $prevTok = $tok;
        }
        return $out;
    }

    private function needSpaceBetween($prevTok, string $prevText, $tok, string $text): bool
    {
        $a = $prevText[strlen($prevText) - 1];
        $b = $text[0];
        $wordy = static fn (string $c) => $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80);
        if ($wordy($a) && $wordy($b)) {
            return true;
        }
        if ($b === '.' && is_array($prevTok) && in_array($prevTok[0], [T_LNUMBER, T_DNUMBER], true)) {
            return true;
        }
        // 关键字后紧跟 `\FQCN`（如 `new \App\Circle`）：压缩成 `new\App\Circle` 会被
        // 词法器当成函数调用。PHP 8 的 `\App\Circle` 是 T_NAME_FULLY_QUALIFIED 等
        // 「完整名字」token，只有这类才需要前置空格；而 group use `A\B\{X,Y}` 里的 `\`
        // 是 T_NS_SEPARATOR 分隔符，若在其前补空格会破坏 class 层对 use 列表的解析
        // （别名表漏项 → 接口/父类断链）。故 PHP 8 下只认 T_NAME_*。
        if ($wordy($a) && $b === '\\' && is_array($tok)) {
            $types = [];
            foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
                if (defined($c)) {
                    $types[] = constant($c);
                }
            }
            if ($types !== []) {
                if (in_array($tok[0], $types, true)) {
                    return true;
                }
            } elseif ($tok[0] === T_NS_SEPARATOR) {
                return true; // PHP 7.4 兼容
            }
        }
        return false;
    }

    // ====================================================================
    // v1.2.13 循环不变量外提（性能层 / LICM）
    //
    // 症状：本层把字面量展开成「函数调用积木」——整数走 strlen(sha1('…'))*N，
    //   字符串走 base64_decode(...) 之类。这类表达式语义上是纯值，但**每次求值
    //   都要真跑一遍函数**。一旦它落在循环条件或循环体里，就被从「一次」放大成
    //   「迭代数 × 一次」：2000 轮的 `for` 会实打实算 2000 次 sha1。循环密集的
    //   业务代码因此比原生慢一个量级，这是 low/mid 档最大的运行期开销来源。
    //
    // 做法：对每个循环估算静态迭代数，超过阈值（默认 20）时，把「每轮都会求值」
    //   的区间（for 的 cond/step、while/do-while 的 cond、以及 body）里的字面量
    //   整体搬到循环语句之前，各用一个临时变量承接；循环内只留变量引用。
    //   随后的常规变换只会在那条前置赋值里生成一次积木——开销从 O(N) 退回 O(1)。
    //
    // 语义安全边界（任何一条不满足就原样不动）：
    //   * 只搬字面量：纯值、无副作用、无求值顺序依赖，多算一次与少算一次等价；
    //   * 跳过常量上下文（const / 参数默认值 / 属性默认值 / case / #[…] / declare）
    //     与跳转层级操作数（`continue 2` 必须是整数字面量）；
    //   * 跳过函数 / 闭包 / 箭头函数 / 类体内部——那些字面量并不在循环里逐轮执行，
    //     搬出去反而会让函数体引用一个它看不见的外部变量（致命）；
    //   * 循环体是单语句、或循环前一个 token 不是语句边界（else、if 无花括号体）时，
    //     用 `{ … }` 把「前置赋值 + 循环」整段包起来，避免被 else / if 吸附走；
    //   * 迭代数静态不可估（while(true)、`while ($row = f())`）一律按「大循环」处理。
    // ====================================================================

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function hoistPass(array $tokens): array
    {
        return $this->hoistRange($tokens);
    }

    /**
     * 递归处理：遇到循环就先递归它的 body（内层先做完，外层才能把内层前置语句里的
     * 字面量也一并提走），再决定自己要不要外提。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function hoistRange(array $tokens): array
    {
        $n = count($tokens);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $loop = $this->parseLoop($tokens, $i);
            if ($loop === null) {
                $out[] = $tokens[$i];
                $i++;
                continue;
            }
            $end = $loop['end'];
            $seg = array_slice($tokens, $i, $end - $i + 1);
            // body 子区间（相对 seg）：递归处理嵌套循环
            $bs = $loop['bodyStart'] - $i;
            $be = $loop['bodyEnd'] - $i;
            if ($be >= $bs && $bs >= 0) {
                $body = $this->hoistRange(array_slice($seg, $bs, $be - $bs + 1));
                $seg = array_merge(array_slice($seg, 0, $bs), $body, array_slice($seg, $be + 1));
            }
            // body 长度可能因内层外提而变化，重新解析一次拿准确位置
            $loop2 = $this->parseLoop($seg, 0);
            if ($loop2 !== null) {
                $seg = $this->applyHoist($seg, $loop2, $tokens, $i);
            }
            foreach ($seg as $t) {
                $out[] = $t;
            }
            $i = $end + 1;
        }
        return $out;
    }

    /**
     * 判定 + 执行单个循环的「轻量注入」。
     *
     * 大循环（静态迭代数 > 阈值）：循环条件 / 步进 / 循环体里每轮都会求值的字面量，
     * 换成**只由运算符与字面量组成**的等价式（`(20000^0)`、`(0+7)`、`'ab'.'cd'`）。
     * PHP 编译期对整个表达式做常量折叠，运行期一条指令都不多；源码里也不再是裸字面量
     * ——混淆形态保留，但没有任何函数调用被迭代放大。
     *
     * 小循环：原样不动，交给随后的常规变换施加重积木（几十轮，放大效应可忽略）。
     *
     * @param array<int,mixed> $seg 循环自身（从关键字开始到语句结束）
     */
    private function applyHoist(array $seg, array $loop, array $outer, int $outerIdx): array
    {
        $est = $this->estimateIterations($seg, $loop);
        if ($est !== null && $est <= $this->hoistThreshold) {
            return $seg;
        }
        $found = $this->collectHoistable($seg, $this->hoistRanges($seg, $loop));
        if ($found === []) {
            return $seg;
        }
        $out = [];
        foreach ($seg as $i => $tok) {
            if (!isset($found[$i])) {
                $out[] = $tok;
                continue;
            }
            foreach ($this->cheapLiteralTokens($found[$i]) as $t) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 轻量恒等的 token 形态。
     *
     * 约束：只允许**常量折叠级**的构造——纯字面量、算术 / 位运算、字符串拼接。
     * 绝不出现函数调用（那正是每轮重算的根源）。生成的 token 打上 [3]=true 标记，
     * 后续常规变换看到标记就跳过，避免被再次展开成重积木。
     *
     * @param array{type:string,value:string,raw:string} $info
     * @return array<int,mixed>
     */
    private function cheapLiteralTokens(array $info): array
    {
        if ($info['type'] === 'i') {
            $v = (int) $info['value'];
            if ($v <= 0) {
                return $this->markCheap($this->exprTokens($info['raw']));
            }
            $forms = [
                '(' . $v . '^0)',
                '(0+' . $v . ')',
                '((' . $v . ')|0)',
                '(0x' . dechex($v) . '^0)',
            ];
            $idx = abs(crc32($v . '|' . $this->constSeed)) % count($forms);
            return $this->markCheap($this->exprTokens($forms[$idx]));
        }
        $s = $info['value'];
        // 纯可打印、无单引号 / 反斜杠 / 插值 → 对半拆成两段拼接（编译期折叠成同一个常量）
        if ($s !== '' && strlen($s) >= 2 && preg_match('/^[\x20-\x7e]+$/', $s)
            && !str_contains($s, "'") && !str_contains($s, '\\') && !str_contains($s, '$')) {
            $cut = strlen($s) >= 4 ? intdiv(strlen($s), 2) : 1;
            $expr = "'" . substr($s, 0, $cut) . "' . '" . substr($s, $cut) . "'";
            return $this->markCheap($this->exprTokens($expr));
        }
        // 含转义 / 控制字节 / 引号的串：保持原字面量原样（值绝对正确，只是形态不变）
        return $this->markCheap([$info['raw']]);
    }

    /**
     * 给 token 打「已是轻量形态」标记，阻止 transformRange 再次展开。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private function markCheap(array $tokens): array
    {
        foreach ($tokens as $k => $t) {
            if (is_array($t)) {
                $tokens[$k] = [$t[0], $t[1], $t[2] ?? 0, true];
            }
        }
        return $tokens;
    }

    /** 前置语句能否直接落在循环前：只有语句边界才安全，否则要包块 */
    private function needHoistBlock(array $tokens, int $i): bool
    {
        $p = Parser::prevSignificant($tokens, $i);
        if ($p < 0) {
            return false;
        }
        $t = $tokens[$p];
        if (is_array($t)) {
            return !in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true);
        }
        return !in_array($t, [';', '{', '}', ':'], true);
    }

    /** 需要检查（并可能被外提）的区间：每轮求值的那几段 */
    private function hoistRanges(array $tokens, array $loop): array
    {
        $r = [];
        // foreach 的表达式只在循环开始前求值一次，不需要搬
        if ($loop['kind'] !== 'foreach' && $loop['condStart'] >= 0) {
            $r[] = [$loop['condStart'], $loop['condEnd']];
        }
        if ($loop['kind'] === 'for' && $loop['stepStart'] >= 0) {
            $r[] = [$loop['stepStart'], $loop['stepEnd']];
        }
        $r[] = [$loop['bodyStart'], $loop['bodyEnd']];
        return $r;
    }

    /**
     * 收集区间内可外提的字面量。
     * @param array<int,mixed> $tokens
     * @param array<int,array{0:int,1:int}> $ranges
     * @return array<int,array{type:string,value:string,raw:string}>
     */
    private function collectHoistable(array $tokens, array $ranges): array
    {
        $n = count($tokens);
        // 常量上下文状态必须从头单遍推进，位置才有意义
        $ctx = new LogicCtx();
        $constAt = [];
        $declareAt = [];
        for ($i = 0; $i < $n; $i++) {
            $ctx->step($tokens, $i);
            $constAt[$i] = $ctx->isConstContext();
            $declareAt[$i] = $ctx->inDeclare();
        }
        $skip = $this->nestedScopeRanges($tokens);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $s = max(0, $s);
            $e = min($n - 1, $e);
            for ($i = $s; $i <= $e; $i++) {
                $tok = $tokens[$i];
                if (!is_array($tok)) {
                    continue;
                }
                if ($declareAt[$i] || $constAt[$i]) {
                    continue;
                }
                if ($this->inAnyRange($i, $skip)) {
                    continue;
                }
                if ($tok[0] === T_LNUMBER) {
                    if (!empty($tok[3]) || $this->isJumpLevelOperand($tokens, $i)) {
                        continue;
                    }
                    $v = $this->intLiteralValue($tok[1]);
                    if ($v === null || $v === 0) {
                        continue;
                    }
                    $out[$i] = ['type' => 'i', 'value' => (string) $v, 'raw' => $tok[1]];
                    continue;
                }
                if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                    if (!empty($tok[3])) {
                        continue;
                    }
                    if ($this->strMaxLen > 0 && strlen($tok[1]) > $this->strMaxLen) {
                        continue;
                    }
                    $v = $this->stripQuotes($tok[1]);
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $out[$i] = ['type' => 's', 'value' => $v, 'raw' => $tok[1]];
                }
            }
        }
        return $out;
    }

    /** 函数体 / 闭包体 / 类体区间——里面的字面量不在循环里逐轮执行，且搬出去会跨作用域 */
    private function nestedScopeRanges(array $tokens): array
    {
        $n = count($tokens);
        $ranges = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_FN) {
                $ranges[] = [$i, $this->fnArrowEnd($tokens, $i)];
                continue;
            }
            if (!in_array($t[0], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                if (!(defined('T_ENUM') && $t[0] === T_ENUM)) {
                    continue;
                }
            }
            // `Foo::class` 的 T_CLASS 后面不会有 `{`，会在 `;` 处分流跳过
            if ($t[0] === T_CLASS) {
                $p = Parser::prevSignificant($tokens, $i);
                if ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_DOUBLE_COLON) {
                    continue;
                }
            }
            $depth = 0;
            $open = -1;
            for ($j = $i + 1; $j < $n; $j++) {
                $tk = $tokens[$j];
                if (is_string($tk)) {
                    if ($tk === '(') {
                        $depth++;
                    } elseif ($tk === ')') {
                        if ($depth > 0) {
                            $depth--;
                        }
                    } elseif ($tk === '{' && $depth === 0) {
                        $open = $j;
                        break;
                    } elseif ($tk === ';' && $depth === 0) {
                        break;
                    }
                }
            }
            if ($open < 0) {
                continue;
            }
            $close = $this->matchBracket($tokens, $open);
            if ($close > $open) {
                $ranges[] = [$open, $close];
            }
        }
        return $ranges;
    }

    /** 箭头函数 `fn(...) => expr` 的结束位置（同层的 , / ) / ; / ] 之前） */
    private function fnArrowEnd(array $tokens, int $i): int
    {
        $n = count($tokens);
        $depth = 0;
        $saw = false;
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_DOUBLE_ARROW) {
                $saw = true;
                continue;
            }
            if (!$saw || !is_string($t)) {
                continue;
            }
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                if ($depth === 0) {
                    return $j;
                }
                $depth--;
            } elseif (($t === ',' || $t === ';') && $depth === 0) {
                return $j;
            }
        }
        return $n - 1;
    }

    private function inAnyRange(int $i, array $ranges): bool
    {
        foreach ($ranges as $r) {
            if ($i >= $r[0] && $i <= $r[1]) {
                return true;
            }
        }
        return false;
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
