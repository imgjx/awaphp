<?php
// 复杂业务示例：用户评分统计 + 素数检测 + 斐波那契 + 报表生成
// 仅使用自定义 VM 已支持的语法特性（函数/类/循环/数组/字符串/位运算/三元等）。
// 故意避开：interface/trait、引用参数、可变参数、静态调用、匿名/箭头函数、match、
// 以及 $this->prop[]（属性数组追加改用局部临时数组）。

function fib($n) {
    if ($n < 2) { return $n; }
    return fib($n - 1) + fib($n - 2);
}

function isPrime($n) {
    if ($n < 2) { return false; }
    $i = 2;
    while ($i * $i <= $n) {
        if ($n % $i === 0) { return false; }
        $i++;
    }
    return true;
}

function factorial($n) {
    $r = 1;
    for ($i = 2; $i <= $n; $i++) { $r *= $i; }
    return $r;
}

function wordStats($s) {
    $len = strlen($s);
    $upper = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = substr($s, $i, 1);
        if ($ch >= 'A' && $ch <= 'Z') { $upper++; }
    }
    return $upper;
}

class User {
    public $id = 0;
    public $name = '';
    public $scores = [];

    public function addScore($v) {
        $s = $this->scores;
        $s[] = $v;
        $this->scores = $s;
    }

    public function average() {
        $sum = 0;
        $n = count($this->scores);
        $i = 0;
        while ($i < $n) {
            $sum += $this->scores[$i];
            $i++;
        }
        if ($n === 0) { return 0; }
        return $sum / $n;
    }

    public function summary() {
        return $this->name . ' (#' . $this->id . ') avg=' . $this->average();
    }
}

class Report {
    public $title = '';
    public $lines = [];

    public function add($s) {
        $tmp = $this->lines;
        $tmp[] = $s;
        $this->lines = $tmp;
    }

    public function render() {
        $out = '=== ' . $this->title . ' ===' . "\n";
        $i = 0;
        foreach ($this->lines as $line) {
            $out .= ($i + 1) . '. ' . $line . "\n";
            $i++;
        }
        return $out;
    }
}

// ---- 主流程 ----
$users = [];
$u = new User();
$u->id = 1;
$u->name = 'Alice';
$u->addScore(90);
$u->addScore(85);
$u->addScore(77);
$users[] = $u;

$u2 = new User();
$u2->id = 2;
$u2->name = 'Bob';
$u2->addScore(60);
$u2->addScore(72);
$users[] = $u2;

$rpt = new Report();
$rpt->title = 'User Scores';
foreach ($users as $user) {
    $rpt->add($user->summary());
}
echo $rpt->render();

echo 'fib(10)=' . fib(10) . "\n";
echo 'isPrime(17)=' . (isPrime(17) ? 'yes' : 'no') . "\n";
echo 'isPrime(20)=' . (isPrime(20) ? 'yes' : 'no') . "\n";
echo 'factorial(5)=' . factorial(5) . "\n";

$primes = [];
for ($i = 2; $i <= 30; $i++) {
    if (isPrime($i)) { $primes[] = $i; }
}
echo 'primes<=30: ' . implode(',', $primes) . "\n";

$mask = 0;
$mask = $mask | 1;
$mask = $mask | 4;
echo 'bitmask=' . $mask . ' has4=' . (($mask & 4) ? 'yes' : 'no') . "\n";

echo 'upperInHELLO=' . wordStats('HeLLo WoRLd') . "\n";

$total = 0;
for ($i = 1; $i <= 100; $i++) {
    $total += ($i % 3 === 0 || $i % 5 === 0) ? $i : 0;
}
echo 'sumMultiples35(1..100)=' . $total . "\n";
