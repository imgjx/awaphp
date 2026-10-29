<?php
/**
 * VM 虚拟机壳（VirtualMachine）
 *
 * 将整段顶层 PHP 语句切分为若干"指令"，每条指令以 base64(XOR KEY) 形式存入指令数组，
 * 由一个内置"虚拟机"循环逐条取指、解码（XOR 还原）并执行（eval）。
 * 同时维护一组"寄存器"($__REG)与程序计数器($__PC)，外观上模拟 CPU，实质仍由 PHP 解释。
 *
 * 跳过规则：若代码含匿名函数(T_FUNCTION 无名字)、箭头函数(T_FN)、match 表达式(T_MATCH)，
 * 或顶层存在裸 return/exit/break 等会破坏 eval 循环的语句，则直接返回原代码，保证正确性。
 */

declare(strict_types=1);

final class VirtualMachine
{
    public function __construct(private Logger $logger, private ?Salt $salt = null) {}

    public function wrap(string $code): string
    {
        $inner = preg_replace('/^\s*<\?php/i', '', $code, 1);
        $inner = preg_replace('/\?>\s*$/', '', $inner);
        if ($inner === '') {
            return $code;
        }

        $tokens = Parser::tokenize("<?php " . $inner);
        $body = [];
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $body[] = $t;
        }

        // 跳过含闭包/箭头函数/match 的代码（命名函数/方法定义是安全的顶层语句，不跳过）
        foreach ($body as $idx => $t) {
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_FN || $t[0] === T_MATCH) {
                $this->logger->warn('VM 壳跳过：代码含箭头函数/match');
                return $code;
            }
            if ($t[0] === T_FUNCTION) {
                // 若 T_FUNCTION 后紧跟 '(' 而非函数名，则为匿名闭包
                $nx = Parser::nextSignificant($body, $idx, 1);
                if ($nx >= 0 && !is_array($body[$nx])) {
                    $this->logger->warn('VM 壳跳过：代码含匿名闭包');
                    return $code;
                }
                if ($nx >= 0 && is_array($body[$nx]) && $body[$nx][0] === T_STRING) {
                    continue; // 命名函数/方法定义，安全
                }
                $this->logger->warn('VM 壳跳过：代码含匿名闭包');
                return $code;
            }
        }

        $stmts = StatementSplitter::split($body);
        $pieces = [];
        foreach ($stmts as $s) {
            $first = $this->firstSig($s);
            if ($first !== null && in_array($first, [T_RETURN, T_EXIT, T_BREAK, T_CONTINUE, T_THROW], true)) {
                $this->logger->warn('VM 壳跳过：顶层含控制流转移语句');
                return $code;
            }
            $pieces[] = $this->emit($s);
        }
        if (count($pieces) === 0) {
            return $code;
        }

        // ---- 密钥与命名（v2）----
        // 密钥不再明写存储：只存 seed，运行期 key = sha256(seed) 前 8 字节派生。
        // 指令/寄存器/计数器变量全部使用拟真命名，去除 $__KEY/$__CODE 指纹。
        $style = new NameStyle($this->salt ?? new Salt(bin2hex(random_bytes(8))));
        $seed = ($this->salt !== null && !$this->salt->isEmpty())
            ? $this->salt->derive('vm-seed', 8)
            : random_bytes(8);
        $key = substr(hash('sha256', $seed, true), 0, 8);
        $vSeed = $style->varName('vm-seed');
        $vKey  = $style->varName('vm-key');
        $vReg  = $style->varName('vm-reg');
        $vOps  = $style->varName('vm-ops');
        $vPc   = $style->varName('vm-pc');
        $vOp   = $style->varName('vm-op');

        $arr = [];
        foreach ($pieces as $p) {
            $arr[] = base64_encode($this->xorStr($p, $key));
        }
        $codeArr = var_export($arr, true);

        $head = "<?php\n"
            . $vSeed . "=base64_decode('" . base64_encode($seed) . "');\n"
            . $vKey . "=substr(hash('sha256'," . $vSeed . ",true),0,8);\n"
            . $vReg . "=[0,0,0];\n"
            . $vOps . "=" . $codeArr . ";\n"
            . $vPc . "=0;\n"
            . "while(" . $vPc . "<count(" . $vOps . ")){"
            . $vOp . "=" . $vOps . "[" . $vPc . "];"
            . $vReg . "[0]^=(" . $vPc . "&1);"
            . "@eval(base64_decode(" . $vOp . ")^str_repeat(" . $vKey . ",(int)strlen(" . $vOp . ")+8));"
            . $vPc . "++;}\n";

        $this->logger->debug('已应用 VM 虚拟机壳（指令数=' . count($pieces) . '）');
        return $head;
    }

    /** @param array<int,mixed> $tokens */
    private function emit(array $tokens): string
    {
        $out = '';
        $prev = '';
        foreach ($tokens as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if ($text === '') {
                continue;
            }
            if ($prev !== '' && $this->needsSpace($prev, $text[0])) {
                $out .= ' ';
            }
            $out .= $text;
            $prev = $text[strlen($text) - 1];
        }
        return $out;
    }

    private function needsSpace(string $prevChar, string $firstChar): bool
    {
        $wordy = fn (string $c) => $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80);
        return $wordy($prevChar) && $wordy($firstChar);
    }

    /** @param array<int,mixed> $tokens */
    private function firstSig(array $tokens)
    {
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t[0];
        }
        return null;
    }

    /**
     * 字符串按密钥做等长 XOR。PHP 的字符串 ^ 运算会截断到较短操作数长度，
     * 故这里把密钥按数据长度重复，确保逐字节异或（生成端与运行端均采用"重复密钥"）。
     */
    private function xorStr(string $data, string $key): string
    {
        if ($data === '') {
            return '';
        }
        $k = str_repeat($key, (int) (strlen($data) / max(1, strlen($key))) + 2);
        return $data ^ $k;
    }
}
