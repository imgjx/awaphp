<?php
/**
 * 反剥离互锁壳（AntiStrip）
 *
 * 针对「脱壳 / 抠层」攻击：攻击者把多层壳的最外层 eval 解出、或直接把某一层的
 * 解码结果另存为独立文件运行时，常规壳无法区分「正常层层解码」与「被剥离后单跑」。
 *
 * 本壳构造 K 层（默认 3，最小 2）互锁的 eval 链：
 *   - 每一层解码前先 define 一个 salt 派生的锚点常量（拟真命名，值随 salt/层号确定）；
 *   - 最内层（真正执行用户源码的那一层）在 eval 之前做一次「锚点网格」校验：
 *     要求**全部** K 个锚点常量都已定义且值逐一匹配；
 *   - 正常执行时 K 层自外向内依次 define，锚点网格完整 → 放行；
 *   - 一旦剥离 / 抠出任意内层单独运行，缺失的那些外层锚点不会被 define，
 *     网格校验失败 → 静默拒绝执行真实源码（silent）或直接终止（exit）。
 *
 * 与既有纵深的关系：
 *   - StructPoison：改文件结构 → 密钥错位解乱码（防静态篡改）；
 *   - AntiHookEval：eval 被替换为 echo 立即自杀（防动态 hook）；
 *   - ShellBinding/AntiDebug 'shell' 组：抠出 VM 内层独立运行 → 标记消失 → 终止；
 *   - AntiStrip：补齐「任意 eval 子层被抠出独立运行」这一格，且**不依赖文件明文锚点**，
 *     锚点是运行期 define 的全局常量，编码壳把守卫一起裹进密文，必须先解全部层才能看到。
 *
 * 关键正确性约束（与 EvalShell 一致）：
 *   - 守卫放在最内层 eval **之外**，因此用户源码里的 declare(strict_types=1)/namespace
 *     仍是 eval 字符串的首句，不破坏「declare must be the very first statement」；
 *   - 用户源码含顶层 return（provider/config 等被 include 取返回值的文件）时，所有层统一
 *     用 `return @eval($d);` 逐层透传，include 语义与原生一致；
 *   - 载荷用 gzdeflate + 8 字节 salt 派生密钥 XOR + base64，密钥前置随载荷存储、
 *     不明文落地；每层密钥独立。
 *
 * 盐为空时由 Salt::derive 内部走随机兜底（每次构建不同，避免同构指纹）。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/NameStyle.php';
require_once __DIR__ . '/../Support/TokenScan.php';

final class AntiStrip
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param array{depth?:int,response?:string} $opts
     *   depth:    互锁层数（2-6，默认 3）
     *   response: 网格校验失败时的反制：'silent'（静默拒绝执行，默认）| 'exit'（终止）
     */
    public function wrap(string $code, array $opts = []): string
    {
        $depth  = max(2, min(6, (int) ($opts['depth'] ?? 3)));
        $silent = (($opts['response'] ?? 'silent') !== 'exit');

        // 剥离最外层开标签 / 末尾闭标签（与 EvalShell 一致，保留 declare/namespace 于载荷内）
        $inner = preg_replace('/^\s*<\?php/i', '', $code, 1);
        $inner = (string) preg_replace('/\?>\s*$/', '', (string) $inner);
        if (trim($inner) === '') {
            $this->logger->warn('AntiStrip: 主体为空，跳过');
            return $code;
        }
        $propagate = TokenScan::hasTopLevelReturn($inner);

        $style  = new NameStyle($this->salt);
        $names  = [];
        $tokens = [];
        for ($i = 0; $i < $depth; $i++) {
            $names[$i]  = strtoupper($style->funcName('as-anchor-' . $i));
            $tokens[$i] = $this->salt->deriveHex('antistrip-token-' . $i, 16);
        }
        // 单引号字符串转义（token / 常量名 / base64 密文均只含 [A-Za-z0-9_/+='] 等安全字符）
        $q = static function (string $s): string {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
        };

        // $content 是「上一层解码后要 eval 的内容」：第 0 层即用户源码，之后逐层为内层解码器代码。
        $content = $inner;
        for ($i = 0; $i < $depth; $i++) {
            // 每层解码器用独立拟真变量名，避免跨 eval（共享作用域）相互覆盖
            $vR = $style->varName('as-' . $i . '-raw');
            $vK = $style->varName('as-' . $i . '-key');
            $vX = $style->varName('as-' . $i . '-xor');
            $vD = $style->varName('as-' . $i . '-data');

            $key  = $this->salt->derive('antistrip-key-' . $i, 8);
            $blob = base64_encode($key . $this->xorStr((string) gzdeflate($content, 9), $key));

            // 解码：base64 → 拆出前置 8 字节密钥 → 重复密钥 XOR → gzinflate
            $dec = $vR . '=base64_decode(' . $q($blob) . ');'
                . $vK . '=substr(' . $vR . ',0,8);'
                . $vX . '=substr(' . $vR . ',8);'
                . $vX . '=' . $vX . '^substr(str_repeat(' . $vK
                . ',(int)(strlen(' . $vX . ')/8)+2),0,strlen(' . $vX . '));'
                . $vD . '=@gzinflate(' . $vX . ');';

            // 本层锚点：未定义才 define，避免同进程重复 include 时告警；
            // 攻击者若预置同名假值，网格校验的值比对仍会失败。
            $layer = 'if(!defined(' . $q($names[$i]) . '))define(' . $q($names[$i]) . ',' . $q($tokens[$i]) . ');';
            $layer .= $dec;

            if ($i === 0) {
                // 锚点网格守卫：全部 K 个锚点常量必须就位且值逐一匹配。
                // 短路避免对未定义常量调用 constant()（PHP 8 会抛 Error）。
                $vOk = $style->varName('as-grid-ok');
                $layer .= $vOk . '=true;';
                for ($j = 0; $j < $depth; $j++) {
                    $layer .= 'if(!defined(' . $q($names[$j]) . ')||constant(' . $q($names[$j]) . ')!=='
                        . $q($tokens[$j]) . ')' . $vOk . '=false;';
                }
                $layer .= 'if(!' . $vOk . '){' . ($silent ? 'return;' : 'exit(1);') . '}';
            }
            $layer .= $propagate ? 'return @eval(' . $vD . ');' : '@eval(' . $vD . ');';

            $content = $layer; // 下一层把「本层解码器代码」再编码进去
        }

        $this->logger->debug('已应用反剥离互锁壳（层数=' . $depth
            . ' 反制=' . ($silent ? 'silent' : 'exit') . ($propagate ? ' 返回值透传' : '') . '）');
        return '<?php ' . $content;
    }

    /** 重复密钥 XOR（与运行期解法一致） */
    private function xorStr(string $data, string $key): string
    {
        if ($data === '') {
            return '';
        }
        $ks = str_repeat($key, (int) (strlen($data) / max(1, strlen($key))) + 2);
        return $data ^ substr($ks, 0, strlen($data));
    }
}
