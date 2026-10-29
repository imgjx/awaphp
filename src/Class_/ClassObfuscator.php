<?php
/**
 * 类 / 接口 / 命名空间混淆（v2：FQCN 感知）
 *
 * v1（按裸短名匹配）的两类静默致命：
 *   1. `use app\AppService; AppService::class`：引用被改成全局名 `_x::class`，
 *      与 `namespace app` 下的声明 `app\_x` 断链 —— 框架 `new $service` 直接
 *      "Class _x not found"；
 *   2. `use think\Request`（框架类）与项目声明的 `app\Request` 短名相同，
 *      按短名一律改名会把框架导入一起改坏。
 *
 * v2 规则（改名一律按【解析后的全限定名 FQCN】查表）：
 *   A. 声明点 `class X`：FQCN = 当前命名空间\X，命中映射则改名；
 *   B. 顶层 use 导入：目标 FQCN 命中映射时改写导入段，并【保留文件内别名绑定】：
 *      `use app\Request;` → `use app\__x as Request;`、`use app\Request as R;` →
 *      `use app\__x as R;`。别名引用（`Request::class` / `new R`）一律不动，
 *      import → FQCN → 改名类 的链条天然闭合（`X::class` 的解析值同步变为新 FQCN）；
 *   C. 裸引用：命中 use 别名则不动（绑定由 B 保住）；否则按 当前命名空间\X 查表；
 *   D. 限定名引用 `\app\BaseController` / `app\listener\OrderPlaced`：引用链到此
 *      token 为止（下一个显著 token 非 `\`）时整链解析，只改最后一段。
 *
 * 安全：以字符串字面量出现过的符号由 DynamicSymbols 整体排除；
 * implements 列表的逗号后继项由 $inImplements 状态覆盖（不再依赖事后补扫）。
 */

declare(strict_types=1);

final class ClassObfuscator
{
    /** PHP 8 限定名 token（整条路径一个 token）；PHP 7 风格则由 T_STRING + T_NS_SEPARATOR 链构成 */
    private const NAME_TOKENS = [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** 名字 token（不含裸 '\' 分隔符） */
    private const NAME_ONLY = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    public function __construct(
        private Salt $salt,
        private Logger $logger,
        private ?SymbolTable $symbols = null
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @param array<string,mixed> $ctx
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config, array &$ctx): array
    {
        if (!$config->get('class_obf')) {
            return $tokens;
        }

        // 排除"名字以字符串字面量出现过"的类名：$c='Box'; new $c(); 一旦改名必断链
        $declared = DynamicSymbols::exclude(
            $this->collectDeclared($tokens),
            DynamicSymbols::fromTokens($tokens)
        );

        // FQCN => 新短名。优先使用全局符号表（整套系统模式）；否则按本文件声明构建（单文件模式）
        if ($this->symbols !== null && $this->symbols->isBuilt()) {
            $map = $this->symbols->classMap();
            foreach (array_keys($declared) as $name) {
                $this->symbols->addClass($name);
            }
        } else {
            if (empty($declared)) {
                return $tokens;
            }
            $used = $this->existingIdentifiers($tokens);
            $map = [];
            foreach (array_keys($declared) as $name) {
                $candidate = $this->salt->identifier($name, 10);
                $n = 0;
                while (isset($used[$candidate])) {
                    $candidate = $this->salt->identifier($name . '#' . (++$n), 10);
                }
                $used[$candidate] = true;
                $map[$name] = $candidate;
            }
        }

        // 白名单类：保留指定类名（FQCN 或短名均可）。跨文件模式由 SymbolTable::preserve
        // 处理；单文件模式在此剔除本地映射，声明点与引用都保持原名。
        // namespaces/interfaces/traits 在白名单里归入 classes 一并保留。
        $wlClasses = array_merge(
            (array) ($config->get('whitelist')['classes'] ?? []),
            (array) ($config->get('whitelist')['namespaces'] ?? []),
            (array) ($config->get('whitelist')['interfaces'] ?? []),
            (array) ($config->get('whitelist')['traits'] ?? [])
        );
        if ($wlClasses !== []) {
            foreach ($wlClasses as $cn) {
                $cn = (string) $cn;
                unset($map[$cn]);
                // 白名单给短名时，按短名兜底剔除所有匹配 FQCN
                foreach (array_keys($map) as $fqcn) {
                    $short = str_contains($fqcn, '\\') ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1) : $fqcn;
                    if ($short === $cn) {
                        unset($map[$fqcn]);
                    }
                }
            }
        }

        // B3a/B3b：预标记参数列表区间（T_FUNCTION/T_FN 后的首个括号）与 catch 子句区间。
        // 通用 prev/next 规则覆盖不到两类位置：
        //  - 联合/交叉类型的首段：function f(A|B $x) 的 A，prev 是 '('；
        //  - 无变量 catch：catch (A|B)，类型后直接 ')'。
        $paramRanges = $this->parenRangesAfter($tokens, [T_FUNCTION, T_FN]);
        $catchRanges = $this->parenRangesAfter($tokens, [T_CATCH]);

        $aliases = [];      // 别名 => FQCN（use 导入，含隐式别名）
        $aliasKeep = [];    // 显式别名（`use A\B\X as R;`）：绑定保持 R，引用不动
        $declSites = [];    // 已改名声明点：[类体闭括号下标, class_alias 回挂语句]
        $curNs = '';        // 当前命名空间
        $inImplements = false;
        $braceDepth = 0;    // 花括号深度：0 = 顶层（namespace 导入语句只在顶层）

        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                if ($tok === '{') {
                    $braceDepth++;
                    $inImplements = false;
                } elseif ($tok === '}') {
                    if ($braceDepth > 0) {
                        $braceDepth--;
                    }
                    $inImplements = false;
                } elseif ($tok === ';') {
                    $inImplements = false;
                }
                continue;
            }
            $type = $tok[0];

            // ---- 命名空间跟踪（声明/裸引用的 FQCN 解析基准）----
            if ($type === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $t2 = $tokens[$j];
                    if (is_array($t2) && in_array($t2[0], self::NAME_TOKENS, true)) {
                        $ns .= $t2[1];
                    } elseif (is_array($t2) && in_array($t2[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    } else {
                        break;
                    }
                }
                $curNs = ltrim($ns, '\\');
                continue;
            }

            // ---- 顶层 use 导入：整条语句专用解析（别名表 + 导入段改名）----
            // 类体内的 `use SomeTrait;`（trait 引入）不是导入语句：走常规引用改名，
            // 由 positionGate(T_USE) 覆盖；目标名解析与导入一致（裸名看别名表/当前命名空间）。
            if ($type === T_USE && $braceDepth === 0) {
                $nx = Parser::nextSignificant($tokens, $i);
                if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_FUNCTION) {
                    continue; // use function f：函数混淆器不处理，跳过
                }
                if ($nx >= 0 && is_array($tokens[$nx]) && $tokens[$nx][0] === T_CONST) {
                    continue; // use const：函数混淆器不处理，跳过
                }
                if ($nx >= 0 && is_string($tokens[$nx]) && $tokens[$nx] === '(') {
                    continue; // 闭包 use (...)：不是命名空间导入（误判会吞掉闭包体半条语句）
                }
                $i = $this->consumeUse($tokens, $i, $aliases, $aliasKeep, $map);
                continue;
            }

            if ($type === T_IMPLEMENTS) {
                $inImplements = true;
                continue;
            }

            // ---- D. PHP 8 限定名 token：整条路径一个 token（\app\X / app\X\Y / namespace\X）----
            if (in_array($type, self::NAME_ONLY, true) && $type !== T_STRING) {
                $text = (string) $tok[1];
                $prev = Parser::prevSignificant($tokens, $i);
                $prevType = $prev >= 0 ? Parser::type($tokens[$prev]) : -1;
                if ($prevType === T_USE) {
                    // 类体内 trait 引入 `use app\lib\T;`：use 目标一律按【绝对名】解析（不拼当前命名空间）
                    $fqcn = ltrim($text, '\\');
                } elseif ($type === T_NAME_RELATIVE) {
                    // namespace\X\Y：相对当前命名空间
                    $rest = substr($text, (int) strpos($text, '\\') + 1);
                    $fqcn = $curNs !== '' ? $curNs . '\\' . $rest : $rest;
                } else {
                    $fqcn = ltrim($text, '\\');
                    if ($type === T_NAME_QUALIFIED && $curNs !== '') {
                        $fqcn = $curNs . '\\' . $fqcn;
                    }
                }
                if ($fqcn === '' || !isset($map[$fqcn])) {
                    continue;
                }
                $next = Parser::nextSignificant($tokens, $i);
                $nextType = $next >= 0 ? Parser::type($tokens[$next]) : -1;
                if ($this->positionGate($prevType, $tokens, $prev, $nextType, $tokens[$next] ?? null, $inImplements, $paramRanges, $catchRanges, $i)) {
                    // 只改最后一段（命名空间前缀原样保留）
                    $sp = strrpos($text, '\\');
                    $head = $sp === false ? '' : substr($text, 0, $sp + 1);
                    $tokens[$i][1] = $head . $map[$fqcn];
                }
                continue;
            }

            if ($type !== T_STRING) {
                continue;
            }
            $name = $tok[1];
            if ($name === '') {
                continue;
            }

            $prev = Parser::prevSignificant($tokens, $i);
            $prevType = $prev >= 0 ? Parser::type($tokens[$prev]) : -1;
            $next = Parser::nextSignificant($tokens, $i);
            $nextType = $next >= 0 ? Parser::type($tokens[$next]) : -1;

            // ---- D. 限定名引用：仅当引用链到此 token 为止（next 非 '\'）----
            $isQualified = $prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NS_SEPARATOR;
            if ($isQualified) {
                if ($next >= 0 && is_array($tokens[$next]) && $tokens[$next][0] === T_NS_SEPARATOR) {
                    continue; // 命名空间中段（`app\` 里的 app），不是引用链末段
                }
                // 向前收集整条引用链，得到 FQCN 与链首下标
                [$fqcn, $chainStart] = $this->resolveQualified($tokens, $i, $curNs);
                if ($fqcn === null || !isset($map[$fqcn])) {
                    continue;
                }
                $startPrev = Parser::prevSignificant($tokens, $chainStart);
                $startPrevType = $startPrev >= 0 ? Parser::type($tokens[$startPrev]) : -1;
                if ($this->positionGate($startPrevType, $tokens, $startPrev, $nextType, $tokens[$next] ?? null, $inImplements, $paramRanges, $catchRanges, $i)) {
                    $tokens[$i][1] = $map[$fqcn];
                }
                continue;
            }

            // ---- A/B/C. 裸引用 / 声明点 ----
            $isDecl = in_array($prevType, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true);
            if ($isDecl) {
                // 声明点：别名不适用，一律按 当前命名空间\X 解析
                $fqcn = $curNs !== '' ? $curNs . '\\' . $name : $name;
            } elseif (isset($aliases[$name])) {
                if (isset($aliasKeep[$name])) {
                    continue; // 显式别名引用（use A\B\X as R）：绑定保持 R，引用不动
                }
                // 隐式别名引用：导入段已改为新短名（use A\B\__x;），引用同步改名闭环
                $fqcn = (string) $aliases[$name];
            } else {
                $fqcn = $curNs !== '' ? $curNs . '\\' . $name : $name;
            }
            if (!isset($map[$fqcn])) {
                continue;
            }
            if ($this->positionGate($prevType, $tokens, $prev, $nextType, $tokens[$next] ?? null, $inImplements, $paramRanges, $catchRanges, $i)) {
                $tokens[$i][1] = $map[$fqcn];
                if ($isDecl && $map[$fqcn] !== $name) {
                    // 声明点已改名：记录 class_alias 回挂点（供外部动态类名 / PSR-4 原文件名命中）
                    $close = $this->classBodyClose($tokens, $i);
                    if ($close > 0) {
                        $newFqcn = $curNs !== '' ? $curNs . '\\' . $map[$fqcn] : (string) $map[$fqcn];
                        $declSites[] = [$close, "\n\\class_alias('" . $newFqcn . "','" . $fqcn . "');"];
                    }
                }
            }
        }

        // class_alias 回挂：从后往前 splice，避免下标位移
        usort($declSites, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($declSites as [$close, $stmt]) {
            array_splice($tokens, $close + 1, 0, [$stmt]);
        }

        $ctx['class'] = $map;
        $this->logger->debug('类名混淆：' . count($map) . ' 个类/接口映射生效');
        return $tokens;
    }

    /**
     * 位置门控：该 T_STRING 是否处于"类名"位置（声明 / 实例化 / 继承 / 类型提示 / :: 前等）。
     * 与 v1 规则一致，外加 implements 列表逗号后继项。
     *
     * @param array<int,mixed> $tokens
     * @param array<int,array{int,int}> $paramRanges
     * @param array<int,array{int,int}> $catchRanges
     */
    private function positionGate(
        $prevType,
        array $tokens,
        int $prev,
        $nextType,
        $nextTok,
        bool $inImplements,
        array $paramRanges,
        array $catchRanges,
        int $i
    ): bool {
        $rename = false;
        if (in_array($prevType, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NEW, T_EXTENDS, T_IMPLEMENTS, T_USE, T_DOUBLE_COLON, T_INSTANCEOF, T_ATTRIBUTE], true)) {
            $rename = true;
        } elseif ($inImplements && $prev >= 0 && is_string($tokens[$prev]) && $tokens[$prev] === ',') {
            $rename = true; // implements A, B 的逗号后继项
        } elseif ($nextType === T_VARIABLE) {
            $rename = true; // 类型提示 / catch / 属性类型
        } elseif ($nextType === T_DOUBLE_COLON) {
            // 静态访问 / 类常量 / 静态属性 / 静态方法：Box::SIDE / Box::$c / Box::make()
            // 类名在 :: 之前——T_DOUBLE_COLON 在 prev 列表只能覆盖 :: 之后的名称。
            // PHP 中除 parent/self/static（关键字，非 T_STRING）外只有类名可位于 :: 前，安全。
            $rename = true;
        } elseif ($prev >= 0
            && ((is_string($tokens[$prev]) && ($tokens[$prev] === ':' || $tokens[$prev] === '?'))
                || Parser::isTypeSeparator($tokens[$prev]))
            && !(is_string($nextTok) && $nextTok === '(')) {
            // 返回类型（): Box { / ); / =>）、可空/联合/交集类型续段。
            // 类名不是合法表达式，故这些运算符后跟已声明类名必为类型位置；
            // '(' 后续排除函数调用形态。标签/命名参数的类名在运算符之前，不受影响。
            $rename = true;
        }

        // B3a：参数列表内联合/交叉类型首段（prev 是 '('，不在通用规则内）
        if (!$rename && $this->inRanges($paramRanges, $i)
            && is_int($nextType) && $nextType >= 0 && Parser::isTypeSeparator($nextTok)) {
            $rename = true;
        }
        // B3b：catch (A|B) 无变量形式——类型后直接 ')' 或类型分隔符
        if (!$rename && $this->inRanges($catchRanges, $i)
            && (is_int($nextType) && $nextType >= 0 && (Parser::isTypeSeparator($nextTok)
                || (is_string($nextTok) && $nextTok === ')')))) {
            $rename = true;
        }
        return $rename;
    }

    /**
     * 解析限定名引用链（如 `\app\BaseController` / `app\listener\OrderPlaced`）。
     * 返回 [FQCN|null, 链首下标]。链首之前的 `\` 归入链（判绝对/相对）。
     *
     * @param array<int,mixed> $tokens
     * @return array{0:?string,1:int}
     */
    private function resolveQualified(array $tokens, int $i, string $curNs): array
    {
        $n = count($tokens);
        // 向前收集：T_STRING / T_NS_SEPARATOR 连续段
        $chain = [$i];
        $j = Parser::prevSignificant($tokens, $i);
        while ($j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NS_SEPARATOR], true)) {
            array_unshift($chain, $j);
            $j = Parser::prevSignificant($tokens, $j);
        }
        $absolute = false;
        if ($chain !== [] && is_array($tokens[$chain[0]]) && $tokens[$chain[0]][0] === T_NS_SEPARATOR) {
            $absolute = true;
            array_shift($chain); // 去掉前导 '\'
        }
        if ($chain === []) {
            return [null, $i];
        }
        $path = '';
        foreach ($chain as $k) {
            $path .= is_array($tokens[$k]) ? (string) $tokens[$k][1] : (string) $tokens[$k];
        }
        $path = ltrim($path, '\\');
        if ($path === '') {
            return [null, $i];
        }
        if (!$absolute && $curNs !== '') {
            $path = $curNs . '\\' . $path;
        }
        return [$path, $chain[0]];
    }

    /**
     * 解析一条顶层 use 语句（含 group use / 多子句）：
     *  - 填充 $aliases（别名 => FQCN，含隐式别名）；显式别名（as X）另记 $aliasKeep；
     *  - 目标 FQCN 命中 $map 时改写导入段末段：
     *      隐式 `use A\B\Short;`   → `use A\B\__x;`（绑定随新短名走，裸引用同步改名）；
     *      显式 `use A\B\Short as R;` → `use A\B\__x as R;`（`as R` 原样保住绑定）。
     * 返回语句结束 `;` 的下标。
     *
     * @param array<int,mixed> $tokens
     * @param array<string,string> $aliases
     * @param array<string,true> $aliasKeep
     * @param array<string,string> $map
     */
    private function consumeUse(array &$tokens, int $i, array &$aliases, array &$aliasKeep, array $map): int
    {
        $n = count($tokens);
        $j = $i + 1;

        $readPath = function () use ($tokens, &$j, $n): array {
            // 返回 [路径文本, 名字 token 下标列表]。PHP 8 限定名是一个 token（文本含多段），
            // PHP 7 风格是 T_STRING + T_NS_SEPARATOR 链。
            $segs = [];
            $path = '';
            while ($j < $n) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], self::NAME_ONLY, true)) {
                    $segs[] = $j;
                    $path .= $t[1];
                    $j++;
                } elseif (is_array($t) && $t[0] === T_NS_SEPARATOR) {
                    $path .= '\\';
                    $j++;
                } else {
                    break;
                }
            }
            return [$path, $segs];
        };
        $skipWs = function () use ($tokens, &$j, $n) {
            while ($j < $n && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
        };

        while ($j < $n) {
            $skipWs();
            if ($j < $n && is_string($tokens[$j]) && $tokens[$j] === ';') {
                return $j;
            }
            [$path, $segs] = $readPath();
            if ($segs === []) {
                // 不认识的形态：就地交还（外层 for 的 $i++ 继续逐 token 扫描）。
                // 不能快进到 ';' —— 闭包 use ($a,$b) 的 '(' 会把闭包体前半条语句一起吞掉，
                // 其中的类引用就此漏改名（绑定已换新名，裸名掉回当前命名空间）。
                return $j;
            }

            $skipWs();
            $isGroup = ($j < $n) && is_string($tokens[$j]) && $tokens[$j] === '{';

            $handleClause = function (string $fqcn, int $nameIdx, ?int $aliasIdx, string $origShort) use (&$tokens, &$aliases, &$aliasKeep, $map) {
                if ($aliasIdx !== null) {
                    $aliasKeep[(string) $tokens[$aliasIdx][1]] = true;
                    $alias = (string) $tokens[$aliasIdx][1];
                } else {
                    $alias = $origShort;
                }
                $aliases[$alias] = $fqcn;
                if (!isset($map[$fqcn])) {
                    return;
                }
                $newName = $map[$fqcn];
                // 只改导入段的【末段】（PHP8 限定名 token 文本含整条路径）
                $tokText = (string) $tokens[$nameIdx][1];
                $sp = strrpos($tokText, '\\');
                $tokens[$nameIdx][1] = ($sp === false ? '' : substr($tokText, 0, $sp + 1)) . $newName;
            };

            if ($isGroup) {
                $j++; // 进 '{'
                $prefix = rtrim($path, '\\');
                while ($j < $n) {
                    $skipWs();
                    if ($j < $n && is_string($tokens[$j]) && $tokens[$j] === '}') {
                        $j++;
                        break;
                    }
                    [$sub, $subSegs] = $readPath();
                    if ($subSegs === []) {
                        break;
                    }
                    $nameIdx = $subSegs[count($subSegs) - 1];
                    $origShort = self::shortOf((string) $tokens[$nameIdx][1]);
                    $skipWs();
                    $aliasIdx = null;
                    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                        $j++;
                        $skipWs();
                        if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $aliasIdx = $j;
                            $j++;
                        }
                    }
                    $fqcn = ltrim($prefix . '\\' . $sub, '\\');
                    $handleClause($fqcn, $nameIdx, $aliasIdx, $origShort);
                    $skipWs();
                    if ($j < $n && is_string($tokens[$j]) && $tokens[$j] === ',') {
                        $j++;
                        continue;
                    }
                }
            } else {
                $nameIdx = $segs[count($segs) - 1];
                $origShort = self::shortOf((string) $tokens[$nameIdx][1]);
                $fqcn = ltrim($path, '\\');
                $skipWs();
                $aliasIdx = null;
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                    $j++;
                    $skipWs();
                    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $aliasIdx = $j;
                        $j++;
                    }
                }
                $handleClause($fqcn, $nameIdx, $aliasIdx, $origShort);
            }

            $skipWs();
            if ($j < $n && is_string($tokens[$j]) && $tokens[$j] === ',') {
                $j++;
                continue;
            }
            if ($j < $n && is_string($tokens[$j]) && $tokens[$j] === ';') {
                return $j;
            }
            // 越界或异常形态：不再前进（外层 for 的 $i++ 兜底）
            return $j;
        }
        return min($j, $n - 1);
    }

    /** 限定名文本取末段短名（PHP8 限定名单 token 的文本含整条路径） */
    public static function shortOf(string $text): string
    {
        $sp = strrpos($text, '\\');
        return $sp === false ? $text : substr($text, $sp + 1);
    }

    /**
     * 找类体闭括号：从声明名向后取第一个 '{' 的配对 '}'。
     * 找不到（异常形态）返回 -1，跳过 class_alias 回挂。
     *
     * @param array<int,mixed> $tokens
     */
    private function classBodyClose(array $tokens, int $nameIdx): int
    {
        $n = count($tokens);
        for ($k = $nameIdx + 1; $k < $n; $k++) {
            $t = $tokens[$k];
            if (is_string($t)) {
                if ($t === '{') {
                    return $this->matchAnyBrace($tokens, $k);
                }
                if ($t === ';' || $t === '(') {
                    return -1;
                }
            }
        }
        return -1;
    }

    /**
     * 收集本文件声明的类/接口/特质的【FQCN】（当前命名空间 + 短名）。
     *
     * @param array<int,mixed> $tokens
     * @return array<string,true>
     */
    public function collectDeclared(array $tokens): array
    {
        $declared = [];
        $curNs = '';
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            if ($tok[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $t2 = $tokens[$j];
                    if (is_array($t2) && in_array($t2[0], self::NAME_TOKENS, true)) {
                        $ns .= $t2[1];
                    } elseif (is_array($t2) && in_array($t2[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    } else {
                        break;
                    }
                }
                $curNs = ltrim($ns, '\\');
                continue;
            }
            if (in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $nameIdx = Parser::nextSignificant($tokens, $i);
                if ($nameIdx >= 0 && is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_STRING) {
                    $short = $tokens[$nameIdx][1];
                    $declared[$curNs !== '' ? $curNs . '\\' . $short : $short] = true;
                }
            }
        }
        return $declared;
    }

    /**
     * 收集指定关键字之后第一个 '(' 的配对区间（参数列表 / catch 子句）。
     * 用于覆盖联合/交叉类型首段与无变量 catch 等通用规则盲区（BUG B3）。
     * @param array<int,mixed> $tokens
     * @param array<int,int> $keywords
     * @return array<int,array{int,int}>
     */
    private function parenRangesAfter(array $tokens, array $keywords): array
    {
        $ranges = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], $keywords, true)) {
                continue;
            }
            // 关键字后找第一个 '('（跳过函数名 / & 返回引用形式）
            $open = -1;
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_string($tokens[$j])) {
                    if ($tokens[$j] === '(') {
                        $open = $j;
                        break;
                    }
                    // 参数列表开始前不可能遇到语句边界
                    if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                        break;
                    }
                }
            }
            if ($open < 0) {
                continue;
            }
            $close = $this->matchAnyBrace($tokens, $open);
            if ($close > $open) {
                $ranges[] = [$open, $close];
            }
        }
        return $ranges;
    }

    /** @param array<int,array{int,int}> $ranges */
    private function inRanges(array $ranges, int $i): bool
    {
        foreach ($ranges as $r) {
            if ($i >= $r[0] && $i <= $r[1]) {
                return true;
            }
        }
        return false;
    }

    /**
     * 从开括号下标找配对闭括号（字符串/注释/heredoc 等数组型 token 天然跳过）。
     * 插值修正：T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES（"{$expr}" 起始）
     * 是数组 token 但其闭合是裸 '}' 字符——不计入深度会让区间提前结束。
     */
    private function matchAnyBrace(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $tok = $tokens[$j];
            if (is_array($tok)) {
                if (in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                continue;
            }
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return -1;
    }

    /** @return array<string,true> */
    private function existingIdentifiers(array $tokens): array
    {
        $set = [];
        foreach ($tokens as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_VARIABLE, T_STRING, T_FUNCTION, T_CLASS], true)) {
                $set[$tok[1]] = true;
            }
        }
        return $set;
    }
}
