<?php
/**
 * 超复杂语法覆盖夹具（vm_full_syntax）—— 供"原生 vs 混淆产物"逐字节比对。
 *
 * 覆盖用户要求的全部编译语法：
 *   命名空间 / 接口 / trait / enum（有值+单位）/ 类（属性·构造·只读·常量·继承·self::）
 *   / 闭包&引用 / 箭头函数 / match / 生成器(yield,yield from) / 命名参数
 *   / heredoc / list 解构 / try-catch(-finally, 多 catch) / 匿名类
 * 外加：goto / instanceof / 可变参数 / 一等可调用 / new 完全限定名(\命名空间)
 *
 * 约定：把所有结果压进 $lines，最后 echo 一行接一行的稳定输出，便于逐字节比对。
 */

namespace App\Demo;

// ---------- 接口 ----------
interface Shape
{
    public function area(): float;
}

// ---------- trait ----------
trait Loggable
{
    protected array $logs = [];
    protected function addLog(string $msg): void
    {
        $this->logs[] = $msg;
    }
    public function dumpLogs(): string
    {
        return implode(';', $this->logs);
    }
}

// ---------- 有值枚举（带方法） ----------
enum Status: string
{
    case ACTIVE = 'active';
    case DONE   = 'done';
    public function label(): string
    {
        return strtoupper($this->name);
    }
}

// ---------- 单位枚举 ----------
enum Color: string
{
    case RED   = 'r';
    case GREEN = 'g';
}

// ---------- 类：实现接口 + 使用 trait ----------
class Circle implements Shape
{
    use Loggable;

    public const PI = 3.14;
    private float $r;
    public readonly string $id;

    public function __construct(float $r, string $id)
    {
        $this->r  = $r;
        $this->id = $id;
        $this->addLog('construct ' . $id);
    }

    public function area(): float
    {
        return self::PI * $this->r * $this->r;
    }

    public function describe(): string
    {
        return 'circle ' . $this->id . ' r=' . $this->r;
    }

    /** 生成器方法（yield / yield from / $this） */
    public function walk(): \Generator
    {
        yield from [1, 2, 3];
        yield 'tail' => $this->id;
    }
}

// ---------- 顶层函数：按引用形参 + 可变参数 + 命名参数 ----------
function totals(array $items, float $tax = 0.0, string ...$tags): float
{
    $sum = 0.0;
    foreach ($items as $it) {
        $sum += $it;
    }
    return $sum * (1 + $tax);
}

// ---------- 生成器（yield / yield from） ----------
function genRange(int $n): \Generator
{
    for ($i = 0; $i < $n; $i++) {
        yield $i => $i * $i;
    }
}
function genChain(int $n): \Generator
{
    yield from genRange($n);
    yield 'end' => -1;
}

// ---------- 箭头函数 ----------
$square = fn(float $x): float => $x * $x;

// ---------- 闭包 & 引用 ----------
$counter = 0;
$inc = function () use (&$counter): void {
    $counter++;
};

// ---------- 匿名类 ----------
$logger = new class {
    public function log(string $m): string
    {
        return '[log] ' . $m;
    }
};

// ===================== 主流程 =====================
$lines = [];

// 普通 new（同命名空间）
$c = new Circle(2.0, 'c1');
$lines[] = $c->describe();
$lines[] = 'area=' . $c->area();
$lines[] = 'logs=' . $c->dumpLogs();

// 完全限定名 new（触发 new \命名空间 路径，是 NeW bug 的高危点）
$c3 = new \App\Demo\Circle(4.0, 'c3');
$lines[] = 'fqn=' . $c3->describe();

// 枚举
$lines[] = 'enum=' . Status::ACTIVE->label();
$lines[] = 'color=' . Color::RED->name;

// 命名参数（全部命名，无位置参数在其后）
$lines[] = 'tax=' . totals(items: [10, 20], tax: 0.1);
// 可变参数（位置形式）
$lines[] = 'variadic=' . totals([1, 2, 3], 0.0, 'a', 'b');
$lines[] = 'sq=' . $square(3);

// 闭包引用写回
$inc();
$inc();
$lines[] = 'counter=' . $counter;

// 生成器
$genOut = [];
foreach (genChain(3) as $k => $v) {
    $genOut[] = $k . ':' . $v;
}
$lines[] = 'gen=' . implode(',', $genOut);

// 类生成器方法
$g2 = [];
foreach ($c->walk() as $k => $v) {
    $g2[] = $k . ':' . (is_string($v) ? $v : $v);
}
$lines[] = 'walk=' . implode(',', $g2);

// 匿名类
$lines[] = $logger->log('hello');

// match
$status = Status::DONE;
$label = match ($status) {
    Status::ACTIVE => 'on',
    Status::DONE   => 'off',
    default        => '?',
};
$lines[] = 'match=' . $label;

// list 解构
[$a, $b, $c] = [1, 2, 3];
$lines[] = 'list=' . $a . ',' . $b . ',' . $c;

// heredoc（含插值）
$lines[] = <<<EOT
heredoc line
with {$b} embedded
EOT;

// try-catch-finally（多 catch + instanceof）
try {
    $obj = new Circle(1.0, 't1');
    if ($obj instanceof Shape) {
        $lines[] = 'instanceof=ok';
    }
    throw new \RuntimeException('boom');
} catch (\InvalidArgumentException $e) {
    $lines[] = 'caught IA';
} catch (\RuntimeException $e) {
    $lines[] = 'caught RTE: ' . $e->getMessage();
} finally {
    $lines[] = 'finally';
}

// goto
$x = 0;
start:
$x++;
if ($x < 3) {
    goto start;
}
$lines[] = 'goto=' . $x;

// 一等可调用（first-class callable）
$fc = strlen(...);
$lines[] = 'fcc=' . $fc('abc');

echo implode("\n", $lines) . "\n";
