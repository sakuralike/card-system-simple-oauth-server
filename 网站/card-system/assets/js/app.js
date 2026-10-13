/**
 * 卡密提取系统 - 前端脚本
 */

document.addEventListener('DOMContentLoaded', function() {
    initCategorySelect();
    initDrawButton();
    initCopyButtons();
    initQueryForm();
});

// 分类选择
function initCategorySelect() {
    const cards = document.querySelectorAll('.category-card');
    const categoryInput = document.getElementById('selected-category');
    
    cards.forEach(card => {
        card.addEventListener('click', function() {
            if (document.getElementById('draw-btn')?.dataset.locked === '1') return;
            cards.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            if (categoryInput) {
                categoryInput.value = this.dataset.id;
            }
        });
    });
}

// 提取按钮
function initDrawButton() {
    const drawBtn = document.getElementById('draw-btn');
    if (!drawBtn) return;

    const categoryInput = document.getElementById('selected-category');
    const categories = document.querySelectorAll('.category-card');
    const statusEl = document.getElementById('draw-status');
    const storageKey = `card-draw-pending:${drawBtn.dataset.userId}`;
    let remaining = Number(document.getElementById('remaining-count')?.textContent || 0);
    let pending = null;
    let inFlight = false;
    let clientError = '';
    let statusMessage = '';

    try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored) {
            pending = JSON.parse(stored);
            if (!pending || !Number.isSafeInteger(pending.category_id) || pending.category_id <= 0 ||
                typeof pending.request_id !== 'string' || !/^[a-f0-9]{32}$/.test(pending.request_id)) {
                pending = null;
                throw new Error('Invalid pending request');
            }
            categoryInput.value = pending.category_id;
            categories.forEach(card => card.classList.toggle('active', Number(card.dataset.id) === pending.category_id));
        }
    } catch (error) {
        clientError = '无法读取待确认请求，请先查看提取记录并检查浏览器站点存储后刷新。';
    }

    if (!window.crypto?.getRandomValues) {
        clientError = '当前浏览器无法生成安全的提取请求，请使用支持的浏览器。';
    }

    function updateState() {
        let blockedReason = '';
        if (drawBtn.dataset.banned === '1') {
            blockedReason = '您的账号已被禁止提取';
        } else if (!categories.length) {
            blockedReason = '暂无开放的卡密分类';
        } else if (remaining <= 0) {
            blockedReason = '今日提取次数已用完';
        }

        const locked = inFlight || pending !== null;
        drawBtn.dataset.locked = locked ? '1' : '0';
        categories.forEach(card => card.setAttribute('aria-disabled', locked ? 'true' : 'false'));
        drawBtn.disabled = inFlight || (!pending && Boolean(clientError || blockedReason));
        drawBtn.innerHTML = inFlight ? '<span class="loading"></span> 提取中...' :
            (pending ? '重试本次提取' : '🎁 立即提取');
        if (statusEl) {
            statusEl.textContent = statusMessage || (pending ?
                '上次提取结果尚未确认，分类已锁定。请重试本次提取以恢复结果，不会重复扣次数。' :
                (clientError || blockedReason));
        }
    }

    function clearPending() {
        try {
            sessionStorage.removeItem(storageKey);
            pending = null;
            if (!Array.from(categories).some(card => card.dataset.id === String(categoryInput.value))) {
                categoryInput.value = categories[0]?.dataset.id || '0';
                categories.forEach(card => card.classList.toggle('active', card.dataset.id === categoryInput.value));
            }
            return true;
        } catch (error) {
            statusMessage = '本次结果已返回，但浏览器无法清理请求记录；重试仍使用原请求，请检查浏览器站点存储。';
            return false;
        }
    }

    updateState();

    drawBtn.addEventListener('click', async function() {
        if (this.disabled) return;

        if (!pending) {
            const categoryId = Number(categoryInput.value);
            if (!Number.isSafeInteger(categoryId) || categoryId <= 0) {
                statusMessage = '请选择开放的卡密分类';
                updateState();
                return;
            }
            try {
                const bytes = window.crypto.getRandomValues(new Uint8Array(16));
                const request = {
                    category_id: categoryId,
                    request_id: Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
                };
                sessionStorage.setItem(storageKey, JSON.stringify(request));
                pending = request;
            } catch (error) {
                clientError = '无法保存安全的提取请求，请检查浏览器站点存储后刷新；本次尚未发起提取。';
                statusMessage = '';
                updateState();
                return;
            }
        }

        const csrf = document.getElementById('csrf-token')?.value || '';
        inFlight = true;
        statusMessage = '正在确认本次提取，请勿关闭页面或切换分类。';
        updateState();

        try {
            const response = await fetch('api/draw.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    category_id: pending.category_id,
                    csrf_token: csrf,
                    request_id: pending.request_id
                })
            });

            if (response.status >= 500) throw new Error('Server error');
            const data = await response.json();
            if (response.status === 401 || response.status === 403) {
                statusMessage = '登录或安全验证已失效，请刷新并重新登录后重试；已保留本次请求，不会发起重复提取。';
                return;
            }
            if (!data || typeof data.success !== 'boolean') throw new Error('Invalid response');

            if (data.success === true) {
                if (!response.ok || data.request_id !== pending.request_id || !Array.isArray(data.cards) ||
                    !data.cards.length || !data.cards.every(card => card && Number.isSafeInteger(Number(card.id)) &&
                        Number(card.id) > 0 && typeof card.card_code === 'string') ||
                    !Number.isInteger(data.remaining) || data.remaining < 0) {
                    throw new Error('Incomplete result');
                }
                showDrawResult(data.cards, data.replayed === true);
                remaining = data.remaining;
                updateRemainingCount(remaining);
                if (clearPending()) statusMessage = '';
            } else {
                if (typeof data.message !== 'string') throw new Error('Invalid error response');
                if (Number.isInteger(data.remaining) && data.remaining >= 0) {
                    remaining = data.remaining;
                    updateRemainingCount(remaining);
                }
                if (data.message === '您的账号已被禁止提取') drawBtn.dataset.banned = '1';
                if (data.retryable === true) {
                    statusMessage = `${data.message}。请重试本次提取，分类已锁定，不会重复扣次数。`;
                } else if (clearPending()) {
                    statusMessage = data.message;
                }
            }
        } catch (error) {
            statusMessage = '提取结果尚未确认，已保留本次请求。请重试本次提取以恢复结果，不会重复扣次数；也可先查看提取记录。';
        } finally {
            inFlight = false;
            updateState();
        }
    });
}

// 显示提取结果
function showDrawResult(cards, replayed = false) {
    const resultContainer = document.getElementById('draw-result');
    if (!resultContainer) return;
    
    let html = `<div class="alert alert-success">${replayed ? '已恢复本次提取结果，不重复扣次数。' : '🎉 提取成功！消耗 1 次。'}共 ${cards.length} 张卡密。</div>`;
    
    cards.forEach(card => {
        html += `
            <div class="card-result">
                <div class="card-code" id="code-${Number(card.id)}">${escapeHtml(card.card_code)}</div>
                ${card.content ? `<div class="card-content">📝 ${escapeHtml(card.content)}</div>` : ''}
                <button class="btn btn-secondary btn-sm copy-btn" onclick="copyCode('code-${Number(card.id)}')">
                    📋 复制卡密
                </button>
            </div>
        `;
    });
    
    resultContainer.innerHTML = html;
    resultContainer.scrollIntoView({ behavior: 'smooth' });
}

// 更新剩余次数
function updateRemainingCount(count) {
    const el = document.getElementById('remaining-count');
    if (el) {
        el.textContent = count;
    }
}

// 复制功能
function initCopyButtons() {
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            if (targetId) {
                copyCode(targetId);
            }
        });
    });
}

function copyCode(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    const text = (el.textContent || el.innerText || '').trim();
    
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            showAlert('复制成功！', 'success');
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    
    try {
        document.execCommand('copy');
        showAlert('复制成功！', 'success');
    } catch (e) {
        showAlert('复制失败，请手动复制', 'error');
    }
    
    document.body.removeChild(textarea);
}

// 查询表单
function initQueryForm() {
    const form = document.getElementById('query-form');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const cardCode = document.getElementById('query-code').value.trim();
        const csrf = document.getElementById('csrf-token')?.value || '';
        
        if (!cardCode) {
            showAlert('请输入卡密', 'error');
            return;
        }
        
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<span class="loading"></span> 查询中...';
        
        try {
            const response = await fetch('api/query.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    card_code: cardCode,
                    csrf_token: csrf
                })
            });
            
            const data = await response.json();
            
            showQueryResult(data);
        } catch (error) {
            showAlert('网络错误，请重试', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '🔍 查询';
        }
    });
}

function showQueryResult(data) {
    const container = document.getElementById('query-result');
    if (!container) return;
    
    if (data.success && data.card) {
        container.innerHTML = `
            <div class="card-result">
                <div class="badge badge-success">已提取</div>
                <div class="card-code">${escapeHtml(data.card.card_code)}</div>
                ${data.card.content ? `<div class="card-content">📝 ${escapeHtml(data.card.content)}</div>` : ''}
                <div class="card-content">
                    📁 分类: ${escapeHtml(data.card.category_name || '默认分类')}<br>
                    🕐 提取时间: ${escapeHtml(data.card.used_at)}
                </div>
            </div>
        `;
    } else {
        container.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message || '未找到该卡密')}</div>`;
    }
}

// 消息提示
function showAlert(message, type = 'info') {
    // 移除现有提示
    document.querySelectorAll('.floating-alert').forEach(el => el.remove());
    
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} floating-alert`;
    alert.style.cssText = 'position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 300px; text-align: center;';
    alert.textContent = message;
    
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.3s';
        setTimeout(() => alert.remove(), 300);
    }, 3000);
}

// HTML转义
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
