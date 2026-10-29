<?php
/**
 * 变量名混淆 + 变量名编码
 *
 * 策略：全局一致替换。同一变量名在源码中所有出现（含 global 声明、参数、static）
 * 都被映射到同一个加盐标识符，保证语义不变。
 *
 * 安全排除项（避免破坏可运行性）：
 *  - 超全局变量（$GLOBALS / $_GET ...）、$this
 *  - $$ 变量变量持有者（如 $$foo 中的 $foo）
 *  - 通过 $GLOBALS['x'] 形式引用的变量名 x
 *  - compact('x') 字符串参数引用的变量名 x
 *
 * 说明：heredoc / nowdoc 区域内的插值变量**不在排除之列**。heredoc 的
 * `$var` / `{$var}` 插值在 token 流中就是普通 T_VARIABLE，与区域外的所有
 * 出现同属一个变量，全局一致改名后语义不变（已实测验证）；nowdoc 本身
 * 不插值，其内容只是纯文本 token，天然不受影响。
 */

declare(strict_types=1);

final class VariableObfuscator
{
    /** @var array<string,true> */
    private array $excluded = [];

    /**
     * 文件内所有类/接口/trait 顶层声明的实例属性名（含构造函数提升属性）。
     * 属性声明本身是 T_VARIABLE，会被本类重命名；因此所有 `$obj->prop` /
     * `$this->prop` 引用（T_STRING）也必须同步改名，否则声明与引用脱节
     * （曾导致 `public $scores = []` 被改名而 `$this->scores` 未改，运行期
     *  Undefined property）。
     * @var array<string,true>
     */
    private array $declaredProps = [];

    /**
     * 是否保留（不重命名）类实例属性名。框架应用（ThinkPHP 等）与父类/框架代码按原始属性名
     * 访问（如 $controller->request / $this->middleware），且属性重命名是"每文件独立随机"，
     * 跨文件继承时声明与引用会得到不同新名 → 运行期动态属性告警/属性丢失。开启后属性名保持
     * 原样，局部变量仍照常重命名（局部变量函数内作用域，跨文件无耦合）。
     */
    private bool $keepProps = false;

    /** 是否保留（不重命名）函数/方法参数名。框架按参数名做路由绑定 / 依赖注入 / 命名参数，
     * 参数名被改名会报"方法参数错误"。开启后参数名保持原样，方法体内的非参数局部变量仍重命名。 */
    private bool $keepParams = false;

    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        $len = (int) $config->get('var_length');
        if ($len <= 0) {
            return $tokens;
        }

        $this->collectExclusions($tokens);
        $this->collectDeclaredProps($tokens);

        // 框架应用：保留属性名（见 $keepProps 说明）。把全部已声明属性名加入排除集，
        // 属性声明 T_VARIABLE 与 `$this->prop` 引用都不再重命名。
        $this->keepProps = (bool) $config->get('var_keep_props');
        if ($this->keepProps) {
            foreach ($this->declaredProps as $p => $_) {
                $this->excluded[$p] = true;
            }
        }
        // 框架应用：保留参数名（路由/DI/命名参数按名匹配）。仅排除参数名，
        // 方法体内其它局部变量照常重命名（局部变量不跨边界，重命名安全）。
        $this->keepParams = (bool) $config->get('var_keep_params');
        if ($this->keepParams) {
            foreach ($this->collectParamNames($tokens) as $p => $_) {
                $this->excluded[$p] = true;
            }
        }

        // 收集待混淆变量名（按首次出现顺序）
        $names = [];
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_VARIABLE) {
                continue;
            }
            $name = $tok[1]; // 形如 $foo
            if (isset($this->excluded[$name])) {
                continue;
            }
            if (!isset($names[$name])) {
                $names[$name] = true;
            }
        }

        // 生成映射（保证唯一、不与已有标识符冲突）
        $used = $this->existingIdentifiers($tokens);
        $map = [];
        foreach (array_keys($names) as $name) {
            $origin = substr($name, 1); // 去掉 $ 前缀
            $candidate = $this->salt->identifier($origin, $len);
            $n = 0;
            while (isset($used[$candidate])) {
                $candidate = $this->salt->identifier($origin . '#' . (++$n), $len);
            }
            $used[$candidate] = true;
            $map[$name] = '$' . $candidate;
        }

        // 命名参数同步：PHP 8 命名参数以参数名引用调用目标，函数/方法参数被
        // 重命名后，调用点的 `name:` 形态必须同步改写，否则运行期
        // Unknown named parameter（曾导致含命名参数的样例在 var 层后直接致命）。
        // 必须在主重命名循环【之前】执行：签名收集要读原始参数名。
        $this->syncNamedArgs($tokens, $map);

        // 应用替换：T_VARIABLE 直接改；`->prop` 引用（T_STRING）在 prop 是
        // 已声明属性时同步改（跳过 `->method()` 调用形态——方法名不归本类管）。
        $objOps = [T_OBJECT_OPERATOR];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $objOps[] = T_NULLSAFE_OBJECT_OPERATOR;
        }
        foreach ($tokens as $i => $tok) {
            if (is_array($tok) && $tok[0] === T_VARIABLE && isset($map[$tok[1]])) {
                $tokens[$i][1] = $map[$tok[1]];
                continue;
            }
            if (is_array($tok) && $tok[0] === T_STRING) {
                // 仅处理 `->prop` / `?->prop` 形态：前一个有效 token 必须是对象运算符，
                // 再往前一个必须是对象表达式（$var / ) / ] / $this 等），避免误伤
                // 同名常量（TITLE）或函数名（title()）。
                $prev = Parser::prevSignificant($tokens, $i);
                if ($prev < 0 || !is_array($tokens[$prev]) || !in_array($tokens[$prev][0], $objOps, true)) {
                    continue;
                }
                $mapped = $this->propRenameFor($tok[1], $map);
                if ($mapped !== null && !$this->isMethodCall($tokens, $i)) {
                    $tokens[$i][1] = $mapped;
                }
            }
        }

        $this->logger->debug('变量名混淆：' . count($map) . ' 个变量被重命名');
        return $tokens;
    }

    /**
     * 同步命名参数调用点（见 process() 内说明）。
     *
     * 安全设计：
     *  - 命名参数位判定：T_STRING 后随 ':' 且前随 '(' 或 ','（参数起始位），
     *    借此排除三目 `a ? b : c`、case/goto 标签、返回类型 `f(): void` 等同形态；
     *  - 调用目标解析：仅当能静态解析到【用户自定义】函数/方法/构造函数时才改写
     *    （内建函数如 str_replace(search: ...) 的参数名由 PHP 固定，绝不碰；
     *    动态调用 $f(...) 无法解析，保守跳过）；
     *  - 全局一致映射：参数名在全文件范围映射到同一新名，故按名索引的签名表
     *    即使跨类合并也不会产生歧义改写。
     *
     * @param array<int,mixed> $tokens
     * @param array<string,string> $map 变量名（带 $）=> 新名（带 $）
     */
    private function syncNamedArgs(array &$tokens, array $map): void
    {
        if ($map === []) {
            return;
        }
        $sig = $this->collectSignatureParams($tokens);
        if ($sig === []) {
            return;
        }
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING) {
                continue;
            }
            $next = Parser::nextSignificant($tokens, $i);
            if ($next < 0 || !is_string($tokens[$next]) || $tokens[$next] !== ':') {
                continue;
            }
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev < 0 || !is_string($tokens[$prev])
                || ($tokens[$prev] !== '(' && $tokens[$prev] !== ',')) {
                continue; // 不在参数起始位：三目/标签/类型等非命名参数形态
            }
            // 传入命名参数位下标 $i（不是前一个 '(' 下标）：resolveCallTarget
            // 会从该位向前按括号深度找包围它的参数列表 '('
            $target = $this->resolveCallTarget($tokens, $i);
            if ($target === null) {
                continue;
            }
            $known = isset($sig[$target['name']][$tok[1]])
                || ($target['ctor'] && isset($sig['__construct'][$tok[1]]));
            if (!$known) {
                continue; // 内建函数 / 无此参数（后者本就是非法调用）
            }
            $var = '$' . $tok[1];
            if (!isset($map[$var])) {
                continue; // 该参数名不在重命名表（如被排除），无需同步
            }
            $tokens[$i][1] = ltrim($map[$var], '$');
        }
    }

    /**
     * 收集用户自定义函数/方法/构造函数的参数名集合：
     *   $sig[name] = ['param' => true, ...]
     * 顶层函数、类/接口/trait 方法统一按名索引；构造函数的参数同时登记在
     * 类名键与 '__construct' 键下（覆盖 new Foo(...) / #[Foo(...)] /
     * parent::__construct(...) 三种调用形态）。闭包/箭头函数不收集。
     *
     * @param array<int,mixed> $tokens
     * @return array<string,array<string,true>>
     */
    private function collectSignatureParams(array $tokens): array
    {
        $sig = [];
        $n = count($tokens);

        // 类/接口/trait 声明体区间（排除 Foo::class 常量用法）
        $classRanges = [];
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                continue;
            }
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_string($tokens[$j]) && $tokens[$j] === '{') {
                    $close = $this->matchAnyBrace($tokens, $j);
                    if ($close > $j) {
                        $classRanges[] = [$j, $close];
                    }
                    break;
                }
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION], true)) {
                    break; // 防御：异常形态
                }
            }
        }

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            $nameIdx = Parser::nextSignificant($tokens, $i);
            if ($nameIdx < 0 || !is_array($tokens[$nameIdx]) || $tokens[$nameIdx][0] !== T_STRING) {
                continue; // 闭包（无名字）
            }
            $name = $tokens[$nameIdx][1];
            $open = Parser::nextSignificant($tokens, $nameIdx);
            if ($open < 0 || $tokens[$open] !== '(') {
                continue;
            }
            $close = $this->matchAnyBrace($tokens, $open);
            if ($close < 0) {
                continue;
            }
            // 参数名：参数列表顶层（相对深度 0）的 T_VARIABLE；
            // 默认值里的闭包/箭头函数参数处于更深层级，不收集。
            $params = [];
            $depth = 0;
            for ($j = $open + 1; $j < $close; $j++) {
                $t = $tokens[$j];
                if (is_string($t)) {
                    if ($t === '(' || $t === '[' || $t === '{') {
                        $depth++;
                    } elseif ($t === ')' || $t === ']' || $t === '}') {
                        $depth--;
                    }
                    continue;
                }
                if ($depth === 0 && $t[0] === T_VARIABLE) {
                    $params[ltrim($t[1], '$')] = true;
                }
            }
            if ($params === []) {
                continue;
            }
            $sig[$name] = ($sig[$name] ?? []) + $params;
            // 构造函数：同时按类名登记（new / 属性实例化经类名解析调用目标）
            if ($name === '__construct') {
                foreach ($classRanges as [$rb, $re]) {
                    if ($nameIdx > $rb && $nameIdx < $re) {
                        $cname = $this->classNameBefore($tokens, $rb);
                        if ($cname !== null) {
                            $sig[$cname] = ($sig[$cname] ?? []) + $params;
                        }
                        break;
                    }
                }
            }
        }
        return $sig;
    }

    /** 取类体开括号之前最近的类名（T_STRING），找不到返回 null */
    private function classNameBefore(array $tokens, int $braceIdx): ?string
    {
        for ($j = $braceIdx - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && Parser::isWhitespaceOrComment($t)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
            return null;
        }
        return null;
    }

    /**
     * 解析命名参数所在调用的目标：$argIdx 为命名参数位（参数名 T_STRING）下标。
     * 从该位向前按括号深度找包围它的参数列表 '('，再取其前的调用目标。
     * 返回 ['name' => 目标名, 'ctor' => 是否构造函数形态]；动态调用返回 null。
     *
     * 深度法而非直接取 prevSignificant：命名参数可能位于参数列表任意位置
     * （第一个参数或逗号之后），其前一个有效 token 是 '(' 或 ','，
     * 必须继续向前跳过嵌套的 (...)/[...]/{...}（默认值闭包体、数组字面量等）
     * 才能定位所属参数列表。
     *
     * @param array<int,mixed> $tokens
     * @return array{name:string,ctor:bool}|null
     */
    private function resolveCallTarget(array $tokens, int $argIdx): ?array
    {
        $depth = 1; // 视作已处于本参数位所属参数列表内部
        $parenIdx = -1;
        for ($j = $argIdx - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t)) {
                // "{$expr}" 起始是数组 token 但闭合是裸 '}'：必须计深度，
                // 否则默认值/参数里的插值串会让深度失衡、找错参数列表
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                continue; // 字符串/注释/heredoc 内不可能有裸括号
            }
            if ($t === ')' || $t === ']' || $t === '}') {
                $depth++;
            } elseif ($t === '(' || $t === '[' || $t === '{') {
                $depth--;
                if ($depth === 0) {
                    $parenIdx = $j;
                    break;
                }
            }
        }
        if ($parenIdx < 0) {
            return null;
        }
        $ci = Parser::prevSignificant($tokens, $parenIdx);
        if ($ci < 0 || !is_array($tokens[$ci]) || $tokens[$ci][0] !== T_STRING) {
            return null; // $f(...) / $obj->$m(...) / (expr)(...) 等动态调用
        }
        $name = $tokens[$ci][1];
        $before = Parser::prevSignificant($tokens, $ci);
        if ($before >= 0 && is_array($tokens[$before])) {
            $bt = $tokens[$before][0];
            $objOps = [T_OBJECT_OPERATOR];
            if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
                $objOps[] = T_NULLSAFE_OBJECT_OPERATOR;
            }
            if ($bt === T_DOUBLE_COLON || in_array($bt, $objOps, true)) {
                return ['name' => $name, 'ctor' => false]; // 方法/静态调用
            }
            if ($bt === T_NEW || $bt === T_ATTRIBUTE) {
                return ['name' => $name, 'ctor' => true]; // new Foo(...) / #[Foo(...)]
            }
            if ($bt === T_NS_SEPARATOR || $bt === T_STRING) {
                return ['name' => $name, 'ctor' => false]; // 限定名 ns\func( 取末段
            }
        }
        return ['name' => $name, 'ctor' => false]; // 普通函数调用
    }

    /**
     * 从开括号下标找配对闭括号（字符串/注释/heredoc 等数组型 token 天然跳过）。
     * 插值修正：T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES（"{$expr}" 起始）
     * 是数组 token 但其闭合是裸 '}' 字符——不计入深度会让类体范围在方法内
     * 第一个插值串处提前结束（签名收集漏方法、classNameBefore 找错类）。
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

    /**
     * 收集所有函数/方法（含闭包）参数列表顶层的变量名（带 $ 前缀）。
     * 用于 var_keep_params：参数名是框架/命名参数的公共契约，不能改名。
     * 实现：定位每个 T_FUNCTION，找到参数左括号，再按括号深度收集 depth=1 的 T_VARIABLE。
     * @param array<int,mixed> $tokens
     * @return array<string,true>
     */
    private function collectParamNames(array $tokens): array
    {
        $names = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            // 找到参数左括号（跳过可选 & 与函数名、返回引用等）
            $j = $i + 1;
            while ($j < $n) {
                $t = $tokens[$j];
                if (is_string($t)) {
                    if ($t === '(') {
                        break;
                    }
                    if ($t === '{' || $t === ';') {
                        $j = -1;
                        break;
                    }
                }
                $j++;
            }
            if ($j < 0 || $j >= $n) {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                $t = $tokens[$k];
                if (is_string($t)) {
                    if ($t === '(') {
                        $depth++;
                    } elseif ($t === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                } elseif (is_array($t) && $t[0] === T_VARIABLE && $depth === 1) {
                    $names[$t[1]] = true;
                }
            }
        }
        return $names;
    }

    /**
     * 若 $name 是已声明属性且在映射表中，返回改名后的属性名（不带 $ 前缀）；否则 null。
     * @param array<string,string> $map
     */
    private function propRenameFor(string $name, array $map): ?string
    {
        if ($this->keepProps) {
            return null;
        }
        $var = '$' . $name;
        if (!isset($this->declaredProps[$var]) || !isset($map[$var])) {
            return null;
        }
        return ltrim($map[$var], '$');
    }

    /**
     * 收集文件内所有类/接口/trait 【体内顶层】声明的实例属性名（含构造函数提升属性）。
     *
     * 实现：先定位每个类声明的体开括号（排除 `Foo::class` 的 T_CLASS 常量用法），
     * 再用花括号栈单遍扫描，仅"栈顶帧即类体"的 T_VARIABLE 才可能是成员，
     * 最后用修饰符链甄别属性声明与构造函数普通参数。
     *
     * @param array<int,mixed> $tokens
     */
    private function collectDeclaredProps(array $tokens): void
    {
        $this->declaredProps = [];
        $n = count($tokens);

        // 1) 类/接口/trait 声明体的开括号集合
        $classBraces = [];
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                continue;
            }
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                continue; // Foo::class 常量用法，不是类声明
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_string($tokens[$j]) && $tokens[$j] === '{') {
                    $classBraces[$j] = true;
                    break;
                }
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION], true)) {
                    break; // 防御：异常形态
                }
            }
        }
        if ($classBraces === []) {
            return;
        }

        // 2) 花括号栈扫描：栈顶帧为类体时，顶层 T_VARIABLE 才可能是属性声明
        /** @var array<int,bool> $stack 每帧标记是否为类体 */
        $stack = [];
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_string($tok) && $tok === '{') {
                $stack[] = isset($classBraces[$i]);
                continue;
            }
            if (is_string($tok) && $tok === '}') {
                if ($stack !== []) {
                    array_pop($stack);
                }
                continue;
            }
            if (!is_array($tok) || $tok[0] !== T_VARIABLE) {
                continue;
            }
            if ($stack === [] || !end($stack)) {
                continue; // 不在类体内 / 在方法体等嵌套块内
            }
            if ($this->isPropertyDeclaration($tokens, $i)) {
                $this->declaredProps[$tok[1]] = true;
            }
        }
    }

    /**
     * 判断 $i 处的 T_VARIABLE 是否为属性声明（含构造函数提升属性）：
     * 向前越过类型提示 token，应落到可见性 / var / static / readonly 修饰符。
     * 普通参数前是 '(' 或 ','，会被拒绝。
     *
     * @param array<int,mixed> $tokens
     */
    private function isPropertyDeclaration(array $tokens, int $i): bool
    {
        $modifiers = [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_VAR, T_STATIC];
        if (defined('T_READONLY')) {
            $modifiers[] = T_READONLY;
        }
        $typeish = [T_STRING, T_NS_SEPARATOR, T_ARRAY, T_CALLABLE];
        $j = Parser::prevSignificant($tokens, $i);
        $guard = 0;
        while ($j >= 0 && $guard++ < 24) {
            $t = $tokens[$j];
            if (is_string($t) && $t === '?') {
                $j = Parser::prevSignificant($tokens, $j);
                continue; // 可空类型前缀
            }
            if (Parser::isTypeSeparator($t)) {
                $j = Parser::prevSignificant($tokens, $j);
                continue; // 联合/交集类型分隔（PHP 8.1+ 的 & 为 T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG）
            }
            if (is_string($t)) {
                return false; // '(' ',' ';' '}' '=' 等 → 普通参数或其他
            }
            if (!is_array($t)) {
                return false;
            }
            if (in_array($t[0], $modifiers, true)) {
                return true;
            }
            if (in_array($t[0], $typeish, true)) {
                $j = Parser::prevSignificant($tokens, $j);
                continue; // 类型提示（如 `public ?int $x` 的 int）
            }
            return false;
        }
        return false;
    }

    /**
     * $i 处的 T_STRING 是否为方法调用（`->name(` / `?->name(`）。
     * @param array<int,mixed> $tokens
     */
    private function isMethodCall(array $tokens, int $i): bool
    {
        $next = Parser::nextSignificant($tokens, $i);
        return $next >= 0 && is_string($tokens[$next]) && $tokens[$next] === '(';
    }

    private function collectExclusions(array $tokens): void
    {
        $super = [
            '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_SESSION',
            '$COOKIE', '$_FILES', '$_ENV', '$http_response_header', '$php_errormsg',
            '$this',
        ];
        foreach ($super as $s) {
            $this->excluded[$s] = true;
        }

        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            // $$foo ：前一个有效 token 是单独的 '$'
            $prev = Parser::prevSignificant($tokens, $i);
            if ($prev >= 0 && is_string($tokens[$prev]) && $tokens[$prev] === '$') {
                $this->excluded[$tok[1]] = true;
                continue;
            }
            // $GLOBALS['x'] ：收集字符串键 x 作为排除名
            if ($tok[0] === T_VARIABLE && $tok[1] === '$GLOBALS') {
                $next = Parser::nextSignificant($tokens, $i);
                if ($next >= 0 && is_string($tokens[$next]) && $tokens[$next] === '[') {
                    $keyIdx = Parser::nextSignificant($tokens, $i, 2);
                    if ($keyIdx >= 0 && is_array($tokens[$keyIdx]) && $tokens[$keyIdx][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $this->excluded['$' . trim($tokens[$keyIdx][1], '\'"')] = true;
                    }
                }
            }
            // compact('x') ：字符串参数引用的变量名 x
            if ($tok[0] === T_STRING && strcasecmp($tok[1], 'compact') === 0) {
                $paren = Parser::nextSignificant($tokens, $i);
                if ($paren >= 0 && is_string($tokens[$paren]) && $tokens[$paren] === '(') {
                    for ($j = $paren + 1; $j < $n; $j++) {
                        if (is_string($tokens[$j]) && $tokens[$j] === ')') {
                            break;
                        }
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                            $this->excluded['$' . trim($tokens[$j][1], '\'"')] = true;
                        }
                    }
                }
            }
        }
    }

    /** @return array<string,true> */
    private function existingIdentifiers(array $tokens): array
    {
        $set = [];
        foreach ($tokens as $tok) {
            if (is_array($tok) && ($tok[0] === T_VARIABLE || $tok[0] === T_STRING || $tok[0] === T_FUNCTION || $tok[0] === T_CLASS)) {
                $set[$tok[1]] = true;
            }
        }
        return $set;
    }
}
