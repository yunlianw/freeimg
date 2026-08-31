/**
 * FreeImg 图片列表 / 详情脚本
 * - 删除 / 恢复 / 永久删除
 * - 复制 URL
 */

(function () {
    'use strict';

    const csrf = window.FREEIMG_CSRF;
    const base = (window.FREEIMG_BASE || '').replace(/\/+$/, '');

    // === 删除到回收站 ===
    document.querySelectorAll('.btn-trash, .trash-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id || window.FREEIMG_ID;
            if (!id) return;
            if (!confirm('确定删除到回收站？')) return;
            post(id, '/trash', btn);
        });
    });

    // === 恢复 ===
    document.querySelectorAll('.btn-restore, .restore-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id || window.FREEIMG_ID;
            post(id, '/restore', btn, () => location.reload());
        });
    });

    // === 永久删除 ===
    document.querySelectorAll('.btn-destroy, .destroy-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id || window.FREEIMG_ID;
            if (!id) return;
            if (!confirm('永久删除？文件将被从磁盘删除，不可恢复！')) return;
            post(id, '/destroy', btn, () => {
                if (window.FREEIMG_ID) {
                    location.href = base + '/images';
                } else {
                    btn.closest('.image-card-wrapper').remove();
                }
            });
        });
    });

    // === 批量模式切换 ===
    const btnBatchMode = document.getElementById('btn-batch-mode');
    const batchBar = document.getElementById('batch-bar');
    const batchCount = document.getElementById('batch-count');
    const btnBatchCancel = document.getElementById('btn-batch-cancel');
    const btnBatchConfirm = document.getElementById('btn-batch-confirm');
    const batchSelectBar = document.getElementById('batch-select-bar');
    const batchSelectAll = document.getElementById('batch-select-all');
    const batchSelectTip = document.getElementById('batch-select-tip');
    const batchSelectAllPages = document.getElementById('batch-select-all-pages');
    const totalImages = parseInt(batchSelectTip?.textContent?.match(/共 (\d+) 张/)?.[1] || '0', 10);

    function isBatchMode() {
        return document.body.classList.contains('batch-mode');
    }
    function updateBatchCount() {
        const allChecks = document.querySelectorAll('.batch-select');
        const checked = document.querySelectorAll('.batch-select:checked');
        if (batchCount) batchCount.textContent = '已选 ' + checked.length + ' 张';
        if (btnBatchConfirm) btnBatchConfirm.disabled = checked.length === 0;
        // 更新"全选当前页"checkbox 状态
        if (batchSelectAll) {
            if (checked.length === 0) {
                batchSelectAll.checked = false;
                batchSelectAll.indeterminate = false;
            } else if (checked.length === allChecks.length) {
                batchSelectAll.checked = true;
                batchSelectAll.indeterminate = false;
            } else {
                batchSelectAll.checked = false;
                batchSelectAll.indeterminate = true;
            }
        }
    }
    if (btnBatchMode) {
        btnBatchMode.addEventListener('click', () => {
            document.body.classList.toggle('batch-mode');
            const on = isBatchMode();
            btnBatchMode.textContent = on ? '✕ 退出批量' : '☑️ 批量删除';
            if (batchBar) batchBar.style.display = on ? 'flex' : 'none';
            updateBatchCount();
        });
    }
    document.querySelectorAll('.batch-select').forEach(cb => {
        cb.addEventListener('change', updateBatchCount);
    });
    // 全选当前页
    if (batchSelectAll) {
        batchSelectAll.addEventListener('change', () => {
            const checked = batchSelectAll.checked;
            document.querySelectorAll('.batch-select').forEach(cb => cb.checked = checked);
            updateBatchCount();
        });
    }
    // 全选所有页（提示确认 + 显示当前已选 + 总数）
    if (batchSelectAllPages) {
        batchSelectAllPages.addEventListener('click', () => {
            if (!confirm('☑️ 勾选当前页所有图片（共 ' + totalImages + ' 张需跨页删除）？\n\n点击"删除选中"会先处理当前页，\n然后需要翻页到下一页重复操作。')) return;
            document.querySelectorAll('.batch-select').forEach(cb => cb.checked = true);
            updateBatchCount();
            alert('✅ 当前页已全部选中\n共 ' + totalImages + ' 张需跨页删除\n请翻页到下一页重复操作。');
        });
    }
    if (btnBatchCancel) {
        btnBatchCancel.addEventListener('click', () => {
            document.querySelectorAll('.batch-select').forEach(cb => cb.checked = false);
            updateBatchCount();
        });
    }
    if (btnBatchConfirm) {
        btnBatchConfirm.addEventListener('click', async () => {
            const ids = Array.from(document.querySelectorAll('.batch-select:checked')).map(cb => cb.value);
            if (ids.length === 0) return;
            if (!confirm('确认将选中的 ' + ids.length + ' 张图片移到回收站？')) return;
            btnBatchConfirm.disabled = true;
            btnBatchConfirm.textContent = '⏳ 处理中…';
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                ids.forEach(id => fd.append('ids[]', id));
                const baseClean = base.replace(/\/+$/, '');
                const res = await fetch(baseClean + '/images/batch-trash', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert(data.message || '失败');
                    btnBatchConfirm.disabled = false;
                    btnBatchConfirm.textContent = '🗑️ 删除选中';
                }
            } catch (err) {
                alert('网络错误：' + err.message);
                btnBatchConfirm.disabled = false;
                btnBatchConfirm.textContent = '🗑️ 删除选中';
            }
        });
    }

    // === 一键清空回收站 ===
    const btnEmptyRecycle = document.getElementById('btn-empty-recycle');
    if (btnEmptyRecycle) {
        btnEmptyRecycle.addEventListener('click', async () => {
            if (!confirm('⚠️ 确认清空回收站？\n\n所有图片将从磁盘永久删除，不可恢复！')) return;
            if (!confirm('真的确认？此操作不可撤销！')) return;
            btnEmptyRecycle.disabled = true;
            btnEmptyRecycle.textContent = '⏳ 清空中…';
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                const baseClean = base.replace(/\/+$/, '');
                const res = await fetch(baseClean + '/images/empty-recycle', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert(data.message || '失败');
                    btnEmptyRecycle.disabled = false;
                    btnEmptyRecycle.textContent = '🗑️ 一键清空回收站';
                }
            } catch (err) {
                alert('网络错误：' + err.message);
                btnEmptyRecycle.disabled = false;
                btnEmptyRecycle.textContent = '🗑️ 一键清空回收站';
            }
        });
    }

    function post(id, action, btn, onSuccess) {
        // 安全检查：csrf 和 base 必须在 window 上
        if (!csrf || !base) {
            console.error('FREEIMG_CSRF or FREEIMG_BASE not set', { csrf, base });
            alert('页面状态异常，请刷新页面');
            return;
        }

        const fd = new FormData();
        fd.append('csrf_token', csrf);

        // 关键：去掉 base 结尾的 /，避免 //images/10/destroy 双斜杠
        const baseClean = base.replace(/\/+$/, '');
        fetch(baseClean + '/images/' + id + action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse failed', text);
                        throw new Error('服务器返回非 JSON：' + text.slice(0, 100));
                    }
                });
            })
            .then(d => {
                if (d.success) {
                    if (onSuccess) onSuccess();
                    else if (btn.closest('.image-card-wrapper')) btn.closest('.image-card-wrapper').remove();
                    else location.reload();
                } else {
                    alert(d.message || '操作失败');
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                alert('网络错误：' + err.message + '\n请刷新页面重试');
            });
    }

    // === 复制文本 ===
    window.copyText = function (inputId, label) {
        const el = document.getElementById(inputId);
        if (!el) return;
        el.select();
        try {
            document.execCommand('copy');
            const orig = el.value;
            el.value = '✓ 已复制';
            setTimeout(() => el.value = orig, 1000);
        } catch (e) {
            alert('复制失败');
        }
    };
})();