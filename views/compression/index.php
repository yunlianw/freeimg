<?php if (!defined('FREEIMG_ROOT')) define('FREEIMG_ROOT', dirname(__DIR__, 2)); ?>
<div class="page-header">
    <div>
        <h1>⚙️ 压缩配置</h1>
        <p class="subtitle">管理压缩预设：尺寸上限、质量、目标大小；设置 Web / API 默认档位</p>
    </div>
</div>

<!-- 默认档位 -->
<div class="card" style="margin-bottom:20px;">
    <form method="POST" action="<?= base_url('compression/defaults') ?>" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="form-group" style="margin:0;">
            <label>🌐 Web 默认档位</label>
            <select name="web_default">
                <?php foreach ($profiles as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $webDefault === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= h($p['name']) ?><?= $p['enabled'] ? '' : '（已禁用）' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>🔑 API 默认档位</label>
            <select name="api_default">
                <?php foreach ($profiles as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $apiDefault === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= h($p['name']) ?><?= $p['enabled'] ? '' : '（已禁用）' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>🖥️ 浏览器上传压缩</label>
            <select name="browser_mode">
                <option value="double" <?= $browserMode === 'double' ? 'selected' : '' ?>>双重压缩（浏览器 + 后端）</option>
                <option value="browser" <?= $browserMode === 'browser' ? 'selected' : '' ?>>仅浏览器压缩</option>
                <option value="backend" <?= $browserMode === 'backend' ? 'selected' : '' ?>>仅后端压缩（原图直传）</option>
            </select>
        </div>
        <button type="submit" class="btn-primary">💾 保存默认档位</button>
    </form>
    <div style="font-size:12px; color:var(--gray-400); margin-top:8px;">
        🖥️ 浏览器上传压缩模式说明：
        <b>双重压缩</b> = 浏览器先压一遍 + 后端再按 Web 档位压一遍（体积最小，CPU 双倍）；
        <b>仅浏览器压缩</b> = 前端 canvas 压缩后直接存（速度快，体积一般）；
        <b>仅后端压缩</b> = 原图直传，后端统一按 Web 档位压（前端不耗性能）。
    </div>
</div>

<!-- 预设列表 -->
<div class="card" style="padding:0; overflow:hidden;">
    <table style="width:100%; border-collapse:collapse; font-size:14px;">
        <thead>
            <tr style="background:var(--gray-100); text-align:left;">
                <th style="padding:12px 16px;">名称</th>
                <th style="padding:12px 16px;">参数</th>
                <th style="padding:12px 16px;">状态</th>
                <th style="padding:12px 16px; text-align:right;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($profiles as $p): ?>
            <tr style="border-top:1px solid var(--gray-200);">
                <td style="padding:12px 16px;">
                    <strong><?= h($p['name']) ?></strong>
                    <?php if ($p['is_builtin']): ?>
                        <span class="tag-chip" style="background:var(--gray-200); color:var(--gray-600);">内置</span>
                    <?php endif; ?>
                    <?php if ($webDefault === (int)$p['id'] || $apiDefault === (int)$p['id']): ?>
                        <span class="tag-chip" style="background:var(--green-100); color:var(--green-700);">
                            <?= $webDefault === (int)$p['id'] ? '🌐 Web默认' : '' ?>
                            <?= $apiDefault === (int)$p['id'] ? '🔑 API默认' : '' ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($p['description'])): ?>
                        <div style="font-size:12px; color:var(--gray-500); margin-top:4px;"><?= h($p['description']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="padding:12px 16px; font-size:13px; color:var(--gray-600);">
                    <code>宽≤<?= (int)$p['max_dimension'] ?>px</code>
                    <code>JPEG q<?= (int)$p['jpeg_quality'] ?></code>
                    <code>WebP q<?= (int)$p['webp_quality'] ?></code>
                    <?php if ((int)$p['target_size_kb'] > 0): ?>
                        <code>目标≤<?= (int)$p['target_size_kb'] ?>KB</code>
                    <?php endif; ?>
                    <code>PNG <?= (int)$p['png_compression'] ?></code>
                    <code><?= $p['strip_metadata'] ? '去元数据' : '留元数据' ?></code>
                </td>
                <td style="padding:12px 16px;">
                    <?php if ($p['enabled']): ?>
                        <span style="color:var(--green-600);">🟢 启用</span>
                    <?php else: ?>
                        <span style="color:var(--gray-400);">⚪ 禁用</span>
                    <?php endif; ?>
                </td>
                <td style="padding:12px 16px; text-align:right; white-space:nowrap;">
                    <form method="POST" action="<?= base_url('compression/toggle') ?>" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn-link"><?= $p['enabled'] ? '禁用' : '启用' ?></button>
                    </form>
                    <?php if (!$p['is_builtin']): ?>
                        <button type="button" class="btn-link edit-profile-btn" data-id="<?= (int)$p['id'] ?>"
                            data-name="<?= h($p['name']) ?>"
                            data-desc="<?= h($p['description'] ?? '') ?>"
                            data-maxdim="<?= (int)$p['max_dimension'] ?>"
                            data-jpeg="<?= (int)$p['jpeg_quality'] ?>"
                            data-webp="<?= (int)$p['webp_quality'] ?>"
                            data-png="<?= (int)$p['png_compression'] ?>"
                            data-target="<?= (int)$p['target_size_kb'] ?>"
                            data-minq="<?= (int)$p['minimum_quality'] ?>"
                            data-strip="<?= (int)$p['strip_metadata'] ?>"
                            data-sort="<?= (int)$p['sort_order'] ?>">编辑</button>
                        <form method="POST" action="<?= base_url('compression/delete') ?>" style="display:inline;" class="delete-profile-form" data-name="<?= h($p['name']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btn-link" style="color:var(--red-500);">删除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 新建预设 -->
<div class="card" style="margin-top:20px;">
    <div class="card-title">➕ 新建自定义预设</div>
    <form method="POST" action="<?= base_url('compression/create') ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px 16px;">
            <div class="form-group">
                <label>名称 *</label>
                <input type="text" name="name" maxlength="64" required placeholder="如：朋友圈专用">
            </div>
            <div class="form-group">
                <label>描述</label>
                <input type="text" name="description" maxlength="255" placeholder="可选">
            </div>
            <div class="form-group">
                <label>最大边长 px</label>
                <input type="number" name="max_dimension" min="100" max="10000" value="1600">
            </div>
            <div class="form-group">
                <label>JPEG 质量</label>
                <input type="number" name="jpeg_quality" min="1" max="100" value="70">
            </div>
            <div class="form-group">
                <label>WebP 质量</label>
                <input type="number" name="webp_quality" min="1" max="100" value="70">
            </div>
            <div class="form-group">
                <label>PNG 压缩级别 0-9</label>
                <input type="number" name="png_compression" min="0" max="9" value="6">
            </div>
            <div class="form-group">
                <label>目标大小 KB（0=不限）</label>
                <input type="number" name="target_size_kb" min="0" max="10240" value="0">
            </div>
            <div class="form-group">
                <label>最低质量 %</label>
                <input type="number" name="minimum_quality" min="1" max="100" value="40">
            </div>
            <div class="form-group">
                <label>排序</label>
                <input type="number" name="sort_order" min="0" max="9999" value="0">
            </div>
            <div class="form-group" style="display:flex; align-items:flex-end; gap:12px;">
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal;">
                    <input type="checkbox" name="strip_metadata" value="1" checked> 去除元数据
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal;">
                    <input type="checkbox" name="enabled" value="1" checked> 创建后启用
                </label>
            </div>
        </div>
        <div style="margin-top:12px;">
            <button type="submit" class="btn-primary">✅ 创建预设</button>
        </div>
    </form>
</div>

<!-- 编辑弹层 -->
<div id="edit-modal" class="modal-mask" style="display:none;">
    <div class="modal">
        <h3 style="margin:0 0 16px;">✏️ 编辑预设 <span id="edit-title"></span></h3>
        <form method="POST" id="edit-form" action="<?= base_url('compression/update') ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" id="edit-id">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 16px;">
                <div class="form-group">
                    <label>名称</label>
                    <input type="text" name="name" id="edit-name" maxlength="64" required>
                </div>
                <div class="form-group">
                    <label>描述</label>
                    <input type="text" name="description" id="edit-desc" maxlength="255">
                </div>
                <div class="form-group">
                    <label>最大边长 px</label>
                    <input type="number" name="max_dimension" id="edit-maxdim" min="100" max="10000">
                </div>
                <div class="form-group">
                    <label>JPEG 质量</label>
                    <input type="number" name="jpeg_quality" id="edit-jpeg" min="1" max="100">
                </div>
                <div class="form-group">
                    <label>WebP 质量</label>
                    <input type="number" name="webp_quality" id="edit-webp" min="1" max="100">
                </div>
                <div class="form-group">
                    <label>PNG 压缩级别</label>
                    <input type="number" name="png_compression" id="edit-png" min="0" max="9">
                </div>
                <div class="form-group">
                    <label>目标大小 KB（0=不限）</label>
                    <input type="number" name="target_size_kb" id="edit-target" min="0" max="10240">
                </div>
                <div class="form-group">
                    <label>最低质量 %</label>
                    <input type="number" name="minimum_quality" id="edit-minq" min="1" max="100">
                </div>
                <div class="form-group">
                    <label>排序</label>
                    <input type="number" name="sort_order" id="edit-sort" min="0" max="9999">
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="strip_metadata" value="1" id="edit-strip"> 去除元数据
                    </label>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                <button type="button" class="btn-link" onclick="document.getElementById('edit-modal').style.display='none'">取消</button>
                <button type="submit" class="btn-primary">保存修改</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // 编辑弹层填充
    document.querySelectorAll('.edit-profile-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('edit-id').value = d.id;
            document.getElementById('edit-title').textContent = '「' + d.name + '」';
            document.getElementById('edit-name').value = d.name;
            document.getElementById('edit-desc').value = d.desc;
            document.getElementById('edit-maxdim').value = d.maxdim;
            document.getElementById('edit-jpeg').value = d.jpeg;
            document.getElementById('edit-webp').value = d.webp;
            document.getElementById('edit-png').value = d.png;
            document.getElementById('edit-target').value = d.target;
            document.getElementById('edit-minq').value = d.minq;
            document.getElementById('edit-sort').value = d.sort;
            document.getElementById('edit-strip').checked = d.strip === '1';
            document.getElementById('edit-modal').style.display = 'flex';
        });
    });
    // 删除确认
    document.querySelectorAll('.delete-profile-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('删除预设「' + form.getAttribute('data-name') + '」？')) {
                e.preventDefault();
            }
        });
    });
    // 点击遮罩关闭
    document.getElementById('edit-modal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });
})();
</script>
