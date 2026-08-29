<?php
/**
 * Step 1: 环境检测视图
 * @var array $checks
 * @var bool $allPass
 */
?>
<h2 style="margin-bottom:20px; color:#495057;">环境检测</h2>
<ul class="check-list">
    <?php foreach ($checks as $c): ?>
        <li class="<?= $c[1] ? 'pass' : 'fail' ?>">
            <span><?= htmlspecialchars($c[0]) ?></span>
            <span><?= $c[1] ? '✅ 通过' : '❌ 失败' ?><?= isset($c[2]) ? ' (' . htmlspecialchars($c[2]) . ')' : '' ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<div style="margin-top:24px; text-align:right;">
    <?php if ($allPass): ?>
        <a href="?step=2" class="btn">下一步：数据库配置 →</a>
    <?php else: ?>
        <div class="alert alert-error" style="text-align:left;">
            请先解决以上失败项，然后刷新页面重试。
        </div>
    <?php endif; ?>
</div>