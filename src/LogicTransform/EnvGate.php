<?php
/**
 * 运行时环境门闩（EnvGate / envgate 层，v1.3.0）
 *
 * 目标（针对"静态分析 + 动态插桩"两类逆向手段）：
 *   1. **把恒真判断从"自消恒等式"换成"环境事实"**。
 *      过去状态机/诱饵用的 `($x ^ $x) === 0`、`strlen(md5(...))` 之类，静态扫一眼就
 *      知道 else 是死代码。本层改成一个**只有到运行期才知道答案**的问题：
 *      PHP 版本是什么、现在几点、装没装调试扩展、本文件是不是被 include 进来的。
 *      答案要到运行期才确定，且**换一台机器答案可能就变了**，静态侧无法把分支剪掉。
 *   2. **同一条语句在多次执行时走不同分支**。
 *      条件里带了自增计数器（`$n=$n+1`），同一表达式第二次求值结果与第一次不同。
 *      任何"跑到不动点就停"的常量折叠 / 符号执行都会在第二轮拿到不同的中间值，
 *      于是要么放弃折叠，要么折出一个自相矛盾的结果（自我污染）。
 *   3. **动态插桩拿到的路径不可复用**。
 *      两个分支**语义完全等价**（只做变量替身与同值改写），所以走哪条都正确；
 *      但被 xdebug 接管时 `extension_loaded('xdebug')` 为真 → 走的分支和线上不同，
 *      插桩者辛苦跟踪到的那条路径，在真实环境里根本不会出现。
 *   4. **成本极低**。
 *      文件级探针只跑一次（time/extension_loaded/get_included_files）；
 *      语句级门闩只用移位 + 异或 + 与（对象是整型字面量与一个计数器），
 *      单次开销与一次数组下标访问同量级，远低于 `strlen(md5())` 这类积木。
 *      复杂度（分支结构、静态可达路径数）不降反升 —— 正是"降本增效"要的方向。
 *
 * 注入形态（文件级，插在 declare / namespace 之后）：
 *
 *   $v=PHP_VERSION_ID;                        // 版本识别
 *   $t=time();                                // 时间识别
 *   $d=(int)(bool)(extension_loaded('xdebug')||function_exists('xdebug_info')||ini_get('xdebug.mode'));
 *                                             // 反调试布尔（是否处于调试/插桩环境）
 *   $i=(int)(count(get_included_files())>1);  // 是否被 include 进测试脚本
 *   $n=0;
 *   $f=(((($v>>8)^($t&255))^$d^$i)^($n=$n+1))&1;   // 环境指纹（含计数器）
 *   if($f){$n=$n-1;}else{$n=$n+1;}            // 两条分支同值，但走向取决于环境
 *
 * 语句级门闩（把一条 echo/print 包成两路，两路输出逐字节相同）：
 *
 *   if((((($v>>8)^($t&255)^$d^$i^($n=$n+1))&1))){ echo $a . 'x'; }
 *   else { $q=$a; echo $q . 'x'; }
 *
 * 约束（宁可不混淆，也不改炸）：
 *   - 只包 `echo` / `print` 语句；含赋值、自增、`->`、`::`、闭包、`yield`、
 *     `@`、引用、超全局、`$this`、插值串的整条跳过。
 *   - 两支输出必须逐字节相同：else 支只做「变量拷进新名字再用」的同值替换。
 *   - 不引入任何函数调用到语句级门闩里（版本用常量、时间只在文件级取一次）。
 *   - `__halt_compiler` / 内联 HTML / 多命名空间 / bracket 命名空间 整文件跳过。
 */

declare(strict_types=1);

final class EnvGate
{
    private Salt $salt;
    private Logger $logger;
    private int $maxGates = 8;
    private bool $useTime = true;
    private bool $useVersion = true;
    private bool $useDebug = true;
    private bool $useInclude = true;
    private int $ctr = 0;
    private string $sfx = '';
    /** @var array<string,string> 探针变量名（含 $） */
    private array $pv = [];

    public function __construct(Salt $salt, Logger $logger)
    {
        $this->salt = $salt;
        $this->logger = $logger;
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $cfg): array
    {
        $this->maxGates = max(0, (int) $cfg->get('envgate_max', 8));
        $this->useVersion = (bool) $cfg->get('envgate_version', true);
        $this->useTime    = (bool) $cfg->get('envgate_time', true);
        $this->useDebug   = (bool) $cfg->get('envgate_debug', true);
        $this->useInclude = (bool) $cfg->get('envgate_include', true);
        $this->ctr = 0;

        $n = count($tokens);
        if ($n === 0) {
            return $tokens;
        }
        if (!$this->fileIsSafe($tokens)) {
            return $tokens;
        }
        $ins = $this->prologueIndex($tokens);
        if ($ins < 0) {
            return $tokens;
        }
        // 四个探针至少要有一个真正参与判断，否则本层没有意义
        if (!$this->useVersion && !$this->useTime && !$this->useDebug && !$this->useInclude) {
            return $tokens;
        }

        $this->sfx = substr(bin2hex($this->salt->derive('envgate|' . $this->fileNs($tokens), 5)), 0, 8);
        $this->pv = [
            'v' => '$__v' . $this->sfx,
            't' => '$__t' . $this->sfx,
            'd' => '$__d' . $this->sfx,
            'i' => '$__i' . $this->sfx,
            'n' => '$__n' . $this->sfx,
        ];

        $prologue = $this->tokenizeFragment($this->buildPrologue());

        // ---- 语句切分（只按圆括号深度定界，排除 for/declare 头里的分号）----
        $stmts = [];
        $paren = 0;
        $brace = 0;
        $start = -1;
        $startDepth = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $txt = Parser::text($t);
            if ($start < 0 && !Parser::isWhitespaceOrComment($t)) {
                $start = $i;
                $startDepth = $brace;
            }
            if ($txt === '(') {
                $paren++;
                continue;
            }
            if ($txt === ')') {
                $paren = max(0, $paren - 1);
                continue;
            }
            if ($txt === '{') {
                $brace++;
                continue;
            }
            if ($txt === '}') {
                $brace = max(0, $brace - 1);
                // 块结束：当前语句作废（避免把 `if(...){` 当成语句起点）
                $start = -1;
                continue;
            }
            if ($paren === 0 && $txt === ';') {
                if ($start >= 0 && $start > $ins) {
                    $stmts[] = [$start, $i, $startDepth];
                }
                $start = -1;
            }
        }

        // ---- 逐条尝试包门闩 ----
        $replace = [];
        $gates = 0;
        foreach ($stmts as [$s, $e, $depth]) {
            if ($gates >= $this->maxGates) {
                break;
            }
            $info = $this->analyzeEcho($tokens, $s, $e);
            if ($info === null) {
                continue;
            }
            $code = $this->buildGate($info, $depth);
            $frag = $this->tokenizeFragment($code);
            if ($frag === []) {
                continue;
            }
            $replace[$s] = ['end' => $e, 'tokens' => $frag];
            $gates++;
        }

        // ---- 组装：原 token + 插入 prologue + 替换门闩 ----
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === $ins) {
                foreach ($prologue as $tk) {
                    $out[] = $tk;
                }
            }
            if (isset($replace[$i])) {
                foreach ($replace[$i]['tokens'] as $tk) {
                    $out[] = $tk;
                }
                $i = $replace[$i]['end'];
                continue;
            }
            $out[] = $tokens[$i];
        }
        if ($ins >= $n) {
            foreach ($prologue as $tk) {
                $out[] = $tk;
            }
        }

        $this->logger->debug(sprintf('envgate: 注入文件级环境探针，包裹 %d 条语句', $gates));
        return $out;
    }

    /** 文件指纹（用于变量名后缀，跨文件隔离、同文件可复现）。 */
    private function fileNs(array $tokens): string
    {
        $sig = '';
        $k = 0;
        foreach ($tokens as $t) {
            if (Parser::isWhitespaceOrComment($t)) {
                continue;
            }
            $sig .= Parser::text($t);
            if (++$k >= 512) {
                break;
            }
        }
        return substr(md5($sig), 0, 6);
    }

    private function fileIsSafe(array $tokens): bool
    {
        $ns = 0;
        foreach ($tokens as $t) {
            if (!Parser::isToken($t)) {
                continue;
            }
            if ($t[0] === T_HALT_COMPILER || $t[0] === T_INLINE_HTML) {
                return false;
            }
            if ($t[0] === T_NAMESPACE && ++$ns > 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * prologue 插入点：第一个 `<?php` 之后，跳过 declare(...) 与 namespace ...; 语句。
     * 返回"应插在其前面"的 token 下标；-1 表示不安全（bracket namespace 等）。
     */
    private function prologueIndex(array $tokens): int
    {
        $n = count($tokens);
        $open = -1;
        for ($i = 0; $i < $n; $i++) {
            if (Parser::isToken($tokens[$i]) && $tokens[$i][0] === T_OPEN_TAG) {
                $open = $i;
                break;
            }
        }
        if ($open < 0) {
            return -1;
        }
        $i = $open + 1;
        while ($i < $n) {
            $j = Parser::nextSignificant($tokens, $i - 1);
            if ($j < 0) {
                return $n;
            }
            if (!Parser::isToken($tokens[$j])) {
                return $j;
            }
            $tid = $tokens[$j][0];
            if ($tid === T_DECLARE || $tid === T_NAMESPACE || $tid === T_USE) {
                // 跳到本条语句的结尾 ';'；遇到 '{' 说明是 bracket 形式 → 放弃
                $end = $this->stmtEnd($tokens, $j);
                if ($end < 0) {
                    return -1;
                }
                $i = $end + 1;
                continue;
            }
            return $j;
        }
        return $n;
    }

    /** 从 $from 起找当前语句的结尾 ';'（括号内不算）；遇到 '{' 返回 -1。 */
    private function stmtEnd(array $tokens, int $from): int
    {
        $n = count($tokens);
        $paren = 0;
        for ($i = $from; $i < $n; $i++) {
            $txt = Parser::text($tokens[$i]);
            if ($txt === '(') {
                $paren++;
                continue;
            }
            if ($txt === ')') {
                $paren = max(0, $paren - 1);
                continue;
            }
            if ($paren === 0 && $txt === '{') {
                return -1;
            }
            if ($paren === 0 && $txt === ';') {
                return $i;
            }
        }
        return -1;
    }

    private function buildPrologue(): string
    {
        $v = $this->pv['v'];
        $t = $this->pv['t'];
        $d = $this->pv['d'];
        $i = $this->pv['i'];
        $n = $this->pv['n'];

        $lines = [];
        // 版本识别：PHP_VERSION_ID 是运行期常量（同一份产物在不同 PHP 上取值不同）
        $lines[] = $v . '=' . ($this->useVersion ? 'PHP_VERSION_ID' : '0') . ';';
        // 时间识别：只在文件级取一次，避免每条语句都调 time()
        $lines[] = $t . '=' . ($this->useTime ? 'time()' : '0') . ';';
        // 反调试布尔：调试器 / 插桩扩展是否在场（xdebug、dbg 家族）
        $lines[] = $d . '=(int)(bool)' . ($this->useDebug
            ? "(extension_loaded('xdebug')||function_exists('xdebug_info')||ini_get('xdebug.mode')||extension_loaded('dbg'))"
            : '(false)') . ';';
        // 是否被 include 进测试脚本（单文件直接执行时 get_included_files() 只有 1 个）
        $lines[] = $i . '=' . ($this->useInclude ? '(int)(count(get_included_files())>1)' : '0') . ';';
        $lines[] = $n . '=0;';

        // 环境指纹：移位 + 异或 + 与，全整型，零函数调用
        $fp = '((' . $v . '>>8)^(' . $t . '&255)^' . $d . '^' . $i . '^(' . $n . '=' . $n . '+1))&1';
        $lines[] = 'if((' . $fp . ')){' . $n . '=' . $n . '-1;}else{' . $n . '=' . $n . '+1;}';

        return implode('', $lines);
    }

    /**
     * 识别一条纯 echo/print 语句。
     * @return array{text:string,expr:string,vars:array<int,string>,kw:string}|null
     */
    private function analyzeEcho(array $tokens, int $s, int $e): ?array
    {
        $k = -1;
        for ($i = $s; $i <= $e; $i++) {
            if (!Parser::isWhitespaceOrComment($tokens[$i])) {
                $k = $i;
                break;
            }
        }
        if ($k < 0 || !Parser::isToken($tokens[$k])) {
            return null;
        }
        $kw = $tokens[$k][0];
        if ($kw !== T_ECHO && $kw !== T_PRINT) {
            return null;
        }

        $vars = [];
        for ($i = $k + 1; $i < $e; $i++) {
            $t = $tokens[$i];
            if (Parser::isToken($t)) {
                $tid = $t[0];
                if ($tid === T_VARIABLE) {
                    $nm = (string) $t[1];
                    if ($nm === '$this' || strncmp($nm, '$GLOBALS', 8) === 0
                        || preg_match('/^\$_(SERVER|GET|POST|FILES|COOKIE|SESSION|ENV|REQUEST)$/', $nm)) {
                        return null;
                    }
                    $vars[$nm] = $nm;
                    continue;
                }
                if (in_array($tid, [
                    T_INC, T_DEC, T_FUNCTION, T_FN, T_YIELD, T_YIELD_FROM, T_RETURN,
                    T_BREAK, T_CONTINUE, T_GOTO, T_EXIT, T_THROW, T_GLOBAL, T_STATIC,
                    T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL,
                    T_UNSET, T_ISSET, T_EMPTY, T_LIST, T_MATCH, T_CLONE, T_NEW,
                    T_LINE, T_FILE, T_DIR, T_CLASS_C, T_TRAIT_C, T_METHOD_C, T_FUNC_C, T_NS_C,
                    T_START_HEREDOC, T_END_HEREDOC, T_ENCAPSED_AND_WHITESPACE,
                    T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE,
                    T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
                    T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL,
                    T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_CONCAT_EQUAL, T_SL_EQUAL,
                    T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
                ], true)) {
                    return null;
                }
                continue;
            }
            $txt = (string) $t;
            if ($txt === '&' || $txt === '@' || $txt === '=') {
                return null;
            }
        }
        if ($vars === []) {
            return null; // 没有可同理替换的变量，两支会长得一模一样，不值得包
        }
        $expr = '';
        for ($i = $k + 1; $i < $e; $i++) {
            $expr .= Parser::text($tokens[$i]);
        }
        $expr = $this->trimExprSafe($expr);
        if ($expr === '') {
            return null;
        }
        $text = '';
        for ($i = $s; $i <= $e; $i++) {
            $text .= Parser::text($tokens[$i]);
        }
        return [
            'text' => $text,
            'expr' => $expr,
            'vars' => array_values($vars),
            'kw'   => $kw === T_ECHO ? 'echo' : 'print',
        ];
    }

    /**
     * 构造门闩。$depth 为语句所在的 `{` 深度：
     *   0 → 顶层，探针变量在作用域内，用「计数器 + 全探针」动态形态；
     *   >0 → 函数/方法体内，探针变量不在作用域，改用常量形态（版本 + salt 常量）。
     */
    private function buildGate(array $info, int $depth): string
    {
        $k = (int) hexdec(substr(bin2hex($this->salt->derive('envgate-k|' . $this->ctr, 3)), 0, 5));
        $this->ctr++;

        if ($depth === 0) {
            $v = $this->pv['v'];
            $t = $this->pv['t'];
            $d = $this->pv['d'];
            $i = $this->pv['i'];
            $n = $this->pv['n'];
            $cond = '(((' . $v . '>>8)^(' . $t . '&255)^' . $d . '^' . $i . '^(' . $n . '=' . $n . '+1))&1)';
        } else {
            // 只用编译期常量 + 一个 salt 派生的整数：零运行期成本，且静态侧必须
            // 先算清本机 PHP 版本才能知道走哪一支。
            $cond = '(((PHP_VERSION_ID>>8)^' . $k . ')&1)';
        }

        // else 支：变量先拷进新名字再用，两支文本不同、输出逐字节相同
        $exprB = $info['expr'];
        $prefix = '';
        foreach ($info['vars'] as $idx => $name) {
            $tmp = '$__g' . $this->sfx . 'x' . $idx;
            $prefix .= $tmp . '=' . $name . ';';
            $exprB = preg_replace('/\Q' . $name . '\E\b/', $tmp, $exprB) ?? $exprB;
        }
        // 真支也要用「关键字 + 显式空格 + 参数」重建，不能直接拼接原始 token 切片：
        // ws 压缩层已经把 echo 与后面 token 之间的空白 token 删掉了，而 `echo"…"` /
        // `echo$v` 本身是合法 PHP，切片里就看不到那个空格。可一旦后续（或先前）的字符串层
        // 把字面量换成了 `str_rot13(...)` 这类函数调用，原始切片重排后就变成
        // `echostr_rot13(` —— 被词法器并成单个 T_STRING，运行期 "Call to undefined
        // function ECHOstr_rot13()"。显式补一个空格即可彻底消除这类粘连。
        $textA = ($info['kw'] === 'print' ? 'print ' : 'echo ') . $info['expr'] . ';';

        // 原语句文本（echo/print 关键字 + 参数 + 分号）在同值替换后重建
        $textB = ($info['kw'] === 'print' ? 'print ' : 'echo ') . $exprB . ';';

        return '{if(' . $cond . '){' . $textA . '}else{' . $prefix . $textB . '}}';
    }

    /**
     * 表达式切片收尾。
     *
     * 直接 trim() 有个致命副作用：若表达式末尾落在 `//` / `#` 行注释里，trim 会把
     * 注释结尾的换行一并削掉，随后拼上的 `;` / `}` 就落进了注释里 —— 整条语句被吞掉，
     * 产物表现为控制流错乱（实测 complex_app 在 high 档 + 特定盐下直接死循环）。
     * 行注释之后补一个换行永远合法（纯空白），因此宁可多补一个。
     */
    private function trimExprSafe(string $expr): string
    {
        $expr = trim($expr);
        if ($expr === '') {
            return $expr;
        }
        $nl = strrpos($expr, "\n");
        $lastLine = $nl === false ? $expr : substr($expr, $nl + 1);
        if (preg_match('~//~', $lastLine) === 1 || preg_match('~(^|\s)#~', $lastLine) === 1) {
            return $expr . "\n";
        }
        return $expr;
    }

    /** 把一段 PHP 代码解析成 token（去掉临时加的 `<?php ` 前缀）。 */
    private function tokenizeFragment(string $code): array
    {
        $t = Parser::tokenize('<?php ' . $code);
        if ($t !== [] && Parser::isToken($t[0]) && $t[0][0] === T_OPEN_TAG) {
            array_shift($t);
        }
        if ($t !== [] && $t[0] === ' ') {
            array_shift($t);
        }
        return $t;
    }
}
