<?php
/**
 * VmCompiler 碎片：语句编译碎片：块 / 各大语句 / 跳转修补 / include 发射。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性/方法；拆分的只是源码组织，不影响行为。
 */

declare(strict_types=1);

trait VmStmtTrait
{

    // ========================================================================
    // 语句块
    // ========================================================================
    /**
     * @param array<int,mixed> $block
     * @param array<int,array<int,mixed>> $code
     */
    private function compileBlock(array $block, array &$code): void
    {
        $stmts = StatementSplitter::split($block);
        foreach ($stmts as $stmt) {
            $this->compileStatement($stmt, $code);
        }
        // 末尾若没有返回，补一个 RET_VOID（顶层/函数都需要一个终止）
    }

    /**
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileStatement(array $stmt, array &$code): void
    {
        // 每条语句边界清除 @ 抑制标志，防止跨语句/跨块串味
        // （如 if (@$a) { include 'x.php'; } 中 @ 只覆盖条件）
        $this->suppressInc = false;
        // 替代语法规范化：`if (c): ... endif;` → `if (c) { ... }`（含嵌套）。
        // 回退路径（EVAL_STMT/detokenize）仍用原始 $stmt——eval 需要合法 PHP，
        // 冒号体原样 detokenize 即合法。
        $origStmt = $stmt;
        $stmt = $this->normalizeAltSyntax($stmt);
        $c = new Cursor($stmt);
        $c->skipWs();
        $t = $c->peek();
        if ($t === null) {
            return;
        }
        // 语句级兜底（EVAL_STMT）：编译该语句途中抛 VmUnsupported 时，不整文件回退，
        // 而是把【整条语句的源码文本】压栈、发射 EVAL_STMT 交运行期 eval 执行。
        // 先记录发射进度标记，失败时截断半成品指令，保证字节码自洽。
        $mark = count($code);
        try {
            $this->compileStatementInner($stmt, $c, $code);
        } catch (VmUnsupported $e) {
            array_splice($code, $mark);
            // 控制流逃逸守卫：return/yield/goto/break/continue/static/global 经
            // eval 兜底后语义会被静默破坏（eval 的 return 不从外层函数返回、
            // static 不跨调用持久也不与帧变量互通、break 出 eval 直接致命）。
            // 这类语句重抛 VmUnsupported，由壳层整文件回退到未 VM 化代码
            // （保留其它层混淆，输出正确、损失该文件的 VM 保护）。
            if ($this->hasControlEscape($origStmt)) {
                throw $e;
            }
            // 类作用域引用守卫：self:: / static:: / parent:: 经 eval 兜底会直接
            // 致命（"Cannot access self when no class scope is active"）——VM 类
            // 本就不是真实 PHP 类，eval 里同样访问不到。这类语句重抛走整文件回退，
            // 至少保证输出正确（曾有 self::$log[] = $m 回退后运行期致命的案例）。
            if ($this->hasClassScopeRef($origStmt)) {
                throw $e;
            }
            // VM 类名引用守卫：语句文本里出现本程序声明的类名（C::$x / new C /
            // C::m() / C::CONST …）时同理——eval 里这些类不存在，兜退即致命。
            // 预扫描收集的全集（vmClassNames）覆盖前向引用（PHP 类声明本就被提升）。
            if ($this->referencesVmClass($origStmt)) {
                throw $e;
            }
            $code[] = [VmOp::PUSH_C, $this->detokenize($origStmt)];
            $code[] = [VmOp::EVAL_STMT];
        }
    }

    /**
     * VM 类名引用判定：语句含预扫描登记的类/trait/接口名时为 true。
     * 与 hasClassScopeRef 同一目的：这类语句的 EVAL 兜退必致命，须整文件回退。
     * @param array<int,mixed> $stmt
     */
    private function referencesVmClass(array $stmt): bool
    {
        if ($this->vmClassNames === []) {
            return false;
        }
        foreach ($stmt as $t) {
            if (is_array($t) && $t[0] === T_STRING && isset($this->vmClassNames[$t[1]])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 类作用域引用判定：语句含 self:: / static:: / parent:: 时为 true。
     * 这类语句一旦走 EVAL_STMT 兜退必致命（eval 无类作用域），必须整文件回退。
     * @param array<int,mixed> $stmt
     */
    private function hasClassScopeRef(array $stmt): bool
    {
        $n = count($stmt);
        for ($i = 0; $i < $n; $i++) {
            $t = $stmt[$i];
            if (!is_array($t) || $t[0] !== T_STRING) {
                continue;
            }
            $low = strtolower($t[1]);
            if ($low !== 'self' && $low !== 'static' && $low !== 'parent') {
                continue;
            }
            // 向后跳过空白/注释，下一个有效 token 须为 T_DOUBLE_COLON
            for ($j = $i + 1; $j < $n; $j++) {
                $tk = $stmt[$j];
                if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($tk) && $tk[0] === T_DOUBLE_COLON) {
                    return true;
                }
                break;
            }
        }
        return false;
    }

    /**
     * 控制流逃逸判定：语句将走 EVAL_STMT 兜底时，检查其是否含「经 eval 后
     * 语义被破坏」的构造。EVAL_STMT 运行期模板虽做了「帧变量→局部变量提取
     * →eval→快照 diff 写回帧」的变量桥接，但以下构造的目标在语句之外，
     * 桥接管不到：
     *   - return / yield / yield from：eval 的返回/产出只作用于 eval 自身，
     *     外层函数静默返回 null、生成器收不到产出（实测 try+return 即此坑）；
     *   - goto：标签在文件别处，eval 内执行直接致命；
     *   - break / continue N：层数超过语句内循环开数（或语句内无循环）时，
     *     目标在语句之外，"Cannot break/continue N level(s)" 致命；
     *   - static $var：静态变量绑定在解释器闭包作用域而非 VM 帧，跨调用
     *     不持久、不与帧变量互通（实测计数器恒输出初值）；
     *   - global：eval 内的引用别名只作用于解释器闭包，VM 帧侧拿到的是
     *     值拷贝，写回丢失。
     * 命中即重抛 VmUnsupported，由壳层整文件回退（保留其它层混淆）。
     *
     * 扫描跳过嵌套闭包/匿名类的方法体——其中的 return 等属于它们自己的
     * 作用域，经 eval 定义后语义正确，不算逃逸。
     *
     * @param array<int,mixed> $tokens
     */
    private function hasControlEscape(array $tokens): bool
    {
        $n = count($tokens);
        // 第一遍：标出闭包/匿名类体区间（[体开 '{', 体闭 '}']，含端点）
        $skip = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_FUNCTION) {
                $nx = Parser::nextSignificant($tokens, $i);
                // 匿名闭包：function 后紧跟 '('（命名函数声明不可能嵌在语句里）
                if ($nx >= 0 && !is_array($tokens[$nx]) && $tokens[$nx] === '(') {
                    $body = $this->findBodyOpen($tokens, $nx);
                    if ($body >= 0) {
                        $end = $this->findBodyEnd($tokens, $body);
                        $skip[] = [$body, $end];
                        $i = $end; // 体内部件（含嵌套闭包）一并跳过
                    }
                }
                continue;
            }
            if ($t[0] === T_CLASS) {
                $pv = Parser::prevSignificant($tokens, $i);
                // 匿名类：new class（Foo::class 的前驱是 T_DOUBLE_COLON，不命中）
                if ($pv >= 0 && is_array($tokens[$pv]) && $tokens[$pv][0] === T_NEW) {
                    $body = $this->findBodyOpen($tokens, $i);
                    if ($body >= 0) {
                        $end = $this->findBodyEnd($tokens, $body);
                        $skip[] = [$body, $end];
                        $i = $end;
                    }
                }
                continue;
            }
        }
        // 第二遍：扫描闭包体之外的逃逸构造
        $loops = 0; // 语句内（闭包体外）已出现的循环/switch 开数
        for ($i = 0; $i < $n; $i++) {
            foreach ($skip as $rng) {
                if ($i >= $rng[0] && $i <= $rng[1]) {
                    continue 2;
                }
            }
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            switch ($t[0]) {
                case T_RETURN:
                case T_YIELD:
                case T_YIELD_FROM:
                case T_GOTO:
                case T_GLOBAL:
                    return true;
                case T_STATIC:
                    // 仅 static 声明（static $var = ...）危险；
                    // static:: / static 闭包 / static fn 均不是声明
                    $nx = Parser::nextSignificant($tokens, $i);
                    if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_VARIABLE) {
                        return true;
                    }
                    break;
                case T_BREAK:
                case T_CONTINUE:
                    // break/continue 的目标通常是语句内循环；仅当层数超过
                    // 语句内循环开数（或无循环可跳）时目标才在语句之外
                    $level = 1;
                    $nx = Parser::nextSignificant($tokens, $i);
                    if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_LNUMBER) {
                        $level = max(1, (int) $tokens[$nx][1]);
                    }
                    if ($level > $loops) {
                        return true;
                    }
                    break;
                case T_WHILE:
                case T_FOR:
                case T_FOREACH:
                case T_DO:
                case T_SWITCH:
                    $loops++;
                    break;
            }
        }
        return false;
    }

    /**
     * 从 $fromIdx 起找函数体/类体的开 '{'：先越过参数/继承链的圆括号深度，
     * 再取括号深度 0 处的第一个 '{'。遇深度 0 的 ';' 即止（无函数体）。
     * 插值串的 T_CURLY_OPEN 是数组 token（文本虽为 '{'），不会误配。
     *
     * @param array<int,mixed> $tokens
     */
    private function findBodyOpen(array $tokens, int $fromIdx): int
    {
        $n = count($tokens);
        $pd = 0;
        for ($i = $fromIdx; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                continue; // 字符串/注释/heredoc 内不可能有裸括号
            }
            if ($t === '(' || $t === '[') {
                $pd++;
            } elseif ($t === ')' || $t === ']') {
                if ($pd > 0) {
                    $pd--;
                }
            } elseif ($t === ';') {
                if ($pd === 0) {
                    return -1;
                }
            } elseif ($t === '{' && $pd === 0) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * 从体开 '{'（$openIdx）找配对的体闭 '}'。
     * 插值花括号深度不对称："{$expr}" 的起始是数组 token T_CURLY_OPEN
     * （文本 '{'），闭合却是裸字符 '}'——只数字符花括号会把插值闭合
     * 误判为体结束，必须把 T_CURLY_OPEN/T_DOLLAR_OPEN_CURLY_BRACES
     * 一并计入深度（与 Cursor::untilMatchBrace 等同理）。
     *
     * @param array<int,mixed> $tokens
     */
    private function findBodyEnd(array $tokens, int $openIdx): int
    {
        $n = count($tokens);
        $depth = 1;
        for ($i = $openIdx + 1; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                continue;
            }
            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return $n - 1; // 未闭合：保守跳到末尾（整个语句都在跳过区内）
    }

    /**
     * compileStatement 的原分发逻辑（抽出以便外层统一兜底）。
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileStatementInner(array $stmt, Cursor $c, array &$code): void
    {
        $t = $c->peek();
        if (is_array($t) && $t[0] === T_ECHO) {
            $c->next();
            $this->compileEcho($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_RETURN) {
            $c->next();
            // 处于 try/finally 保护区（且当前不在 finally 块体内）时，return 必须延后：
            // 先存返回值/置控制标志，再跳到 finally 收尾（finally 的 EX_FINALLY_END 才真正返回）。
            // 注意：只有【最内层 try 确有 finally】才需要延后——若无 finally（仅 catch），
            // 正常 return 即可，catch 仅在异常时进入、不会拦截正常返回；否则延后回退的 JMP
            // 因无 finally 可回填而变为 JMP 0 落空，控制流会直接掉进 catch 处理器、破坏返回值。
            $topTry = count($this->tryStack) - 1;
            $deferred = ($topTry >= 0 && !$this->inFinally
                && ($this->tryStack[$topTry]['hasFinally'] ?? false));
            if ($c->is(';') || $c->eof() || $this->nextIsBlockEnd($stmt)) {
                if ($deferred) {
                    $code[] = [VmOp::PUSH_C, 2];
                    $code[] = [VmOp::POP_V, "\x01ctl"];
                    $this->emitJumpToFinally($code);
                } else {
                    $code[] = [VmOp::RET_VOID];
                }
                return;
            }
            $expr = $c->untilTerminator(';');
            if ($deferred) {
                $this->compileExpr($expr, $code);
                $code[] = [VmOp::POP_V, "\x01retv"];
                $code[] = [VmOp::PUSH_C, 1];
                $code[] = [VmOp::POP_V, "\x01ctl"];
                $this->emitJumpToFinally($code);
            } else {
                // 引用返回函数（function &g()）：return 前把返回目标绑到
                // 桥接槽 "\x01rr"（CALL_RR 调用后据此把目标引用绑到调用方
                // 帧槽——PHP: $r=&g() 后 $r=9 改的是 g 内的 $s）。
                // 目标限「简单变量 / 变量+下标属性链」，其余形态抛
                // VmUnsupported 由语句级回退保底（值返回降级）。
                if ($this->curReturnsRef) {
                    $this->emitReturnRefBridge($expr, $code);
                }
                $this->compileExpr($expr, $code);
                $code[] = [VmOp::RET];
            }
            return;
        }
        if (is_array($t) && $t[0] === T_IF) {
            $this->compileIf($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_WHILE) {
            $this->compileWhile($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_FOR) {
            $this->compileFor($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_FOREACH) {
            $this->compileForeach($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_SWITCH) {
            $this->compileSwitch($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_DO) {
            $this->compileDoWhile($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_TRY) {
            $this->compileTry($c, $code);
            return;
        }
        if (is_array($t) && in_array($t[0], [T_BREAK, T_CONTINUE], true)) {
            $c->next();
            $this->applyLoopJump($t[0] === T_BREAK ? 'break' : 'continue', $code, $c);
            return;
        }
        if (is_array($t) && $t[0] === T_UNSET) {
            $c->next();
            $this->compileUnset($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_GLOBAL) {
            $c->next();
            $this->compileGlobal($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_STATIC) {
            // 函数/方法内的 `static $x = expr;` 静态局部变量声明（仅在函数作用域内处理；
            // 顶层 static 或 static function/fn 闭包不在此列，继续走常规链→EVAL_STMT 兜底）。
            $nx = Parser::nextSignificant($stmt, $c->pos());
            if ($this->curScopeId !== '' && $nx >= 0 && is_array($stmt[$nx]) && $stmt[$nx][0] === T_VARIABLE) {
                $this->compileStaticLocal($c, $code);
                return;
            }
        }
        if (is_array($t) && $t[0] === T_CONST) {
            $this->compileConst($stmt, $code);
            return;
        }
        if (in_array($t[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            // 语句级 include（非赋值右值）：发射包含逻辑后丢弃返回值
            $c->next();
            $this->emitInclude($t[0], $c, $code);
            $code[] = [VmOp::DROP];
            return;
        }
        if (is_array($t) && $t[0] === T_EXIT) {
            $c->next();
            $this->compileExit($c, $code);
            return;
        }
        if (is_array($t) && $t[0] === T_PRINT) {
            $c->next();
            $expr = $c->untilTerminator(';');
            $this->compileExpr($expr, $code);
            $code[] = [VmOp::PRINT_];
            $code[] = [VmOp::DROP];
            return;
        }
        if (is_array($t) && $t[0] === T_THROW) {
            $c->next();
            $expr = $c->untilTerminator(';');
            $this->compileExpr($expr, $code);
            $code[] = [VmOp::THROW];
            return;
        }
        // 赋值语句 / 表达式语句
        $this->compileExprOrAssign($stmt, $code);
    }

    /**
     * 编译 `static $x = expr;`（可多个，逗号分隔）静态局部变量声明。
     *
     * PHP 语义：static 局部变量跨函数调用保持值——首次执行到声明时求值一次并保存，
     * 后续调用保留旧值（不再求值）；无默认值者为 null。作用域为整个函数/方法体。
     *
     * 实现（复用 GLOBAL_REF 共享帧机制，无需新 opcode / Program 表 / 序列化格式）：
     *  - 每次调用都 GLOBAL_REF 把帧槽位绑到共享帧（跨调用持久）；
     *  - 带默认值时用一次性初始化守卫（flag 槽位）保证默认表达式只求值一次；
     *  - 变量读写/自增经 VmExprTrait::varName() 路由到该共享槽位。
     * 触发条件：compileStatementInner 仅在 $this->curScopeId 非空（函数/方法作用域）时分发至此。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function compileStaticLocal(Cursor $c, array &$code): void
    {
        $c->next(); // 消费 T_STATIC
        while (true) {
            $c->skipWs();
            if (!$c->is(T_VARIABLE)) {
                break;
            }
            $var = $c->expectText(T_VARIABLE); // 含 $ 前缀
            $mangled = $this->staticLocalName($this->curScopeId, $var);
            // 登记：本函数内该变量的读写/自增统一路由到共享槽位
            $this->curStatic[$var] = $mangled;
            // 每次调用重新绑定共享帧槽位（跨调用保持）
            $code[] = [VmOp::GLOBAL_REF, $mangled];
            $c->skipWs();
            if ($c->is('=')) {
                $c->next();
                $default = $c->collectUntilTop(';', ',');
                $flag = $mangled . "\x01i";
                $code[] = [VmOp::GLOBAL_REF, $flag];
                // flag 为真（已初始化）则跳过；否则求值默认表达式一次并置位 flag
                $code[] = [VmOp::PUSH_V, $flag];
                $jz = count($code);
                $code[] = [VmOp::JNZ, 0];
                $this->compileExpr($default, $code);
                $code[] = [VmOp::POP_V, $mangled];
                $code[] = [VmOp::PUSH_C, true];
                $code[] = [VmOp::POP_V, $flag];
                $code[$jz][1] = count($code) - ($jz + 1);
            }
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
                continue;
            }
            break;
        }
        $c->skipWs();
        if ($c->is(';')) {
            $c->next();
        }
    }

    /**
     * 引用返回函数（function &g()）的 return <expr> 桥接插桩：
     * 把返回目标绑到桥接槽 "\x01rr"——CALL_RR 调用返回后据此把目标
     * 引用绑到调用方帧槽（PHP: $r=&g() 后 $r=9 改的是 g 内的 $s）。
     * 目标限「简单变量 / 变量+下标属性链」，复用引用赋值链式桥接；
     * 其余形态（常量/运算/调用结果）抛 VmUnsupported，语句级回退保底。
     *
     * @param array<int,mixed> $expr
     * @param array<int,array<int,mixed>> $code
     */
    private function emitReturnRefBridge(array $expr, array &$code): void
    {
        $sig = $this->trimWs($expr);
        if ($sig === []) {
            throw new VmUnsupported('VM 引用返回的 return 无目标（已回退）');
        }
        $first = $sig[0];
        if (!(is_array($first) && $first[0] === T_VARIABLE)) {
            throw new VmUnsupported('VM 引用返回的目标必须是变量（已回退）');
        }
        if (count($sig) === 1) {
            // 简单变量：直接绑桥接槽（帧槽名过 varName 路由 static 局部等）
            $code[] = [VmOp::MAKE_REF, "\x01rr", $this->varName($first[1])];
            return;
        }
        // 变量 + 后缀链（$a[0] / $this->p ...）：复用链式桥接，末级 dst=桥接槽
        $this->compileRefAssignRHS(new Cursor($sig), "\x01rr", $code);
    }

    // ========================================================================
    // try / catch / finally（异常真编译）
    // ========================================================================
    /**
     * 编译 try/catch/finally。
     *
     * 布局（异常表条目见下方注释）：
     *   [try 体]                        保护区间 [tryStart, tryEnd)
     *   JMP normalJmp                  正常完成 → 跳过 catch 处理器、进 finally/收尾
     *   [catch1 体]  JMP catchEndJmp    仅经异常表路由进入；末尾跳到 finally（或收尾）
     *   [catch2 体]  JMP catchEndJmp
     *   ...
     *   [finally 体]  EX_FINALLY_ENTER / 体 / EX_FINALLY_END   有 finally 时存在
     *   [收尾点]
     *
     * 异常表条目：[tryStart, end, handlerPc, kind, type, varName]
     *   catch  : end = tryEnd（仅 try 体被 catch 捕获）；type 为异常类型（'' 表任意 Throwable）
     *   finally: end = catchesEnd（覆盖 try 体 + 全部 catch，保证 catch 内抛出也先跑 finally）；
     *            handlerPc = finally 块起始（pending 待重抛异常置位后入 finally）。
     *
     * finally 内 return / catch 内未捕获异常 / try 体未捕获异常 的语义：均先执行 finally，
     * 由 EX_FINALLY_END 统一收尾（按 $__pending / $__ctl 决定重抛或返回）。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function compileTry(Cursor $c, array &$code): void
    {
        $c->expect(T_TRY);
        $c->expect('{');
        $tryBody = $c->untilMatchBrace();

        // 先解析（消费）catch 与 finally 结构，确定是否存在 finally —— 保护区内的 return
        // 需据此决定是否延后（finally 必须先行）。解析阶段只收 token，不编译。
        $catches = [];
        while (true) {
            $c->skipWs();
            $t = $c->peek();
            if (!is_array($t) || $t[0] !== T_CATCH) {
                break;
            }
            $c->next();
            $c->expect('(');
            $types = $this->parseCatchTypes($c);
            $varName = '';
            $c->skipWs();
            $vt = $c->peek();
            if (is_array($vt) && $vt[0] === T_VARIABLE) {
                $varName = '$' . ltrim($vt[1], '$');
                $c->next();
            }
            $c->expect(')');
            $c->expect('{');
            $cb = $c->untilMatchBrace();
            $catches[] = ['types' => $types, 'varName' => $varName, 'body' => $cb];
        }
        $c->skipWs();
        $ft = $c->peek();
        $hasFinally = is_array($ft) && $ft[0] === T_FINALLY;
        $finallyBody = null;
        if ($hasFinally) {
            $c->next();
            $c->expect('{');
            $finallyBody = $c->untilMatchBrace();
        }

        // 推入异常控制栈：保护区内的 return 据此延后到 finally（仅当 hasFinally）
        $this->tryStack[] = ['finallyPc' => null, 'retJmps' => [], 'hasFinally' => $hasFinally];

        $tryStart = count($code);
        $this->compileBlock($tryBody, $code);
        $tryEnd = count($code);

        // 正常完成落点（跳过 catch 处理器）
        $normalJmp = count($code);
        $code[] = [VmOp::JMP, 0];

        // catch 处理器（仅经异常表路由进入；正常路径不会落入）
        $catchJmps = [];
        foreach ($catches as $catch) {
            $catchPc = count($code);
            $this->compileBlock($catch['body'], $code);
            if ($catch['types'] === []) {
                $this->curSegET[] = [$tryStart, $tryEnd, $catchPc, 'catch', '', $catch['varName']];
            } else {
                foreach ($catch['types'] as $ty) {
                    $this->curSegET[] = [$tryStart, $tryEnd, $catchPc, 'catch', $ty, $catch['varName']];
                }
            }
            $catchJmps[] = count($code);
            $code[] = [VmOp::JMP, 0];
        }
        $catchesEnd = count($code);

        $top = count($this->tryStack) - 1;
        if ($hasFinally) {
            $finallyPc = count($code);
            // finally 表项区间 [tryStart, finallyPc)：覆盖 try 体 + 全部 catch（保证 catch
            // 内抛出也先跑 finally），但【排除 finally 自身执行体】——否则 finally 末尾
            // EX_FINALLY_END 重抛 pending 时会被【同一】 finally 表项再次捕获，造成死循环 /
            // finally 被吞。重抛点（pc=finallyPc 起）已落在表项区间外，自然由【外层】 handler 接管。
            $this->curSegET[] = [$tryStart, $finallyPc, $finallyPc, 'finally', ''];
            // 回填：正常落 JMP / 各 catch 末尾 JMP / 延后返回 JMP → finally 起始
            $this->patch($code, $normalJmp);
            foreach ($catchJmps as $jj) {
                $this->patch($code, $jj);
            }
            foreach ($this->tryStack[$top]['retJmps'] as $rj) {
                $this->patch($code, $rj);
            }
            $this->tryStack[$top]['finallyPc'] = $finallyPc;
            $this->inFinally = true;
            $code[] = [VmOp::EX_FINALLY_ENTER];
            $this->compileBlock($finallyBody, $code);
            $code[] = [VmOp::EX_FINALLY_END];
            $this->inFinally = false;
        } else {
            // 无 finally：正常落点 / catch 末尾 → 收尾（catch 之后），等价于顺序落下
            $this->patch($code, $normalJmp);
            foreach ($catchJmps as $jj) {
                $this->patch($code, $jj);
            }
        }
        array_pop($this->tryStack);
    }

    /**
     * 解析 catch 的类型列表（支持单类型与联合类型 A|B 及命名空间前缀 \Ns\T）。
     * 调用方已消费 '('；本方法消费类型 token，停在 ')' 或变量前。
     * @return array<int,string>
     */
    private function parseCatchTypes(Cursor $c): array
    {
        $types = [];
        $nameToks = [T_STRING, T_NS_SEPARATOR, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];
        while (true) {
            $c->skipWs();
            $t = $c->peek();
            if (!is_array($t) || !in_array($t[0], $nameToks, true)) {
                break;
            }
            $name = '';
            while (true) {
                $c->skipWs();
                $nt = $c->peek();
                if (is_array($nt) && in_array($nt[0], $nameToks, true)) {
                    $name .= $nt[1];
                    $c->next();
                } else {
                    break;
                }
            }
            $types[] = $name;
            $c->skipWs();
            $nt = $c->peek();
            if (is_string($nt) && $nt === '|') {
                $c->next();
                continue;
            }
            break;
        }
        return $types;
    }

    /**
     * 延后返回：当前 try/finally 保护区内的 return 不能直接 RET，须先跳到 finally 收尾。
     * 保存返回控制（ctl=1 带值 / ctl=2 void）后，把 JMP 占位记入最内层 tryStack 项，
     * 待 finally 起始 pc 确定后由 compileTry 统一回填。
     * @param array<int,array<int,mixed>> $code
     */
    private function emitJumpToFinally(array &$code): void
    {
        $top = count($this->tryStack) - 1;
        $jmp = count($code);
        $code[] = [VmOp::JMP, 0];
        if ($top >= 0) {
            $this->tryStack[$top]['retJmps'][] = $jmp;
        }
    }

    /**
     * 发射一条 include/require 逻辑：
     *  - 计划项带 embed id（构建期已静态解析并嵌入）→ INCLUDE_EMBED；
     *  - 计划项标记循环包含 → 压 true（视为已包含过）；
     *  - 其余（动态路径 / 目标不存在）→ 编译路径表达式 + INCLUDE_RT 运行期真实包含。
     * @param array<int,array<int,mixed>> $code
     */
    private function emitInclude(int $kw, Cursor $c, array &$code): void
    {
        $once = in_array($kw, [T_INCLUDE_ONCE, T_REQUIRE_ONCE], true);
        $require = in_array($kw, [T_REQUIRE, T_REQUIRE_ONCE], true);
        // @include 的诊断抑制（parseUnary 置位，这里取用即清）
        $suppress = $this->suppressInc;
        $this->suppressInc = false;
        $entry = $this->nextIncludeEntry();
        if ($entry !== null && $entry['cycle']) {
            // 构建期发现的循环包含：PHP include_once 的务实行为——直接跳过
            $this->collectIncludePath($c);   // 消费路径 token，避免残余 token 触发回退
            $code[] = [VmOp::PUSH_C, true];
            return;
        }
        if ($entry !== null && $entry['embed'] !== null) {
            $this->collectIncludePath($c);   // 消费路径 token，避免残余 token 触发回退
            $code[] = [VmOp::INCLUDE_EMBED, (int) $entry['embed'], $once ? 1 : 0];
            return;
        }
        // 运行期 include：路径表达式按普通表达式编译（值留栈），再发 INCLUDE_RT。
        // 目标文件在构建期不存在时也走此路——运行期由 PHP 原生产生
        // warning（include）/ fatal error（require），与原生语义一致。
        $expr = $this->collectIncludePath($c);
        $this->compileExpr($expr, $code);
        $flags = ($once ? 1 : 0) | ($require ? 2 : 0) | ($suppress ? 4 : 0);
        $code[] = [VmOp::INCLUDE_RT, $flags];
    }

    /**
     * 收集 include 的路径表达式 token：白名单式收集（字面串/变量/函数调用/
     * __DIR__/__FILE__/插值串/下标/括号/连接点），遇到路径表达式不可能出现的
     * token（三元、比较、逻辑运算、语句分隔符等）即停——兼容语句级
     * （include 'x.php';）与表达式级（$v = include 'x.php' ?: 'd';）两种上下文。
     * @return array<int,mixed>
     */
    private function collectIncludePath(Cursor $c): array
    {
        $out = [];
        $depth = 0;
        while (!$c->eof()) {
            $t = $c->peek();
            if (is_string($t)) {
                if ($t === '(' || $t === '[') {
                    $depth++;
                    $out[] = $c->next();
                    continue;
                }
                if ($t === ')' || $t === ']') {
                    if ($depth === 0) {
                        break;   // 收尾上一层上下文
                    }
                    $depth--;
                    $out[] = $c->next();
                    continue;
                }
                if ($depth === 0 && ($t === ';' || $t === ',' || $t === '?')) {
                    break;
                }
                if ($depth === 0 && $t === '.') {
                    $out[] = $c->next();
                    continue;
                }
                break;   // 其他字符（运算符等）不属于路径表达式
            }
            $ok = in_array($t[0], [
                T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
                T_VARIABLE, T_STRING, T_DIR, T_FILE, T_LNUMBER, T_DNUMBER,
                T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_STRING_VARNAME, T_NUM_STRING,
                T_START_HEREDOC, T_END_HEREDOC,
            ], true);
            if (!$ok) {
                break;
            }
            $out[] = $c->next();
        }
        return $out;
    }

    private function compileEcho(Cursor $c, array &$code): void
    {
        $c->skipWs();
        while (!$c->is(';') && !$c->eof()) {
            $expr = $c->collectUntilTop(',', ';');
            $this->compileExpr($expr, $code);
            $code[] = [VmOp::ECHO];
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
                $c->skipWs();
            }
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileIf(Cursor $c, array &$code): void
    {
        $c->expect(T_IF);
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($cond, $code);
        $jz = count($code);
        $code[] = [VmOp::JZ, 0]; // 占位
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $this->compileBlock($body, $code);

        $jEnds = []; // 跳到结尾的 JMP 占位（then/else 分支结束处）
        $hasElse = false;
        while (true) {
            $c->skipWs();
            $t = $c->peek();
            if ($t === null) {
                break;
            }
            if (is_array($t) && $t[0] === T_ELSEIF) {
                $c->next();
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $this->patch($code, $jz); // 上一轮 JZ 落到此处（elseif 入口）
                $c->expect('(');
                // initPdepth=1：调用方已消费 '('，否则条件含内层括号对时会在第一个内层 ) 处提前停止，
                // 收集到残缺表达式（如 `$c===(1-0`），compileExpr 递归时期望 ) 但已到末尾而抛错。
                $ec = $c->collectUntilTop(')', null, 1);
                $c->expect(')');
                $this->compileExpr($ec, $code);
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
                $c->expect('{');
                $b2 = $c->untilMatchBrace();
                $this->compileBlock($b2, $code);
                $jEnds[] = $jmp;
            } elseif (is_array($t) && $t[0] === T_ELSE) {
                $c->next();
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $this->patch($code, $jz); // 上一轮 JZ 落到此处（else 入口）
                $c->expect('{');
                $b3 = $c->untilMatchBrace();
                $this->compileBlock($b3, $code);
                $jEnds[] = $jmp;
                $hasElse = true;
                break;
            } else {
                break;
            }
        }
        // 没有 else/elseif 时，JZ 直接跳到整段末尾；有 else 时循环内已把 JZ 指向 else 入口，
        // 此处若再 patch 会把它错误地覆写为末尾，导致条件为假时整个跳过 else（这正是原 bug）。
        if (!$hasElse) {
            $this->patch($code, $jz);
        }
        foreach ($jEnds as $j) {
            $this->patch($code, $j);
        }
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileWhile(Cursor $c, array &$code): void
    {
        $c->expect(T_WHILE);
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $start = count($code);
        // 空条件（while(;;)）恒为真：不能走 compileExpr（空 token 会压 null 成假，循环体永不执行）
        if ($this->trimWs($cond) === []) {
            $code[] = [VmOp::PUSH_C, 1];
        } else {
            $this->compileExpr($cond, $code);
        }
        $jz = count($code);
        $code[] = [VmOp::JZ, 0];
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => $start];
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $code[] = [VmOp::JMP, $start - (count($code) + 1)];
        $this->patch($code, $jz);
        $this->patchBreaks($lid, $code);
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileFor(Cursor $c, array &$code): void
    {
        $c->expect(T_FOR);
        $c->expect('(');
        $init = $c->collectUntilTop(';', null);
        $c->expect(';');
        $cond = $c->collectUntilTop(';', null);
        $c->expect(';');
        // initPdepth=1：调用方已消费 '('；step 含内层括号对（如 foo($x)）时，
        // pdepth=0 会在第一个内层 ) 处提前停止，导致 step 被截断。
        $step = $c->collectUntilTop(')', null, 1);
        $c->expect(')');

        // 注意：for 的初始化段可能是赋值（如 $i=0），必须用 compileExprOrAssign，
        // 不能用 compileExpr（后者走 parseTernary 链，不识别 '='，会把赋值右值吞掉）。
        if ($init !== []) {
            $this->compileExprOrAssign($init, $code);
        }
        $start = count($code);
        // 空条件（for(;;)）恒为真：不能走 compileExpr（空 token 会压 null 成假，循环体永不执行）
        if ($this->trimWs($cond) === []) {
            $code[] = [VmOp::PUSH_C, 1];
        } else {
            $this->compileExpr($cond, $code);
        }
        $jz = count($code);
        $code[] = [VmOp::JZ, 0];
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1]; // 占位：continue 目标 = step 起点，编译后再填
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $contIdx = count($code); // L_cont：step 起点（continue 先自增再循环）
        $this->loopCtx[$lid]['cont'] = $contIdx;
        if ($this->trimWs($step) !== []) {
            $this->compileExpr($step, $code);
        }
        $code[] = [VmOp::JMP, $start - (count($code) + 1)];
        $this->patch($code, $jz);
        $this->patchContinues($lid, $code);
        $this->patchBreaks($lid, $code);
    }

    /**
     * @param array<int,array<int,mixed>> $code
     */
    private function compileForeach(Cursor $c, array &$code): void
    {
        $c->expect(T_FOREACH);
        $c->expect('(');
        $src = $c->collectUntilTop(T_AS, null);
        $c->expect(T_AS);
        // 键/值（initPdepth=1：调用方已消费 '('，防止 list($a,$b) 等内层括号对导致提前停止）
        $rest = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $rest = $this->trimWs($rest);
        // 分析 $k => $v 或 $v（注意：PHP 把 => 解析为单 token T_DOUBLE_ARROW，而非 '=' '>' 两个）。
        // 关键：解构模式内部的 "k"=>$x 也含 =>，必须忽略括号/方括号内的 =>，只认顶层箭头。
        $arrowAt = -1;
        $pdepth = 0;
        $bdepth = 0;
        foreach ($rest as $i => $t) {
            if (is_string($t)) {
                if ($t === '[') { $bdepth++; continue; }
                if ($t === ']') { $bdepth = max(0, $bdepth - 1); continue; }
                if ($t === '(') { $pdepth++; continue; }
                if ($t === ')') { $pdepth = max(0, $pdepth - 1); continue; }
            }
            if ($pdepth === 0 && $bdepth === 0) {
                if (is_array($t) && $t[0] === T_DOUBLE_ARROW) {
                    $arrowAt = $i;
                    break;
                }
                if (is_string($t) && $t === '=' && isset($rest[$i + 1]) && is_string($rest[$i + 1]) && $rest[$i + 1] === '>') {
                    $arrowAt = $i;
                    break;
                }
            }
        }
        if ($arrowAt >= 0) {
            $kvar = trim($rest[0][1] ?? '', '$');
            // 值的位置：T_DOUBLE_ARROW 是单 token → 值在 arrowAt+1；'=''>' 两 token → arrowAt+2
            $arrowTok = $rest[$arrowAt];
            $vOff = (is_array($arrowTok) && $arrowTok[0] === T_DOUBLE_ARROW) ? 1 : 2;
            $vvar = trim($rest[$arrowAt + $vOff][1] ?? '', '$');
            $valStart = $arrowAt + $vOff;
            // 跳过 => 与值之间的空白 token，避免把空白误判为值/解构起始
            while (isset($rest[$valStart]) && is_array($rest[$valStart]) && $rest[$valStart][0] === T_WHITESPACE) {
                $valStart++;
            }
            $hasKey = true;
        } else {
            $kvar = null;
            $vvar = trim($rest[0][1] ?? '', '$');
            $valStart = 0;
            $hasKey = false;
        }
        // 值位置是否为 list/数组解构模式：[...] 或 list(...)
        $valTokens = array_slice($rest, $valStart);
        $firstVal = $valTokens[0] ?? null;
        $isList = (is_array($firstVal) && $firstVal[0] === T_LIST)
               || (is_string($firstVal) && $firstVal === '[');
        // 值位引用遍历：foreach($a as &$v) / foreach($a as $k => &$v)。
        // （list 内引用 PHP 本身非法，与 $isList 互斥。）
        $refByRef = false;
        if (!$isList) {
            $vt = $this->trimWs($valTokens);
            if ($vt !== [] && $this->isAmpFollowedByVar($vt[0])) {
                $vn = $vt[1] ?? null;
                if (!(is_array($vn) && $vn[0] === T_VARIABLE)) {
                    throw new VmUnsupported('VM 引用 foreach 值位必须是简单变量（已回退）');
                }
                $vvar = trim($vn[1], '$');
                $refByRef = true;
            }
        }
        if ($refByRef) {
            // 引用遍历要求源是简单变量（帧槽位别名；$this->arr / $a[0] / f() 等
            // 非简单源回退原生执行，保写穿正确）。
            $srcT = $this->trimWs($src);
            if (!(count($srcT) === 1 && is_array($srcT[0]) && $srcT[0][0] === T_VARIABLE
                && $srcT[0][1] !== '$GLOBALS')) {
                throw new VmUnsupported('VM 引用 foreach 源必须是简单变量（已回退）');
            }
        }
        $ta = '__fe' . ($this->tempSeq++);
        $tk = '__fe' . ($this->tempSeq++);
        $ti = '__fe' . ($this->tempSeq++);

        if ($refByRef) {
            // $ta 与源变量别名（引用同一数组）：迭代内经 MAKE_REF_EL 把 $v 别名为
            // 当前元素，获得与 PHP 完全一致的即时写穿语义。
            $code[] = [VmOp::MAKE_REF, '$' . $ta, $this->varName($srcT[0][1])];
        } else {
            // $ta = src（值拷贝）
            $this->compileExpr($src, $code);
            $code[] = [VmOp::POP_V, '$' . $ta];
        }
        // $tk = array_keys($ta); $ti = 0;
        $code[] = [VmOp::PUSH_V, '$' . $ta];
        $code[] = [VmOp::CALL, 'array_keys', 1];
        $code[] = [VmOp::POP_V, '$' . $tk];
        $code[] = [VmOp::PUSH_C, 0];
        $code[] = [VmOp::POP_V, '$' . $ti];

        $start = count($code);
        // if ($ti >= count($tk)) goto end
        $code[] = [VmOp::PUSH_V, '$' . $ti];
        $code[] = [VmOp::PUSH_V, '$' . $tk];
        $code[] = [VmOp::COUNT];
        $code[] = [VmOp::GE];
        $jz = count($code);
        // 当 $ti >= count($tk) 为真（非 0）时跳到循环末尾：用 JNZ
        $code[] = [VmOp::JNZ, 0];
        // 先解析出当前迭代的“键” $tk[$ti]（无论是否用 $k，值都要用真实键取）
        $keyTmp = '__fe' . ($this->tempSeq++);
        $code[] = [VmOp::PUSH_V, '$' . $tk];
        $code[] = [VmOp::PUSH_V, '$' . $ti];
        $code[] = [VmOp::INDEX_GET];
        $code[] = [VmOp::POP_V, '$' . $keyTmp];
        if ($hasKey) {
            // $k 直接就是解析出的键 $tk[$ti]，不要再对 $ta 做索引
            $code[] = [VmOp::PUSH_V, '$' . $keyTmp];
            $code[] = [VmOp::POP_V, '$' . $kvar];
        }
        // $v = $ta[ 当前键 ]（注意 $ta 是关联数组，必须用真实键而非整数下标 $ti）
        $valueTmp = $isList ? ('__fe' . ($this->tempSeq++)) : $vvar;
        if ($refByRef) {
            // 引用遍历：$v = &$ta[当前键]（每次迭代重新别名；写穿源数组）。
            $code[] = [VmOp::PUSH_V, '$' . $keyTmp];
            $code[] = [VmOp::MAKE_REF_EL, '$' . $vvar, '$' . $ta];
        } else {
            $code[] = [VmOp::PUSH_V, '$' . $ta];
            $code[] = [VmOp::PUSH_V, '$' . $keyTmp];
            $code[] = [VmOp::INDEX_GET];
            $code[] = [VmOp::POP_V, '$' . $valueTmp];
        }
        if ($isList) {
            // list/数组解构：把 $valueTmp 逐元素分发到目标变量（支持位置/带键/嵌套）
            $this->emitListDestruct(new Cursor($valTokens), '$' . $valueTmp, $code);
        }

        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1]; // 占位：continue 目标 = $ti++ 起点
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $contIdx = count($code); // continue 目标 = $ti++ 起点（先自增再循环）
        $this->loopCtx[$lid]['cont'] = $contIdx;
        // $ti++
        $code[] = [VmOp::PUSH_V, '$' . $ti];
        $code[] = [VmOp::PUSH_C, 1];
        $code[] = [VmOp::ADD];
        $code[] = [VmOp::POP_V, '$' . $ti];
        $code[] = [VmOp::JMP, $start - (count($code) + 1)];
        $this->patch($code, $jz);
        $this->patchContinues($lid, $code);
        $this->patchBreaks($lid, $code);
    }

    /**
     * 编译 list/数组解构目标（foreach 的 `[$a,$b]` / `["k"=>$a]` / 嵌套形态）。
     * 调用前光标应停在值 token 序列起始（第一个有效 token 为 '[' 或 T_LIST）。
     * 消费完整的 `[...]` / `list(...)` 并返回；逐元素从 $srcVar 读取值并赋给目标变量。
     */
    private function emitListDestruct(Cursor $c, string $srcVar, array &$code): void
    {
        $c->skipWs();
        if ($c->is(T_LIST)) {
            $c->next();
            $c->expect('(');
        } else {
            $c->expect('[');
        }
        $pos = 0;
        while (true) {
            $c->skipWs();
            if ($c->eof()) {
                break;
            }
            if ($c->is(']') || $c->is(')')) {
                $c->next();
                break;
            }
            if ($c->is(',')) {
                $c->next();
                $pos++;
                continue;
            }
            // 带键元素：KEY => TARGET（KEY 为字符串/数字/标识符字面量）
            $key = null;
            $k0 = $c->peekSig();
            $k1 = $c->peekSigAt(1);
            if ($k0 !== null && is_array($k0)
                && in_array($k0[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_STRING], true)
                && $k1 !== null && is_array($k1) && $k1[0] === T_DOUBLE_ARROW) {
                $key = $this->constTokenText($k0);
                $c->next();          // KEY
                $c->skipWs();
                $c->expect(T_DOUBLE_ARROW);
            }
            $c->skipWs();
            if ($c->is('[') || $c->is(T_LIST)) {
                // 嵌套解构：先把当前元素读到临时变量，再递归
                $subVar = '__fe' . ($this->tempSeq++);
                // 注意：dstVar 必须带 '$' 前缀，与后续递归读该临时变量的键名保持一致
                //（否则会写成 $__vars['__fe5'] 而无 $ 前缀，导致读侧 $__vars['$__fe5'] 取不到）。
                $this->emitReadKey($srcVar, $key ?? $pos, '$' . $subVar, $code);
                $this->emitListDestruct($c, '$' . $subVar, $code);
            } else {
                $varName = trim($c->expectText(T_VARIABLE), '$');
                $c->next();
                $this->emitReadKey($srcVar, $key ?? $pos, '$' . $varName, $code);
            }
            if ($key === null) {
                $pos++;
            }
            $c->skipWs();
            if ($c->is(',')) {
                $c->next();
            }
        }
    }

    /** 发射「$dstVar = $srcVar[$key]」（位置下标或字符串键皆可） */
    private function emitReadKey(string $srcVar, $key, string $dstVar, array &$code): void
    {
        $code[] = [VmOp::PUSH_V, $srcVar];
        $code[] = [VmOp::PUSH_C, $key];
        $code[] = [VmOp::INDEX_GET];
        $code[] = [VmOp::POP_V, $dstVar];
    }

    /** 解构键字面量 token → 字符串/数字值（去引号、反转义） */
    private function constTokenText(array $tok): string
    {
        if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            return stripcslashes(substr($tok[1], 1, -1));
        }
        return (string) $tok[1];
    }

    /**
     * switch / case / default。
     *
     * 展开为「比较链 + 落体穿透」：
     *   检查case_i -> 命中则落入 body_i，body_i 末尾 JMP 到 body_{i+1}（穿透语义）；
     *   未命中则 JZ 跳到下一个 case 的检查；default 放在最后；break 跳到 switch 末尾。
     * @param array<int,array<int,mixed>> $code
     */
    private function compileSwitch(Cursor $c, array &$code): void
    {
        $c->expect(T_SWITCH);
        $c->expect('(');
        $subj = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $c->expect('{');
        $inner = $c->untilMatchBrace();

        // 按 T_CASE / T_DEFAULT 切分（只认顶层，忽略嵌套 switch 的 case）
        $segs = [];
        $cur = null;   // ['kind'=>'case'|'default','val'=>tokens,'body'=>tokens]
        $depth = 0;
        $pendingVal = false;  // case 的值还在收集
        $pendingTerm = false; // 标签终止符（':' 或 ';'）还未消费
        foreach ($inner as $tok) {
            $isCase = is_array($tok) && $tok[0] === T_CASE && $depth === 0;
            $isDefault = is_array($tok) && $tok[0] === T_DEFAULT && $depth === 0;
            if (is_string($tok)) {
                if ($tok === '{') { $depth++; }
                elseif ($tok === '}') { $depth = max(0, $depth - 1); }
            } elseif (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }
            if (($isCase || $isDefault) && $depth === 0) {
                if ($cur !== null) { $segs[] = $cur; }
                $cur = ['kind' => $isCase ? 'case' : 'default', 'val' => [], 'body' => []];
                $pendingVal = $isCase; // case 值位于 ':' / ';' 之前
                $pendingTerm = true;   // default 的 ':' 也必须被吞掉，否则会漏进 body
                continue;
            }
            if ($cur === null) {
                continue; // switch 体内的杂项（注释等）
            }
            if ($pendingTerm && is_string($tok) && ($tok === ':' || $tok === ';')) {
                $pendingTerm = false;
                $pendingVal = false;
                continue;
            }
            if ($pendingVal) { $cur['val'][] = $tok; } else { $cur['body'][] = $tok; }
        }
        if ($cur !== null) { $segs[] = $cur; }

        // 主题表达式求值一次，存进临时变量
        $sw = '__sw' . ($this->tempSeq++);
        $this->compileExpr($subj, $code);
        $code[] = [VmOp::POP_V, '$' . $sw];

        $sid = ++$this->swSeq;
        $this->ctxStack[] = ['k' => 'switch', 'id' => $sid];

        // 每个 case：检查段 + 体段；体段末尾 JMP 到下一个体段（穿透）
        $checks = [];      // 每条：JZ 占位下标（default 为 -1）
        $checkStarts = []; // 每条：检查段起点下标（default 与其体段同点）
        $bodyStarts = [];
        $jmpSlots = [];    // 每条：穿透 JMP 的占位下标（最后一条跳到 end）
        foreach ($segs as $i => $seg) {
            if ($seg['kind'] === 'case') {
                $checkStarts[$i] = count($code);
                $code[] = [VmOp::PUSH_V, '$' . $sw];
                $this->compileExpr($this->trimWs($seg['val']), $code);
                $code[] = [VmOp::EQ];
                $jz = count($code);
                $code[] = [VmOp::JZ, 0];
            } else {
                // default：无条件落到下一段（其检查段为空）
                $jz = -1;
                $checkStarts[$i] = count($code);
            }
            $bodyStarts[$i] = count($code);
            $this->compileBlock($seg['body'], $code);
            $jmp = count($code);
            $code[] = [VmOp::JMP, 0];
            $checks[$i] = $jz;
            $jmpSlots[$i] = $jmp;
        }
        $end = count($code);
        array_pop($this->ctxStack);

        // 回填：未命中 -> 下一个 case 的检查段起点（JZ 之后才是其体，不能用 JZ 下标）；
        // 下一个是 default 则直接落进其体；之后 -> end
        foreach ($checks as $i => $jz) {
            if ($jz < 0) { continue; }
            $target = $end;
            if (isset($checks[$i + 1])) {
                $target = $checks[$i + 1] >= 0 ? ($checkStarts[$i + 1] ?? $checks[$i + 1]) : $bodyStarts[$i + 1];
            }
            $code[$jz][1] = $target - ($jz + 1);
        }
        // 穿透：body_i 末尾 -> body_{i+1} 起点（最后一条 -> end）
        foreach ($jmpSlots as $i => $jmp) {
            $target = isset($bodyStarts[$i + 1]) ? $bodyStarts[$i + 1] : $end;
            $code[$jmp][1] = $target - ($jmp + 1);
        }
        // switch 内的 break -> end
        $this->patchSwitchBreaks($sid, $code, $end);
    }

    private int $swSeq = 0;

    /**
     * do { body } while (cond);
     * 语义：先执行体，再判定条件，真则回到体首。
     * @param array<int,array<int,mixed>> $code
     */
    private function compileDoWhile(Cursor $c, array &$code): void
    {
        $c->expect(T_DO);
        $start = count($code);
        $c->expect('{');
        $body = $c->untilMatchBrace();
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1];
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileBlock($body, $code);
        array_pop($this->ctxStack);
        $contIdx = count($code);
        $this->loopCtx[$lid]['cont'] = $contIdx;
        $c->skipWs();
        if (is_array($c->peek()) && $c->type() === T_WHILE) {
            $c->next();
        }
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($cond, $code);
        $code[] = [VmOp::JNZ, $start - (count($code) + 1)];
        $this->patchContinues($lid, $code);
        $this->patchBreaks($lid, $code);
    }

    /**
     * unset($a) / unset($a['k']) / unset($a, $b)
     * @param array<int,array<int,mixed>> $code
     */
    private function compileUnset(Cursor $c, array &$code): void
    {
        $c->skipWs();
        $c->expect('(');
        $all = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        foreach ($this->splitTopLevel($all, ',') as $arg) {
            $arg = $this->trimWs($arg);
            if ($arg === []) { continue; }
            [$name, $keys] = $this->parseIssetTarget($arg);
            $n = count($keys);
            foreach ($keys as $k) { $this->compileExpr($k, $code); }
            $code[] = [VmOp::UNSET_, $name, $n];
        }
    }

    /**
     * global $a, $b; —— 把当前帧的变量槽位绑定为「共享全局帧」的引用。
     *
     * VM 的帧变量表默认是每帧独立的普通数组（值语义），与 PHP 的全局符号表不通。
     * 这里为每个 global 变量发射 GLOBAL_REF：运行期把 $__vars[$name] 变成
     * $__gframe[$name] 的引用，此后 PUSH_V/POP_V/++/-- 全部自然穿透到全局帧，
     * 与 PHP 的 global 语义一致（读写共享、函数返回后仍有效）。
     * 顶层帧与全局帧的同步由运行期在「顶层帧发起调用」的边界上完成（见 RuntimeGenerator）。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function compileGlobal(Cursor $c, array &$code): void
    {
        $c->skipWs();
        while (!$c->eof() && !$c->is(';')) {
            if ($c->is(T_VARIABLE)) {
                $name = $c->expectText(T_VARIABLE);
                $this->globalNames[ltrim($name, '$')] = true;
                $code[] = [VmOp::GLOBAL_REF, $name];
                $c->skipWs();
                if ($c->is(',')) {
                    $c->next();
                    $c->skipWs();
                    continue;
                }
                break;
            }
            // global &$x 之类非常规形式：跳过该 token 继续尝试
            $c->next();
            $c->skipWs();
        }
        $c->skipWs();
        if ($c->is(';')) {
            $c->next();
        }
    }

    /**
     * const NAME = expr; / const A = 1, B = 2; —— 运行期 define()。
     * 常量在 PHP 里是运行期实体，编译期 defined() 不可见，故发射 DEFINE 操作码；
     * 引用处按预扫描收集的常量名发射 CONST_FETCH。
     *
     * @param array<int,mixed> $stmt
     * @param array<int,array<int,mixed>> $code
     */
    private function compileConst(array $stmt, array &$code): void
    {
        $c = new Cursor($stmt);
        $c->skipWs();
        $c->expect(T_CONST);
        // 整条取到语句尾，再按顶层逗号拆分：const A = 1; / const A = 1, B = 2;
        $rest = $c->untilTerminator(';');
        foreach ($this->splitTopLevel($rest, ',') as $part) {
            $part = $this->trimWs($part);
            if ($part === []) {
                continue;
            }
            $pc = new Cursor($part);
            $pc->skipWs();
            if (!$pc->is(T_STRING)) {
                continue; // 非常规形式：跳过该常量定义
            }
            $pname = $pc->expectText(T_STRING);
            $this->constNames[$pname] = true;
            $pc->skipWs();
            if (!$pc->is('=')) {
                continue;
            }
            $pc->next();
            $this->compileExpr($pc->collectUntilEnd(), $code);
            $code[] = [VmOp::DEFINE, $pname];
        }
    }

    /**
     * exit / die：exit; exit(1); die('msg');
     * @param array<int,array<int,mixed>> $code
     */
    private function compileExit(Cursor $c, array &$code): void
    {
        $c->skipWs();
        if ($c->is('(')) {
            $c->next();
            $args = $this->splitTopLevel($c->collectUntilTop(')', null, 1), ',');
            $c->expect(')');
            $args = array_values(array_filter($args, fn ($a) => $this->trimWs($a) !== []));
            if ($args === []) {
                $code[] = [VmOp::EXIT_VOID];
                return;
            }
            $this->compileExpr($this->trimWs($args[0]), $code);
            $code[] = [VmOp::EXIT_];
            return;
        }
        $code[] = [VmOp::EXIT_VOID];
    }

    /**
     * 解析 isset / empty / unset 的「变量 + 键路径」目标，如 $a['k']['j']。
     * @param array<int,mixed> $tokens
     * @return array{0:string,1:array<int,array<int,mixed>>}
     */
    private function parseIssetTarget(array $tokens): array
    {
        $t = $tokens[0] ?? null;
        if (!is_array($t) || $t[0] !== T_VARIABLE) {
            throw new VmUnsupported('VM 仅支持变量形式的 isset/empty/unset 目标（已回退）');
        }
        $name = $t[1];
        $keys = [];
        $i = 1;
        while ($i < count($tokens)) {
            if (($tokens[$i] ?? null) === '[') {
                $depth = 1; $i++; $buf = [];
                while ($i < count($tokens) && $depth > 0) {
                    $tk = $tokens[$i];
                    if ($tk === '[') { $depth++; }
                    elseif ($tk === ']') { $depth--; if ($depth === 0) { $i++; break; } }
                    $buf[] = $tk; $i++;
                }
                $keys[] = $this->trimWs($buf);
                continue;
            }
            if (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_OBJECT_OPERATOR) {
                // $a->b 形式：把属性名当作常量键
                $i++;
                $p = $tokens[$i] ?? null;
                $pname = is_array($p) ? $p[1] : (string) $p;
                $keys[] = [is_array($p) ? [T_CONSTANT_ENCAPSED_STRING, "'" . $pname . "'"] : "'" . $pname . "'"];
                $i++;
                continue;
            }
            break;
        }
        return [$name, $keys];
    }

    /**
     * 回填 switch 内 break 的跳转目标（switch 末尾）。
     * @param array<int,array<int,mixed>> $code
     */
    private function patchSwitchBreaks(int $sid, array &$code, int $end): void
    {
        $keep = [];
        foreach ($this->pendingSwBreaks as $b) {
            if ($b['sw'] === $sid) {
                $code[$b['idx']][1] = $end - ($b['idx'] + 1);
            } else {
                $keep[] = $b;
            }
        }
        $this->pendingSwBreaks = $keep;
    }

    /** @var array<int,array{cont:int}> loopId => 该循环的 continue 目标指令索引 */
    private array $loopCtx = [];
    private int $loopSeq = 0;

    /**
     * 回填一条跳转指令的偏移：target - (占位索引 + 1)。
     * （运行时取指后 pc 已指向下一条，故偏移需相对 `占位索引 + 1` 计算。）
     * @param array<int,array<int,mixed>> $code
     */
    private function patch(array &$code, int $idx): void
    {
        $code[$idx][1] = count($code) - ($idx + 1);
    }

    /**
     * break / continue，支持可选的层级参数（break N / continue N）。
     *
     * 历史 bug：原先虽消费了层级数字却从不使用，`break 2` 被当成 `break 1`——
     * 在 `while(true){switch(...){...break 2;...}}` 状态机里只跳出 switch，
     * 外层 while 永远无法退出，运行期死循环。
     *
     * 语义：
     *  - break N：从最内层【可中断结构】（switch 或 loop）向外数第 N 层，跳到其末尾；
     *  - continue N：只数【循环】（switch 不算 continue 目标），跳到该循环的 cont 点。
     * 层数超出栈深度时忽略（PHP 会 fatal，这里选择宽容）。
     *
     * @param array<int,array<int,mixed>> $code
     */
    private function applyLoopJump(string $kind, array &$code, Cursor $c): void
    {
        $m = count($code);
        // 解析可选的层级数字
        $level = 1;
        if ($c->is(T_LNUMBER)) {
            $lt = $c->next();
            $lv = is_array($lt) ? (int) $lt[1] : 1;
            if ($lv > 0) {
                $level = $lv;
            }
        }
        if ($kind === 'break') {
            // break N：跳过最内层 N-1 个结构，落到第 N 层结构的末尾
            $idx = count($this->ctxStack) - $level;
            $top = $idx >= 0 ? $this->ctxStack[$idx] : null;
            if ($top === null) {
                // 层数超出 / 不在任何可中断结构内：忽略（顶层 break 已在 VirtualMachine 层跳过）
            } elseif ($top['k'] === 'switch') {
                $code[] = [VmOp::JMP, 0];
                $this->pendingSwBreaks[] = ['idx' => $m, 'sw' => $top['id']];
            } else {
                $code[] = [VmOp::JMP, 0];
                $this->pendingBreaks[] = ['idx' => $m, 'lid' => $top['id']];
            }
        } else {
            // continue 只作用于循环（switch 不构成 continue 目标）
            $found = 0;
            $lid = null;
            for ($i = count($this->ctxStack) - 1; $i >= 0; $i--) {
                if ($this->ctxStack[$i]['k'] === 'loop') {
                    $found++;
                    if ($found === $level) {
                        $lid = $this->ctxStack[$i]['id'];
                        break;
                    }
                }
            }
            if ($lid === null) {
                // 不在循环内 / 层数超出：忽略
            } else {
                $code[] = [VmOp::JMP, 0];
                // continue 先记录占位，待循环体编译完、cont 目标确定后再回填（支持 for/foreach 的自增步）
                $this->pendingContinues[] = ['idx' => $m, 'lid' => $lid];
            }
        }
        if ($c->is(';')) {
            $c->next();
        }
    }

    /** @var array<int,array{idx:int,lid:int}> */
    private array $pendingBreaks = [];

    /** @var array<int,array{idx:int,lid:int}> */
    private array $pendingContinues = [];

    /**
     * 循环结束时，把该循环内所有 break 占位回填到结尾。
     * @param array<int,array<int,mixed>> $code
     */
    private function patchBreaks(int $lid, array &$code): void
    {
        $end = count($code);
        $keep = [];
        foreach ($this->pendingBreaks as $b) {
            if ($b['lid'] === $lid) {
                $code[$b['idx']][1] = $end - ($b['idx'] + 1);
            } else {
                $keep[] = $b;
            }
        }
        $this->pendingBreaks = $keep;
    }

    /**
     * 循环结束时，把该循环内所有 continue 占位回填到自增/自增步位置。
     * @param array<int,array<int,mixed>> $code
     */
    private function patchContinues(int $lid, array &$code): void
    {
        $keep = [];
        foreach ($this->pendingContinues as $b) {
            if ($b['lid'] === $lid) {
                $cont = $this->loopCtx[$lid]['cont'];
                $code[$b['idx']][1] = $cont - ($b['idx'] + 1);
            } else {
                $keep[] = $b;
            }
        }
        $this->pendingContinues = $keep;
    }
}
