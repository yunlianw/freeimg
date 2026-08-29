<?php
/** @var array $image */
$title = $image['original_name'];
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$shareUrl = 'https://' . $host . '/s/img/' . urlencode($image['uuid']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($image['original_name']) ?> - FreeImg</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, "PingFang SC", "Noto Sans SC", "Microsoft YaHei", sans-serif; background: #111827; color: #f9fafb; min-height: 100vh; display: flex; flex-direction: column; }
    .wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .stage { max-width: 100%; text-align: center; }
    .stage img { max-width: 100%; max-height: 78vh; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.5); background: #1f2937; }
    .info { padding: 18px 20px; display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap; font-size: 13px; color: #9ca3af; }
    .info .name { color: #f9fafb; max-width: 360px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .info .dims { color: #6b7280; }
    .copy-row { display: flex; gap: 8px; }
    .copy-row input { padding: 7px 12px; border-radius: 8px; border: 1px solid #374151; background: #1f2937; color: #f9fafb; font-size: 12px; width: 260px; max-width: 40vw; outline: none; }
    .copy-row button { padding: 7px 14px; border-radius: 8px; border: none; background: #2563eb; color: #fff; cursor: pointer; font-size: 12px; }
    .copy-row button:hover { background: #1d4ed8; }
    .back { position: fixed; top: 16px; left: 16px; color: #9ca3af; text-decoration: none; font-size: 13px; background: rgba(17,24,39,.8); padding: 8px 14px; border-radius: 20px; border: 1px solid #374151; }
    .back:hover { color: #fff; }
</style>
</head>
<body>
<a class="back" href="javascript:history.back()">← 返回</a>
<div class="wrap">
    <div class="stage">
        <img src="<?= h($image['public_url']) ?>" alt="<?= h($image['original_name']) ?>">
        <div class="info">
            <span class="name"><?= h($image['original_name']) ?></span>
            <span class="dims"><?= $image['width'] ?>×<?= $image['height'] ?> · <?= number_format($image['final_size'] / 1024, 1) ?> KB</span>
            <div class="copy-row">
                <input type="text" readonly value="<?= h($image['public_url']) ?>" id="img-url">
                <button type="button" onclick="copyUrl()">复制直链</button>
            </div>
        </div>
    </div>
</div>
<script>
function copyUrl() {
    var el = document.getElementById('img-url');
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
