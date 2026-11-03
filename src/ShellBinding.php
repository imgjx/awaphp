<?php
/**
 * 外壳绑定标记（ShellBinding）
 *
 * 用途：给「被完整混淆后的成品」打一个只有混淆器自己才知道的绑定标记（全局常量）。
 *   - 合法运行（运行完整混淆文件）时，该常量由混淆器在【VM 内层之外】定义，
 *     故 VM 解释器 / 反调试守卫执行时它已就位；
 *   - 一旦把 VM 内层（runtime 闭包 + 字节码）单独抠出来独立运行（脱壳独立执行），
 *     或把绑定标记 define 整行删掉，该常量即消失 → 判定为「已脱壳 / 独立执行 / 标记被剥离」，
 *     VM 把它当作污染因子混入 opcode 解码（静默错乱），反调试守卫则直接终止。
 *
 * 标记名 / 期望值均由 salt 确定性派生（随机化，无固定指纹），三方（Obfuscator 注入的
 * define、AntiDebug 守卫、VM 运行期自检）调用同一组派生即可得到完全一致的常量名与值。
 *
 * ============================ 安全边界（单向化）============================
 * 运行期校验必须拿到「期望值」，而运行期没有 salt，故期望值只能以某种形式固化进产物。
 * 若把【原始 token】直接写进内层校验表达式，攻击者抠出内层即可读到期望值，
 * 自行 `define()` 伪造即可绕过整个自检 —— 故本类采用**单向摘要**：
 *
 *   - 外层（唯一持有原始 token 的地方）：define(NAME, TOKEN)
 *   - 内层（VM / 反调试守卫）：只持有 DIGEST = sha256(TOKEN)，
 *     校验 `hash_equals(DIGEST, hash('sha256', (string)constant(NAME)))`。
 *
 * 攻击者仅拿到内层时，看到的是摘要而非 token，无法构造出摘要匹配的常量值
 * （需要 sha256 的原像），故内层被抠出独立执行 → 必然判定脱壳。
 * 原始 token 在整个产物中只出现一次（外层 define 处），无第二处泄露。
 */

declare(strict_types=1);

final class ShellBinding
{
    private const NAME_LABEL  = 'awa-sh-name';
    private const TOKEN_LABEL = 'awa-sh-token';

    /** 绑定标记常量名（盐派生，下划线起头，合法常量名）。 */
    public static function markerName(Salt $salt): string
    {
        return '_' . $salt->deriveHex(self::NAME_LABEL, 11);
    }

    /** 绑定标记期望值（盐派生，随机十六进制串）。全产物仅出现于外层 define。 */
    public static function markerToken(Salt $salt): string
    {
        return $salt->deriveHex(self::TOKEN_LABEL, 32);
    }

    /**
     * 绑定标记期望值的单向摘要（sha256 hex）。
     *
     * 内层校验只持有本摘要，不持有原始 token：攻击者抠出内层无法伪造常量值
     * （需要 sha256 原像），故「内层被独立执行」这一判定无法被绕过。
     */
    public static function markerDigest(Salt $salt): string
    {
        return hash('sha256', self::markerToken($salt));
    }

    /**
     * 生成「外壳绑定标记 define」源码（注入在【VM 内层之外】）。
     * 合法运行（完整混淆文件）时该常量先于 VM 解释器执行而存在；一旦把 VM 内层单独抠出
     * 独立运行，此 define 即消失，VM 自检会判定为「脱壳独立执行」并污染指令流。
     */
    public static function defineSource(Salt $salt): string
    {
        $n = self::markerName($salt);
        $t = self::markerToken($salt);
        return "if(!defined('{$n}')){define('{$n}','{$t}');}";
    }

    /**
     * 生成「外壳绑定标记」守卫源码：标记缺失/不匹配即终止（外壳反调试加强 —— 脱壳检测）。
     * 与 VM 运行期自检形成纵深：守卫是显式终止，VM 自检是静默污染。
     */
    public static function guardSource(Salt $salt): string
    {
        return 'if(' . self::isUnboundExpr($salt) . "){@trigger_error('fatal: shell binding missing',E_USER_ERROR);exit(1);}";
    }

    /**
     * 返回一段「是否已脱壳 / 标记被剥离」的 PHP 布尔表达式（可直接拼进守卫 / VM 自检）。
     * 返回 true 表示【应触发惩罚】（脱壳独立执行）。
     *
     * 内层只暴露摘要：即便攻击者把这段表达式整个读走，也无法反推出可用的常量值。
     */
    public static function isUnboundExpr(Salt $salt): string
    {
        return '!(' . self::boundExpr($salt) . ')';
    }

    /**
     * 「标记已就位」的判定表达式（true = 绑定完好）。
     *
     * 逐项：
     *   defined(NAME)         —— 常量存在（短路，避免 constant() 抛 Error）
     *   is_string($v=constant(NAME)) —— 值是字符串（防数组/对象等异常类型）
     *   hash_equals(DIGEST, hash('sha256', $v)) —— 摘要一致（防常量被改成别的值）
     */
    private static function boundExpr(Salt $salt): string
    {
        $n = self::markerName($salt);
        $d = self::markerDigest($salt);
        $v = '$__' . $salt->deriveHex('awa-sh-var', 6);
        return "defined('{$n}')&&is_string({$v}=constant('{$n}'))"
            . "&&hash_equals('{$d}',hash('sha256',{$v}))";
    }
}
