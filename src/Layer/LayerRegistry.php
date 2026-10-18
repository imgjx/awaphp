<?php
/**
 * 分层路由表（LayerRegistry）
 *
 * 把分层 JSON / 等级预设里的 layer.name 映射到"层类型"。Obfuscator::applyLayer()
 * 据此决定：token 级层作用于 token 流，shell:* 层包裹源码字符串，inject 为注入层。
 *
 * 这是唯一权威的"已知层名"清单 —— Config::fromLayeredJson() 也会用 names() 做校验。
 */

declare(strict_types=1);

final class LayerRegistry
{
    /** token 级层：作用于同一 token 流，按序累积 */
    private const TOKEN = [
        'var', 'func', 'class', 'controlflow', 'number',
        'string', 'comment', 'stat', 'ws', 'dead', 'junk_runtime', 'token', 'semantic',
    ];

    /** 源码头/尾包裹层（shell）：按序包裹源码字符串；数组越靠后 = 越外层 */
    private const SHELL = [
        'shell:anti_debug', 'shell:anti_hook',   // 最内层：被后续编码壳裹入密文藏匿
        'shell:vm', 'shell:eval', 'shell:gz',
        'shell:anti_tamper', 'shell:license',     // 最外层：明文锚点须可见
    ];

    /** 特殊层：仅做注入，不改变主流程逻辑 */
    private const SPECIAL = ['inject'];

    /** @return array<string,array{kind:string}> */
    public static function names(): array
    {
        $m = [];
        foreach (self::TOKEN as $n) {
            $m[$n] = ['kind' => 'token'];
        }
        foreach (self::SHELL as $n) {
            $m[$n] = ['kind' => 'shell'];
        }
        foreach (self::SPECIAL as $n) {
            $m[$n] = ['kind' => 'inject'];
        }
        return $m;
    }

    /** @return 'token'|'shell'|'inject'|null */
    public static function kind(string $name): ?string
    {
        return self::names()[$name]['kind'] ?? null;
    }

    public static function isToken(string $name): bool
    {
        return self::kind($name) === 'token';
    }

    public static function isShell(string $name): bool
    {
        return self::kind($name) === 'shell';
    }

    /** shell:vm 内部标记，便于 Obfuscator 选择驱动 */
    public static function shellKey(string $name): string
    {
        return substr($name, strlen('shell:'));
    }
}
