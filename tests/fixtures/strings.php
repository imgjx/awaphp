<?php
// 字符串 / 数字 / 常量上下文混淆测试样例
// 用于验证 StringEncoder 会跳过"常量必需上下文"（const 值、默认参数、switch case）
const APP_NAME = "MyApp";            // const 字符串 -> 不可编码

function greet($who = "guest")        // 默认参数字符串 -> 不可编码
{
    return "hello " . $who;
}

function tag($x)
{
    return "<b>" . $x . "</b>";       // HTML 字符串 -> 可编码
}

$cmd = "a";
switch ($cmd) {
    case "a":                         // case 值 -> 不可编码
        $msg = "alpha";
        break;
    default:
        $msg = "other";
}

echo APP_NAME . ":" . greet() . ":" . tag("x") . ":" . $msg . "\n";
echo 255 . "\n";                     // 数字 -> 可编码
echo 0x1F . "\n";                    // 十六进制数字
