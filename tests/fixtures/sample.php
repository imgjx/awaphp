<?php
// 示例：演示变量、函数、类、字符串、数字与流程
$greeting = "hello world";
$count = 3;

function add($a, $b)
{
    return $a + $b;
}

class Calculator
{
    public function multiply($x, $y)
    {
        return $x * $y;
    }
}

function runDemo()
{
    global $count, $greeting;
    $sum = add(1, 2);
    $calc = new Calculator();
    $prod = $calc->multiply($count, 4);
    echo $greeting . " sum=" . $sum . " prod=" . $prod . "\n";
    for ($i = 0; $i < $count; $i++) {
        echo "i=" . $i . "\n";
    }
    return $sum + $prod;
}

$result = runDemo();
echo "result=" . $result . "\n";
