<div class="page-header">
    <h1>🔑 修改密码</h1>
    <p class="subtitle">建议使用 12 位以上、包含大小写字母和数字的强密码</p>
</div>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="<?= base_url('security/password') ?>" id="password-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="form-group">
            <label>当前密码</label>
            <input type="password" name="old_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label>新密码 <span id="strength-label" style="font-weight:normal; font-size:12px;"></span></label>
            <input type="password" name="new_password" id="new_password" required autocomplete="new-password" minlength="10">
            <div id="strength-bar" style="margin-top:6px; height:6px; background:var(--gray-200); border-radius:3px; overflow:hidden;">
                <div id="strength-fill" style="height:100%; width:0%; background:var(--red-500); transition:all .2s;"></div>
            </div>
            <div class="hint" style="font-size:12px; color:var(--gray-500); margin-top:4px;">
                至少 10 位 · 建议包含大小写字母、数字、特殊符号
            </div>
        </div>
        <div class="form-group">
            <label>确认新密码</label>
            <input type="password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn-primary">修改密码</button>
    </form>
</div>

<div class="card" style="background:var(--gray-50);">
    <div class="card-title">⚠️ 修改后会发生</div>
    <ul style="font-size:13px; color:var(--gray-700); line-height:1.8;">
        <li>你当前浏览器的会话<strong>保留</strong></li>
        <li>其他设备/浏览器的登录会被<strong style="color:var(--red-500);">强制下线</strong>，需用新密码重新登录</li>
        <li>不能重用最近 5 个使用过的密码</li>
    </ul>
</div>

<script>
(function () {
    const inp = document.getElementById('new_password');
    const fill = document.getElementById('strength-fill');
    const label = document.getElementById('strength-label');
    if (!inp) return;
    const colors = ['#ef4444', '#f59e0b', '#eab308', '#10b981', '#059669'];
    const labels = ['极弱', '弱', '一般', '强', '极强'];
    inp.addEventListener('input', () => {
        const v = inp.value;
        let score = 0;
        if (v.length >= 10) score++;
        if (v.length >= 14) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v) && /[^a-zA-Z0-9]/.test(v)) score++;
        if (v.length >= 18) score++;
        score = Math.min(4, score);
        fill.style.width = ((score + 1) * 20) + '%';
        fill.style.background = colors[score];
        label.textContent = labels[score] ? '· ' + labels[score] : '';
    });
})();
</script>