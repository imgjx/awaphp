<?php
/**
 * 变式 2：病态边界覆盖（vm_full_syntax_v2）—— 原生 vs ultra 产物逐字节比对。
 *
 * 侧重：关键字作大小写敏感标识符（const new / Cfg::new / enum case list / goto 标签）
 * / 多命名空间 bracketed / switch 穿透 / new self·static·parent / match(true) 无默认
 * / 字符串插值数组下标 / yield from 深链 / 命名参数乱序 / list 带键解构 / 引用返回
 * / 匿名类 implements 接口 / 闭包 use 引用 / 三元嵌套 / nowdoc 闭合边界 / 一等可调用。
 */

declare(strict_types=1);

namespace Core\Base {

    interface Describable { public function describe(): string; }

    // 关键字作类常量名（大小写敏感）—— 声明端与引用端必须一致
    class Cfg
    {
        const new = 'NEW';
        const list = 'LIST';
        const match = 'MATCH';
        public static function all(): array { return [self::new, self::list, self::match]; }
    }

    // 关键字作 enum case 名
    enum Flag: string
    {
        case new  = 'n';
        case list = 'l';
        case fn   = 'f';
        public function code(): string { return strtoupper($this->name); }
    }

    abstract class Base implements Describable
    {
        protected string $id;
        public function __construct(string $id) { $this->id = $id; }
        // 覆盖 self:: / static:: 晚期静态绑定语法；类名会被 class-obf 改名
        // （::class 返回真实声明类名，class_alias 无法伪造），故仅用于绑定判定，
        // 不把类名写进稳定输出，保证原生/混淆逐字节一致。
        public function describe(): string {
            $late = static::class;
            $early = self::class;
            return ($late === $early ? 'same' : 'derived') . '#' . $this->id;
        }
        public function selfNew(): static { return new static($this->id . '.child'); }
        public static function parentNew(string $id): static { return new static($id); }
    }
}

namespace Core\App {

    use Core\Base\Base;
    use Core\Base\Cfg;
    use Core\Base\Flag;
    use Core\Base\Describable;

    final class Node extends Base
    {
        private array $meta = [];
        public function put(string $k, $v): void { $this->meta[$k] = $v; }
        public function get(string $k) { return $this->meta[$k] ?? null; }
        public function describe(): string { return parent::describe() . '(' . count($this->meta) . ')'; }
    }

    // 引用返回 + 静态局部变量
    function &slot(string $k)
    {
        static $store = [];
        if (!array_key_exists($k, $store)) { $store[$k] = 0; }
        return $store[$k];
    }

    // yield from 深链
    function leaf(int $n): \Generator { for ($i = 0; $i < $n; $i++) { yield $i => $i * $i; } }
    function middle(int $n): \Generator { yield 'head' => -1; yield from leaf($n); }
    function deep(int $n): \Generator { yield from middle($n); yield 'tail' => 99; }

    // 命名参数乱序 + 类型声明
    function render(string $title, int $width = 10, string $pad = '-'): string
    {
        return str_pad($title, $width, $pad, STR_PAD_BOTH);
    }

    function switchDemo(int $n): string
    {
        $r = '';
        switch ($n) {
            case 1:
            case 2:
                $r .= 'low';   // 穿透
            case 3:
                $r .= '|mid';
                break;
            default:
                $r .= 'high';
        }
        return $r;
    }

    function matchDemo($v): string
    {
        return match (true) {
            is_int($v) && $v < 0 => 'neg',
            is_int($v) && $v > 0 => 'pos',
            $v === 0             => 'zero',
            default              => 'other',
        };
    }

    // ===================== 主流程 =====================
    $out = [];

    // 关键字类常量 / enum case
    $out[] = 'cfg=' . implode(',', Cfg::all()) . ':' . Cfg::new . Cfg::list . Cfg::match;
    $out[] = 'flag=' . Flag::new->code() . Flag::list->value . Flag::fn->name;

    // 类继承 / new static / parent::describe
    $node = new Node('N1');
    $node->put('a', 1);
    $node->put('b', 2);
    $out[] = 'node=' . $node->describe();
    $out[] = 'selfNew=' . $node->selfNew()->describe();
    $out[] = 'parentNew=' . Node::parentNew('N2')->describe();

    // instanceof 接口
    $out[] = 'instanceof=' . ($node instanceof Describable ? 'yes' : 'no');

    // 引用返回写回
    $ref = &slot('counter');
    $ref = 41;
    $ref++;
    $out[] = 'ref=' . slot('counter');

    // yield from 深链
    $g = [];
    foreach (deep(3) as $k => $v) { $g[] = $k . ':' . $v; }
    $out[] = 'gen=' . implode(',', $g);

    // 命名参数乱序
    $out[] = 'render=' . render(pad: '.', width: 9, title: 'X');

    // switch 穿透 + match(true)
    $out[] = 'switch1=' . switchDemo(1) . '|switch3=' . switchDemo(3) . '|switch9=' . switchDemo(9);
    $out[] = 'match=' . matchDemo(-5) . matchDemo(5) . matchDemo(0) . matchDemo('s');

    // list 带键解构 + 嵌套
    $row = ['id' => 7, 'name' => 'abc', 'nest' => ['x' => 8, 'y' => 9]];
    ['id' => $id, 'name' => $nm, 'nest' => ['x' => $x, 'y' => $y]] = $row;
    $out[] = 'list=' . $id . $nm . $x . $y;

    // 闭包 use 引用 + 箭头函数
    $acc = 0;
    $inc = function (int $by) use (&$acc): int { $acc += $by; return $acc; };
    $out[] = 'closure=' . $inc(3) . ',' . $inc(4) . ',acc=' . $acc;
    $mul = fn($a, $b) => $a * $b;
    $out[] = 'arrow=' . $mul(6, 7);

    // 字符串插值数组下标
    $arr = ['key' => 'V'];
    $out[] = "interp={$arr['key']}";

    // 三元嵌套 + null 合并
    $out[] = 'ternary=' . ($x > 100 ? 'big' : ($x > 5 ? 'mid' : 'small'));
    $out[] = 'coalesce=' . ($missing ?? 'default');

    // 匿名类 implements 接口
    $probe = new class('P') implements Describable {
        private string $p;
        public function __construct(string $p) { $this->p = $p; }
        public function describe(): string { return 'anon:' . $this->p; }
    };
    $out[] = $probe->describe();

    // 一等可调用 + 可变参数
    $fc = implode(...);
    $out[] = 'fcc=' . $fc(',', [1, 2, 3]);

    // nowdoc 闭合边界
    $out[] = <<<'TXT'
nowdoc: $not_interpolated \n literal
TXT;

    // goto 标签（大小写敏感；标签名须为 T_STRING 标识符）
    $n = 0;
    loopTop:
    $n++;
    if ($n < 3) { goto loopTop; }
    $out[] = 'goto=' . $n;

    echo implode("\n", $out) . "\n";
}
