<link rel="stylesheet" href="<?= htmlspecialchars(base_url('assets/api-keys.css')) ?>?v=20260829">
<script>window.FREEIMG_CSRF = "<?= htmlspecialchars($csrf) ?>";window.FREEIMG_BASE = "<?= htmlspecialchars(rtrim(base_url(), '/')) ?>";</script>
<script src="<?= htmlspecialchars(base_url('assets/api-keys.js')) ?>?v=20260829" defer></script>

<div class="page-header">
    <div>
        <h1>🔑 API 密钥</h1>
        <p class="subtitle">为每个调用方创建独立密钥（PicGo / ShareX / 帝国CMS / AutoShop…）</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="<?= base_url('settings') ?>" class="btn-ghost">⚙️ 设置</a>
    </div>
</div>

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

<?php
$apiBaseUrl = rtrim(base_url(), '/');
$totalKeys = count($keys);
$activeCount = count(array_filter($keys, fn($k) => (int)$k['status'] === 1));
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
            <?php foreach ($keys as $k): $ak = htmlspecialchars($k['access_key']); $kid = (int)$k['id']; ?>
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
                            <div class="detail-block">
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
                            <div class="detail-block">
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
                            <div class="detail-block">
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
