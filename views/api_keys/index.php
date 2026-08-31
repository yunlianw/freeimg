<link rel="stylesheet" href="<?= htmlspecialchars(base_url('assets/api-keys.css')) ?>?v=<?= filemtime(FREEIMG_ROOT . '/public/assets/api-keys.css') ?>">
<script>window.FREEIMG_CSRF = "<?= htmlspecialchars($csrf) ?>";</script>
<script src="<?= htmlspecialchars(base_url('assets/api-keys.js')) ?>?v=20260901" defer></script>

<div class="page-header">
    <div>
        <h1>🔑 API 密钥</h1>
        <p class="subtitle">为每个调用方创建独立密钥（PicGo / ShareX / 帝国CMS / AutoShop…）</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="<?= base_url('settings') ?>" class="btn-ghost">⚙️ 设置</a>
    </div>
</div>

<!-- 🔧 API 调试工具（上传 + 压缩档对比） -->
<div class="api-debug-card" id="api-debug-card">
    <div class="api-debug-header">
        <h2>🔧 API 调试工具</h2>
        <p>在线测试上传接口 + 实时对比各压缩档效果（用专用测试 Key，调试上传的图片自动 force_recompress=1）</p>
    </div>

    <div class="api-debug-body">
        <form id="api-debug-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="api-debug-row">
                <div class="api-debug-field api-debug-file">
                    <label>📁 选择图片</label>
                    <input type="file" name="file" id="api-debug-file" accept="image/*" required>
                    <div class="api-debug-file-preview" id="api-debug-preview"></div>
                </div>

                <div class="api-debug-field api-debug-compression">
                    <label>🎚️ 压缩档位（同步 /compression 配置）</label>
                    <select name="compression" id="api-debug-compression">
                        <?php foreach ($profiles as $p): ?>
                            <option value="<?= htmlspecialchars($p['code']) ?>"
                                <?= $p['code'] === 'balanced' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                                (<?= (int)$p['max_dimension'] ?>px / <?= strtoupper($p['output_format'] ?? 'auto') ?> q<?= (int)$p['jpeg_quality'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary api-debug-submit" id="api-debug-submit">
                        🚀 上传测试
                    </button>
                </div>
            </div>
        </form>

    <div class="api-debug-result" id="api-debug-result" style="display:none;"></div>
    </div>
</div>

<style>
.api-debug-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 24px;
    margin: 0 0 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.api-debug-header h2 { margin: 0 0 4px; font-size: 18px; }
.api-debug-header p { margin: 0 0 16px; color: #64748b; font-size: 14px; }
.api-debug-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.api-debug-field label { display:block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #475569; }
.api-debug-file input[type=file] {
    display:block; width:100%; padding: 10px 12px;
    border: 1px dashed #cbd5e1; border-radius: 8px;
    background: #f8fafc; font-size: 13px;
}
.api-debug-file-preview {
    margin-top: 10px; min-height: 60px;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; color: #64748b;
}
.api-debug-file-preview img {
    max-height: 80px; max-width: 120px;
    border-radius: 6px; border: 1px solid #e2e8f0;
}
.api-debug-compression select {
    width: 100%; padding: 10px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    background: #fff; font-size: 14px; margin-bottom: 12px;
}
.api-debug-submit {
    width: 100%; padding: 10px;
    background: #3b82f6;
    border: none; border-radius: 8px;
    color: #fff; font-weight: 600; font-size: 14px; cursor: pointer;
}
.api-debug-submit:disabled { background: #94a3b8; cursor: not-allowed; }
.api-debug-result {
    margin-top: 18px; padding: 18px;
    background: #f8fafc; border-radius: 10px;
    border: 1px solid #e2e8f0;
}
.api-debug-result h3 { margin: 0 0 12px; font-size: 16px; }
.api-debug-result.success h3 { color: #16a34a; }
.api-debug-result.error h3 { color: #dc2626; }
.api-debug-stats {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px; margin-bottom: 16px;
}
.api-debug-stat {
    background: #fff; padding: 12px;
    border-radius: 8px; border: 1px solid #e2e8f0;
}
.api-debug-stat-label { font-size: 12px; color: #94a3b8; }
.api-debug-stat-value { font-size: 20px; font-weight: 700; color: #1e293b; margin-top: 4px; }
.api-debug-stat-value.small { color: #16a34a; }
.api-debug-comparison {
    display: flex; gap: 14px; align-items: center;
    padding: 12px; background: #fff; border-radius: 8px;
    border: 1px solid #e2e8f0; flex-wrap: wrap;
}
.api-debug-comparison > div { flex: 1; min-width: 0; }
.api-debug-comparison img {
    max-height: 100px; max-width: 100%;
    border-radius: 6px; border: 1px solid #e2e8f0;
}
@media (max-width: 768px) {
    .api-debug-row { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    const fileInput = document.getElementById('api-debug-file');
    const preview = document.getElementById('api-debug-preview');
    const compressionSel = document.getElementById('api-debug-compression');
    const form = document.getElementById('api-debug-form');
    const submitBtn = document.getElementById('api-debug-submit');
    const resultBox = document.getElementById('api-debug-result');

    // 选中文件预览
    fileInput?.addEventListener('change', () => {
        const f = fileInput.files[0];
        preview.innerHTML = '';
        if (!f) return;
        const sizeKB = (f.size / 1024).toFixed(1);
        const url = URL.createObjectURL(f);
        const img = document.createElement('img');
        img.src = url;
        img.onload = () => URL.revokeObjectURL(url);
        const meta = document.createElement('div');
        meta.innerHTML = `<strong>${escapeHtml(f.name)}</strong><br>${sizeKB} KB · ${f.type || '?'}`;
        preview.appendChild(img);
        preview.appendChild(meta);
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1024 / 1024).toFixed(2) + ' MB';
    }

    // 提交调试上传
    form?.addEventListener('submit', async e => {
        e.preventDefault();
        if (!fileInput.files[0]) {
            alert('请先选择一张图片');
            return;
        }
        submitBtn.disabled = true;
        const origText = submitBtn.textContent;
        submitBtn.textContent = '⏳ 上传中…';
        resultBox.style.display = 'none';
        resultBox.className = 'api-debug-result';

        try {
            const fd = new FormData(form);
            // 防御性归一化：FREEIMG_BASE 可能带/不带尾部斜杠，统一去尾再拼接
            const base = (window.FREEIMG_BASE || '').replace(/\/+$/, '');
            const res = await fetch(base + '/api-keys/debug-upload', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json().catch(() => null);

            if (!res.ok || !data || !data.success) {
                resultBox.className = 'api-debug-result error';
                resultBox.style.display = '';
                resultBox.innerHTML = '<h3>❌ ' + escapeHtml((data && data.message) || ('HTTP ' + res.status)) + '</h3>' +
                    '<div style="margin-top:10px;font-size:12px;color:#64748b;">期望 URL: ' + escapeHtml(base + '/api-keys/debug-upload') + '<br>实际请求 URL: ' + escapeHtml(res.url || '?') + '<br>Status: ' + res.status + ' ' + res.statusText + '</div>';
                return;
            }

            // 成功
            const img = data.image;
            const ratio = data.size_before / Math.max(1, data.size_after);
            const savedPct = ((1 - data.size_after / data.size_before) * 100).toFixed(1);
            resultBox.className = 'api-debug-result success';
            resultBox.style.display = '';
            resultBox.innerHTML = `
                <h3>✅ 调试上传成功 · 档位 <code>${escapeHtml(data.compression)}</code></h3>
                <div class="api-debug-stats">
                    <div class="api-debug-stat">
                        <div class="api-debug-stat-label">原始大小</div>
                        <div class="api-debug-stat-value">${formatBytes(data.size_before)}</div>
                    </div>
                    <div class="api-debug-stat">
                        <div class="api-debug-stat-label">压缩后</div>
                        <div class="api-debug-stat-value small">${formatBytes(data.size_after)}</div>
                    </div>
                    <div class="api-debug-stat">
                        <div class="api-debug-stat-label">节省</div>
                        <div class="api-debug-stat-value small">${savedPct}%</div>
                    </div>
                    <div class="api-debug-stat">
                        <div class="api-debug-stat-label">压缩比</div>
                        <div class="api-debug-stat-value">${ratio.toFixed(2)}x</div>
                    </div>
                    <div class="api-debug-stat">
                        <div class="api-debug-stat-label">尺寸</div>
                        <div class="api-debug-stat-value">${img.width}×${img.height}</div>
                    </div>
                    <div class="api-debug-stat">
                        <div class="api-debug-stat-label">压缩方式</div>
                        <div class="api-debug-stat-value">${escapeHtml(img.compressor || '?')}</div>
                    </div>
                </div>
                <div class="api-debug-comparison">
                    <div>
                        <div style="font-size:12px;color:#94a3b8;margin-bottom:6px;">原图</div>
                        <img src="${preview.querySelector('img')?.src || ''}" alt="原图">
                    </div>
                    <div>
                        <div style="font-size:12px;color:#94a3b8;margin-bottom:6px;">压缩后</div>
                        <img src="${escapeHtml(img.public_url)}" alt="压缩后">
                    </div>
                </div>
                <details style="margin-top:14px;font-size:12px;color:#64748b;">
                    <summary style="cursor:pointer;">📋 原始响应 JSON</summary>
                    <pre style="background:#1e293b;color:#cbd5e1;padding:10px;border-radius:6px;overflow:auto;max-height:300px;font-size:11px;">${escapeHtml(JSON.stringify(data, null, 2))}</pre>
                </details>
            `;
        } catch (err) {
            resultBox.className = 'api-debug-result error';
            resultBox.style.display = '';
            resultBox.innerHTML = '<h3>❌ 网络错误：' + escapeHtml(err.message) + '</h3>';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = origText;
        }
    });
})();
</script>

<!-- 🚨 API 安全提示 -->
<div class="security-banner">
    <div class="security-banner-icon">⚠️</div>
    <div class="security-banner-body">
        <strong>API 接口安全提示</strong>
        <ul>
            <li><strong>API endpoint 是永久地址</strong>：<code class="endpoint-code"><?= htmlspecialchars(base_url('api/v1/upload')) ?></code> 请勿对外公开</li>
            <li><strong>Access Key + Secret Key 等同于账号密码</strong>，泄露 = 别人可上传/消耗你的存储</li>
            <li><strong>建议</strong>：每个调用方建独立 Key，泄露可单独撤销不影响其他</li>
            <li><strong>定期轮换</strong>：怀疑泄露立即删除旧 Key 并重建</li>
        </ul>
    </div>
</div>

<!-- 🎨 压缩档位优先级说明 -->
<div class="security-banner" style="background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);border:1px solid #bfdbfe;border-left:4px solid #3b82f6;">
    <div class="security-banner-icon">🎯</div>
    <div class="security-banner-body">
        <strong>压缩档位优先级（API 上传时）</strong>
        <ol style="margin:6px 0 0 18px;padding:0;font-size:13px;line-height:1.8;color:#1e3a8a;">
            <li><strong>API 调用参数</strong> <code style="background:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">compression=extreme</code> （最高，可临时覆盖）</li>
            <li><strong>本 Key 绑定的预设</strong> 创建 Key 时选的压缩档（固定）</li>
            <li><strong>后台"压缩配置 → API 默认档"</strong>（fallback）</li>
        </ol>
        <div style="margin-top:6px;font-size:12px;color:#1e40af;">
            💡 例：本 Key 绑定 <code style="background:#fff;padding:1px 5px;border-radius:3px;">small</code>，但调用时传 <code style="background:#fff;padding:1px 5px;border-radius:3px;">compression=extreme</code>，则本次上传按 <strong>extreme</strong> 压缩
        </div>
    </div>
</div>

<?php
$apiBaseUrl = rtrim(base_url(), '/');
// 过滤 __debug__ 专用 Key（不计入用户可见统计）
$visibleKeys = array_filter($keys, fn($k) => ($k['name'] ?? '') !== '__debug__');
$totalKeys = count($visibleKeys);
$activeCount = count(array_filter($visibleKeys, fn($k) => (int)$k['status'] === 1));
?>

<?php if (!empty($_SESSION['new_api_key_secret'])): $showSecret = $_SESSION['new_api_key_secret']; $showName = $_SESSION['new_api_key_name'] ?? ''; $showAccessKey = $_SESSION['new_api_key_access'] ?? ''; unset($_SESSION['new_api_key_secret'], $_SESSION['new_api_key_name'], $_SESSION['new_api_key_id'], $_SESSION['new_api_key_access']); ?>
<div class="alert-secret">
    <div class="alert-secret-head">
        <span class="alert-secret-icon">🎉</span>
        <strong>密钥创建成功</strong>
        <span class="alert-secret-tag">仅显示一次</span>
    </div>
    <div class="alert-secret-body">
        <div class="secret-row">
            <span class="secret-label">名称</span>
            <span class="secret-value"><?= htmlspecialchars($showName) ?></span>
        </div>
        <div class="secret-row">
            <span class="secret-label">Access Key</span>
            <code class="secret-code" id="new-ak"><?= htmlspecialchars($showAccessKey) ?></code>
            <button type="button" class="btn-mini" data-copy-el="new-ak">复制</button>
        </div>
        <div class="secret-row">
            <span class="secret-label">Secret Key</span>
            <code class="secret-code" id="new-sk"><?= htmlspecialchars($showSecret) ?></code>
            <button type="button" class="btn-mini" data-copy-el="new-sk">复制</button>
        </div>
        <div class="alert-secret-foot">
            ⏰ 关闭后无法再次查看，请立即复制保存！
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 顶部状态卡片 -->
<div class="key-stats">
    <div class="key-stat">
        <div class="key-stat-num"><?= $totalKeys ?></div>
        <div class="key-stat-label">总密钥</div>
    </div>
    <div class="key-stat key-stat-active">
        <div class="key-stat-num"><?= $activeCount ?></div>
        <div class="key-stat-label">已启用</div>
    </div>
    <div class="key-stat key-stat-disabled">
        <div class="key-stat-num"><?= $totalKeys - $activeCount ?></div>
        <div class="key-stat-label">已禁用</div>
    </div>
</div>

<!-- 创建新密钥卡片 -->
<div class="card-modern">
    <div class="card-modern-head">
        <span class="card-modern-icon">➕</span>
        <h3>创建新密钥</h3>
    </div>
    <form method="POST" action="<?= base_url('api-keys') ?>" class="create-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-row">
            <div class="form-cell form-cell-grow">
                <label>名称 <span class="form-hint">（用途说明，方便管理）</span></label>
                <input type="text" name="name" required maxlength="64" placeholder="例如：PicGo 笔记本 / 帝国CMS 站点 / AutoShop 商城">
            </div>
            <div class="form-cell">
                <label>压缩预设</label>
                <select name="compression_profile_id">
                    <option value="">跟随系统默认</option>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-cell">
                <label>过期时间 <span class="form-hint">（可选）</span></label>
                <input type="date" name="expires_at">
            </div>
            <div class="form-cell form-cell-action">
                <button type="submit" class="btn-primary">创建密钥</button>
            </div>
        </div>
    </form>
</div>

<!-- 密钥列表 -->
<div class="card-modern">
    <div class="card-modern-head">
        <span class="card-modern-icon">📋</span>
        <h3>已创建密钥</h3>
        <span class="head-tag"><?= $totalKeys ?> 个</span>
    </div>

    <?php if (empty($keys)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔑</div>
            <h3>还没有密钥</h3>
            <p>创建第一个密钥给第三方程序使用（PicGo、ShareX、帝国CMS 等）</p>
        </div>
    <?php else: ?>
        <div class="key-list">
            <?php foreach ($keys as $k): if (($k['name'] ?? '') === '__debug__') continue; $ak = htmlspecialchars($k['access_key']); $kid = (int)$k['id']; ?>
                <div class="key-card" data-key-id="<?= $kid ?>">
                    <!-- 头部：状态指示条 -->
                    <div class="key-card-strip <?= $k['status'] ? 'is-active' : 'is-disabled' ?>"></div>

                    <div class="key-card-body">
                        <!-- 第一行：名称 + 状态 + 操作按钮 -->
                        <div class="key-row-main">
                            <div class="key-name-wrap">
                                <input type="text" class="key-name-input" value="<?= htmlspecialchars($k['name']) ?>" maxlength="64" placeholder="密钥名称">
                                <span class="key-status <?= $k['status'] ? 'key-status-on' : 'key-status-off' ?>">
                                    <span class="key-status-dot"></span>
                                    <?= $k['status'] ? '已启用' : '已禁用' ?>
                                </span>
                            </div>
                            <div class="key-actions">
                                <button type="button" class="btn-icon btn-toggle" data-id="<?= $kid ?>" data-action="<?= $k['status'] ? 'revoke' : 'activate' ?>" title="<?= $k['status'] ? '禁用' : '启用' ?>">
                                    <span class="btn-icon-glyph"><?= $k['status'] ? '⏸' : '▶' ?></span>
                                </button>
                                <button type="button" class="btn-icon btn-delete" data-id="<?= $kid ?>" title="删除">
                                    <span class="btn-icon-glyph">🗑</span>
                                </button>
                            </div>
                        </div>

                        <!-- 第二行：Access Key + 配置 -->
                        <div class="key-row-config">
                            <div class="key-meta">
                                <span class="meta-label">Access Key</span>
                                <code class="meta-code"><?= $ak ?></code>
                                <button type="button" class="btn-mini" data-copy="<?= $ak ?>">复制</button>
                            </div>
                            <div class="key-meta">
                                <span class="meta-label">压缩</span>
                                <select class="key-profile-select" data-id="<?= $kid ?>">
                                    <option value="0">系统默认</option>
                                    <?php
                                        $currentProfileId = (int)$k['compression_profile_id'];
                                        $matched = false;
                                        foreach ($profiles as $p):
                                            $isMatch = $currentProfileId === (int)$p['id'];
                                            if ($isMatch) $matched = true;
                                    ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= $isMatch ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if ($currentProfileId > 0 && !$matched): ?>
                                        <option value="<?= $currentProfileId ?>" selected disabled>
                                            ⚠️ 预设 #<?= $currentProfileId ?> 已禁用
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="key-meta">
                                <span class="meta-label">最后使用</span>
                                <span class="meta-text"><?= $k['last_used_at'] ? htmlspecialchars($k['last_used_at']) : '<em>从未</em>' ?></span>
                            </div>
                            <div class="key-meta">
                                <span class="meta-label">过期</span>
                                <span class="meta-text <?= $k['expires_at'] ? 'meta-warn' : '' ?>"><?= $k['expires_at'] ? htmlspecialchars($k['expires_at']) : '永不过期' ?></span>
                            </div>
                        </div>

                        <!-- 第三行：endpoint + 折叠调用方式 -->
                        <div class="key-endpoint">
                            <span class="endpoint-label">📌 接口地址</span>
                            <code class="endpoint-url"><?= htmlspecialchars($apiBaseUrl) ?>/api/v1/upload</code>
                            <button type="button" class="btn-mini" data-copy="<?= htmlspecialchars($apiBaseUrl) ?>/api/v1/upload">复制</button>
                            <button type="button" class="btn-detail-toggle" data-detail-id="detail-<?= $kid ?>">
                                <span class="detail-toggle-text">展开调用方式</span>
                                <span class="detail-toggle-arrow">▾</span>
                            </button>
                        </div>

                        <div class="key-detail" id="detail-<?= $kid ?>" style="display:none;">
                            <!-- cURL -->
                            <div class="detail-block detail-curl">
                                <div class="detail-head">
                                    <span class="detail-title">📦 cURL</span>
                                    <span class="detail-tag">通用</span>
                                    <button type="button" class="btn-mini" data-copy-target="curl-<?= $kid ?>">复制</button>
                                </div>
                                <pre class="detail-code" id="curl-<?= $kid ?>">curl -X POST \
  -H "Authorization: Bearer <?= $ak ?>:YOUR_SECRET_KEY" \
  -F "file=@/path/to/image.jpg" \
  <?= htmlspecialchars($apiBaseUrl) ?>/api/v1/upload</pre>
                                <div class="detail-note">⚠️ 把 YOUR_SECRET_KEY 替换为创建时显示的密钥</div>
                            </div>

                            <!-- PicGo -->
                            <div class="detail-block detail-picgo">
                                <div class="detail-head">
                                    <span class="detail-title">🖼️ PicGo</span>
                                    <span class="detail-tag">自定义图床</span>
                                    <button type="button" class="btn-mini" data-copy-target="picgo-<?= $kid ?>">复制 JSON</button>
                                </div>
                                <pre class="detail-code" id="picgo-<?= $kid ?>">{
  "api": "<?= htmlspecialchars($apiBaseUrl) ?>/api/v1/upload",
  "method": "POST",
  "headers": {
    "Authorization": "Bearer <?= $ak ?>:YOUR_SECRET_KEY"
  },
  "body": { "file": "binary" }
}</pre>
                                <div class="detail-note">PicGo → 偏好设置 → 自定义图床 → 粘贴上面 JSON</div>
                            </div>

                            <!-- ShareX -->
                            <div class="detail-block detail-sharex">
                                <div class="detail-head">
                                    <span class="detail-title">📸 ShareX</span>
                                    <span class="detail-tag">自定义上传</span>
                                    <button type="button" class="btn-mini" data-copy-target="sharex-<?= $kid ?>">复制</button>
                                </div>
                                <pre class="detail-code" id="sharex-<?= $kid ?>">{
  "requestURL": "<?= htmlspecialchars($apiBaseUrl) ?>/api/v1/upload",
  "headers": {
    "Authorization": "Bearer <?= $ak ?>:YOUR_SECRET_KEY"
  },
  "body": { "file": "binary" }
}</pre>
                            </div>

                            <!-- 帝国CMS -->
                            <div class="detail-block detail-block-future">
                                <div class="detail-head">
                                    <span class="detail-title">🏛️ 帝国CMS</span>
                                    <span class="detail-tag detail-tag-future">规划中</span>
                                </div>
                                <div class="detail-note">帝国编辑器集成开发中，完成后直接在文章编辑页点"图床选图"按钮</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 全局错误提示 toast -->
<div id="toast" class="toast"></div>
