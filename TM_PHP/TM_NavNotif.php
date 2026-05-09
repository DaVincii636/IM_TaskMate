<?php
// ============================================================
// TM_NavNotif.php — Notification bell partial
// ============================================================
// Include AFTER TM_Session.php and TM_DB.php are already loaded.
// Also calls TM_NotifCron.php so notifications are always fresh.
// Usage:  require_once 'TM_PHP/TM_NavNotif.php';
// Then echo $tm_notif_bell_html; inside the navbar.
// ============================================================

// Run the cron inline so notifications are generated on every page load.
// In production replace this with a real server cron and remove this line.
require_once __DIR__ . '/TM_NotifCron.php';

$_tm_uid = tm_uid();

// Fetch the 15 most recent notifications for this user
$_tm_notif_stmt = tm_exec(
    "SELECT notif_id, task_id, type, message, is_read,
            TO_CHAR(created_at, 'Mon DD, HH24:MI') AS created_fmt
     FROM (
         SELECT * FROM TM_Notifications
         WHERE user_id = :p1
         ORDER BY created_at DESC
     ) WHERE ROWNUM <= 15",
    [$_tm_uid]
);
$_tm_notifs     = tm_fetch_all($_tm_notif_stmt);
$_tm_unread     = 0;
foreach ($_tm_notifs as $_n) {
    if ((int)($_n['IS_READ'] ?? $_n['is_read'] ?? 0) === 0) $_tm_unread++;
}

// Type → icon glyph map
$_tm_notif_icons = [
    'overdue'    => '<i class="fa-solid fa-circle-exclamation"></i>',
    'due_today'  => '<i class="fa-solid fa-clock"></i>',
    'due_soon'   => '<i class="fa-solid fa-calendar-days"></i>',
    // CHANGE 4 — mention & assignment notification types
    'mention'    => '<i class="fa-solid fa-at"></i>',
    'assignment' => '<i class="fa-solid fa-user-check"></i>',
];

ob_start(); ?>
<div class="notif-wrap">
    <button class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if ($_tm_unread > 0): ?>
        <span class="notif-badge" id="notifBadge"><?= $_tm_unread > 99 ? '99+' : $_tm_unread ?></span>
        <?php else: ?>
        <span class="notif-badge" id="notifBadge" style="display:none">0</span>
        <?php endif; ?>
    </button>

    <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dropdown-header">
            <span class="notif-dropdown-title">
                Notifications<?= $_tm_unread > 0 ? " <span style='color:var(--gray-400);font-weight:400'>({$_tm_unread} unread)</span>" : '' ?>
            </span>
            <?php if ($_tm_unread > 0): ?>
            <button class="notif-mark-all-btn" id="notifMarkAll">Mark all as read</button>
            <?php endif; ?>
        </div>

        <?php if (empty($_tm_notifs)): ?>
        <div class="notif-empty">
            <i class="fa-solid fa-bell-slash"></i>
            <p>You're all caught up!</p>
        </div>
        <?php else: ?>
        <ul class="notif-list">
        <?php foreach ($_tm_notifs as $_n):
            $_nid      = (int)($_n['NOTIF_ID']    ?? $_n['notif_id']);
            $_ntype    = $_n['TYPE']               ?? $_n['type']        ?? 'due_soon';
            $_nmsg     = $_n['MESSAGE']            ?? $_n['message']     ?? '';
            $_nread    = (int)($_n['IS_READ']      ?? $_n['is_read']     ?? 0);
            $_ntime    = $_n['CREATED_FMT']        ?? $_n['created_fmt'] ?? '';
            $_nicon    = $_tm_notif_icons[$_ntype] ?? $_tm_notif_icons['due_soon'];
            $_nunread  = $_nread === 0 ? ' unread' : '';
        ?>
        <li class="notif-item<?= $_nunread ?>"
            data-id="<?= $_nid ?>"
            data-task-id="<?= (int)($_n['TASK_ID'] ?? $_n['task_id'] ?? 0) ?>"
            onclick="tmHandleNotifClick(this)"
            style="cursor:pointer;">
            <div class="notif-dot type-<?= htmlspecialchars($_ntype) ?>"><?= $_nicon ?></div>
            <div class="notif-body">
                <div class="notif-msg"><?= htmlspecialchars($_nmsg) ?></div>
                <div class="notif-time"><?= htmlspecialchars($_ntime) ?></div>
            </div>
        </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php
$tm_notif_bell_html = ob_get_clean();

// Append the notification click handler script once per page.
// tmHandleNotifClick: marks the notification read, closes the dropdown,
// then opens the task view modal ON THE CURRENT PAGE — no redirect.
$tm_notif_bell_html .= <<<'NOTIFJS'
<script>
function tmHandleNotifClick(el) {
    var notifId = el.dataset.id;
    var taskId  = el.dataset.taskId;

    // 1. Mark as read via AJAX (silent — UI updates immediately)
    if (el.classList.contains('unread')) {
        el.classList.remove('unread');
        el.classList.add('read');
        var badge = document.getElementById('notifBadge');
        if (badge) {
            var n = Math.max(0, parseInt(badge.textContent, 10) - 1);
            badge.textContent = n;
            if (n === 0) badge.style.display = 'none';
        }
        fetch('TM_PHP/TM_NotifActions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_read&id=' + encodeURIComponent(notifId)
        }).catch(function(){});
    }

    // 2. Close the dropdown
    var dropdown = document.getElementById('notifDropdown');
    if (dropdown) dropdown.classList.remove('open');

    // 3. Open the task view modal on the current page (no redirect)
    if (taskId && taskId !== '0' && typeof window.tmOpenView === 'function') {
        window.tmOpenView(taskId);
    }
}
</script>
NOTIFJS;

// Clean up temp vars from global scope
unset($_tm_uid, $_tm_notif_stmt, $_tm_notifs, $_tm_unread, $_tm_notif_icons, $_n,
      $_nid, $_ntype, $_nmsg, $_nread, $_ntime, $_nicon, $_nunread);
      