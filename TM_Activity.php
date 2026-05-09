<?php
// =============================================
// TM_Activity.php — Personal activity feed
// =============================================
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$uid       = tm_uid();
$firstName = tm_uname();
$flash     = tm_get_flash();

// ── Filters ───────────────────────────────────────────────────────────────────
$filterAction = trim($_GET['action'] ?? '');
$filterType   = trim($_GET['type']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

$allowed_actions = ['create', 'edit', 'delete', 'status_change'];
$allowed_types   = ['task', 'user'];
if (!in_array($filterAction, $allowed_actions)) $filterAction = '';
if (!in_array($filterType,   $allowed_types))   $filterType   = '';

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = 'WHERE user_id = :p1';
$params = [$uid];

if ($filterAction !== '') {
    $params[] = $filterAction;
    $where   .= ' AND action = :p' . count($params);
}
if ($filterType !== '') {
    $params[] = $filterType;
    $where   .= ' AND entity_type = :p' . count($params);
}

// ── Total count for pagination ────────────────────────────────────────────────
$cntRow    = tm_fetch_one(tm_exec("SELECT COUNT(*) AS n FROM TM_AuditLog $where", $params));
$totalRows = (int)($cntRow['N'] ?? $cntRow['n'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Fetch page rows (Oracle pagination) ───────────────────────────────────────
$pParams   = $params;
$pParams[] = $offset + $perPage; // upper bound
$pParams[] = $offset;            // lower bound
$uIdx      = count($pParams) - 1;
$lIdx      = count($pParams);

$stmt = tm_exec(
    "SELECT * FROM (
         SELECT a.log_id, a.action, a.entity_type, a.entity_id,
                a.entity_name, a.old_value, a.new_value,
                TO_CHAR(a.created_at, 'YYYY-MM-DD HH24:MI:SS') AS created_at,
                ROWNUM AS rn
         FROM TM_AuditLog a
         $where
         ORDER BY a.created_at DESC
     )
     WHERE rn <= :p" . (count($pParams) - 1) . "
       AND rn >  :p" . count($pParams),
    $pParams
);
$rows = tm_fetch_all($stmt);

// ── Quick stats (all time, no filter) ────────────────────────────────────────
$statsStmt = tm_exec(
    "SELECT action, COUNT(*) AS cnt FROM TM_AuditLog
     WHERE user_id = :p1 GROUP BY action",
    [$uid]
);
$statsRows = tm_fetch_all($statsStmt);
$statMap   = [];
foreach ($statsRows as $s) {
    $statMap[strtolower($s['ACTION'] ?? $s['action'] ?? '')] = (int)($s['CNT'] ?? $s['cnt'] ?? 0);
}

// ── URL builder ───────────────────────────────────────────────────────────────
function buildActivityUrl(array $ov = []): string {
    global $filterAction, $filterType, $page;
    $action = array_key_exists('action', $ov) ? $ov['action'] : $filterAction;
    $type   = array_key_exists('type',   $ov) ? $ov['type']   : $filterType;
    $pg     = (int)(array_key_exists('page', $ov) ? $ov['page'] : $page);
    $p = array_filter([
        'action' => $action,
        'type'   => $type,
        'page'   => $pg,
    ], fn($v) => $v !== '' && $v !== 0 && $v !== 1);
    return 'TM_Activity.php' . ($p ? '?' . http_build_query($p) : '');
}

// ── Audit display helpers (declared once, used inside the feed loop) ──────────
function parseAuditKV(string $s): array {
    $map = [];
    foreach (explode(',', $s) as $part) {
        $part = trim($part);
        if (str_contains($part, ':')) {
            [$k, $v] = explode(':', $part, 2);
            $map[trim($k)] = trim($v);
        }
    }
    return $map;
}
function fmtStatus(string $s): string {
    return match($s) {
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'review'      => 'Under Review',
        'done'        => 'Done',
        'cancelled'   => 'Cancelled',
        default       => ucfirst($s),
    };
}
function fmtPri(string $p): string {
    return match($p) {
        'high' => 'High', 'mid' => 'Medium', 'low' => 'Low',
        default => ucfirst($p),
    };
}

// ── Notifications (bell) ──────────────────────────────────────────────────────
require_once 'TM_PHP/TM_NavNotif.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Activity - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
/* ── Page shell ──────────────────────────────── */
.activity-page { max-width: 760px; margin: 2rem auto; padding: 0 1.25rem 4rem; }
.activity-header { margin-bottom: 1.5rem; }
.activity-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
.activity-header p  { font-size: 13px; color: var(--gray-500); margin: 0; }

/* ── Stats strip ─────────────────────────────── */
.stats-strip { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.stat-pill {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 14px; border-radius: 50px;
    font-size: 12px; font-weight: 600;
    border: 1.5px solid var(--border); background: var(--white);
    color: var(--gray-500);
    cursor: pointer; text-decoration: none;
    transition: border-color .15s, background .15s, color .15s;
}
.stat-pill:hover { border-color: var(--black); color: var(--black); }
.stat-pill.active {
    background: var(--black); border-color: var(--black);
    color: #fff;
}
.stat-pill.active .num { color: #fff; }
.stat-pill i { font-size: 12px; }
.stat-pill .num { font-weight: 700; color: var(--black); }

/* ── Filter bar ──────────────────────────────── */
.filter-bar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 1.25rem; align-items: center; }
.filter-select {
    padding: 8px 10px; border: 1.5px solid var(--border); border-radius: 8px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    background: var(--white); color: var(--black); cursor: pointer; transition: border-color .15s;
}
.filter-select:focus { outline: none; border-color: var(--black); }
.btn-filter-apply {
    padding: 8px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif; background: var(--black); color: #fff;
    border: none; cursor: pointer; display: inline-flex; align-items: center;
    gap: 6px; transition: opacity .15s;
}
.btn-filter-apply:hover { opacity: .85; }
.btn-filter-clear {
    padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif; border: 1.5px solid var(--border);
    color: var(--gray-500); background: transparent; text-decoration: none; transition: background .15s;
}
.btn-filter-clear:hover { background: var(--gray-100); }
.result-count { font-size: 12px; color: var(--gray-500); margin-left: auto; }

/* ── Feed card ───────────────────────────────── */
.feed-card {
    background: var(--white); border: 1px solid var(--gray-100);
    border-radius: var(--radius-md); box-shadow: var(--shadow-sm); overflow: hidden;
}
.feed-list { list-style: none; margin: 0; padding: 0; }
.feed-item {
    display: flex; align-items: flex-start; gap: 13px;
    padding: 14px 20px; border-bottom: 1px solid var(--gray-100);
}
.feed-item:last-child { border-bottom: none; }

/* ── Icon circle ─────────────────────────────── */
.feed-icon {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 13px; margin-top: 1px;
}
.ic-create { background: #dcfce7; color: #15803d; }
.ic-edit   { background: #dbeafe; color: #1d4ed8; }
.ic-delete { background: #fee2e2; color: #b91c1c; }
.ic-status { background: #fef9c3; color: #92400e; }

/* ── Feed body ───────────────────────────────── */
.feed-body   { flex: 1; min-width: 0; }
.feed-action { font-size: 13px; font-weight: 700; color: var(--black); }
.feed-desc   { font-size: 13px; color: var(--gray-500); margin-top: 2px; line-height: 1.5; }
.feed-desc strong { color: var(--black); font-weight: 600; }
.feed-meta   { font-size: 11px; color: var(--gray-400); margin-top: 4px; }
.feed-changes  { margin-top: 6px; display: flex; flex-direction: column; gap: 4px; }
.feed-change-row {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 12px; color: var(--gray-500);
}
.chg-label {
    font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
    color: var(--gray-400); background: var(--gray-100);
    padding: 2px 7px; border-radius: 4px; flex-shrink: 0;
}
.chg-from {
    color: var(--gray-500); text-decoration: line-through; font-size: 12px;
}
.chg-arrow { font-size: 9px; color: var(--gray-300); }
.chg-to    { color: var(--black); font-weight: 600; font-size: 12px; }

/* ── Badges ──────────────────────────────────── */
.feed-badge {
    display: inline-block; font-size: 10px; font-weight: 700;
    padding: 2px 7px; border-radius: 50px; margin-left: 5px; vertical-align: middle;
}
.badge-task { background: #e0e7ff; color: #3730a3; }
.badge-user { background: #fce7f3; color: #9d174d; }

/* ── Empty ───────────────────────────────────── */
.feed-empty { text-align: center; padding: 4rem 2rem; }
.feed-empty i  { font-size: 2.5rem; color: var(--gray-300); margin-bottom: 1rem; }
.feed-empty h3 { font-size: 1rem; font-weight: 700; color: var(--gray-500); margin: 0 0 .35rem; }
.feed-empty p  { font-size: 13px; color: var(--gray-400); margin: 0; }

/* ── Pagination ──────────────────────────────── */
.pagination { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 1.5rem; }
.page-btn {
    padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif; border: 1.5px solid var(--border);
    background: var(--white); color: var(--black); text-decoration: none; transition: all .15s;
}
.page-btn:hover  { background: var(--gray-100); }
.page-btn.active { background: var(--black); color: #fff; border-color: var(--black); }
.page-btn.disabled { opacity: .4; pointer-events: none; }
.page-info { font-size: 12px; color: var(--gray-500); padding: 0 4px; }

/* ── Logout modal ────────────────────────────── */
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
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars($firstName) ?></strong></span>
        <a href="TM_Profile.php" class="btn-logout" title="My Profile" style="display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-user-circle"></i></a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"     class="btn-logout">Tasks</a>
        <a href="TM_Activity.php"  class="btn-logout" style="font-weight:700;">Activity</a>
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

<div class="activity-page">

    <div class="activity-header">
        <h1>Activity Feed</h1>
        <p>Everything you've done in TaskMate, in chronological order.</p>
    </div>

    <!-- Stats strip — click to filter -->
    <div class="stats-strip">
        <a href="TM_Activity.php" class="stat-pill<?= ($filterAction === '' && $filterType === '') ? ' active' : '' ?>">
            <i class="fa-solid fa-list" style="color:var(--gray-400)"></i>
            <span class="num"><?= array_sum($statMap) ?></span> All
        </a>
        <a href="<?= buildActivityUrl(['action' => 'create', 'type' => '']) ?>" class="stat-pill<?= $filterAction === 'create' ? ' active' : '' ?>">
            <i class="fa-solid fa-plus" style="color:#15803d"></i>
            <span class="num"><?= $statMap['create'] ?? 0 ?></span> Created
        </a>
        <a href="<?= buildActivityUrl(['action' => 'edit', 'type' => '']) ?>" class="stat-pill<?= $filterAction === 'edit' ? ' active' : '' ?>">
            <i class="fa-solid fa-pen" style="color:#1d4ed8"></i>
            <span class="num"><?= $statMap['edit'] ?? 0 ?></span> Edited
        </a>
        <a href="<?= buildActivityUrl(['action' => 'status_change', 'type' => '']) ?>" class="stat-pill<?= $filterAction === 'status_change' ? ' active' : '' ?>">
            <i class="fa-solid fa-arrow-right-arrow-left" style="color:#92400e"></i>
            <span class="num"><?= $statMap['status_change'] ?? 0 ?></span> Status Changes
        </a>
        <a href="<?= buildActivityUrl(['action' => 'delete', 'type' => '']) ?>" class="stat-pill<?= $filterAction === 'delete' ? ' active' : '' ?>">
            <i class="fa-solid fa-trash" style="color:#b91c1c"></i>
            <span class="num"><?= $statMap['delete'] ?? 0 ?></span> Deleted
        </a>
        <span class="result-count" style="margin-left:auto;font-size:12px;color:var(--gray-500);align-self:center">
            <?= number_format($totalRows) ?> event<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
    </div>
    <!-- Feed -->
    <div class="feed-card">
        <?php if (empty($rows)): ?>
        <div class="feed-empty">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <h3>No activity yet</h3>
            <p>Events will appear here as you create, edit, or complete tasks.</p>
        </div>
        <?php else: ?>
        <ul class="feed-list">
        <?php foreach ($rows as $row):
            $action     = strtolower($row['ACTION']      ?? $row['action']      ?? '');
            $entityType = strtolower($row['ENTITY_TYPE'] ?? $row['entity_type'] ?? '');
            $entityName = $row['ENTITY_NAME'] ?? $row['entity_name'] ?? '—';
            $oldVal     = $row['OLD_VALUE']   ?? $row['old_value']   ?? '';
            $newVal     = $row['NEW_VALUE']   ?? $row['new_value']   ?? '';
            $createdAt  = $row['CREATED_AT']  ?? $row['created_at']  ?? '';

            $iconClass = match($action) {
                'create'        => 'ic-create',
                'edit'          => 'ic-edit',
                'delete'        => 'ic-delete',
                'status_change' => 'ic-status',
                default         => 'ic-edit',
            };
            $iconGlyph = match($action) {
                'create'        => '<i class="fa-solid fa-plus"></i>',
                'edit'          => '<i class="fa-solid fa-pen"></i>',
                'delete'        => '<i class="fa-solid fa-trash"></i>',
                'status_change' => '<i class="fa-solid fa-arrow-right-arrow-left"></i>',
                default         => '<i class="fa-solid fa-pen"></i>',
            };
            $actionLabel = match($action) {
                'create'        => 'Created',
                'edit'          => 'Edited',
                'delete'        => 'Deleted',
                'status_change' => 'Status changed',
                default         => ucfirst($action),
            };
            $badgeClass = $entityType === 'task' ? 'badge-task' : 'badge-user';
            $badgeLabel = strtoupper($entityType);

            // Relative timestamp
            $ts   = strtotime($createdAt);
            $diff = time() - $ts;
            if ($diff < 60)         $timeAgo = 'Just now';
            elseif ($diff < 3600)   $timeAgo = floor($diff / 60) . 'm ago';
            elseif ($diff < 86400)  $timeAgo = floor($diff / 3600) . 'h ago';
            elseif ($diff < 604800) $timeAgo = floor($diff / 86400) . 'd ago';
            else                    $timeAgo = date('M j, Y', $ts);
        ?>
        <?php
        $old = parseAuditKV($oldVal);
        $new = parseAuditKV($newVal);

        // Build a clean description line based on action type
        $descParts = [];
        if ($action === 'status_change') {
            $from = fmtStatus($old['status'] ?? '');
            $to   = fmtStatus($new['status'] ?? '');
            if ($from && $to && $from !== $to) {
                $descParts[] = [
                    'label' => 'Status',
                    'from'  => $from,
                    'to'    => $to,
                ];
            }
            // Only show priority change if it actually changed
            $fromPri = fmtPri($old['pri'] ?? '');
            $toPri   = fmtPri($new['pri'] ?? '');
            if ($fromPri && $toPri && $fromPri !== $toPri) {
                $descParts[] = [
                    'label' => 'Priority',
                    'from'  => $fromPri,
                    'to'    => $toPri,
                ];
            }
        } elseif ($action === 'edit') {
            $fromPri = fmtPri($old['pri'] ?? '');
            $toPri   = fmtPri($new['pri'] ?? '');
            if ($fromPri && $toPri && $fromPri !== $toPri) {
                $descParts[] = ['label'=>'Priority','from'=>$fromPri,'to'=>$toPri];
            }
            $fromSt = fmtStatus($old['status'] ?? '');
            $toSt   = fmtStatus($new['status'] ?? '');
            if ($fromSt && $toSt && $fromSt !== $toSt) {
                $descParts[] = ['label'=>'Status','from'=>$fromSt,'to'=>$toSt];
            }
        } elseif ($action === 'create' && $newVal) {
            // "cat:work, pri:high, due:2025-06-01"
            $cat = ucfirst($new['cat'] ?? '');
            $pri = fmtPri($new['pri'] ?? '');
            $due = $new['due'] ?? '';
            if ($due) $due = date('M j, Y', strtotime($due));
            if ($cat)  $descParts[] = ['label'=>'Category','value'=>$cat];
            if ($pri)  $descParts[] = ['label'=>'Priority', 'value'=>$pri];
            if ($due)  $descParts[] = ['label'=>'Due',      'value'=>$due];
        }
        ?>
        <li class="feed-item">
            <div class="feed-icon <?= $iconClass ?>"><?= $iconGlyph ?></div>
            <div class="feed-body">
                <div class="feed-action">
                    <?= $actionLabel ?>
                    <span class="feed-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                </div>
                <div class="feed-desc">
                    <strong><?= htmlspecialchars($entityName) ?></strong>
                    <?php if (!empty($descParts)): ?>
                    <div class="feed-changes">
                        <?php foreach ($descParts as $dp): ?>
                        <div class="feed-change-row">
                            <span class="chg-label"><?= $dp['label'] ?></span>
                            <?php if (isset($dp['from'], $dp['to'])): ?>
                                <span class="chg-from"><?= htmlspecialchars($dp['from']) ?></span>
                                <i class="fa-solid fa-arrow-right chg-arrow"></i>
                                <span class="chg-to"><?= htmlspecialchars($dp['to']) ?></span>
                            <?php else: ?>
                                <span class="chg-to"><?= htmlspecialchars($dp['value']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="feed-meta">
                    <i class="fa-regular fa-clock" style="margin-right:3px"></i>
                    <?= htmlspecialchars($timeAgo) ?> &nbsp;·&nbsp; <?= htmlspecialchars($createdAt) ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <a href="<?= buildActivityUrl(['page' => $page - 1]) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        if ($start > 1) {
            echo '<a href="' . buildActivityUrl(['page' => 1]) . '" class="page-btn">1</a>';
            if ($start > 2) echo '<span class="page-info">…</span>';
        }
        for ($p = $start; $p <= $end; $p++) {
            echo '<a href="' . buildActivityUrl(['page' => $p]) . '" class="page-btn ' . ($p === $page ? 'active' : '') . '">' . $p . '</a>';
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="page-info">…</span>';
            echo '<a href="' . buildActivityUrl(['page' => $totalPages]) . '" class="page-btn">' . $totalPages . '</a>';
        }
        ?>
        <a href="<?= buildActivityUrl(['page' => $page + 1]) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>

</div><!-- /.activity-page -->

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
<script>
// ── Auto-submit filters ────────────────────────────────────────────────────
(function () {
    const form = document.querySelector('.filter-bar');
    if (!form) return;
    form.querySelectorAll('select').forEach(function (el) {
        el.addEventListener('change', function () { form.submit(); });
    });
})();
</script>
</body>
</html>
