<div class="page-header">
    <div>
        <h1>上传图片</h1>
        <p class="subtitle">支持拖拽、粘贴或点击上传 · 单张限制 <?= h((string)config('settings.upload_max_size', 10)) ?> MB</p>
    </div>
    <button type="button" id="toggle-config" style="display:flex; align-items:center; gap:8px; padding:8px 16px; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-sm); font-size:13px; color:var(--gray-700); cursor:pointer;">
        ⚙️ <span id="config-toggle-label">隐藏配置</span>
    </button>
</div>

<input type="hidden" id="freeimg-csrf" value="<?= h($csrf) ?>" data-prefix="<?= h(config('settings.url_path_prefix') ?? 'img') ?>" data-base="<?= h(base_url()) ?>">

<!-- 上传配置区（roim-picx 风格：6 控件 3 列网格）-->
<div class="config-collapse-wrapper" id="config-wrapper">
    <div class="config-collapse-inner">
        <div class="config-grid">

            <!-- 1. 储存目录（下拉 + 输入）-->
            <div class="config-item">
                <label class="config-label">📁 储存目录</label>
                <div class="config-path-row">
                    <select id="dir-suggestions" class="config-select-small">
                        <option value="">📁 根目录（无子目录）</option>
                    </select>
                    <input type="text" id="custom-path" placeholder="或输入子目录" class="config-input">
                </div>
                <div class="config-hint">
                    路径：<code><?= h((string)config('settings.url_path_prefix', 'img')) ?>/&lt;这里填的&gt;</code>，默认前缀在后台「设置 → 存储」修改
                </div>
            </div>

            <!-- 2. 文件命名 -->
            <div class="config-item">
                <div class="config-switch-row">
                    <label class="switch">
                        <input type="checkbox" id="keep-name-checkbox">
                        <span class="switch-slider"></span>
                    </label>
                    <span class="switch-label">📝 文件命名</span>
                </div>
                <div class="config-hint">开启后使用原文件名（默认短文件名）</div>
            </div>

            <!-- 3. 过期销毁 -->
            <div class="config-item">
                <div class="config-switch-row">
                    <label class="switch">
                        <input type="checkbox" id="enable-expiry-checkbox">
                        <span class="switch-slider"></span>
                    </label>
                    <span class="switch-label">⏰ 过期销毁</span>
                </div>
                <div class="config-hint">开启后图片在指定时间自动删除</div>
                <input type="datetime-local" id="expire-time" class="config-input-small" style="display:none; margin-top:8px;">
            </div>

            <!-- 4. 图片压缩 -->
            <div class="config-item">
                <label class="config-label">📐 图片压缩</label>
                <?php
                $dq = $default_quality ?? 'saver';  // v1.3.9.1: 兜底 saver，跟 controller/UploadService/Installer 三方一致
                $bm = $browser_mode ?? 'browser';
                $browserPresets = $browser_presets ?? [];
                $serverProfiles = $server_profiles ?? [];
                ?>
                <select name="quality" id="quality-select" class="config-input">
                    <?php if ($bm === 'backend'): ?>
                        <?php foreach ($serverProfiles as $p): ?>
                            <?php
                                $dim = (int)($p['max_dimension'] ?? 0);
                                $qual = (int)($p['jpeg_quality'] ?? 0);
                                $sizeKb = (int)($p['target_size_kb'] ?? 0);
                                $sizeStr = $sizeKb > 0 ? ' · ≤' . round($sizeKb/1024, 1) . 'MB' : '';
                                $dimStr = $dim > 0 ? "{$dim}px" : '原尺寸';
                                $label = $p['name'] . ' (' . $dimStr . ' / q' . $qual . ')' . $sizeStr;
                                if ((int)($p['is_builtin'] ?? 0) === 0) $label .= ' · 自定义';
                            ?>
                            <option value="<?= h($p['code']) ?>" <?= $dq === $p['code'] ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($browserPresets as $p): ?>
                            <option value="<?= h($p['code']) ?>" <?= $dq === $p['code'] ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= h($p['desc']) ?>)</option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="config-hint">
                    <?php if ($bm === 'backend'): ?>
                        默认档来自后台「压缩配置 → Web 默认档」设置（后端执行压缩）
                    <?php elseif ($bm === 'double'): ?>
                        默认档来自后台「设置 → 默认压缩档位」（浏览器先压，后端再压，结果取字节数最小的）
                    <?php else: ?>
                        默认档来自后台「设置 → 默认压缩档位」（浏览器执行压缩）
                    <?php endif; ?>
                </div>
            </div>

            <!-- Phase 9.3: 浏览器上传压缩模式（后台可切换） -->
            <?php $bm = $browser_mode ?? 'browser'; ?>
            <input type="hidden" id="browser-mode" value="<?= h($bm) ?>">
            <div class="config-item" id="browser-mode-tip" style="display:none;">
                <div class="config-hint" style="color:var(--orange-500);">
                    🖥️ 当前模式：<?= $bm === 'double' ? '双重压缩（浏览器+后端）' : ($bm === 'backend' ? '仅后端压缩（原图直传）' : '仅浏览器压缩') ?>
                </div>
            </div>

            <?php $hasMultipleStorage = count($visible_storages ?? []) > 1; ?>
            <?php if ($hasMultipleStorage): ?>
            <div class="config-item">
                <label class="config-label">💾 存储位置</label>
                <select id="storage-select" name="storage_id" class="config-input">
                    <?php foreach ($visible_storages as $s): ?>
                        <?php
                            // Phase 9.3: current_usage_mb 已是真实 MB（可能为小数）
                            $used = (float)($s["current_usage_mb"] ?? 0);
                            $max = (float)$s['max_capacity_mb'];
                            $fmt = function (float $mb): string {
                                if ($mb >= 1024) return round($mb / 1024, 2) . 'GB';
                                if ($mb >= 1) return round($mb, 2) . 'MB';
                                return round($mb * 1024, 1) . 'KB';
                            };
                            $capStr = $max > 0 ? $fmt($max) : '∞';
                            $usedStr = $fmt($used);
                            $pct = $max > 0 ? min(100, (int)round($used / $max * 100)) : 0;
                            $full = !empty($s['is_full']);
                        ?>
                        <option value="<?= (int)$s['id'] ?>" <?= !empty($s['is_full']) ? 'disabled' : '' ?>>
                            <?= h($s['name']) ?> (<?= $s['driver'] ?>) - <?= $usedStr ?> / <?= $capStr ?> (<?= $pct ?>%)<?= $full ? ' ⚠️已满' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="config-hint">手动选择存储位置（默认按优先级）</div>
            </div>
            <?php else: ?>
                <input type="hidden" name="storage_id" value="<?= (int)($visible_storages[0]['id'] ?? 0) ?>">
            <?php endif; ?>

            <!-- 5. 上传到相册 -->
            <div class="config-item">
                <label class="config-label">📁 上传到相册</label>
                <select id="album-select" class="config-input">
                    <option value="0">无</option>
                    <?php foreach ($albums ?? [] as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="config-hint" id="album-hint">上传的图片会自动归入选择的相册</div>
            </div>

            <!-- 6. 标签 -->
            <div class="config-item">
                <label class="config-label">🏷️ 标签</label>
                <div id="tags-container" class="tags-container">
                    <input type="text" id="tags-input" placeholder="输入后按回车添加" class="config-input">
                </div>
                <div class="config-hint">例如：cover, 2024, wallpaper</div>
            </div>

            <!-- 7. 图片水印 -->
            <div class="config-item">
                <div class="config-switch-row">
                    <label class="switch">
                        <input type="checkbox" id="enable-watermark">
                        <span class="switch-slider"></span>
                    </label>
                    <span class="switch-label">💧 图片水印</span>
                </div>
                <div class="config-hint">开启后为上传图片添加水印（可在后台 → 设置 → 水印配置参数）</div>
            </div>

            <!-- 8. 访问权限 -->
            <div class="config-item">
                <div class="config-switch-row">
                    <label class="switch">
                        <input type="checkbox" id="is-public" checked>
                        <span class="switch-slider"></span>
                    </label>
                    <span class="switch-label">🌐 公开访问</span>
                </div>
                <div class="config-hint">任何人可访问直链（关闭后需鉴权）</div>
            </div>

        </div>
    </div>
</div>

<div class="upload-layout">
    <div>
        <div id="dropzone" class="dropzone">
            <div class="dropzone-inner">
                <div class="dropzone-icon">📥</div>
                <p class="title">拖拽图片到此处，或点击选择</p>
                <p class="hint">支持 <?= h(strtoupper(str_replace(',', ' / ', (string)config('settings.upload_allowed_types', 'jpg,jpeg,png,gif,webp,bmp')))) ?> · 浏览器自动压缩</p>
                <input type="file" id="file-input" multiple accept="image/*" hidden>
            </div>
        </div>

        <div id="pending-list" class="pending-list"></div>

        <div id="result-list" class="result-list" style="display:none;">
            <div class="result-header">
                <h3>✅ 上传结果</h3>
                <button type="button" id="clear-results" class="btn-link">清除全部</button>
            </div>
            <div id="result-items"></div>
        </div>
    </div>

    <div>
        <div class="upload-stats-card">
            <h3 style="font-size:14px; font-weight:600; color:var(--gray-900); margin-bottom:16px;">📊 上传统计</h3>
            <div class="stat-row">
                <span class="stat-row-label">待上传</span>
                <span class="stat-row-value" id="stat-count">0 张</span>
            </div>
            <div class="stat-row">
                <span class="stat-row-label">原始大小</span>
                <span class="stat-row-value" id="stat-orig">0 B</span>
            </div>
            <div class="stat-row">
                <span class="stat-row-label">压缩后</span>
                <span class="stat-row-value highlight" id="stat-comp">0 B</span>
            </div>
            <div class="stat-row">
                <span class="stat-row-label">节省</span>
                <span class="stat-row-value highlight" id="stat-saved">0%</span>
            </div>

            <div id="upload-actions" style="margin-top:16px; display:none; flex-direction:column; gap:8px;">
                <button type="button" id="upload-now-btn" class="btn-primary">📤 立即上传</button>
                <button type="button" id="clear-pending-btn" class="btn-link" style="text-align:center; padding:8px;">清空选择</button>
            </div>

            <div style="margin-top:16px; padding:12px; background:var(--primary-bg); border-radius:var(--radius-sm); font-size:12px; color:var(--primary-dark); line-height:1.6;">
                💡 <strong>提示</strong>：roim-picx 风格——先压缩预览，确认后点击"立即上传"才传输到服务器
            </div>
        </div>
    </div>
</div>

<script src="/assets/upload.js"></script>