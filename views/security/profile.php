<div class="page-header">
    <h1>👤 账户资料</h1>
    <p class="subtitle">修改用户名、邮箱 · <a href="<?= base_url('security/password') ?>">改密 →</a></p>
</div>

<?php if (($flash = flash_get()) !== null): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-title">基本信息</div>
    <form method="POST" action="<?= base_url('security/profile') ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="form-group">
            <label>用户名</label>
            <input type="text" name="username" value="<?= h($user['username']) ?>" required minlength="3" maxlength="32"
                   pattern="[a-zA-Z0-9_\-]+" title="只能包含字母、数字、下划线、短横线">
            <div class="hint">3-32 字符 · 字母/数字/下划线/短横线</div>
        </div>
        <div class="form-group">
            <label>邮箱</label>
            <input type="email" name="email" value="<?= h($user['email']) ?>" required>
            <div class="hint">用于账户通知、找回密码等（暂未开放密码找回）</div>
        </div>
        <div class="form-group">
            <label>角色</label>
            <input type="text" value="<?= h($user['role']) ?>" disabled style="background:var(--gray-100);">
        </div>
        <div class="form-group">
            <label>上次登录</label>
            <input type="text" value="<?= h($user['last_login_at'] ?? '从未') ?>" disabled style="background:var(--gray-100);">
        </div>
        <button type="submit" class="btn-primary">保存资料</button>
    </form>
</div>

<div class="card" style="background:var(--gray-50);">
    <div class="card-title">🔐 安全相关入口</div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
        <a href="<?= base_url('security/password') ?>" class="btn-secondary" style="text-align:center; padding:12px;">🔑 修改密码</a>
        <a href="<?= base_url('security/2fa') ?>" class="btn-secondary" style="text-align:center; padding:12px;">🔐 两步验证 2FA</a>
        <a href="<?= base_url('security/sessions') ?>" class="btn-secondary" style="text-align:center; padding:12px;">📱 活跃会话</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
        <a href="<?= base_url('security/policy') ?>" class="btn-secondary" style="text-align:center; padding:12px;">⚙️ 安全策略</a>
        <a href="<?= base_url('security/login-logs') ?>" class="btn-secondary" style="text-align:center; padding:12px;">📋 登录日志</a>
        <?php endif; ?>
    </div>
</div>