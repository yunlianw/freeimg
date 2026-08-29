<div class="page-header">
    <h1>🔐 两步验证（2FA）</h1>
    <p class="subtitle">使用 Google Authenticator / 微软 Authenticator / 1Password 等支持 TOTP 的 App</p>
</div>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if (!empty($showBackup) && !empty($backupCodes)): ?>
<div class="card" style="background:#fffbeb; border:2px solid #f59e0b;">
    <div class="card-title" style="color:#b45309;">🎉 2FA 已开启！请保存这些备份码</div>
    <p style="color:#92400e; font-size:13px; margin-bottom:16px;">
        ⚠️ 如果你丢了手机，每个备份码<strong>只能用一次</strong>用于登录。
        <strong>立即保存</strong>到密码管理器或打印出来。
    </p>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:8px; font-family:monospace; font-size:14px;">
        <?php foreach ($backupCodes as $code): ?>
            <div style="background:#fff; padding:10px; border-radius:6px; text-align:center; border:1px solid #fde68a;">
                <?= h($code) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p style="margin-top:16px; color:#92400e; font-size:13px;">
        我已保存 · <a href="<?= base_url('security/2fa') ?>" style="color:#b45309;">返回安全中心</a>
    </p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">当前状态</div>
    <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
        <p style="color:#059669;">✅ 两步验证<strong>已开启</strong></p>
        <p style="font-size:13px; color:var(--gray-500); margin-top:8px;">
            绑定时间：<?= h($user['created_at'] ?? '-') ?><br>
            剩余备份码：<?= count(json_decode($user['totp_backup_codes'] ?? '[]', true) ?: []) ?> 个
        </p>

        <form method="POST" action="<?= base_url('security/2fa/disable') ?>" style="margin-top:20px; padding-top:20px; border-top:1px solid var(--gray-200);">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <p style="color:var(--red-500); font-size:13px;">⚠️ 关闭 2FA 需要验证密码 + 当前两步代码</p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px;">
                <input type="password" name="password" placeholder="当前密码" required>
                <input type="text" name="totp_code" placeholder="6 位验证码" pattern="\d{6}" maxlength="6" required style="font-family:monospace; text-align:center; letter-spacing:4px;">
            </div>
            <button type="submit" class="btn-mini btn-mini-danger" style="margin-top:12px;">关闭两步验证</button>
        </form>
    <?php else: ?>
        <p style="color:var(--gray-500);">❌ 两步验证未开启</p>

        <?php if (!empty($qrUrl)): ?>
        <div style="margin-top:20px; padding:20px; background:var(--gray-50); border-radius:8px;">
            <h3 style="margin:0 0 12px;">📱 步骤 1：用 Authenticator App 扫描</h3>
            <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
                <img src="<?= h($qrUrl) ?>" alt="2FA QR" style="width:180px; height:180px; border:4px solid #fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                <div style="font-size:13px;">
                    <p><strong>手动输入：</strong></p>
                    <code style="display:block; padding:10px; background:#fff; border:1px solid var(--gray-200); border-radius:6px; font-size:14px; user-select:all;"><?= h($secret) ?></code>
                    <p style="margin-top:8px; color:var(--gray-500); font-size:12px;">类型：基于时间 (TOTP) · 30 秒 · 颁发者：<?= h($issuer ?? 'FreeImg') ?></p>
                </div>
            </div>
        </div>

        <form method="POST" action="<?= base_url('security/2fa/enable') ?>" style="margin-top:24px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <h3 style="margin:0 0 12px;">🔢 步骤 2：输入 6 位验证码确认</h3>
            <input type="text" name="totp_code" placeholder="123456" pattern="\d{6}" maxlength="6" required autofocus
                   style="font-size:20px; letter-spacing:6px; font-family:monospace; text-align:center; width:200px;">
            <button type="submit" class="btn-primary" style="margin-left:12px;">✓ 确认开启</button>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= base_url('security/2fa/setup') ?>" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="btn-primary">开始设置两步验证</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card" style="background:var(--gray-50);">
    <div class="card-title">📖 什么是两步验证？</div>
    <p style="font-size:13px; color:var(--gray-700); line-height:1.7;">
        开启后，登录时除了密码，还需要输入手机 App 上 <strong>30 秒变一次</strong>的 6 位数字。
        即使密码泄露，攻击者没有你的手机也登不上。
    </p>
    <p style="font-size:13px; color:var(--gray-700); line-height:1.7;">
        <strong>推荐 App：</strong>Google Authenticator、微软 Authenticator、1Password、Bitwarden
    </p>
</div>