<div class="albums-page">
    <div class="page-header">
        <div>
            <h1>📁 相册</h1>
            <p class="subtitle">共 <?= count($folders) ?> 个相册 · 图片通过「上传选择 / 添加图片」归入相册</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn-primary" id="open-create-btn">➕ 新建相册</button>
            <a href="<?= base_url('upload') ?>" class="btn-secondary">📤 上传</a>
        </div>
    </div>

    <!-- 创建相册（可折叠） -->
    <div class="card create-album-card" id="create-album-card" style="display:none;">
        <form method="POST" action="<?= base_url('albums/create') ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="form-group">
                <label>相册名称</label>
                <input type="text" name="name" placeholder="例如：旅行 / 产品图 / 头像" maxlength="128" required autofocus>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-link" id="cancel-create-btn">取消</button>
                <button type="submit" class="btn-primary">创建</button>
            </div>
        </form>
    </div>

    <?php if (empty($folders)): ?>
        <div class="empty-state-card">
            <div class="empty-icon">📁</div>
            <h3>还没有相册</h3>
            <p>创建一个相册，把图片归类管理 · 一键分享给朋友</p>
            <button type="button" class="btn-primary" id="empty-create-btn">➕ 创建第一个相册</button>
        </div>
    <?php else: ?>
        <div class="album-grid">
            <?php foreach ($folders as $f): ?>
                <div class="album-card card">
                    <a href="<?= base_url('albums/' . $f['id']) ?>" class="album-cover">
                        <?php if (!empty($f['cover_url'])): ?>
                            <img src="<?= h($f['cover_url']) ?>" alt="<?= h($f['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="album-cover-empty">
                                <span class="empty-folder-icon">📁</span>
                                <span class="empty-folder-text">暂无图片</span>
                            </div>
                        <?php endif; ?>
                        <div class="album-cover-overlay">
                            <span class="cover-count"><?= (int)$f['image_count'] ?> 张</span>
                        </div>
                    </a>
                    <div class="album-body">
                        <div class="album-name" title="<?= h($f['name']) ?>"><?= h($f['name']) ?></div>
                        <div class="album-meta">
                            <?php if (!empty($f['share_token'])): ?>
                                <span class="album-share-badge">
                                    🔗 已分享<?= !empty($f['share_expires_at']) ? ' · ' . date('m-d', strtotime($f['share_expires_at'])) . ' 过期' : '' ?>
                                    <?= !empty($f['share_password']) ? ' · 🔒' : '' ?>
                                </span>
                            <?php else: ?>
                                <span class="album-meta-private">🔒 私密</span>
                            <?php endif; ?>
                        </div>
                        <div class="album-actions">
                            <a href="<?= base_url('albums/' . $f['id']) ?>" class="btn-mini">📂 打开</a>
                            <button type="button" class="btn-mini share-btn" data-id="<?= (int)$f['id'] ?>" data-name="<?= h($f['name']) ?>" data-has-share="<?= !empty($f['share_token']) ? '1' : '0' ?>">
                                🔗 <?= !empty($f['share_token']) ? '分享' : '分享' ?>
                            </button>
                            <button type="button" class="btn-mini rename-btn" data-id="<?= (int)$f['id'] ?>" data-name="<?= h($f['name']) ?>">✏️ 重命名</button>
                            <?php if (!empty($f['share_token'])): ?>
                                <form method="POST" action="<?= base_url('albums/' . $f['id'] . '/unshare') ?>" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <button type="submit" class="btn-mini">🚫 取消分享</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="<?= base_url('albums/' . $f['id'] . '/delete') ?>" style="display:inline;" class="delete-album-form" data-name="<?= h($f['name']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <button type="submit" class="btn-mini btn-mini-danger">🗑️ 删除</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 分享弹层 -->
<div id="share-modal" class="modal-mask" style="display:none;">
    <div class="modal">
        <h3 style="margin:0 0 16px;">🔗 分享相册</h3>
        <form method="POST" id="share-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="form-group">
                <label>有效期</label>
                <select name="expires">
                    <option value="0">永久有效</option>
                    <option value="1">1 天</option>
                    <option value="7">7 天</option>
                    <option value="30">30 天</option>
                </select>
            </div>
            <div class="form-group">
                <label>访问密码（可选）</label>
                <input type="password" name="password" placeholder="6-64 字符 · 留空保留原密码" maxlength="64">
                <label style="margin-top:8px; display:flex; align-items:center; gap:6px; font-size:13px;">
                    <input type="checkbox" name="clear_password" value="1"> 清除已设置的密码
                </label>
            </div>
            <p style="margin:10px 0 0; font-size:12px; color:#b45309; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:8px;">
                ⚠️ 分享后，相册内所有图片（含未公开）对持链接者可见
            </p>
            <div class="modal-actions">
                <button type="button" class="btn-link" data-close>取消</button>
                <button type="submit" class="btn-primary">生成分享链接</button>
            </div>
        </form>
    </div>
</div>

<!-- 重命名弹层 -->
<div id="rename-modal" class="modal-mask" style="display:none;">
    <div class="modal">
        <h3 style="margin:0 0 16px;">✏️ 重命名相册</h3>
        <form method="POST" id="rename-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="text" name="name" id="rename-input" maxlength="128" required style="width:100%;">
            <div class="modal-actions">
                <button type="button" class="btn-link" data-close>取消</button>
                <button type="submit" class="btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // 创建相册折叠
    const createCard = document.getElementById('create-album-card');
    const openBtn = document.getElementById('open-create-btn');
    const emptyBtn = document.getElementById('empty-create-btn');
    const cancelBtn = document.getElementById('cancel-create-btn');
    if (openBtn) openBtn.addEventListener('click', () => {
        createCard.style.display = '';
        createCard.querySelector('input[name=name]').focus();
    });
    if (emptyBtn) emptyBtn.addEventListener('click', () => {
        createCard.style.display = '';
        createCard.querySelector('input[name=name]').focus();
    });
    if (cancelBtn) cancelBtn.addEventListener('click', () => { createCard.style.display = 'none'; });

    // 删除确认
    document.querySelectorAll('.delete-album-form').forEach(f => {
        f.addEventListener('submit', e => {
            if (!confirm('删除相册「' + f.getAttribute('data-name') + '」？相册内图片会移出保留，不会删除图片。')) {
                e.preventDefault();
            }
        });
    });

    // 分享
    document.querySelectorAll('.share-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const form = document.getElementById('share-form');
            form.action = window.FREEIMG_BASE + 'albums/' + id + '/share';
            document.getElementById('share-modal').style.display = 'flex';
        });
    });

    // 重命名
    document.querySelectorAll('.rename-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const form = document.getElementById('rename-form');
            form.action = window.FREEIMG_BASE + 'albums/' + id + '/rename';
            document.getElementById('rename-input').value = btn.dataset.name;
            document.getElementById('rename-modal').style.display = 'flex';
        });
    });

    // 关闭弹层
    document.querySelectorAll('[data-close]').forEach(b => {
        b.addEventListener('click', e => {
            const modal = e.target.closest('.modal-mask');
            if (modal) modal.style.display = 'none';
        });
    });
    document.querySelectorAll('.modal-mask').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
    });
})();
</script>