<?php
/**
 * VmCompiler 碎片：闭包 / 箭头函数编译。
 *
 * 本文件是 VmCompiler 主体（src/Vm/Compiler.php）按职责拆出的 trait 碎片之一，
 * 由主体文件顶部 require_once 加载。trait 与主体类同处一个类体，可直接访问主体的
 * private 属性/方法。
 *
 * 为什么必须真编译而不是语句级 EVAL_STMT 兜底：
 *   - EVAL_STMT 把整条语句交回原生 eval，那条语句的内容（含闭包体）等于没被 VM 保护，
 *     静态分析直接从产物里读回明文；
 *   - 闭包是 ThinkPHP 里最常见的结构（Db::transaction(fn)、Cache::remember(fn)、
 *     array_map(fn)、事件回调、中间件链），一旦整条语句回退，VM 覆盖率会塌掉一大片；
 *   - 语句里出现闭包时还常带着 return / 类名引用（hasControlEscape / hasClassScopeRef
 *     守卫会命中），那就不再是"语句回退"而是"整文件回退"，损失更大。
 *
 * 实现方式：把闭包体当作一个【匿名函数段】编译进同一 Program 的 funcs 表
 * （合成名 "\x01c<n>"，与普通函数同构：funcParams / funcET 都登记），
 * 再由 MAKE_CLOSURE 在运行期包装成真实 PHP Closure（体是字节码，不是 PHP 源码）。
 */

declare(strict_types=1);

trait VmClosureTrait
{
    /**
     * 编译闭包（T_FUNCTION 无名字形式）或箭头函数（T_FN）。
     *
     * 调用方光标应停在 `function` / `fn` 关键字（或其前的 `static`）上。
     *
     * @param array<int,array<int,mixed>> $code
     * @param bool $arrow    true=箭头函数（fn (...) => expr）
     * @param bool $isStatic true=static 闭包（不绑定 $this）
     */
    private function parseClosure(Cursor $c, array &$code, bool $arrow, bool $isStatic = false): void
    {
        $c->skipWs();
        if ($arrow) {
            $c->expect(T_FN);
        } else {
            $c->expect(T_FUNCTION);
            // 引用返回（function &(...)）：闭包的引用返回在 VM 里按普通返回处理
            // （值语义），不因这个 & 解析失败；真实的 &$f = &$closure() 极少见。
            $c->skipWs();
            if ($this->isAmpNotFollowedByVar($c->peek()) || $c->is('&')) {
                $c->next();
            }
        }

        $c->expect('(');
        $params = $this->parseParams($c);
        $c->expect(')');

        // ---- 捕获列表 use ($a, &$b) ----
        $capNames = [];
        $capRefs = [];
        if (!$arrow) {
            $c->skipWs();
            if ($c->is(T_USE)) {
                $c->next();
                $c->expect('(');
                while (true) {
                    $c->skipWs();
                    if ($c->is(')') || $c->eof()) {
                        break;
                    }
                    $byRef = false;
                    $t = $c->peek();
                    if ($this->isAmpNotFollowedByVar($t) || $c->is('&')) {
                        $c->next();
                        $byRef = true;
                    }
                    $c->skipWs();
                    $t = $c->peek();
                    if (!is_array($t) || $t[0] !== T_VARIABLE) {
                        throw new VmUnsupported('VM 闭包 use 列表只支持变量捕获');
                    }
                    $capNames[] = $t[1];
                    $capRefs[] = $byRef;
                    $c->next();
                    if ($c->is(',')) {
                        $c->next();
                        continue;
                    }
                    break;
                }
                $c->expect(')');
            }
        }

        // ---- 函数体 ----
        if ($arrow) {
            $c->expect(T_DOUBLE_ARROW);
            $bodyToks = $this->collectArrowBody($c);
        } else {
            // 跳过返回类型（: type），直到函数体开括号
            while (!$c->is('{') && !$c->eof()) {
                $c->next();
            }
            $c->expect('{');
            $bodyToks = $c->untilMatchBrace();
        }

        // ---- 编译为匿名函数段 ----
        $name = "\x01c" . (++$this->tempSeq);

        // 作用域状态整体换新（闭包有独立帧：静态局部变量、goto/标签、异常控制栈都不共享），
        // 编译完必须逐项还原，否则外层后续语句会串到闭包的状态里。
        $savedScope    = $this->curScopeId;
        $savedStatic   = $this->curStatic;
        $savedRetRef   = $this->curReturnsRef;
        $savedSegSel   = $this->curSegSel;
        $savedTryStack = $this->tryStack;
        $savedInFin    = $this->inFinally;
        $savedLabels   = $this->labelTab;
        $savedGotos    = $this->pendingGotos;

        $this->curScopeId = $name;
        $this->curStatic = [];
        $this->curReturnsRef = false;
        $this->bindSeg("f\x00" . $name);
        $this->tryStack = [];
        $this->inFinally = false;
        $this->labelTab = [];
        $this->pendingGotos = [];

        $bc = [];
        $this->bindParams($bc, $params);
        // 实例方法序章同款：把 $this 显式落进帧槽，闭包体里的 $this->x 才走 PROPGET
        // （否则 PUSH_V '$this' 读到空槽 → 属性读成 null）。static 闭包无 $this 绑定。
        if (!$isStatic) {
            $bc[] = [VmOp::LOAD_THIS];
            $bc[] = [VmOp::POP_V, '$this'];
        }
        if ($arrow) {
            $this->parseExpr($bodyToks, $bc);
            $bc[] = [VmOp::RET];
        } else {
            $this->prescanDuplicateLabels($bodyToks);
            $this->emitStaticPreamble($bodyToks, $bc);
            $this->compileBlock($bodyToks, $bc);
            $this->resolveGotos($bc, $this->funcET[$name] ?? []);
        }
        $this->funcs[$name] = $bc;
        // 参数规格（默认值段）须在还原作用域之前生成：默认值表达式里可能出现 $this / self::
        $this->funcParams[$name] = $this->paramSpecs($params);

        // 还原外层作用域（异常表引用按选择器重新指向，见 bindSeg 注释）
        $this->curScopeId = $savedScope;
        $this->curStatic = $savedStatic;
        $this->curReturnsRef = $savedRetRef;
        $this->tryStack = $savedTryStack;
        $this->inFinally = $savedInFin;
        $this->labelTab = $savedLabels;
        $this->pendingGotos = $savedGotos;
        $this->bindSeg($savedSegSel);

        // ---- 发射创建指令 ----
        // 箭头函数：名字位传 '*'，运行期按值捕获当前帧全部变量（PHP 箭头函数自动
        // 捕获所引用的外层变量；超集捕获不改变可见语义，只在帧里多几个未用槽位）。
        if ($arrow) {
            $names = '*';
            $flags = '';
        } else {
            $names = implode(',', $capNames);
            $flags = implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $capRefs));
        }
        $code[] = [VmOp::MAKE_CLOSURE, $name, $names, $flags, $isStatic ? 1 : 0];
    }

    /**
     * 收集箭头函数的结果表达式 token（光标位于 `=>` 之后）。
     *
     * 结束条件：括号/方括号/花括号全部归零时的 `,` `)` `]` `}` `;`（均不消费）。
     * 这里不能用 collectUntilTop：它只能盯着【单一】停止符，而箭头函数体会在
     * 外层任意一种分隔符处结束（array_map(fn()=>1, $a) / [fn()=>1] / $x = fn()=>1;）。
     *
     * @return array<int,mixed>
     */
    private function collectArrowBody(Cursor $c): array
    {
        $out = [];
        $pd = 0;
        $bd = 0;
        $d = 0;
        while (!$c->eof()) {
            $t = $c->peek();
            if (is_string($t)) {
                if ($pd === 0 && $bd === 0 && $d === 0
                    && ($t === ',' || $t === ')' || $t === ']' || $t === '}' || $t === ';')) {
                    break;
                }
                if ($t === '(') {
                    $pd++;
                } elseif ($t === ')') {
                    $pd = max(0, $pd - 1);
                } elseif ($t === '[') {
                    $bd++;
                } elseif ($t === ']') {
                    $bd = max(0, $bd - 1);
                } elseif ($t === '{') {
                    $d++;
                } elseif ($t === '}') {
                    $d = max(0, $d - 1);
                }
            } elseif (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $d++;
            }
            $out[] = $c->next();
        }
        if ($out === []) {
            throw new VmUnsupported('VM 箭头函数体为空');
        }
        return $out;
    }
}
