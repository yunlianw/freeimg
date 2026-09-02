<?php
/** @var string $token */
/** @var string|null $error */
$title = '请输入访问密码';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🔒 受保护的相册</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, "PingFang SC", "Noto Sans SC", "Microsoft YaHei", sans-serif; background: #f4f5f7; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .box { background: #fff; border-radius: 16px; padding: 40px 32px; width: 360px; max-width: 90vw; text-align: center; box-shadow: 0 8px 30px rgba(0,0,0,.08); }
    .icon { font-size: 44px; margin-bottom: 12px; }
    h1 { font-size: 18px; margin-bottom: 6px; }
    p { color: #6b7280; font-size: 13px; margin-bottom: 20px; }
    input { width: 100%; padding: 11px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; margin-bottom: 12px; outline: none; }
    input:focus { border-color: #2563eb; }
    button { width: 100%; padding: 11px; border: none; border-radius: 10px; background: #2563eb; color: #fff; font-size: 14px; cursor: pointer; }
    button:hover { background: #1d4ed8; }
    .error { color: #dc2626; font-size: 13px; margin-bottom: 10px; }
</style>
</head>
<body>
<div class="box">
    <div class="icon">🔒</div>
    <h1>此相册受密码保护</h1>
    <p>请输入访问密码以查看内容</p>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="POST" action="<?= h(share_url('s/' . $token)) ?>">
        <input type="password" name="password" placeholder="访问密码" autofocus required>
        <button type="submit">进入相册</button>
    </form>
</div>
</body>
</html>
