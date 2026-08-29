<div class="page-header">
    <div>
        <h1>控制台</h1>
        <p class="subtitle">欢迎回来，<?= htmlspecialchars(\App\Services\AuthService::user()['username'] ?? '') ?></p>
    </div>
    <div>
        <a href="<?= base_url('upload') ?>" class="btn-primary">📤 立即上传</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">🖼️ 活跃图片</div>
        <div class="stat-num"><?= number_format((int)$stats['images']) ?></div>
        <div class="stat-meta">张</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">📁 文件夹</div>
        <div class="stat-num"><?= number_format((int)$stats['folders']) ?></div>
        <div class="stat-meta">个</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">💾 占用空间</div>
        <div class="stat-num"><?= number_format(($stats['size'] ?? 0) / 1024 / 1024, 2) ?> <span style="font-size:13px; color:var(--gray-500); font-weight:500;">MB</span></div>
        <div class="stat-meta">已使用</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">🗑️ 回收站</div>
        <div class="stat-num"><?= number_format((int)$stats['recycle']) ?></div>
        <div class="stat-meta">个待清理</div>
    </div>
</div>

<div class="action-grid">
    <a href="<?= base_url('upload') ?>" class="action-card">
        <div class="action-icon">📤</div>
        <div>
            <div class="action-name">上传图片</div>
            <div class="action-desc">支持拖拽、粘贴、批量</div>
        </div>
    </a>
    <a href="<?= base_url('images') ?>" class="action-card">
        <div class="action-icon">🗂️</div>
        <div>
            <div class="action-name">我的图片</div>
            <div class="action-desc">查看和管理所有图片</div>
        </div>
    </a>
    <a href="<?= base_url('images?status=recycle') ?>" class="action-card">
        <div class="action-icon">🗑️</div>
        <div>
            <div class="action-name">回收站</div>
            <div class="action-desc">恢复或永久删除</div>
        </div>
    </a>
    <a href="<?= base_url('settings') ?>" class="action-card">
        <div class="action-icon">⚙️</div>
        <div>
            <div class="action-name">系统设置</div>
            <div class="action-desc">配置站点、上传、存储</div>
        </div>
    </a>
</div>

<div class="card">
    <div class="card-title">
        <span>最近上传</span>
        <?php if (!empty($recent)): ?>
            <a href="<?= base_url('images') ?>" class="btn-link">查看全部 →</a>
        <?php endif; ?>
    </div>

    <?php if (empty($recent)): ?>
        <div class="empty-state" style="border:none; padding:40px 20px;">
            <div class="empty-icon">🌅</div>
            <h3>还没有图片</h3>
            <p>上传你的第一张图片，开始使用</p>
            <a href="<?= base_url('upload') ?>" class="btn-primary">立即上传</a>
        </div>
    <?php else: ?>
        <div class="image-grid">
            <?php foreach ($recent as $img): ?>
                <div class="image-card-wrapper">
                    <a href="<?= base_url('images/' . $img['id']) ?>" class="image-card">
                        <img src="<?= htmlspecialchars($img['public_url']) ?>" alt="<?= htmlspecialchars($img['original_name']) ?>" loading="lazy">
                        <div class="image-card-info">
                            <div class="image-card-name"><?= htmlspecialchars($img['original_name']) ?></div>
                            <div class="image-card-meta">
                                <span><?= number_format($img['final_size'] / 1024, 1) ?> KB</span>
                                <span class="saved">↓ <?= round((1 - $img['compression_ratio']) * 100, 0) ?>%</span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>