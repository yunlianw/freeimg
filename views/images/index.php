<div class="page-header">
    <div>
        <h1>
            <?php if ($status === 'recycle'): ?>
                🗑️ 回收站
            <?php else: ?>
                🗂️ 我的图片
            <?php endif; ?>
        </h1>
        <p class="subtitle">共 <?= (int)$list['total'] ?> 张图片</p>
    </div>
    <div style="display:flex; gap:8px;">
        <?php if ($status === 'recycle'): ?>
            <button type="button" id="btn-empty-recycle" class="btn-primary" style="background:var(--red-500);">🗑️ 一键清空回收站</button>
        <?php else: ?>
            <button type="button" id="btn-batch-mode" class="btn-primary" style="background:var(--gray-700);">☑️ 批量删除</button>
            <a href="<?= base_url('upload') ?>" class="btn-primary">📤 上传</a>
        <?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <form method="GET" action="<?= base_url('images') ?>" class="search-form">
        <input type="text" name="q" placeholder="🔍 搜索文件名…" value="<?= htmlspecialchars($keyword) ?>">
        <select name="folder">
            <option value="0">📁 全部目录（根）</option>
            <?php foreach ($folders as $f): ?>
                <option value="<?= htmlspecialchars($f['path']) ?>" <?= (string)$f['path'] === (string)$folder ? 'selected' : '' ?>>
                    📁 <?= htmlspecialchars($f['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <button type="submit" class="btn-search">搜索</button>
    </form>

    <div class="toolbar-actions">
        <?php if ($status !== 'recycle'): ?>
            <a href="<?= base_url('images?status=recycle') ?>">🗑️ 回收站</a>
        <?php else: ?>
            <a href="<?= base_url('images?status=active') ?>">📁 活跃图片</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($list['items'])): ?>
    <div class="empty-state">
        <div class="empty-icon"><?= $status === 'recycle' ? '🗑️' : '🖼️' ?></div>
        <h3><?= $status === 'recycle' ? '回收站是空的' : '还没有图片' ?></h3>
        <p><?= $status === 'recycle' ? '删除的图片会出现在这里' : '上传你的第一张图片开始使用' ?></p>
        <?php if ($status !== 'recycle'): ?>
            <a href="<?= base_url('upload') ?>" class="btn-primary">立即上传</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="image-grid">
        <?php foreach ($list['items'] as $img): ?>
            <div class="image-card-wrapper" data-id="<?= (int)$img['id'] ?>">
                <label class="batch-checkbox" style="display:none;">
                    <input type="checkbox" class="batch-select" value="<?= (int)$img['id'] ?>">
                </label>
                <a href="<?= base_url('images/' . $img['id']) ?>" class="image-card">
                    <img src="<?= htmlspecialchars($img['public_url']) ?>" alt="<?= htmlspecialchars($img['original_name']) ?>" loading="lazy">
                    <div class="image-card-info">
                        <div class="image-card-name"><?= htmlspecialchars($img['original_name']) ?></div>
                        <?php
                            // 兼容老数据：storage_path 可能带 'img/' 前缀（历史遗留），显示时去掉
                            $displayPath = (string)$img['storage_path'];
                            if (str_starts_with($displayPath, 'img/')) {
                                $displayPath = substr($displayPath, 4);
                            }
                        ?>
                        <div class="image-card-path" title="物理路径">📁 public/img/<?= htmlspecialchars($displayPath) ?></div>
                        <div class="image-card-meta">
                            <span><?= number_format($img['final_size'] / 1024, 1) ?> KB</span>
                            <span class="saved">↓ <?= round((1 - $img['compression_ratio']) * 100, 0) ?>%</span>
                        </div>
                    </div>
                </a>
                <?php if ($status === 'recycle'): ?>
                    <button class="card-action restore-btn" data-id="<?= (int)$img['id'] ?>">恢复</button>
                    <button class="card-action destroy-btn" data-id="<?= (int)$img['id'] ?>">永久删</button>
                <?php else: ?>
                    <button class="card-action trash-btn" data-id="<?= (int)$img['id'] ?>">删除</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 批量操作浮动栏 -->
    <div id="batch-bar" style="display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:var(--gray-900); color:#fff; padding:14px 20px; border-radius:30px; box-shadow:0 8px 32px rgba(0,0,0,0.3); display:flex; align-items:center; gap:12px; z-index:100;">
        <span id="batch-count">已选 0 张</span>
        <button type="button" id="btn-batch-cancel" style="background:transparent; border:1px solid #fff; color:#fff; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:13px;">取消</button>
        <button type="button" id="btn-batch-confirm" style="background:var(--red-500); border:none; color:#fff; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:13px;">🗑️ 删除选中</button>
    </div>

    <?php if ($list['total_pages'] > 1): ?>
    <div class="pagination">
        <?php for ($p = 1; $p <= $list['total_pages']; $p++): ?>
            <a href="?q=<?= urlencode($keyword) ?>&folder=<?= urlencode($folder) ?>&status=<?= urlencode($status) ?>&page=<?= $p ?>"
               class="<?= $p === (int)$list['page'] ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
window.FREEIMG_CSRF = "<?= htmlspecialchars($csrf) ?>";
window.FREEIMG_BASE = "<?= base_url() ?>";
</script>
<script src="/assets/images.js"></script>