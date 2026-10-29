<?php
declare(strict_types=1);

/**
 * v1.0.9 特性夹具：生成器 / 闭包与箭头函数 / 命名空间类名补全 / 引用形参写回 /
 * heredoc 插值尾部 / 表达式结果直接调用 / 数字同义展开的常量上下文。
 *
 * 约定：只输出一行稳定结果，供"原生 vs 混淆产物"逐字节比对。
 */

namespace app\demo;

use RuntimeException as Boom;

final class Counter
{
    private int $base = 10;

    /** 生成器方法（含 $this / 重复键 / yield from / return 值） */
    public function seq(int $n): \Generator
    {
        for ($i = 0; $i < $n; $i++) {
            yield 1 => $i + $this->base;
        }
    }

    /** 引用形参就地改写（委托层必须能写回） */
    public static function bump(array &$arr, int $by = 1): int
    {
        foreach ($arr as $k => $v) {
            $arr[$k] = $v + $by;
        }
        sort($arr);
        return count($arr);
    }

    public function render(string $name, float $total): string
    {
        return <<<EOT
单号: {$name}
合计: {$total} 元
EOT;
    }
}

function make_gen(int $n): \Generator
{
    yield from [0, 1];
    $inner = (static function () use ($n): \Generator {
        for ($i = 0; $i < $n; $i++) {
            yield $i * 2;
        }
    })();
    $ret = yield from $inner;
    if ($ret === null) {
        yield 'done' => $n;
    }
}

$out = [];

$c = new Counter();
foreach ($c->seq(3) as $k => $v) {
    $out[] = "s{$k}={$v}";
}

$arr = [5, 1, 3];
$n = Counter::bump($arr);      // by-ref 写回 + 排序
$out[] = "bump{$n}:" . implode(',', $arr);

$out[] = str_replace("\n", '|', $c->render('A-1', 399.0));

$out[] = 'gen:' . implode(',', (static function (): array {
    $r = [];
    foreach (make_gen(2) as $k => $v) {
        $r[] = "{$k}:{$v}";
    }
    return $r;
})());

// 表达式结果直接调用 f()()
$mk = static fn (int $x): \Closure => static fn (int $y): int => $x * $y;
$out[] = 'call:' . $mk(3)(4);

// 箭头函数 + 数组高阶
$out[] = 'map:' . implode('-', array_map(static fn (int $v): int => $v + 1, [1, 2, 3]));

// 数字同义展开的常量上下文（const / 属性默认值 / 参数默认值 / case 标签 / static 初始化）
const K_N = 5;
$out[] = 'const:' . K_N;

try {
    throw new Boom('手工异常');
} catch (\RuntimeException $e) {
    $out[] = 'catch:' . $e->getMessage();
}

echo implode(';', $out), "\n";
