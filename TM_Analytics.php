<?php
// =============================================
// TM_Analytics.php — Deep analytics page
// =============================================
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$uid       = tm_uid();
$firstName = tm_uname();
$flash     = tm_get_flash();
$oid       = tm_org_id();


$scopeWhere  = "(user_id = :uid_scope OR assigned_to = :uid_scope2
                 OR (is_org_task = 1 AND org_id = :oid_scope))";
$scopeParams = [$uid, $uid, $oid]; // self + assigned + org-wide + project member

// ── Notifications (bell) ──────────────────────────────────────────────────────
require_once 'TM_PHP/TM_NavNotif.php';

// ── Helper: safely get first column value from a single-row result ────────────
function _an_val(array $rows, string $key, $default = 0) {
    if (empty($rows)) return $default;
    $row = $rows[0];
    return $row[strtoupper($key)] ?? $row[strtolower($key)] ?? $default;
}

// ══════════════════════════════════════════════════════════════════════════════
// QUERY 1 — Completion rate by week (last 8 weeks)
// Count tasks whose done status_change landed in each ISO week.
// ══════════════════════════════════════════════════════════════════════════════
// Week start = Monday. Oracle: TRUNC(d) - MOD(TRUNC(d) - DATE'1970-01-05', 7)
// DATE'1970-01-05' is a known Monday used as epoch anchor.
$stmtWeekly = tm_exec(
    "SELECT week_start,
            SUM(completed) AS completed,
            SUM(total_due)  AS total_due
     FROM (
         SELECT (TRUNC(created_at) - MOD(TRUNC(created_at) - DATE'1970-01-05', 7)) AS week_start,
                1 AS completed, 0 AS total_due
         FROM TM_AuditLog al
         WHERE al.action   = 'status_change'
           AND al.new_value LIKE '%done%'
           AND al.created_at >= TRUNC(SYSDATE) - 56
           AND (
               al.user_id = :p1
               OR al.entity_id IN (
                   SELECT task_id FROM TM_Tasks
                   WHERE assigned_to = :p2
                      OR (is_org_task = 1 AND org_id = :p3)
               )
           )
         UNION ALL
         SELECT (TRUNC(due_date) - MOD(TRUNC(due_date) - DATE'1970-01-05', 7)) AS week_start,
                0 AS completed, 1 AS total_due
         FROM TM_Tasks
         WHERE (user_id = :p4 OR assigned_to = :p5
                OR (is_org_task = 1 AND org_id = :p6))
           AND due_date >= TRUNC(SYSDATE) - 56
           AND due_date <  TRUNC(SYSDATE) + 7
     )
     GROUP BY week_start
     ORDER BY week_start ASC",
    [$uid, $uid, $oid, $uid, $uid, $oid]
);
$weeklyRaw = tm_fetch_all($stmtWeekly);

// Build a full 8-week array (fill gaps with zeros)
$weeks = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = strtotime("monday this week -" . ($i * 7) . " days");
    // Oracle week_start = Monday (matched by DATE arithmetic in the SQL)
    $key = date('Y-m-d', $ts);
    $weeks[$key] = ['label' => date('M j', $ts), 'completed' => 0, 'total_due' => 0];
}
foreach ($weeklyRaw as $row) {
    $raw = $row['WEEK_START'] ?? $row['week_start'] ?? '';
    // Oracle may return "DD-MON-YY" or "YYYY-MM-DD" depending on NLS settings
    $ts  = strtotime($raw);
    $key = date('Y-m-d', $ts);
    if (isset($weeks[$key])) {
        $weeks[$key]['completed'] = (int)($row['COMPLETED'] ?? $row['completed'] ?? 0);
        $weeks[$key]['total_due'] = (int)($row['TOTAL_DUE'] ?? $row['total_due'] ?? 0);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// QUERY 2 — Most-missed deadlines by category
// Overdue = due_date < today AND status not done/cancelled
// Also includes tasks marked done_late (completed after due date)
// ══════════════════════════════════════════════════════════════════════════════
$stmtMissed = tm_exec(
    "SELECT CASE WHEN category = 'others' AND custom_category IS NOT NULL
                     THEN custom_category
                     ELSE INITCAP(category)
                END AS cat_label,
            COUNT(*) AS missed_count
     FROM TM_Tasks
     WHERE (user_id = :p1 OR assigned_to = :p2
            OR (is_org_task = 1 AND org_id = :p3))
       AND due_date < TRUNC(SYSDATE)
       AND status NOT IN ('done','cancelled')
     GROUP BY CASE WHEN category = 'others' AND custom_category IS NOT NULL
                   THEN custom_category
                   ELSE INITCAP(category)
              END
     ORDER BY COUNT(*) DESC",
    [$uid, $uid, $oid]
);
$missedRows = tm_fetch_all($stmtMissed);

// ══════════════════════════════════════════════════════════════════════════════
// QUERY 3 — Average days from start_date to done
// Uses TM_AuditLog to find when each task was marked done,
// then joins TM_Tasks for start_date.
// ══════════════════════════════════════════════════════════════════════════════
$stmtAvgDays = tm_exec(
    "SELECT ROUND(AVG(TRUNC(done_at) - TRUNC(t.start_date)), 1) AS avg_days,
            COUNT(*) AS sample_size
     FROM TM_Tasks t
     JOIN (
         SELECT entity_id,
                MIN(created_at) AS done_at
         FROM TM_AuditLog
         WHERE action    = 'status_change'
           AND new_value LIKE '%done%'
           AND entity_id IN (
               SELECT task_id FROM TM_Tasks
               WHERE user_id = :p1 OR assigned_to = :p2
                  OR (is_org_task = 1 AND org_id = :p3)
           )
         GROUP BY entity_id
     ) d ON d.entity_id = t.task_id
     WHERE (t.user_id = :p4 OR t.assigned_to = :p5
            OR (t.is_org_task = 1 AND t.org_id = :p6))
       AND t.status IN ('done', 'done_late')",
    [$uid, $uid, $oid, $uid, $uid, $oid]
);
$avgDaysRow  = tm_fetch_all($stmtAvgDays);
$avgDays     = _an_val($avgDaysRow, 'avg_days',    null);
$sampleSize  = (int)_an_val($avgDaysRow, 'sample_size', 0);

// ══════════════════════════════════════════════════════════════════════════════
// QUERY 4 — Current streak: consecutive days (ending today) with ≥1 task done
// ══════════════════════════════════════════════════════════════════════════════
$stmtDoneDays = tm_exec(
    "SELECT done_day FROM (
         SELECT DISTINCT TRUNC(created_at) AS done_day
         FROM TM_AuditLog
         WHERE user_id  = :p1
           AND action   = 'status_change'
           AND new_value LIKE '%done%'
     )
     ORDER BY done_day DESC",
    [$uid]
);
$doneDays = tm_fetch_all($stmtDoneDays);

$streak      = 0;
$streakBest  = 0;
$checkDate   = strtotime('today');
foreach ($doneDays as $dRow) {
    $raw = $dRow['DONE_DAY'] ?? $dRow['done_day'] ?? '';
    $day = strtotime(date('Y-m-d', strtotime($raw))); // normalise
    if ($day === $checkDate) {
        $streak++;
        $checkDate = strtotime('-1 day', $checkDate);
    } else {
        break; // gap found — streak ends
    }
}

// Best-ever streak (scan all done days)
$allDays   = array_map(fn($r) => strtotime(date('Y-m-d', strtotime($r['DONE_DAY'] ?? $r['done_day'] ?? 'now'))), $doneDays);
sort($allDays);
$runLen    = 0;
$prevDay   = null;
foreach ($allDays as $d) {
    if ($prevDay !== null && $d === strtotime('+1 day', $prevDay)) {
        $runLen++;
    } else {
        $runLen = 1;
    }
    if ($runLen > $streakBest) $streakBest = $runLen;
    $prevDay = $d;
}

// ══════════════════════════════════════════════════════════════════════════════
// QUERY 5 — Top-level summary numbers for the hero strip
// ══════════════════════════════════════════════════════════════════════════════
$fullScope = "(user_id=:p1 OR assigned_to=:p2
               OR (is_org_task=1 AND org_id=:p3))";
$cntDone    = (int)_an_val(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks WHERE $fullScope AND status IN ('done','done_late')",
    [$uid, $uid, $oid])), 'n');
$cntTotal   = (int)_an_val(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks WHERE $fullScope",
    [$uid, $uid, $oid])), 'n');
$cntOverdue = (int)_an_val(tm_fetch_all(tm_exec(
    "SELECT COUNT(*) AS n FROM TM_Tasks
     WHERE ($fullScope) AND due_date < TRUNC(SYSDATE) AND status NOT IN ('done','cancelled')
        OR (user_id=:p4 AND status = 'done_late')",
    [$uid, $uid, $oid, $uid])), 'n');
$completionPct = $cntTotal > 0 ? round($cntDone / $cntTotal * 100) : 0;

// ── Pass data to JS for the bar chart ─────────────────────────────────────────
$chartLabels    = array_column(array_values($weeks), 'label');
$chartCompleted = array_column(array_values($weeks), 'completed');
$chartDue       = array_column(array_values($weeks), 'total_due');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Analytics - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
/* ── Page shell ─────────────────────────────────────── */
.analytics-page {
    max-width: 960px;
    margin: 2rem auto;
    padding: 0 1.25rem 4rem;
}
.page-heading { margin-bottom: 1.75rem; }
.page-heading h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .2rem; }
.page-heading p  { font-size: 13px; color: var(--gray-500); margin: 0; }

/* ── Hero strip ─────────────────────────────────────── */
.hero-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.75rem;
}
@media (max-width: 700px) { .hero-strip { grid-template-columns: repeat(2, 1fr); } }
.hero-card {
    background: var(--white);
    border: 1px solid var(--gray-100);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1.25rem 1.25rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .3rem;
    transition: box-shadow .18s;
}
.hero-card:hover { box-shadow: var(--shadow-md); }
.hero-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; margin-bottom: .2rem;
}
.hero-value { font-size: 2rem; font-weight: 700; line-height: 1; color: var(--black); }
.hero-label { font-size: 12px; font-weight: 600; color: var(--gray-500); letter-spacing: .02em; }
.hero-card.c-blue   .hero-icon { background: #dbeafe; color: #1d4ed8; }
.hero-card.c-green  .hero-icon { background: #dcfce7; color: #15803d; }
.hero-card.c-red    .hero-icon { background: #fee2e2; color: #b91c1c; }
.hero-card.c-amber  .hero-icon { background: #e0e7ff; color: #3730a3; }

/* ── Two-column grid ─────────────────────────────────── */
.analytics-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 680px) { .analytics-grid { grid-template-columns: 1fr; } }

/* ── Panel card ─────────────────────────────────────── */
.panel-card {
    background: var(--white);
    border: 1px solid var(--gray-100);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.panel-card.full-width { grid-column: 1 / -1; }
.panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: .9rem 1.25rem;
    border-bottom: 1px solid var(--gray-100);
}
.panel-title { font-size: 14px; font-weight: 700; }
.panel-sub   { font-size: 12px; color: var(--gray-500); }
.panel-body  { padding: 1.25rem; }

/* ── Weekly chart ────────────────────────────────────── */
.chart-wrap { position: relative; height: 200px; }

/* ── Category miss bars ──────────────────────────────── */
.miss-row {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 12px;
}
.miss-row:last-child { margin-bottom: 0; }
.miss-label { font-size: 13px; font-weight: 600; color: var(--black); min-width: 80px; }
.miss-bar-wrap {
    flex: 1; height: 10px; background: var(--gray-100);
    border-radius: 10px; overflow: hidden;
}
.miss-bar { height: 100%; border-radius: 10px; background: #ef4444; transition: width .4s; }
.miss-count { font-size: 12px; font-weight: 600; color: var(--gray-500); min-width: 22px; text-align: right; }

/* ── Stat tiles (streak / avg days) ─────────────────── */
.stat-tiles {
    display: flex; gap: 1rem;
}
.stat-tile {
    flex: 1; background: var(--bg);
    border-radius: var(--radius-sm);
    padding: 1rem;
    text-align: center;
}
.stat-tile-val  { font-size: 2.5rem; font-weight: 700; color: var(--black); line-height: 1; }
.stat-tile-val.streak-active { color: #f97316; }
.stat-tile-sub  { font-size: 12px; color: var(--gray-500); margin-top: .3rem; font-weight: 600; }
.stat-tile-desc { font-size: 11px; color: var(--gray-400); margin-top: .2rem; }

/* ── No-data placeholder ─────────────────────────────── */
.no-data {
    text-align: center; padding: 2rem 1rem;
    color: var(--gray-400); font-size: 13px;
}
.no-data i { font-size: 2rem; display: block; margin-bottom: .5rem; }

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
        <a href="TM_Tasks.php"     class="btn-logout">To-Do List</a>
        <a href="TM_Activity.php"  class="btn-logout">Activity</a>
        <a href="TM_Analytics.php" class="btn-logout" style="font-weight:700;">Analytics</a>
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

<div class="analytics-page">

    <!-- Heading -->
    <div class="page-heading" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:10px;">
        <div>
            <h1>Analytics</h1>
            <p>Your task patterns, completion history, and productivity trends.</p>
        </div>
        <!-- Feature 10: Export (IM101 Week 14 — Data Warehousing) -->
        <div style="display:flex;gap:8px;flex-shrink:0;margin-top:4px;">
            <a href="TM_PHP/TM_TaskActions.php?action=export&format=csv"
               style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:50px;font-size:12px;font-weight:600;font-family:'Poppins',sans-serif;text-decoration:none;background:#fff;border:1.5px solid #e5e5e5;color:#666;transition:all .2s;"
               onmouseover="this.style.background='#f5f5f5';this.style.color='#111';"
               onmouseout="this.style.background='#fff';this.style.color='#666';"
               title="Download all tasks as CSV">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </a>
            <a href="TM_PHP/TM_TaskActions.php?action=export&format=html"
               style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:50px;font-size:12px;font-weight:600;font-family:'Poppins',sans-serif;text-decoration:none;background:#111;border:1.5px solid #111;color:#fff;transition:all .2s;"
               onmouseover="this.style.opacity='.85';"
               onmouseout="this.style.opacity='1';"
               title="Download printable HTML report">
                <i class="fa-solid fa-file-lines"></i> Export Report
            </a>
        </div>
    </div>

    <!-- Hero strip -->
    <div class="hero-strip">
        <div class="hero-card c-blue">
            <div class="hero-icon"><i class="fa-solid fa-list-check"></i></div>
            <div class="hero-value"><?= $cntTotal ?></div>
            <div class="hero-label">Total Tasks</div>
        </div>
        <div class="hero-card c-green">
            <div class="hero-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="hero-value"><?= $cntDone ?></div>
            <div class="hero-label">Completed</div>
        </div>
        <div class="hero-card c-red">
            <div class="hero-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="hero-value"><?= $cntOverdue ?></div>
            <div class="hero-label">Overdue / Done Late</div>
        </div>
        <div class="hero-card c-amber">
            <div class="hero-icon"><i class="fa-solid fa-percent"></i></div>
            <div class="hero-value"><?= $completionPct ?>%</div>
            <div class="hero-label">Completion Rate</div>
        </div>
    </div>

    <!-- Weekly chart — full width -->
    <div class="panel-card full-width" style="margin-bottom:1.25rem;">
        <div class="panel-header">
            <span class="panel-title">Completion Rate by Week</span>
            <span class="panel-sub">Last 8 weeks</span>
        </div>
        <div class="panel-body">
            <div class="chart-wrap">
                <canvas id="weeklyChart"></canvas>
            </div>
        </div>
    </div>

    <div class="analytics-grid">

        <!-- Missed deadlines by category -->
        <div class="panel-card">
            <div class="panel-header">
                <span class="panel-title">Missed Deadlines by Category</span>
                <span class="panel-sub">Overdue, not done, or done late</span>
            </div>
            <div class="panel-body">
                <?php if (empty($missedRows)): ?>
                <div class="no-data">
                    <i class="fa-solid fa-party-horn"></i>
                    No overdue tasks — great work!
                </div>
                <?php else:
                    $maxMissed = max(array_map(fn($r) => (int)($r['MISSED_COUNT'] ?? $r['missed_count'] ?? 0), $missedRows));
                    foreach ($missedRows as $mr):
                        $cat   = htmlspecialchars($mr['CAT_LABEL']    ?? $mr['cat_label']    ?? 'Other');
                        $count = (int)($mr['MISSED_COUNT'] ?? $mr['missed_count'] ?? 0);
                        $pct   = $maxMissed > 0 ? round($count / $maxMissed * 100) : 0;
                ?>
                <div class="miss-row">
                    <div class="miss-label"><?= $cat ?></div>
                    <div class="miss-bar-wrap">
                        <div class="miss-bar" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div class="miss-count"><?= $count ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Streak & avg completion time -->
        <div class="panel-card">
            <div class="panel-header">
                <span class="panel-title">Productivity Stats</span>
                <span class="panel-sub">Based on your history</span>
            </div>
            <div class="panel-body">
                <div class="stat-tiles">
                    <!-- Current streak -->
                    <div class="stat-tile">
                        <div class="stat-tile-val <?= $streak > 0 ? 'streak-active' : '' ?>">
                            <?= $streak ?>
                            <?php if ($streak > 0): ?>
                            
                            <?php endif; ?>
                        </div>
                        <div class="stat-tile-sub">Day Streak</div>
                        <div class="stat-tile-desc">
                            <?php if ($streak === 0): ?>
                                Complete a task today to start!
                            <?php elseif ($streak === 1): ?>
                                Keep it going tomorrow
                            <?php else: ?>
                                Best ever: <?= $streakBest ?> day<?= $streakBest !== 1 ? 's' : '' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Avg completion days -->
                    <div class="stat-tile">
                        <div class="stat-tile-val">
                            <?= $avgDays !== null ? $avgDays : '—' ?>
                        </div>
                        <div class="stat-tile-sub">Avg Days to Done</div>
                        <div class="stat-tile-desc">
                            <?php if ($sampleSize === 0): ?>
                                Complete tasks to see this
                            <?php else: ?>
                                From <?= $sampleSize ?> completed task<?= $sampleSize !== 1 ? 's' : '' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.analytics-grid -->

</div><!-- /.analytics-page -->

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    // Data from PHP
    var labels    = <?= json_encode($chartLabels) ?>;
    var completed = <?= json_encode($chartCompleted) ?>;
    var totalDue  = <?= json_encode($chartDue) ?>;

    // Detect color scheme
    var isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    var labelColor = isDark ? '#9ca3af' : '#6b7280';

    var ctx = document.getElementById('weeklyChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Completed',
                    data: completed,
                    backgroundColor: '#22c55e',
                    borderRadius: 5,
                    borderSkipped: false,
                },
                {
                    label: 'Due',
                    data: totalDue,
                    backgroundColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.07)',
                    borderRadius: 5,
                    borderSkipped: false,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: labelColor, font: { size: 12, family: 'Poppins, sans-serif' }, padding: 16 }
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: gridColor },
                    ticks: { color: labelColor, font: { size: 11, family: 'Poppins, sans-serif' } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: {
                        color: labelColor,
                        font: { size: 11, family: 'Poppins, sans-serif' },
                        stepSize: 1,
                        precision: 0
                    }
                }
            }
        }
    });
})();
</script>

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

</body>
</html>