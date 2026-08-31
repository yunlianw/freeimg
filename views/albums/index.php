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
                            <button type="button" class="btn-mini share-btn" data-id="<?= (int)$f['id'] ?>" data-name="<?= h($f['name']) ?>" data-has-share="<?= !empty($f['share_token']) ? '1' : '0' ?>" data-expires-at="<?= h($f['share_expires_at'] ?? '') ?>" data-has-password="<?= !empty($f['share_password']) ? '1' : '0' ?>">
                                🔗 <?= !empty($f['share_token']) ? '管理分享' : '分享' ?>
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
        <h3 style="margin:0 0 16px;">🔗 分享「<span id="share-folder-name"></span>」</h3>
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
                <button type="submit" class="btn-primary" id="share-submit-btn">生成分享链接</button>
            </div>
        </form>
    </div>
</div>

<!-- 分享成功弹层（链接 + 一键复制） -->
<div id="share-success-modal" class="modal-mask" style="display:none;">
    <div class="modal modal-share-success">
        <button type="button" class="modal-close" data-close-share-success>✕</button>
        <div class="share-success-icon">✅</div>
        <h3 style="margin:0 0 6px; text-align:center;">分享成功</h3>
        <p class="share-success-sub" style="margin:0 0 18px; text-align:center; color:#64748b; font-size:14px;">复制链接发给朋友</p>

        <div class="share-link-box">
            <input type="text" id="share-link-input" readonly>
            <button type="button" id="share-copy-btn" class="btn-primary share-copy-btn">
                <span class="share-copy-label">📋 复制</span>
            </button>
        </div>

        <div class="share-meta" id="share-meta"></div>

        <div class="modal-actions" style="margin-top:20px;">
            <a id="share-preview-link" href="#" target="_blank" class="btn-secondary">🌐 打开预览</a>
            <button type="button" class="btn-link" data-close-share-success>关闭</button>
        </div>
    </div>
</div>

<style>
.modal-share, .modal-share-success {
    width: 92%;
    max-width: 480px;
    padding: 24px 20px 20px;
    position: relative;
}
.modal-share-success { padding-top: 28px; }
.modal-share .modal-close, .modal-share-success .modal-close {
    position: absolute;
    top: 12px;
    right: 12px;
    background: none;
    border: none;
    font-size: 22px;
    color: #94a3b8;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    line-height: 1;
}
.modal-share .modal-close:hover, .modal-share-success .modal-close:hover {
    background: #f1f5f9;
    color: #475569;
}
.share-success-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #dcfce7;
    color: #16a34a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    margin: 0 auto 14px;
}
.share-link-box {
    display: flex;
    gap: 8px;
    margin-bottom: 14px;
}
.share-link-box input {
    flex: 1;
    min-width: 0;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    background: #f8fafc;
    color: #334155;
    font-family: ui-monospace, "SF Mono", Consolas, monospace;
    overflow: hidden;
    text-overflow: ellipsis;
}
.share-copy-btn {
    flex-shrink: 0;
    padding: 10px 16px;
    min-width: 86px;
    white-space: nowrap;
}
.share-copy-btn.copied { background: #16a34a !important; }
.share-meta {
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 8px;
    font-size: 13px;
    color: #475569;
    line-height: 1.7;
}
.share-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
@media (max-width: 480px) {
    .modal-share, .modal-share-success { padding: 20px 16px 16px; }
    .share-link-box { flex-direction: column; }
    .share-copy-btn { width: 100%; }
}
</style>

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
    const shareModal = document.getElementById('share-modal');
    const shareSuccessModal = document.getElementById('share-success-modal');
    const shareForm = document.getElementById('share-form');
    const shareSubmitBtn = document.getElementById('share-submit-btn');
    const shareFolderNameEl = document.getElementById('share-folder-name');
    const shareLinkInput = document.getElementById('share-link-input');
    const shareCopyBtn = document.getElementById('share-copy-btn');
    const sharePreviewLink = document.getElementById('share-preview-link');
    const shareMeta = document.getElementById('share-meta');

    document.querySelectorAll('.share-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            shareFolderNameEl.textContent = btn.dataset.name;
            shareForm.action = (window.FREEIMG_BASE || '').replace(/\/+$/, '') + '/albums/' + id + '/share';
            // 预填当前设置（管理分享模式保留原有效期/密码状态）
            const expiresAt = btn.dataset.expiresAt || '';
            const select = shareForm.querySelector('select[name=expires]');
            if (expiresAt) {
                const daysLeft = Math.max(0, Math.ceil((new Date(expiresAt.replace(' ', 'T')).getTime() - Date.now()) / 86400000));
                const match = [1, 7, 30].find(d => daysLeft <= d);
                select.value = match ? String(match) : '0';
            } else {
                select.value = '0';
            }
            shareForm.querySelector('input[name=password]').value = '';
            shareForm.querySelector('input[name=clear_password]').checked = false;
            shareSubmitBtn.disabled = false;
            shareSubmitBtn.textContent = btn.dataset.hasShare === '1' ? '更新分享' : '生成分享链接';
            shareModal.style.display = 'flex';
        });
    });

    // 关闭分享弹层（配置 + 成功）
    document.querySelectorAll('[data-close-share], [data-close-share-success]').forEach(b => {
        b.addEventListener('click', () => {
            shareModal.style.display = 'none';
            shareSuccessModal.style.display = 'none';
            shareCopyBtn.classList.remove('copied');
            shareCopyBtn.querySelector('.share-copy-label').textContent = '📋 复制';
        });
    });
    [shareModal, shareSuccessModal].forEach(m => {
        if (!m) return;
        m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
    });

    // 提交分享（fetch + JSON，不刷新页面）
    shareForm?.addEventListener('submit', async e => {
        e.preventDefault();
        shareSubmitBtn.disabled = true;
        const origLabel = shareSubmitBtn.textContent;
        shareSubmitBtn.textContent = '生成中…';
        try {
            const fd = new FormData(shareForm);
            const res = await fetch(shareForm.action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) {
                if (res.status === 401 || res.status === 403) alert('❌ 会话已过期，请刷新页面');
                else if (res.status >= 500) alert('❌ 服务器错误（HTTP ' + res.status + '），请稍后重试');
                else alert('❌ 请求失败（HTTP ' + res.status + '）');
                return;
            }
            const data = await res.json().catch(() => null);
            if (!data) { alert('❌ 响应格式异常，请刷新页面重试'); return; }
            if (!data.success) { alert('❌ ' + (data.message || '分享失败')); return; }
            // 成功：切到成功弹层
            shareModal.style.display = 'none';
            shareLinkInput.value = data.share_url;
            sharePreviewLink.href = data.share_url;
            const meta = [];
            meta.push('<div class="share-meta-item">📅 有效期：' + (data.expires_at ? new Date(data.expires_at.replace(' ', 'T')).toLocaleString('zh-CN') + ' 过期' : '永久有效') + '</div>');
            meta.push('<div class="share-meta-item">🔒 访问密码：' + (data.has_password ? '已设置（访问者需输入）' : '无') + '</div>');
            shareMeta.innerHTML = meta.join('');
            shareSuccessModal.style.display = 'flex';
            setTimeout(() => { shareLinkInput.select(); }, 100);
        } catch (err) {
            alert('❌ 网络错误：' + err.message);
        } finally {
            shareSubmitBtn.disabled = false;
            shareSubmitBtn.textContent = origLabel;
        }
    });

    // 一键复制（带 fallback）
    shareCopyBtn?.addEventListener('click', async () => {
        const url = shareLinkInput.value;
        if (!url) return;
        const showCopied = () => {
            shareCopyBtn.classList.add('copied');
            shareCopyBtn.querySelector('.share-copy-label').textContent = '✅ 已复制';
            setTimeout(() => {
                shareCopyBtn.classList.remove('copied');
                shareCopyBtn.querySelector('.share-copy-label').textContent = '📋 复制';
            }, 2200);
        };
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(url);
                showCopied();
            } else {
                shareLinkInput.select();
                shareLinkInput.setSelectionRange(0, url.length);
                const ok = document.execCommand('copy');
                if (ok) showCopied();
                else alert('请手动复制：' + url);
            }
        } catch (err) {
            alert('复制失败，请手动复制：' + url);
        }
    });

    // 重命名
    document.querySelectorAll('.rename-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const form = document.getElementById('rename-form');
            // 防御性归一化：FREEIMG_BASE 可能带/不带尾部斜杠
            const base = (window.FREEIMG_BASE || '').replace(/\/+$/, '');
            form.action = base + '/albums/' + id + '/rename';
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