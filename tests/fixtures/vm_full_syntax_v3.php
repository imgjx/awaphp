<?php
/**
 * 变式 3：group use / 显式别名 / bracketed 多命名空间 / trait 冲突解决
 * / enum backing+接口+静态方法 / 引用参数 / 生成器 send / 嵌套异常
 * / 闭包 use 引用+嵌套箭头 / 命名参数+解包 / heredoc 复杂插值 / list 跳过嵌套
 * / 匿名类 extends+implements+parent:: / match(true) 嵌套 / 一等可调用 / static 返回。
 *
 * 原生 vs ultra 产物逐字节比对。输出不含任何会被混淆改名的符号名
 * （类名/函数名/变量名），保证两种运行逐字节一致。
 */
declare(strict_types=1);

namespace V3\Contracts {
    interface Tagged { public function tag(): string; }
    interface Renderable { public function render(): string; }
}

namespace V3\Traits {
    trait Prefix { public function wrap(string $s): string { return '[' . $s . ']'; } }
    trait Suffix { public function wrap(string $s): string { return '{' . $s . '}'; } }
}

namespace V3\Enums {
    use V3\Contracts\Tagged;

    enum Level: int implements Tagged
    {
        case Low  = 1;
        case Mid  = 2;
        case High = 3;
        public function tag(): string {
            return match ($this) {
                Level::Low  => 'L',
                Level::Mid  => 'M',
                Level::High => 'H',
            };
        }
        public static function of(int $n): self {
            return match (true) {
                $n <= 1  => self::Low,
                $n === 2 => self::Mid,
                default  => self::High,
            };
        }
    }
}

namespace V3\Helpers {
    const LIMIT = 4;
    function inc(int $v): int { return $v + 1; }
}

namespace V3\Models {
    use V3\Contracts\{Renderable, Tagged};   // group use（consumeUse 的 group 分支）
    use V3\Contracts\Renderable as R;         // 显式别名
    use V3\Traits\Prefix;
    use V3\Traits\Suffix;
    use V3\Enums\Level;

    class Box implements Renderable, Tagged
    {
        use Prefix, Suffix {
            Prefix::wrap insteadof Suffix;
            Suffix::wrap as wrapBrace;
        }
        private array $items = [];
        public function __construct(private string $label, mixed ...$seed) {
            foreach ($seed as $s) { $this->items[] = $s; }
        }
        public function add(mixed $v): void { $this->items[] = $v; }
        public function size(): int { return count($this->items); }
        public function tag(): string { return 'T'; }
        public function render(): string {
            return $this->wrap($this->label) . ':' . $this->size();
        }
        public function with(mixed $v): static { $c = clone $this; $c->add($v); return $c; }
        public function asAlias(R $other): string { return $other->render(); }
        public function levelOf(int $n): string { return Level::of($n)->tag(); }
    }
}

namespace V3\Runtime {
    use V3\Models\Box;
    use V3\Enums\Level;
    use V3\Contracts\Renderable;
    use function V3\Helpers\inc;
    use const V3\Helpers\LIMIT;
    use InvalidArgumentException;
    use RuntimeException;
    use Throwable;
    use Generator;

    // 引用参数：调用点必须按引用回写，不能被 call_user_func 降级
    function bump(int &$x): int { $x += 10; return $x; }

    function seq(): Generator {
        yield 'a' => 1;
        yield from ['b' => 2, 'c' => 3];
        return 'sum6';
    }
    function echoGen(): Generator {
        $v = yield 'init';
        yield 'got:' . $v;
    }
    function fmt(string $a, string $b, string $c = 'C', string ...$rest): string {
        return $a . '|' . $b . '|' . $c . '|' . implode(',', $rest);
    }

    $out = [];

    // group use / 显式别名 / trait insteadof·as / static 返回类型
    $b = new Box('core', 1, 2);
    $b->add(3);
    $out[] = 'box=' . $b->render() . ',' . $b->wrapBrace('x') . ',' . $b->tag();
    $out[] = 'alias=' . $b->asAlias(new Box('sub'));
    $out[] = 'static=' . $b->with(4)->render();
    $out[] = 'iface=' . ($b instanceof Renderable ? 'y' : 'n');
    $out[] = 'lvl=' . $b->levelOf(2);

    // enum backing / 接口 / 静态方法 / match
    $out[] = 'enum=' . Level::of(1)->tag() . Level::of(2)->value . Level::of(9)->name;

    // use function / use const
    $out[] = 'fn=' . inc(LIMIT - 1) . ',limit=' . LIMIT;

    // 引用参数：必须回写
    $n = 5;
    $r = bump($n);
    $out[] = 'ref=' . $n . ',' . $r;

    // 生成器：yield 键 / yield from / return 值
    $gs = seq();
    $pairs = [];
    foreach ($gs as $k => $v) { $pairs[] = $k . ':' . $v; }
    $out[] = 'gen=' . implode(',', $pairs) . ',ret=' . $gs->getReturn();

    // 生成器 send
    $e = echoGen();
    $o1 = $e->current();
    $o2 = $e->send('Z');
    $out[] = 'send=' . $o1 . ',' . $o2;

    // 嵌套 try / 多 catch / finally
    $trace = [];
    try {
        try {
            throw new InvalidArgumentException('inner');
        } catch (RuntimeException $ex) {
            $trace[] = 'runtime';
        } finally {
            $trace[] = 'fin1';
        }
    } catch (InvalidArgumentException $ex) {
        $trace[] = 'invalid:' . $ex->getMessage();
    } catch (Throwable $ex) {
        $trace[] = 'other';
    } finally {
        $trace[] = 'fin2';
    }
    $out[] = 'try=' . implode('|', $trace);

    // 闭包 use 引用 + 嵌套箭头函数
    $sum = 0;
    $outer = function (int $mul) use (&$sum): callable {
        $f = fn(int $x): int => $x * $mul;
        return function (int $y) use (&$sum, $f): int { $sum += $f($y); return $sum; };
    };
    $cb = $outer(3);
    $out[] = 'closure=' . $cb(2) . ',' . $cb(4) . ',sum=' . $sum;
    $out[] = 'arrow=' . (fn($x) => (fn($y) => $x + $y)(4))(3);

    // 命名参数 / 解包 / 可变参数
    $out[] = 'named=' . fmt(c: 'X', a: 'A', b: 'B');
    $out[] = 'unpack=' . fmt(...['a' => 'A', 'b' => 'B', 'c' => 'C']);
    $out[] = 'variadic=' . fmt('A', 'B', 'C', 'x', 'y');

    // heredoc 复杂插值 + 字符串插值数组下标
    $h = new Box('H', 9);
    $idx = ['k' => 'v'];
    $out[] = <<<TXT
heredoc: {$h->render()} / {$idx['k']} / {$b->size()}
TXT;
    $out[] = "interp={$idx['k']}";

    // list 跳过 + 嵌套解构
    $row = ['a' => 1, 'skip' => 2, 'b' => 3, 'nest' => ['p' => 4, 'q' => 5]];
    ['a' => $la, 'b' => $lb, 'nest' => ['q' => $lq]] = $row;
    [, , $third] = [10, 20, 30];
    $out[] = 'list=' . $la . $lb . $lq . ',' . $third;

    // 匿名类 extends + implements + 构造参数 + parent::
    $anon = new class('A', 7) extends Box implements Renderable {
        private int $extra;
        public function __construct(string $label, int $extra) {
            parent::__construct($label, $extra);
            $this->extra = $extra;
        }
        public function render(): string { return parent::render() . '+anon' . $this->extra; }
    };
    $out[] = 'anon=' . $anon->render();

    // match(true) 多条件 + 嵌套 match
    $classify = function (int $v): string {
        return match (true) {
            $v <= 0           => 'nonpos',
            $v > 0 && $v < 10 => 'small',
            default           => match ($v % 2) { 0 => 'even', default => 'odd' },
        };
    };
    $out[] = 'match=' . $classify(-1) . $classify(5) . $classify(12) . $classify(13);

    // 一等可调用 + 可变参数
    $implode = implode(...);
    $out[] = 'fcc=' . $implode('-', ['a', 'b', 'c']);

    echo implode("\n", $out) . "\n";
}
