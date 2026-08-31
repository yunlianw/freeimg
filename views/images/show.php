<div class="page-header">
    <div>
        <h1>图片详情</h1>
        <p class="subtitle"><?= htmlspecialchars($image['original_name']) ?></p>
    </div>
    <a href="<?= base_url('images') ?>" class="btn-link">← 返回列表</a>
</div>

<div class="image-detail">
    <div class="image-detail-preview">
        <img src="<?= htmlspecialchars($image['public_url']) ?>" alt="<?= htmlspecialchars($image['original_name']) ?>">
    </div>

    <div class="card">
        <div class="info-row">
            <label>文件名</label>
            <form method="POST" action="<?= base_url('images/' . $image['id'] . '/rename') ?>" style="display:flex; gap:8px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="text" name="name" value="<?= htmlspecialchars($image['original_name']) ?>" style="flex:1;">
                <button type="submit" class="btn-primary" style="padding:8px 18px;">保存</button>
            </form>
        </div>

        <div class="info-row">
            <label>📎 直链 URL</label>
            <div class="copy-row">
                <input type="text" readonly value="<?= htmlspecialchars($image['public_url']) ?>" id="url-input">
                <button type="button" onclick="copyText('url-input')">复制</button>
            </div>
        </div>

        <div class="info-row">
            <label>📝 Markdown</label>
            <div class="copy-row">
                <input type="text" readonly value="![<?= htmlspecialchars($image['original_name']) ?>](<?= htmlspecialchars($image['public_url']) ?>)" id="md-input">
                <button type="button" onclick="copyText('md-input')">复制</button>
            </div>
        </div>

        <div class="info-row">
            <label>🔖 HTML</label>
            <div class="copy-row">
                <input type="text" readonly value='<img src="<?= htmlspecialchars($image['public_url']) ?>" alt="<?= htmlspecialchars($image['original_name']) ?>">' id="html-input">
                <button type="button" onclick="copyText('html-input')">复制</button>
            </div>
        </div>

        <div class="info-row">
            <label>💬 BBCode</label>
            <div class="copy-row">
                <input type="text" readonly value="[img]<?= htmlspecialchars($image['public_url']) ?>[/img]" id="bb-input">
                <button type="button" onclick="copyText('bb-input')">复制</button>
            </div>
        </div>

        <div class="detail-meta">
            <div><strong><?= (int)$image['width'] ?> × <?= (int)$image['height'] ?></strong> 尺寸</div>
            <div>
                <strong><?= number_format($image['final_size'] / 1024, 2) ?> KB</strong>
                （原 <?= number_format($image['original_size'] / 1024, 2) ?> KB
                <?php if (!empty($image['original_mime']) && $image['original_mime'] !== $image['mime_type']): ?>
                    · 检测 MIME: <?= htmlspecialchars($image['original_mime']) ?>
                <?php endif; ?>
                ）
            </div>
            <div>
                <strong style="color:var(--green-500);">↓ <?= round((1 - $image['compression_ratio']) * 100, 1) ?>%</strong>
                节省（<?= $image['compression_ratio'] >= 1.0 ? '未压缩' : '已压缩' ?>）
            </div>
            <div>
                <strong><?= htmlspecialchars($image['mime_type']) ?></strong>
                <?php if (!empty($image['original_extension']) && $image['original_extension'] !== $image['extension']): ?>
                    <span class="detail-tag" style="background:#fef3c7;color:#92400e;">原 .<?= htmlspecialchars($image['original_extension']) ?> → 现 .<?= htmlspecialchars($image['extension']) ?></span>
                <?php endif; ?>
            </div>
            <div>
                🛠 <strong><?= htmlspecialchars($image['compressor'] ?? 'original') ?></strong>
                <?php if (!empty($image['compression_source']) && $image['compression_source'] !== 'none'): ?>
                    · 源 <?= htmlspecialchars($image['compression_source']) ?>
                <?php endif; ?>
            </div>
            <div><strong><?= htmlspecialchars($image['created_at']) ?></strong></div>
        </div>

        <div class="info-row">
            <label>SHA256</label>
            <div style="font-family:monospace; font-size:11px; word-break:break-all; color:var(--gray-500);"><?= htmlspecialchars($image['sha256']) ?></div>
        </div>

        <div style="margin-top:20px; display:flex; gap:8px; flex-wrap:wrap;">
            <?php if ($image['status'] === 'recycle'): ?>
                <button class="btn-success restore-btn" data-id="<?= (int)$image['id'] ?>">♻️ 恢复</button>
                <button class="btn-danger destroy-btn" data-id="<?= (int)$image['id'] ?>">💀 永久删除</button>
            <?php else: ?>
                <button class="btn-danger trash-btn" data-id="<?= (int)$image['id'] ?>">🗑️ 删除到回收站</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.FREEIMG_CSRF = "<?= htmlspecialchars($csrf) ?>";
window.FREEIMG_BASE = "<?= base_url() ?>";
window.FREEIMG_ID = <?= (int)$image['id'] ?>;
</script>
<script src="/assets/images.js?v=<?= @filemtime(FREEIMG_ROOT . '/public/assets/images.js') ?: time() ?>"></script>