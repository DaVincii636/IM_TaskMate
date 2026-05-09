/**
 * TM_JS/TM_Realtime.js
 * ──────────────────────────────────────────────────────────────
 * COLLABORATION & MULTI-USER — Change 5: Real-time collaboration
 *
 * Polling-based live-update client.
 * Drop this script on any page that should reflect live changes:
 *
 *   <script src="TM_JS/TM_Realtime.js"></script>
 *
 * Configuration (set before the <script> tag or in a preceding
 * inline script):
 *
 *   window.TM_RT_CONFIG = {
 *     pageType:  'dashboard' | 'tasks' | 'task_detail' | 'calendar',
 *     taskId:    <int>   // only on task_detail pages
 *     projectId: <int>   // only on project pages
 *     scope:     'mine' | 'shared' | 'project:<id>'   // poll scope
 *     interval:  <ms>   // default 5000
 *   };
 *
 * Events emitted on document (listen with addEventListener):
 *
 *   tm:tasks-updated   detail: { changes: [], deletes: [] }
 *   tm:comments-added  detail: { comments: [] }
 *   tm:online-changed  detail: { users: [] }
 */

(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────────────────────────
    const cfg = Object.assign(
        {
            pageType:  'dashboard',
            taskId:    null,
            projectId: null,
            scope:     'mine',
            interval:  5000,        // ms between polls
            endpoint:  'TM_PHP/TM_RealtimeActions.php',
        },
        window.TM_RT_CONFIG || {}
    );

    // Resolve endpoint relative to the current page location
    const BASE = (function () {
        const parts = window.location.pathname.split('/');
        parts.pop(); // remove filename
        return parts.join('/') + '/';
    })();
    const ENDPOINT = cfg.endpoint.startsWith('http')
        ? cfg.endpoint
        : BASE + cfg.endpoint;

    let lastTs        = null;  // ISO timestamp of last successful poll
    let pollTimer     = null;
    let heartbeatTimer = null;
    let isPolling     = false;

    // ── Helpers ─────────────────────────────────────────────────────────────
    function emit(eventName, detail) {
        document.dispatchEvent(new CustomEvent(eventName, { detail, bubbles: true }));
    }

    function post(params) {
        const body = new URLSearchParams(params);
        return fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            credentials: 'same-origin',
        }).then(r => r.json());
    }

    function get(params) {
        const qs = new URLSearchParams(params);
        return fetch(`${ENDPOINT}?${qs}`, {
            credentials: 'same-origin',
            headers: { 'Cache-Control': 'no-cache' },
        }).then(r => r.json());
    }

    // ── Heartbeat ────────────────────────────────────────────────────────────
    // Keeps the current user's row in TM_ActivePresence fresh so teammates
    // can see them as "online".
    function sendHeartbeat() {
        post({
            action:     'heartbeat',
            page_type:  cfg.pageType,
            task_id:    cfg.taskId    || '',
            project_id: cfg.projectId || '',
        }).catch(() => { /* silently ignore network errors */ });
    }

    // ── Poll ─────────────────────────────────────────────────────────────────
    function poll() {
        if (isPolling) return;
        isPolling = true;

        const params = {
            action: 'poll_changes',
            scope:  cfg.scope,
        };
        if (lastTs)       params.since   = lastTs;
        if (cfg.taskId)   params.task_id = cfg.taskId;

        get(params)
            .then(data => {
                if (!data || !data.ok) return;

                lastTs = data.server_ts; // advance the watermark

                // ── Task changes ───────────────────────────────────────────
                if ((data.changes && data.changes.length) ||
                    (data.deletes && data.deletes.length)) {
                    emit('tm:tasks-updated', {
                        changes: data.changes || [],
                        deletes: data.deletes || [],
                    });
                    applyTaskChangesToDOM(data.changes || [], data.deletes || []);
                }

                // ── New comments ──────────────────────────────────────────
                if (data.comments && data.comments.length) {
                    emit('tm:comments-added', { comments: data.comments });
                    appendNewComments(data.comments);
                }

                // ── Online users ──────────────────────────────────────────
                if (data.online) {
                    emit('tm:online-changed', { users: data.online });
                    renderOnlineBar(data.online);
                }
            })
            .catch(() => { /* silently swallow network errors */ })
            .finally(() => { isPolling = false; });
    }

    // ── DOM patches ──────────────────────────────────────────────────────────

    /**
     * applyTaskChangesToDOM
     * Patches the task list / dashboard tables in place without a full reload.
     * Works on:
     *   • TM_Dashboard.php  — .up-table  rows with data-task-id
     *   • TM_Tasks.php      — .tasks-table rows with data-task-id
     */
    function applyTaskChangesToDOM(changes, deletes) {
        // Remove deleted rows
        deletes.forEach(tid => {
            document.querySelectorAll(`[data-task-id="${tid}"]`).forEach(row => {
                row.style.transition = 'opacity .3s';
                row.style.opacity    = '0';
                setTimeout(() => row.remove(), 320);
            });
        });

        // Update changed rows
        changes.forEach(task => {
            const tid  = task.task_id;
            const rows = document.querySelectorAll(`[data-task-id="${tid}"]`);

            rows.forEach(row => {
                // Highlight the row briefly so the user notices the change
                row.classList.add('tm-rt-flash');
                setTimeout(() => row.classList.remove('tm-rt-flash'), 1200);

                // Patch status pill
                const statusPill = row.querySelector('.status-pill');
                if (statusPill) {
                    statusPill.className = 'status-pill ' + statusCssClass(task.status);
                    statusPill.textContent = statusLabel(task.status);
                }

                // Patch priority pill
                const priPill = row.querySelector('.pri-pill');
                if (priPill) {
                    priPill.className = 'pri-pill ' + priorityCssClass(task.priority);
                    priPill.textContent = priorityLabel(task.priority);
                }

                // Patch color dot
                const dot = row.querySelector('.color-dot');
                if (dot) dot.style.background = task.color || '#ef4444';

                // Patch task name cell
                const nameCell = row.querySelector('.task-name-cell');
                if (nameCell) {
                    // Update text node, preserving the color dot
                    const textNodes = [...nameCell.childNodes].filter(n => n.nodeType === 3);
                    if (textNodes.length) {
                        textNodes[textNodes.length - 1].textContent = ' ' + (task.task_name || '');
                    }
                }

                // Patch assigned-to badge (if rendered)
                const assignBadge = row.querySelector('.assigned-badge');
                if (assignBadge) {
                    if (task.assigned_username) {
                        assignBadge.textContent = '@' + task.assigned_username;
                        assignBadge.style.display = '';
                    } else {
                        assignBadge.style.display = 'none';
                    }
                }
            });

            // If no matching row, show a toast so user knows something changed
            if (rows.length === 0) {
                showToast(
                    `Task "${esc(task.task_name)}" was updated by a teammate.`,
                    'default'
                );
            }
        });
    }

    /**
     * appendNewComments
     * Appends comment rows to an open task modal / comment section.
     * Looks for #tm-comment-list (rendered by TM_TaskModal.php).
     */
    function appendNewComments(comments) {
        const list = document.getElementById('tm-comment-list');
        if (!list) return;

        comments.forEach(c => {
            // Skip if comment already in DOM (idempotent)
            if (list.querySelector(`[data-comment-id="${c.comment_id}"]`)) return;

            const div = document.createElement('div');
            div.className = 'tm-comment';
            div.dataset.commentId = c.comment_id;
            div.innerHTML = `
                <div class="tm-comment-meta">
                    <strong>${esc(c.username)}</strong>
                    <span class="tm-comment-time">${esc(c.created_fmt)}</span>
                    <span class="tm-live-badge">live</span>
                </div>
                <div class="tm-comment-body">${esc(c.content)}</div>`;
            list.appendChild(div);

            // Scroll new comment into view
            div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    /**
     * renderOnlineBar
     * Shows/updates the floating "who's online" indicator.
     * The bar is created the first time; subsequent calls update it.
     */
    function renderOnlineBar(users) {
        let bar = document.getElementById('tm-online-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'tm-online-bar';
            bar.className = 'tm-online-bar';
            document.body.appendChild(bar);
        }

        if (!users || users.length === 0) {
            bar.style.display = 'none';
            return;
        }

        bar.style.display = 'flex';

        const avatars = users.slice(0, 5).map(u => {
            const initials = getInitials(u.full_name || u.username);
            const pageIcon = pageTypeIcon(u.page_type);
            return `<span class="tm-avatar" title="${esc(u.full_name || u.username)} · ${esc(u.page_type)}">
                        ${esc(initials)}${pageIcon}
                    </span>`;
        }).join('');

        const extra = users.length > 5
            ? `<span class="tm-avatar tm-avatar-extra">+${users.length - 5}</span>`
            : '';

        bar.innerHTML = `
            <span class="tm-online-dot"></span>
            <span class="tm-online-label">${users.length} online</span>
            <div class="tm-avatar-row">${avatars}${extra}</div>`;
    }

    // ── Label / CSS helpers (mirror TM_Dashboard.php PHP functions) ──────────
    function statusCssClass(s) {
        return { pending: 'status-pending', in_progress: 'status-in-progress',
                 review: 'status-review', done: 'status-done',
                 cancelled: 'status-cancelled' }[s] || 'status-pending';
    }
    function statusLabel(s) {
        return { pending: 'Pending', in_progress: 'In Progress', review: 'Review',
                 done: 'Done', cancelled: 'Cancelled' }[s] || s;
    }
    function priorityCssClass(p) {
        return { high: 'pri-high', mid: 'pri-mid', low: 'pri-low' }[p] || 'pri-mid';
    }
    function priorityLabel(p) {
        return { high: 'High', mid: 'Mid', low: 'Low' }[p] || p;
    }
    function getInitials(name) {
        return (name || '?').split(' ').slice(0, 2)
            .map(w => w[0] || '').join('').toUpperCase();
    }
    function pageTypeIcon(pt) {
        return { tasks: '📋', dashboard: '🏠', calendar: '📅',
                 task_detail: '🔍', activity: '📊' }[pt] || '';
    }
    function esc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Inline styles for the online bar & flash ──────────────────────────────
    (function injectStyles() {
        if (document.getElementById('tm-rt-styles')) return;
        const style = document.createElement('style');
        style.id = 'tm-rt-styles';
        style.textContent = `
/* ── Real-time flash highlight ──────────────────────── */
@keyframes tm-rt-flash-anim {
    0%   { background: #fef9c3; }
    100% { background: transparent; }
}
.tm-rt-flash { animation: tm-rt-flash-anim 1.2s ease-out forwards; }

/* ── "Who's online" bar ─────────────────────────────── */
.tm-online-bar {
    position: fixed;
    bottom: 1.25rem;
    right: 1.25rem;
    z-index: 900;
    display: flex;
    align-items: center;
    gap: .5rem;
    background: var(--white, #fff);
    border: 1px solid var(--gray-100, #f3f4f6);
    border-radius: 50px;
    padding: .4rem .9rem .4rem .6rem;
    box-shadow: 0 4px 18px rgba(0,0,0,.12);
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-500, #6b7280);
    font-family: 'Poppins', sans-serif;
    transition: opacity .3s;
}
.tm-online-bar:hover { opacity: 1 !important; }

.tm-online-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #22c55e;
    flex-shrink: 0;
    animation: tm-pulse 2s ease-in-out infinite;
}
@keyframes tm-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .6; transform: scale(1.3); }
}

.tm-online-label { white-space: nowrap; }

.tm-avatar-row { display: flex; gap: .25rem; margin-left: .25rem; }
.tm-avatar {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: var(--black, #111);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    cursor: default;
}
.tm-avatar-extra { background: var(--gray-300, #d1d5db); color: var(--gray-600, #4b5563); }

/* ── Live comment badge ─────────────────────────────── */
.tm-live-badge {
    display: inline-block;
    background: #dcfce7;
    color: #15803d;
    border-radius: 50px;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 7px;
    margin-left: .35rem;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: .04em;
}

/* ── Comment list ───────────────────────────────────── */
.tm-comment {
    padding: .65rem 0;
    border-top: 1px solid var(--gray-100, #f3f4f6);
}
.tm-comment-meta {
    font-size: 12px;
    color: var(--gray-500, #6b7280);
    margin-bottom: .2rem;
}
.tm-comment-meta strong { color: var(--black, #111); }
.tm-comment-time { margin-left: .5rem; }
.tm-comment-body { font-size: 13px; line-height: 1.5; white-space: pre-wrap; }
        `;
        document.head.appendChild(style);
    })();

    // ── Start ────────────────────────────────────────────────────────────────
    function start() {
        // Immediate first heartbeat + poll
        sendHeartbeat();
        poll();

        // Recurring heartbeat every 5 s (same as poll; can be tuned separately)
        heartbeatTimer = setInterval(sendHeartbeat, cfg.interval);

        // Recurring poll
        pollTimer = setInterval(poll, cfg.interval);

        // Pause polling when tab is hidden; resume when visible again
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(pollTimer);
                clearInterval(heartbeatTimer);
            } else {
                sendHeartbeat();
                poll();
                pollTimer      = setInterval(poll, cfg.interval);
                heartbeatTimer = setInterval(sendHeartbeat, cfg.interval);
            }
        });
    }

    // Expose public API for use by other scripts (e.g. TM_TaskModal.php)
    window.TM_Realtime = {
        start,
        poll,           // manual trigger
        sendHeartbeat,
        /**
         * Call this after a local mutation (add/edit/delete) so the change
         * is logged immediately without waiting for the next poll cycle.
         */
        logChange: function (taskId, changeType) {
            changeType = changeType || 'update';
            post({ action: 'log_change', task_id: taskId, change_type: changeType })
                .catch(() => {});
        },
    };

    // Auto-start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

})();
