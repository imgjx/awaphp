<?php
/**
 * 插入无用代码（DeadCodeInjector）
 *
 * 当 --insert-dead 开启时，在文件顶部与每个函数/方法体的开头插入不影响语义的
 * 废代码（无用变量赋值）。这些语句使用唯一名称，绝不被后续逻辑读取，安全无害。
 *
 * dead_density（1-10）：每个插入点的废代码条数。命名经 NameStyle 拟真化，
 * 不带 _dc 之类指纹。
 */

declare(strict_types=1);

final class DeadCodeInjector
{
    public function __construct(
        private Salt $salt,
        private Logger $logger
    ) {
    }

    /**
     * @param array<int,mixed> $tokens
     * @return array<int,mixed>
     */
    public function process(array $tokens, Config $config): array
    {
        if (!$config->get('insert_dead')) {
            return $tokens;
        }
        $density = max(1, min(10, (int) $config->get('dead_density')));
        $rand = new Random($this->salt->getSalt() . ':dead');
        $style = new NameStyle($this->salt);
        $out = [];
        $awaitBody = false;
        $topInserted = false;
        // 顶部插入点必须越过前导 declare/namespace（否则 declare 不再是首语句直接致命）
        $topInsAt = DeclareHoist::headInsertIndex($tokens);

        for ($i = 0; $i < count($tokens); $i++) {
            $tok = $tokens[$i];

            // 文件顶部（declare/namespace 之后）插入废代码（无开标签的片段流不插）
            if (!$topInserted && $topInsAt >= 0 && $i === $topInsAt) {
                for ($d = 0; $d < $density; $d++) {
                    $out[] = $this->makeDead($rand, $style);
                }
                $topInserted = true;
            }

            // 标记：下一个 '{' 是函数/方法体开头
            if (is_array($tok) && $tok[0] === T_FUNCTION) {
                $awaitBody = true;
                $out[] = $tok;
                continue;
            }
            if ($awaitBody && is_string($tok) && $tok === ';') {
                // 无函数体的签名（接口方法 / 抽象方法）：function f();
                // 若不清除标记，下一个 '{'（interface/trait/class 体）会被
                // 误当函数体插入裸语句——类体只允许成员声明，直接语法错误。
                $awaitBody = false;
                $out[] = $tok;
                continue;
            }
            if ($awaitBody && is_string($tok) && $tok === '{') {
                $awaitBody = false;
                $out[] = $tok;
                for ($d = 0; $d < $density; $d++) {
                    $out[] = $this->makeDead($rand, $style);
                }
                continue;
            }

            $out[] = $tokens[$i];
        }

        $this->logger->debug('已插入无用代码（密度 ' . $density . '）');
        return $out;
    }

    /** 拟真命名 + 随机常量赋值：无 _dc 指纹、绝不与真实变量碰撞 */
    private function makeDead(Random $rand, NameStyle $style): string
    {
        // varName() 自带 $ 前缀，直接拼语句即可（切勿再套 \${...}，否则会变成变量变量）
        $name = $style->varName('dead-' . $rand->string(6));
        $val = $rand->int(1000);
        return $name . '=' . $val . ';';
    }
}
