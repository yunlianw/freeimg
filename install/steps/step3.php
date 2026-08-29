<?php
/**
 * Step 3: 创建管理员视图
 */
?>
<h2 style="margin-bottom:20px; color:#495057;">创建管理员账号</h2>
<form method="POST">
    <div class="form-group">
        <label>管理员用户名</label>
        <input type="text" name="admin_username" required minlength="3" maxlength="32">
    </div>
    <div class="form-group">
        <label>管理员邮箱</label>
        <input type="email" name="admin_email" required>
    </div>
    <div class="form-group">
        <label>管理员密码</label>
        <input type="password" name="admin_password" required minlength="8">
        <div class="hint">至少 8 位，请妥善保管</div>
    </div>
    <div style="display:flex; justify-content:space-between; margin-top:24px;">
        <a href="?step=2" class="btn" style="background:#868e96;">← 上一步</a>
        <button type="submit" class="btn">立即安装 →</button>
    </div>
</form>