<?php
// v1.0.8 兼容性回归夹具：覆盖本版新增/强化的 VM 真编译语法。
// 每行 echo 输出一段固定值，便于与原生运行结果逐行比对。

// —— heredoc 插值（<<<EOT ... $var ... EOT）——
$name = 'awa';
$greet = <<<EOT
hello $name
EOT;
echo trim($greet) . "\n";

// —— 数组展开（[...$a, 3]）——
$a = [1, 2];
$b = [...$a, 3];
echo count($b) . "\n";

// —— empty(任意表达式)（empty(0) / empty(f())）——
function isZero($x) { return $x === 0; }
echo (empty(0) ? 'e' : 'f') . (empty(isZero(0)) ? 'E' : 'F') . "\n";

// —— 动态属性读写（$o->$p）——
$o = new stdClass();
$p = 'secret';
$o->$p = 42;
echo $o->secret . "\n";

// —— nullsafe + 空合并赋值（?-> / ??=）——
class Box { public function val($x) { return $x * 2; } }
$n = null;
echo ($n?->val(3) ?? 'nil') . "\n";
$k = null;
$k ??= 'def';
echo $k . "\n";

// —— 数组/列表解构（list / [...]）——
list($x, $y) = [10, 20];
[$u, $v] = [3, 4];
echo ($x + $y + $u + $v) . "\n";

// —— clone ——
class P { public $v = 1; }
$orig = new P();
$cp = clone $orig;
$cp->v = 9;
echo ($orig->v . $cp->v) . "\n";
