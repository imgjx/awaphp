<?php
// 演示用源码：计算斐波那契并输出一段 HTML
function fib(int $n): int {
    if ($n < 2) {
        return $n;
    }
    return fib($n - 1) + fib($n - 2);
}

class Greeter {
    private string $name;
    public function __construct(string $name) {
        $this->name = $name;
    }
    public function hello(): string {
        $n = 10;
        $r = fib($n);
        $msg = '斐波那契(' . $n . ') = ' . $r;
        return $msg . ' —— 你好 ' . $this->name;
    }
}

$g = new Greeter('awa');
echo '<div class="box">第10项: ' . $g->hello() . '</div>';
$cfg = 3 * 7 + 0x10;
echo '<p>配置值=' . $cfg . '</p>';
