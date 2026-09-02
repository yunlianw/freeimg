<?php
/** @var array $folder */
/** @var array $list */
$title = $folder['name'];
// v1.1.9: 用 share_url() 替换硬编码的 HTTP_HOST 拼接
$canonical = share_url('s/' . urlencode($folder['share_token']));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($folder['name']) ?> - 相册分享</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, "PingFang SC", "Noto Sans SC", "Microsoft YaHei", sans-serif; background: #f4f5f7; color: #1f2937; }
    .header { background: #111827; color: #fff; padding: 28px 20px; text-align: center; }
    .header h1 { font-size: 22px; font-weight: 600; margin-bottom: 6px; word-break: break-all; }
    .header p { color: #9ca3af; font-size: 13px; }
    .header .copy-row { margin-top: 14px; display: flex; justify-content: center; gap: 8px; }
    .header input { padding: 8px 12px; border-radius: 8px; border: none; width: 320px; max-width: 70vw; font-size: 13px; color: #111827; }
    .header button { padding: 8px 16px; border-radius: 8px; border: none; background: #2563eb; color: #fff; cursor: pointer; font-size: 13px; }
    .header button:hover { background: #1d4ed8; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; padding: 28px 20px; max-width: 1200px; margin: 0 auto; }
    .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .card a { display: block; }
    .card img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #e5e7eb; }
    .card .meta { padding: 10px 12px; font-size: 12px; color: #6b7280; display: flex; justify-content: space-between; gap: 8px; }
    .card .name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .empty { text-align: center; padding: 80px 20px; color: #9ca3af; }
    .empty .icon { font-size: 52px; margin-bottom: 12px; }
    .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 12px; }
</style>
</head>
<body>
<div class="header">
    <h1>📁 <?= h($folder['name']) ?></h1>
    <p>共 <?= count($list) ?> 张图片<?= !empty($folder['share_expires_at']) ? ' · 分享至 ' . date('Y-m-d', strtotime($folder['share_expires_at'])) . ' 过期' : '' ?><?= !empty($folder['share_password']) ? ' · 🔒 受密码保护' : '' ?></p>
    <div class="copy-row">
        <input type="text" readonly value="<?= h($canonical) ?>" id="share-url">
        <button type="button" onclick="copyUrl()">复制链接</button>
    </div>
</div>

<?php if (empty($list)): ?>
    <div class="empty">
        <div class="icon">🖼️</div>
        <p>这个相册还没有图片</p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($list as $img): ?>
            <div class="card">
                <a href="<?= h(share_url('s/img/' . $img['uuid'])) ?>" target="_blank">
                    <img src="<?= h($img['public_url']) ?>" alt="<?= h($img['original_name']) ?>" loading="lazy">
                </a>
                <div class="meta">
                    <span class="name" title="<?= h($img['original_name']) ?>"><?= h($img['original_name']) ?></span>
                    <span><?= $img['width'] ?>×<?= $img['height'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="footer">图片由 <?= h(brand()) ?> 图床提供</div>
<script>
function copyUrl() {
    var el = document.getElementById('share-url');
    el.select();
    try { document.execCommand('copy'); } catch (e) {}
    var btn = el.nextElementSibling;
    var old = btn.textContent;
    btn.textContent = '已复制 ✓';
    setTimeout(function () { btn.textContent = old; }, 1500);
}
</script>
</body>
</html>
