<?php
/**
 * 类保留 VM（ClassPreservingVm）
 *
 * 问题：自定义字节码 VM 把类声明编译成 stdClass + META 的"VM 类"，运行期不是真实
 * PHP 类 —— 这会破坏一切依赖反射 / `class_exists` / `new $class` 的框架
 * （ThinkPHP8 的自动加载、依赖注入、路由反射均如此）。
 *
 * 解决：对"含类声明"的文件，保留【真实的 PHP 类声明】（签名原文不动，仅把方法体换成
 * VM 委托），同时把原 VM 类字节码保留在运行期，由真实类方法委托执行。这样：
 *   - `class_exists('app\controller\Shop')` / `new Shop()` / 反射 / DI 全部照常工作；
 *   - 每个方法体逻辑仍被 VM 字节码保护（不可读、不可直接调试）；
 *   - 类内部的 self / static / parent / 属性访问 / `$this->method()` 全部走真实对象，
 *     由运行期"真实类优先"分支路由回 VM 方法字节码。
 *
 * 注入点（对 buildArtifact 产出的运行时做最小化修改）：
 *   1. NEWOBJ 增加"真实类清单"分支：清单内的类用 ReflectionClass 实例化真实类；
 *   2. 运行期把 `$___RUN___` 与解码后的类字节码暴露到 $GLOBALS，供全局委托函数调用；
 *   3. 文件顶层发射真实类桩（带命名空间 / use / extends / 属性 / 常量 / 原文方法签名），
 *      方法体统一委托到全局 `__awa_vmmethod()`。
 *
 * 已知边界（不影响 TP8 常用写法，已在教程中说明）：
 *   - 方法返回引用（function &foo）暂按值返回；
 *   - 方法形参按引用传参（&$x）在委托层无法回写（PHP8 func_get_args 返回副本）；
 *   - 类上的 final / abstract 修饰符与类级 #[属性] 暂不在桩中复刻（方法级不需要）。
 */

declare(strict_types=1);

final class ClassPreservingVm
{
    /** 判断源码是否含顶层类/接口/trait 声明（决定是否需要类保留模式）。 */
    public static function hasClasses(string $code): bool
    {
        $toks = self::tokenizeOffsets($code);
        $depth = 0;
        foreach ($toks as $t) {
            $type = is_array($t) ? $t[0] : null;
            if ($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT) {
                // 顶层（不在函数/类体内）的类声明才算
                if ($depth === 0) {
                    return true;
                }
            }
            if ($type === T_FUNCTION || $type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT) {
                // 进入函数/类体，depth 在下方括号匹配中处理
            }
            if (is_string($t[1] ?? null) && ($t[1] === '{' || $t[1] === '}')) {
                $depth += ($t[1] === '{') ? 1 : -1;
            }
        }
        return false;
    }

    /**
     * 生成类保留 VM 产物。返回 null 表示"无类 / 不适用"，调用方应回退到普通 VM。
     *
     * @return string|null
     */
    public static function wrap(string $src, array $opts, Salt $salt): ?string
    {
        if (!self::hasClasses($src)) {
            return null;
        }
        // buildArtifact 内部会把源码编译进 VM；若源码带 `<?php` 开闭标签，运行时某些
        // 还原/回退分支会把它当原生源码 eval，导致 "unexpected token <"。故剥离后单独传入。
        // 注意：collectRealClasses / emitStubs 依赖 token_get_all，必须以【带 <?php 的原文】
        // 分词（无开标签时 token_get_all 会把整段当 T_INLINE_HTML，找不到任何类）。
        $srcPlain = preg_replace('/^\s*<\?php/i', '', $src, 1);
        $srcPlain = preg_replace('/\?>\s*$/', '', $srcPlain);

        if (!class_exists(VmRuntimeGenerator::class, false)) {
            require_once __DIR__ . '/RuntimeGenerator.php';
        }
        $rg = new VmRuntimeGenerator($salt);
        try {
            $art = $rg->buildArtifact($srcPlain, $opts);
        } catch (Throwable $e) {
            return null;
        }
        $program = $art['program'];
        if (empty($program['classes'])) {
            return null;
        }

        // 收集真实类：短名 -> FQN（短名用于匹配 program 字节码键；FQN 用于真实实例化与桩）
        $realMap = self::collectRealClasses($src);
        if ($realMap === []) {
            return null;
        }

        // 每文件唯一后缀（uid）：多文件项目里多个类文件会被框架先后 include，若都用固定的
        // 全局函数名 `__awa_vmmethod` 与全局键 `__awa_run`/`__awa_prog`，会触发
        // "Cannot redeclare __awa_vmmethod()" 且后加载文件会覆盖前者的运行期全局，导致先前
        // 类的委托指向错误字节码。故用 uid 派生唯一函数名与全局键，使每个文件自包含、互不干扰。
        // uid 由"类名集合 + 源码"派生：同内容不同类也唯一；同文件重复构建稳定可复现。
        $uid = 'a' . substr(sha1(implode('|', array_keys($realMap)) . '#' . $src), 0, 10);

        $php = $art['php'];
        // buildArtifact 产出的运行时自带 `<?php` 开头；类保留模式会在最外层统一加一个
        // `<?php`，故此处先剥离运行时自带的开放标签，避免文件出现两个 `<?php` 而语法错误。
        $php = preg_replace('/^\s*<\?php/i', '', $php, 1);

        // ① 真实类清单注入为全局（NEWOBJ 真实类分支用）：放在运行时最外层，VM IIFE 内可经
        //    $GLOBALS['__awa_realcls_<uid>'] 读取。用全局变量而非闭包内注入，规避"注入点命中
        //    到 inline native 闭包而非主 IIFE"的歧义。键带 uid，避免多文件互相覆盖。
        $realArr = var_export($realMap, true);
        $php = "\$GLOBALS['__awa_realcls_{$uid}']={$realArr};\n" . $php;

        // ② 暴露运行期到全局，供顶层桩的委托函数调用。
        //    buildArtifact 会对运行时内部变量（$___RUN___/$___P___）做重命名（如改为生僻 CJK
        //    名），故不能写死字面量；改为从主调用行正则提取实际变量名后注入。
        //    - $___RUN___（运行时解释器闭包）→ $GLOBALS['__awa_run_<uid>']
        //    - $___P___（完整 program）→ $GLOBALS['__awa_prog_<uid>']（委托函数需同时取 classes/classET）
        if (preg_match('/\$__vmv=\[\];\$([^\s(\[;]+)\(\$([^\s(\[;]+)\[\'main\'\]/', $php, $m)) {
            $runVar = $m[1];
            $pVar   = $m[2];
            // $___PAD___（参数规格补默认值闭包）同样被重命名。委托函数必须能拿到它：
            // 原生调用方（框架 / 反射 / call_user_func）只传"实际实参"，缺省的尾部参数
            // 由 VM 帧入口的 POP_V 逐个弹出——不补齐就会整体错位（$next 拿到 $request），
            // 表现为 "Object of class Closure could not be converted to string" 之类。
            $padVar = '';
            if (preg_match('/\$([A-Za-z_\x80-\xff][^\s=]*)=function\(\$spec,\$args\)use\(&/', $php, $pm)) {
                $padVar = $pm[1];
            }
            $php = preg_replace_callback(
                '/\$__vmv=\[\];((?:if\(array_key_exists\(\'argv\',\$GLOBALS\)\)\{[^}]*\})?)\$'
                    . preg_quote($runVar, '/') . '\(\$' . preg_quote($pVar, '/') . "\['main'\]/",
                static function (array $mm) use ($runVar, $pVar, $uid, $padVar): string {
                    $padExp = $padVar !== '' ? "\$GLOBALS['__awa_pad_{$uid}']=\$" . $padVar . ';' : '';
                    return $padExp
                        . "\$GLOBALS['__awa_run_{$uid}']=\$" . $runVar
                        . ";\$GLOBALS['__awa_prog_{$uid}']=\$" . $pVar
                        . ";\$__vmv=[];" . $mm[1] . '$' . $runVar . '($' . $pVar . "['main']";
                },
                $php,
                1
            );

            // ③ 修补 NEWOBJ：清单内的类改用 ReflectionClass 实例化真实类。
            //    在 VM 类分支 `if(isset($<pVar>['classes'][$cls])){` 之前插入真实类分支，
            //    使真实类走 ReflectionClass 实例化（保留框架 class_exists / new $class / DI 兼容）。
            //    必须用重命名后的 $pVar 匹配（原 $___P___ 已被重命名）。
            $patchNewObj = "if(isset(\$GLOBALS['__awa_realcls_{$uid}'][\$cls])){"
                . "return (new \\ReflectionClass(\$GLOBALS['__awa_realcls_{$uid}'][\$cls]))->newInstanceArgs(\$args);}"
                . "if(isset(\$" . $pVar . "['classes'][\$cls])){";
            $php = preg_replace(
                '/if\(isset\(\$' . preg_quote($pVar, '/') . "\['classes'\]\[\$cls\]\)\)\{/",
                $patchNewObj,
                $php,
                1
            );
        }

        // ④ 顶层发射真实类桩 + 全局委托函数
        // 结构约束（PHP）：bracketed 命名空间 `namespace X { }` 之后，所有代码必须仍处
        // 于某个命名空间块内。故类桩（各自 namespace 块）在前，委托函数与 VM 运行时统一
        // 包进全局命名空间块 `namespace { ... }`，避免 "No code may exist outside of namespace"。
        $stubs = self::emitStubs($src, $realMap, $uid);

        // 纯全局文件（无任何带命名空间的类）：类桩与运行时都平铺在顶层，绝不能加
        // `namespace { }` —— 括号式命名空间一旦出现，文件里所有代码都必须进块，
        // 平铺的类声明会让 PHP 直接报 "Namespace declaration statement has to be the
        // very first statement"。只有当桩里确实用了 namespace 块时才包全局块。
        $wrapTail = $stubs['blockMode'];
        return "<?php\n" . $stubs['code'] . "\n"
            . ($wrapTail ? "namespace {\n" : '')
            . self::delegateFn($uid) . "\n" . $php . "\n"
            . ($wrapTail ? "}\n" : '');
    }

    /** 全局委托函数（定义于文件顶层，被真实类桩方法调用）。名称带 uid，避免多文件重声明。 */
    private static function delegateFn(string $uid): string
    {
        return <<<PHP
function __awa_vmmethod_{$uid}(string \$cls, string \$m, array \$args, \$thisObj) {
    \$run = \$GLOBALS['__awa_run_{$uid}'] ?? null;
    \$prog = \$GLOBALS['__awa_prog_{$uid}'] ?? null;
    if (\$run === null || \$prog === null) { return null; }
    \$short = (\$p = strrpos(\$cls, '\\\\')) !== false ? substr(\$cls, \$p + 1) : \$cls;
    \$seg = \$prog['classes'][\$short]['methods'][\$m] ?? null;
    if (\$seg === null) { return null; }
    \$et = \$prog['classET'][\$short][\$m] ?? null;
    \$spec = \$prog['classParams'][\$short][\$m] ?? null;
    if (is_array(\$spec) && \$spec !== []) {
        \$n = count(\$spec);
        \$variadic = ((int) (\$spec[\$n - 1][2] ?? 0) & 2) === 2;
        // 原生多传的实参：PHP 对非变参函数是"忽略多余实参"（仅 func_get_args 可见）。
        // VM 帧入口按参数槽逐个 POP_V，多余实参留在栈顶会让弹出的槽整体前移，故先截断。
        if (!\$variadic && count(\$args) > \$n) { \$args = array_slice(\$args, 0, \$n); }
        \$pad = \$GLOBALS['__awa_pad_{$uid}'] ?? null;
        if (\$pad !== null) { \$args = \$pad(\$spec, \$args); }
    }
    \$vv = ['\$this' => \$thisObj];
    \$r = \$run(\$seg, \$vv, \$thisObj, \$args, false, \$et);
    // by-ref 形参写回：桩层传入的 \$args 元素是【对调用方变量的引用】（见 emitStubs 的
    // 引用形参处理），帧跑完后把形参槽位的最终值赋回该元素即写穿到调用方。
    // 生成器方法返回时帧尚未执行（\$vv 为空），此处自然无操作。
    if (is_array(\$spec)) {
        foreach (\$spec as \$i => \$p) {
            if (((int) (\$p[2] ?? 0) & 1) !== 1) { continue; }
            if (!array_key_exists(\$i, \$args)) { continue; }
            \$slot = (string) (\$p[0] ?? '');
            if (\$slot !== '' && array_key_exists(\$slot, \$vv)) { \$args[\$i] = \$vv[\$slot]; }
        }
    }
    return \$r;
}
PHP;
    }

    /**
     * 从源码解析出"短名 -> FQN"映射，并定位每个顶层类的起止与命名空间。
     * @return array<string,string> short => fqn
     */
    private static function collectRealClasses(string $code): array
    {
        $toks = self::tokenizeOffsets($code);
        $map = [];
        $curNs = '';
        $depth = 0;          // 顶层结构括号深度
        $stack = [];         // 记录每个 { 对应的起点类型
        $i = 0;
        $n = count($toks);
        while ($i < $n) {
            $t = $toks[$i];
            $type = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t[1];
            if ($type === T_NAMESPACE) {
                // namespace A\B; 或 namespace A\B { ... }
                $j = $i + 1;
                $ns = '';
                while ($j < $n) {
                    $tt = $toks[$j];
                    if (is_array($tt) && in_array($tt[0], [T_STRING, T_NS_SEPARATOR], true)) {
                        $ns .= $tt[1];
                        $j++;
                    } elseif (is_array($tt) && in_array($tt[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        // PHP 8：限定名被合并为单个令牌（如 namespace app\controller）
                        $ns = ltrim($tt[1], '\\');
                        $j++;
                    } elseif (self::isChar($tt, ';')) {
                        $curNs = $ns;
                        $i = $j + 1;
                        continue 2;
                    } elseif (self::isChar($tt, '{')) {
                        $curNs = $ns;
                        break;
                    } elseif ($tt[0] === null || in_array($tt[0] ?? -1, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        // 跳过 namespace 与名称之间的空白 / 注释
                        $j++;
                    } else {
                        break;
                    }
                }
            }
            if (($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT) && $depth === 0) {
                // 收集类名（紧随其后的 T_STRING）
                $j = $i + 1;
                $name = null;
                while ($j < $n) {
                    $tt = $toks[$j];
                    if (is_array($tt) && $tt[0] === T_STRING) {
                        $name = $tt[1];
                        break;
                    }
                    if ((self::isChar($tt, '{') || self::isChar($tt, ';'))) {
                        break;
                    }
                    $j++;
                }
                if ($name !== null) {
                    $fqn = $curNs !== '' ? $curNs . '\\' . $name : $name;
                    // 同时以短名与 FQN 为键：VM 内部 NEWOBJ 既可能传短名（如 'Shop'）
                    // 也可能传 FQN（如 'app\controller\Shop'），两种都映射到真实 FQN。
                    $map[$name] = $fqn;
                    $map[$fqn]  = $fqn;
                }
            }
            if (is_string($text) && ($text === '{' || $text === '}')) {
                $depth += ($text === '{') ? 1 : -1;
            }
            $i++;
        }
        return $map;
    }

    /**
     * 从方法签名文本里取出【按引用传参】的形参：位置索引 => '$变量名'。
     * 支持 `array &$a`、`&...$rest`、类型/默认值/属性等修饰；括号/方括号内的逗号不切分。
     *
     * @return array<int,string>
     */
    private static function refParamsOf(string $sig): array
    {
        $sig = (string) preg_replace('#/\*.*?\*/|//[^\n]*#', ' ', $sig);
        $p = strpos($sig, '(');
        if ($p === false) {
            return [];
        }
        $depth = 0;
        $close = -1;
        $n = strlen($sig);
        for ($i = $p; $i < $n; $i++) {
            if ($sig[$i] === '(') {
                $depth++;
            } elseif ($sig[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        if ($close < 0) {
            return [];
        }
        $body = substr($sig, $p + 1, $close - $p - 1);
        $parts = [];
        $cur = '';
        $d2 = 0;
        $d3 = 0;
        for ($i = 0, $L = strlen($body); $i < $L; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $d2++;
            } elseif ($ch === ')') {
                $d2--;
            } elseif ($ch === '[') {
                $d3++;
            } elseif ($ch === ']') {
                $d3--;
            }
            if ($ch === ',' && $d2 === 0 && $d3 === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if (trim($cur) !== '') {
            $parts[] = $cur;
        }
        $out = [];
        foreach ($parts as $k => $pt) {
            if (preg_match('/&\s*(?:\.\.\.\s*)?\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/', $pt, $m)) {
                $out[$k] = '$' . $m[1];
            }
        }
        return $out;
    }

    /**
     * 发射真实类桩：保留类声明原文（extends / implements / 属性 / 常量 / use trait /
     * 原文方法签名），仅把每个【有方法体】的方法体替换为 VM 委托调用。
     *
     * 按命名空间分组：同一命名空间下的所有类合并进同一个 `namespace X { ... }` 块，
     * 其 use 语句在块顶部发射一次（PHP 要求 use 在命名空间块首部；重复 use 同别名会致命）。
     * 全局（无命名空间）类直接平铺，use 同样去重一次。
     *
     * @return array{code:string, blockMode:bool} blockMode=true 表示产物使用了括号式
     *         命名空间块——此时文件里所有代码（含运行时）都必须落在某个 namespace 块内。
     */
    private static function emitStubs(string $code, array $realMap, string $uid): array
    {
        $toks = self::tokenizeOffsets($code);
        $n = count($toks);
        $curNs = '';
        $usesByNs = [];       // ns => [use 语句文本]
        $seenByNs = [];       // ns => [use 文本 => true]（去重）
        $depth = 0;
        $classes = [];        // [['short','fqn','headStart','bodyOpen','bodyClose','ns']]

        $i = 0;
        while ($i < $n) {
            $t = $toks[$i];
            $type = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t[1];
            if ($type === T_NAMESPACE) {
                $j = $i + 1;
                $ns = '';
                while ($j < $n) {
                    $tt = $toks[$j];
                    if (is_array($tt) && in_array($tt[0], [T_STRING, T_NS_SEPARATOR], true)) {
                        $ns .= $tt[1];
                        $j++;
                    } elseif (is_array($tt) && in_array($tt[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $ns = ltrim($tt[1], '\\');
                        $j++;
                    } elseif ((self::isChar($tt, '{') || self::isChar($tt, ';'))) {
                        $curNs = $ns;
                        $i = ($tt === ';') ? $j + 1 : $j; // 进入 bracketed 不跳过 '{' 体
                        continue 2;
                    } elseif ($tt[0] === null || in_array($tt[0] ?? -1, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        // 跳过 namespace 与名称之间的空白 / 注释
                        $j++;
                    } else {
                        break;
                    }
                }
            }
            if ($type === T_USE && $depth === 0) {
                // 只收集【命名空间导入】use：`use A\B;` / `use function f;` / `use A\{B,C};`。
                // 闭包的 `function () use ($n): \Generator { ... }` 也是 T_USE，但它出现在
                // 表达式里（深度>0）或紧跟 `(`，若被当成导入语句收集，会把闭包片段
                // `use ($n): \Generator { for (...) ...` 原样搬到命名空间块首部 →
                // "syntax error, unexpected token "(""。故双重判定：深度 0 且 use 后不是 `(`。
                $k2 = $i + 1;
                while ($k2 < $n) {
                    $tk = $toks[$k2];
                    $tkType = is_array($tk) ? $tk[0] : null;
                    if ($tkType === T_WHITESPACE || $tkType === T_COMMENT || $tkType === T_DOC_COMMENT) {
                        $k2++;
                        continue;
                    }
                    break;
                }
                $isClosureUse = ($k2 < $n) && self::isChar($toks[$k2], '(');
                if ($isClosureUse) {
                    $i++;
                    continue;
                }
                $j = $i;
                $u = '';
                while ($j < $n) {
                    $tt = $toks[$j];
                    $u .= is_array($tt) ? $tt[1] : $tt[1];
                    if (self::isChar($tt, ';')) {
                        // $u 已含结尾 ';'，这里只规整空白，不能再补一个 ';'（否则 `use A;;`）。
                        $u = rtrim($u);
                        if (!isset($seenByNs[$curNs])) {
                            $seenByNs[$curNs] = [];
                        }
                        if (!isset($seenByNs[$curNs][$u])) {
                            $seenByNs[$curNs][$u] = true;
                            $usesByNs[$curNs][] = $u;
                        }
                        $i = $j + 1;
                        continue 2;
                    }
                    $j++;
                }
            }
            if (($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT) && $depth === 0) {
                // 跳过 bracketed 命名空间内的类体（depth>0）——仅处理顶层类
                $j = $i + 1;
                $name = null;
                while ($j < $n) {
                    $tt = $toks[$j];
                    if (is_array($tt) && $tt[0] === T_STRING) { $name = $tt[1]; break; }
                    if ((self::isChar($tt, '{') || self::isChar($tt, ';'))) { break; }
                    $j++;
                }
                if ($name !== null && isset($realMap[$name])) {
                    $k = $j;
                    $bodyOpen = null;
                    while ($k < $n) {
                        $tt = $toks[$k];
                        if (self::isChar($tt, '{')) { $bodyOpen = $k; break; }
                        if (self::isChar($tt, ';')) { $bodyOpen = null; break; }
                        $k++;
                    }
                    if ($bodyOpen !== null) {
                        $bd = 1;
                        $bodyClose = null;
                        for ($m2 = $bodyOpen + 1; $m2 < $n; $m2++) {
                            $tt2 = $toks[$m2];
                            $ttype2 = is_array($tt2) ? $tt2[0] : null;
                            $isOpen2 = self::isChar($tt2, '{')
                                || $ttype2 === T_CURLY_OPEN
                                || (defined('T_DOLLAR_OPEN_CURLY_BRACES') && $ttype2 === T_DOLLAR_OPEN_CURLY_BRACES);
                            if ($isOpen2) { $bd++; }
                            elseif (self::isChar($tt2, '}')) { $bd--; if ($bd === 0) { $bodyClose = $m2; break; } }
                        }
                        if ($bodyClose !== null) {
                            $classes[] = [
                                'short' => $name,
                                'fqn'   => $realMap[$name],
                                // 关键：substr 需要【字符偏移】，故存 tokenizeOffsets 的起始偏移（$toks[x][2]），而非 token 下标 $i/$bodyOpen/$bodyClose
                                'headStart' => $toks[$i][2],
                                'bodyOpen'  => $toks[$bodyOpen][2],
                                'bodyClose' => $toks[$bodyClose][2],
                                'ns'    => $curNs,
                            ];
                            $i = $bodyClose + 1;
                            continue;
                        }
                    }
                }
            }
            if (is_string($text) && ($text === '{' || $text === '}')) {
                $depth += ($text === '{') ? 1 : -1;
            }
            $i++;
        }

        if ($classes === []) {
            return ['code' => '', 'blockMode' => false];
        }

        // 按命名空间分组，逐组发射：namespace 块（去重 use + 全部类）+ 全局块
        $byNs = [];
        foreach ($classes as $c) {
            $byNs[$c['ns']][] = $c;
        }
        // PHP 硬规则：一旦使用括号式命名空间 `namespace X { }`，文件里【所有】代码都必须
        // 落在某个 namespace 块内（"No code may exist outside of namespace {}"）。
        // 所以只要有一个带命名空间的类，原本平铺的全局类也必须包进 `namespace { }`；
        // 反之（纯全局文件）绝不能发射 `namespace { }`，否则与前面的平铺类声明冲突：
        // "Namespace declaration statement has to be the very first statement"。
        $blockMode = false;
        foreach (array_keys($byNs) as $nsKey) {
            if ((string) $nsKey !== '') {
                $blockMode = true;
                break;
            }
        }
        $out = '';
        foreach ($byNs as $ns => $group) {
            $nsBlock = ($blockMode) ? ($ns !== '' ? "namespace {$ns} {\n" : "namespace {\n") : '';
            $useBlock = '';
            if (($usesByNs[$ns] ?? []) !== []) {
                $useBlock = implode("\n", $usesByNs[$ns]) . "\n";
            }
            $body = '';
            foreach ($group as $c) {
                $head = substr($code, $c['headStart'], $c['bodyOpen'] - $c['headStart'] + 1);
                $bodySrc = substr($code, $c['bodyOpen'] + 1, $c['bodyClose'] - $c['bodyOpen'] - 1);
                $newBody = self::replaceMethodBodies($bodySrc, $c['fqn'], $uid);
                $body .= $head . "\n" . $newBody . "\n}\n";
            }
            $closeNs = ($blockMode) ? "}\n" : '';
            $out .= $nsBlock . $useBlock . $body . $closeNs;
        }
        return ['code' => $out, 'blockMode' => $blockMode];
    }

    /**
     * 在类体内部，把每个"有方法体"的方法体 { ... } 替换为 VM 委托调用，其余原文保留。
     */
    private static function replaceMethodBodies(string $body, string $fqn, string $uid): string
    {
        // 关键：token_get_all 对"无 <?php 开标签"的裸片段会整体当 T_INLINE_HTML，导致找不到任何
        // T_FUNCTION。必须加一个开标签前缀再分词，并在用偏移 substr 时扣掉前缀长度。
        $prefix = '<?php ';
        $plen   = strlen($prefix);
        $toks = self::tokenizeOffsets($prefix . $body);
        $n = count($toks);
        $depth = 0;
        $result = '';
        $i = 0;
        $pending = '';        // 暂存方法签名前的内容（含上一个方法体之后到下一个方法签名前的原文）
        $buf = '';            // 当前累积的"方法签名"文本（从 function 到 { 或 ; ）
        $inMethodSig = false;
        $methodStatic = false;
        $lastFuncDepth = null;

        // 简化：逐 token 扫描，遇到 T_FUNCTION 且处于类体顶层（depth 跟踪）则进入签名收集，
        // 直到命中方法体 { 或抽象 ; ；把签名替换为委托体。
        while ($i < $n) {
            $t = $toks[$i];
            $type = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t[1];
            $start = $t[2];

            // 跳过为分词而加的 `<?php ` 开标签 token（不能进入 pending/输出，否则源码里
            // 混入 `<` 导致 "unexpected token <"）。
            if ($type === T_OPEN_TAG) {
                $i++;
                continue;
            }

            // 处理括号深度（仅在类体顶层，即不在嵌套函数/闭包中）
            if (is_string($text) && ($text === '{' || $text === '}')) {
                // 仅在"非方法签名收集态"时更新深度
                if (!$inMethodSig) {
                    $depth += ($text === '{') ? 1 : -1;
                }
            }

            if ($type === T_FUNCTION && $depth === 0 && !$inMethodSig) {
                // 开始一个方法签名：先 flush 暂存原文
                $result .= $pending;
                $pending = '';
                $buf = is_array($t) ? $t[1] : $t[1]; // 'function'
                $inMethodSig = true;
                // static 修饰符在 `function` 【之前】，若只在签名（function 之后）里找会漏判，
                // 桩就会把 `$this` 塞进静态方法体 → 运行期 "Using $this when not in object context"。
                // 故此处向前回溯连续修饰符，命中 T_STATIC 即静态方法（委托层传 null 而非 $this）。
                $methodStatic = false;
                for ($b = $i - 1; $b >= 0; $b--) {
                    $tb = $toks[$b];
                    $tbt = is_array($tb) ? $tb[0] : null;
                    if ($tbt === T_WHITESPACE || $tbt === T_COMMENT || $tbt === T_DOC_COMMENT) {
                        continue;
                    }
                    if ($tbt === T_STATIC) {
                        $methodStatic = true;
                        break;
                    }
                    if (in_array($tbt, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL], true)) {
                        continue;
                    }
                    break;
                }
                $i++;
                continue;
            }

            if ($inMethodSig) {
                $buf .= $text;
                if ($type === T_STATIC) {
                    $methodStatic = true;
                }
                // 签名结束：遇 {（有方法体）或 ;（抽象/接口）
                if (is_string($text) && ($text === '{' || $text === ';')) {
                    // 抽象/接口方法（;）：保留签名原文（含 ;）
                    if ($text === ';') {
                        $result .= $buf;
                        $inMethodSig = false;
                        $buf = '';
                        $i++;
                        continue;
                    }
                    // 有方法体：找到匹配 }
                    $bd = 1;
                    $bodyStart = $t[2] + strlen($text);
                    $bodyClose = null;
                    for ($m2 = $i + 1; $m2 < $n; $m2++) {
                        $tt2 = $toks[$m2];
                        $ttype = is_array($tt2) ? $tt2[0] : null;
                        // 字符串插值 `{$x}` / `${x}` 的开括号是 T_CURLY_OPEN /
                        // T_DOLLAR_OPEN_CURLY_BRACES（token 数组，非裸 '{'），而收括号是裸 '}' 字符。
                        // 若只按 isChar 计 '{' 会漏计开括号、却计入收括号 → 花括号失配、方法体提前闭合，
                        // 残留 `... users";` 之类的源码尾巴（"unexpected identifier ... expecting function"）。
                        // 故把所有插值开括号一并计为 +1，使配平。
                        $isOpen  = self::isChar($tt2, '{')
                            || $ttype === T_CURLY_OPEN
                            || (defined('T_DOLLAR_OPEN_CURLY_BRACES') && $ttype === T_DOLLAR_OPEN_CURLY_BRACES);
                        if ($isOpen) {
                            $bd++;
                        } elseif (self::isChar($tt2, '}')) {
                            $bd--; if ($bd === 0) { $bodyClose = $m2; break; }
                        }
                    }
                    if ($bodyClose !== null) {
                        $mName = self::extractMethodName($buf);
                        $thisArg = $methodStatic ? 'null' : '$this';
                        // void 方法不能 `return <value>`（PHP 致命错误"A void function must not
                        // return a value"），故 void 时只调用不返回。`void` 只可能出现在返回类型位
                        // （形参不能是 void），`: void` 判定安全。
                        // 先剥掉注释再判定：壳间注入的注释可能落在 `:` 与 `void` 之间
                        // （如 `): /* x */ void`），直接对含注释文本做正则会漏判。
                        $sig = preg_replace('#/\*.*?\*/|//[^\n]*#s', ' ', $buf);
                        $isVoid = (bool) preg_match('/:\s*void\b/i', (string) $sig);
                        $ret = $isVoid ? '' : 'return ';
                        // $buf 已包含签名末尾的 `{`，故 delegate 只需返回体 + 收尾 `}`，
                        // 否则会出现双花括号 `{{`。
                        // by-ref 形参（`array &$arr`）：func_get_args() 返回的是【副本】，
                        // 直接传给委托层会导致"函数内改了、调用方没变"。故改用引用数组：
                        // 先取全部实参，再把声明位替换为对应形参变量的【引用】——委托层写回
                        // 该元素即写穿到调用方变量（PHP 数组元素引用语义）。
                        $refParams = self::refParamsOf($buf);
                        if ($refParams === []) {
                            $argsExp = 'func_get_args()';
                        } else {
                            $pre = ' $__va = func_get_args();';
                            foreach ($refParams as $rpIdx => $rpVar) {
                                $pre .= ' $__va[' . $rpIdx . '] = &' . $rpVar . ';';
                            }
                            $delegate = "\n        " . trim($pre) . "\n        {$ret}\\__awa_vmmethod_{$uid}('{$fqn}', '{$mName}', \$__va, {$thisArg});\n    }";
                            $result .= $buf . $delegate;
                            $pending = '';
                            $inMethodSig = false;
                            $buf = '';
                            $i = $bodyClose + 1;
                            continue;
                        }
                        $delegate = "\n        {$ret}\\__awa_vmmethod_{$uid}('{$fqn}', '{$mName}', {$argsExp}, {$thisArg});\n    }";
                        $result .= $buf . $delegate;
                        // 跳过方法体：$i 移到匹配 } 之后，由循环继续逐 token 累积到 pending。
                        // （不能在此用 substr 把"剩余全部"塞进 pending —— 循环随后会再次累积
                        //   同一批 token，导致后续方法被重复发射，进而 "Cannot redeclare"。）
                        $pending = '';
                        $inMethodSig = false;
                        $buf = '';
                        $i = $bodyClose + 1;
                        continue;
                    }
                    // 兜底：未找到匹配，原样输出
                    $result .= $buf;
                    $inMethodSig = false;
                    $buf = '';
                }
                $i++;
                continue;
            }

            // 普通原文
            $pending .= $text;
            $i++;
        }
        $result .= $pending;
        return $result;
    }

    /** 从方法签名文本中提取方法名（function 之后第一个 T_STRING）。 */
    private static function extractMethodName(string $sig): string
    {
        // 同上：裸片段需加开标签才能分词；这里只取 T_STRING，无需偏移换算。
        $toks = self::tokenizeOffsets('<?php ' . $sig);
        $seenFunc = false;
        foreach ($toks as $t) {
            if (is_array($t) && $t[0] === T_FUNCTION) {
                $seenFunc = true;
                continue;
            }
            if ($seenFunc && is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
        }
        return '';
    }

    /**
     * 判断 tokenizeOffsets 产出的 token 是否为某个单字符令牌（如 '{' / '}' / ';'）。
     * tokenizeOffsets 把单字符令牌包成 [null, char, start] 数组，故不能用 is_string($tok)。
     */
    private static function isChar(array $tok, string $ch): bool
    {
        return $tok[0] === null && ($tok[1] ?? '') === $ch;
    }

    /** token_get_all 并附带每个 token 的起始偏移。 */
    private static function tokenizeOffsets(string $code): array
    {
        $raw = @token_get_all($code);
        if (!is_array($raw)) {
            return [];
        }
        $pos = 0;
        $out = [];
        foreach ($raw as $t) {
            if (is_array($t)) {
                $text = $t[1];
                $start = $pos;
                $pos += strlen($text);
                $out[] = [$t[0], $text, $start];
            } else {
                $start = $pos;
                $pos += 1;
                $out[] = [null, $t, $start];
            }
        }
        return $out;
    }
}
