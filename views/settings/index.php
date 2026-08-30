<?php if (!defined('FREEIMG_ROOT')) define('FREEIMG_ROOT', dirname(__DIR__, 2)); ?>
<div class="page-header">
    <div>
        <h1>系统设置</h1>
        <p class="subtitle">配置站点信息、上传规则和存储</p>
    </div>
</div>

<form method="POST" action="<?= base_url('settings') ?>" class="settings-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? '') ?>">
    <input type="hidden" id="freeimg-csrf" value="<?= htmlspecialchars($csrf ?? '') ?>" data-prefix="<?= htmlspecialchars(config('settings.url_path_prefix') ?? 'img') ?>">

    <div class="field-group">
        <div class="field-group-title">📝 基础设置</div>
        <div class="form-group">
            <label>站点名称</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? 'FreeImg') ?>">
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">📤 上传设置</div>
        <div class="form-group">
            <label>最大文件大小 (MB)</label>
            <input type="number" name="upload_max_size" min="1" max="20" value="<?= htmlspecialchars($settings['upload_max_size'] ?? '10') ?>">
            <div class="hint">受 nginx `client_max_body_size 20M` 限制，最大 20MB</div>
        </div>
        <div class="form-group">
            <label>允许的扩展名（逗号分隔）</label>
            <input type="text" name="upload_allowed_types" value="<?= htmlspecialchars($settings['upload_allowed_types'] ?? 'jpg,jpeg,png,gif,webp,bmp') ?>">
        </div>
        <div class="form-group">
            <label>默认压缩档位</label>
            <select name="default_compression">
                <?php
                $opts = ['original' => '原图', 'high' => '高清', 'balanced' => '均衡', 'saver' => '省流', 'extreme' => '极限省流'];
                $cur = $settings['default_compression'] ?? 'balanced';
                foreach ($opts as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $cur === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                <input type="checkbox" name="strip_exif" value="1" <?= ($settings['strip_exif'] ?? '1') === '1' ? 'checked' : '' ?>>
                上传时自动剥离 EXIF 元数据（默认开启，防止 GPS / 拍摄设备信息泄露）
            </label>
            <div class="hint">JPEG / PNG / WebP / BMP 会通过 GD 重绘剥离 EXIF/IPTC/XMP。GIF 有动画帧，不参与剥离。</div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">💧 水印（上传时自动添加）</div>
        <div class="form-group">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                <input type="checkbox" name="watermark_enabled" value="1" <?= ($settings['watermark_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                启用水印（图片上添加文字水印）
            </label>
        </div>
        <div class="form-group">
            <label>水印文字</label>
            <input type="text" name="watermark_text" value="<?= htmlspecialchars($settings['watermark_text'] ?? '') ?>" placeholder="如：© Your Site">
            <div class="hint">支持中文，留空默认使用站点名</div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
            <div class="form-group">
                <label>字体大小 (px)</label>
                <input type="number" name="watermark_font_size" min="8" max="200" value="<?= htmlspecialchars($settings['watermark_font_size'] ?? '28') ?>">
            </div>
            <div class="form-group">
                <label>透明度 (1-100)</label>
                <input type="number" name="watermark_opacity" min="1" max="100" value="<?= htmlspecialchars($settings['watermark_opacity'] ?? '50') ?>">
                <div class="hint">100 = 完全不透明</div>
            </div>
            <div class="form-group">
                <label>旋转角度</label>
                <input type="number" name="watermark_angle" min="-180" max="180" value="<?= htmlspecialchars($settings['watermark_angle'] ?? '0') ?>">
            </div>
            <div class="form-group">
                <label>边距 (px)</label>
                <input type="number" name="watermark_margin" min="0" max="200" value="<?= htmlspecialchars($settings['watermark_margin'] ?? '20') ?>">
            </div>
            <div class="form-group">
                <label>颜色</label>
                <input type="color" name="watermark_color" value="<?= htmlspecialchars($settings['watermark_color'] ?? '#ffffff') ?>" style="height:38px; padding:4px; width:100%;">
            </div>
        </div>
        <div class="form-group">
            <label>位置</label>
            <?php
            $positions = ['tl' => '左上', 'tc' => '上中', 'tr' => '右上', 'ml' => '左中', 'mc' => '居中', 'mr' => '右中', 'bl' => '左下', 'bc' => '下中', 'br' => '右下'];
            $curPos = $settings['watermark_position'] ?? 'br';
            ?>
            <div style="display:grid; grid-template-columns:repeat(3, 64px); gap:6px; max-width:216px;">
                <?php foreach ($positions as $pk => $pv): ?>
                    <label style="display:flex; align-items:center; justify-content:center; gap:4px; border:1px solid var(--gray-300); border-radius:8px; padding:8px 4px; cursor:pointer; background:<?= $curPos === $pk ? 'var(--blue-50)' : '#fff' ?>; font-size:12px;">
                        <input type="radio" name="watermark_position" value="<?= $pk ?>" <?= $curPos === $pk ? 'checked' : '' ?> style="margin:0;">
                        <?= htmlspecialchars($pv) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">🖼️ 图片水印（Logo 水印）</div>
        <div class="form-group">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                <input type="checkbox" name="image_watermark_enabled" value="1" <?= ($settings['image_watermark_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                启用水印图片（使用 Logo 覆盖，优先于文字水印）
            </label>
        </div>
        <div class="form-group">
            <label>水印图片</label>
            <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <input type="file" name="watermark_image" accept="image/png,image/jpeg,image/webp">
                <?php if (file_exists(FREEIMG_ROOT . '/public/storage/watermark/logo.png')): ?>
                    <img src="/storage/watermark/logo.png" alt="当前水印图" style="max-height:48px; border:1px solid var(--gray-300); border-radius:6px; background:repeating-conic-gradient(#eee 0% 25%, #fff 0% 50%) 0 0/16px 16px;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; color:var(--red-500);">
                        <input type="checkbox" name="watermark_image_remove" value="1"> 删除当前水印图
                    </label>
                <?php else: ?>
                    <span style="font-size:13px; color:var(--gray-500);">尚未上传（上传 PNG 透明图效果最佳）</span>
                <?php endif; ?>
            </div>
            <div class="hint">上传新图将覆盖旧图；推荐 <code>PNG 透明底</code></div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
            <div class="form-group">
                <label>水印大小 (占图宽 %)</label>
                <input type="number" name="image_watermark_size" min="5" max="100" value="<?= htmlspecialchars($settings['image_watermark_size'] ?? '20') ?>">
            </div>
            <div class="form-group">
                <label>透明度 (1-100)</label>
                <input type="number" name="image_watermark_opacity" min="1" max="100" value="<?= htmlspecialchars($settings['image_watermark_opacity'] ?? '80') ?>">
                <div class="hint">100 = 完全不透明</div>
            </div>
            <div class="form-group">
                <label>边距 (px)</label>
                <input type="number" name="image_watermark_margin" min="0" max="200" value="<?= htmlspecialchars($settings['image_watermark_margin'] ?? '15') ?>">
            </div>
        </div>
        <div class="form-group">
            <label>位置</label>
            <?php
            $positions = ['tl' => '左上', 'tc' => '上中', 'tr' => '右上', 'ml' => '左中', 'mc' => '居中', 'mr' => '右中', 'bl' => '左下', 'bc' => '下中', 'br' => '右下'];
            $curPos = $settings['image_watermark_position'] ?? 'br';
            ?>
            <div style="display:grid; grid-template-columns:repeat(3, 64px); gap:6px; max-width:216px;">
                <?php foreach ($positions as $pk => $pv): ?>
                    <label style="display:flex; align-items:center; justify-content:center; gap:4px; border:1px solid var(--gray-300); border-radius:8px; padding:8px 4px; cursor:pointer; background:<?= $curPos === $pk ? 'var(--blue-50)' : '#fff' ?>; font-size:12px;">
                        <input type="radio" name="image_watermark_position" value="<?= $pk ?>" <?= $curPos === $pk ? 'checked' : '' ?> style="margin:0;">
                        <?= htmlspecialchars($pv) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">💾 存储</div>
        <div class="form-group">
            <label>URL 路径前缀</label>
            <input type="text" name="url_path_prefix" value="<?= htmlspecialchars($settings['url_path_prefix'] ?? 'rest/new') ?>" placeholder="rest/new">
            <div class="hint">
                图片存储路径格式：<code>{前缀}/{uuid}.{ext}</code><br>
                示例：<code>rest/new</code> → <code>https://yourdomain.com/uploads/rest/new/abc123.jpg</code><br>
                留空使用默认 <code>rest/new</code>，仅允许字母、数字、斜杠、下划线、短横线
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">📝 重命名规则</div>
        <div class="form-group">
            <label>默认命名规则</label>
            <select name="rename_rule" id="rename-rule-select">
                <option value="short" <?= ($settings['rename_rule'] ?? '') === 'short' || empty($settings['rename_rule']) ? 'selected' : '' ?>>随机短名（7 位，默认）</option>
                <option value="timestamp" <?= ($settings['rename_rule'] ?? '') === 'timestamp' ? 'selected' : '' ?>>时间戳（YmdHis + 4 位随机）</option>
                <option value="original" <?= ($settings['rename_rule'] ?? '') === 'original' ? 'selected' : '' ?>>原文件名</option>
                <option value="custom" <?= ($settings['rename_rule'] ?? '') === 'custom' ? 'selected' : '' ?>>自定义格式</option>
            </select>
            <div class="hint">上传页勾选「使用原文件名」可单次覆盖此规则</div>
        </div>
        <div class="form-group" id="custom-format-group" style="display:none;">
            <label>自定义格式</label>
            <input type="text" name="rename_custom_format" value="<?= htmlspecialchars($settings['rename_custom_format'] ?? '{date}_{random}') ?>" placeholder="{date}_{random}" maxlength="128">
            <div class="hint">
                占位符：<code>{date}</code> 日期 · <code>{time}</code> 时间 · <code>{datetime}</code> 日期时间 · <code>{random}</code> 随机 7 位 · <code>{uuid}</code> 唯一 ID · <code>{original}</code> 原文件名 · <code>{ext}</code> 扩展名<br>
                示例：<code>{date}_{time}_{random}</code>、<code>img_{uuid}</code>
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">📁 目录规则</div>
        <div class="form-group">
            <label>自动子目录（上传未手动指定目录时生效）</label>
            <select name="dir_rule" id="dir-rule-select">
                <option value="none" <?= ($settings['dir_rule'] ?? '') === 'none' || empty($settings['dir_rule']) ? 'selected' : '' ?>>无子目录（默认）</option>
                <option value="year" <?= ($settings['dir_rule'] ?? '') === 'year' ? 'selected' : '' ?>>按年（2026）</option>
                <option value="month" <?= ($settings['dir_rule'] ?? '') === 'month' ? 'selected' : '' ?>>按年月（2026/08）</option>
                <option value="day" <?= ($settings['dir_rule'] ?? '') === 'day' ? 'selected' : '' ?>>按年月日（2026/08/29）</option>
                <option value="ymd" <?= ($settings['dir_rule'] ?? '') === 'ymd' ? 'selected' : '' ?>>按日期（20260829）</option>
                <option value="custom" <?= ($settings['dir_rule'] ?? '') === 'custom' ? 'selected' : '' ?>>自定义格式</option>
            </select>
            <div class="hint">上传页手动输入子目录时优先于规则；规则只作用于未指定目录的上传</div>
        </div>
        <div class="form-group" id="dir-custom-group" style="display:none;">
            <label>自定义目录格式</label>
            <input type="text" name="dir_custom_format" value="<?= htmlspecialchars($settings['dir_custom_format'] ?? '{date}') ?>" placeholder="{date}" maxlength="128">
            <div class="hint">
                占位符：<code>{date}</code> 日期 · <code>{time}</code> 时间 · <code>{datetime}</code> 日期时间 · <code>{random}</code> 随机 · <code>{uuid}</code> 唯一 ID<br>
                斜杠 <code>/</code> 可做多级目录，示例：<code>{date}/images</code> → <code>20260829/images</code>
            </div>
        </div>
    </div>

    <div class="field-group">
        <div class="field-group-title">🔐 安全设置已迁出</div>
        <p style="color:var(--gray-600); font-size:13px; padding:8px 12px; background:var(--gray-50); border-radius:6px;">
            💡 会话超时、登录锁定、密码强度、2FA 颁发者 等安全策略已迁到 <a href="<?= base_url('security/policy') ?>" style="color:var(--primary); font-weight:600;">安全策略</a> 页面（仅管理员可见）。
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">💾 保存设置</button>
    </div>
</form>
<script>
(function () {
    var sel = document.getElementById('rename-rule-select');
    var grp = document.getElementById('custom-format-group');
    var dirSel = document.getElementById('dir-rule-select');
    var dirGrp = document.getElementById('dir-custom-group');
    function toggle() {
        if (sel && grp) grp.style.display = sel.value === 'custom' ? '' : 'none';
        if (dirSel && dirGrp) dirGrp.style.display = dirSel.value === 'custom' ? '' : 'none';
    }
    if (sel) sel.addEventListener('change', toggle);
    if (dirSel) dirSel.addEventListener('change', toggle);
    toggle();
})();
</script>

<!-- 存储扫描与清理 -->
<div class="card" style="margin-top:24px;">
    <div class="card-title">
        <span>🧹 存储扫描与清理</span>
        <span id="scan-stats" style="font-size:13px; font-weight:normal; color:var(--gray-500);"></span>
    </div>
    <p style="color:var(--gray-600); font-size:13px; margin-bottom:16px; line-height:1.6;">
        扫描 <code>public/&lt;前缀&gt;/</code> 下的所有图片文件，对比数据库：
        <br>· <strong>孤儿文件</strong>（磁盘有但数据库没记录）→ 一键清理物理文件
        <br>· <strong>孤儿记录</strong>（数据库有但磁盘没文件）→ 一键移到回收站
    </p>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" id="btn-scan" class="btn-primary">🔍 扫描</button>
        <button type="button" id="btn-cleanup" class="btn-primary" style="background:var(--red-500);" disabled title="先点击「🔍 扫描」检查">🗑️ 清理孤儿文件</button>
        <button type="button" id="btn-cleanup-records" class="btn-primary" style="background:var(--amber-500);" disabled title="先点击「🔍 扫描」检查">⚠️ 清理孤儿记录</button>
    </div>

    <div id="scan-result" style="margin-top:16px; display:none;"></div>
</div>

<script src="/assets/settings-scan.js"></script>