// ---- Toast ----
function showToast(msg, type = 'default') {
    // Remove any existing toast first
    const existing = document.getElementById('toast');
    if (existing) existing.remove();

    const icons = { error: '✕', success: '✓', default: 'ℹ' };
    const titles = { error: 'Error', success: 'Success', default: 'Notice' };

    const toast = document.createElement('div');
    toast.id = 'toast';
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <span class="toast-icon">${icons[type] || icons.default}</span>
        <div class="toast-content">
            <div class="toast-title">${titles[type] || titles.default}</div>
            <div class="toast-msg">${msg}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">✕</button>
    `;

    document.body.appendChild(toast);

    // Trigger animation
    requestAnimationFrame(() => {
        requestAnimationFrame(() => toast.classList.add('show'));
    });

    // Auto dismiss after 4 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

// ---- Convert inline alerts to toasts on page load ----
document.addEventListener('DOMContentLoaded', function () {

    // Check for error banner
    const errorBanner = document.querySelector('.validation-summary');
    if (errorBanner) {
        const msg = errorBanner.textContent.trim();
        if (msg) showToast(msg, 'error');
        errorBanner.style.display = 'none'; // Hide inline banner
    }

    // Check for success banner
    const successBanner = document.querySelector('.success-banner');
    if (successBanner) {
        const msg = successBanner.textContent.trim();
        if (msg) showToast(msg, 'success');
        successBanner.style.display = 'none';
    }

    // ---- Modal ----
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.openModal));
    });

    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) this.classList.remove('active');
        });
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active')
                .forEach(m => m.classList.remove('active'));
        }
    });

    // ---- Typing animation on welcome page ----
    const typingEl = document.getElementById('typingText');
    if (typingEl && typeof fullName !== 'undefined' && fullName.trim() !== '') {
        let i = 0;
        let deleting = false;
        const text = fullName;
        const typeSpeed = 80;
        const deleteSpeed = 40;
        const pauseAfterType = 2000;
        const pauseAfterDelete = 500;

        function type() {
            if (!deleting) {
                typingEl.textContent = text.slice(0, i + 1);
                i++;
                if (i === text.length) {
                    deleting = true;
                    setTimeout(type, pauseAfterType);
                    return;
                }
            } else {
                typingEl.textContent = text.slice(0, i - 1);
                i--;
                if (i === 0) {
                    deleting = false;
                    setTimeout(type, pauseAfterDelete);
                    return;
                }
            }
            setTimeout(type, deleting ? deleteSpeed : typeSpeed);
        }
        setTimeout(type, 600);
}


    // ---- Search filter on user list ----
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('tbody tr.user-row').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    // ---- Confirm delete ----
    document.querySelectorAll('.btn-delete-confirm').forEach(btn => {
        btn.addEventListener('click', function (e) {
            if (!confirm('Are you sure you want to delete this user?')) {
                e.preventDefault();
            }
        });
    });

    // ---- Edit user: populate modal ----
    document.querySelectorAll('.btn-edit-user').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_id').value           = this.dataset.id;
            document.getElementById('edit_fname').value        = this.dataset.fname;
            document.getElementById('edit_lname').value        = this.dataset.lname;
            document.getElementById('edit_email').value        = this.dataset.email;
            document.getElementById('edit_email_hidden').value = this.dataset.email;
            document.getElementById('edit_phone').value        = this.dataset.phone;
            document.getElementById('edit_phone_hidden').value = this.dataset.phone;
            openModal('editModal');
        });
    });

    // ---- Password toggle ----
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            this.innerHTML = input.type === 'password'
                ? `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/><circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/></svg>`
                : `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.477 10.477A3 3 0 0013.5 13.5M6.228 6.228A10.45 10.45 0 002.458 12C3.732 16.057 7.523 19 12 19c1.7 0 3.3-.42 4.697-1.16M9.878 4.121A10.45 10.45 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.459 10.459 0 01-1.485 3.03"/></svg>`;
        });
    });
});

// ---- Modal helpers ----
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

// ---- Delete Task helpers ----
// Called by the Delete button inside the Edit modal
function confirmDeleteTask() {
    // Grab task id and name from the edit modal fields
    const taskName = document.getElementById('editTaskName').value || 'this task';
    const taskId   = document.getElementById('editTaskId').value;

    // Populate the confirm modal
    document.getElementById('deleteTaskName').textContent = taskName;
    document.getElementById('deleteTaskId').value = taskId;

    // Close edit modal, open confirm modal
    closeModal('editTaskModal');
    openModal('deleteConfirmModal');
}

// Called by the "Yes, Delete" button inside the confirm modal
function submitDeleteTask() {
    document.getElementById('deleteTaskForm').submit();
}

// =============================================
// Notification Bell
// =============================================
(function () {
    const bell     = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge    = document.getElementById('notifBadge');
    if (!bell || !dropdown) return;

    // Toggle dropdown open/closed
    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
        if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });

    // Mark single notification as read on click
    dropdown.querySelectorAll('.notif-item[data-id]').forEach(function (item) {
        item.addEventListener('click', function () {
            const id = this.dataset.id;
            if (this.classList.contains('read')) return;
            fetch('TM_PHP/TM_NotifActions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&id=' + encodeURIComponent(id)
            })
            .then(r => r.json())
            .then(function (res) {
                if (!res.ok) return;
                item.classList.remove('unread');
                item.classList.add('read');
                const dot = item.querySelector('.notif-dot');
                if (dot) { dot.className = 'notif-dot read'; }
                const txt = item.querySelector('.notif-text');
                if (txt) { txt.style.fontWeight = '400'; txt.style.color = 'var(--gray-400)'; }
                decrementBadge();
            });
        });
    });

    // Mark all as read
    const markAllBtn = document.getElementById('notifMarkAll');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            fetch('TM_PHP/TM_NotifActions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(r => r.json())
            .then(function (res) {
                if (!res.ok) return;
                dropdown.querySelectorAll('.notif-item.unread').forEach(function (item) {
                    item.classList.remove('unread');
                    item.classList.add('read');
                    const dot = item.querySelector('.notif-dot');
                    if (dot) { dot.className = 'notif-dot read'; }
                    const txt = item.querySelector('.notif-text');
                    if (txt) { txt.style.fontWeight = '400'; txt.style.color = 'var(--gray-400)'; }
                });
                if (badge) { badge.textContent = '0'; badge.classList.add('hidden'); }
            });
        });
    }

    function decrementBadge() {
        if (!badge) return;
        const current = parseInt(badge.textContent, 10) || 0;
        const next = Math.max(current - 1, 0);
        badge.textContent = next;
        if (next === 0) badge.classList.add('hidden');
    }
})();

// =============================================
// Notification Bell
// =============================================
(function () {
    const btn      = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    if (!btn || !dropdown) return;

    // Toggle open/close
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
        if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });

    // Mark single notification as read on click
    dropdown.querySelectorAll('.notif-item[data-id]').forEach(function (item) {
        item.addEventListener('click', function () {
            const id = this.dataset.id;
            if (!this.classList.contains('unread')) return;
            fetch('TM_PHP/TM_NotifActions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&id=' + encodeURIComponent(id)
            }).then(function () {
                item.classList.remove('unread');
                item.style.removeProperty('background');
                // Remove the left accent bar by removing unread class
                // Update badge count
                const badge = document.getElementById('notifBadge');
                if (badge) {
                    const current = parseInt(badge.textContent, 10) || 0;
                    const next = current - 1;
                    if (next <= 0) {
                        badge.style.display = 'none';
                    } else {
                        badge.textContent = next > 99 ? '99+' : next;
                    }
                }
            });
        });
    });

    // Mark all as read
    const markAllBtn = document.getElementById('notifMarkAll');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            fetch('TM_PHP/TM_NotifActions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            }).then(function () {
                dropdown.querySelectorAll('.notif-item.unread').forEach(function (item) {
                    item.classList.remove('unread');
                });
                const badge = document.getElementById('notifBadge');
                if (badge) badge.style.display = 'none';
            });
        });
    }
})();