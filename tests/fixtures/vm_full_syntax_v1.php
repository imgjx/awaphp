<?php
/**
 * 变式 1：面向对象深度覆盖（vm_full_syntax_v1）—— 原生 vs ultra 产物逐字节比对。
 *
 * 侧重：接口多实现 / trait 冲突解决(insteadof·as·别名) / 有值枚举带方法 + match
 * / 抽象类 + 继承 + parent::/self::/static:: / 提升属性 / readonly / 匿名类继承带构造参数
 * / 生成器 yield from / 一等可调用 / 命名参数 / list 嵌套解构 / 引用形参 / heredoc+nowdoc
 * / try-catch-finally 多 catch / new 完全限定名 / nullsafe / 可变参数。
 */

declare(strict_types=1);

namespace Shop\Domain;

// ---------- 接口 ----------
interface Priceable { public function price(): float; }
interface Taggable  { public function tags(): array; }

// ---------- trait（含冲突解决所需的两份同名方法） ----------
trait Taxable
{
    protected array $log = [];
    public function withTax(float $rate): float { return $this->price() * (1 + $rate); }
    protected function note(string $m): void { $this->log[] = $m; }
    public function history(): string { return implode('|', $this->log); }
}
trait Discountable
{
    public function withTax(float $rate): float { return $this->price() * (1 + $rate) * 0.5; }
    public function halfTax(float $rate): float { return $this->price() * $rate * 0.5; }
}

// ---------- 有值枚举（方法 + match + 接口） ----------
enum Tier: int implements Taggable
{
    case BASIC = 1;
    case PRO   = 2;
    case ELITE = 3;

    public function multiplier(): float
    {
        return match ($this) {
            self::BASIC => 1.0,
            self::PRO   => 0.9,
            self::ELITE => 0.8,
        };
    }
    public function tags(): array { return ['tier', strtolower($this->name)]; }
    public static function fromName(string $n): self
    {
        return match (strtoupper($n)) {
            'PRO' => self::PRO,
            'ELITE' => self::ELITE,
            default => self::BASIC,
        };
    }
}

// ---------- 抽象类 ----------
abstract class Item implements Priceable
{
    use Taxable, Discountable {
        Taxable::withTax insteadof Discountable;
        Discountable::withTax as withHalfTax;
    }

    public const KIND = 'item';
    protected float $base;
    public readonly string $sku;

    public function __construct(float $base, string $sku)
    {
        $this->base = $base;
        $this->sku  = $sku;
        $this->note('created:' . $sku);
    }
    public function price(): float { return $this->base; }
    public function finalPrice(Tier $tier, float $tax = 0.0): float
    {
        return $this->withTax($tax) * $tier->multiplier();
    }
    abstract public function label(): string;
}

// ---------- 具体类：继承 + parent::/static:: + 静态工厂 ----------
class Book extends Item
{
    private string $title;
    public function __construct(float $base, string $sku, string $title)
    {
        parent::__construct($base, $sku);
        $this->title = $title;
    }
    public function label(): string { return 'Book<' . $this->title . '>'; }
    public function price(): float { return parent::price() * 0.95; }
    public static function make(string $t): static { return new static(10.0, 'B-1', $t); }

    /** 生成器方法：yield from + 键 + 返回值 */
    public function iter(): \Generator
    {
        yield from [1, 2, 3];
        yield 'sku' => $this->sku;
        return $this->title;
    }
}

// ---------- 顶层函数：可变参数 + 命名参数 + 引用形参 ----------
function total(array $nums, float $scale = 1.0, int ...$extra): float
{
    $s = 0.0;
    foreach ($nums as $n) { $s += $n; }
    foreach ($extra as $e) { $s += $e; }
    return $s * $scale;
}
function bump(array &$arr, int $by): int
{
    foreach ($arr as $k => $v) { $arr[$k] = $v + $by; }
    return count($arr);
}

// ===================== 主流程 =====================
$out = [];

$book = new Book(100.0, 'SKU-9', 'PHP 深度解析');
$out[] = $book->label();
$out[] = 'price=' . $book->price();
$out[] = 'finalPro=' . $book->finalPrice(tier: Tier::PRO, tax: 0.1);
$out[] = 'halfTax=' . $book->withHalfTax(0.2);
$out[] = 'history=' . $book->history();
$out[] = 'enum=' . Tier::fromName('elite')->name . ':' . Tier::ELITE->multiplier();
$out[] = 'enumTags=' . implode(',', Tier::PRO->tags());

// 匿名类继承 Item，带构造参数
$anon = new class(50.0, 'ANON', '匿名书') extends Book {
    public function label(): string { return 'Anon<' . $this->titlePrivate() . '>'; }
    private function titlePrivate(): string { return 'X'; }
};
$out[] = $anon->label() . ':' . $anon->price();

// 生成器
$gen = [];
foreach ($book->iter() as $k => $v) { $gen[] = $k . ':' . $v; }
$out[] = 'gen=' . implode(',', $gen);

// 命名参数 + 可变参数
$out[] = 'total=' . total(nums: [1, 2, 3], scale: 2.0);
$out[] = 'variadic=' . total([1, 2, 3], 1.0, 10, 20);

// 引用形参写回
$nums = [5, 1, 3];
$cnt = bump($nums, 2);
$out[] = 'bump=' . $cnt . ':' . implode(',', $nums);

// list 嵌套解构
[$a, [$b, $c], , $d] = [1, [2, 3], 'skip', 4];
$out[] = 'list=' . $a . $b . $c . $d;

// 一等可调用 + 箭头函数嵌套
$fc = strlen(...);
$arrow = fn(int $x): int => (fn(int $y): int => $y * 2)($x) + 1;
$out[] = 'fcc=' . $fc('abcd') . ':arrow=' . $arrow(5);

// heredoc（插值）与 nowdoc
$name = 'VIP';
$out[] = <<<EOT
hello {$name}
tier={$book->sku}
EOT;
$out[] = <<<'EOT'
nowdoc $name literal \n
EOT;

// try-catch-finally（多 catch）
try {
    throw new \RangeException('oops', 7);
} catch (\LogicException $e) {
    $out[] = 'logic';
} catch (\RangeException $e) {
    $out[] = 'range:' . $e->getCode();
} finally {
    $out[] = 'finally';
}

// new 完全限定名 + nullsafe
$dt = new \DateTimeImmutable('2020-01-02');
$out[] = 'date=' . $dt->format('Y-m-d');
$maybe = null;
$out[] = 'nullsafe=' . ($maybe?->format('Y') ?? 'none');

echo implode("\n", $out) . "\n";
