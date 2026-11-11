<!DOCTYPE html>
<!--<?= 'TP8 混写看板 / 静态构建' ?>-->
<?php
/**
 * 极复杂 PHP/HTML 混写页面（TP8 高级混写基准夹具）
 *
 * 覆盖：文件开头 T_INLINE_HTML、<?= 短标签、if/elseif/else/endif、foreach/endforeach、
 * for/endfor、while/endwhile、switch/endswitch 等替代语法跨 ?><?php 标签边界、PHP 嵌
 * HTML 属性与 <script>/<style>、heredoc 内 HTML、?><?php 紧邻边界、HTML 注释内 PHP。
 *
 * 数据通道：TP8 控制器把真实数据放进 $GLOBALS['__awa_dashboard']；独立运行（本文件被
 * php 直接执行或被混淆器单测）时回退到下方内置确定性数据，保证输出逐字节可复现。
 */
$d = $GLOBALS['__awa_dashboard'] ?? null;
if (!is_array($d)) {
    $d = [
        'title'  => 'AWA 商城 · 运营看板',
        'badge'  => 'DEMO',
        'nav'    => [
            ['k' => 'overview', 'label' => '概览',   'on' => true],
            ['k' => 'goods',    'label' => '商品',   'on' => false],
            ['k' => 'orders',   'label' => '订单',   'on' => false],
        ],
        'products' => [
            ['id' => 1, 'name' => '机械键盘', 'price' => 399.0, 'stock' => 50, 'on_sale' => 1, 'tags' => ['外设', '键盘']],
            ['id' => 2, 'name' => '显示器',   'price' => 1299.0,'stock' => 3,  'on_sale' => 1, 'tags' => ['外设']],
            ['id' => 3, 'name' => '已下架品', 'price' => 99.0,  'stock' => 99, 'on_sale' => 0, 'tags' => []],
        ],
        'orders' => [
            ['id' => 1001, 'user' => 'awa',   'amount' => 798.0,  'status' => 'paid'],
            ['id' => 1002, 'user' => 'guest', 'amount' => 1299.0, 'status' => 'pending'],
            ['id' => 1003, 'user' => 'awa',   'amount' => 99.0,   'status' => 'closed'],
        ],
        'stats'  => ['total' => 3, 'onSale' => 2, 'lowStock' => 1, 'revenue' => 2196.0],
        'matrix' => [[1, 2, 3], [4, 5, 6], [7, 8, 9]],
        'flags'  => [true, false, true],
        'notice' => '库存低于 5 的商品已标红',
    ];
}
$title   = $d['title'];
$badge   = $d['badge'];
$nav     = $d['nav'];
$products= $d['products'];
$orders  = $d['orders'];
$stats   = $d['stats'];
$matrix  = $d['matrix'];
$flags   = $d['flags'];
$notice  = $d['notice'];
$themeHue = 210;
?>
<html lang="zh-CN" data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?> [<?= $badge ?>]</title>
<style>
:root{--hue:<?= (int) $themeHue ?>;--accent:hsl(<?= (int) $themeHue ?> 70% 45%);}
<?php foreach ($nav as $i => $n): ?>
.nav-<?= $i ?>{color:<?= $n['on'] ? 'var(--accent)' : '#889' ?>;font-weight:<?= $n['on'] ? '700' : '400' ?>;}
<?php endforeach; ?>
<?php if ($stats['lowStock'] > 0): ?>
.stock-low{color:#c33;}
<?php else: ?>
.stock-low{display:none;}
<?php endif; ?>
</style>
<script>
window.__DASH__ = <?= json_encode(['stats' => $stats, 'matrix' => $matrix], JSON_UNESCAPED_UNICODE) ?>;
<?php foreach ($products as $p) { ?>
window.__DASH__.goods = window.__DASH__.goods || [];
window.__DASH__.goods.push({id:<?= (int) $p['id'] ?>, name:<?= json_encode($p['name'], JSON_UNESCAPED_UNICODE) ?>, price:<?= (float) $p['price'] ?>});
<?php } ?>
</script>
</head>
<body class="theme-<?= $badge === 'DEMO' ? 'demo' : 'live' ?>">
<?php /* HTML 与 PHP 紧邻边界：下面一行故意让 ?> 与 <?php 相邻 */ ?><?php if (count($nav) > 0) { ?><nav aria-label="主导航">
<?php foreach ($nav as $i => $n): ?>
  <?php if ($n['on']): ?>
  <a class="nav-<?= $i ?> active" href="#<?= $n['k'] ?>" aria-current="page"><?= htmlspecialchars($n['label']) ?></a>
  <?php elseif ($i % 2 === 1): ?>
  <a class="nav-<?= $i ?> odd" href="#<?= $n['k'] ?>"><?= htmlspecialchars($n['label']) ?>(奇)</a>
  <?php else: ?>
  <a class="nav-<?= $i ?>" href="#<?= $n['k'] ?>"><?= htmlspecialchars($n['label']) ?></a>
  <?php endif; ?>
<?php endforeach; ?>
</nav><?php } ?>

<header>
  <h1><?= htmlspecialchars($title, ENT_QUOTES) ?><sup><?= $badge ?></sup></h1>
  <?php
  // heredoc 内含 HTML，且嵌入 PHP 变量插值
  $hero = <<<HTML
  <div class="hero" data-count="{$stats['total']}">
    <p>共 {$stats['total']} 件商品，{$stats['onSale']} 件在售，营收 ¥{$stats['revenue']}。</p>
  </div>
HTML;
  echo $hero;
  ?>
  <!-- 提示：<?= htmlspecialchars($notice, ENT_QUOTES) ?> -->
</header>

<section id="goods">
<h2>商品清单</h2>
<table border="1" data-on-sale="<?= $stats['onSale'] ?>">
<thead><tr><th>#</th><th>名称</th><th>价格</th><th>库存</th><th>状态</th><th>标签</th></tr></thead>
<tbody>
<?php foreach ($products as $p): $pid = (int) $p['id']; ?>
<tr id="row-<?= $pid ?>" class="<?= $p['on_sale'] ? 'sale' : 'off' ?>">
  <td><?= $pid ?></td>
  <td data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($p['name'], ENT_QUOTES) ?></td>
  <td><?= number_format((float) $p['price'], 2) ?></td>
  <?php if ($p['stock'] < 5): ?><td class="stock-low"><?= (int) $p['stock'] ?></td>
  <?php else: ?><td><?= (int) $p['stock'] ?></td><?php endif; ?>
  <?php switch ($p['on_sale']): case 1: ?><td>在售</td><?php break; ?><?php case 0: ?><td>下架</td><?php break; ?><?php default: ?><td>未知</td><?php endswitch; ?>
  <td><?php foreach ($p['tags'] as $t) { ?><span class="tag"><?= htmlspecialchars($t) ?></span><?php } ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</section>

<section id="orders">
<h2>订单矩阵</h2>
<?php $i = 0; while ($i < count($orders)): $o = $orders[$i]; ?>
<div class="order" data-id="<?= (int) $o['id'] ?>">
  <span>#<?= (int) $o['id'] ?></span>
  <span><?= htmlspecialchars($o['user']) ?></span>
  <span>¥<?= number_format((float) $o['amount'], 2) ?></span>
  <?php if ($o['status'] === 'paid'): ?><b class="paid">已支付</b>
  <?php elseif ($o['status'] === 'pending'): ?><b class="pending">待支付</b>
  <?php else: ?><b class="closed">已关闭</b><?php endif; ?>
</div>
<?php $i++; endwhile; ?>
</section>

<section id="matrix">
<h2>数值矩阵（for/endfor 替代语法）</h2>
<table>
<?php for ($r = 0; $r < count($matrix); $r++): ?>
<tr data-row="<?= $r ?>">
<?php for ($c = 0; $c < count($matrix[$r]); $c++): ?>
  <td data-r="<?= $r ?>" data-c="<?= $c ?>"><?= $matrix[$r][$c] * $matrix[$r][$c] ?></td>
<?php endfor; ?>
</tr>
<?php endfor; ?>
</table>
</section>

<section id="flags">
<h2>布尔旗标（三元 + 短标签嵌属性）</h2>
<?php foreach ($flags as $k => $f): ?>
<input type="checkbox" data-k="<?= $k ?>" <?= $f ? 'checked' : '' ?> value="<?= $f ? '1' : '0' ?>"><label><?= $f ? 'ON' : 'OFF' ?></label>
<?php endforeach; ?>
</section>

<footer>
<?php
$sum = 0;
foreach ($products as $p) { $sum += (int) $p['stock']; }
echo "<!-- 库存合计: {$sum} / 生成标记: STATIC-BUILD -->\n";
?>
<p>&copy; AWA · <?= $badge ?></p>
</footer>
</body>
</html>
