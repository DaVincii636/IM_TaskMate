<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

function tm_log_task_change(int $taskId, int $changedBy, string $type = 'update'): void {
    if ($taskId <= 0) return;
    try {
        tm_exec(
            "INSERT INTO TM_TaskChangeLog (task_id, changed_by, change_type)
             VALUES (:p1, :p2, :p3)",
            [$taskId, $changedBy, $type]
        );
    } catch (Throwable $e) {
        // Non-fatal — never break the main action if changelog write fails
        error_log("TM_TaskChangeLog write failed: " . $e->getMessage());
    }
}

tm_require_login();

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$uid      = tm_uid();
$oid      = tm_org_id(); // Feature 6: org-scoped filtering
$is_admin = tm_is_admin(); // used by the reassign action to relax task ownership check

// ── COLLABORATION helper: get username without requiring TM_CollabActions.php ─
if (!function_exists('tm_get_username_inline')) {
    function tm_get_username_inline(int $userId): string {
        $row = tm_fetch_one(tm_exec(
            "SELECT username FROM TM_Users WHERE user_id = :p1", [$userId]
        ));
        return $row['username'] ?? "user#{$userId}";
    }
}


$isApi = (($_GET['format'] ?? '') === 'json')
      || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

// ── Read endpoint: GET ?action=list&format=json ───────────────────────────────
// Returns the authenticated user's tasks as a JSON array.
// No equivalent browser action exists — this is API-only.
if ($action === 'list') {
    if (!$isApi) tm_api_err('This endpoint requires ?format=json or Accept: application/json', 406);
    $stmt = tm_exec(
        "SELECT task_id, task_name,
                TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD')   AS due_date,
                category, custom_category, priority, color, notes, status
         FROM TM_Tasks
         WHERE (user_id = :p1 OR assigned_to = :p2)
           AND org_id  = :p3
         ORDER BY due_date ASC",
        [$uid, $uid, $oid]
    );
    $rows = tm_fetch_all($stmt);
    // Cast task_id to int so JSON encodes it as a number, not a string
    $tasks = array_map(function ($row) {
        $row['task_id'] = (int)$row['task_id'];
        // Resolve any LOB/resource for notes (same guard as TM_Calendar.php)
        if (isset($row['notes'])) {
            if ($row['notes'] instanceof OCILob)  $row['notes'] = $row['notes']->load();
            elseif (is_resource($row['notes']))    $row['notes'] = stream_get_contents($row['notes']);
            $row['notes'] = (string)($row['notes'] ?? '');
        }
        return $row;
    }, $rows);
    tm_api_ok($tasks);
}

// ── Feature 10: CSV / Report Export ──────────────────────────────────────────
// GET ?action=export&format=csv  → downloads all tasks as a .csv file
// GET ?action=export&format=html → downloads an HTML analytics report
// IM101 Week 14 (Data Warehousing): exports make task data available outside
// the system for decision-making, spreadsheets, and presentations.
// IM101 Week 15 (Data Mining): exported data can be loaded into analytics tools.
// HCI101 Week 2 (Utility): users can act on their data beyond the app boundary.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'export') {
    $format = strtolower(trim($_GET['format'] ?? 'csv'));

    // Fetch all tasks for the current user
    $stmt = tm_exec(
        "SELECT task_id, task_name,
                TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD')   AS due_date,
                category, custom_category, priority, color,
                status, recurrence,
                TO_CHAR(created_at,'YYYY-MM-DD HH24:MI:SS') AS created_at
         FROM TM_Tasks
         WHERE (user_id = :p1 OR assigned_to = :p2)
           AND org_id  = :p3
         ORDER BY due_date ASC",
        [$uid, $uid, $oid]
    );
    $rows = tm_fetch_all($stmt);

    // Resolve CLOB notes field
    $rows = array_map(function ($row) {
        if (isset($row['notes'])) {
            if ($row['notes'] instanceof OCILob)  $row['notes'] = $row['notes']->load();
            elseif (is_resource($row['notes']))    $row['notes'] = stream_get_contents($row['notes']);
            $row['notes'] = (string)($row['notes'] ?? '');
        }
        return $row;
    }, $rows);

    $filename = 'taskmate_export_' . date('Y-m-d');

    if ($format === 'csv') {
        // ── CSV export using PHP's built-in fputcsv() ─────────────────────────
        // Enrich rows with project, team, org, assigned-to info
        $enrichedRows = array_map(function ($r) {
            $taskId = (int)($r['task_id'] ?? 0);
            $extra  = ['team_name' => '', 'org_name' => '', 'assigned_to_name' => ''];
            if ($taskId > 0) {
                $infoRow = tm_fetch_one(tm_exec(
                    "SELECT tm.team_name, o.org_name,
                            u.first_name || ' ' || u.last_name AS assigned_to_name
                     FROM TM_Tasks t
                     LEFT JOIN TM_Teams         tm ON tm.team_id     = t.team_id
                     LEFT JOIN TM_Organizations o  ON o.org_id       = t.org_id
                     LEFT JOIN TM_Users         u  ON u.user_id      = t.assigned_to
                     WHERE t.task_id = :p1",
                    [$taskId]
                ));
                if ($infoRow) {
                    $extra['team_name']        = $infoRow['team_name']        ?? $infoRow['TEAM_NAME']        ?? '';
                    $extra['org_name']         = $infoRow['org_name']         ?? $infoRow['ORG_NAME']         ?? '';
                    $extra['assigned_to_name'] = trim($infoRow['assigned_to_name'] ?? $infoRow['ASSIGNED_TO_NAME'] ?? '');
                }
            }
            return array_merge($r, $extra);
        }, $rows);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Cache-Control: no-cache, no-store');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens it correctly without encoding issues
        fwrite($out, "\xEF\xBB\xBF");

        // Header row
        fputcsv($out, [
            'Task ID', 'Task Name', 'Start Date', 'Due Date',
            'Category', 'Custom Category', 'Priority', 'Color',
            'Notes', 'Status', 'Recurrence', 'Created At',
            'Department', 'Organization', 'Assigned To'
        ]);

        // Data rows
        foreach ($enrichedRows as $r) {
            fputcsv($out, [
                (int)($r['task_id']          ?? 0),
                $r['task_name']              ?? '',
                $r['start_date']             ?? '',
                $r['due_date']               ?? '',
                $r['category']               ?? '',
                $r['custom_category']        ?? '',
                $r['priority']               ?? '',
                $r['color']                  ?? '',
                $r['notes']                  ?? '',
                $r['status']                 ?? '',
                $r['recurrence']             ?? '',
                $r['created_at']             ?? '',                $r['team_name']              ?? '',
                $r['org_name']               ?? '',
                $r['assigned_to_name']       ?? '',
            ]);
        }
        fclose($out);
        exit;

    } else {
        // ── HTML report export (with analytics stats) ────────────────────────
        $total    = count($rows);
        $done     = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'done'));
        $overdue  = count(array_filter($rows, fn($r) =>
            ($r['status'] ?? '') !== 'done' &&
            ($r['status'] ?? '') !== 'cancelled' &&
            ($r['due_date'] ?? '') < date('Y-m-d')
        ));
        $pending  = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'pending'));
        $inProg   = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'in_progress'));
        $compRate = $total > 0 ? round($done / $total * 100) : 0;

        // ── Analytics: avg completion days ───────────────────────────────────
        $avgDaysRow = tm_fetch_one(tm_exec(
            "SELECT ROUND(AVG(TRUNC(done_at) - TRUNC(t.start_date)), 1) AS avg_days,
                    COUNT(*) AS sample_size
             FROM TM_Tasks t
             JOIN (
                 SELECT entity_id, MIN(created_at) AS done_at
                 FROM TM_AuditLog
                 WHERE user_id = :p1 AND action = 'status_change'
                   AND new_value LIKE '%status:done%'
                 GROUP BY entity_id
             ) done_log ON done_log.entity_id = t.task_id
             WHERE t.user_id = :p2 AND t.status = 'done'",
            [$uid, $uid]
        ));
        $avgDays    = $avgDaysRow['avg_days']    ?? $avgDaysRow['AVG_DAYS']    ?? null;
        $sampleSize = (int)($avgDaysRow['sample_size'] ?? $avgDaysRow['SAMPLE_SIZE'] ?? 0);

        // ── Analytics: most missed category ──────────────────────────────────
        $missedStmt = tm_exec(
            "SELECT cat_label, missed_count FROM (
                 SELECT CASE WHEN category = 'others' AND custom_category IS NOT NULL
                                  THEN custom_category ELSE INITCAP(category) END AS cat_label,
                        COUNT(*) AS missed_count
                 FROM TM_Tasks
                 WHERE user_id  = :p1
                   AND status   NOT IN ('done','cancelled')
                   AND due_date < TRUNC(SYSDATE)
                 GROUP BY category, custom_category
                 ORDER BY missed_count DESC
             ) WHERE ROWNUM <= 3",
            [$uid]
        );
        $missedCats = tm_fetch_all($missedStmt);

        // ── Analytics: current streak ─────────────────────────────────────────
        $streakStmt = tm_exec(
            "SELECT DISTINCT TRUNC(created_at) AS done_day
             FROM TM_AuditLog
             WHERE user_id = :p1 AND action = 'status_change'
               AND new_value LIKE '%status:done%'
             ORDER BY done_day DESC",
            [$uid]
        );
        $doneDays  = tm_fetch_all($streakStmt);
        $streak    = 0;
        $checkDate = strtotime('today');
        foreach ($doneDays as $dRow) {
            $raw = $dRow['DONE_DAY'] ?? $dRow['done_day'] ?? '';
            $day = strtotime(date('Y-m-d', strtotime($raw)));
            if ($day === $checkDate || $day === strtotime('-1 day', $checkDate)) {
                $streak++;
                $checkDate = strtotime('-1 day', $checkDate);
            } else {
                break;
            }
        }

        // ── Enrich rows with project / team / org / assigned-to names ──────────
        $enrichedHtmlRows = array_map(function ($r) {
            $taskId = (int)($r['task_id'] ?? 0);
            $extra  = ['team_name' => '', 'org_name' => '', 'assigned_to_name' => ''];
            if ($taskId > 0) {
                $infoRow = tm_fetch_one(tm_exec(
                    "SELECT tm.team_name, o.org_name,
                            u.first_name || ' ' || u.last_name AS assigned_to_name
                     FROM TM_Tasks t
                     LEFT JOIN TM_Teams         tm ON tm.team_id     = t.team_id
                     LEFT JOIN TM_Organizations o  ON o.org_id       = t.org_id
                     LEFT JOIN TM_Users         u  ON u.user_id      = t.assigned_to
                     WHERE t.task_id = :p1",
                    [$taskId]
                ));
                if ($infoRow) {
                    $extra['team_name']        = $infoRow['team_name']        ?? $infoRow['TEAM_NAME']        ?? '';
                    $extra['org_name']         = $infoRow['org_name']         ?? $infoRow['ORG_NAME']         ?? '';
                    $extra['assigned_to_name'] = trim($infoRow['assigned_to_name'] ?? $infoRow['ASSIGNED_TO_NAME'] ?? '');
                }
            }
            return array_merge($r, $extra);
        }, $rows);

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.html"');
        header('Cache-Control: no-cache, no-store');

        $compColor  = $compRate >= 75 ? '#16a34a' : ($compRate >= 40 ? '#d97706' : '#dc2626');
        $streakIcon = $streak >= 7 ? '🔥' : ($streak >= 3 ? '⚡' : '📅');

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TaskMate Report — ' . date('Y-m-d') . '</title>
<style>
  @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap\');
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:\'Inter\',system-ui,sans-serif;background:#f8fafc;color:#0f172a;line-height:1.6;}
  .page-wrap{max-width:960px;margin:0 auto;padding:2.5rem 1.5rem 4rem;}

  /* ── Header ── */
  .report-header{background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);
    border-radius:16px;padding:2rem 2.25rem;margin-bottom:2rem;color:#fff;
    display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1rem;}
  .report-title{font-size:1.55rem;font-weight:800;letter-spacing:-.02em;display:flex;align-items:center;gap:.5rem;}
  .report-meta{font-size:.8rem;color:#94a3b8;margin-top:.3rem;}
  .report-brand{background:rgba(255,255,255,.08);border-radius:10px;padding:.6rem 1rem;
    font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
    color:#94a3b8;text-align:right;}
  .report-brand span{display:block;font-size:1.1rem;font-weight:800;color:#fff;letter-spacing:-.01em;}

  /* ── Section titles ── */
  .section-title{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
    color:#64748b;margin:2rem 0 .85rem;display:flex;align-items:center;gap:.5rem;}
  .section-title::after{content:\'\';flex:1;height:1px;background:#e2e8f0;}

  /* ── Stat cards ── */
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.85rem;margin-bottom:.5rem;}
  .stat-card{background:#fff;border-radius:12px;padding:1.1rem 1rem;
    border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.04);text-align:center;}
  .stat-val{font-size:2rem;font-weight:800;line-height:1;letter-spacing:-.03em;}
  .stat-lbl{font-size:.68rem;font-weight:600;color:#94a3b8;margin-top:.35rem;
    text-transform:uppercase;letter-spacing:.06em;}
  .stat-card.accent-green .stat-val{color:#16a34a;}
  .stat-card.accent-red   .stat-val{color:#dc2626;}
  .stat-card.accent-blue  .stat-val{color:#2563eb;}
  .stat-card.accent-amber .stat-val{color:#d97706;}

  /* ── Insight cards ── */
  .insights-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.85rem;margin-bottom:.5rem;}
  .insight-card{background:#fff;border-radius:12px;padding:1.1rem 1.25rem;
    border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.04);}
  .insight-val{font-size:1.5rem;font-weight:800;letter-spacing:-.02em;}
  .insight-lbl{font-size:.68rem;font-weight:600;color:#94a3b8;margin-top:.2rem;
    text-transform:uppercase;letter-spacing:.06em;}
  .insight-sub{font-size:.75rem;color:#94a3b8;margin-top:.15rem;}

  /* ── Progress bar ── */
  .progress-wrap{background:#fff;border-radius:12px;padding:1.1rem 1.25rem;
    border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.04);}
  .progress-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.6rem;}
  .progress-label{font-size:.75rem;font-weight:600;color:#475569;}
  .progress-pct{font-size:1.4rem;font-weight:800;color:' . $compColor . ';}
  .progress-bar-bg{background:#f1f5f9;border-radius:50px;height:10px;overflow:hidden;}
  .progress-bar-fill{height:100%;border-radius:50px;background:linear-gradient(90deg,' . $compColor . ',' . $compColor . 'aa);
    width:' . $compRate . '%;}

  /* ── Missed category list ── */
  .missed-card{background:#fff;border-radius:12px;padding:1.1rem 1.25rem;
    border:1px solid #e2e8f0;border-left:4px solid #f87171;box-shadow:0 1px 3px rgba(0,0,0,.04);}
  .missed-list{list-style:none;margin-top:.6rem;}
  .missed-list li{display:flex;justify-content:space-between;align-items:center;
    padding:.45rem 0;border-bottom:1px solid #f1f5f9;font-size:.83rem;}
  .missed-list li:last-child{border:none;}
  .missed-cat{font-weight:600;color:#0f172a;}
  .missed-count{background:#fee2e2;color:#b91c1c;padding:2px 10px;border-radius:50px;
    font-size:.72rem;font-weight:700;}

  /* ── Task table ── */
  .table-wrap{background:#fff;border-radius:12px;border:1px solid #e2e8f0;
    overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
  table{width:100%;border-collapse:collapse;font-size:.8rem;}
  thead{background:#f8fafc;border-bottom:2px solid #e2e8f0;}
  th{padding:10px 12px;text-align:left;font-size:.68rem;font-weight:700;
    letter-spacing:.06em;text-transform:uppercase;color:#64748b;white-space:nowrap;}
  td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155;}
  tr:last-child td{border:none;}
  tbody tr:hover td{background:#f8fafc;}
  .task-name{font-weight:600;color:#0f172a;}
  .meta-tag{background:#f1f5f9;color:#475569;border-radius:4px;padding:1px 6px;
    font-size:.7rem;font-weight:500;}
  .meta-tag.project{background:#eff6ff;color:#1d4ed8;}
  .meta-tag.team{background:#f5f3ff;color:#6d28d9;}
  .meta-tag.assigned{background:#f0fdf4;color:#15803d;}

  /* ── Badges ── */
  .badge{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:50px;
    font-size:.7rem;font-weight:700;white-space:nowrap;}
  .b-done{background:#dcfce7;color:#166534;}
  .b-done_late{background:#d1fae5;color:#065f46;}
  .b-pending{background:#fef9c3;color:#854d0e;}
  .b-in_progress{background:#dbeafe;color:#1e40af;}
  .b-overdue{background:#fee2e2;color:#991b1b;}
  .b-cancelled{background:#f3f4f6;color:#6b7280;}
  .b-review{background:#ede9fe;color:#5b21b6;}

  /* ── Priority dots ── */
  .pri-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:4px;vertical-align:middle;}
  .pri-high{background:#ef4444;}
  .pri-mid{background:#f97316;}
  .pri-low{background:#22c55e;}

  /* ── Footer ── */
  .report-footer{text-align:center;margin-top:2.5rem;font-size:.75rem;color:#94a3b8;}

  @media print{
    body{background:#fff;}
    .page-wrap{padding:0;}
    .report-header{border-radius:0;}
    .table-wrap,.stat-card,.insight-card,.progress-wrap,.missed-card{box-shadow:none;}
  }
</style>
</head>
<body>
<div class="page-wrap">

<!-- Header -->
<div class="report-header">
  <div>
    <div class="report-title">📋 TaskMate Report</div>
    <div class="report-meta">Generated ' . date('F j, Y \a\t g:i A') . ' &nbsp;·&nbsp; ' . htmlspecialchars(tm_uname()) . '</div>
  </div>
  <div class="report-brand">Analytics Export<span>' . date('Y-m-d') . '</span></div>
</div>

<!-- Summary Stats -->
<div class="section-title">Summary</div>
<div class="stats-grid">
  <div class="stat-card"><div class="stat-val">' . $total . '</div><div class="stat-lbl">Total Tasks</div></div>
  <div class="stat-card accent-green"><div class="stat-val">' . $done . '</div><div class="stat-lbl">Completed</div></div>
  <div class="stat-card accent-red"><div class="stat-val">' . $overdue . '</div><div class="stat-lbl">Overdue</div></div>
  <div class="stat-card accent-amber"><div class="stat-val">' . $pending . '</div><div class="stat-lbl">Pending</div></div>
  <div class="stat-card accent-blue"><div class="stat-val">' . $inProg . '</div><div class="stat-lbl">In Progress</div></div>
</div>

<!-- Completion Rate -->
<div class="section-title" style="margin-top:1.25rem;">Completion Rate</div>
<div class="progress-wrap">
  <div class="progress-header">
    <span class="progress-label">Tasks completed vs total</span>
    <span class="progress-pct">' . $compRate . '%</span>
  </div>
  <div class="progress-bar-bg"><div class="progress-bar-fill"></div></div>
</div>

<!-- Productivity Insights -->
<div class="section-title">Productivity Insights</div>
<div class="insights-grid">
  <div class="insight-card">
    <div class="insight-val">' . ($avgDays !== null ? $avgDays . 'd' : '—') . '</div>
    <div class="insight-lbl">Avg. Completion Time</div>
    <div class="insight-sub">' . ($sampleSize > 0 ? 'Based on ' . $sampleSize . ' completed tasks' : 'No completed tasks yet') . '</div>
  </div>
  <div class="insight-card">
    <div class="insight-val">' . $streakIcon . ' ' . $streak . ' day' . ($streak !== 1 ? 's' : '') . '</div>
    <div class="insight-lbl">Current Streak</div>
    <div class="insight-sub">Consecutive days with completions</div>
  </div>
</div>';

        if (!empty($missedCats)) {
            echo '<div class="section-title">Most Missed Deadlines by Category</div>
<div class="missed-card">
<ul class="missed-list">';
            foreach ($missedCats as $mc) {
                $lbl = htmlspecialchars($mc['cat_label'] ?? $mc['CAT_LABEL'] ?? '—');
                $cnt = (int)($mc['missed_count'] ?? $mc['MISSED_COUNT'] ?? 0);
                echo '<li><span class="missed-cat">' . $lbl . '</span><span class="missed-count">' . $cnt . ' overdue</span></li>';
            }
            echo '</ul></div>';
        }

        echo '<div class="section-title">Task List</div>
<div class="table-wrap">
<table>
<thead><tr>
  <th>#</th>
  <th>Task Name</th>
  <th>Category</th>
  <th>Priority</th>
  <th>Project / Department</th>
  <th>Assigned To</th>
  <th>Start</th>
  <th>Due</th>
  <th>Status</th>
</tr></thead>
<tbody>';

        $rowNum = 1;
        foreach ($enrichedHtmlRows as $r) {
            $status  = $r['status'] ?? 'pending';
            $isOD    = $status !== 'done' && $status !== 'done_late' && $status !== 'cancelled'
                       && ($r['due_date'] ?? '') < date('Y-m-d');
            $bClass  = $isOD ? 'b-overdue' : 'b-' . str_replace(' ','_',$status);
            $label   = $isOD ? 'Overdue' : ucfirst(str_replace('_', ' ', $status));
            $priCls  = match($r['priority'] ?? '') { 'high' => 'pri-high', 'low' => 'pri-low', default => 'pri-mid' };
            $priLbl  = ucfirst($r['priority'] ?? 'mid');
            $teamTag = $r['team_name'] ? '<span class="meta-tag team">' . htmlspecialchars($r['team_name']) . '</span>' : '';
            $asgn    = $r['assigned_to_name'] ? '<span class="meta-tag assigned">' . htmlspecialchars($r['assigned_to_name']) . '</span>' : '<span style="color:#cbd5e1">—</span>';
            echo '<tr>
  <td style="color:#94a3b8;font-size:.72rem;">' . $rowNum++ . '</td>
  <td class="task-name">' . htmlspecialchars($r['task_name'] ?? '') . '</td>
  <td>' . htmlspecialchars(ucfirst($r['category'] ?? '')) . '</td>
  <td><span class="pri-dot ' . $priCls . '"></span>' . $priLbl . '</td>
  <td>' . ($teamTag ?: '<span style="color:#cbd5e1">—</span>') . '</td>
  <td>' . $asgn . '</td>
  <td style="color:#64748b;">' . htmlspecialchars($r['start_date'] ?? '') . '</td>
  <td style="' . ($isOD ? 'color:#dc2626;font-weight:700;' : 'color:#64748b;') . '">' . htmlspecialchars($r['due_date'] ?? '') . '</td>
  <td><span class="badge ' . $bClass . '">' . htmlspecialchars($label) . '</span></td>
</tr>';
        }

        echo '</tbody></table></div>
<div class="report-footer">TaskMate Analytics Report &nbsp;·&nbsp; ' . date('Y') . '</div>
</div>
</body></html>';
        exit;
    }
}
// ── End Feature 10 export ─────────────────────────────────────────────────────

switch ($action) {

    case 'add':
        $name       = trim($_POST['name']           ?? '');
        $start      = trim($_POST['startDate']      ?? '');
        $due        = trim($_POST['dueDate']        ?? '');
        $cat        = trim($_POST['category']       ?? 'errands');
        $ccat       = trim($_POST['customCategory'] ?? '');
        $pri        = trim($_POST['priority']       ?? 'mid');
        $col        = trim($_POST['color']          ?? '#ef4444');
        $notes      = trim($_POST['notes']          ?? '');
        $recur      = trim($_POST['recurrence']     ?? '');
        $assignedTo = (int)($_POST['assigned_to']   ?? 0);
        $isOrgTask  = isset($_POST['is_org_task']) && $_POST['is_org_task'] ? 1 : 0;
        $teamId     = isset($_POST['team_id']) && (int)$_POST['team_id'] > 0
                        ? (int)$_POST['team_id'] : null;
        if (!in_array($recur, ['daily','weekly','monthly','yearly'])) $recur = '';

        if (!$name || !$start || !$due) {
            if ($isApi) tm_api_err('Name and dates are required.');
            tm_flash('error', 'Name and dates are required.'); break;
        }
        if ($start > $due) {
            if ($isApi) tm_api_err('Start date cannot be after due date.');
            tm_flash('error', 'Start date cannot be after due date.'); break;
        }
        // ── Feature 9: call TM_CreateTask stored procedure ───────────────────
        // PHP no longer writes SQL inline to TM_Tasks or TM_AuditLog.
        // The procedure handles the INSERT + audit atomically inside Oracle
        // (IM101 Week 12: security, integrity, performance, reuse).
        // Feature 6: pass org_id so the stored procedure stamps the new task correctly
        try {
            $newId = tm_sp_create_task(
                $uid, $name, $start, $due,
                $cat, $ccat, $pri, $col, $notes, $recur, $oid, $isOrgTask
            );
        } catch (RuntimeException $e) {
            if ($isApi) tm_api_err('Could not save task: ' . $e->getMessage());
            tm_flash('error', 'Could not save task: ' . $e->getMessage()); break;
        }
        // ── End stored procedure call ─────────────────────────────────────────

        // ── CHANGE 1 & 2: Update assigned_to, project_id, and team_id after SP insert ──
        if ($newId > 0 && ($assignedTo > 0 || $teamId !== null)) {
            tm_exec(
                "UPDATE TM_Tasks SET
                    assigned_to = :p1,
                    team_id     = :p3
                 WHERE task_id = :p2",
                [
                    $assignedTo > 0 ? $assignedTo : null,
                    $newId,
                    $teamId,
                ]
            );
            // Notify assigned user
            if ($assignedTo > 0 && $assignedTo !== $uid) {
                $assignerName = tm_get_username_inline($uid);
                $notifMsg = "{$assignerName} assigned you a task: {$name}";
                tm_exec(
                    "INSERT INTO TM_Notifications
                        (user_id, task_id, type, message, is_read, source_type, mentioned_by)
                     VALUES (:p1, :p2, 'assignment', :p3, 0, 'assignment', :p4)",
                    [$assignedTo, $newId, substr($notifMsg, 0, 500), $uid]
                );
            }
        }

        // ── CHANGE 4: Process @mentions in notes ──────────────────────────────
        if ($newId > 0 && $notes !== '') {
            preg_match_all('/@([\w]+)/', $notes, $mentionMatches);
            foreach (array_unique($mentionMatches[1] ?? []) as $uname) {
                $mRow = tm_fetch_one(tm_exec(
                    "SELECT user_id FROM TM_Users WHERE LOWER(username) = LOWER(:p1)", [$uname]
                ));
                if (!$mRow || (int)$mRow['user_id'] === $uid) continue;
                $authorName = tm_get_username_inline($uid);
                $msg = "@{$authorName} mentioned you in task: {$name}";
                tm_exec(
                    "INSERT INTO TM_Notifications
                        (user_id, task_id, type, message, is_read, source_type, mentioned_by)
                     VALUES (:p1, :p2, 'mention', :p3, 0, 'task_note', :p4)",
                    [(int)$mRow['user_id'], $newId, substr($msg, 0, 500), $uid]
                );
            }
        }
        // ── END Collaboration additions ───────────────────────────────────────

        // Save any blocker dependencies chosen in the add modal
        $blockerRaw = trim($_POST['blocker_ids'] ?? '');
        if ($blockerRaw !== '' && $newId > 0) {
            foreach (explode(',', $blockerRaw) as $rawBid) {
                $bid = (int)trim($rawBid);
                if ($bid <= 0 || $bid === $newId) continue;
                $bo = tm_fetch_one(tm_exec(
                    "SELECT task_id FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2 AND org_id=:p3",
                    [$bid, $uid, $oid]
                ));
                if (!$bo) continue;
                tm_exec(
                    "INSERT INTO TM_TaskLinks (task_id, depends_on_id, link_type)
                     VALUES (:p1, :p2, 'blocks')",
                    [$newId, $bid]
                );
            }
        }

        if ($isApi) tm_api_ok(['task_id' => $newId, 'task_name' => $name]);
        // NOTE: TM_CreateTask stored procedure already writes the 'create' audit
        // row atomically inside Oracle (Feature 9). A second tm_audit() call here
        // was causing every task creation to appear TWICE in the Activity Feed.
        // The SP commit (OCI_COMMIT_ON_SUCCESS) ensures the row is always visible.
        tm_flash('success', 'Task added!');
        if ($newId > 0) {
            tm_log_task_change($newId, $uid, 'create');
        }
        break;

    case 'edit':
        $id     = (int)($_POST['id']            ?? 0);
        $name   = trim($_POST['name']           ?? '');
        $start  = trim($_POST['startDate']      ?? '');
        $due    = trim($_POST['dueDate']        ?? '');
        $cat    = trim($_POST['category']       ?? 'errands');
        $ccat   = trim($_POST['customCategory'] ?? '');
        $pri    = trim($_POST['priority']       ?? 'mid');
        $col    = trim($_POST['color']          ?? '#ef4444');
        $notes  = trim($_POST['notes']          ?? '');
        $status = trim($_POST['status']         ?? 'pending');
        $recur  = trim($_POST['recurrence']     ?? '');
        // CHANGE 1 & 2: Assignment and project
        $assignedTo = array_key_exists('assigned_to', $_POST) ? (int)$_POST['assigned_to'] : -1;
        if (!in_array($recur, ['daily','weekly','monthly','yearly'])) $recur = '';
        $allowed_statuses = ['pending', 'in_progress', 'review', 'done', 'done_late', 'cancelled'];
        if (!in_array($status, $allowed_statuses)) { $status = 'pending'; }

        if ($id <= 0 || !$name || !$start || !$due) {
            if ($isApi) tm_api_err('Invalid task data.');
            tm_flash('error', 'Invalid task data.'); break;
        }

        $oldRow = tm_fetch_one(tm_exec(
            // Feature 10: allow editing tasks you own OR tasks delegated to you
            "SELECT task_name, status, priority FROM TM_Tasks
             WHERE task_id=:p1 AND (user_id=:p2 OR assigned_to=:p3) AND org_id=:p4",
            [$id, $uid, $uid, $oid]
        ));
        if (!$oldRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }
        $oldName   = $oldRow['task_name'] ?? '';
        $oldStatus = $oldRow['status']    ?? '';
        $oldPri    = $oldRow['priority']  ?? '';

        tm_exec(
            "UPDATE TM_Tasks SET task_name=:p1,
             start_date=TO_DATE(:p2,'YYYY-MM-DD'),
             due_date=TO_DATE(:p3,'YYYY-MM-DD'),
             category=:p4, custom_category=:p5,
             priority=:p6, color=:p7, notes=:p8,
             status=:p9, recurrence=:p10,
             assigned_to=CASE WHEN :p11 = -1 THEN assigned_to ELSE :p12 END,
             WHERE task_id=:p15 AND (user_id=:p16 OR assigned_to=:p17)",
            [
                $name, $start, $due, $cat, $ccat, $pri, $col, $notes, $status, $recur ?: null,
                $assignedTo, $assignedTo > 0 ? $assignedTo : null, // p11/p12
                $id, $uid, $uid,
            ]
        );

        $auditAction = ($status !== $oldStatus) ? 'status_change' : 'edit';
        tm_audit_sp($uid, $auditAction, 'task', $id, $name,
                 "status:{$oldStatus}, pri:{$oldPri}",
                 "status:{$status}, pri:{$pri}");

        // ── CHANGE 4: Process @mentions in notes on edit ──────────────────────
        if ($notes !== '') {
            preg_match_all('/@([\w]+)/', $notes, $mentionMatches);
            foreach (array_unique($mentionMatches[1] ?? []) as $uname) {
                $mRow = tm_fetch_one(tm_exec(
                    "SELECT user_id FROM TM_Users WHERE LOWER(username) = LOWER(:p1)", [$uname]
                ));
                if (!$mRow || (int)$mRow['user_id'] === $uid) continue;
                $authorName = tm_get_username_inline($uid);
                $msg = "@{$authorName} mentioned you in task: {$name}";
                tm_exec(
                    "INSERT INTO TM_Notifications
                        (user_id, task_id, type, message, is_read, source_type, mentioned_by)
                     VALUES (:p1, :p2, 'mention', :p3, 0, 'task_note', :p4)",
                    [(int)$mRow['user_id'], $id, substr($msg, 0, 500), $uid]
                );
            }
        }
        // ── END mention parsing ───────────────────────────────────────────────

        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => $status]);
        tm_flash('success', 'Task updated!');
        tm_log_task_change($id, $uid, 'update');
        break;

    // ── FEATURE 10: Task Delegation ───────────────────────────────────────────
    // Admin / moderator only. Reassigns a task to another user in the same org.
    // Logs the change in TM_AuditLog and notifies the new assignee.
    case 'reassign':
        if (!tm_is_moderator()) {
            if ($isApi) tm_api_err('Insufficient permissions.', 403);
            tm_flash('error', 'Insufficient permissions.'); break;
        }

        $taskId   = (int)($_POST['task_id']   ?? 0);
        $toUserId = (int)($_POST['to_user_id'] ?? 0);

        if ($taskId <= 0 || $toUserId <= 0) {
            if ($isApi) tm_api_err('Invalid task or user.');
            tm_flash('error', 'Invalid task or user.'); break;
        }

        // Fetch current task (admin can see any task in the org; moderators only their own)
        $tRow = tm_fetch_one(tm_exec(
            $is_admin
                ? "SELECT task_id, task_name, user_id FROM TM_Tasks WHERE task_id=:p1 AND org_id=:p2"
                : "SELECT task_id, task_name, user_id FROM TM_Tasks WHERE task_id=:p1 AND org_id=:p2 AND user_id=:p3",
            $is_admin ? [$taskId, $oid] : [$taskId, $oid, $uid]
        ));
        if (!$tRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }

        $taskName    = $tRow['task_name'] ?? $tRow['TASK_NAME'] ?? "task #{$taskId}";
        $fromUserId  = (int)($tRow['user_id'] ?? $tRow['USER_ID'] ?? 0);

        if ($fromUserId === $toUserId) {
            if ($isApi) tm_api_err('Task is already assigned to that user.');
            tm_flash('error', 'Task is already assigned to that user.'); break;
        }

        // Verify target user exists in the same org
        $toRow = tm_fetch_one(tm_exec(
            "SELECT user_id, first_name, last_name FROM TM_Users WHERE user_id=:p1 AND org_id=:p2",
            [$toUserId, $oid]
        ));
        if (!$toRow) {
            if ($isApi) tm_api_err('Target user not found in your organization.', 404);
            tm_flash('error', 'Target user not found in your organization.'); break;
        }

        // Reassign: update user_id (and also assigned_to for display consistency)
        tm_exec(
            "UPDATE TM_Tasks SET user_id=:p1, assigned_to=:p2 WHERE task_id=:p3",
            [$toUserId, $toUserId, $taskId]
        );

        // Lookup old and new user names for audit/notification
        $fromRow  = tm_fetch_one(tm_exec(
            "SELECT first_name, last_name FROM TM_Users WHERE user_id=:p1", [$fromUserId]
        ));
        $fromName = trim(($fromRow['first_name'] ?? $fromRow['FIRST_NAME'] ?? '') . ' ' .
                         ($fromRow['last_name']  ?? $fromRow['LAST_NAME']  ?? ''));
        $toName   = trim(($toRow['first_name']   ?? $toRow['FIRST_NAME']   ?? '') . ' ' .
                         ($toRow['last_name']    ?? $toRow['LAST_NAME']    ?? ''));

        // Notify the new assignee
        $delegatorName = tm_get_username_inline($uid);
        $notifMsg = "{$delegatorName} delegated the task \"{$taskName}\" to you.";
        tm_exec(
            "INSERT INTO TM_Notifications (user_id, task_id, type, message, is_read)
             VALUES (:p1, :p2, 'assignment', :p3, 0)",
            [$toUserId, $taskId, substr($notifMsg, 0, 500)]
        );

        // Audit log
        tm_audit($uid, 'edit', 'task', $taskId, $taskName,
                 "owner:{$fromName}", "owner:{$toName}");

        if ($isApi) tm_api_ok(['task_id' => $taskId, 'to_user_id' => $toUserId]);
        tm_flash('success', "Task \"{$taskName}\" delegated to {$toName}.");
        break;
    // ── END FEATURE 10 ───────────────────────────────────────────────────────

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($isApi) tm_api_err('Invalid task.');
            tm_flash('error', 'Invalid task.'); break;
        }

        $delRow = tm_fetch_one(tm_exec(
            // Feature 10: allow deleting tasks you own OR tasks delegated to you
            "SELECT task_name FROM TM_Tasks WHERE task_id=:p1 AND (user_id=:p2 OR assigned_to=:p3) AND org_id=:p4",
            [$id, $uid, $uid, $oid]
        ));
        if (!$delRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }
        $delName = $delRow['task_name'] ?? "task #{$id}";

        tm_exec(
            'DELETE FROM TM_Tasks WHERE task_id=:p1 AND (user_id=:p2 OR assigned_to=:p3) AND org_id=:p4',
            [$id, $uid, $uid, $oid]
        );
        tm_audit($uid, 'delete', 'task', $id, $delName, $delName, '');
        tm_log_task_change($id, $uid, 'delete');

        if ($isApi) tm_api_ok(['task_id' => $id, 'deleted' => true]);
        tm_flash('success', 'Task deleted.');
        break;

    case 'quick_status':
        // Simple inline status update (Pending -> In Progress progression)
        $id        = (int)($_POST['id']     ?? 0);
        $newStatus = trim($_POST['status']  ?? '');
        $allowed   = ['pending', 'in_progress'];
        if ($id <= 0 || !in_array($newStatus, $allowed)) {
            if ($isApi) tm_api_err('Invalid task or status.');
            tm_flash('error', 'Invalid task or status.'); break;
        }
        $qsRow = tm_fetch_one(tm_exec(
            "SELECT status FROM TM_Tasks WHERE task_id=:p1 AND (user_id=:p2 OR assigned_to=:p3) AND org_id=:p4",
            [$id, $uid, $uid, $oid]
        ));
        if (!$qsRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }
        tm_sp_update_status($id, $uid, $newStatus);
        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => $newStatus]);
        tm_flash('success', 'Task status updated.');
        break;

    case 'quick_done':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($isApi) tm_api_err('Invalid task.');
            tm_flash('error', 'Invalid task.'); break;
        }

        $qdRow = tm_fetch_one(tm_exec(
            "SELECT task_name, status, recurrence,
                     TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                     TO_CHAR(due_date,'YYYY-MM-DD')   AS due_date,
                     category, custom_category, priority, color
              FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2 AND org_id=:p3",
            [$id, $uid, $oid]
        ));
        if (!$qdRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }
        $qdName  = $qdRow['task_name'] ?? "task #{$id}";
        $qdOldSt = $qdRow['status']    ?? 'pending';

        $blockerRow = tm_fetch_one(tm_exec(
            "SELECT COUNT(*) AS n
             FROM TM_TaskLinks tl
             JOIN TM_Tasks blocker ON blocker.task_id = tl.depends_on_id
             WHERE tl.task_id   = :p1
               AND tl.link_type = 'blocks'
               AND blocker.status NOT IN ('done', 'cancelled')",
            [$id]
        ));
        $blockerCount = (int)($blockerRow['n'] ?? 0);

        if ($blockerCount > 0) {
            $msg = "Cannot mark done: {$blockerCount} blocking task"
                 . ($blockerCount > 1 ? 's are' : ' is') . " still pending.";
            if ($isApi) tm_api_err($msg, 409);
            tm_flash('error', $msg); break;
        }

        // ── Feature 9: call TM_UpdateTaskStatus stored procedure ─────────────
        // Determine final status: done_late if task is overdue, else done
        $qdDueStr   = $qdRow['due_date']   ?? $qdRow['DUE_DATE']   ?? '';
        $qdStartStr = $qdRow['start_date'] ?? $qdRow['START_DATE'] ?? '';
        $finalStatus = (!empty($qdDueStr) && $qdDueStr < date('Y-m-d')) ? 'done_late' : 'done';
        tm_sp_update_status($id, $uid, $finalStatus);
        // ── End stored procedure call ─────────────────────────────────────────

        // ── Recurring: create next occurrence ────────────────────────────────
        $qdRecur    = $qdRow['recurrence'] ?? '';
        $nextFlash  = ($finalStatus === 'done_late') ? 'Task marked as done (late)!' : 'Task marked as done!';
        if ($qdRecur && $qdDueStr) {
            $dueTs   = strtotime($qdDueStr);
            $startTs = strtotime($qdStartStr);
            // Preserve the original task duration (number of days from start to due)
            $durationDays = max(0, (int)(($dueTs - $startTs) / 86400));
            $nextDue = match($qdRecur) {
                'daily'   => date('Y-m-d', strtotime('+1 day',   $dueTs)),
                'weekly'  => date('Y-m-d', strtotime('+1 week',  $dueTs)),
                'monthly' => date('Y-m-d', strtotime('+1 month', $dueTs)),
                'yearly'  => date('Y-m-d', strtotime('+1 year',  $dueTs)),
                default   => null,
            };
            if ($nextDue) {
                $nextDueTs = strtotime($nextDue);
                // Start date = next due minus the original duration
                $nextStart = date('Y-m-d', $nextDueTs - ($durationDays * 86400));
                $qdCat   = $qdRow['category']        ?? $qdRow['CATEGORY']        ?? 'errands';
                $qdCcat  = $qdRow['custom_category'] ?? $qdRow['CUSTOM_CATEGORY'] ?? '';
                $qdPri   = $qdRow['priority']        ?? $qdRow['PRIORITY']        ?? 'mid';
                $qdCol   = $qdRow['color']           ?? $qdRow['COLOR']           ?? '#ef4444';
                tm_exec(
                    "INSERT INTO TM_Tasks (user_id, org_id, task_name, start_date, due_date, category, custom_category, priority, color, recurrence)
                     VALUES (:p1,:p2,:p3,TO_DATE(:p4,'YYYY-MM-DD'),TO_DATE(:p5,'YYYY-MM-DD'),:p6,:p7,:p8,:p9,:p10)",
                    [$uid, $oid, $qdName, $nextStart, $nextDue, $qdCat, $qdCcat, $qdPri, $qdCol, $qdRecur]
                );
                $nextFlash = "Done! Next {$qdRecur} recurrence created for {$nextDue}.";
            }
        }
        // ── End recurring ─────────────────────────────────────────────────────

        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => $finalStatus, 'recurrence' => $qdRecur]);
        tm_flash('success', $nextFlash);
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($ref, 'TM_Tasks.php') !== false) {
            header('Location: ' . $ref); exit;
        }
        header('Location: ../TM_Tasks.php'); exit;


    case 'undo_done':
        // ── Undo: revert last status_change for a task ──────────────────────
        // Reads the most recent TM_AuditLog entry where the task was marked done
        // and reverts the status back to old_value.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($isApi) tm_api_err('Invalid task.');
            tm_flash('error', 'Invalid task.'); break;
        }

        $taskRow = tm_fetch_one(tm_exec(
            "SELECT task_name, status FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2 AND org_id=:p3",
            [$id, $uid, $oid]
        ));
        if (!$taskRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }
        if (($taskRow['status'] ?? '') !== 'done') {
            if ($isApi) tm_api_err('Task is not marked done — nothing to undo.', 409);
            tm_flash('error', 'Nothing to undo.'); break;
        }

        // Find the most recent log entry that set this task to done
        $logRow = tm_fetch_one(tm_exec(
            "SELECT log_id, old_value FROM TM_AuditLog
              WHERE entity_type='task' AND entity_id=:p1
                AND action='status_change'
                AND new_value LIKE '%done%'
              ORDER BY created_at DESC
              FETCH FIRST 1 ROWS ONLY",
            [$id]
        ));

        // Parse old_value like 'status:pending' → 'pending'
        $prevStatus = 'pending'; // safe fallback
        if ($logRow) {
            $ov = $logRow['old_value'] ?? '';
            if (preg_match('/status:([\w]+)/', $ov, $m)) {
                $prevStatus = $m[1];
            }
        }
        $allowed = ['pending','in_progress','review','done','cancelled'];
        if (!in_array($prevStatus, $allowed)) $prevStatus = 'pending';

        $taskName = $taskRow['task_name'] ?? "task #{$id}";
        tm_exec(
            "UPDATE TM_Tasks SET status=:p1 WHERE task_id=:p2 AND user_id=:p3 AND org_id=:p4",
            [$prevStatus, $id, $uid, $oid]
        );
        tm_log_task_change($id, $uid, 'update');
        tm_audit($uid, 'status_change', 'task', $id, $taskName,
                 'status:done', "status:{$prevStatus}");

        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => $prevStatus]);
        tm_flash('success', "Undone! '{$taskName}' is back to " . ucfirst(str_replace('_',' ',$prevStatus)) . '.');
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($ref, 'TM_Tasks.php') !== false) {
            header('Location: ' . $ref); exit;
        }
        header('Location: ../TM_Tasks.php'); exit;

    default:
        if ($isApi) tm_api_err("Unknown action: '{$action}'", 400);
        break;
}

$redirect = match($action) {
    'quick_status' => '../TM_Tasks.php',
    'quick_done' => '../TM_Tasks.php',
    default      => (function() {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($ref, 'TM_Dashboard.php') !== false) return '../TM_Dashboard.php';
        if (strpos($ref, 'TM_Tasks.php')     !== false) return '../TM_Tasks.php';
        return '../TM_Calendar.php';
    })(),
};

header('Location: ' . $redirect); exit;