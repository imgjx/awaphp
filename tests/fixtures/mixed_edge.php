<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>混写边界页</title></head>
<body>
<?php
$title = '高级混写边界';
$items = ['alpha', 'beta', 'gamma'];
/* 块注释里含有 ?> 也不会切出 PHP 模式；单行注释才会，故这里刻意用块注释验证规范化器 */
?>
<h1 data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>" data-count="<?= count($items) ?>"><?= $title ?></h1>

<?php if (count($items) > 0): ?>
<ul class="list">
<?php foreach ($items as $i => $it): ?>
  <li id="item-<?= $i ?>" class="<?= ($i % 2) ? 'odd' : 'even' ?>"><?= strtoupper($it) ?></li>
<?php endforeach; ?>
</ul>
<?php elseif (count($items) === 0): ?>
<p>empty-list</p>
<?php else: ?>
<p>unexpected</p>
<?php endif; ?>

<?php for ($k = 0; $k < 3; $k++): ?><span class="k<?= $k ?>"><?= $k * 2 ?></span><?php endfor; ?>

<?php $w = 0; while ($w < 3): ?><b data-w="<?= $w ?>"><?= $w ?></b><?php $w++; endwhile; ?>

<?php switch (count($items)): case 3: ?><em>three-items</em><?php break; case 2: ?><em>two-items</em><?php break; default: ?><em>other</em><?php endswitch; ?>

<script type="text/javascript">var __n = <?= count($items) ?>; var __t = "<?= $title ?>";</script>
<style type="text/css">.x::after { content: "<?= $title ?>"; }</style>

<?php
$hd = <<<HTML
<section class="heredoc"><p>{$title}</p><span>HTML-in-heredoc</span></section>
HTML;
echo $hd, "\n";
?><?php echo "相邻边界 OK\n"; ?>

<!-- HTML 注释内嵌 PHP：<?php echo 'comment-php'; ?> -->
<?php
echo "END\n";
