<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash     = tm_get_flash();
$firstName = tm_uname();
$fullName  = $firstName . ' ' . ($_SESSION['tm_last_name'] ?? '');
$uid       = tm_uid();

// ── Feature 11: Onboarding check (HCI101 Week 2 — Learnability) ───────────────
// Check TM_UserPrefs to see if this user has already seen the walkthrough.
// If no row exists yet, treat it as "not done" (new user).
$prefsRow = tm_fetch_one(tm_exec(
    "SELECT onboarding_done FROM TM_UserPrefs WHERE user_id = :p1",
    [$uid]
));
$showOnboarding = (!$prefsRow || (int)($prefsRow['onboarding_done'] ?? 0) === 0);
// ── End onboarding check ──────────────────────────────────────────────────────

// ── Stat queries ──────────────────────────────────────────────────────────────
// Oracle returns column names uppercase; use reset() to grab the first column
// value regardless of case so we never silently get 0 from a wrong key.
function _tm_count(array $rows): int {
    if (empty($rows)) return 0;
    return (int) reset($rows[0]);
}

$cntTotal = _tm_count(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1", [$uid]
)));

$cntPending = _tm_count(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks
     WHERE user_id=:p1 AND status NOT IN ('done','cancelled')",
    [$uid]
)));

$cntDone = _tm_count(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1 AND status='done'",
    [$uid]
)));

$cntOverdue = _tm_count(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks
     WHERE user_id=:p1 AND TRUNC(due_date) < TRUNC(SYSDATE) AND status NOT IN ('done','cancelled')",
    [$uid]
)));

// ── 5 upcoming tasks (not done/cancelled, closest due date first) ─────────────
$stmtUpcoming = tm_exec(
    "SELECT task_id, task_name,
            TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
            TO_CHAR(due_date,'YYYY-MM-DD')   AS due_date,
            category, custom_category, priority, color, status, notes
     FROM (
         SELECT task_id, task_name, start_date, due_date,
                category, custom_category, priority, color, status, notes
         FROM TM_Tasks
         WHERE user_id=:p1
           AND status NOT IN ('done','cancelled')
           AND due_date >= TRUNC(SYSDATE)
         ORDER BY due_date ASC
     )
     WHERE ROWNUM <= 5",
    [$uid]
);
$upcoming = tm_fetch_all($stmtUpcoming);
// Resolve CLOB fields
$upcoming = array_map(function($row) {
    if (isset($row['notes'])) {
        if ($row['notes'] instanceof OCILob)  $row['notes'] = $row['notes']->load();
        elseif (is_resource($row['notes']))   $row['notes'] = stream_get_contents($row['notes']);
        $row['notes'] = (string)($row['notes'] ?? '');
    }
    return $row;
}, $upcoming);

// ── All tasks (for Add Task dependency select) ────────────────────────────────
$stmtAllTasks = tm_exec(
    "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
            TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
            category, custom_category, priority, color, notes, status, recurrence
     FROM TM_Tasks WHERE user_id = :p1 ORDER BY due_date ASC",
    [$uid]
);
$allTasks = tm_fetch_all($stmtAllTasks);
$allTasks = array_map(function($row) {
    if (isset($row['notes'])) {
        if ($row['notes'] instanceof OCILob) $row['notes'] = $row['notes']->load();
        elseif (is_resource($row['notes']))  $row['notes'] = stream_get_contents($row['notes']);
        $row['notes'] = (string)($row['notes'] ?? '');
    }
    return $row;
}, $allTasks);
$tasksJson = json_encode($allTasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if ($tasksJson === false) { $tasksJson = '[]'; }

// ── Notifications (shared partial: runs cron + builds bell HTML) ──────────────
require_once 'TM_PHP/TM_NavNotif.php';

function priorityClass(string $p): string {
    return match($p) { 'high'=>'pri-high','mid'=>'pri-mid','low'=>'pri-low', default=>'pri-mid' };
}
function priorityLabel(string $p): string {
    return match($p) { 'high'=>'High','mid'=>'Mid','low'=>'Low', default=>ucfirst($p) };
}
function statusClass(string $s): string {
    return match($s) {
        'pending'=>'status-pending','in_progress'=>'status-in-progress',
        'review'=>'status-review','done'=>'status-done','cancelled'=>'status-cancelled',
        default=>'status-pending',
    };
}
function statusLabel(string $s): string {
    return match($s) {
        'pending'=>'Pending','in_progress'=>'In Progress','review'=>'Review',
        'done'=>'Done','cancelled'=>'Cancelled', default=>ucfirst($s),
    };
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Dashboard - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
/* ── Page shell ──────────────────────────────────────── */
.dash-page {
    max-width: 960px;
    margin: 2rem auto;
    padding: 0 1.25rem 4rem;
}

/* ── Greeting ────────────────────────────────────────── */
.dash-greeting {
    margin-bottom: 1.75rem;
}
.dash-greeting h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 .2rem;
}
.dash-greeting p {
    font-size: 13px;
    color: var(--gray-500);
    margin: 0;
}

/* ── Stat cards ──────────────────────────────────────── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}
@media (max-width: 700px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}
.stat-card {
    background: var(--white);
    border: 1px solid var(--gray-100);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1.25rem 1.25rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .35rem;
    transition: box-shadow .18s;
}
.stat-card:hover { box-shadow: var(--shadow-md); }
.stat-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; margin-bottom: .25rem;
}
.stat-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    color: var(--black);
}
.stat-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-500);
    letter-spacing: .02em;
}
/* Accent variants */
.stat-card.accent-blue  .stat-icon { background: #dbeafe; color: #1d4ed8; }
.stat-card.accent-green .stat-icon { background: #dcfce7; color: #15803d; }
.stat-card.accent-red   .stat-icon { background: #fee2e2; color: #b91c1c; }
.stat-card.accent-gray  .stat-icon { background: var(--gray-100); color: var(--gray-500); }

/* ── Quick-action bar ────────────────────────────────── */
.quick-actions {
    display: flex;
    gap: .75rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}
.qa-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 50px;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid var(--border);
    background: var(--white);
    color: var(--black);
    text-decoration: none;
    transition: all .17s;
}
.qa-btn:hover { background: var(--gray-100); }
.qa-btn.qa-primary {
    background: var(--black);
    color: #fff;
    border-color: var(--black);
}
.qa-btn.qa-primary:hover { opacity: .88; }

/* ── Upcoming tasks panel ────────────────────────────── */
.panel-card {
    background: var(--white);
    border: 1px solid var(--gray-100);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .9rem 1.25rem;
    border-bottom: 1px solid var(--gray-100);
}
.panel-title {
    font-size: 14px;
    font-weight: 700;
}
.panel-link {
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-500);
    text-decoration: none;
}
.panel-link:hover { color: var(--black); }

.up-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.up-table tbody td { padding: 12px 1.25rem; border-top: 1px solid var(--gray-100); vertical-align: middle; }
.up-table tbody tr:hover { background: var(--bg); cursor: pointer; }

.task-name-cell { font-weight: 600; color: var(--black); }
.color-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 7px; vertical-align: middle; }
.task-date { color: var(--gray-500); white-space: nowrap; font-size: 12px; }

.status-pill { display: inline-block; border-radius: 50px; font-size: 11px; font-weight: 700; padding: 3px 10px; white-space: nowrap; }
.status-pending     { background: #f3f4f6; color: #6b7280; }
.status-in-progress { background: #dbeafe; color: #1d4ed8; }
.status-review      { background: #fef9c3; color: #92400e; }
.status-done        { background: #dcfce7; color: #15803d; }
.status-cancelled   { background: #fee2e2; color: #b91c1c; }

.pri-pill { display: inline-block; border-radius: 50px; font-size: 11px; font-weight: 700; padding: 3px 10px; }
.pri-high { background: #fee2e2; color: #b91c1c; }
.pri-mid  { background: #fef9c3; color: #78350f; }
.pri-low  { background: #dcfce7; color: #15803d; }

.empty-state { padding: 3rem 2rem; text-align: center; }
.empty-state i { font-size: 2rem; color: var(--gray-300); margin-bottom: .75rem; }
.empty-state h3 { font-size: .9rem; font-weight: 700; color: var(--gray-500); margin: 0 0 .3rem; }
.empty-state p  { font-size: 12px; color: var(--gray-400); margin: 0; }

/* ── Logout modal ────────────────────────────────────── */
.pc-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1000;align-items:center;justify-content:center;}
.pc-modal-overlay.active{display:flex;}
.pc-modal-box{background:var(--white);border-radius:var(--radius-lg);padding:2rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);text-align:center;}
.pc-modal-icon{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.5rem;}
.pc-modal-title{font-size:1.1rem;font-weight:700;color:var(--black);margin-bottom:.5rem;}
.pc-modal-body{font-size:13px;color:var(--gray-500);margin-bottom:1.5rem;line-height:1.6;}
.pc-modal-btns{display:flex;gap:10px;justify-content:center;}
.pc-modal-cancel{padding:9px 22px;border-radius:50px;font-size:13px;font-weight:600;border:1.5px solid var(--border);background:var(--white);color:var(--gray-500);cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.pc-modal-cancel:hover{background:var(--border);}
.pc-modal-confirm-red{padding:9px 22px;border-radius:50px;font-size:13px;font-weight:700;background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;border:none;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
.pc-modal-confirm-red:hover{opacity:.9;transform:translateY(-1px);}
/* ── Add Task Modal extras ──────────────────── */
.category-options { display: flex; gap: 8px; flex-wrap: wrap; }
.cat-btn {
    padding: 7px 14px; border-radius: 100px; font-size: 12px; font-weight: 600;
    border: 1.5px solid var(--border); background: var(--white); color: var(--gray-500);
    transition: all 0.2s; cursor: pointer; font-family: 'Poppins', sans-serif;
}
.cat-btn.active { border-color: var(--black); background: var(--black); color: var(--white); }
.priority-options { display: flex; gap: 8px; }
.priority-btn {
    flex: 1; padding: 8px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 600;
    border: 1.5px solid var(--border); background: var(--white); color: var(--gray-500);
    text-align: center; transition: all 0.2s; cursor: pointer; font-family: 'Poppins', sans-serif;
}
.priority-btn.high.active { border-color: #ef4444; background: #ef4444; color: white; }
.priority-btn.mid.active  { border-color: #f97316; background: #f97316; color: white; }
.priority-btn.low.active  { border-color: #22c55e; background: #22c55e; color: white; }
.color-picker-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.color-swatch {
    width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
    border: 2.5px solid transparent; transition: all 0.2s; flex-shrink: 0;
}
.color-swatch:hover { transform: scale(1.15); }
.color-swatch.selected {
    border-color: var(--black); transform: scale(1.15);
    box-shadow: 0 0 0 2px var(--white), 0 0 0 4px var(--black);
}
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars($firstName) ?></strong></span>
        <a href="TM_Profile.php" class="btn-logout" title="My Profile" style="display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-user-circle"></i></a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"     class="btn-logout">Tasks</a>
        <a href="TM_Activity.php"  class="btn-logout">Activity</a>
        <a href="TM_Analytics.php" class="btn-logout">Analytics</a>
                <!-- Global Search (Feature 5) -->
        <form class="navbar-search" action="TM_Tasks.php" method="get">
            <input type="hidden" name="view" value="all"/>
            <input type="text" name="q" class="navbar-search-input"
                   placeholder="Search tasks..." autocomplete="off"
                   value="<?= isset($_GET['q']) ? htmlspecialchars($_GET['q']) : '' ?>"/>
            <button type="submit" class="navbar-search-btn" title="Search">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </form>
        <?= $tm_notif_bell_html ?>
        <a href="#" class="btn-logout" id="logoutBtn">Log Out</a>
    </div>
</nav>

<!-- Logout Modal -->
<div id="logoutModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(231,76,60,.12);">
            <i class="fa-solid fa-arrow-right-from-bracket" style="color:#e74c3c;"></i>
        </div>
        <div class="pc-modal-title">Log Out?</div>
        <div class="pc-modal-body">You'll need to sign in again to access your tasks.</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" id="logoutCancel">Cancel</button>
            <a href="TM_PHP/TM_AuthActions.php?action=logout" class="pc-modal-confirm-red">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out
            </a>
        </div>
    </div>
</div>

<?php if ($flash): ?>
<div class="<?= $flash['type']==='error' ? 'validation-summary' : 'success-banner' ?>" style="display:none">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="dash-page">

    <!-- Greeting -->
    <div class="dash-greeting">
        <h1>Dashboard</h1>
        <p>Welcome back, <?= htmlspecialchars($firstName) ?>. Here's your task overview.</p>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card accent-gray">
            <div class="stat-icon"><i class="fa-solid fa-list-check"></i></div>
            <div class="stat-value"><?= (int)$cntTotal ?></div>
            <div class="stat-label">Total Tasks</div>
        </div>
        <div class="stat-card accent-blue">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-value"><?= (int)$cntPending ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card accent-green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-value"><?= (int)$cntDone ?></div>
            <div class="stat-label">Done</div>
        </div>
        <div class="stat-card accent-red">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-value"><?= (int)$cntOverdue ?></div>
            <div class="stat-label">Overdue</div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="quick-actions">
        <button type="button" class="qa-btn qa-primary" onclick="openModal('addTaskModal')">
            <i class="fa-solid fa-plus"></i> Add Task
        </button>
        <a href="TM_Tasks.php?view=all" class="qa-btn">
            <i class="fa-solid fa-table-list"></i> All Tasks
        </a>
        <a href="TM_Tasks.php?view=missing" class="qa-btn">
            <i class="fa-solid fa-clock-rotate-left"></i> Missing
        </a>
        <a href="TM_Tasks.php?view=done" class="qa-btn">
            <i class="fa-solid fa-flag-checkered"></i> Done
        </a>
        <a href="TM_Calendar.php" class="qa-btn">
            <i class="fa-regular fa-calendar"></i> Calendar
        </a>
    </div>

    <!-- Upcoming tasks -->
    <div class="panel-card">
        <div class="panel-header">
            <span class="panel-title">Upcoming Tasks</span>
            <a href="TM_Tasks.php?view=all" class="panel-link">View all →</a>
        </div>
        <?php if (empty($upcoming)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-circle-check"></i>
            <h3>Nothing coming up</h3>
            <p>No pending tasks due from today onwards. <a href="TM_Calendar.php">Add one</a>.</p>
        </div>
        <?php else: ?>
        <table class="up-table">
            <tbody>
            <?php foreach ($upcoming as $t):
                $dueDate  = $t['DUE_DATE']  ?? $t['due_date'];
                $taskId   = $t['TASK_ID']   ?? $t['task_id'];
                $taskName = $t['TASK_NAME'] ?? $t['task_name'];
                $pri      = $t['PRIORITY']  ?? $t['priority'];
                $status   = $t['STATUS']    ?? $t['status'];
                $color    = $t['COLOR']     ?? $t['color'];
            ?>
            <tr data-task-id="<?= (int)$taskId ?>" title="Click to view details">
                <td class="task-name-cell">
                    <span class="color-dot" style="background:<?= htmlspecialchars($color) ?>"></span>
                    <?= htmlspecialchars($taskName) ?>
                </td>
                <td class="task-date">
                    <?= htmlspecialchars(date('M j, Y', strtotime($dueDate))) ?>
                </td>
                <td>
                    <span class="pri-pill <?= priorityClass($pri) ?>">
                        <?= priorityLabel($pri) ?>
                    </span>
                </td>
                <td>
                    <span class="status-pill <?= statusClass($status) ?>">
                        <?= statusLabel($status) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div><!-- /.dash-page -->

<?php require_once 'TM_PHP/TM_TaskModal.php'; ?>

<div class="toast" id="toast"></div>

<script>
(function(){
    var btn    = document.getElementById('logoutBtn');
    var modal  = document.getElementById('logoutModal');
    var cancel = document.getElementById('logoutCancel');
    if (!btn) return;
    btn.addEventListener('click', function(e){ e.preventDefault(); modal.classList.add('active'); });
    cancel.addEventListener('click', function(){ modal.classList.remove('active'); });
    modal.addEventListener('click', function(e){ if(e.target===modal) modal.classList.remove('active'); });
})();
</script>
<script src="TM_JS/TM_App.js"></script>

<?php require_once 'TM_PHP/TM_AddTaskModal.php'; ?>

<script>
// Pass all tasks to JS so the dependency select is populated
const serverTasks = <?= $tasksJson ?>.map(function(t) {
    return {
        Id:        parseInt(t.task_id, 10),
        Name:      t.task_name,
        Status:    t.status || 'pending',
        Color:     t.color  || '#ef4444',
        DueDate:   t.due_date || ''
    };
});
</script>

<script>
(function () {
    var COLORS = [
        { name: 'Red',    hex: '#ef4444' },
        { name: 'Orange', hex: '#f97316' },
        { name: 'Yellow', hex: '#eab308' },
        { name: 'Green',  hex: '#22c55e' },
        { name: 'Blue',   hex: '#3b82f6' },
        { name: 'Indigo', hex: '#6366f1' },
        { name: 'Violet', hex: '#a855f7' },
    ];

    // Build color swatches
    var colorRow   = document.getElementById('addColorRow');
    var colorInput = document.getElementById('addColorInput');
    COLORS.forEach(function (c) {
        var sw = document.createElement('div');
        sw.className = 'color-swatch' + (c.hex === '#ef4444' ? ' selected' : '');
        sw.style.background = c.hex;
        sw.title = c.name;
        sw.addEventListener('click', function () {
            colorRow.querySelectorAll('.color-swatch').forEach(function (s) { s.classList.remove('selected'); });
            sw.classList.add('selected');
            colorInput.value = c.hex;
        });
        colorRow.appendChild(sw);
    });

    // Category buttons
    document.querySelectorAll('#addCatOptions .cat-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#addCatOptions .cat-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('addCategoryInput').value = btn.dataset.cat;
            document.getElementById('addOthersWrap').style.display = btn.dataset.cat === 'others' ? 'block' : 'none';
        });
    });

    // Priority buttons
    document.querySelectorAll('#addPriorityOptions .priority-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#addPriorityOptions .priority-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('addPriorityInput').value = btn.dataset.priority;
        });
    });

    // Reset the add-task modal fields every time it opens
    document.querySelectorAll('[onclick*="addTaskModal"]').forEach(function (el) {
        el.addEventListener('click', function () {
            document.getElementById('addTaskName').value  = '';
            document.getElementById('addTaskStart').value = '';
            document.getElementById('addTaskDue').value   = '';
            if (typeof window.addDepReset === 'function') window.addDepReset();
        });
    });

    // ── Dependency UI ─────────────────────────────────────────────────────────
    (function () {
        function toNum(v) { return parseInt(v, 10) || 0; }
        function esc(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function fmtDate(s) {
            if (!s) return '';
            var p = s.split('-');
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[parseInt(p[1],10)-1] + ' ' + parseInt(p[2],10);
        }

        function getAvailTasks() {
            return (typeof serverTasks !== 'undefined' ? serverTasks : []);
        }

        var _selected = [];

        function renderDep() {
            var container = document.getElementById('addDepSelected');
            var hidden    = document.getElementById('addDepBlockerIds');
            if (!container || !hidden) return;
            container.innerHTML = '';
            hidden.value = _selected.map(function(s) { return s.id; }).join(',');
            _selected.forEach(function(s) {
                var chip = document.createElement('div');
                chip.className = 'dep-chip';
                chip.innerHTML =
                    '<span class="dep-chip-dot" style="background:' + esc(s.color) + '"></span>' +
                    '<span class="dep-chip-name">' + esc(s.name) + '</span>' +
                    (s.dueDate ? '<span class="dep-chip-due">' + fmtDate(s.dueDate) + '</span>' : '') +
                    '<button type="button" class="dep-chip-remove" title="Remove"><i class="fa-solid fa-xmark"></i></button>';
                chip.querySelector('.dep-chip-remove').addEventListener('click', function() {
                    _selected = _selected.filter(function(x) { return x.id !== s.id; });
                    renderDep();
                    buildDepSelect();
                });
                container.appendChild(chip);
            });
            buildDepSelect();
        }

        function buildDepSelect() {
            var sel = document.getElementById('addDepSelect');
            if (!sel) return;
            // Only show tasks whose due date is on or before the selected due date.
            // A blocker must be finishable before the new task's deadline.
            var currentDue = (document.getElementById('addTaskDue') || {}).value || '';
            var eligible = getAvailTasks().filter(function(t) {
                return !['done','cancelled'].includes((t.Status||'').toLowerCase()) &&
                       !_selected.find(function(s) { return s.id === t.Id; }) &&
                       (currentDue === '' || !t.DueDate || t.DueDate <= currentDue);
            });
            sel.innerHTML = '<option value="">— Pick a task —</option>';
            eligible.forEach(function(t) {
                var opt = document.createElement('option');
                opt.value = t.Id;
                opt.textContent = t.Name + (t.DueDate ? '  ·  ' + fmtDate(t.DueDate) : '');
                sel.appendChild(opt);
            });
        }

        // Re-filter when the due date changes
        var addDueEl = document.getElementById('addTaskDue');
        if (addDueEl) addDueEl.addEventListener('change', buildDepSelect);

        var selEl = document.getElementById('addDepSelect');
        if (selEl) {
            selEl.addEventListener('change', function() {
                var id = toNum(this.value);
                if (!id) return;
                var task = getAvailTasks().find(function(t) { return t.Id === id; });
                if (task && !_selected.find(function(s) { return s.id === id; })) {
                    _selected.push({ id: task.Id, name: task.Name,
                                     color: task.Color || '#888', dueDate: task.DueDate || '' });
                    renderDep();
                }
                this.value = '';
            });
        }

        window.addDepReset = function() { _selected = []; renderDep(); };
        buildDepSelect();
        renderDep();
    })();

    // ── Auto-expand textareas ──────────────────────────────────────────────────
    function autoExpand(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
    document.querySelectorAll('textarea.tm-auto-expand').forEach(function (ta) {
        ta.addEventListener('input', function () { autoExpand(ta); });
    });

    // ── Daily recurrence: keep start & due in sync ────────────────────────────
    (function () {
        var recEl   = document.getElementById('addRecurrence');
        var startEl = document.getElementById('addTaskStart');
        var dueEl   = document.getElementById('addTaskDue');
        if (!recEl || !startEl || !dueEl) return;
        recEl.addEventListener('change', function () {
            if (recEl.value === 'daily' && startEl.value) dueEl.value = startEl.value;
        });
        startEl.addEventListener('change', function () {
            if (recEl.value === 'daily') dueEl.value = startEl.value;
        });
        dueEl.addEventListener('change', function () {
            if (recEl.value === 'daily') startEl.value = dueEl.value;
        });
    })();

    // ── Form validation before submit ─────────────────────────────────────────
    var addForm = document.getElementById('addTaskForm');
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            var name  = document.getElementById('addTaskName');
            var start = document.getElementById('addTaskStart');
            var due   = document.getElementById('addTaskDue');
            if (!name.value.trim()) { e.preventDefault(); name.focus(); return; }
            if (!start.value)       { e.preventDefault(); start.focus(); return; }
            if (!due.value)         { e.preventDefault(); due.focus(); return; }
            if (start.value > due.value) {
                e.preventDefault();
                alert('Start date cannot be after due date.');
                start.focus();
            }
        });
    }
})();
</script>

<?php if ($showOnboarding): ?>
<!-- Feature 11: Onboarding Tooltip Walkthrough (HCI101 Week 2, 4, 10-11) -->
<script>
    // Flag read by TM_Onboarding.js to decide whether to show the overlay.
    // Only true for first-time users (TM_UserPrefs.onboarding_done = 0).
    window.TM_SHOW_ONBOARDING = true;
</script>
<script src="TM_JS/TM_Onboarding.js"></script>
<?php endif; ?>
</body>
</html>