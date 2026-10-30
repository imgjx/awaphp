<?php
/**
 * 壳层自保护变换器（ShellProtector）
 *
 * 用途：把"每一层混淆壳自己的解码逻辑"再施加一层混淆，使攻击者必须先解开外层壳的
 * 混淆，才能读到内层壳的解码逻辑——而不是"破一层就直接拿到下一层的明文源码"。
 *
 * 设计要点：
 *   - 只作用于【当前这一层壳】的顶层解码代码；内层被编码壳裹成的字符串/字节码是
 *     黑盒（base64 / gz 串是单个 T_CONSTANT_ENCAPSED_STRING token，不会被改动），
 *     因此逐层套用后形成"解一层要先被恶心一层"的递归保护；
 *   - 三种模式：
 *       'full'     变量重命名 + token 形态 + 语义变换 + 压缩（最强，供普通壳用）；
 *       'norename' 仅 token 形态 + 语义变换（不动变量名，供 anti_tamper 等"靠变量
 *                  名自匹配 needle / 自带区段哈希"的壳用，避免破坏其自校验）；
 *       'caseonly' 仅 token 大小写随机（最轻，兜底）；
 *   - 全程 php -l 校验，任何变换抛异常或语法不过都回滚到原始传入代码，绝不降级为错误产物。
 *
 * 注意：本类不触碰字符串字面量内容（token_quote / token_heredoc 永远关闭），所以
 * 壳里承载内层密文的 base64 串绝不会被改写。
 */

declare(strict_types=1);

final class ShellProtector
{
    /**
     * 对一段 PHP 源码施加壳层自保护变换。
     *
     * @param string      $code   待保护代码（通常是一层壳包裹后的完整文件）
     * @param Salt|null   $salt   用于确定性命名（空则随机，仍安全）
     * @param Logger      $logger
     * @param string      $mode   'full' | 'norename' | 'caseonly'
     * @param int         $strength 语义变换强度（1-3，仅 full/norename 生效）
     * @return string 保护后的代码；失败时原样返回（不影响主流程）
     */
    public static function protect(
        string $code,
        ?Salt $salt,
        Logger $logger,
        string $mode = 'full',
        int $strength = 2
    ): string {
        $salt = $salt ?? new Salt(bin2hex(random_bytes(8)));
        $before = $code;
        try {
            $tokens = Parser::tokenize($code);
            // 关键安全步骤：行注释（// 与 #）在压缩去换行后会"吞掉"紧随其后的整段代码，
            // 导致产物运行期静默无输出。统一在压缩前转成块注释（/* ... */），
            // 块注释不依赖尾随换行，压缩后语义不变。
            $tokens = self::normalizeLineComments($tokens);
            if ($mode !== 'norename') {
                // 变量重命名（full / caseonly 都可带——但 caseonly 默认也跑，增强混淆）
                // var_keep_props=true：壳层自保护作用于【整个产物】，其中可能含类保留 VM 生成
                // 的真实类桩（protected $x / $this->x 等）。属性名必须保持原样，否则桩的声明
                // 与引用被各自改名而脱节 → 运行期动态属性告警/属性丢失。局部变量仍照常重命名。
                $cfg = new Config(['var_length' => 12, 'var_keep_props' => true, 'var_keep_params' => true]);
                $tokens = (new VariableObfuscator($salt, $logger))->process($tokens, $cfg);
            }
            if ($mode !== 'caseonly') {
                $strength = max(1, min(3, $strength));
                $cfg = new Config(['semantic' => true, 'semantic_strength' => $strength]);
                $tokens = (new SemanticRewriter($salt, $logger))->process($tokens, $cfg);
            }
            // token 形态：只开大小写 + 字面量（数字/true/false 等价形式），永远不开引号改写
            $cfg = new Config([
                'token_obf'     => true,
                'token_case'    => true,
                'token_literal' => true,
                'token_quote'   => false,
                'token_heredoc' => false,
                'token_decoy'   => 0,
                'no_comments'   => false,
            ]);
            $tokens = (new TokenObfuscator($salt, $logger))->process($tokens, $cfg);
            // 压缩空白
            $cfg = new Config(['compress' => true, 'strip_newline' => true]);
            $tokens = (new Whitespace($logger))->process($tokens, $cfg);
            $out = self::emit($tokens);
        } catch (Throwable $e) {
            $logger->warn('[壳层自保护] 变换异常，回滚：' . $e->getMessage());
            return $before;
        }
        $check = (new Validator($logger))->checkSyntaxString($out);
        if (!$check['ok']) {
            // 先尝试序言归一修复（把被推后的 declare/namespace 提到最前），修复成功则复用，
            // 避免自保护整体回退（壳仍在，但自保护强度丢失）。
            $fixed = DeclareHoist::reconcile($out);
            if ($fixed !== $out) {
                $c2 = (new Validator($logger))->checkSyntaxString($fixed);
                if (!empty($c2['ok'])) {
                    return $fixed;
                }
            }
            // 调试钩子：OBF_SHELL_DUMP=<目录> 时把非法产物与原始输入落盘，便于定位
            $dumpDir = getenv('OBF_SHELL_DUMP');
            if (is_string($dumpDir) && $dumpDir !== '') {
                @mkdir($dumpDir, 0777, true);
                $tag = date('His') . '_' . substr(md5($out), 0, 6);
                @file_put_contents($dumpDir . '/' . $tag . '.bad.php', $out);
                @file_put_contents($dumpDir . '/' . $tag . '.in.php', $before);
            }
            $logger->warn('[壳层自保护] 产物未通过 php -l，回滚');
            return $before;
        }
        return $out;
    }

    /** 把 token 数组重建为源码字符串（与 Obfuscator::emit 等价的最小实现）。 */
    private static function emit(array $tokens): string
    {
        $out = '';
        $prevChar = '';
        $prevTok = null;
        foreach ($tokens as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if ($text === '') {
                continue;
            }
            // 开标签尾部必须留一个边界（空格），避免 <?phpfunction 这类粘连被误判为标签体/字面量
            if (is_array($tok) && $tok[0] === T_OPEN_TAG) {
                $text = rtrim($text) . ' ';
            }
            if ($prevChar !== '' && self::needsSpace($prevTok, $prevChar, $text, $tok)) {
                $out .= ' ';
            }
            $out .= $text;
            $prevChar = $text[strlen($text) - 1];
            $prevTok = $tok;
        }
        return $out;
    }

    /**
     * 把行注释（// 与 #）改写为块注释（/* ... *\/），使其在压缩去换行后
     * 不会把后续代码吞进注释。块注释内部若出现 *\/ 先做转义，避免提前闭合。
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    private static function normalizeLineComments(array $tokens): array
    {
        foreach ($tokens as &$tok) {
            if (!is_array($tok) || $tok[0] !== T_COMMENT) {
                continue;
            }
            $t = $tok[1];
            $isLine = str_starts_with($t, '//') || $t[0] === '#';
            if (!$isLine) {
                continue; // 已经是 /* */ 块注释或 /** */ 文档注释，无需处理
            }
            $body = str_starts_with($t, '//') ? substr($t, 2) : substr($t, 1);
            $body = str_replace('*/', '* /', $body);
            $tok[1] = '/*' . $body . '*/';
        }
        unset($tok);
        return $tokens;
    }

    private static function needsSpace($prevTok, string $prevChar, string $text, $tok = null): bool
    {
        $wordy = fn (string $c) => $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80);
        if ($wordy($prevChar) && $wordy($text[0])) {
            return true;
        }
        // new / extends / instanceof 等关键字后紧跟命名空间名（`new \stdClass()`）时，
        // 压缩去空白会变成 `new\stdClass` —— PHP 词法器把它当成"函数调用"而非对象实例化，
        // 直接报 "Call to undefined function new\stdClass()"。故词素单词后接 `\` 起头的
        // 命名空间名必须补空格。
        // 但只对【名字类 token】生效：双引号插值里的转义字面量（T_ENCAPSED_AND_WHITESPACE
        // 的 "\n" / "\t"）文本也以反斜杠开头，补空格会改掉字符串内容（"$v\n"→"$v \n"）。
        if ($wordy($prevChar) && $text[0] === '\\' && self::isNameToken($tok)) {
            return true;
        }
        if ($text[0] === '.' && is_array($prevTok)
            && in_array($prevTok[0], [T_LNUMBER, T_DNUMBER], true)) {
            return true;
        }
        return false;
    }

    /** 是否为"名字类" token（命名空间名 / 类名 / 裸分隔符）。 */
    private static function isNameToken($tok): bool
    {
        if (!is_array($tok)) {
            return false;
        }
        $types = [T_STRING, T_NS_SEPARATOR];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
            if (defined($c)) {
                $types[] = constant($c);
            }
        }
        return in_array($tok[0], $types, true);
    }
}
