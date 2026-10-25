<?php
/**
 * 命名参数回归 fixture（tests/fixtures/named_args.php）
 *
 * 覆盖三类命名参数场景：
 *  1. 用户函数命名参数调用（var 层重命名参数后，调用点必须同步改写，
 *     否则运行期 Unknown named parameter —— 2026-10 修复的既有 bug）；
 *  2. 参数名与调用方局部变量同名（映射表全局一致，不得串味）；
 *  3. 内建函数命名参数（str_replace(search: ...) —— 参数名由 PHP 固定，
 *     混淆器绝不改写，否则同样 Unknown named parameter）。
 * 输出完全确定性。
 */

function tag($first, $second = 'b') {
    return $first . $second;
}

// 场景 1：命名参数调用（参数将被 var 层重命名，调用点须同步）
echo tag(first: 'x', second: 'y'), "\n";
echo tag(first: 'p'), "\n";
echo tag('q'), "\n";

// 场景 2：局部变量与参数同名（$first 在映射表内有条目，但这里不是命名参数位）
function localShadow() {
    $first = 'local';
    $search = 'needle';
    $subject = 'find the needle here';
    $replaced = str_replace(search: $search, replace: 'pin', subject: $subject);
    return $first . '|' . $replaced;
}
echo localShadow(), "\n";

// 场景 3：内建函数命名参数 + 用户命名参数混排
function pick($a, $b = 2, $c = 3) {
    return $a * 100 + $b * 10 + $c;
}
echo pick(c: 9, a: 1), "\n";
echo pick(a: 5, c: 7, b: 6), "\n";

// 场景 4：方法命名参数
class Maker {
    public function build($w = 1, $h = 2) {
        return $w . 'x' . $h;
    }
}
$m = new Maker();
echo $m->build(w: 3), "\n";
echo $m->build(h: 9, w: 8), "\n";

// 场景 5：三目/标签同形态不得误判（b : c 不是命名参数）
function ternaryLike($flag) {
    $b = 'B';
    $c = 'C';
    return $flag ? $b : $c;
}
echo ternaryLike(true), ',', ternaryLike(false), "\n";

echo 'FINAL ok', "\n";
