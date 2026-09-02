/**
 * FreeImg 上传脚本（roim-picx 风格：批量结果 + Tab 切换）
 *
 * 流程：
 *  1. 选图 / 拖拽 / 粘贴
 *  2. 浏览器压缩（5 档）
 *  3. 显示待上传预览
 *  4. 点"立即上传"按钮 → 批量上传
 *  5. 上传成功 → 整个结果区域出现
 *     Tab 1：预览（每张图大缩略图 + 4 个格式输入框）
 *     Tab 2：HTML（批量所有图）
 *     Tab 3：Markdown（批量）
 *     Tab 4：BBCode（批量）
 *     Tab 5：Link（批量 URL）
 *     Tab 6：删除链接（每个图独立删除 URL）
 *  6. 每个 Tab 右上有"复制"按钮，一键复制整个批量
 */

(function () {
    'use strict';

    const QUALITY_PRESETS = {
        original: { maxDim: 0,    quality: 1.0,  mime: 'image/jpeg' },
        high:     { maxDim: 2048, quality: 0.85, mime: 'image/jpeg' },
        balanced: { maxDim: 1600, quality: 0.70, mime: 'image/jpeg' },
        saver:    { maxDim: 1200, quality: 0.55, mime: 'image/jpeg' },
        extreme:  { maxDim: 900,  quality: 0.40, mime: 'image/jpeg' },
    };

    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file-input');
    const qualitySelect = document.getElementById('quality-select');
    const pendingList = document.getElementById('pending-list');
    const resultList = document.getElementById('result-list');
    const resultItems = document.getElementById('result-items');
    const uploadActions = document.getElementById('upload-actions');
    const uploadNowBtn = document.getElementById('upload-now-btn');
    const clearPendingBtn = document.getElementById('clear-pending-btn');
    const clearResultsBtn = document.getElementById('clear-results');
    const csrfInput = document.getElementById('freeimg-csrf');
    // v1.3.8: 改用 FREEIMG_BASE 替代 window.location.origin，支持子目录部署
    const FREEIMG_BASE = ((csrfInput && csrfInput.dataset.base) || window.FREEIMG_BASE || '').replace(/\/+$/, '');
    const urlPathPrefix = (csrfInput && csrfInput.dataset.prefix) || 'img';
    const customPathInput = document.getElementById('custom-path');
    const publicCheckbox = document.getElementById('is-public');
    // Phase 9.3: 浏览器上传压缩模式（double=双重 / browser=仅浏览器 / backend=仅后端）
    const browserMode = (document.getElementById('browser-mode') || {}).value || 'browser';
    const isBackendOnly = browserMode === 'backend';
    const keepNameCheckbox = document.getElementById('keep-name-checkbox');
    const dirSuggestions = document.getElementById('dir-suggestions');
    const enableExpiryCheckbox = document.getElementById('enable-expiry-checkbox');
    const expireTimeInput = document.getElementById('expire-time');
    const tagsInput = document.getElementById('tags-input');
    const tagsContainer = document.getElementById('tags-container');

    if (!dropzone || !fileInput || !csrfInput) {
        console.error('FreeImg: 必需元素缺失');
        return;
    }

    const pending = [];
    // v1.3.8: dirty 标记 — 用户改了 quality 后才传 quality，后端才能区分「默认」和「显式选」
    let qualityDirty = false;
    const uploaded = [];   // { id, url, name, size, delToken? }

    // === 拖拽 / 点击 / 粘贴 ===
    dropzone.addEventListener('click', () => fileInput.click());
    ['dragenter', 'dragover'].forEach(ev => {
        dropzone.addEventListener(ev, e => {
            e.preventDefault();
            dropzone.classList.add('dragging');
        });
    });
    ['dragleave', 'drop'].forEach(ev => {
        dropzone.addEventListener(ev, e => {
            e.preventDefault();
            dropzone.classList.remove('dragging');
        });
    });
    dropzone.addEventListener('drop', e => {
        const files = Array.from(e.dataTransfer.files || []).filter(f => f.type.startsWith('image/'));
        if (files.length) addFiles(files);
    });
    fileInput.addEventListener('change', () => {
        const files = Array.from(fileInput.files || []);
        if (files.length) addFiles(files);
        fileInput.value = '';
    });
    document.addEventListener('paste', e => {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        const files = [];
        for (const item of items) {
            if (item.kind === 'file' && item.type.startsWith('image/')) {
                files.push(item.getAsFile());
            }
        }
        if (files.length) addFiles(files);
    });

    // === 待上传预览 ===
    async function addFiles(files) {
        for (const file of files) {
            const item = createPendingItem(file);
            // 保存原文件 + 当前档位
            item.originalFile = file;
            item.currentPreset = qualitySelect.value;
            pending.push(item);
            pendingList.appendChild(item.root);
            item.origSizeEl.textContent = formatSize(file.size);
            item.statusEl.textContent = '⏳ 压缩中…';
            item.statusEl.className = 'pending-status';
            // 立刻用当前档位压缩一次（用于显示预览）
            await recomputeItem(item, item.currentPreset);
            updateStats();
        }
    }

    /**
     * 重新压缩单个 item（切换档位时调用）
     */
    async function recomputeItem(item, preset) {
        item.currentPreset = preset;
        // 浏览器压缩模式（browser/double）走前端 QUALITY_PRESETS
        // 后端压缩模式（double/backend）用 preset code 调后端 API，按 DB compression_profiles 执行
        const presetCfg = QUALITY_PRESETS[preset] || QUALITY_PRESETS.balanced;
        try {
            // Phase 9.3: 仅后端压缩模式 → 前端不压缩，原图直传
            if (isBackendOnly) {
                item.compressed = {
                    blob: item.originalFile,
                    size: item.originalFile.size,
                    dataUrl: null,
                };
                item.preview.style.display = 'none';
                item.statusEl.textContent = '✓ 原图（后端压缩）';
                item.statusEl.className = 'pending-status ready';
            } else if (presetCfg.maxDim === 0 && presetCfg.quality >= 1.0) {
                // 原图：不压缩
                item.compressed = {
                    blob: item.originalFile,
                    size: item.originalFile.size,
                    dataUrl: null,
                };
                item.preview.style.display = 'none';
                item.statusEl.textContent = '✓ 原图（未压缩）';
                item.statusEl.className = 'pending-status ready';
            } else {
                const compressed = await compressImage(item.originalFile, presetCfg);
                item.compressed = compressed;
                item.preview.src = compressed.dataUrl;
                item.preview.style.display = 'block';
                item.statusEl.textContent = '✓ 压缩完成';
                item.statusEl.className = 'pending-status ready';
            }
            item.compSizeEl.textContent = formatSize(item.compressed.size);
            item.savedEl.textContent = item.originalFile.size > 0
                ? ((1 - item.compressed.size / item.originalFile.size) * 100).toFixed(1) + '%'
                : '0%';
        } catch (err) {
            item.statusEl.textContent = '❌ 压缩失败';
            item.statusEl.className = 'pending-status error';
            item.compressed = null;
        }
    }

    /**
     * 档位切换：重新压缩所有 pending 项
     */
    if (qualitySelect) {
        qualitySelect.addEventListener('change', async () => {
            qualityDirty = true;
            const newPreset = qualitySelect.value;
            for (const item of pending) {
                if (item.currentPreset !== newPreset) {
                    item.statusEl.textContent = '⏳ 重算中…';
                    item.statusEl.className = 'pending-status';
                    await recomputeItem(item, newPreset);
                }
            }
            updateStats();
        });
    }

    // === 上传按钮 ===
    uploadNowBtn.addEventListener('click', async () => {
        if (!pending.length) return;
        uploadNowBtn.disabled = true;
        uploadNowBtn.textContent = '⏳ 上传中…';

        for (const item of pending) {
            if (!item.compressed) continue;
            try {
                item.statusEl.textContent = '⬆️ 上传中…';
                item.statusEl.className = 'pending-status uploading';
                const res = await uploadOne(item.compressed.blob, item.originalFile.name.replace(/\.[^.]+$/, '.jpg'), item.progressBar, item.originalFile.size);
                item.statusEl.textContent = '✅ 完成';
                item.statusEl.className = 'pending-status success';
                if (res && res.image) {
                    uploaded.push({
                        id: res.image.id,
                        url: res.image.public_url,
                        name: res.image.original_name,
                        origSize: item.originalFile.size,
                        finalSize: res.image.final_size || res.image.finalSize || 0,
                        saved: res.image.final_size && item.originalFile.size > 0 ? ((1 - res.image.final_size / item.originalFile.size) * 100).toFixed(1) : '0',
                    });
                }
            } catch (err) {
                item.statusEl.textContent = '❌ ' + err.message;
                item.statusEl.className = 'pending-status error';
            }
        }

        uploadNowBtn.disabled = false;
        uploadNowBtn.textContent = '📤 立即上传';
        pending.length = 0;
        pendingList.innerHTML = '';
        updateStats();
        renderResults();
    });

    clearPendingBtn.addEventListener('click', () => {
        pending.length = 0;
        pendingList.innerHTML = '';
        updateStats();
    });

    clearResultsBtn.addEventListener('click', () => {
        uploaded.length = 0;
        renderResults();
    });

    // === 浏览器压缩 ===
    function compressImage(file, preset) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            const reader = new FileReader();
            reader.onload = e => { img.src = e.target.result; };
            reader.onerror = () => reject(new Error('读取失败'));
            img.onload = () => {
                try {
                    const w0 = img.naturalWidth, h0 = img.naturalHeight;
                    let w = w0, h = h0;
                    if (preset.maxDim > 0 && (w0 > preset.maxDim || h0 > preset.maxDim)) {
                        const ratio = w0 / h0;
                        if (w0 > h0) { w = preset.maxDim; h = Math.round(w / ratio); }
                        else { h = preset.maxDim; w = Math.round(h * ratio); }
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => {
                        if (!blob) return reject(new Error('canvas.toBlob 失败'));
                        resolve({ blob, size: blob.size, dataUrl: canvas.toDataURL(preset.mime, preset.quality) });
                    }, preset.mime, preset.quality);
                } catch (err) { reject(err); }
            };
            img.onerror = () => reject(new Error('图片加载失败'));
            reader.readAsDataURL(file);
        });
    }

    // === 单文件上传 ===
    function uploadOne(blob, filename, progressBar, originalSize) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('csrf_token', csrfInput.value);
            fd.append('files[]', blob, filename);
            // Phase 9.3: 浏览器上传压缩模式
            //  - browser: 前端已压缩 → 后端跳过（skip_compress=1）
            //  - double : 前端压缩后后端再压（不传 skip_compress）
            //  - backend: 原图直传，后端统一压缩（不传 skip_compress）
            if (browserMode === 'browser') {
                fd.append('skip_compress', '1');
            }
            // 把真正的原图大小传给后端（用于计算节省%）
            // 注意：backend 模式原图直传 → original_size 即实际大小，不传让后端用 $_FILES size
            if (browserMode !== 'backend' && originalSize && originalSize > 0) {
                fd.append('original_size', String(originalSize));
            }
            // v1.3.8: dirty 标记 — 只在用户显式改了 quality 时才传，否则让后端用 web 默认档
            if (qualityDirty) {
                fd.append('quality', qualitySelect.value);
            }
            fd.append('is_public', publicCheckbox && publicCheckbox.checked ? 1 : 0);
            const customPath = customPathInput ? customPathInput.value.trim() : '';
            if (customPath) fd.append('custom_path', customPath);
            // Phase 8: 手动选存储
            const storageSelect = document.getElementById('storage-select');
            if (storageSelect && storageSelect.value) {
                fd.append('storage_id', storageSelect.value);
            }
            if (keepNameCheckbox && keepNameCheckbox.checked) fd.append('keep_name', '1');
            const albumSelect = document.getElementById('album-select');
            if (albumSelect && albumSelect.value !== '0') fd.append('folder_id', albumSelect.value);

            const tags = window.__freeimgGetTags ? window.__freeimgGetTags() : '';
            if (tags) fd.append('tags', tags);
            const expireAt = window.__freeimgGetExpire ? window.__freeimgGetExpire() : '';
            if (expireAt) fd.append('expires_at', expireAt);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', FREEIMG_BASE + '/upload');
            xhr.upload.onprogress = e => {
                if (e.lengthComputable) {
                    const pct = (e.loaded / e.total) * 100;
                    const bar = progressBar;
                    if (bar) bar.style.width = pct.toFixed(1) + '%';
                }
            };
            xhr.onload = () => {
                try {
                    if (xhr.status !== 200) {
                        reject(new Error('服务器错误 HTTP ' + xhr.status + '（可能是图片已存在但被删除，或文件过大）'));
                        return;
                    }
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        const imgData = data.image || (data.results && data.results[0] && data.results[0].image);
                        resolve(imgData ? { image: imgData } : data);
                    } else {
                        reject(new Error(data.message || '上传失败'));
                    }
                } catch (e) { reject(new Error('返回数据格式错误：' + (xhr.responseText || '空').slice(0, 100))); }
            };
            xhr.onerror = () => reject(new Error('网络错误'));
            xhr.send(fd);
        });
    }

    // === 渲染结果（roim-picx Tab 风格）===
    function renderResults() {
        if (uploaded.length === 0) {
            resultList.style.display = 'none';
            resultItems.innerHTML = '';
            return;
        }
        resultList.style.display = 'block';

        // 生成 4 种格式批量
        const htmlAll = uploaded.map(it => `<a href="${it.url}" target="_blank"><img src="${it.url}"></a>`).join('\n');
        const mdAll = uploaded.map(it => `![${it.name}](${it.url})`).join('\n');
        const bbAll = uploaded.map(it => `[img]${it.url}[/img]`).join('\n');
        const linkAll = uploaded.map(it => it.url).join('\n');

        resultItems.innerHTML = `
            <div class="result-tabs" id="result-tabs">
                <button class="tab active" data-tab="preview">预览 (${uploaded.length})</button>
                <button class="tab" data-tab="html">HTML</button>
                <button class="tab" data-tab="markdown">Markdown</button>
                <button class="tab" data-tab="bbcode">BBCode</button>
                <button class="tab" data-tab="link">Link</button>
            </div>
            <div class="result-panes">
                <div class="result-pane active" data-pane="preview">
                    ${uploaded.map(it => `
                        <div class="result-image-card">
                            <div class="result-image-preview">
                                <img src="${it.url}" alt="${escapeAttr(it.name)}">
                            </div>
                            <div class="result-image-info">
                                <div class="result-image-name">${escapeHtml(it.name)} <button type="button" class="del-one-btn" data-id="${it.id}" style="float:right; background:var(--red-500); color:white; border:none; border-radius:4px; padding:2px 8px; font-size:11px; cursor:pointer;">🗑️ 删除</button></div>
                                <div class="result-image-stats">
                                    <span>${formatSize(it.origSize)} → <strong>${formatSize(it.finalSize)}</strong></span>
                                    <span class="saved">↓ ${it.saved}%</span>
                                </div>
                                <div class="result-link-row">
                                    <label>URL</label>
                                    <div class="copy-row">
                                        <input type="text" readonly value="${escapeAttr(it.url)}">
                                        <button type="button" class="copy-btn" data-copy="${escapeAttr(it.url)}">复制</button>
                                    </div>
                                </div>
                                <div class="result-link-row">
                                    <label>HTML</label>
                                    <div class="copy-row">
                                        <input type="text" readonly value='&lt;a href=&quot;${escapeAttr(it.url)}&quot; target=&quot;_blank&quot;&gt;&lt;img src=&quot;${escapeAttr(it.url)}&quot;&gt;&lt;/a&gt;'>
                                        <button type="button" class="copy-btn" data-copy='&lt;a href=&quot;${escapeAttr(it.url)}&quot; target=&quot;_blank&quot;&gt;&lt;img src=&quot;${escapeAttr(it.url)}&quot;&gt;&lt;/a&gt;'>复制</button>
                                    </div>
                                </div>
                                <div class="result-link-row">
                                    <label>Markdown</label>
                                    <div class="copy-row">
                                        <input type="text" readonly value="![${escapeAttr(it.name)}](${escapeAttr(it.url)})">
                                        <button type="button" class="copy-btn" data-copy="![${escapeAttr(it.name)}](${escapeAttr(it.url)})">复制</button>
                                    </div>
                                </div>
                                <div class="result-link-row">
                                    <label>BBCode</label>
                                    <div class="copy-row">
                                        <input type="text" readonly value="[img]${escapeAttr(it.url)}[/img]">
                                        <button type="button" class="copy-btn" data-copy="[img]${escapeAttr(it.url)}[/img]">复制</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
                <div class="result-pane" data-pane="html">
                    <div class="code-block">${escapeHtml(htmlAll)}<button class="copy-btn copy-btn-float" data-copy="${escapeAttr(htmlAll)}">复制全部</button></div>
                </div>
                <div class="result-pane" data-pane="markdown">
                    <div class="code-block">${escapeHtml(mdAll)}<button class="copy-btn copy-btn-float" data-copy="${escapeAttr(mdAll)}">复制全部</button></div>
                </div>
                <div class="result-pane" data-pane="bbcode">
                    <div class="code-block">${escapeHtml(bbAll)}<button class="copy-btn copy-btn-float" data-copy="${escapeAttr(bbAll)}">复制全部</button></div>
                </div>
                <div class="result-pane" data-pane="link">
                    <div class="code-block">${escapeHtml(linkAll)}<button class="copy-btn copy-btn-float" data-copy="${escapeAttr(linkAll)}">复制全部</button></div>
                </div>
            </div>
        `;

        // 单图删除按钮
        resultItems.querySelectorAll('.del-one-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                if (!id) return;
                if (!confirm('确认删除这张图？\n文件将从磁盘删除，不可恢复！')) return;
                btn.disabled = true;
                btn.textContent = '⏳';
                try {
                    const fd = new FormData();
                    fd.append('csrf_token', csrfInput.value);
                    const res = await fetch(FREEIMG_BASE + '/images/' + id + '/destroy', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });
                    const data = await res.json();
                    if (data.success) {
                        // 从 uploaded 数组移除
                        const idx = uploaded.findIndex(u => u.id == id);
                        if (idx >= 0) uploaded.splice(idx, 1);
                        renderResults();
                    } else {
                        alert(data.message || '删除失败');
                        btn.disabled = false;
                        btn.textContent = '🗑️ 删除';
                    }
                } catch (err) {
                    alert('删除失败：' + err.message);
                    btn.disabled = false;
                    btn.textContent = '🗑️ 删除';
                }
            });
        });

        // Tab 切换
        resultItems.querySelectorAll('.result-tabs .tab').forEach(tab => {
            tab.addEventListener('click', () => {
                resultItems.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                resultItems.querySelectorAll('.result-pane').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                resultItems.querySelector(`.result-pane[data-pane="${tab.dataset.tab}"]`).classList.add('active');
            });
        });

        // 复制按钮
        resultItems.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const text = btn.dataset.copy || '';
                copyToClipboard(text, btn);
            });
        });
    }

    function copyToClipboard(text, btn) {
        try {
            // 现代 API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                // 降级：临时 textarea
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            const orig = btn.textContent;
            btn.textContent = '✓ 已复制';
            btn.style.background = 'var(--green-500)';
            setTimeout(() => { btn.textContent = orig; btn.style.background = ''; }, 1200);
        } catch (e) {
            alert('复制失败：' + e.message);
        }
    }

    function createPendingItem(file) {
        const root = document.createElement('div');
        root.className = 'pending-item';
        root.innerHTML = `
            <img class="pending-preview" style="display:none">
            <div class="pending-info">
                <div class="pending-name"></div>
                <div class="pending-sizes">
                    原: <span class="pending-orig"></span>
                    → 压缩后: <span class="pending-comp"></span>
                    (<span class="pending-saved"></span>)
                </div>
                <div class="pending-progress"><div class="pending-progress-bar"></div></div>
                <div class="pending-status">⏳ 准备中…</div>
            </div>
        `;
        root.querySelector('.pending-name').textContent = file.name;
        return {
            root,
            originalFile: file,
            compressed: null,
            preview: root.querySelector('.pending-preview'),
            origSizeEl: root.querySelector('.pending-orig'),
            compSizeEl: root.querySelector('.pending-comp'),
            savedEl: root.querySelector('.pending-saved'),
            statusEl: root.querySelector('.pending-status'),
            progressBar: root.querySelector('.pending-progress-bar'),
        };
    }

    function updateStats() {
        const readyItems = pending.filter(p => p.compressed);
        let origTotal = 0, compTotal = 0;
        pending.forEach(it => { origTotal += it.originalFile.size; if (it.compressed) compTotal += it.compressed.size; });
        const saved = origTotal > 0 ? ((origTotal - compTotal) / origTotal * 100).toFixed(1) : '0';
        document.getElementById('stat-count').textContent = pending.length + ' 张';
        document.getElementById('stat-orig').textContent = formatSize(origTotal);
        document.getElementById('stat-comp').textContent = formatSize(compTotal);
        document.getElementById('stat-saved').textContent = saved + '%';
        if (readyItems.length > 0) {
            uploadActions.style.display = 'flex';
            uploadNowBtn.textContent = `📤 立即上传 (${readyItems.length} 张)`;
        } else {
            uploadActions.style.display = 'none';
        }
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1024 / 1024).toFixed(2) + ' MB';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function escapeAttr(s) {
        return String(s).replace(/"/g, '&quot;');
    }

    // 配置折叠
    const toggleBtn = document.getElementById('toggle-config');
    const configWrapper = document.getElementById('config-wrapper');
    const configLabel = document.getElementById('config-toggle-label');
    if (toggleBtn && configWrapper) {
        toggleBtn.addEventListener('click', () => {
            configWrapper.classList.toggle('is-collapsed');
            configLabel.textContent = configWrapper.classList.contains('is-collapsed') ? '展开配置' : '隐藏配置';
        });
    }

    // 目录建议下拉 → 填到输入框
    if (dirSuggestions && customPathInput) {
        dirSuggestions.addEventListener('change', () => {
            if (dirSuggestions.value) customPathInput.value = dirSuggestions.value;
        });
    }

    // 页面加载时拉取 img/ 下的真实子目录（从后端 /api/storage/dirs）
    async function loadDirs() {
        try {
            const res = await fetch(FREEIMG_BASE + '/api/storage/dirs?prefix=' + encodeURIComponent(urlPathPrefix), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data.success && Array.isArray(data.dirs)) {
                // 重建下拉
                dirSuggestions.innerHTML = '<option value="">📁 根目录（无子目录）</option>';
                data.dirs.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d;
                    opt.textContent = '📁 ' + d;
                    dirSuggestions.appendChild(opt);
                });
            }
        } catch (e) { /* 静默失败 */ }
    }
    loadDirs();

    // 过期销毁开关 → 显示/隐藏日期
    if (enableExpiryCheckbox && expireTimeInput) {
        enableExpiryCheckbox.addEventListener('change', () => {
            expireTimeInput.style.display = enableExpiryCheckbox.checked ? 'block' : 'none';
            if (enableExpiryCheckbox.checked && !expireTimeInput.value) {
                const d = new Date();
                d.setDate(d.getDate() + 7);
                expireTimeInput.value = d.toISOString().slice(0, 16);
            }
        });
    }

    // 标签输入：回车或逗号添加
    const currentTags = [];
    function renderTags() {
        if (!tagsContainer) return;
        tagsContainer.querySelectorAll('.tag-chip').forEach(c => c.remove());
        currentTags.forEach(tag => {
            const chip = document.createElement('span');
            chip.className = 'tag-chip';
            chip.innerHTML = tag + '<button type="button" data-tag="' + tag + '">×</button>';
            chip.querySelector('button').addEventListener('click', () => {
                const idx = currentTags.indexOf(tag);
                if (idx >= 0) currentTags.splice(idx, 1);
                renderTags();
            });
            tagsContainer.insertBefore(chip, tagsInput);
        });
    }
    if (tagsInput) {
        tagsInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const v = tagsInput.value.trim().replace(/,/g, '');
                if (v && !currentTags.includes(v)) {
                    currentTags.push(v);
                    renderTags();
                }
                tagsInput.value = '';
            } else if (e.key === 'Backspace' && !tagsInput.value) {
                currentTags.pop();
                renderTags();
            }
        });
    }
    window.__freeimgGetTags = () => currentTags.join(',');
    window.__freeimgGetExpire = () => enableExpiryCheckbox && enableExpiryCheckbox.checked && expireTimeInput ? expireTimeInput.value : '';
// === 相册选中状态提示（添加选中反馈） ===
    const albumHintEl = document.getElementById('album-hint');
    const albumSelectEl = document.getElementById('album-select');
    if (albumSelectEl && albumHintEl) {
        const updateHint = () => {
            const opt = albumSelectEl.options[albumSelectEl.selectedIndex];
            if (albumSelectEl.value === '0') {
                albumHintEl.textContent = '当前：未选择相册（图片将归入"图库"）';
                albumHintEl.style.color = '';
            } else {
                albumHintEl.textContent = '✓ 当前相册：' + (opt ? opt.text : '?') + '（上传将自动归入此相册）';
                albumHintEl.style.color = '#059669';
                albumHintEl.style.fontWeight = '600';
            }
        };
        albumSelectEl.addEventListener('change', updateHint);
        updateHint();
    }
})();