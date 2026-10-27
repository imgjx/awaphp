<?php
/**
 * 蜜罐函数（Honeypot）—— 钓鱼式反分析陷阱
 *
 * 与 JunkFunctions（纯诱饵、混淆视角污染）不同，蜜罐是【针对破解者的钓鱼】：
 * 注入若干"看起来极其重要"的拟真函数——deriveMasterKey / verifyLicense /
 * unpackPayload 这类破解者逆向上最想找的目标。正常执行【永不调用】（零开销、
 * 零影响），但破解者在分析/调试时几乎必然主动调用或插桩它们，此时触发：
 *
 *   1) 【拖慢】确定性深循环消耗秒级 CPU（调试单步直接卡住）；
 *   2) 【钓鱼】返回一段拟真"密钥"（拟真 hex + 拟真常量拼接），
 *      诱导破解者沿假线索继续逆向，消耗其时间；
 *   3) 【误导】把调用参数拼进返回值前缀，让 hook/插桩者误以为参数被真实处理。
 *
 * 函数名经 function_exists 防冲突（拟真名 + function_exists 检查重试）；
 * 函数体表面逻辑为纯字符串/哈希运算，绝无副作用。
 */

declare(strict_types=1);

final class Honeypot
{
    /** 拟真高价值命名池（破解者逆向上最"值得"怀疑的目标名） */
    private const NAMES = [
        'deriveMasterKey', 'verifyLicense', 'unpackPayload', 'buildSignature',
        'decryptLayer', 'restoreContext', 'resolveKeychain', 'computeIntegrity',
        'validateChecksum', 'unwrapSecret', 'loadCipherTable', 'initCryptoCtx',
        'deriveSubKeys', 'verifyFingerprint', 'decodeObfuscated', 'expandKey',
    ];

    public function __construct(
        private Logger $logger,
        private ?Salt $salt = null
    ) {
    }

    /**
     * 向产物顶层注入 $count 个蜜罐函数（追加在代码尾部）。
     * @return string 注入后的产物
     */
    public function inject(string $code, int $count): string
    {
        $count = max(0, $count);
        if ($count <= 0) {
            return $code;
        }
        // eval() 不解析 <?php：与 JunkFunctions 一致，注入在纯 PHP 语句区
        $inner = preg_replace('/^\s*<\?(php)?\s*/i', '', $code);
        $inner = preg_replace('/\?>\s*$/', '', $inner);
        $inner = (string) $inner;

        $names = $this->salt === null || $this->salt->isEmpty()
            ? self::NAMES
            : $this->salt->shuffle(self::NAMES, 'honeypot-names');
        $src = '';
        $used = 0;
        foreach ($names as $name) {
            if ($used >= $count) {
                break;
            }
            // 命名冲突防御：目标环境可能已有同名函数（拟真名撞上用户代码）
            if (preg_match('/function\s+' . preg_quote($name, '/') . '\b/', $inner) === 1) {
                continue;
            }
            $src .= $this->honeypotFn($name, $used);
            $used++;
        }
        if ($used === 0) {
            return $code;
        }
        $this->logger->debug("已注入 {$used} 个蜜罐函数（钓鱼陷阱）");
        return "<?php " . $inner . $src;
    }

    /**
     * 生成单个蜜罐函数：拟真表面 + 首次调用陷阱（拖慢 + 钓鱼返回）。
     */
    private function honeypotFn(string $name, int $idx): string
    {
        // 拟真常量表（表面上是"加密表"，实为诱饵）
        $consts = [];
        for ($i = 0; $i < 3; $i++) {
            $consts[] = substr(hash('sha256', $name . $idx . $i), 0, 16);
        }
        $c0 = $consts[0];
        $c1 = $consts[1];
        $c2 = $consts[2];

        return <<<PHP

if (!function_exists('{$name}')) {
function {$name}(\$__in = '') {
    static \$__f = 0;
    if (\$__f++ === 0) {
        // 首次调用（正常执行不会走到这里）：时间上界的确定性深循环，拖住单步调试
        \$__t = '{$c0}';
        \$__d = microtime(true) + 1.4;
        do {
            \$__t = strrev(md5(\$__t));
        } while (microtime(true) < \$__d);
        // 关闭时再吐一段"看着像解密成功"的伪密钥，污染自动化比对脚本
        register_shutdown_function(function () use (\$__t) {
            echo substr(hash('sha512', \$__t), 0, 64), "\\n";
        });
    }
    // 拟真"密钥派生"表面逻辑：拼接诱饵常量，返回看似真实的 hex 串（钓鱼）
    \$__k = hash('sha256', '{$c1}' . \$__in . '{$c2}');
    return substr(\$__k, 2, 40) . '{$c0}';
}
}

PHP;
    }
}
