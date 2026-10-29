<?php
/**
 * 常量表达式上下文扫描（ConstExprScan）
 *
 * 字符串编码层会把字面量改写成「运行期解码表达式」（base64_decode(...) / XOR 装配 /
 * chr 拼接 / gzinflate(...)）。这类表达式**不能**出现在常量表达式位置——PHP 内核在
 * 编译期就要把它折叠成一个常量，遇到函数调用会直接致命：
 *
 *   PHP Fatal error: Constant expression contains invalid operations
 *
 * 典型常量表达式位置（本类负责全部标记）：
 *   1. `const X = ...;`（全局 / 类 / 接口）——直到同层的 `;`
 *   2. 类属性默认值 `public $a = ...;`（含 static 属性、readonly）——直到同层 `;`
 *   3. 枚举 case 支撑值 `enum E: string { case A = 'x'; }`
 *   4. 函数/方法/闭包/箭头函数的参数默认值 `function f($a = 'x')`——直到同层 `,` 或 `)`
 *   5. 静态局部/静态变量默认值 `static $x = 'a';`
 *   6. 属性实参 `#[Attr('x')]`（PHP 8 Attribute 参数同样要求常量表达式）
 *
 * 历史教训：这段判定原先在两处（StringEncoder 与 StringRuntimeAssembler）各写一份，
 * 其中一份补了「类成员默认值」而另一份没补，结果 `--str-xor`（走后者）在
 * 属性默认值上报 "Constant expression contains invalid operations" 并整层回滚。
 * 现统一到本类，避免再次分叉。
 */

declare(strict_types=1);

final class ConstExprScan
{
    /**
     * 返回"处于常量表达式上下文"的字符串字面量下标集合（这些下标禁止改写）。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,true>
     */
    public static function safeStringIndices(array $tokens): array
    {
        $safe = [];
        $n = count($tokens);
        $nest = 0;                 // ( [ { 的综合嵌套深度
        $braceDepth = 0;           // 仅 { } 深度（类体判定用）
        $classStack = [];          // 类/接口/trait/enum 体内部的花括号深度栈
        $awaitClassBrace = false;  // 刚见到 class/enum… 等待类体 {
        $hdrParen = 0;             // 类头 (…) 期间的括号深度
        $T_ENUM = defined('T_ENUM') ? T_ENUM : -1;

        $inParam = false;          // 处于函数/方法/闭包形参表内
        $paramNest = 0;
        $awaitParam = false;       // 刚见到 function/fn，等待形参表 (
        $attrNest = 0;             // #[ ... ] 属性实参嵌套

        // 激活中的常量表达式区段：['k' => const|member|static|param, 'nest' => 深度]
        // k 决定由哪个符号结束：';' 结束 const/member/static，',' 结束 member/static/param。
        $ranges = [];

        $prevArr = false;
        $prevType = null;

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            $isArr = is_array($tok);
            $type = $isArr ? $tok[0] : null;
            $t = is_string($tok) ? $tok : null;

            // —— 属性实参 #[...]：内部一切字符串都必须是常量表达式 ——
            if ($type === T_ATTRIBUTE) {
                $attrNest = 1;
                $prevArr = true;
                $prevType = $type;
                continue;
            }
            if ($attrNest > 0) {
                if ($isArr && $type === T_CONSTANT_ENCAPSED_STRING) {
                    $safe[$i] = true;
                }
                if ($t === '[') {
                    $attrNest++;
                } elseif ($t === ']') {
                    $attrNest--;
                }
                $prevArr = $isArr;
                $prevType = $type;
                continue;
            }

            // —— 类体定位：`Foo::class` 里的 T_CLASS 不是类声明，必须排除 ——
            if (($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT || $type === $T_ENUM)
                && !($prevArr && $prevType === T_DOUBLE_COLON)) {
                $awaitClassBrace = true;
                $hdrParen = 0;
            }
            if ($awaitClassBrace) {
                if ($t === '(') {
                    $hdrParen++;
                } elseif ($t === ')') {
                    if ($hdrParen > 0) {
                        $hdrParen--;
                    }
                } elseif ($t === '{' && $hdrParen === 0) {
                    $awaitClassBrace = false;
                    $classStack[] = $braceDepth + 1;   // 本 token 稍后自增，故 +1
                }
            }
            $top = $classStack === [] ? -1 : $classStack[count($classStack) - 1];
            $atMemberLevel = ($top >= 0 && $braceDepth === $top);

            // —— 形参表跟踪 ——
            if ($type === T_FUNCTION || (defined('T_FN') && $type === T_FN)) {
                $awaitParam = true;
            } elseif ($t === '(') {
                if ($awaitParam) {
                    $inParam = true;
                    $paramNest = 1;
                    $awaitParam = false;
                } elseif ($inParam) {
                    $paramNest++;
                }
            } elseif ($t === ')') {
                if ($inParam && $paramNest > 1) {
                    $paramNest--;
                } elseif ($inParam && $paramNest === 1) {
                    $inParam = false;
                    $ranges = self::drop($ranges, ['param']);
                }
            }

            // —— 进入常量表达式区段 ——
            if ($type === T_CONST) {
                $ranges[] = ['k' => 'const', 'nest' => $nest];
            } elseif ($type === T_STATIC && self::nextSigIsVariable($tokens, $i)) {
                $ranges[] = ['k' => 'static', 'nest' => $nest];
            } elseif ($t === '=' && $inParam && $paramNest === 1) {
                $ranges[] = ['k' => 'param', 'nest' => $nest];
            } elseif ($t === '=' && $atMemberLevel) {
                $ranges[] = ['k' => 'member', 'nest' => $nest];
            } elseif (($type === T_CASE || $type === T_DEFAULT) && $atMemberLevel) {
                // 枚举 case 支撑值：`case A = 'x';`
                $ranges[] = ['k' => 'const', 'nest' => $nest];
            }

            if ($isArr && $type === T_CONSTANT_ENCAPSED_STRING && $ranges !== []) {
                $safe[$i] = true;
            }

            // —— 区段结束 ——
            if ($t === ';') {
                $ranges = self::drop($ranges, ['const', 'member', 'static'], $nest);
            } elseif ($t === ',') {
                $ranges = self::drop($ranges, ['member', 'static', 'param'], $nest);
            }

            // —— 深度推进 ——
            if ($t === '(' || $t === '[' || $t === '{') {
                $nest++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                if ($nest > 0) {
                    $nest--;
                }
            }
            if ($t === '{') {
                $braceDepth++;
            } elseif ($t === '}') {
                if ($top >= 0 && $braceDepth === $top) {
                    array_pop($classStack);
                }
                $braceDepth--;
            }

            if (!$isArr || !in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $prevArr = $isArr;
                $prevType = $type;
            }
        }
        return $safe;
    }

    /**
     * 结束某些类型的区段：kinds 中的类型若记录深度等于 $nest（默认任意深度）则移除。
     *
     * @param array<int,array{k:string,nest:int}> $ranges
     * @param array<int,string> $kinds
     * @return array<int,array{k:string,nest:int}>
     */
    private static function drop(array $ranges, array $kinds, ?int $nest = null): array
    {
        if ($ranges === []) {
            return $ranges;
        }
        $out = [];
        foreach ($ranges as $r) {
            $hit = in_array($r['k'], $kinds, true) && ($nest === null || $r['nest'] === $nest);
            if (!$hit) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * 当前位置之后的下一个有效 token 是否为变量（判定 `static $x` 而非 `static::`/`static function`）。
     *
     * @param array<int,mixed> $tokens
     */
    private static function nextSigIsVariable(array $tokens, int $i): bool
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($t) && $t[0] === T_VARIABLE;
        }
        return false;
    }

    /**
     * 便捷入口：Token 流中是否存在需要保护的常量表达式字符串（供调试/统计）。
     *
     * @param array<int,mixed> $tokens
     */
    public static function countSafe(array $tokens): int
    {
        return count(self::safeStringIndices($tokens));
    }
}
