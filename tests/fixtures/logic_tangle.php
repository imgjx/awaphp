<?php
/**
 * v1.2.2 超激进逻辑变换专用样例
 *
 * 覆盖「输出 10086 → 先造两个临时变量 → 串计算 → 后续语句复用」以及所有
 * 曾暴露语义偏差的边界：declare(strict_types) 下的恒等积木、const / enum case /
 * 属性默认值等常量表达式上下文、引用参数与引用赋值、match(true) 的 => 标签、
 * switch case 标签、heredoc / nowdoc、list 嵌套解构、goto 标签、一等可调用。
 *
 * 原生 vs ultra / --logic 产物逐字节比对；输出不含任何会被改名的符号名。
 */
declare(strict_types=1);

const LIMIT = 7;

enum Tier: int
{
    case Low  = 1;
    case High = 9;
    public function label(): string
    {
        return match ($this) {
            Tier::Low  => 'L',
            Tier::High => 'H',
        };
    }
}

interface Named { public function name(): string; }
trait Tag { public function tag(): string { return 'T'; } }

// 引用参数：调用点必须按引用回写
function bump(int &$v): int { $v += 3; return $v; }
function sumTo(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $s += $i; }
    return $s;
}
function gen(): \Generator { yield 1; yield from [2, 3]; }

class Item implements Named
{
    use Tag;
    public function __construct(private int $qty = 2) {}
    public function name(): string { return 'item'; }
    public function qty(): int { return $this->qty; }
}

$out = [];

// 用户点名形态：一个裸整数输出，逻辑层会先造两个临时变量、串计算、再复用
$base = 10086;
$out[] = 'n=' . $base;

$x = 5;
$y = 12;
$out[] = 'cmp=' . ($x < $y ? 'lt' : 'ge') . ($x > 0 && $y > 0 ? 'both' : 'no');
$out[] = 'bool=' . ((!$x) ? 'zero' : 'nonzero');
$out[] = 'sum=' . sumTo(LIMIT);
$out[] = 'enum=' . Tier::Low->label() . Tier::High->value;

$it = new Item(4);
$out[] = 'obj=' . $it->name() . $it->tag() . $it->qty();

// 引用不能被缠结打断
$r = 10;
$rr = bump($r);
$out[] = 'ref=' . $r . ',' . $rr;

$g = [];
foreach (gen() as $v) { $g[] = $v; }
$out[] = 'gen=' . implode('', $g);

$acc = 0;
$f = function (int $m) use (&$acc): int { $acc += $m; return $acc; };
$out[] = 'clo=' . $f(2) . ',' . $f(3) . ',acc=' . $acc;
$out[] = 'arrow=' . (fn($a) => (fn($b) => $a * $b)(4))(3);

$row = ['a' => 1, 'b' => 2, 'nest' => ['c' => 3]];
['a' => $la, 'b' => $lb, 'nest' => ['c' => $lc]] = $row;
$out[] = 'list=' . $la . $lb . $lc;

$out[] = <<<TXT
heredoc:{$base}
TXT;
$out[] = 'nowdoc:' . <<<'TXT'
literal-\n-$not_interpolated
TXT;
$out[] = 'match=' . match (true) { $base > 10000 => 'big', default => 'small' };
$out[] = 'switch=' . (function (int $v): string {
    switch ($v) {
        case 1: return 'one';
        case 2: return 'two';
        default: return 'other';
    }
})(2);

$implode = implode(...);
$out[] = 'fcc=' . $implode('-', ['a', 'b']);

// 显式 goto：状态机层必须整体跳过
$n = 0;
loopTop:
$n++;
if ($n < 3) { goto loopTop; }
$out[] = 'goto=' . $n;

echo implode("\n", $out) . "\n";
