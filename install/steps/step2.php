<?php
/**
 * Step 2: 数据库配置视图
 */
?>
<h2 style="margin-bottom:20px; color:#495057;">数据库配置</h2>
<div class="alert alert-info">
    ℹ️ 库不存在时，安装程序会自动创建。请确保数据库账号有 CREATE DATABASE 权限。
</div>
<form method="POST">
    <div class="form-group">
        <label>数据库主机</label>
        <input type="text" name="db_host" value="127.0.0.1" required>
    </div>
    <div class="form-group">
        <label>数据库端口</label>
        <input type="number" name="db_port" value="3306" required>
    </div>
    <div class="form-group">
        <label>数据库名</label>
        <input type="text" name="db_name" required placeholder="例如：freeimg">
        <div class="hint">不存在会自动创建</div>
    </div>
    <div class="form-group">
        <label>用户名</label>
        <input type="text" name="db_user" required>
    </div>
    <div class="form-group">
        <label>密码</label>
        <input type="password" name="db_pass">
    </div>
    <div style="display:flex; justify-content:space-between; margin-top:24px;">
        <a href="?step=1" class="btn" style="background:#868e96;">← 上一步</a>
        <button type="submit" class="btn">下一步：创建管理员 →</button>
    </div>
</form>