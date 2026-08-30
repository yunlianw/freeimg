<?php
/** @var string $content */
$user = \App\Services\AuthService::user();
$initial = mb_strtoupper(mb_substr($user['username'] ?? '?', 0, 1));
$currentPath = $_SERVER['REQUEST_URI'] ?? '/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($title ?? '控制台') . ' - ' . (config('settings.site_name') ?: 'FreeImg')) ?></title>
<link rel="stylesheet" href="/assets/style.css?v=1787966089">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a href="<?= base_url('dashboard') ?>" class="brand">
            <div class="brand-icon">🖼️</div>
            FreeImg
        </a>
        <nav class="topnav">
            <a href="<?= base_url('dashboard') ?>" class="<?= str_contains($currentPath, 'dashboard') ? 'active' : '' ?>">控制台</a>
            <a href="<?= base_url('upload') ?>" class="<?= str_contains($currentPath, 'upload') ? 'active' : '' ?>">上传</a>
            <a href="<?= base_url('images') ?>" class="<?= str_contains($currentPath, 'images') && !str_contains($currentPath, 'albums') ? 'active' : '' ?>">图片</a>
            <a href="<?= base_url('albums') ?>" class="<?= str_contains($currentPath, 'albums') ? 'active' : '' ?>">📁 相册</a>
            <a href="<?= base_url('storages') ?>" class="<?= str_contains($currentPath, 'storages') ? 'active' : '' ?>">💾 存储</a>
            <a href="<?= base_url('compression') ?>" class="<?= str_contains($currentPath, 'compression') ? 'active' : '' ?>">⚙️ 压缩</a>
            <a href="<?= base_url('settings') ?>" class="<?= str_contains($currentPath, 'settings') ? 'active' : '' ?>">设置</a>
            <a href="<?= base_url('api-keys') ?>" class="<?= str_contains($currentPath, 'api-keys') ? 'active' : '' ?>">🔑 API</a>
            <a href="<?= base_url('security') ?>" class="<?= str_contains($currentPath, 'security') ? 'active' : '' ?>">🔐 安全</a>
        </nav>
        <div class="user-menu">
            <div class="user-avatar"><?= htmlspecialchars($initial) ?></div>
            <span class="user-name"><?= htmlspecialchars($user['username'] ?? '') ?></span>
            <form method="POST" action="<?= base_url('logout') ?>" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" class="btn-link">退出</button>
            </form>
        </div>
    </div>
</header>
<main class="container-app">
    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-error">⚠ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
<script>
window.FREEIMG_BASE = "<?= htmlspecialchars(rtrim(base_url(), '/')) ?>";
</script>
</body>
</html>