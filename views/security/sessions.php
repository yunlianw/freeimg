<div class="page-header">
    <h1>📱 活跃会话</h1>
    <p class="subtitle">所有登录你账号的设备 · 可强制下线</p>
</div>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="card">
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:1px solid var(--gray-200); text-align:left;">
                <th style="padding:12px 8px;">设备</th>
                <th style="padding:12px 8px;">IP</th>
                <th style="padding:12px 8px;">最后活跃</th>
                <th style="padding:12px 8px;">过期时间</th>
                <th style="padding:12px 8px;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
            <tr style="border-bottom:1px solid var(--gray-100);">
                <td style="padding:12px 8px; font-size:13px;">
                    <?= h(substr($s['user_agent'] ?? '-', 0, 60)) ?>
                    <?php if ($s['session_token'] === $currentToken): ?>
                        <span style="color:#059669; font-weight:600; margin-left:6px;">· 当前</span>
                    <?php endif; ?>
                </td>
                <td style="padding:12px 8px; font-family:monospace; font-size:12px;"><?= h($s['ip'] ?? '-') ?></td>
                <td style="padding:12px 8px; font-size:13px;"><?= h($s['last_activity_at']) ?></td>
                <td style="padding:12px 8px; font-size:13px;"><?= h($s['expires_at']) ?></td>
                <td style="padding:12px 8px;">
                    <?php if ($s['session_token'] !== $currentToken): ?>
                    <form method="POST" action="<?= base_url('security/sessions/' . $s['session_token'] . '/destroy') ?>" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <button type="submit" class="btn-mini btn-mini-danger" onclick="return confirm('强制下线此设备？')">下线</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($sessions)): ?>
            <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--gray-500);">暂无活跃会话</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card" style="background:var(--gray-50);">
    <div class="card-title">🔐 安全提示</div>
    <ul style="font-size:13px; color:var(--gray-700); line-height:1.8;">
        <li>如果发现<strong>陌生设备</strong>，立即下线 + 修改密码 + 开启 2FA</li>
        <li>会话有效期：<?= (int)config('settings.session_ttl_hours') ?: 24 ?> 小时（后台可配置）</li>
        <li>每次操作（最多每分钟 1 次）会自动延长会话</li>
    </ul>
</div>