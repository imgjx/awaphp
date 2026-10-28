<?php
/**
 * v1.0.7 回归夹具：VM 运行时内存加密 + 新增复杂语法。
 * 覆盖：
 *   - 操作数栈加密对多种值类型的往返保真（标量 / 浮点 / 大整数 / 嵌套数组）
 *   - 不可序列化值（对象、含对象的数组）走旁路存储后仍取回原值
 *   - clone / 空安全 ?-> / ??= / 数组与 list 解构
 */

// —— 内存加密：多类型值经操作数栈往返 ——
function rt_values()
{
    $big = 2246822519;                       // > 2^31 整数
    $f   = 1.25 + 2.75;                      // 浮点
    $arr = [1, 2, ["x" => 3], 4.5, "s", true, null];
    $sum = 0;
    foreach ($arr as $v) {
        if (is_int($v)) { $sum += $v; }
    }
    $nested = ["k" => ["j" => [9, 8, 7]]];
    $deep   = $nested["k"]["j"][1];          // 8
    echo $sum . "|" . $f . "|" . $deep . "|" . $big . "|" . (($big + 1) & 0x7FFFFFFF);
}

// —— 不可序列化值（对象 / 含对象的数组）经旁路存储往返 ——
class V107Box { public $v = 7; }
function rt_unserializable()
{
    $o = new V107Box();
    $wrapped = [$o, 1, 2];                    // 含对象 → 序列化失败 → 旁路存储
    echo "|" . $wrapped[0]->v . "|" . $o->v;
}

// —— clone：深拷贝语义（改副本不影响原件）——
class V107Clone { public $v = 7; }
function rt_clone()
{
    $a = new V107Clone();
    $b = clone $a;
    $b->v = 9;
    echo "|" . $a->v . $b->v;                 // 79
}

// —— 空安全 ?-> ——
class V107Inner { public $x = 5; }
class V107Outer { public $p = null; }
function rt_nullsafe()
{
    $o1 = new V107Outer();
    $o2 = new V107Outer();
    $o2->p = new V107Inner();
    echo "|" . ($o1->p?->x ?? "none");         // none
    echo "|" . $o2->p?->x;                     // 5
    $nil = null;
    echo "|" . ($nil?->foo() ?? "nil");        // nil
}

// —— ??= ——
function rt_coalesce_assign()
{
    $a = null;
    $a ??= 5;
    echo "|" . $a;                             // 5
    $a ??= 9;                                  // 已非 null，保持 5
    echo $a;                                   // 5
}

// —— 数组 / list 解构 ——
function rt_destructure()
{
    $arr = [1, 2];
    [$x, $y] = $arr;
    echo "|" . $x . $y;                        // 12
    $kv = ["k" => 5];
    ["k" => $v] = $kv;
    echo "|" . $v;                             // 5
    $arr3 = [1, 2, 3];
    [, $mid, ] = $arr3;
    echo "|" . $mid;                           // 2
    list($m, $n) = $arr;
    echo "|" . $m . $n;                        // 12
}

rt_values();
rt_unserializable();
rt_clone();
rt_nullsafe();
rt_coalesce_assign();
rt_destructure();
echo "\n";
