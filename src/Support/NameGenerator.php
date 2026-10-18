<?php
/**
 * 随机标识符生成器（长度可配）
 *
 * 当某些场景需要"非加盐"的随机名字（例如死代码变量）时使用。
 * 命名型混淆主路径仍走 Salt::identifier 以保证可复现。
 */

declare(strict_types=1);

final class NameGenerator
{
    private Random $random;
    /** @var array<string,true> */
    private array $used = [];

    public function __construct(string $salt = '', private int $length = 8)
    {
        $this->random = new Random($salt);
    }

    /** 生成一个不与已生成名字冲突的标识符（形如 _Xy12） */
    public function generate(): string
    {
        do {
            $name = '_' . $this->random->string($this->length);
        } while (isset($this->used[$name]));
        $this->used[$name] = true;
        return $name;
    }

    public function markUsed(string $name): void
    {
        $this->used[$name] = true;
    }
}
