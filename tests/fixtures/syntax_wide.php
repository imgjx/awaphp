<?php
// PHP 8.x 语法广度 fixture（输出确定性）
enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';
    public function label(): string { return match($this) { Suit::Hearts => '红心', Suit::Spades => '黑桃' }; }
}
class Point {
    public function __construct(public readonly int $x = 0, protected int $y = 0) {}
    public function sum(): int { return $this->x + $this->y; }
}
class Counter {
    private array $log = [];
    public function add(int ...$nums): static {
        foreach ($nums as $n) { $this->log[] = $n; }
        return $this;
    }
    public function total(): int { return array_sum($this->log); }
    public function __toString(): string { return 'log=' . implode(',', $this->log); }
}
function firstClass(callable $f): mixed { return $f; }
function never(int $v): never { if ($v < 0) { exit(1); } throw new RuntimeException('never'); }
function gen(): Generator { yield 1; yield 2; yield 3; }
function union(int|string $v): int|string { return $v; }
function nullable(?int $v): ?int { return $v; }
function variadic(string $head, ...$rest): string { return $head . ':' . implode('|', $rest); }
function refs(int &$x): void { $x *= 2; }
function namedArgs(int $a, int $b = 2, int $c = 3): int { return $a + $b * 10 + $c * 100; }
$arr = [5, 3, 8, 1];
usort($arr, fn($a, $b) => $a <=> $b);
$mapped = array_map(strtoupper(...), ['a', 'b']);
$neg = -3;
try { never(1); } catch (RuntimeException $e) { echo 'caught:', $e->getMessage(), "\n"; }
echo Suit::Hearts->label(), ',', Suit::Spades->value, "\n";
$p = new Point(x: 4, y: 6);
echo $p->sum(), "\n";
$c = (new Counter())->add(1, 2)->add(3);
echo $c->total(), ';', $c, "\n";
$fc = strlen(...);
echo $fc('hello'), ',', firstClass($fc)('ab'), "\n";
echo implode(',', iterator_to_array(gen())), "\n";
echo union('str'), ',', union(9), ',', nullable(null) ?? 'null', ',', nullable(7), "\n";
echo variadic('h', 'r1', 'r2'), "\n";
$z = 5; refs($z);
echo $z, "\n";
echo namedArgs(a: 1, c: 9), "\n";
echo implode('-', [...$arr, ...[9, 0]]), "\n";
echo implode(',', $mapped), "\n";
$s = "interp: {$arr[0]} and {$p->x}";
echo $s, "\n";
echo PHP_VERSION >= '8.0' ? 'ge8' : 'lt8', "\n";
