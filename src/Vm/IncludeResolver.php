<?php
/**
 * VM include/require 支持（IncludeResolver）
 *
 * PHP 的 include 语义有三条硬约束，VM 要"能用"就必须满足：
 *   1. 被包含文件在【调用点的变量作用域】内执行（能看到 includer 的变量，
 *      自己新定义的变量在 include 返回后对 includer 可见）；
 *   2. include 表达式的值 = 被包含文件的 return 值（无 return 则为 1）；
 *   3. include_once / require_once 按"真实路径"去重，重复包含直接跳过。
 *
 * 实现分两路：
 *   A. 构建期可静态求值的路径（字面串 / __DIR__ / __FILE__ 的 . 连接）：
 *      读取目标文件 → 用子 VmCompiler 编成子程序 → 嵌入主程序 embeds 表，
 *      编译期发射 INCLUDE_EMBED。被包含文件完全走 VM 字节码，源码不落地。
 *      其函数/类/参数默认值合并进主程序符号表（PHP 中本就是全局可见的）。
 *   B. 运行期才能确定的路径（$var、插值串、函数调用……）：
 *      编译期发射 INCLUDE_RT，运行期做真实 include/require，并通过
 *      "帧变量表 ⇄ 真实局部变量"双向桥接保住作用域语义（见 RuntimeGenerator）。
 *
 * 循环包含（A→B→A）在构建期即被发现：后出现的那次按"已包含过"处理
 * （压入 true 跳过），避免编译期无限递归。
 */

declare(strict_types=1);

final class IncludeResolver
{
    /** @var array<int,array{seg:array,funcs:array,classes:array,funcParams:array,classParams:array,globals:array}> 全局嵌入表（跨递归共享） */
    private array $embeds = [];
    /** @var array<string,int> realpath => embed id（同文件只嵌一次） */
    private array $embedIds = [];
    /** @var array<string,true> 正在编译的文件（循环守卫） */
    private array $processing = [];
    /** @var string 主文件 realpath（被包含文件回指主文件时按"已包含过"处理） */
    private string $mainReal = '';
    /** @var array<int,string> 构建期告警（路径不存在等） */
    private array $warnings = [];

    /** 登记主文件（循环包含检测用：被包含文件再 include 主文件 = 已包含过） */
    public function setMainFile(string $path): void
    {
        $this->mainReal = $path !== '' ? (realpath($path) ?: $path) : '';
    }

    /**
     * 扫描一份源码里的全部 include/require 语句，返回【有序】计划。
     * 顺序与 VmCompiler 遇到 include 语句的顺序一一对应（同为源码线性序）。
     *
     * @return array<int,array{kw:int,once:bool,require:bool,embed:?int,cycle:bool,path:string}>
     */
    public function scan(string $src, string $baseDir, string $curFile = ''): array
    {
        $plan = [];
        $toks = Parser::tokenize('<?php ' . $src);
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t) || !in_array($t[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                continue;
            }
            // 守卫 1：->include( / ::include( 是方法/静态调用，不是包含语句
            $prev = $this->prevSig($toks, $i);
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            // 守卫 2：include(...) 直接跟左括号同样按调用处理
            $next = $this->nextSig($toks, $i + 1);
            if ($next === '(') {
                continue;
            }
            $once = in_array($t[0], [T_INCLUDE_ONCE, T_REQUIRE_ONCE], true);
            $require = in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE], true);

            // 收集路径表达式（到顶层 ; 为止；仅供静态求值，动态路径原样交给编译器）
            $expr = [];
            for ($j = $i + 1; $j < $n; $j++) {
                $tk = $toks[$j];
                if ($tk === ';') {
                    break;
                }
                $expr[] = $tk;
            }

            $entry = [
                'kw' => $t[0], 'once' => $once, 'require' => $require,
                'embed' => null, 'cycle' => false, 'path' => '',
            ];

            $static = $this->evalStaticPath($expr, $curFile, $baseDir);
            if ($static !== null && $static !== '') {
                $entry['path'] = $static;
                $abs = $this->resolvePath($static, $baseDir);
                if (is_file($abs)) {
                    $id = $this->embedFile($abs);
                    if ($id !== null) {
                        $entry['embed'] = $id;
                    } else {
                        // 循环包含（含被包含文件回指主文件）：PHP include_once 的
                        // 务实行为——视为已包含过，编译期压 true 跳过
                        $entry['cycle'] = true;
                    }
                } else {
                    $this->warnings[] = "include 目标不存在，将转为运行期包含: {$static}";
                }
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    /**
     * 静态求值 include 路径表达式：字面串 / __DIR__ / __FILE__ 及它们的 . 连接。
     * 任何含变量/函数调用/插值的形态都返回 null（转运行期 include）。
     *
     * @param array<int,mixed> $expr
     */
    private function evalStaticPath(array $expr, string $curFile, string $baseDir): ?string
    {
        $parts = $this->splitTopLevelDot($expr);
        if ($parts === null || $parts === []) {
            return null;
        }
        $dir = $curFile !== '' ? dirname($curFile) : $baseDir;
        $s = '';
        foreach ($parts as $p) {
            $p = $this->trimWs($p);
            if ($p === []) {
                return null;
            }
            if (count($p) !== 1 || !is_array($p[0])) {
                return null;
            }
            if ($p[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $s .= $this->decodeStringLiteral($p[0][1]);
            } elseif ($p[0][0] === T_DIR) {
                $s .= $dir;
            } elseif ($p[0][0] === T_FILE) {
                $s .= $curFile;
            } else {
                return null;
            }
        }
        return $s;
    }

    /**
     * 把一个文件编进嵌入表，返回全局 embed id；已嵌过返回原 id；循环中返回 null。
     */
    private function embedFile(string $absPath): ?int
    {
        $rp = realpath($absPath);
        if ($rp === false) {
            return null;
        }
        if (isset($this->embedIds[$rp])) {
            return $this->embedIds[$rp];
        }
        if ($rp === $this->mainReal || isset($this->processing[$rp])) {
            return null;   // 回指主文件 / 循环包含：按"已包含过"处理
        }
        $this->processing[$rp] = true;
        try {
            $src = (string) file_get_contents($rp);
            $src = (string) preg_replace('/^\s*<\?php/i', '', $src, 1);
            $src = (string) preg_replace('/\?>\s*$/', '', $src);
            // 先递归处理被包含文件自己的 include（分配全局 id），再编译它
            $plan = $this->scan($src, dirname($rp), $rp);
            $child = new VmCompiler();
            $child->setIncludePlan($plan);
            $child->setSourceContext($rp, dirname($rp));
            // PHP 语义：被包含文件声明的函数全局可见。把当前已嵌入文件的顶层
            // 函数名预登记进子编译器——否则嵌套 include（a 含 b，a 调用 b 的
            // 函数）会被误分流到 CALL_NATIVE，运行期在真实函数表里找不到而致命。
            $knownFns = [];
            foreach ($this->embeds() as $e) {
                foreach (array_keys($e['funcs']) as $fn) {
                    $knownFns[$fn] = true;
                }
            }
            if ($knownFns !== []) {
                $child->addKnownFunctions(array_keys($knownFns));
            }
            $prog = $child->compile($src);
            // PHP 语义：被包含文件无 return 时 include 表达式值为 1（有 return 则提前 RET）
            $prog['main'][] = [VmOp::PUSH_C, 1];
            $prog['main'][] = [VmOp::RET];
            $id = count($this->embeds);
            $this->embedIds[$rp] = $id;
            $this->embeds[] = [
                'seg'         => $prog['main'],
                'funcs'       => $prog['funcs'],
                'classes'     => $prog['classes'],
                'funcParams'  => $prog['funcParams'],
                'classParams' => $prog['classParams'],
                'globals'     => $prog['globals'],
                // 子编译器的原生函数名表（CALL_NATIVE 操作数是子表编号，
                // 合并进主程序时由 buildArtifact 重映射到主 natTbl）
                'natTbl'      => $child->natTbl(),
            ];
        } finally {
            unset($this->processing[$rp]);
        }
        return $id;
    }

    /**
     * @return array<int,array{seg:array,funcs:array,classes:array,funcParams:array,classParams:array,globals:array,natTbl:array<string,int>}>
     */
    public function embeds(): array
    {
        return $this->embeds;
    }

    /** @return array<int,string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function resolvePath(string $p, string $baseDir): string
    {
        if ($p === '') {
            return $p;
        }
        if ($p[0] === '/' || $p[0] === '\\' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p)) {
            return $p;   // 绝对路径（含 Windows 盘符）
        }
        $base = rtrim($baseDir, '/\\');
        return ($base === '' ? '.' : $base) . '/' . $p;
    }

    /**
     * 按顶层 '.' 切分（括号/方括号内的点不算，如 $a['k.v']）。
     * @param array<int,mixed> $expr
     * @return array<int,array<int,mixed>>|null
     */
    private function splitTopLevelDot(array $expr): ?array
    {
        $parts = [];
        $cur = [];
        $depth = 0;
        foreach ($expr as $t) {
            if (is_string($t)) {
                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    $depth = max(0, $depth - 1);
                } elseif ($t === '.' && $depth === 0) {
                    $parts[] = $cur;
                    $cur = [];
                    continue;
                }
            }
            $cur[] = $t;
        }
        $parts[] = $cur;
        return $parts;
    }

    /** 解码 T_CONSTANT_ENCAPSED_STRING 的字面文本（含引号） */
    private function decodeStringLiteral(string $tok): string
    {
        if (strlen($tok) < 2) {
            return '';
        }
        $q = $tok[0];
        $s = substr($tok, 1, -1);
        if ($q === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $s);
        }
        // 双引号（无插值才可能走到这里）：按 PHP 转义规则解码
        return (string) preg_replace_callback(
            '/\\\\(u\{[0-9a-fA-F]+\}|x[0-9a-fA-F]{1,2}|[0-7]{1,3}|.)/s',
            static function (array $m): string {
                $c = $m[1];
                if ($c[0] === 'u' && isset($c[1]) && $c[1] === '{') {
                    $ch = mb_chr((int) hexdec(substr($c, 2, -1)), 'UTF-8');
                    return $ch === false ? '' : $ch;
                }
                if ($c[0] === 'x') {
                    return chr((int) (hexdec(substr($c, 1)) & 0xFF));
                }
                if ($c[0] >= '0' && $c[0] <= '7') {
                    return chr((int) (octdec($c) & 0xFF));
                }
                $map = [
                    'n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v",
                    'e' => "\e", 'f' => "\f", '\\' => '\\', '"' => '"', '$' => '$',
                ];
                return $map[$c] ?? $c;
            },
            $s
        );
    }

    /**
     * @param array<int,mixed> $toks
     * @return mixed
     */
    private function prevSig(array $toks, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $toks[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /**
     * @param array<int,mixed> $toks
     * @return mixed
     */
    private function nextSig(array $toks, int $i)
    {
        $n = count($toks);
        for ($j = $i; $j < $n; $j++) {
            $t = $toks[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /**
     * @param array<int,mixed> $toks
     * @return array<int,mixed>
     */
    private function trimWs(array $toks): array
    {
        $out = [];
        foreach ($toks as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }
}
