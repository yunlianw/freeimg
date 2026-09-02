<div class="login-box">
    <div class="login-card">
        <div class="login-header">
            <h1>🖼️ <?= h(brand()) ?></h1>
            <p>自由图床 · 管理后台</p>
        </div>
        <?php if ($err = flash('error')): ?>
            <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <form method="POST" action="<?= base_url('login') ?>" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? '') ?>">
            <div class="form-group">
                <label>用户名 / 邮箱</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-primary">登 录</button>
        </form>
    </div>
</div>