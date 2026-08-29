<div class="page-header">
    <h1>📋 登录日志</h1>
    <p class="subtitle">最近 100 条登录记录 · 30 天前自动清理</p>
</div>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="card">
    <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr style="border-bottom:1px solid var(--gray-200); text-align:left;">
                <th style="padding:10px 6px;">时间</th>
                <th style="padding:10px 6px;">用户</th>
                <th style="padding:10px 6px;">IP</th>
                <th style="padding:10px 6px;">状态</th>
                <th style="padding:10px 6px;">原因</th>
                <th style="padding:10px 6px;">User-Agent</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr style="border-bottom:1px solid var(--gray-100);">
                <td style="padding:8px 6px; font-family:monospace; font-size:12px;"><?= h($log['created_at']) ?></td>
                <td style="padding:8px 6px;"><?= h($log['username'] ?? '-') ?></td>
                <td style="padding:8px 6px; font-family:monospace; font-size:12px;"><?= h($log['ip'] ?? '-') ?></td>
                <td style="padding:8px 6px;">
                    <?php if ($log['status'] === 'success'): ?>
                        <span style="color:#059669; font-weight:600;">✓ 成功</span>
                    <?php elseif ($log['status'] === 'locked'): ?>
                        <span style="color:#d97706; font-weight:600;">🔒 锁定</span>
                    <?php else: ?>
                        <span style="color:#dc2626; font-weight:600;">✗ 失败</span>
                    <?php endif; ?>
                </td>
                <td style="padding:8px 6px; color:var(--gray-600); font-size:12px;"><?= h($log['reason'] ?? '-') ?></td>
                <td style="padding:8px 6px; color:var(--gray-500); font-size:11px; max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($log['user_agent'] ?? '') ?>">
                    <?= h(substr($log['user_agent'] ?? '-', 0, 50)) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>