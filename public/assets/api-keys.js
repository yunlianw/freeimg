(function() {
    // 全局错误捕获
    window.addEventListener('error', function(e) {
        console.error('Global error:', e.message);
    });
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Unhandled rejection:', e.reason);
    });


    function toast(msg, type) {
        var t = document.getElementById('toast');
        if (!t) return;
        t.className = 'toast toast-' + (type || 'info') + ' toast-show';
        t.textContent = msg;
        clearTimeout(t._timer);
        t._timer = setTimeout(function() { t.className = 'toast'; }, 2500);
    }

    function copyToClipboard(text) {
        // 兼容旧浏览器
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // fallback
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch(e) {}
        document.body.removeChild(ta);
        return Promise.resolve();
    }

    // 通用 fetch + 错误处理
    async function apiPost(url, data) {
        var fd = new FormData();
        fd.append('csrf_token', window.FREEIMG_CSRF);
        for (var k in data) { if (data.hasOwnProperty(k)) fd.append(k, data[k]); }
        // 防御性：清理 URL 双斜杠（nginx 默认不 merge_slashes 会 404）
        var cleanBase = (window.FREEIMG_BASE || '').replace(/\/+$/, '');
        var cleanUrl = '/' + String(url || '').replace(/^\/+/, '');
        var fullUrl = cleanBase + cleanUrl;
        try {
            var r = await fetch(fullUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            var ct = r.headers.get('content-type') || '';
            var status = r.status;
            // 只解析 JSON，其他都视为错误并截断文本
            if (ct.indexOf('json') >= 0) {
                var d = await r.json();
                d.__status = status;
                return d;
            }
            // 非 JSON：多半是 404/302/HTML 错误页
            var txt = await r.text();
            console.error('Non-JSON response:', status, ct, txt.substring(0, 200));
            if (status === 404) return { success: false, ok: false, message: '接口不存在（404）：请检查页面是否过期，刷新重试' };
            if (status === 403) return { success: false, ok: false, message: '权限不足或会话过期（403），请刷新页面' };
            if (status === 302 || status === 401) return { success: false, ok: false, message: '会话过期，请重新登录' };
            // 截断 80 字符避免 HTML 整页塞 toast
            var shortTxt = txt.replace(/\s+/g, ' ').trim().substring(0, 80);
            return { success: false, ok: false, message: '服务器返回非 JSON（HTTP ' + status + '）：' + shortTxt };
        } catch (err) {
            console.error('fetch error:', err);
            return { success: false, ok: false, message: '网络错误：' + (err.message || err) };
        }
    }

    // ---- 复制按钮 ----
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-copy], [data-copy-el], [data-copy-target]');
        if (!btn) return;
        var text = '';
        if (btn.dataset.copy !== undefined) {
            text = btn.dataset.copy;
        } else if (btn.dataset.copyEl) {
            var el = document.getElementById(btn.dataset.copyEl);
            if (el) text = el.textContent;
        } else if (btn.dataset.copyTarget) {
            var el2 = document.getElementById(btn.dataset.copyTarget);
            if (el2) text = el2.textContent;
        }
        if (!text) return;
        copyToClipboard(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = '✅ 已复制';
            btn.classList.add('btn-copied');
            setTimeout(function() { btn.textContent = orig; btn.classList.remove('btn-copied'); }, 1500);
        }).catch(function(err) { toast('复制失败：' + err.message, 'error'); });
    });

    // ---- 折叠详情 ----
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-detail-toggle');
        if (!btn) return;
        var detail = document.getElementById(btn.dataset.detailId);
        if (!detail) return;
        var arrow = btn.querySelector('.detail-toggle-arrow');
        var text = btn.querySelector('.detail-toggle-text');
        if (detail.style.display === 'none') {
            detail.style.display = 'block';
            if (text) text.textContent = '收起调用方式';
            if (arrow) arrow.textContent = '▴';
        } else {
            detail.style.display = 'none';
            if (text) text.textContent = '展开调用方式';
            if (arrow) arrow.textContent = '▾';
        }
    });

    // ---- 启用/禁用 ----
    document.addEventListener('click', async function(e) {
        var btn = e.target.closest('.btn-toggle');
        if (!btn) return;
        e.preventDefault();
        btn.disabled = true;
        btn.style.opacity = '0.5';
        var d = await apiPost('/api-keys/toggle', { id: btn.dataset.id, action: btn.dataset.action });
        if (d.success) {
            toast('已' + (btn.dataset.action === 'activate' ? '启用' : '禁用'), 'success');
            setTimeout(function() { location.reload(); }, 600);
        } else {
            btn.disabled = false;
            btn.style.opacity = '';
            toast(d.message || '操作失败', 'error');
        }
    });

    // ---- 删除 ----
    document.addEventListener('click', async function(e) {
        var btn = e.target.closest('.btn-delete');
        if (!btn) return;
        e.preventDefault();
        if (!confirm('确认删除？此操作不可恢复！')) return;
        btn.disabled = true;
        btn.style.opacity = '0.5';
        var d = await apiPost('/api-keys/delete', { id: btn.dataset.id });
        if (d.success) {
            toast('已删除', 'success');
            setTimeout(function() { location.reload(); }, 600);
        } else {
            btn.disabled = false;
            btn.style.opacity = '';
            toast(d.message || '删除失败', 'error');
        }
    });

    // ---- 改名（失焦自动保存） ----
    document.addEventListener('blur', async function(e) {
        var input = e.target.closest('.key-name-input');
        if (!input) return;
        var card = input.closest('.key-card');
        if (!card) return;
        var id = card.dataset.keyId;
        var name = input.value.trim();
        if (!name) { toast('名称不能为空', 'error'); input.focus(); return; }
        var sel = card.querySelector('.key-profile-select');
        var d = await apiPost('/api-keys/edit', { id: id, name: name, compression_profile_id: sel ? sel.value : 0 });
        if (d.ok) {
            input.classList.add('input-saved');
            setTimeout(function() { input.classList.remove('input-saved'); }, 1000);
        } else {
            toast(d.message || '保存失败', 'error');
        }
    }, true);

    // ---- 改压缩预设 ----
    document.addEventListener('change', async function(e) {
        var sel = e.target.closest('.key-profile-select');
        if (!sel) return;
        var card = sel.closest('.key-card');
        if (!card) return;
        var id = card.dataset.keyId;
        var input = card.querySelector('.key-name-input');
        var d = await apiPost('/api-keys/edit', { id: id, name: input.value.trim(), compression_profile_id: sel.value });
        if (d.ok) {
            sel.classList.add('select-saved');
            setTimeout(function() { sel.classList.remove('select-saved'); }, 1000);
        } else {
            toast(d.message || '保存失败', 'error');
        }
    });

})();
