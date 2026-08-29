<?php if (!defined('FREEIMG_ROOT')) define('FREEIMG_ROOT', dirname(__DIR__, 2)); ?>
<div class="page-header">
    <div>
        <h1>💾 存储管理</h1>
        <p class="subtitle">配置图片存储位置：本地磁盘 / SFTP 远程服务器 / 云对象存储</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="<?= base_url('storages/form?driver=local') ?>" class="btn-primary">➕ 添加存储</a>
    </div>
</div>

<?php if (empty($list)): ?>
    <div class="empty-state">
        <div class="empty-icon">💾</div>
        <h3>还没有存储配置</h3>
        <p>添加一个存储驱动开始使用（本地存储开箱即用）</p>
        <a href="<?= base_url('storages/form?driver=local') ?>" class="btn-primary">添加本地存储</a>
    </div>
<?php else: ?>
    <div class="card" style="padding:0; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead>
                <tr style="background:var(--gray-100); text-align:left;">
                    <th style="padding:12px 16px;">名称</th>
                    <th style="padding:12px 16px;">驱动类型</th>
                    <th style="padding:12px 16px;">配置摘要</th>
                    <th style="padding:12px 16px;">状态</th>
                    <th style="padding:12px 16px; text-align:center;">优先级</th>
                    <th style="padding:12px 16px; text-align:center;">上传页</th>
                    <th style="padding:12px 16px;">容量</th>
                    <th style="padding:12px 16px; text-align:right;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $s): ?>
                <tr style="border-top:1px solid var(--gray-200);">
                    <td style="padding:12px 16px;">
                        <strong><?= htmlspecialchars($s['name']) ?></strong>
                        <?php if ($s['is_default']): ?>
                            <span class="tag-chip" style="background:var(--green-100); color:var(--green-700);">⭐ 默认</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;"><?= htmlspecialchars($s['driver_label']) ?></td>
                    <td style="padding:12px 16px; color:var(--gray-600); font-size:13px;"><?= htmlspecialchars($s['summary']) ?></td>
                    <td style="padding:12px 16px;">
                        <?php if ($s['status']): ?>
                            <span class="tag-chip" style="background:var(--green-100); color:var(--green-700);">✅ 启用</span>
                        <?php else: ?>
                            <span class="tag-chip" style="background:var(--gray-200); color:var(--gray-600);">⏸ 禁用</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px; text-align:center;">
                        <?= (int)$s['priority'] ?>
                    </td>
                    <td style="padding:12px 16px; text-align:center;">
                        <?php if ($s['visible_in_upload']): ?>
                            <span style="color:var(--green-600);">✅ 显示</span>
                        <?php else: ?>
                            <span style="color:var(--gray-400);">🚫 隐藏</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px; min-width:160px;">
                        <?php if ($s['max_capacity_mb'] > 0): ?>
                            <?php
                                // Phase 9.3: current_usage_mb 已是真实 MB（可能为小数）
                                $usedMb = (float)$s['current_usage_mb'];
                                $maxMb = (float)$s['max_capacity_mb'];
                                $pct = $maxMb > 0 ? min(100, (int)round($usedMb / $maxMb * 100)) : 0;
                                $color = $pct >= 80 ? '#dc2626' : ($pct >= 60 ? '#f59e0b' : '#10b981');
                                // 自动选单位显示（支持小数 MB）
                                $fmtMb = function (float $mb): string {
                                    if ($mb >= 1024) return number_format($mb / 1024, 2) . ' GB';
                                    if ($mb >= 1) return number_format($mb, 2) . ' MB';
                                    return round($mb * 1024, 1) . ' KB';
                                };
                            ?>
                            <div style="font-size:12px; color:var(--gray-600); margin-bottom:4px;">
                                <?= $fmtMb($usedMb) ?> / <?= $fmtMb($maxMb) ?>
                                <span style="color:<?= $color ?>; font-weight:600; margin-left:4px;"><?= $pct ?>%</span>
                            </div>
                            <div style="height:6px; background:var(--gray-200); border-radius:3px; overflow:hidden;">
                                <div style="height:100%; background:<?= $color ?>; width:<?= min(100, $pct) ?>%;"></div>
                            </div>
                        <?php else: ?>
                            <?php
                                // Phase 9.3: 真实容量显示（自动选单位，支持小数 MB）
                                $usedBytes = (float)$s['current_usage_mb'] * 1048576;
                                $fmt = function (float $bytes): string {
                                    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
                                    if ($bytes >= 1048576)   return number_format($bytes / 1048576, 2) . ' MB';
                                    if ($bytes >= 1024)       return number_format($bytes / 1024, 1) . ' KB';
                                    return round($bytes) . ' B';
                                };
                            ?>
                            <div style="font-size:12px; color:var(--gray-500);">∞ 无限制</div>
                            <div style="font-size:11px; color:var(--gray-400); margin-top:2px;">已用 <?= $fmt($usedBytes) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px; text-align:right; white-space:nowrap;">
                        <a href="<?= base_url('storages/form?id=' . $s['id']) ?>" class="btn-link">✏️ 编辑</a>
                        <form method="POST" action="<?= base_url('storages/recalc') ?>" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn-link" title="从图片表重新统计容量">🔄 重算</button>
                        </form>
                        <?php if (!$s['is_default']): ?>
                            <form method="POST" action="<?= base_url('storages/default') ?>" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn-link">⭐ 设默认</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="<?= base_url('storages/toggle') ?>" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn-link"><?= $s['status'] ? '⏸ 禁用' : '▶️ 启用' ?></button>
                        </form>
                        <?php if (!$s['is_default']): ?>
                            <form method="POST" action="<?= base_url('storages/delete') ?>" style="display:inline;" onsubmit="return confirm('确定删除「<?= htmlspecialchars($s['name']) ?>」？图片不会删除，只是不能再上传到该存储。')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn-link" style="color:var(--red-500);">🗑️ 删除</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="field-group" style="margin-top:20px;">
        <div class="field-group-title">ℹ️ 说明</div>
        <div class="hint">上传时使用 ⭐ 默认 存储；同一驱动可配置多个实例（如两台不同的 SFTP 服务器）。</div>
    </div>
<?php endif; ?>
