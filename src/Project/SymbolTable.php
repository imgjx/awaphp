<?php
/**
 * 全局符号表（SymbolTable）
 *
 * 解决"整套系统一起混淆"时的两个致命兼容问题：
 *
 * 问题一：碰撞回退不一致
 *   各模块原本用「本文件已有标识符集合」做碰撞回退。A 文件撞了会退化成 name#1，
 *   B 文件没撞就用 name —— 同一个函数在两文件里被重命名成两个名字，跨文件调用直接断。
 *   解法：符号表用一份「全局」已用集合统一分配，碰撞回退在整套系统内一致。
 *
 * 问题二：声明与调用不对称
 *   映射原本只从「本文件声明」收集。B 文件调用 A 文件声明的函数时，B 的调用点不在映射内，
 *   不会被重命名 -> B 仍调用原名，而 A 的声明已改名 -> 运行即崩。
 *   解法：先全量扫描所有文件收集声明，再生成一张覆盖全部符号的映射；
 *        每个文件重命名时对该映射内「所有」符号生效（声明点 + 调用点）。
 *
 * 由于 Salt::identifier() 只由 (原名, salt) 决定，同一 salt 下不同文件天然得到同一结果；
 * 符号表保证在此基础上连"碰撞回退"也全局一致，并可序列化为 manifest 供增量重建复用。
 */

declare(strict_types=1);

final class SymbolTable
{
    /** @var array<string,true> 全项目声明过的函数名 */
    private array $funcs = [];

    /** @var array<string,true> 全项目声明过的类名/接口/特质 */
    private array $classes = [];

    /** @var array<string,string> 函数名：原名 => 混淆名 */
    private array $funcMap = [];

    /** @var array<string,string> 类名：原名 => 混淆名 */
    private array $classMap = [];

    /**
     * 是否已完成全局构建。
     *
     * 注意：不能用 `funcMap !== [] || classMap !== []` 代替 —— 当全部符号都因
     * 动态引用被排除时映射为空表，但"空表"本身就是构建结论：此时必须禁止
     * 各文件回退到"按本文件声明重命名"，否则同一个被排除的符号又会被改掉。
     */
    private bool $built = false;

    public function addFunction(string $name): void
    {
        $this->funcs[$name] = true;
    }

    public function addClass(string $name): void
    {
        $this->classes[$name] = true;
    }

    /**
     * 动态符号排除：名字以字符串字面量形式出现在代码里的符号（call_user_func('name')、
     * 可变函数、compact/extract 等）一旦改名就会断链，必须整体排除出重命名。
     * @param array<string,true> $names
     */
    public function removeDynamic(array $names): void
    {
        foreach (array_keys($names) as $n) {
            $n = (string) $n;
            if ($n === '') {
                continue;
            }
            unset($this->funcs[$n], $this->classes[$n]);
            // 已有映射（manifest 复用）同样剔除，保持"排除"语义在增量重建时依然生效
            unset($this->funcMap[$n], $this->classMap[$n]);
        }
    }

    /** @return array<string,true> */
    public function declaredFunctions(): array
    {
        return $this->funcs;
    }

    /** @return array<string,true> */
    public function declaredClasses(): array
    {
        return $this->classes;
    }

    /**
     * 生成全局映射。使用「全局已用集合」做碰撞回退，保证跨文件一致。
     * 名称按字典序处理，保证同一输入得到同一结果（可复现）。
     */
    public function build(Salt $salt, int $funcLen, int $classLen): void
    {
        $this->built = true; // 即便映射为空表也算"已构建"（见 $built 注释）
        $used = [];

        $names = array_keys($this->funcs);
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            // 已存在映射（来自 manifest 复用）则直接沿用，保持增量重建一致
            if (isset($this->funcMap[$n])) {
                $used[$this->funcMap[$n]] = true;
                continue;
            }
            $candidate = $salt->identifier($n, $funcLen);
            $i = 0;
            while (isset($used[$candidate])) {
                $candidate = $salt->identifier($n . '#' . (++$i), $funcLen);
            }
            $used[$candidate] = true;
            $this->funcMap[$n] = $candidate;
        }

        $names = array_keys($this->classes);
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            if (isset($this->classMap[$n])) {
                $used[$this->classMap[$n]] = true;
                continue;
            }
            $candidate = $salt->identifier($n, $classLen);
            $i = 0;
            while (isset($used[$candidate])) {
                $candidate = $salt->identifier($n . '#' . (++$i), $classLen);
            }
            $used[$candidate] = true;
            $this->classMap[$n] = $candidate;
        }
    }

    /**
     * 采纳已有映射（从 manifest 读入），使增量重建沿用旧名字。
     * @param array<string,string> $funcMap
     * @param array<string,string> $classMap
     */
    public function adopt(array $funcMap, array $classMap): void
    {
        foreach ($funcMap as $k => $v) {
            $this->funcMap[(string) $k] = (string) $v;
            $this->funcs[(string) $k] = true;
        }
        foreach ($classMap as $k => $v) {
            $this->classMap[(string) $k] = (string) $v;
            $this->classes[(string) $k] = true;
        }
        // manifest 沿用下来的映射即为构建结论（增量重建时不重新分配名字）
        $this->built = true;
    }

    public function isBuilt(): bool
    {
        return $this->built;
    }

    /** @return array<string,string> */
    public function funcMap(): array
    {
        return $this->funcMap;
    }

    /** @return array<string,string> */
    public function classMap(): array
    {
        return $this->classMap;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'func'   => $this->funcMap,
            'class'  => $this->classMap,
            'declared' => [
                'func'  => array_keys($this->funcs),
                'class' => array_keys($this->classes),
            ],
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $t = new self();
        $funcs  = is_array($a['func'] ?? null) ? $a['func'] : [];
        $classes = is_array($a['class'] ?? null) ? $a['class'] : [];
        $t->adopt($funcs, $classes);
        foreach ((array) ($a['declared']['func'] ?? []) as $n) {
            $t->addFunction((string) $n);
        }
        foreach ((array) ($a['declared']['class'] ?? []) as $n) {
            $t->addClass((string) $n);
        }
        return $t;
    }
}
