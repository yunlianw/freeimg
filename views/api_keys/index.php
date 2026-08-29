<div class="page-header">
    <div>
        <h1>🔑 API Key 管理</h1>
        <p class="subtitle">用于 PicGo / ShareX / 帝国CMS 等第三方程序远程上传</p>
    </div>
    <a href="<?= base_url('settings') ?>" class="btn-link">⚙️ 设置</a>
</div>

<?php if (!empty($_SESSION['new_api_key_secret'])): $showSecret = $_SESSION['new_api_key_secret']; $showName = $_SESSION['new_api_key_name'] ?? ''; unset($_SESSION['new_api_key_secret'], $_SESSION['new_api_key_name']); ?>
<div class="alert alert-success" style="background:#fff3cd; border:2px solid #ffc107; padding:16px;">
    <strong style="color:#d97706;">⚠️ 重要：API Key 创建成功！请立即保存以下信息（secret_key 仅显示一次）</strong>
    <div style="background:#fff; padding:12px; border-radius:6px; margin-top:12px; font-family:monospace;">
        <div><strong>名称：</strong> <?= htmlspecialchars($showName) ?></div>
        <div style="margin-top:8px;"><strong>Access Key：</strong> <code id="ak-val"><?= htmlspecialchars($_SESSION['new_api_key_id'] ?? '') ?></code></div>
        <div style="margin-top:8px;"><strong>Secret Key：</strong> <code id="sk-val"><?= htmlspecialchars($showSecret) ?></code></div>
        <button type="button" onclick="copyText('sk-val')" class="btn-primary" style="margin-top:8px; font-size:12px; padding:4px 12px;">📋 复制 Secret Key</button>
    </div>
    <div style="margin-top:8px; font-size:12px; color:#666;">
        调用方示例（PHP cURL）：
        <pre style="background:#f5f5f5; padding:8px; border-radius:4px; font-size:11px; overflow-x:auto;">curl -X POST -H "Authorization: Bearer ACCESS_KEY:SECRET_KEY" \
     -F "file=@/path/to/image.jpg" https://pic.5276.net/api/v1/upload</pre>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <div class="card-title">
        <span>➕ 创建新 API Key</span>
    </div>
    <form method="POST" action="<?= base_url('api-keys') ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-group" style="margin:0; flex:1; min-width:200px;">
            <label>名称（用途说明）</label>
            <input type="text" name="name" required maxlength="64" placeholder="例如：PicGo / 帝国CMS / Autoshop">
        </div>
        <div class="form-group" style="margin:0; min-width:160px;">
            <label>压缩预设</label>
            <select name="compression_profile_id">
                <option value="">跟随系统 API 默认</option>
                <?php foreach ($profiles as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0; min-width:160px;">
            <label>过期时间（可选）</label>
            <input type="date" name="expires_at">
        </div>
        <button type="submit" class="btn-primary">创建</button>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <span>📋 已创建的 API Key</span>
        <span style="font-size:13px; color:var(--gray-500);"><?= count($keys) ?> 个</span>
    </div>

    <?php if (empty($keys)): ?>
        <div class="empty-state" style="padding:32px;">
            <div class="empty-icon">🔑</div>
            <h3>还没有 API Key</h3>
            <p>创建第一个 API Key 给第三方程序（PicGo、ShareX 等）使用</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:var(--gray-50); text-align:left;">
                        <th style="padding:10px;">名称</th>
                        <th style="padding:10px;">Access Key</th>
                        <th style="padding:10px;">压缩预设</th>
                        <th style="padding:10px;">最后使用</th>
                        <th style="padding:10px;">过期</th>
                        <th style="padding:10px;">状态</th>
                        <th style="padding:10px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $k): ?>
                        <tr style="border-top:1px solid var(--gray-100);">
                            <td style="padding:10px;"><strong><?= htmlspecialchars($k['name']) ?></strong></td>
                            <td style="padding:10px; font-family:monospace; font-size:11px;"><?= htmlspecialchars($k['access_key']) ?></td>
                            <td style="padding:10px;">
                                <?php if ($k['compression_profile_id']): ?>
                                    <?php foreach ($profiles as $p) { if ($p['id'] == $k['compression_profile_id']) { echo htmlspecialchars($p['name']); break; } } ?>
                                <?php else: ?>
                                    <span style="color:var(--gray-500);">系统默认</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px; color:var(--gray-500); font-size:12px;">
                                <?= $k['last_used_at'] ? htmlspecialchars($k['last_used_at']) : '从未使用' ?>
                            </td>
                            <td style="padding:10px; color:var(--gray-500); font-size:12px;">
                                <?= $k['expires_at'] ? htmlspecialchars($k['expires_at']) : '永不过期' ?>
                            </td>
                            <td style="padding:10px;">
                                <?php if ($k['status']): ?>
                                    <span style="color:var(--green-500);">✓ 启用</span>
                                <?php else: ?>
                                    <span style="color:var(--red-500);">✕ 禁用</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <button type="button" class="api-toggle-btn" data-id="<?= (int)$k['id'] ?>" data-action="<?= $k['status'] ? 'revoke' : 'activate' ?>" style="font-size:12px; padding:2px 8px;">
                                    <?= $k['status'] ? '禁用' : '启用' ?>
                                </button>
                                <button type="button" class="api-delete-btn" data-id="<?= (int)$k['id'] ?>" style="font-size:12px; padding:2px 8px; margin-left:4px; color:var(--red-500);">删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
window.FREEIMG_CSRF = "<?= htmlspecialchars($csrf) ?>";
window.FREEIMG_BASE = "<?= base_url() ?>";
document.querySelectorAll('.api-toggle-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const fd = new FormData();
        fd.append('csrf_token', FREEIMG_CSRF);
        fd.append('id', btn.dataset.id);
        fd.append('action', btn.dataset.action);
        const r = await fetch(FREEIMG_BASE + '/api-keys/toggle', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) location.reload(); else alert(d.message);
    });
});
document.querySelectorAll('.api-delete-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('确认删除？此操作不可恢复！')) return;
        const fd = new FormData();
        fd.append('csrf_token', FREEIMG_CSRF);
        fd.append('id', btn.dataset.id);
        const r = await fetch(FREEIMG_BASE + '/api-keys/delete', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) location.reload(); else alert(d.message);
    });
});
</script>
