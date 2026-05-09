<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash = tm_get_flash();
$uid   = tm_uid();

// Which tab is active: all | missing | done
$view = $_GET['view'] ?? 'all';
if (!in_array($view, ['all', 'missing', 'done'])) { $view = 'all'; }

// ── Search & filter params (URL-driven, so results are bookmarkable) ──────────
$search    = trim($_GET['q']    ?? '');
$filterCat = trim($_GET['cat']  ?? '');
$filterPri = trim($_GET['pri']  ?? '');
$dateFrom  = trim($_GET['from'] ?? '');
$dateTo    = trim($_GET['to']   ?? '');

$extraWhere  = '';
$extraParams = [$uid]; // :p1 is always user_id

if ($search !== '') {
    $extraWhere .= " AND UPPER(task_name) LIKE UPPER(:p" . (count($extraParams)+1) . ")";
    $extraParams[] = '%' . $search . '%';
}
if ($filterCat !== '') {
    $extraWhere .= " AND category = :p" . (count($extraParams)+1);
    $extraParams[] = $filterCat;
}
if ($filterPri !== '') {
    $extraWhere .= " AND priority = :p" . (count($extraParams)+1);
    $extraParams[] = $filterPri;
}
if ($dateFrom !== '') {
    $extraWhere .= " AND due_date >= TO_DATE(:p" . (count($extraParams)+1) . ",'YYYY-MM-DD')";
    $extraParams[] = $dateFrom;
}
if ($dateTo !== '') {
    $extraWhere .= " AND due_date <= TO_DATE(:p" . (count($extraParams)+1) . ",'YYYY-MM-DD')";
    $extraParams[] = $dateTo;
}

// Build query based on active tab
if ($view === 'done') {
    $stmt = tm_exec(
        "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
                category, custom_category, priority, color, notes, status
         FROM TM_Tasks
         WHERE user_id = :p1 AND status = 'done'
         $extraWhere
         ORDER BY due_date DESC",
        $extraParams
    );
} elseif ($view === 'missing') {
    $stmt = tm_exec(
        "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
                category, custom_category, priority, color, notes, status
         FROM TM_Tasks
         WHERE user_id = :p1
           AND due_date < SYSDATE
           AND status NOT IN ('done','cancelled')
         $extraWhere
         ORDER BY due_date ASC",
        $extraParams
    );
} else {
    // all
    $stmt = tm_exec(
        "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
                category, custom_category, priority, color, notes, status
         FROM TM_Tasks
         WHERE user_id = :p1
         $extraWhere
         ORDER BY due_date ASC",
        $extraParams
    );
}

$tasks = tm_fetch_all($stmt);

// Resolve CLOB/LOB fields to plain strings
$tasks = array_map(function($row) {
    if (isset($row['notes'])) {
        if ($row['notes'] instanceof OCILob) {
            $row['notes'] = $row['notes']->load();
        } elseif (is_resource($row['notes'])) {
            $row['notes'] = stream_get_contents($row['notes']);
        }
        $row['notes'] = (string)($row['notes'] ?? '');
    }
    return $row;
}, $tasks);

// ---- Helpers ----
function statusLabel(string $s): string {
    return match($s) {
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'review'      => 'Review',
        'done'        => 'Done',
        'cancelled'   => 'Cancelled',
        default       => ucfirst($s),
    };
}
function statusClass(string $s): string {
    return match($s) {
        'pending'     => 'status-pending',
        'in_progress' => 'status-in-progress',
        'review'      => 'status-review',
        'done'        => 'status-done',
        'cancelled'   => 'status-cancelled',
        default       => 'status-pending',
    };
}
function priorityLabel(string $p): string {
    return match($p) {
        'high' => 'High', 'mid' => 'Mid', 'low' => 'Low', default => ucfirst($p)
    };
}
function priorityClass(string $p): string {
    return match($p) { 'high'=>'pri-high','mid'=>'pri-mid','low'=>'pri-low', default=>'pri-mid' };
}
function buildUrl(array $overrides = []): string {
    global $view, $search, $filterCat, $filterPri, $dateFrom, $dateTo;
    $params = array_filter([
        'view' => $overrides['view'] ?? $view,
        'q'    => $overrides['q']    ?? $search,
        'cat'  => $overrides['cat']  ?? $filterCat,
        'pri'  => $overrides['pri']  ?? $filterPri,
        'from' => $overrides['from'] ?? $dateFrom,
        'to'   => $overrides['to']   ?? $dateTo,
    ], fn($v) => $v !== '');
    return 'TM_Tasks.php?' . http_build_query($params);
}
function categoryDisplay(array $row): string {
    if ($row['category'] === 'custom' && !empty($row['custom_category'])) {
        return htmlspecialchars($row['custom_category']);
    }
    return ucfirst(htmlspecialchars($row['category']));
}
function isOverdue(string $dueDate, string $status): bool {
    return $dueDate < date('Y-m-d') && !in_array($status, ['done','cancelled']);
}

// ── Notifications ─────────────────────────────────────────────────────────────
require_once 'TM_PHP/TM_NavNotif.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Tasks - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
/* ── Page layout ───────────────────────────── */
.tasks-page { max-width: 960px; margin: 2rem auto; padding: 0 1.25rem 4rem; }

/* ── Page header ───────────────────────────── */
.tasks-header { margin-bottom: 1.5rem; }
.tasks-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
.tasks-header p  { font-size: 13px; color: var(--gray-500); margin: 0; }

/* ── Tab bar ────────────────────────────────── */
.tab-bar { display: flex; gap: 6px; margin-bottom: 1.5rem; }
.tab-btn {
    padding: 8px 20px; border-radius: 50px;
    font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif;
    text-decoration: none; border: 1.5px solid var(--border);
    color: var(--gray-500); background: var(--white);
    transition: all .18s;
}
.tab-btn:hover { background: var(--gray-100); color: var(--black); }
.tab-btn.active {
    background: var(--black); color: #fff; border-color: var(--black);
}
.tab-count {
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,.22); color: inherit;
    border-radius: 50px; font-size: 11px; font-weight: 700;
    padding: 1px 7px; margin-left: 5px;
}
.tab-btn:not(.active) .tab-count { background: var(--gray-100); color: var(--gray-500); }

/* ── Task table card ─────────────────────────  */
.task-table-card {
    background: var(--white); border-radius: var(--radius-md);
    border: 1px solid var(--gray-100);
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    overflow: hidden;
}
.task-table-wrap { overflow-x: auto; }
table.task-table {
    width: 100%; border-collapse: collapse;
    font-size: 13px;
}
table.task-table thead tr {
    background: var(--gray-100);
}
table.task-table thead th {
    padding: 11px 14px; text-align: left;
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    color: var(--gray-500); white-space: nowrap;
}
table.task-table tbody td {
    padding: 13px 14px; border-top: 1px solid var(--gray-100);
    vertical-align: middle;
}
table.task-table tbody tr:hover { background: var(--bg); }
table.task-table tbody tr.row-overdue td:first-child {
    border-left: 3px solid #ef4444;
}

.task-name-cell { font-weight: 600; color: var(--black); }
.task-name-cell .overdue-tag {
    display: inline-block; margin-left: 6px;
    font-size: 10px; font-weight: 700; color: #ef4444;
    background: #fee2e2; border-radius: 50px; padding: 1px 7px;
    vertical-align: middle;
}
.task-date { color: var(--gray-500); white-space: nowrap; }
.task-date.overdue-date { color: #ef4444; font-weight: 600; }

/* ── Status pills ────────────────────────────  */
.status-pill {
    display: inline-block; border-radius: 50px;
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    white-space: nowrap;
}
.status-pending     { background: #f3f4f6; color: #6b7280; }
.status-in-progress { background: #dbeafe; color: #1d4ed8; }
.status-review      { background: #fef9c3; color: #92400e; }
.status-done        { background: #dcfce7; color: #15803d; }
.status-cancelled   { background: #fee2e2; color: #b91c1c; }

/* ── Priority pills ──────────────────────────  */
.pri-pill {
    display: inline-block; border-radius: 50px;
    font-size: 11px; font-weight: 700; padding: 3px 10px;
}
.pri-high { background: #fee2e2; color: #b91c1c; }
.pri-mid  { background: #fef9c3; color: #78350f; }
.pri-low  { background: #dcfce7; color: #15803d; }

/* ── Color dot ───────────────────────────────  */
.color-dot {
    display: inline-block; width: 10px; height: 10px;
    border-radius: 50%; margin-right: 6px; flex-shrink: 0;
    vertical-align: middle;
}

/* ── Action btns ─────────────────────────────  */
.btn-quick-done {
    padding: 5px 12px; border-radius: 50px;
    font-size: 11px; font-weight: 700; font-family: 'Poppins', sans-serif;
    border: 1.5px solid #16a34a; color: #16a34a; background: transparent;
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
.btn-quick-done:hover { background: #dcfce7; }

/* ── Empty state ─────────────────────────────  */
.empty-state {
    padding: 4rem 2rem; text-align: center;
}
.empty-state i { font-size: 2.5rem; color: var(--gray-300); margin-bottom: 1rem; }
.empty-state h3 { font-size: 1rem; font-weight: 700; margin: 0 0 .4rem; color: var(--gray-500); }
.empty-state p  { font-size: 13px; color: var(--gray-400); margin: 0; }

/* ── Quick-done form (hidden) ────────────────  */
.quick-done-form { display: none; }

/* ── Filter bar ──────────────────────────────  */
.filter-bar { margin-bottom: 1.25rem; }
.filter-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.filter-search { position: relative; flex: 1 1 180px; }
.filter-search i {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: var(--gray-400); font-size: 13px; pointer-events: none;
}
.filter-input {
    width: 100%; padding: 8px 12px 8px 32px;
    border: 1.5px solid var(--border); border-radius: 8px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    background: var(--white); color: var(--black); transition: border-color .15s;
    box-sizing: border-box;
}
.filter-input:focus { outline: none; border-color: var(--black); }
.filter-select {
    padding: 8px 10px; border: 1.5px solid var(--border); border-radius: 8px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    background: var(--white); color: var(--black); cursor: pointer;
    transition: border-color .15s;
}
.filter-select:focus { outline: none; border-color: var(--black); }
.btn-filter-apply {
    padding: 8px 18px; border-radius: 8px;
    font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif;
    background: var(--black); color: #fff; border: none;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: opacity .15s;
}
.btn-filter-apply:hover { opacity: .85; }
.btn-filter-clear {
    padding: 8px 14px; border-radius: 8px;
    font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif;
    border: 1.5px solid var(--border); color: var(--gray-500);
    background: transparent; text-decoration: none; transition: background .15s;
}
.btn-filter-clear:hover { background: var(--gray-100); }

/* ── Logout modal (reuse from dashboard) ─────  */
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
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars(tm_uname()) ?></strong></span>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php" class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"    class="btn-logout">Tasks</a>
        <a href="TM_Activity.php" class="btn-logout">Activity</a>
        <!-- Notification Bell -->
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

<?php
// Count tasks for tab badges (always query all three counts)
$cntAll = tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1", [$uid]
))[0]['N'] ?? 0;

$cntMissing = tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks
     WHERE user_id=:p1 AND due_date < SYSDATE AND status NOT IN ('done','cancelled')",
    [$uid]
))[0]['N'] ?? 0;

$cntDone = tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1 AND status='done'", [$uid]
))[0]['N'] ?? 0;
?>

<div class="tasks-page">

    <div class="tasks-header">
        <h1>Tasks</h1>
        <p>Browse, filter, and manage all your tasks in one place.</p>
    </div>

    <!-- Tab bar -->
    <div class="tab-bar">
        <a href="<?= buildUrl(['view'=>'all']) ?>"     class="tab-btn <?= $view==='all'     ? 'active' : '' ?>">
            All <span class="tab-count"><?= (int)$cntAll ?></span>
        </a>
        <a href="<?= buildUrl(['view'=>'missing']) ?>" class="tab-btn <?= $view==='missing' ? 'active' : '' ?>">
            Missing <span class="tab-count"><?= (int)$cntMissing ?></span>
        </a>
        <a href="<?= buildUrl(['view'=>'done']) ?>"    class="tab-btn <?= $view==='done'    ? 'active' : '' ?>">
            Done <span class="tab-count"><?= (int)$cntDone ?></span>
        </a>
    </div>

    <!-- Search & Filter bar -->
    <form method="get" action="TM_Tasks.php" class="filter-bar">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>"/>
        <div class="filter-row">
            <div class="filter-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" class="filter-input"
                       placeholder="Search tasks…"
                       value="<?= htmlspecialchars($search) ?>"/>
            </div>
            <select name="cat" class="filter-select">
                <option value="">All Categories</option>
                <?php foreach (['errands','work','school','personal','health','finance','custom'] as $c): ?>
                <option value="<?= $c ?>" <?= $filterCat===$c?'selected':'' ?>><?= ucfirst($c) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="pri" class="filter-select">
                <option value="">All Priorities</option>
                <option value="high" <?= $filterPri==='high'?'selected':'' ?>>High</option>
                <option value="mid"  <?= $filterPri==='mid' ?'selected':'' ?>>Mid</option>
                <option value="low"  <?= $filterPri==='low' ?'selected':'' ?>>Low</option>
            </select>
            <input type="date" name="from" class="filter-select"
                   value="<?= htmlspecialchars($dateFrom) ?>" title="Due from"/>
            <input type="date" name="to"   class="filter-select"
                   value="<?= htmlspecialchars($dateTo) ?>"   title="Due to"/>
            <button type="submit" class="btn-filter-apply">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if ($search || $filterCat || $filterPri || $dateFrom || $dateTo): ?>
            <a href="TM_Tasks.php?view=<?= $view ?>" class="btn-filter-clear">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Task table -->
    <div class="task-table-card">
        <?php if (empty($tasks)): ?>
        <div class="empty-state">
            <?php if ($view === 'missing'): ?>
                <i class="fa-solid fa-circle-check"></i>
                <h3>No overdue tasks</h3>
                <p>You're all caught up — nothing is past its due date.</p>
            <?php elseif ($view === 'done'): ?>
                <i class="fa-solid fa-flag-checkered"></i>
                <h3>No completed tasks yet</h3>
                <p>Mark tasks as done and they'll show up here.</p>
            <?php else: ?>
                <i class="fa-solid fa-list-check"></i>
                <h3>No tasks yet</h3>
                <p>Go to the <a href="TM_Calendar.php">Calendar</a> to add your first task.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="task-table-wrap">
            <table class="task-table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Category</th>
                        <th>Due Date</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <?php if ($view !== 'done'): ?>
                        <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t):
                    $overdue = isOverdue($t['DUE_DATE'] ?? $t['due_date'], $t['STATUS'] ?? $t['status']);
                    $dueDate = $t['DUE_DATE'] ?? $t['due_date'];
                    $status  = $t['STATUS']   ?? $t['status'];
                    $taskId  = $t['TASK_ID']  ?? $t['task_id'];
                    $taskName= $t['TASK_NAME']?? $t['task_name'];
                    $cat     = $t['CATEGORY'] ?? $t['category'];
                    $ccat    = $t['CUSTOM_CATEGORY'] ?? $t['custom_category'];
                    $pri     = $t['PRIORITY'] ?? $t['priority'];
                    $color   = $t['COLOR']    ?? $t['color'];
                ?>
                <tr class="<?= $overdue ? 'row-overdue' : '' ?>">
                    <!-- Task name -->
                    <td class="task-name-cell">
                        <span class="color-dot" style="background:<?= htmlspecialchars($color) ?>"></span>
                        <?= htmlspecialchars($taskName) ?>
                        <?php if ($overdue): ?>
                            <span class="overdue-tag">Overdue</span>
                        <?php endif; ?>
                    </td>

                    <!-- Category -->
                    <td>
                        <?php
                        if ($cat === 'custom' && !empty($ccat)) {
                            echo htmlspecialchars($ccat);
                        } else {
                            echo ucfirst(htmlspecialchars($cat));
                        }
                        ?>
                    </td>

                    <!-- Due date -->
                    <td class="task-date <?= $overdue ? 'overdue-date' : '' ?>">
                        <?= htmlspecialchars(date('M j, Y', strtotime($dueDate))) ?>
                    </td>

                    <!-- Priority -->
                    <td>
                        <span class="pri-pill <?= priorityClass($pri) ?>">
                            <?= priorityLabel($pri) ?>
                        </span>
                    </td>

                    <!-- Status -->
                    <td>
                        <span class="status-pill <?= statusClass($status) ?>">
                            <?= statusLabel($status) ?>
                        </span>
                    </td>

                    <!-- Quick-done action (hidden on Done tab) -->
                    <?php if ($view !== 'done'): ?>
                    <td>
                        <?php if ($status !== 'done' && $status !== 'cancelled'): ?>
                        <form method="POST" action="TM_PHP/TM_TaskActions.php" class="quick-done-form" id="qdf-<?= (int)$taskId ?>">
                            <input type="hidden" name="action"    value="quick_done"/>
                            <input type="hidden" name="id"        value="<?= (int)$taskId ?>"/>
                        </form>
                        <button class="btn-quick-done"
                                onclick="submitQuickDone(<?= (int)$taskId ?>)"
                                title="Mark as done">
                            <i class="fa-solid fa-check"></i> Done
                        </button>
                        <?php else: ?>
                        <span style="color:var(--gray-300);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div><!-- /.task-table-card -->

</div><!-- /.tasks-page -->

<div class="toast" id="toast"></div>

<script>
// Logout modal
(function(){
    var btn    = document.getElementById('logoutBtn');
    var modal  = document.getElementById('logoutModal');
    var cancel = document.getElementById('logoutCancel');
    if (!btn) return;
    btn.addEventListener('click', function(e){ e.preventDefault(); modal.classList.add('active'); });
    cancel.addEventListener('click', function(){ modal.classList.remove('active'); });
    modal.addEventListener('click', function(e){ if(e.target===modal) modal.classList.remove('active'); });
})();

// Quick-done submit
function submitQuickDone(id) {
    if (!confirm('Mark this task as done?')) return;
    document.getElementById('qdf-' + id).submit();
}
</script>
<script src="TM_JS/TM_App.js"></script>
</body>
</html>