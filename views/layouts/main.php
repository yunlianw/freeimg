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
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(FREEIMG_ROOT . '/public/assets/style.css') ?: time() ?>">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <button type="button" class="hamburger" id="hamburger-btn" aria-label="打开菜单" aria-expanded="false" aria-controls="mobile-drawer">
            <span></span><span></span><span></span>
        </button>
        <a href="<?= base_url('dashboard') ?>" class="brand">
            <div class="brand-icon">🖼️</div>
            <span class="brand-text">FreeImg</span>
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
            <form method="POST" action="<?= base_url('logout') ?>" class="user-logout-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" class="btn-link">退出</button>
            </form>
        </div>
    </div>
</header>

<!-- 移动端侧边抽屉 -->
<div class="mobile-drawer-overlay" id="drawer-overlay"></div>
<aside class="mobile-drawer" id="mobile-drawer" aria-hidden="true">
    <div class="drawer-header">
        <div class="drawer-user">
            <div class="user-avatar"><?= htmlspecialchars($initial) ?></div>
            <span><?= htmlspecialchars($user['username'] ?? '') ?></span>
        </div>
        <button type="button" class="drawer-close" id="drawer-close" aria-label="关闭菜单">×</button>
    </div>
    <nav class="drawer-nav">
        <a href="<?= base_url('dashboard') ?>" class="<?= str_contains($currentPath, 'dashboard') ? 'active' : '' ?>">📊 控制台</a>
        <a href="<?= base_url('upload') ?>" class="<?= str_contains($currentPath, 'upload') ? 'active' : '' ?>">📤 上传</a>
        <a href="<?= base_url('images') ?>" class="<?= str_contains($currentPath, 'images') && !str_contains($currentPath, 'albums') ? 'active' : '' ?>">🖼️ 图片</a>
        <a href="<?= base_url('albums') ?>" class="<?= str_contains($currentPath, 'albums') ? 'active' : '' ?>">📁 相册</a>
        <a href="<?= base_url('storages') ?>" class="<?= str_contains($currentPath, 'storages') ? 'active' : '' ?>">💾 存储</a>
        <a href="<?= base_url('compression') ?>" class="<?= str_contains($currentPath, 'compression') ? 'active' : '' ?>">⚙️ 压缩</a>
        <a href="<?= base_url('settings') ?>" class="<?= str_contains($currentPath, 'settings') ? 'active' : '' ?>">⚙️ 设置</a>
        <a href="<?= base_url('api-keys') ?>" class="<?= str_contains($currentPath, 'api-keys') ? 'active' : '' ?>">🔑 API</a>
        <a href="<?= base_url('security') ?>" class="<?= str_contains($currentPath, 'security') ? 'active' : '' ?>">🔐 安全</a>
    </nav>
    <div class="drawer-footer">
        <form method="POST" action="<?= base_url('logout') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <button type="submit" class="drawer-logout-btn">退出登录</button>
        </form>
    </div>
</aside>

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
(function() {
    var btn = document.getElementById('hamburger-btn');
    var drawer = document.getElementById('mobile-drawer');
    var overlay = document.getElementById('drawer-overlay');
    var closeBtn = document.getElementById('drawer-close');
    if (!btn || !drawer || !overlay || !closeBtn) return;
    function openDrawer() {
        drawer.classList.add('open');
        overlay.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeDrawer() {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    btn.addEventListener('click', openDrawer);
    closeBtn.addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);
    // ESC 关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
    });
})();
</script>
</body>
</html>