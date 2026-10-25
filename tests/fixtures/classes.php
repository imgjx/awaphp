<?php
// 类 / 接口 / trait 混淆测试样例
interface Shape
{
    public function area(): float;
}

trait LoggerTrait
{
    public function log($m)
    {
        echo "[log] " . $m . "\n";
    }
}

class Circle implements Shape
{
    use LoggerTrait;

    private float $r;

    public function __construct($r)
    {
        $this->r = $r;
    }

    public function area(): float
    {
        return 3.14 * $this->r * $this->r;
    }
}

$c = new Circle(2);
$c->log("area=" . $c->area());
echo "area=" . $c->area() . "\n";
