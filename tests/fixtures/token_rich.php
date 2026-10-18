<?php
/**
 * Token 形态混淆专用 fixture：覆盖模块会触碰的每一类 token 形态。
 * 输出完全确定性（无随机/时间/环境依赖）。
 */

// —— 关键字全景：流程控制 / 声明 / 字面量 / 转换 ——
function control($n) {
    $s = 0;
    if ($n > 10) {
        $s = 1;
    } elseif ($n > 5) {
        $s = 2;
    } else {
        $s = 3;
    }
    for ($i = 0; $i < 3; $i++) {
        $s += $i;
    }
    $j = 0;
    while ($j < 2) {
        $s += 10;
        $j++;
    }
    do {
        $s -= 1;
        $j--;
    } while ($j > 0);
    foreach ([1, 2, 3] as $v) {
        $s += $v;
    }
    switch ($s % 3) {
        case 0:
            $s += 100;
            break;
        case 1:
            $s += 200;
            break;
        default:
            $s += 300;
    }
    return $s;
}

// —— 字面量形态：true/false/null / cast 别名 / array() 长语法 ——
function literals($x) {
    $a = true;
    $b = false;
    $c = null;
    $d = (int) $x;
    $e = (bool) $x;
    $f = (float) $x;
    $g = (string) $x;
    $h = array(1, 2, 3);
    $i = array('k' => 'v', 'n' => 9);
    $arr = array();
    $arr[] = $a ? 1 : 0;
    $arr[] = $b ? 1 : 0;
    $arr[] = $c === null ? 1 : 0;
    $arr[] = (integer) $x + 1;
    $arr[] = (boolean) $x ? 1 : 0;
    $arr[] = (double) $x + 0.5;
    return $d . ',' . $e . ',' . $f . ',' . $g . ',' . count($h) . ',' . $i['n'] . ',' . implode(',', $arr);
}

// —— 字符串形态：单引号 / 双引号 / 转义 / 插值（simple + curly） ——
function strings($name, $n) {
    $sq = 'plain single';
    $dq = "plain double";
    $esc = 'it\'s a \"test\" \\ done';
    $esc2 = "line\nbreak\ttab";
    $interp = "hello $name, you have $n messages";
    $curly = "user={$name} num={$n} idx={$name[0]}";
    $arr = ['key' => 'val'];
    $mixed = "pre {$arr['key']} post";
    $empty = '';
    $quotes = 'has "double" inside';
    $quotes2 = "has 'single' inside";
    return $sq . '|' . $dq . '|' . $esc . '|' . $esc2 . '|' . $interp . '|' . $curly . '|' . $mixed . '|' . $empty . '|' . $quotes . '|' . $quotes2;
}

// —— heredoc / nowdoc 源码（应原样穿过） ——
function heredocs($who) {
    $h = <<<EOT
Hello $who, this is heredoc with \n literal escape
EOT;
    $n = <<<'NOW'
raw nowdoc $who \n stays literal
NOW;
    return $h . '|' . $n;
}

// —— 类 / 接口 / trait / 常量 / 静态 ——
// 注：VM 壳暂不支持静态属性【写】（self::$made++ 会走语句级 eval 回退，
// 而 eval 内既无类作用域也无 VM 虚拟类，必然致命），故计数器只保留声明、
// 不写不读；self:: 的字面量覆盖走 side() 的常量读（VM 的 SPROP 常量路径支持）。
interface Shape {
    public function area();
}

trait Loud {
    public function shout() {
        return strtoupper($this->name());
    }
}

class Box implements Shape {
    use Loud;
    const SIDE = 4;
    public static $made = 0;
    private $w;
    protected $h;
    public function __construct($w = 2, $h = 3) {
        $this->w = $w;
        $this->h = $h;
    }
    public function name() {
        return 'box';
    }
    public function area() {
        return $this->w * $this->h;
    }
    public function side() {
        return self::SIDE;
    }
    public static function make() {
        return new Box();
    }
    public function __toString() {
        return (string) $this->area();
    }
}

// —— 闭包 / 箭头函数 / match / 命名参数 / 属性 ——
$mul = function ($a, $b = 2) use (&$counter) {
    return $a * $b;
};
$add = fn($a, $b = 1) => $a + $b;
$gx = 7;
$grade = match (true) {
    $gx > 5 => 'high',
    default => 'low',
};
function named($first, $second = 'b') {
    return $first . $second;
}

#[Attribute]
class Marker {}

#[Marker]
final class Tagged {
    public function label(): string {
        // 位置参数（命名参数形态见 tests/fixtures/named_args.php：
        // VM 壳暂不支持命名参数调用，会走语句级 eval 回退且找不到 VM 函数表符号）
        return named('x', 'y');
    }
}

// —— 逻辑运算符 / 位运算 / 三元 / 空合并前身（isset） ——
function ops($a, $b) {
    $r = [];
    $r[] = ($a && $b) ? 'and' : 'or';
    $r[] = ($a || !$b) ? 'or' : 'nor';
    $r[] = ($a xor $b) ? 'xor' : 'eq';
    $r[] = (isset($a) && empty($b)) ? 'ie' : 'ne';
    $r[] = ($a instanceof Box) ? 'box' : 'other';
    $r[] = (5 & 3) . (5 | 2) . (5 ^ 1) . (1 << 3) . (16 >> 2) . (~0 & 0xFF);
    list($p, $q) = [1, 2];
    $r[] = $p + $q;
    return implode('|', $r);
}

// —— try/catch/finally + throw ——
function risky($n) {
    try {
        if ($n < 0) {
            throw new Exception('neg');
        }
        return 'ok:' . $n;
    } catch (Exception $e) {
        return 'caught:' . $e->getMessage();
    } finally {
        $GLOBALS['__fin'] = ($GLOBALS['__fin'] ?? 0) + 1;
    }
}

// —— global / static 变量 / 引用 ——
function counters() {
    static $c = 0;
    $c++;
    global $cfg;
    $cfg = $cfg ?? 'default';
    return $c . ':' . $cfg;
}

// ======================= 演示输出 =======================
$GLOBALS['__fin'] = 0;
$box = new Box(3, 4);
echo control(7), "\n";
echo control(3), "\n";
echo literals(5), "\n";
echo literals(0), "\n";
echo strings('awa', 3), "\n";
echo heredocs('world'), "\n";
echo $box->area(), ',', $box->__toString(), ',', $box->shout(), ',', $box->side(), ',', Box::SIDE, "\n";
echo $mul(3), ',', $add(4), ',', $grade, ',', (new Tagged())->label(), "\n";
echo ops(true, false), "\n";
echo risky(5), "\n";
echo risky(-1), "\n";
echo counters(), ',', counters(), "\n";
echo 'FINAL ok', "\n";
