/**
 * 存储扫描 + 清理按钮
 * - 每次操作前重新拿 CSRF（防止页面停留过久 token 过期）
 */

(function () {
    'use strict';

    const btnScan = document.getElementById('btn-scan');
    const btnCleanup = document.getElementById('btn-cleanup');
    const btnCleanupRecords = document.getElementById('btn-cleanup-records');
    const result = document.getElementById('scan-result');
    const stats = document.getElementById('scan-stats');
    const csrfInput = document.getElementById('freeimg-csrf');

    if (!btnScan) return;

    // v1.3.8: 改用 FREEIMG_BASE 替代 window.location.origin，支持子目录部署
    const FREEIMG_BASE = (window.FREEIMG_BASE || '').replace(/\/+$/, '');

    // 每次操作前从 server 拉取最新 CSRF（防止页面停留 token 过期）
    async function getFreshCsrf() {
        try {
            const res = await fetch(FREEIMG_BASE + '/api/csrf', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success && data.csrf_token) {
                if (csrfInput) csrfInput.value = data.csrf_token;
                return data.csrf_token;
            }
        } catch (err) {
            console.warn('获取 CSRF 失败：', err);
        }
        return csrfInput ? csrfInput.value : '';
    }

    btnScan.addEventListener('click', async () => {
        btnScan.disabled = true;
        btnScan.textContent = '🔍 扫描中…';
        result.style.display = 'none';
        result.innerHTML = '';

        try {
            const res = await fetch(FREEIMG_BASE + '/api/storage/scan', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (!data.success) {
                alert(data.message || '扫描失败');
                return;
            }
            renderScan(data);
        } catch (err) {
            alert('网络错误：' + err.message);
        } finally {
            btnScan.disabled = false;
            btnScan.textContent = '🔍 扫描';
        }
    });

    async function doCleanup({ url, btn, confirmMsg, label, needConfirmString }) {
        // 第一步：dry-run（不带 confirm），预览要删的
        btn.disabled = true;
        const originalText = btn.textContent;
        btn.textContent = '⏳ 预览中…';
        try {
            const csrf = await getFreshCsrf();
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            const res = await fetch(FREEIMG_BASE + url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (!data.success) {
                if (res.status === 419) {
                    alert('会话已过期，请刷新页面（Ctrl+F5）后重试');
                } else {
                    alert(data.message || '操作失败');
                }
                return;
            }

            // Cleanup 类操作（孤儿文件）需要双重确认
            if (data.dry_run) {
                const items = data.would_delete || [];
                if (items.length === 0) {
                    alert('✅ 预览：没有需要清理的文件');
                    return;
                }
                // 显示详细列表
                const preview = items.slice(0, 30).map(o => '📄 ' + o.path + ' (' + (o.size / 1024).toFixed(1) + ' KB)').join('\n');
                const more = items.length > 30 ? '\n... 还有 ' + (items.length - 30) + ' 个' : '';
                const userConfirm = prompt(
                    '⚠️ 即将删除 ' + items.length + ' 个孤儿文件（不可恢复）：\n\n' + preview + more +
                    '\n\n输入 "我确认删除" 以继续：'
                );
                if (userConfirm !== '我确认删除') {
                    alert('已取消');
                    return;
                }
                // 第二步：真删
                btn.textContent = '⏳ 删除中…';
                const csrf2 = await getFreshCsrf();
                const fd2 = new FormData();
                fd2.append('csrf_token', csrf2);
                fd2.append('confirm', 'I_UNDERSTAND');
                const res2 = await fetch(FREEIMG_BASE + url, {
                    method: 'POST',
                    body: fd2,
                    credentials: 'same-origin'
                });
                const data2 = await res2.json();
                if (!data2.success) {
                    alert(data2.message || '操作失败');
                    return;
                }
                alert('✅ 已删除 ' + data2.deleted + ' 个文件\n跳过 ' + data2.skipped + ' 个（DB有记录）');
                btnScan.click();
            } else {
                // 非 dry-run 操作（孤儿记录）
                alert('✅ ' + (data.message || '完成') + '\n处理：' + (data.deleted || 0) + ' 条');
                btnScan.click();
            }
        } catch (err) {
            alert('网络错误：' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    if (btnCleanup) {
        btnCleanup.addEventListener('click', () => doCleanup({
            url: '/api/storage/cleanup',
            btn: btnCleanup,
            label: '孤儿文件',
        }));
    }

    if (btnCleanupRecords) {
        btnCleanupRecords.addEventListener('click', () => doCleanup({
            url: '/api/storage/cleanup-records',
            btn: btnCleanupRecords,
            label: '孤儿记录',
        }));
    }

    function renderScan(data) {
        stats.textContent = '📁 ' + data.prefix + ' · 磁盘 ' + data.stats.files + ' 个 · DB ' + data.stats.db_total + ' 个';
        result.style.display = 'block';
        result.innerHTML = '';

        const wrap = document.createElement('div');
        wrap.style.cssText = 'background:var(--gray-50); padding:16px; border-radius:8px; font-size:13px;';

        // 统计卡片
        const statsHtml = `
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:8px; margin-bottom:16px;">
                <div style="background:white; padding:10px; border-radius:6px; text-align:center;">
                    <div style="color:var(--gray-500); font-size:11px;">磁盘图片</div>
                    <div style="font-size:18px; font-weight:700; color:var(--gray-900);">${data.stats.files}</div>
                </div>
                <div style="background:white; padding:10px; border-radius:6px; text-align:center;">
                    <div style="color:var(--gray-500); font-size:11px;">数据库记录</div>
                    <div style="font-size:18px; font-weight:700; color:var(--gray-900);">${data.stats.db_total}</div>
                </div>
                <div style="background:white; padding:10px; border-radius:6px; text-align:center;">
                    <div style="color:var(--gray-500); font-size:11px;">孤儿文件</div>
                    <div style="font-size:18px; font-weight:700; color:${data.stats.orphans > 0 ? 'var(--red-500)' : 'var(--gray-900)'};">${data.stats.orphans}</div>
                </div>
                <div style="background:white; padding:10px; border-radius:6px; text-align:center;">
                    <div style="color:var(--gray-500); font-size:11px;">孤儿记录</div>
                    <div style="font-size:18px; font-weight:700; color:${data.stats.missing > 0 ? 'var(--amber-500)' : 'var(--gray-900)'};">${data.stats.missing}</div>
                </div>
            </div>
        `;
        wrap.innerHTML = statsHtml;

        // 孤儿文件列表
        if (data.orphans.length > 0) {
            const head = document.createElement('div');
            head.innerHTML = '<strong style="color:var(--red-500);">🗑️ 孤儿文件</strong>（磁盘有，DB 没有）— 可清理：';
            wrap.appendChild(head);
            const list = document.createElement('div');
            list.style.cssText = 'margin-top:8px; max-height:200px; overflow-y:auto; background:white; border:1px solid var(--gray-200); border-radius:6px; padding:8px;';
            data.orphans.forEach(o => {
                const row = document.createElement('div');
                row.style.cssText = 'padding:4px 0; font-family:monospace; font-size:11px; border-bottom:1px dashed var(--gray-100);';
                row.textContent = '📄 ' + o.path + ' (' + (o.size / 1024).toFixed(1) + ' KB)';
                list.appendChild(row);
            });
            wrap.appendChild(list);
            if (btnCleanup) btnCleanup.disabled = false; btnCleanup.title = '';
        } else {
            if (btnCleanup) btnCleanup.disabled = true; btnCleanup.title = '扫描后无孤儿文件';
        }

        // 孤儿记录
        if (data.missing.length > 0) {
            const head = document.createElement('div');
            head.style.marginTop = '16px';
            head.innerHTML = '<strong style="color:var(--amber-500);">⚠️ 孤儿记录</strong>（DB 有，磁盘没文件）— 可清理：';
            wrap.appendChild(head);
            const list = document.createElement('div');
            list.style.cssText = 'margin-top:8px; max-height:200px; overflow-y:auto; background:white; border:1px solid var(--gray-200); border-radius:6px; padding:8px;';
            data.missing.forEach(m => {
                const row = document.createElement('div');
                row.style.cssText = 'padding:4px 0; font-family:monospace; font-size:11px; border-bottom:1px dashed var(--gray-100);';
                row.textContent = '🆔 #' + m.id + ' ' + m.storage_path + ' (' + m.original_name + ')';
                list.appendChild(row);
            });
            wrap.appendChild(list);
            if (btnCleanupRecords) btnCleanupRecords.disabled = false; btnCleanupRecords.title = '';
        } else {
            if (btnCleanupRecords) btnCleanupRecords.disabled = true; btnCleanupRecords.title = '扫描后无孤儿记录';
        }

        result.appendChild(wrap);
    }
})();