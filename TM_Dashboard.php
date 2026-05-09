<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash     = tm_get_flash();
$firstName = tm_uname();
$fullName  = $firstName . ' ' . ($_SESSION['tm_last_name'] ?? '');
$uid       = tm_uid();

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
     WHERE user_id=:p1 AND due_date < SYSDATE AND status NOT IN ('done','cancelled')",
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

<!-- ── Add Task Modal ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="addTaskModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">New Task</div>
            <button class="modal-close" onclick="closeModal('addTaskModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TaskActions.php">
            <input type="hidden" name="action" value="add"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Task Name</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Buy groceries" required/>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="startDate" id="dash_startDate" class="form-input" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="dueDate" id="dash_dueDate" class="form-input" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <div class="category-options">
                        <button type="button" class="cat-btn active" data-cat="errands">Errands</button>
                        <button type="button" class="cat-btn" data-cat="school">School</button>
                        <button type="button" class="cat-btn" data-cat="medicine">Medicine</button>
                        <button type="button" class="cat-btn" data-cat="others">Others</button>
                    </div>
                    <input type="hidden" name="category" id="dash_categoryInput" value="errands"/>
                    <div id="dash_othersWrap" style="display:none;margin-top:8px">
                        <input type="text" name="customCategory" class="form-input" placeholder="Specify category..."/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <div class="priority-options">
                        <button type="button" class="priority-btn high" data-priority="high">🔴 High</button>
                        <button type="button" class="priority-btn mid active" data-priority="mid">🟡 Mid</button>
                        <button type="button" class="priority-btn low" data-priority="low">🟢 Low</button>
                    </div>
                    <input type="hidden" name="priority" id="dash_priorityInput" value="mid"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Task Color</label>
                    <div class="color-picker-row" id="dash_colorRow"></div>
                    <input type="hidden" name="color" id="dash_colorInput" value="#ef4444"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input" placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addTaskModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Task</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const COLORS = [
        { name: 'Red',    hex: '#ef4444' },
        { name: 'Orange', hex: '#f97316' },
        { name: 'Yellow', hex: '#eab308' },
        { name: 'Green',  hex: '#22c55e' },
        { name: 'Blue',   hex: '#3b82f6' },
        { name: 'Indigo', hex: '#6366f1' },
        { name: 'Violet', hex: '#a855f7' },
    ];

    // Build color swatches
    const colorRow   = document.getElementById('dash_colorRow');
    const colorInput = document.getElementById('dash_colorInput');
    COLORS.forEach(function (c) {
        const sw = document.createElement('div');
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
    document.querySelectorAll('#addTaskModal .cat-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#addTaskModal .cat-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('dash_categoryInput').value = btn.dataset.cat;
            document.getElementById('dash_othersWrap').style.display = btn.dataset.cat === 'others' ? 'block' : 'none';
        });
    });

    // Priority buttons
    document.querySelectorAll('#addTaskModal .priority-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#addTaskModal .priority-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('dash_priorityInput').value = btn.dataset.priority;
        });
    });

    // Default today's date on open
    document.querySelector('[onclick="openModal(\'addTaskModal\')"]').addEventListener('click', function () {
        const today = new Date().toISOString().split('T')[0];
        const s = document.getElementById('dash_startDate');
        const d = document.getElementById('dash_dueDate');
        if (!s.value) s.value = today;
        if (!d.value) d.value = today;
    });
})();
</script>
</body>
</html>