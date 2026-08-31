<div class="album-view-page">
    <!-- 顶部 -->
    <div class="page-header">
        <div>
            <div class="album-crumb">
                <a href="<?= base_url('albums') ?>">📁 相册</a>
                <span class="crumb-sep">/</span>
                <span class="crumb-current"><?= h($folder['name']) ?></span>
            </div>
            <p class="subtitle">共 <?= (int)$list['total'] ?> 张图片<?= !empty($folder['share_token']) ? ' · 🔗 已分享' : '' ?></p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn-primary" id="open-picker-btn" data-folder-id="<?= (int)$folder['id'] ?>">
                ➕ 添加图片
            </button>
            <a href="<?= base_url('albums') ?>" class="btn-link">← 返回列表</a>
        </div>
    </div>

    <?php if (empty($list['items'])): ?>
        <div class="empty-state-card">
            <div class="empty-icon">📷</div>
            <h3>这个相册还是空的</h3>
            <p>点击「➕ 添加图片」从图库挑选，或上传时选择此相册</p>
            <button type="button" class="btn-primary" data-folder-id="<?= (int)$folder['id'] ?>" onclick="document.getElementById('open-picker-btn').click()">
                ➕ 添加图片
            </button>
        </div>
    <?php else: ?>
        <div class="image-grid">
            <?php foreach ($list['items'] as $img): ?>
                <div class="image-card-wrapper">
                    <a href="<?= base_url('images/' . $img['id']) ?>" class="image-card">
                        <img src="<?= h($img['public_url']) ?>" alt="<?= h($img['original_name']) ?>" loading="lazy">
                        <div class="image-card-info">
                            <div class="image-card-name"><?= h($img['original_name']) ?></div>
                            <div class="image-card-meta">
                                <span><?= number_format($img['final_size'] / 1024, 1) ?> KB</span>
                                <span><?= (int)$img['width'] ?>×<?= (int)$img['height'] ?></span>
                            </div>
                        </div>
                    </a>
                    <form method="POST" action="<?= base_url('albums/' . $folder['id'] . '/remove/' . $img['id']) ?>" style="display:inline;" class="remove-image-form" data-name="<?= h($img['original_name']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <button type="submit" class="card-action" title="移出相册（图片保留在图片库）">↩ 移出</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($list['total_pages'] > 1): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= $list['total_pages']; $p++): ?>
                <a href="?page=<?= $p ?>" class="<?= $p === (int)$list['page'] ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- 添加图片 picker 弹层 -->
<div id="picker-modal" class="modal-mask" style="display:none;">
    <div class="modal modal-picker">
        <div class="picker-header">
            <h3>➕ 添加图片到「<?= h($folder['name']) ?>」</h3>
            <button type="button" class="modal-close" data-close>✕</button>
        </div>

        <!-- 目录面包屑 -->
        <div class="picker-breadcrumb" id="picker-breadcrumb">
            <a href="#" data-path="">📁 全部</a>
            <span class="crumb-sep" id="picker-crumb-sep" style="display:none;">/</span>
            <span id="picker-current-path"></span>
        </div>

        <!-- 子目录 -->
        <div id="picker-subdirs" class="picker-subdirs"></div>

        <!-- 图片网格（多选） -->
        <div id="picker-grid" class="picker-grid">
            <div class="picker-loading">加载中…</div>
        </div>

        <!-- 翻页 -->
        <div id="picker-pagination" class="picker-pagination"></div>

        <!-- 底部操作栏 -->
        <div class="picker-footer">
            <div class="picker-stats">
                <label class="picker-select-all">
                    <input type="checkbox" id="picker-select-all"> 全选当前页
                </label>
                <span id="picker-selected-count">已选 0 张</span>
            </div>
            <div class="picker-actions">
                <button type="button" class="btn-link" data-close>取消</button>
                <button type="button" class="btn-primary" id="picker-submit-btn" disabled>添加到相册</button>
            </div>
        </div>
    </div>
</div>

<!-- 真实提交表单（动态建，避免 picker 中图片被吞进 fd） -->
<form method="POST" action="<?= base_url('albums/' . $folder['id'] . '/add-images') ?>" id="picker-submit-form" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="folder_id" value="<?= (int)$folder['id'] ?>">
    <div id="picker-hidden-ids"></div>
</form>
<script>


(function () {
    const folderId = <?= (int)$folder['id'] ?>;
    const csrfToken = <?= json_encode($csrf) ?>;
    const modal = document.getElementById('picker-modal');
    const openBtns = [document.getElementById('open-picker-btn')].concat(
        Array.from(document.querySelectorAll('[data-folder-id]'))
            .filter(b => b.id !== 'open-picker-btn' && !b.hasAttribute('data-share-btn'))
    );
    const grid = document.getElementById('picker-grid');
    const subDirsEl = document.getElementById('picker-subdirs');
    const crumbEl = document.getElementById('picker-current-path');
    const crumbSep = document.getElementById('picker-crumb-sep');
    const paginationEl = document.getElementById('picker-pagination');
    const selectAllBox = document.getElementById('picker-select-all');
    const countEl = document.getElementById('picker-selected-count');
    const submitBtn = document.getElementById('picker-submit-btn');

    let currentPath = '';
    let currentPage = 1;
    let selectedIds = new Set();

    function openPicker() {
        modal.style.display = 'flex';
        selectedIds.clear();
        updateCount();
        loadDir(currentPath, 1);
    }
    function closePicker() {
        modal.style.display = 'none';
    }
    openBtns.forEach(b => b && b.addEventListener('click', openPicker));
    document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closePicker));
    modal.addEventListener('click', e => { if (e.target === modal) closePicker(); });

    async function loadDir(path, page) {
        currentPath = path; currentPage = page;
        crumbEl.textContent = path || '';
        crumbSep.style.display = path ? '' : 'none';
        grid.innerHTML = '<div class="picker-loading">加载中…</div>';
        subDirsEl.innerHTML = '';
        paginationEl.innerHTML = '';

        // 防御性拼接：FREEIMG_BASE 可能带/不带尾部斜杠，统一归一化后补一个 '/'
        const base = (window.FREEIMG_BASE || '').replace(/\/+$/, '');
        const url = base + '/albums/picker?folder_id=' + folderId + '&path=' + encodeURIComponent(path) + '&page=' + page;
        try {
            const res = await fetch(url, {
                credentials: 'same-origin',
                mode: 'same-origin',
                cache: 'no-store',
                redirect: 'follow',
                headers: { 'Accept': 'application/json' },
            });
            // 网络层错（如 CORS、DNS、超时）走到这里
            const data = await res.json().catch(parseErr => {
                grid.innerHTML = '<div class="picker-empty">❌ 响应不是 JSON（HTTP ' + res.status + ' ' + res.statusText + '）。可能：<br>1. 登录态过期，刷新页重试<br>2. 反代/防火墙拦截<br>3. 服务端错误<br><br>URL: ' + url + '</div>';
                return null;
            });
            if (!data) return;
            if (!data.success) {
                grid.innerHTML = '<div class="picker-empty">' + (data.message || '加载失败') + '</div>';
                return;
            }

            // 子目录
            if (data.sub_dirs && data.sub_dirs.length) {
                subDirsEl.innerHTML = data.sub_dirs.map(d =>
                    `<a href="#" class="subdir-chip" data-path="${d.path}">📂 ${d.path}</a>`
                ).join('');
                subDirsEl.querySelectorAll('.subdir-chip').forEach(a => {
                    a.addEventListener('click', e => {
                        e.preventDefault();
                        loadDir(a.dataset.path, 1);
                    });
                });
            }

            // 图片网格
            if (!data.images.length) {
                grid.innerHTML = '<div class="picker-empty">📭 此目录下没有图片</div>';
            } else {
                grid.innerHTML = data.images.map(img => {
                    const checked = selectedIds.has(img.id) ? 'checked' : '';
                    const sel = selectedIds.has(img.id) ? 'selected' : '';
                    return `
                    <label class="picker-card ${sel}" data-id="${img.id}">
                        <input type="checkbox" class="picker-checkbox" data-id="${img.id}" ${checked}>
                        <img src="${img.public_url}" alt="${escapeHtml(img.original_name)}" loading="lazy">
                        <div class="picker-card-name" title="${escapeHtml(img.original_name)}">${escapeHtml(img.original_name)}</div>
                    </label>`;
                }).join('');
                grid.querySelectorAll('.picker-checkbox').forEach(cb => {
                    cb.addEventListener('change', () => {
                        const id = parseInt(cb.dataset.id, 10);
                        if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
                        cb.closest('.picker-card').classList.toggle('selected', cb.checked);
                        updateCount();
                        updateSelectAll();
                    });
                });
            }

            // 翻页
            if (data.total_pages > 1) {
                const pages = [];
                for (let p = 1; p <= data.total_pages; p++) {
                    pages.push(`<a href="#" class="${p === data.page ? 'active' : ''}" data-page="${p}">${p}</a>`);
                }
                paginationEl.innerHTML = pages.join('');
                paginationEl.querySelectorAll('a').forEach(a => {
                    a.addEventListener('click', e => {
                        e.preventDefault();
                        loadDir(currentPath, parseInt(a.dataset.page, 10));
                    });
                });
            }
        } catch (err) {
            grid.innerHTML = '<div class="picker-empty">❌ 加载失败：' + escapeHtml(err.message) + '</div>';
        }
    }

    function updateCount() {
        countEl.textContent = '已选 ' + selectedIds.size + ' 张';
        submitBtn.disabled = selectedIds.size === 0;
    }
    function updateSelectAll() {
        const boxes = grid.querySelectorAll('.picker-checkbox');
        selectAllBox.checked = boxes.length > 0 && Array.from(boxes).every(b => b.checked);
    }
    selectAllBox.addEventListener('change', () => {
        const checked = selectAllBox.checked;
        grid.querySelectorAll('.picker-card').forEach(card => {
            const cb = card.querySelector('.picker-checkbox');
            const id = parseInt(cb.dataset.id, 10);
            cb.checked = checked;
            card.classList.toggle('selected', checked);
            if (checked) selectedIds.add(id); else selectedIds.delete(id);
        });
        updateCount();
    });

    // 提交
    submitBtn.addEventListener('click', () => {
        if (!selectedIds.size) return;
        if (!confirm('确认添加 ' + selectedIds.size + ' 张图片到相册？')) return;
        const form = document.getElementById('picker-submit-form');
        const container = document.getElementById('picker-hidden-ids');
        container.innerHTML = '';
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'image_ids[]';
            input.value = id;
            container.appendChild(input);
        });
        form.submit();
    });

    // 面包屑
    document.querySelector('#picker-breadcrumb a').addEventListener('click', e => {
        e.preventDefault();
        loadDir('', 1);
    });

    // 移出确认
    document.querySelectorAll('.remove-image-form').forEach(f => {
        f.addEventListener('submit', e => {
            if (!confirm('移出「' + f.getAttribute('data-name') + '」？图片本身保留在图库。')) {
                e.preventDefault();
            }
        });
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
})();
</script>
