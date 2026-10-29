<?php
/**
 * 防 hook eval（AntiHookEval）
 *
 * 逆向者最常用的手段之一，是把源码里的 `eval(` 替换成 `echo(` / `print(`（或用 uopz / runkit
 * 之类的扩展给 eval 打钩子），让加壳内容被"打印出来"而不是"执行掉"，从而一次性 dump 出全部中间层。
 *
 * 本模块从两个方向反制：
 *
 * 1) eval 完整性探针（核心）
 *    构造一个随机变量名与随机期望值，用 `@eval('$VAR="期望值";')` 写入，随后立即校验该变量是否
 *    真被赋成期望值。若 eval 被替换成 echo/print（或任何不真正执行的钩子），变量就不会被赋值，
 *    校验失败 -> 立即终止。这样"批量替换 eval 为 echo"这一经典套路将直接让产物自杀，拿不到任何明文。
 *
 * 2) hook 环境检测
 *    检测 uopz / runkit / runkit7 扩展、phpdbg SAPI 等可被用来劫持 eval 的运行时。
 *
 * 与自校验守卫一样，整段判定逻辑被 gzdeflate + base64 编码后由一行 `@eval(gzinflate(...))` 执行，
 * 因此 'uopz' / 'phpdbg' 等特征串与期望值均不以明文出现。
 *
 * 运行期开销：仅一次极短的 eval 与若干 extension_loaded / function_exists 调用，无忙等、无延时。
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/DeclareHoist.php';

final class AntiHookEval
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * 注入"防 hook eval"守卫。
     *
     * @param array{strict?:bool} $opts strict=true 时同时检测可劫持函数的扩展
     *                                    （uopz / runkit / runkit7 / phpdbg）
     * @return array{code:string, ok:bool}
     */
    public function wrap(string $code, array $opts = []): array
    {
        $strict = (bool) ($opts['strict'] ?? true);
        if (!preg_match('/<\?(php)?/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $this->logger->warn('AntiHookEval: 未找到 PHP 开标签，跳过');
            return ['code' => $code, 'ok' => false];
        }
        $openPos = (int) $m[0][1];
        $openLen = strlen($m[0][0]);

        // 前导 declare(strict_types=1) 必须仍在最前面（守卫行插到它之后）
        $split  = DeclareHoist::split($code);
        $prefix = $split['prefix'];
        $body   = $split['body'];      // 开标签（+ 前导 declare）之后的全部内容

        // 随机量由 salt 派生：同一 salt 可复现；不同 salt 互不相同。
        // 命名全部拟真化（NameStyle），去除 __awa_ 指纹。
        $style = new NameStyle($this->salt);
        $hookFn   = $style->funcName('antihookeval-guard');
        $probeVar = $style->varName('antihookeval-probe');
        $lineVar  = $style->varName('antihookeval-line');
        $expect   = $this->salt->deriveHex('antihookeval-expect', 12);

        // ---- 生成的守卫逻辑（会被编码，不以明文留在文件里）----
        $guard = "if(!function_exists('" . $hookFn . "')){function " . $hookFn . "(){"
            // 可被用来劫持 eval / 重定义函数的扩展（strict 模式）
            . ($strict
                ? "\$r=false;"
                  . "if(extension_loaded('uopz'))\$r=true;"
                  . "if(extension_loaded('runkit')||extension_loaded('runkit7'))\$r=true;"
                  . "if(function_exists('uopz_set_hook')||function_exists('uopz_set_return')||function_exists('uopz_allow_exit'))\$r=true;"
                  . "if(function_exists('runkit_function_redefine')||function_exists('runkit7_function_redefine'))\$r=true;"
                  . "if(defined('PHP_SAPI')&&PHP_SAPI==='phpdbg')\$r=true;"
                  . "if(function_exists('phpdbg_break_file'))\$r=true;"
                  . "return \$r;}}"
                : "return false;}")
            // eval 完整性探针：写入随机变量
            . "\$" . $probeVar . "=null;"
            . "@eval('\$" . $probeVar . "=\"" . $expect . "\";');"
            // 判定：eval 未真正生效，或处于可 hook 环境 -> 终止
            . "if(\$" . $probeVar . "!==\"" . $expect . "\"||" . $hookFn . "()){"
            . "@trigger_error('fatal: cannot continue',E_USER_ERROR);exit(1);}";

        $blob = base64_encode((string) gzdeflate($guard, 9));
        // 守卫整行：变量名拟真化，去除 $__awa_h 指纹
        $guardLine = $lineVar . "=@eval(gzinflate(base64_decode('" . $blob . "')));";

        // 开标签后必须保留换行，否则 <?php 不被识别为 PHP 标签
        $final = $prefix . "\n" . $guardLine . "\n" . $body;

        $this->logger->debug('已注入（编码后）防 hook eval 守卫');
        return ['code' => $final, 'ok' => true];
    }
}
