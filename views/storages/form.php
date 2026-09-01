<?php if (!defined('FREEIMG_ROOT')) define('FREEIMG_ROOT', dirname(__DIR__, 2)); ?>
<div class="page-header">
    <div>
        <h1><?= $item['id'] ? '✏️ 编辑存储' : '➕ 添加存储' ?></h1>
        <p class="subtitle"><?= htmlspecialchars($def['icon'] . ' ' . $def['label']) ?> · <?= htmlspecialchars($def['desc']) ?></p>
    </div>
    <a href="<?= base_url('storages') ?>" class="btn-link">← 返回列表</a>
</div>

<form method="POST" action="<?= base_url('storages/save') ?>" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
    <input type="hidden" name="driver" value="<?= htmlspecialchars($driver) ?>">

    <div class="field-group">
        <div class="field-group-title">📋 基本信息</div>
        <div class="form-group">
            <label>存储名称 *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" placeholder="如：主服务器 / 备份服务器 / 腾讯云" required>
            <div class="hint">给自己看的名字，方便区分多个存储</div>
        </div>
        <div class="form-group">
            <label>驱动类型</label>
            <select id="driver-select" onchange="location.href='<?= base_url('storages/form') ?>?driver='+this.value<?= $item['id'] ? "+'&id={$item['id']}'" : '' ?>">
                <?php foreach ($drivers as $dk => $dv): ?>
                    <option value="<?= $dk ?>" <?= $dk === $driver ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dv['icon'] . ' ' . $dv['label']) ?> — <?= htmlspecialchars($dv['desc']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">🔧 <?= htmlspecialchars($def['icon'] . ' ' . $def['label']) ?> 配置</div>

        <?php foreach ($def['fields'] as $key => $f): ?>
            <?php
            $val = $item['config'][$key] ?? ($f['default'] ?? '');
            $isPwd = ($f['type'] === 'password');
            // 密码字段：编辑时显示前 4 位 + 星号（让用户知道密码已存但看不到全部）
            $maskedPlaceholder = '';
            if ($isPwd && $item['id'] && !empty($val)) {
                $valStr = (string)$val;
                $visibleLen = min(4, mb_strlen($valStr));
                $maskedPlaceholder = mb_substr($valStr, 0, $visibleLen) . str_repeat('•', max(8, $visibleLen * 2));
            }
            ?>
            <div class="form-group">
                <label><?= htmlspecialchars($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></label>
                <?php if ($f['type'] === 'select'): ?>
                    <select name="cfg_<?= $key ?>">
                        <?php foreach ($f['options'] as $ok => $ov): ?>
                            <option value="<?= $ok ?>" <?= (string)$val === (string)$ok ? 'selected' : '' ?>><?= htmlspecialchars($ov) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="<?= $isPwd ? 'password' : ($f['type'] === 'number' ? 'number' : 'text') ?>"
                           name="cfg_<?= $key ?>"
                           value="<?= $isPwd ? '' : htmlspecialchars((string)$val) ?>"
                           placeholder="<?= htmlspecialchars($maskedPlaceholder ?: ($f['placeholder'] ?? '')) ?>"
                           <?= !empty($f['required']) ? 'required' : '' ?>>
                <?php endif; ?>
                <?php if (!empty($f['help'])): ?>
                    <div class="hint"><?= htmlspecialchars($f['help']) ?></div>
                <?php endif; ?>
                <?php if ($isPwd && $item['id'] && $val !== ''): ?>
                    <div class="hint" style="color:var(--green-600);">🔒 已保存密码（<?= htmlspecialchars(mb_substr((string)$val, 0, 4)) ?>****）— 留空则不修改，<a href="javascript:void(0)" class="clear-pwd" data-field="cfg_<?= htmlspecialchars($key) ?>">点此清空重填</a></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="form-group">
            <label class="switch-label" style="display:flex; align-items:center; gap:10px;">
                <label class="switch">
                    <input type="checkbox" name="status" value="1" <?= $item['status'] ? 'checked' : '' ?>>
                    <span class="switch-slider"></span>
                </label>
                启用此存储
            </label>
        </div>

        <div class="form-group">
            <label>优先级（数字越大越优先）</label>
            <input type="number" name="priority" value="<?= (int)($item['priority'] ?? 0) ?>" min="0" max="999">
            <div class="hint">多存储时按此排序，建议本地=10，云=5，备份=0</div>
        </div>
        <div class="form-group">
            <label>上传页可见性</label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                <input type="checkbox" name="visible_in_upload" value="1" <?= !empty($item['visible_in_upload']) ? 'checked' : '' ?>>
                在上传页面下拉中显示
            </label>
            <div class="hint">不勾选：用户上传时看不到此选项（仍可作为 fallback）</div>
        </div>
        <div class="form-group">
            <label>容量上限（MB）</label>
            <input type="number" name="max_capacity_mb" value="<?= (int)($item['max_capacity_mb'] ?? 0) ?>" min="0">
            <div class="hint">0 = 无限；达到 80% 时自动停止写入（fallback 切换）</div>
        </div>

        <div style="display:flex; gap:10px; margin-top:8px;">
            <button type="button" id="btn-test" class="btn-primary" style="background:var(--blue-500);">🔌 测试连接</button>
            <button type="submit" class="btn-primary">💾 保存</button>
        </div>
        <div id="test-result" style="margin-top:10px; font-size:14px;"></div>
    </div>
</form>

<?php if (!empty($def['tips'])): ?>
    <div class="field-group" style="margin-top:16px;">
        <div class="field-group-title">💡 提示</div>
        <div class="hint"><?= htmlspecialchars($def['tips']) ?></div>
    </div>
<?php endif; ?>

<?php if (($def['tutorial'] ?? '') === 'sftp'): ?>
<!-- ================= SFTP 小白教程 ================= -->
<!-- 注意：不要套 config-collapse-wrapper 类！那个类 max-height:800px + overflow:hidden 会截断底部教程内容 -->
<div class="sftp-tutorial" style="margin-top:16px;">
    <div style="border:1px solid var(--gray-200); border-radius:10px; overflow:hidden;">
        <details>
            <summary style="padding:14px 18px; cursor:pointer; font-weight:600; background:var(--gray-50);">
                📖 SFTP 小白教程 —— 三步把图片存到另一台服务器
            </summary>
            <div style="padding:18px; line-height:1.9; font-size:14px;">

                <p><strong>SFTP 是什么？</strong><br>
                简单说：把你的图片通过加密通道传到<b>另一台服务器</b>上保存。
                适合：网站和图床分开部署、有大硬盘的存储机、不想占用主站磁盘。</p>

                <hr style="border:none; border-top:1px dashed var(--gray-300); margin:14px 0;">

                <p><strong>第 1 步：在目标服务器上创建存储目录</strong></p>
                <div class="code-block"><pre># SSH 登录你的目标服务器（用自己的账号）
ssh root@你的服务器IP

# 创建存储目录（路径随意，记住它）
mkdir -p /home/www/uploads

# 给写权限（如果是 www 用户）
chown -R www:www /home/www/uploads</pre></div>

                <p><strong>第 2 步：选择登录方式</strong>（二选一）</p>
                <p>🔑 <strong>方式 A：密码登录（最简单）</strong><br>
                直接用目标服务器的 SSH 账号密码，填到上面的「用户名」「密码」即可。</p>
                <p>🔐 <strong>方式 B：密钥登录（更安全，推荐）</strong></p>
                <div class="code-block"><pre># 在本机（图床服务器）生成密钥对，一路回车
ssh-keygen -t ed25519 -f ~/.ssh/freeimg -N ""

# 把公钥装到目标服务器（输入目标服务器密码一次）
ssh-copy-id -i ~/.ssh/freeimg.pub 用户名@服务器IP

# 测试登录（免密成功就 OK）
ssh -i ~/.ssh/freeimg 用户名@服务器IP</pre></div>
                <div class="hint">⚠️ 然后在上面的「私钥文件路径」填 <code>~/.ssh/freeimg</code>（注意：同目录必须有 <code>freeimg.pub</code> 公钥文件）。<br>
                ⚠️ Debian 12+ 服务器默认禁用了老旧的 ssh-rsa 算法，请用上面的 ed25519 生成方式。</div>

                <p><strong>第 3 步：填写上面的配置并保存</strong></p>
                <ul style="margin:6px 0 0 20px; padding:0;">
                    <li><b>服务器地址</b>：IP 或域名</li>
                    <li><b>端口</b>：默认 22，没改过不用动</li>
                    <li><b>远程存储目录</b>：第 1 步创建的目录</li>
                    <li><b>公开访问 URL 前缀</b>：如果目标服务器装了 Nginx 能直接访问图片（如 <code>https://img.example.com</code>）就填上；没有就留空（图片只保存、前台不显示）</li>
                </ul>

                <p><strong>第 4 步：点「🔌 测试连接」</strong>，显示绿色 ✅ 就成功了，最后点保存。</p>

                <hr style="border:none; border-top:1px dashed var(--gray-300); margin:14px 0;">

                <p style="color:var(--gray-600); font-size:13px;">
                ❓ 测试失败？<br>
                · 地址/端口/用户名/密码 核对一遍（密码认证失败多半是账号错）<br>
                · 密钥认证失败 → 确认私钥路径正确、公钥已装到服务器、用的是 ed25519<br>
                · 提示目录不存在 → 回第 1 步把目录建好<br>
                · 服务器防火墙拦了 22 端口 → 到目标服务器安全组放行</p>
            </div>
        </details>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('btn-test')?.addEventListener('click', function () {
    const btn = this;
    const box = document.getElementById('test-result');
    btn.disabled = true;
    btn.textContent = '⏳ 测试中…';
    box.innerHTML = '';

    const form = btn.closest('form');
    const fd = new FormData(form);
    fd.set('csrf_token', document.querySelector('input[name=csrf_token]').value);
    fd.set('driver', document.querySelector('input[name=driver]').value);
    // 去掉必填校验，测试时允许空
    fd.delete('name');

    fetch('<?= base_url('storages/test') ?>', {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json' },
    })
        .then(r => r.json())
        .then(data => {
            const span = document.createElement('span');
            span.style.color = data.ok ? 'var(--green-600)' : 'var(--red-500)';
            span.style.fontWeight = '600';
            span.textContent = data.message;
            box.appendChild(span);
        })
        .catch(() => { box.textContent = '❌ 请求失败，请重试'; box.style.color = 'var(--red-500)'; })
        .finally(() => { btn.disabled = false; btn.textContent = '🔌 测试连接'; });
});

// 点此清空重填：清空密码字段，让用户填新值
document.querySelectorAll('.clear-pwd').forEach(function (a) {
    a.addEventListener('click', function () {
        const fieldName = a.getAttribute('data-field');
        const input = document.querySelector('input[name="' + fieldName + '"]');
        if (input) {
            input.value = '';
            input.placeholder = '请输入新值';
            input.focus();
        }
    });
});
</script>
