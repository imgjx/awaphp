<?php
/**
 * do-while 回归 fixture（tests/fixtures/do_while.php）
 *
 * 2026-10 修复：StatementSplitter 曾在 do-while 的 } 后刷新语句，把
 * `while (cond);` 从句子上切掉，VM 编译/回退产出缺 while 的残缺语句
 * （syntax error, unexpected ";" expecting "while"）。GotoObfuscator 与
 * VM 均消费 StatementSplitter，故两类控制流混淆都需回归。
 * 输出完全确定性。
 */

$j = 3;
$s = 0;
do {
    $s += $j;
    $j--;
} while ($j > 0);
echo $s, ',', $j, "\n";

// do-while 内嵌 break/continue（语句切分 + 循环上下文）
$k = 0;
$acc = [];
do {
    $k++;
    if ($k % 2 === 0) {
        continue;
    }
    if ($k > 5) {
        break;
    }
    $acc[] = $k;
} while ($k < 10);
echo implode(',', $acc), "\n";

// 嵌套：do-while 里套 while/for
$n = 2;
$out = '';
do {
    for ($i = 0; $i < $n; $i++) {
        $out .= $i;
    }
    $out .= '|';
    $n--;
} while ($n > 0);
echo $out, "\n";

echo 'FINAL ok', "\n";
