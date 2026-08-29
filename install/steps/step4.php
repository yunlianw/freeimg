<?php
/**
 * Step 4: 完成视图
 */
?>
<div class="success-box">
    <div class="success-icon">✓</div>
    <h2 style="color:#2b8a3e; margin-bottom:16px;">🎉 安装成功！</h2>
    <p style="color:#495057; margin-bottom:24px;">FreeImg 已经准备就绪，立即开始使用吧。</p>

    <div class="alert alert-error" style="text-align:left;">
        ⚠️ <strong>安全提醒</strong>：为安全起见，请立即删除 <code>install/</code> 目录！
    </div>

    <div style="margin-top:24px;">
        <a href="<?= '/' ?>" class="btn">进入首页</a>
        <a href="<?= '/login' ?>" class="btn" style="margin-left:10px; background:#51cf66;">管理员登录</a>
    </div>
</div>