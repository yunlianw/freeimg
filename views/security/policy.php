<div class="page-header">
    <h1>⚙️ 安全策略</h1>
    <p class="subtitle">会话、登录锁定、密码强度、2FA 颁发者名称</p>
</div>

<?php if (($flash = flash_get()) !== null): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<form method="POST" action="<?= base_url('security/policy') ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

    <div class="field-group">
        <div class="field-group-title">🕐 会话超时</div>
        <div class="form-group">
            <label>会话有效期（小时）</label>
            <input type="number" name="session_ttl_hours" min="1" max="8760" value="<?= h($settings['session_ttl_hours'] ?? '24') ?>" required>
            <div class="hint">1 小时 ~ 12 个月（8760 小时）· 滑动过期，每次活动自动续期</div>
        </div>
        <div class="form-group">
            <label>最大并发会话数</label>
            <input type="number" name="max_concurrent_sessions" min="1" max="20" value="<?= h($settings['max_concurrent_sessions'] ?? '3') ?>" required>
            <div class="hint">同一账号可同时登录的设备/浏览器数 · 超出自动踢掉最久没活动的会话 · 设 1 等同单点登录</div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">🔒 登录失败锁定</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
            <div class="form-group">
                <label>失败阈值（次）</label>
                <input type="number" name="login_max_failed" min="1" max="100" value="<?= h($settings['login_max_failed'] ?? '5') ?>" required>
                <div class="hint">连续失败 N 次后锁定</div>
            </div>
            <div class="form-group">
                <label>锁定时长（分钟）</label>
                <input type="number" name="login_lock_minutes" min="1" max="1440" value="<?= h($settings['login_lock_minutes'] ?? '15') ?>" required>
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">🔑 密码强度</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
            <div class="form-group">
                <label>最小长度</label>
                <input type="number" name="password_min_length" min="8" max="64" value="<?= h($settings['password_min_length'] ?? '10') ?>" required>
            </div>
            <div class="form-group">
                <label>历史记录数（防重用）</label>
                <input type="number" name="password_history_count" min="0" max="20" value="<?= h($settings['password_history_count'] ?? '5') ?>" required>
                <div class="hint">不能重用最近 N 个密码</div>
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">🔐 2FA 颁发者</div>
        <div class="form-group">
            <label>App 中显示的名称</label>
            <input type="text" name="totp_issuer" maxlength="32" value="<?= h($settings['totp_issuer'] ?? 'FreeImg') ?>" required>
            <div class="hint">显示在 Google Authenticator / 微软 Authenticator 等 App 里</div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">💾 保存安全策略</button>
        <a href="<?= base_url('security') ?>" class="btn-link">返回安全中心</a>
    </div>
</form>