<div class="auth-page">
    <div class="auth-card">
        <h1>🔐 两步验证</h1>
        <p class="subtitle">请打开 Google Authenticator / 微软 Authenticator 输入 6 位代码</p>

        <?php $flash = flash_get(); ?>
<?php if ($flash !== null): ?>
            <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= base_url('login/2fa') ?>" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="form-group">
                <label>6 位验证码</label>
                <input type="text" name="totp_code" pattern="\d{6}" maxlength="6" placeholder="123456" required autofocus
                       style="text-align:center; font-size:24px; letter-spacing:8px; font-family:monospace;">
            </div>
            <button type="submit" class="btn-primary" style="width:100%;">验证并登录</button>
        </form>

        <p style="margin-top:20px; font-size:13px; color:var(--gray-500); text-align:center;">
            10 分钟内有效 · <a href="<?= base_url('login') ?>" style="color:var(--gray-500);">返回</a>
        </p>
    </div>
</div>