<?php
/**
 * VmCompiler 碎片：控制流编译（if/elseif/else、while、for、foreach、switch、do-while）
 * 与 break/continue、goto/label 的回填辅助。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，
 * 可直接访问主体的 private 属性 / 方法；拆分的只是源码组织，不影响行为。
 *
 * 依赖（同处一个类体，运行时经 $this 直接调用，不跨文件耦合）：
 *   - compileControlBody / collectBracelessBody / compileExpr / compileExprOrAssign / trimWs
 *     由 VmStmtTrait / VmTokTrait 等提供；
 *   - 共享状态 ctxStack / labelTab / pendingGotos / loopSeq / loopCtx 由主体定义。
 */

declare(strict_types=1);

trait VmControlTrait
{
    private function compileIf(Cursor $c, array &$code): void
    {
        $c->expect(T_IF);
        $c->expect('(');
        $cond = $c->collectUntilTop(')', null, 1);
        $c->expect(')');
        $this->compileExpr($cond, $code);
        $jz = count($code);
        $code[] = [VmOp::JZ, 0]; // 占位
        $this->compileControlBody($c, $code);

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
                $this->compileControlBody($c, $code);
                $jEnds[] = $jmp;
            } elseif (is_array($t) && $t[0] === T_ELSE) {
                $c->next();
                $jmp = count($code);
                $code[] = [VmOp::JMP, 0];
                $this->patch($code, $jz); // 上一轮 JZ 落到此处（else 入口）
                $this->compileControlBody($c, $code);
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
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => $start];
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileControlBody($c, $code);
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
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1]; // 占位：continue 目标 = step 起点，编译后再填
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileControlBody($c, $code);
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
            $valStart = $arrowAt + $vOff;
            // 跳过 => 与值之间的空白 token（如 "$k => $v" 中 "=>" 两侧的空格）：
            // 否则 $vvar 会落到空白 token，得到空/空白变量名，值永远存不进（取出恒为 null）。
            while (isset($rest[$valStart]) && is_array($rest[$valStart]) && $rest[$valStart][0] === T_WHITESPACE) {
                $valStart++;
            }
            $vvar = trim($rest[$valStart][1] ?? '', '$');
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
        // 用 CALL_NATIVE 调用 array_keys（而非 CALL）：array_keys 是编译器内部的取键辅助，
        // 必须恒走原生分发；否则当模块名恰好为 array_keys 时，renameCallTargets 会把这条
        // CALL 误改写成模块自身别名，造成无限递归（OOM / Killed）。
        $code[] = [VmOp::PUSH_V, '$' . $ta];
        $code[] = [VmOp::CALL_NATIVE, $this->natIdx('array_keys'), 1, 0];
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
        $this->compileControlBody($c, $code);
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
        $lid = ++$this->loopSeq;
        $this->loopCtx[$lid] = ['cont' => -1];
        $this->ctxStack[] = ['k' => 'loop', 'id' => $lid];
        $this->compileControlBody($c, $code);
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

    // ========================================================================
    // goto / label
    // ========================================================================
    /**
     * 判定语句是否为标签声明（`name:` 起始）：第一个有效 token 为 T_STRING、
     * 其后（仅空白/注释相隔）为 ':' 字符 token。返回标签名（区分大小写——
     * 实测 `goto MyLabel` 不匹配 `mylabel:`），否则 null。
     * @param array<int,mixed> $stmt
     */
    private function labelName(array $stmt): ?string
    {
        $n = count($stmt);
        $i = 0;
        while ($i < $n && is_array($stmt[$i])
            && in_array($stmt[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }
        if ($i >= $n || !is_array($stmt[$i]) || $stmt[$i][0] !== T_STRING) {
            return null;
        }
        $name = $stmt[$i][1];
        $j = $i + 1;
        while ($j < $n && is_array($stmt[$j])
            && in_array($stmt[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j < $n && $stmt[$j] === ':') {
            return $name;
        }
        return null;
    }

    /**
     * 去掉标签声明语句的 `name:` 前缀，返回其后语句的 token 序列（可能为空）。
     * @param array<int,mixed> $stmt
     * @return array<int,mixed>
     */
    private function stripLabelPrefix(array $stmt): array
    {
        $n = count($stmt);
        $i = 0;
        while ($i < $n && is_array($stmt[$i])
            && in_array($stmt[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }
        $i++; // T_STRING（标签名）
        while ($i < $n && is_array($stmt[$i])
            && in_array($stmt[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }
        if ($i < $n && $stmt[$i] === ':') {
            $i++;
        }
        return array_slice($stmt, $i);
    }

    /**
     * 当前所处 loop/switch 上下文 id 序列（ctxStack 全量——标签/goto 的
     * 「受保护构造链」判定用：标签所在构造链必须是 goto 当前链的子集，
     * 否则即原生「'goto' into loop or switch statement is disallowed」）。
     * @return array<int>
     */
    private function ctxChain(): array
    {
        $out = [];
        foreach ($this->ctxStack as $ctx) {
            $out[] = $ctx['id'];
        }
        return $out;
    }

    /**
     * 作用域收尾：校验并回填本作用域全部 goto 占位 JMP。
     *  - 未定义标签 → fileLevel 回退（原生 fatal：'goto' to undefined label）
     *  - 标签在 loop/switch 内而 goto 不在同一构造内 → fileLevel 回退
     *  - 跳出 try/finally 保护区（finally 须先执行，运行期无该控制类）→ 回退
     *  - 占位/标签已被语句级截断（所在语句 EVAL_STMT 化，goto 已交原生执行）
     *    → 跳过
     * @param array<int,array<int,mixed>> $code
     * @param array<int,array<int,mixed>> $et 本作用域异常表
     */
    private function resolveGotos(array &$code, array $et): void
    {
        $n = count($code);
        foreach ($this->pendingGotos as $g) {
            if ($g['code'] >= $n) {
                continue; // 已被截断（外层语句 EVAL_STMT 化时原生执行）
            }
            $lbl = $this->labelTab[$g['name']] ?? null;
            if ($lbl === null || $lbl['code'] > $n) {
                throw VmUnsupported::fileLevel(
                    "'goto' to undefined label '{$g['name']}'"
                );
            }
            foreach ($lbl['chain'] as $cid) {
                if (!in_array($cid, $g['chain'], true)) {
                    throw VmUnsupported::fileLevel(
                        "'goto' into loop or switch statement is disallowed"
                    );
                }
            }
            foreach ($et as $e) {
                if (($e[3] ?? '') !== 'finally') {
                    continue;
                }
                $srcIn = $g['code'] >= $e[0] && $g['code'] < $e[1];
                $dstIn = $lbl['code'] >= $e[0] && $lbl['code'] < $e[1];
                if ($srcIn && !$dstIn) {
                    throw VmUnsupported::fileLevel(
                        'goto 跳出 try/finally 保护区（finally 须先执行，已回退）'
                    );
                }
            }
            $code[$g['code']][1] = $lbl['code'] - ($g['code'] + 1);
        }
        $this->pendingGotos = [];
        $this->labelTab = [];
    }

    /**
     * 预扫描（源码层）重复标签：原生 parse 期即对同名标签致命
     * （Label 'x' already defined）。深度感知地识别「T_STRING ':'」：
     *  - 只认 () / [] 深度 0 处（排除命名实参 `f(x: 1)` 等）；
     *  - ':' 前有未配对 '?'（同深度）视为三元/elvis 冒号；
     *  - ':' 前序为 ')' 视为返回类型冒号（其后 '?' 是可空标记，不计三元）；
     *  - 排除 `case FOO:` / `enum Suit:` 的冒号。
     * 函数/类/闭包体（独立标签作用域）整体跳过。
     * @param array<int,mixed> $toks
     */
    private function prescanDuplicateLabels(array $toks): void
    {
        $n = count($toks);
        // 跳过函数/类/trait/接口/enum/闭包体（独立标签作用域）
        $skip = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t)) {
                continue;
            }
            $isFn = $t[0] === T_FUNCTION;
            if (!$isFn && !in_array($t[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                continue;
            }
            if ($isFn) {
                $nx = Parser::nextSignificant($toks, $i);
                if ($nx >= 0 && !is_array($toks[$nx]) && $toks[$nx] === '(') {
                    // 匿名闭包：跳到体闭区间
                    $body = $this->findBodyOpen($toks, $nx);
                    if ($body >= 0) {
                        $end = $this->findBodyEnd($toks, $body);
                        $skip[] = [$body, $end];
                        $i = $end;
                    }
                    continue;
                }
                // 命名函数：函数体同样是独立标签作用域，跳过
                $body = $this->findBodyOpen($toks, $i);
                if ($body >= 0) {
                    $end = $this->findBodyEnd($toks, $body);
                    $skip[] = [$body, $end];
                    $i = $end;
                }
                continue;
            }
            $body = $this->findBodyOpen($toks, $i);
            if ($body >= 0) {
                $end = $this->findBodyEnd($toks, $body);
                $skip[] = [$body, $end];
                $i = $end;
            }
        }
        // 深度感知扫描标签声明
        $counts = [];
        $depth = 0; // () 与 [] 合计深度
        $q = 0;     // 深度 0 处待配对的三元/elvis '?' 数
        $skipQ = false; // 返回类型冒号后的可空 '?' 标记
        for ($i = 0; $i < $n; $i++) {
            foreach ($skip as $rng) {
                if ($i >= $rng[0] && $i <= $rng[1]) {
                    continue 2;
                }
            }
            $t = $toks[$i];
            if ($skipQ) {
                $skipQ = false;
                if (is_string($t) && $t === '?') {
                    continue; // 可空返回类型标记，不是三元运算符
                }
            }
            if (is_string($t)) {
                if ($t === '(' || $t === '[') {
                    $depth++;
                    continue;
                }
                if ($t === ')' || $t === ']') {
                    $depth = max(0, $depth - 1);
                    continue;
                }
                if ($depth !== 0) {
                    continue;
                }
                if ($t === '?') {
                    $q++;
                    continue;
                }
                if ($t === ':') {
                    if ($q > 0) {
                        $q--; // 三元/elvis 冒号
                        continue;
                    }
                    $p = Parser::prevSignificant($toks, $i);
                    if ($p >= 0 && $toks[$p] === ')') {
                        $skipQ = true; // 返回类型冒号（function f(): T）
                        continue;
                    }
                    if ($p >= 0 && is_array($toks[$p]) && $toks[$p][0] === T_STRING) {
                        $pp = Parser::prevSignificant($toks, $p, 2);
                        if ($pp >= 0 && is_array($toks[$pp])
                            && in_array($toks[$pp][0], [T_CASE, T_ENUM], true)) {
                            continue; // case FOO: / enum Suit:
                        }
                        $nm = $toks[$p][1];
                        $counts[$nm] = ($counts[$nm] ?? 0) + 1;
                    }
                    continue;
                }
                continue;
            }
        }
        foreach ($counts as $nm => $cnt) {
            if ($cnt > 1) {
                throw VmUnsupported::fileLevel("Label '{$nm}' already defined");
            }
        }
    }
}
