<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash = tm_get_flash();
$uid   = tm_uid();

// Which tab is active: all | missing | done | board
$view = $_GET['view'] ?? 'all';
if (!in_array($view, ['all', 'missing', 'done', 'board'])) { $view = 'all'; }

// ── Search & filter params (URL-driven, so results are bookmarkable) ──────────
$search    = trim($_GET['q']    ?? '');
$filterCat = trim($_GET['cat']  ?? '');
$filterPri = trim($_GET['pri']  ?? '');
$dateFrom  = trim($_GET['from']  ?? '');
$dateTo    = trim($_GET['to']   ?? '');
$filterTeam = (int)($_GET['team'] ?? 0); // Feature 8: team filter

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
// Feature 8: team filter — restrict to tasks owned by members of the selected team
if ($filterTeam > 0) {
    $extraWhere .= " AND user_id IN (SELECT user_id FROM TM_TeamMembers WHERE team_id = :p" . (count($extraParams)+1) . ")";
    $extraParams[] = $filterTeam;
}

// ── Sort params ────────────────────────────────────────────────────────────────
$sortCol = $_GET['sort']    ?? 'due_date';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$allowedSorts = ['task_name','category','due_date','priority','status'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'due_date';

// Priority needs a custom sort order (high > mid > low)
$sortExpr = match($sortCol) {
    'priority' => "CASE priority WHEN 'high' THEN 1 WHEN 'mid' THEN 2 ELSE 3 END",
    'status'   => "CASE status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'review' THEN 3 WHEN 'done' THEN 4 ELSE 5 END",
    default    => $sortCol,
};
$sortSql = "$sortExpr " . strtoupper($sortDir);

// Build query based on active tab
// Board view fetches all non-cancelled tasks grouped by status — skip the list query.
// $stmt is initialised here so static analysis never sees it as possibly undefined.
$stmt = null;
if ($view === 'board') {
    $tasks = []; // placeholder; board section does its own query below
} elseif ($view === 'done') {
    $stmt = tm_exec(
        "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
                category, custom_category, priority, color, notes, status
         FROM TM_Tasks
         WHERE user_id = :p1 AND status = 'done'
         $extraWhere
         ORDER BY $sortSql",
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
         ORDER BY $sortSql",
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
         ORDER BY $sortSql",
        $extraParams
    );
}

$tasks = ($view !== 'board') ? tm_fetch_all($stmt) : [];

// Resolve CLOB/LOB fields to plain strings (list views only)
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
    global $view, $search, $filterCat, $filterPri, $dateFrom, $dateTo, $filterTeam, $sortCol, $sortDir;
    $params = array_filter([
        'view' => $overrides['view'] ?? $view,
        'q'    => $overrides['q']    ?? $search,
        'cat'  => $overrides['cat']  ?? $filterCat,
        'pri'  => $overrides['pri']  ?? $filterPri,
        'from' => $overrides['from'] ?? $dateFrom,
        'to'   => $overrides['to']   ?? $dateTo,
        'team' => $overrides['team'] ?? ($filterTeam > 0 ? $filterTeam : ''),
        'sort' => $overrides['sort'] ?? $sortCol,
        'dir'  => $overrides['dir']  ?? $sortDir,
    ], fn($v) => $v !== '' && $v !== 'due_date' || isset($overrides['sort']));
    // Always include sort/dir if they are non-default
    if (($overrides['sort'] ?? $sortCol) !== 'due_date' || ($overrides['dir'] ?? $sortDir) !== 'asc') {
        $params['sort'] = $overrides['sort'] ?? $sortCol;
        $params['dir']  = $overrides['dir']  ?? $sortDir;
    }
    $params = array_filter([
        'view' => $overrides['view'] ?? $view,
        'q'    => $overrides['q']    ?? $search,
        'cat'  => $overrides['cat']  ?? $filterCat,
        'pri'  => $overrides['pri']  ?? $filterPri,
        'from' => $overrides['from'] ?? $dateFrom,
        'to'   => $overrides['to']   ?? $dateTo,
        'team' => $overrides['team'] ?? ($filterTeam > 0 ? $filterTeam : ''),
        'sort' => $overrides['sort'] ?? ($sortCol !== 'due_date' ? $sortCol : ''),
        'dir'  => $overrides['dir']  ?? ($sortDir !== 'asc'     ? $sortDir : ''),
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

// ── Feature 8: Load teams for the filter dropdown ────────────────────────────
$_teamStmt = tm_exec(
    "SELECT t.team_id, t.team_name FROM TM_Teams t
     JOIN TM_TeamMembers m ON m.team_id = t.team_id
     WHERE m.user_id = :p1
     ORDER BY t.team_name",
    [$uid]
);
$myTeams = tm_fetch_all($_teamStmt);

// ── Notifications ─────────────────────────────────────────────────────────────
require_once 'TM_PHP/TM_NavNotif.php';
?>
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
/* Feature 10 — Export buttons */
.tasks-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 1rem; }
.tasks-header h1 { margin: 0; }
.tasks-header p { margin: .2rem 0 0; }
.export-btns { display: flex; gap: 8px; flex-shrink: 0; margin-top: 4px; }
.btn-export {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 50px; font-size: 12px; font-weight: 600;
    font-family: 'Poppins', sans-serif; text-decoration: none;
    background: var(--white); border: 1.5px solid var(--border);
    color: var(--gray-500); transition: all .2s; cursor: pointer;
}
.btn-export:hover { background: var(--gray-100); color: var(--black); border-color: var(--black); }
.btn-export-report { background: var(--black); color: var(--white); border-color: var(--black); }
.btn-export-report:hover { opacity: .85; color: var(--white); }

.btn-quick-done {    padding: 5px 12px; border-radius: 50px;
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

/* ── Kanban board ────────────────────────────  */
.kanban-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    align-items: start;
}
@media (max-width: 900px) {
    .kanban-board { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
    .kanban-board { grid-template-columns: 1fr; }
}

.kanban-col {
    background: var(--bg);
    border-radius: var(--radius-md);
    border: 1px solid var(--gray-100);
    min-height: 200px;
    display: flex;
    flex-direction: column;
}
.kanban-col-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem 1rem;
    border-bottom: 1px solid var(--gray-100);
    background: var(--white);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    position: sticky;
    top: 0;
}
.kanban-col-title {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--gray-500);
}
.kanban-col-count {
    font-size: 11px;
    font-weight: 700;
    background: var(--gray-100);
    color: var(--gray-500);
    border-radius: 50px;
    padding: 1px 8px;
}
/* Column accent colours */
.kanban-col[data-status="pending"]     .kanban-col-header { border-top: 3px solid #9ca3af; }
.kanban-col[data-status="in_progress"] .kanban-col-header { border-top: 3px solid #3b82f6; }
.kanban-col[data-status="review"]      .kanban-col-header { border-top: 3px solid #eab308; }
.kanban-col[data-status="done"]        .kanban-col-header { border-top: 3px solid #22c55e; }

.kanban-col[data-status="pending"]     .kanban-col-title { color: #6b7280; }
.kanban-col[data-status="in_progress"] .kanban-col-title { color: #1d4ed8; }
.kanban-col[data-status="review"]      .kanban-col-title { color: #92400e; }
.kanban-col[data-status="done"]        .kanban-col-title { color: #15803d; }

.kanban-col[data-status="in_progress"] .kanban-col-count { background: #dbeafe; color: #1d4ed8; }
.kanban-col[data-status="review"]      .kanban-col-count { background: #fef9c3; color: #92400e; }
.kanban-col[data-status="done"]        .kanban-col-count { background: #dcfce7; color: #15803d; }

.kanban-cards {
    padding: .6rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    min-height: 80px;
}
/* Drop-zone highlight */
.kanban-cards.drag-over {
    background: rgba(59,130,246,.07);
    border-radius: 0 0 var(--radius-md) var(--radius-md);
    outline: 2px dashed #93c5fd;
    outline-offset: -4px;
}

/* Individual task card */
.kanban-card {
    background: var(--white);
    border: 1px solid var(--gray-100);
    border-radius: 8px;
    padding: .75rem;
    cursor: grab;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    transition: box-shadow .15s, opacity .15s;
    user-select: none;
}
.kanban-card:active { cursor: grabbing; }
.kanban-card.dragging {
    opacity: .45;
    box-shadow: 0 6px 20px rgba(0,0,0,.15);
}

.kanban-card-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--black);
    margin-bottom: .4rem;
    line-height: 1.4;
    display: flex;
    align-items: flex-start;
    gap: 6px;
}
.kanban-card-name .color-dot {
    flex-shrink: 0;
    margin-top: 3px;
}
.kanban-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
    margin-top: .35rem;
}
.kanban-card-cat {
    font-size: 11px;
    color: var(--gray-400);
    background: var(--bg);
    border-radius: 50px;
    padding: 1px 7px;
    font-weight: 600;
}
.kanban-card-due {
    font-size: 11px;
    color: var(--gray-400);
    margin-left: auto;
}
.kanban-card-due.overdue { color: #ef4444; font-weight: 700; }

/* Drop placeholder ghost card */
.kanban-placeholder {
    border: 2px dashed #93c5fd;
    border-radius: 8px;
    height: 60px;
    background: rgba(59,130,246,.05);
}

/* Saving spinner overlay on card */
.kanban-card.saving { opacity: .6; pointer-events: none; }

/* ── Sortable column headers ──────────────────  */
.th-sort {
    cursor: pointer; user-select: none;
    white-space: nowrap;
    display: inline-flex; align-items: center; gap: 5px;
    text-decoration: none; color: inherit;
}
.th-sort:hover { color: var(--black); }
.th-sort .sort-icon { font-size: 10px; color: var(--gray-400); }
.th-sort.active { color: var(--black); }
.th-sort.active .sort-icon { color: var(--black); }

/* ── Quick-done green confirm button ─────────────────────  */
.pc-modal-confirm-green {
    padding: 9px 22px; border-radius: 50px;
    font-size: 13px; font-weight: 700; font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff; border: none; cursor: pointer;
    transition: all .2s; display: inline-flex; align-items: center; gap: 6px;
}
.pc-modal-confirm-green:hover { opacity: .9; transform: translateY(-1px); }

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
        <a href="TM_Profile.php" class="btn-logout" title="My Profile" style="display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-user-circle"></i></a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php" class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"    class="btn-logout">Tasks</a>
        <a href="TM_Activity.php" class="btn-logout">Activity</a>
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

<?php
// Count tasks for tab badges (always query all three counts)
$_r = tm_fetch_all(tm_exec("SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1", [$uid]));
$cntAll = (int)($_r[0]['n'] ?? $_r[0]['N'] ?? 0);

$_r = tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks
     WHERE user_id=:p1 AND due_date < SYSDATE AND status NOT IN ('done','cancelled')",
    [$uid]
));
$cntMissing = (int)($_r[0]['n'] ?? $_r[0]['N'] ?? 0);

$_r = tm_fetch_all(tm_exec("SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1 AND status='done'", [$uid]));
$cntDone = (int)($_r[0]['n'] ?? $_r[0]['N'] ?? 0);
?>

<div class="tasks-page">

    <div class="tasks-header">
        <h1>Tasks</h1>
        <p>Browse, filter, and manage all your tasks in one place.</p>
        <!-- Feature 10: CSV / Report Export (IM101 Week 14 — Data Warehousing) -->
        <div class="export-btns">
            <a href="TM_PHP/TM_TaskActions.php?action=export&format=csv"
               class="btn-export" title="Download all tasks as CSV">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </a>
            <a href="TM_PHP/TM_TaskActions.php?action=export&format=html"
               class="btn-export btn-export-report" title="Download printable HTML report">
                <i class="fa-solid fa-file-lines"></i> Export Report
            </a>
        </div>
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
        <a href="<?= buildUrl(['view'=>'board']) ?>"   class="tab-btn <?= $view==='board'   ? 'active' : '' ?>">
            <i class="fa-solid fa-table-columns" style="font-size:11px;"></i> Board
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
            <?php if (!empty($myTeams)): ?>
            <select name="team" class="filter-select" title="Filter by team">
                <option value="">All Teams</option>
                <?php foreach ($myTeams as $t):
                    $tId = (int)($t['team_id'] ?? 0);
                ?>
                <option value="<?= $tId ?>" <?= $filterTeam===$tId?'selected':'' ?>>
                    &#127991; <?= htmlspecialchars($t['team_name'] ?? '') ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="date" name="from" class="filter-select"
                   value="<?= htmlspecialchars($dateFrom) ?>" title="Due from"/>
            <input type="date" name="to"   class="filter-select"
                   value="<?= htmlspecialchars($dateTo) ?>"   title="Due to"/>
            <button type="submit" class="btn-filter-apply" id="filterSubmitBtn" style="display:none;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if ($search || $filterCat || $filterPri || $dateFrom || $dateTo || $filterTeam): ?>
            <a href="TM_Tasks.php?view=<?= $view ?>" class="btn-filter-clear">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Task table -->
    <div class="task-table-card">
        <?php if (empty($tasks)): ?>
        <div class="empty-state">
            <?php
            $hasFilters = $search !== '' || $filterCat !== '' || $filterPri !== '' || $dateFrom !== '' || $dateTo !== '';
            if ($hasFilters): ?>
                <i class="fa-solid fa-magnifying-glass"></i>
                <h3>No results found</h3>
                <p>No tasks match your search or filters. <a href="TM_Tasks.php?view=<?= htmlspecialchars($view) ?>">Clear filters</a> to see all tasks.</p>
            <?php elseif ($view === 'missing'): ?>
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
                        <?php
                        // Helper: build a sortable <th> link
                        // Clicking the same column toggles asc/desc; clicking a new column defaults to asc
                        function thSort(string $col, string $label, string $currentCol, string $currentDir): string {
                            $isActive = $currentCol === $col;
                            $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
                            $icon     = $isActive
                                ? ($currentDir === 'asc' ? '▲' : '▼')
                                : '↕';
                            $url = buildUrl(['sort' => $col, 'dir' => $nextDir]);
                            $cls = $isActive ? 'th-sort active' : 'th-sort';
                            return "<th><a href=\"{$url}\" class=\"{$cls}\">{$label} <span class=\"sort-icon\">{$icon}</span></a></th>";
                        }
                        echo thSort('task_name', 'Task',     $sortCol, $sortDir);
                        echo thSort('category',  'Category', $sortCol, $sortDir);
                        echo thSort('due_date',  'Due Date', $sortCol, $sortDir);
                        echo thSort('priority',  'Priority', $sortCol, $sortDir);
                        echo thSort('status',    'Status',   $sortCol, $sortDir);
                        ?>
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
                <tr class="<?= $overdue ? 'row-overdue' : '' ?>"
                    data-task-id="<?= (int)$taskId ?>"
                    title="Click to view details"
                    style="cursor:pointer;"
                >
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
                                onclick="event.stopPropagation(); submitQuickDone(<?= (int)$taskId ?>)"
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

    <?php if ($view === 'board'):
        // ── Fetch all non-cancelled tasks for the board ──────────
        $boardStmt = tm_exec(
            "SELECT task_id, task_name, TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
                    category, custom_category, priority, color, status
             FROM TM_Tasks
             WHERE user_id = :p1
               AND status NOT IN ('cancelled')
             ORDER BY due_date ASC",
            [$uid]
        );
        $boardTasks = tm_fetch_all($boardStmt);

        // Group by status
        $cols = ['pending' => [], 'in_progress' => [], 'review' => [], 'done' => []];
        foreach ($boardTasks as $bt) {
            $st = $bt['STATUS'] ?? $bt['status'] ?? 'pending';
            if (isset($cols[$st])) $cols[$st][] = $bt;
        }
        $colLabels = [
            'pending'     => 'Pending',
            'in_progress' => 'In Progress',
            'review'      => 'Review',
            'done'        => 'Done',
        ];
    ?>
    <!-- Kanban board -->
    <div class="kanban-board" id="kanbanBoard">
        <?php foreach ($cols as $colStatus => $colCards): ?>
        <div class="kanban-col" data-status="<?= $colStatus ?>">
            <div class="kanban-col-header">
                <span class="kanban-col-title"><?= $colLabels[$colStatus] ?></span>
                <span class="kanban-col-count" id="colCount-<?= $colStatus ?>"><?= count($colCards) ?></span>
            </div>
            <div class="kanban-cards" id="col-<?= $colStatus ?>">
                <?php foreach ($colCards as $bc):
                    $bId   = $bc['TASK_ID']   ?? $bc['task_id'];
                    $bName = $bc['TASK_NAME'] ?? $bc['task_name'];
                    $bDue  = $bc['DUE_DATE']  ?? $bc['due_date'];
                    $bCat  = $bc['CATEGORY']  ?? $bc['category'];
                    $bCcat = $bc['CUSTOM_CATEGORY'] ?? $bc['custom_category'] ?? '';
                    $bPri  = $bc['PRIORITY']  ?? $bc['priority'];
                    $bCol  = $bc['COLOR']     ?? $bc['color'];
                    $bSt   = $bc['STATUS']    ?? $bc['status'];
                    $bOver = $bDue < date('Y-m-d') && !in_array($bSt, ['done','cancelled']);
                    $bCatLabel = ($bCat === 'custom' && $bCcat) ? $bCcat : ucfirst($bCat);
                ?>
                <div class="kanban-card"
                     draggable="true"
                     data-id="<?= (int)$bId ?>"
                     data-task-id="<?= (int)$bId ?>"
                     data-status="<?= htmlspecialchars($bSt) ?>"
                     data-name="<?= htmlspecialchars($bName) ?>"
                     data-due="<?= htmlspecialchars($bDue) ?>"
                     data-pri="<?= htmlspecialchars($bPri) ?>"
                     data-cat="<?= htmlspecialchars($bCat) ?>"
                     data-ccat="<?= htmlspecialchars($bCcat) ?>"
                     data-color="<?= htmlspecialchars($bCol) ?>">
                    <div class="kanban-card-name">
                        <span class="color-dot" style="background:<?= htmlspecialchars($bCol) ?>"></span>
                        <?= htmlspecialchars($bName) ?>
                    </div>
                    <div class="kanban-card-meta">
                        <span class="kanban-card-cat"><?= htmlspecialchars($bCatLabel) ?></span>
                        <span class="pri-pill <?= priorityClass($bPri) ?>"><?= priorityLabel($bPri) ?></span>
                        <span class="kanban-card-due<?= $bOver ? ' overdue' : '' ?>">
                            <?= htmlspecialchars(date('M j', strtotime($bDue))) ?>
                            <?= $bOver ? '⚠' : '' ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div><!-- /.kanban-board -->
    <?php endif; ?>

</div><!-- /.tasks-page -->

<?php require_once 'TM_PHP/TM_TaskModal.php'; ?>

<!-- ── Quick-Done Confirmation Modal ─────────────────────── -->
<div id="qdConfirmModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(34,197,94,.12);">
            <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:1.4rem;"></i>
        </div>
        <div class="pc-modal-title">Mark as Done?</div>
        <div class="pc-modal-body">
            Mark <strong id="qdModalName"></strong> as completed?<br>
            <span style="font-size:12px;color:var(--gray-400);">You can change the status again later from Edit.</span>
        </div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel"
                    onclick="document.getElementById('qdConfirmModal').classList.remove('active')">
                Cancel
            </button>
            <button class="pc-modal-confirm-green" onclick="qdSubmit()">
                <i class="fa-solid fa-check"></i> Yes, Mark Done
            </button>
        </div>
    </div>
</div>
<!-- hidden form that does the actual POST -->
<form method="POST" action="TM_PHP/TM_TaskActions.php" id="qdSubmitForm" style="display:none">
    <input type="hidden" name="action" value="quick_done"/>
    <input type="hidden" name="id"     id="qdModalTaskId"/>
</form>

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

// Quick-done modal submit (Feature 7: AJAX + Undo toast)
function qdSubmit() {
    var id   = document.getElementById('qdModalTaskId').value;
    var name = document.getElementById('qdModalName').textContent || 'this task';
    document.getElementById('qdConfirmModal').classList.remove('active');

    var fd = new FormData();
    fd.append('action', 'quick_done');
    fd.append('id', id);

    fetch('TM_PHP/TM_TaskActions.php?format=json', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                showToast(data.error || 'Could not mark done.', 'error');
                return;
            }
            // Hide the row immediately
            var row = document.querySelector('[data-task-id="' + id + '"]');
            if (row) row.style.display = 'none';

            // Determine message
            var msg = data.data && data.data.recurrence
                ? 'Done! Next ' + data.data.recurrence + ' recurrence created.'
                : 'Task marked as done!';

            showUndoToast(msg, id, name);
        })
        .catch(function() {
            showToast('Network error — please try again.', 'error');
        });
}

// Feature 7: Show success toast with timed Undo button
function showUndoToast(msg, taskId, taskName) {
    var existing = document.getElementById('toast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = 'toast';
    toast.className = 'toast toast-success';
    toast.innerHTML =
        '<span class="toast-icon">✓</span>' +
        '<div class="toast-content">' +
            '<div class="toast-title">Success</div>' +
            '<div class="toast-msg">' + msg + '</div>' +
        '</div>' +
        '<button class="toast-undo" id="toastUndoBtn" title="Undo">Undo</button>' +
        '<button class="toast-close" onclick="this.parentElement.remove()">✕</button>';
    document.body.appendChild(toast);

    requestAnimationFrame(function(){
        requestAnimationFrame(function(){ toast.classList.add('show'); });
    });

    var undoBtn = document.getElementById('toastUndoBtn');
    var dismissed = false;

    // Undo click: AJAX call to undo_done
    undoBtn.addEventListener('click', function() {
        if (dismissed) return;
        dismissed = true;
        var fd2 = new FormData();
        fd2.append('action', 'undo_done');
        fd2.append('id', taskId);
        fetch('TM_PHP/TM_TaskActions.php?format=json', { method: 'POST', body: fd2 })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                toast.remove();
                if (d.ok) {
                    // Restore the row
                    var row = document.querySelector('[data-task-id="' + taskId + '"]');
                    if (row) row.style.display = '';
                    showToast('Undone! "' + taskName + '" restored.', 'success');
                } else {
                    showToast(d.error || 'Undo failed.', 'error');
                    location.reload();
                }
            })
            .catch(function(){ location.reload(); });
    });

    // Auto-dismiss after 8 seconds (longer than normal to allow undo)
    var timer = setTimeout(function() {
        if (!dismissed) {
            dismissed = true;
            toast.classList.remove('show');
            setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 400);
        }
    }, 8000);

    // Cancel timer if manually closed
    toast.querySelector('.toast-close').addEventListener('click', function(){
        dismissed = true; clearTimeout(timer);
    });
}

function submitQuickDone(id) {
    var row  = document.querySelector('[data-task-id="' + id + '"]');
    var nameEl = row ? row.querySelector('.task-name-cell') : null;
    var name = '';
    if (nameEl) {
        nameEl.childNodes.forEach(function(n) {
            if (n.nodeType === 3) name += n.textContent;
        });
        name = name.trim();
    }
    document.getElementById('qdModalName').textContent = name || 'this task';
    document.getElementById('qdModalTaskId').value = id;
    document.getElementById('qdConfirmModal').classList.add('active');
}
</script>
<script src="TM_JS/TM_App.js"></script>
<script>
// ── Auto-submit filters ────────────────────────────────────────────────────
(function () {
    const form = document.querySelector('.filter-bar');
    if (!form) return;

    // Selects and date inputs fire immediately on change
    form.querySelectorAll('select, input[type="date"]').forEach(function (el) {
        el.addEventListener('change', function () {
            form.submit();
        });
    });

    // Search input: debounce 500ms so it doesn't fire on every keystroke
    const searchInput = form.querySelector('input[name="q"]');
    if (searchInput) {
        let timer;
        searchInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { form.submit(); }, 500);
        });
    }
})();
</script>
<script>
// ── Kanban drag-and-drop ───────────────────────────────────────────────────
(function () {
    'use strict';

    var board = document.getElementById('kanbanBoard');
    if (!board) return; // only runs on board view

    var dragging   = null;   // the card element being dragged
    var placeholder = null;  // the ghost drop-slot element

    // ── Helper: show a toast message ──────────────────────────
    function showToast(msg, type) {
        var t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'toast ' + (type === 'error' ? 'toast-error' : 'toast-success');
        t.classList.add('show');
        setTimeout(function () { t.classList.remove('show'); }, 3000);
    }

    // ── Helper: update the column count badges ─────────────────
    function refreshColCounts() {
        board.querySelectorAll('.kanban-col').forEach(function (col) {
            var st    = col.getAttribute('data-status');
            var count = col.querySelectorAll('.kanban-card').length;
            var badge = document.getElementById('colCount-' + st);
            if (badge) badge.textContent = count;
        });
    }

    // ── Persist a status change via fetch ─────────────────────
    function persistStatus(card, newStatus) {
        card.classList.add('saving');

        var fd = new FormData();
        fd.append('action',         'edit');
        fd.append('id',             card.dataset.id);
        fd.append('name',           card.dataset.name);
        fd.append('startDate',      card.dataset.due);   // fallback — same as due
        fd.append('dueDate',        card.dataset.due);
        fd.append('category',       card.dataset.cat);
        fd.append('customCategory', card.dataset.ccat || '');
        fd.append('priority',       card.dataset.pri);
        fd.append('color',          card.dataset.color);
        fd.append('notes',          '');
        fd.append('status',         newStatus);

        fetch('TM_PHP/TM_TaskActions.php', { method: 'POST', body: fd })
            .then(function (res) {
                // TM_TaskActions redirects on success — any 2xx/3xx is fine
                card.dataset.status = newStatus;
                card.classList.remove('saving');
                refreshColCounts();
                showToast('Status updated to "' + newStatus.replace('_', ' ') + '"', 'success');
            })
            .catch(function () {
                card.classList.remove('saving');
                showToast('Could not save — please try again.', 'error');
                // Move card back to its original column
                var orig = document.getElementById('col-' + card.dataset.status);
                if (orig) orig.appendChild(card);
                refreshColCounts();
            });
    }

    // ── Make placeholder ───────────────────────────────────────
    function makePlaceholder() {
        var el = document.createElement('div');
        el.className = 'kanban-placeholder';
        return el;
    }

    // ── Drag events on cards (use event delegation) ────────────
    board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.kanban-card');
        if (!card) return;
        dragging = card;
        window._tmDragging = true;
        dragging.classList.add('dragging');
        placeholder = makePlaceholder();
        e.dataTransfer.effectAllowed = 'move';
        // Firefox requires setData
        e.dataTransfer.setData('text/plain', card.dataset.id);
    });

    board.addEventListener('dragend', function () {
        if (dragging) dragging.classList.remove('dragging');
        window._tmDragging = false;
        if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        dragging    = null;
        placeholder = null;
        board.querySelectorAll('.kanban-cards').forEach(function (c) {
            c.classList.remove('drag-over');
        });
    });

    // ── Drop-zone events on columns ────────────────────────────
    board.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        var col = e.target.closest('.kanban-col');
        if (!col || !dragging) return;

        var cards = col.querySelector('.kanban-cards');
        if (!cards) return;

        // Highlight the column
        board.querySelectorAll('.kanban-cards').forEach(function (c) {
            c.classList.remove('drag-over');
        });
        cards.classList.add('drag-over');

        // Position the placeholder before the nearest card below the cursor
        var afterCard = getDragAfterElement(cards, e.clientY);
        if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        if (afterCard) {
            cards.insertBefore(placeholder, afterCard);
        } else {
            cards.appendChild(placeholder);
        }
    });

    board.addEventListener('dragleave', function (e) {
        var cards = e.target.closest('.kanban-cards');
        if (cards && !cards.contains(e.relatedTarget)) {
            cards.classList.remove('drag-over');
        }
    });

    board.addEventListener('drop', function (e) {
        e.preventDefault();
        if (!dragging) return;

        var col = e.target.closest('.kanban-col');
        if (!col) return;

        var newStatus = col.getAttribute('data-status');
        var cards     = col.querySelector('.kanban-cards');
        if (!cards) return;

        // Insert card where the placeholder is
        if (placeholder && placeholder.parentNode === cards) {
            cards.insertBefore(dragging, placeholder);
        } else {
            cards.appendChild(dragging);
        }

        // Clean up
        cards.classList.remove('drag-over');
        if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);

        var oldStatus = dragging.dataset.status;
        if (newStatus !== oldStatus) {
            persistStatus(dragging, newStatus);
        }
    });

    // ── Helper: find the card the cursor is above ──────────────
    function getDragAfterElement(container, y) {
        var cards = Array.from(container.querySelectorAll('.kanban-card:not(.dragging)'));
        return cards.reduce(function (closest, child) {
            var box    = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

})();
</script>

<script>
// ── Notification auto-open: open task modal when arriving via ?open=ID ─────
// Also handles legacy ?highlight=ID (scroll + flash only, no modal)
(function () {
    var params = new URLSearchParams(window.location.search);

    // ?open=ID — mark-read already happened client-side; just open the modal
    var openId = params.get('open');
    if (openId && parseInt(openId, 10) > 0) {
        // Wait for TM_TaskModal.php JS to initialise (it runs in DOMContentLoaded)
        window.addEventListener('DOMContentLoaded', function () {
            if (typeof window.tmOpenView === 'function') {
                window.tmOpenView(openId);
            }
        });
        // Clean the URL so refreshing doesn't re-open the modal
        history.replaceState(null, '', window.location.pathname +
            (params.get('view') ? '?view=' + params.get('view') : ''));
        return;
    }

    // Legacy ?highlight=ID — scroll + flash
    var taskId = params.get('highlight');
    if (!taskId) return;
    var el = document.querySelector('[data-task-id="' + taskId + '"]');
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.style.transition = 'box-shadow 0.3s ease';
    el.style.boxShadow  = '0 0 0 3px var(--black)';
    setTimeout(function () { el.style.boxShadow = ''; }, 2000);
})();
</script>
</body>
</html>