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

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid    = tm_uid();

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
         WHERE user_id = :p1
         ORDER BY due_date ASC",
        [$uid]
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
         WHERE user_id = :p1
         ORDER BY due_date ASC",
        [$uid]
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
            'Notes', 'Status', 'Recurrence', 'Created At'
        ]);

        // Data rows
        foreach ($rows as $r) {
            fputcsv($out, [
                (int)($r['task_id']         ?? 0),
                $r['task_name']             ?? '',
                $r['start_date']            ?? '',
                $r['due_date']              ?? '',
                $r['category']              ?? '',
                $r['custom_category']       ?? '',
                $r['priority']              ?? '',
                $r['color']                 ?? '',
                $r['notes']                 ?? '',
                $r['status']                ?? '',
                $r['recurrence']            ?? '',
                $r['created_at']            ?? '',
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

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.html"');
        header('Cache-Control: no-cache, no-store');

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>TaskMate Report — ' . date('Y-m-d') . '</title>
<style>
  *{box-sizing:border-box;}
  body{font-family:system-ui,sans-serif;margin:2rem;color:#111;background:#fff;}
  h1{font-size:1.6rem;margin-bottom:.25rem;}
  h2{font-size:1rem;font-weight:700;margin:2rem 0 .75rem;padding-bottom:.4rem;border-bottom:2px solid #f0f0f0;}
  .sub{color:#666;font-size:.9rem;margin-bottom:2rem;}
  .stats{display:flex;gap:1rem;margin-bottom:.5rem;flex-wrap:wrap;}
  .stat{background:#f5f5f5;border-radius:10px;padding:.9rem 1.25rem;min-width:110px;text-align:center;}
  .stat-val{font-size:1.8rem;font-weight:800;line-height:1;}
  .stat-lbl{font-size:.7rem;color:#555;margin-top:.25rem;text-transform:uppercase;letter-spacing:.04em;}
  .insight-row{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;}
  .insight{background:#f5f5f5;border-radius:10px;padding:.8rem 1.1rem;flex:1;min-width:160px;}
  .insight-val{font-size:1.3rem;font-weight:700;}
  .insight-lbl{font-size:.7rem;color:#666;margin-top:.15rem;text-transform:uppercase;letter-spacing:.04em;}
  .missed-list{margin:.5rem 0 0;padding:0;list-style:none;}
  .missed-list li{display:flex;justify-content:space-between;padding:5px 0;
                  border-bottom:1px solid #eee;font-size:.85rem;}
  .missed-list li:last-child{border:none;}
  .missed-count{font-weight:700;color:#b91c1c;}
  table{width:100%;border-collapse:collapse;font-size:.82rem;margin-top:.5rem;}
  th{background:#111;color:#fff;padding:8px 10px;text-align:left;font-size:.78rem;}
  td{padding:7px 10px;border-bottom:1px solid #e5e5e5;vertical-align:middle;}
  tr:hover td{background:#fafafa;}
  .badge{display:inline-block;padding:2px 8px;border-radius:50px;font-size:.72rem;font-weight:600;}
  .b-done{background:#dcfce7;color:#166534;}
  .b-pending{background:#fef9c3;color:#854d0e;}
  .b-in_progress{background:#dbeafe;color:#1e40af;}
  .b-overdue{background:#fee2e2;color:#991b1b;}
  .b-cancelled{background:#f3f4f6;color:#6b7280;}
  .b-review{background:#ede9fe;color:#5b21b6;}
  @media print{body{margin:1cm;}h2{break-before:avoid;}}
</style></head><body>
<h1>&#x1F4CB; TaskMate — Analytics Report</h1>
<p class="sub">Generated on ' . date('F j, Y \a\t H:i') . ' &nbsp;·&nbsp; ' . htmlspecialchars(tm_uname()) . '</p>

<h2>Summary</h2>
<div class="stats">
  <div class="stat"><div class="stat-val">' . $total . '</div><div class="stat-lbl">Total Tasks</div></div>
  <div class="stat"><div class="stat-val">' . $done . '</div><div class="stat-lbl">Completed</div></div>
  <div class="stat"><div class="stat-val">' . $overdue . '</div><div class="stat-lbl">Overdue</div></div>
  <div class="stat"><div class="stat-val">' . $pending . '</div><div class="stat-lbl">Pending</div></div>
  <div class="stat"><div class="stat-val">' . $inProg . '</div><div class="stat-lbl">In Progress</div></div>
  <div class="stat"><div class="stat-val">' . $compRate . '%</div><div class="stat-lbl">Completion Rate</div></div>
</div>

<h2>Productivity Insights</h2>
<div class="insight-row">
  <div class="insight">
    <div class="insight-val">' . ($avgDays !== null ? $avgDays . ' days' : '—') . '</div>
    <div class="insight-lbl">Avg. days to complete a task' . ($sampleSize > 0 ? ' (from ' . $sampleSize . ' tasks)' : '') . '</div>
  </div>
  <div class="insight">
    <div class="insight-val">' . $streak . ' day' . ($streak !== 1 ? 's' : '') . '</div>
    <div class="insight-lbl">Current completion streak</div>
  </div>
</div>';

        if (!empty($missedCats)) {
            echo '<h2>Most Missed Deadlines by Category</h2>
<ul class="missed-list">';
            foreach ($missedCats as $mc) {
                $lbl = htmlspecialchars($mc['cat_label'] ?? $mc['CAT_LABEL'] ?? '—');
                $cnt = (int)($mc['missed_count'] ?? $mc['MISSED_COUNT'] ?? 0);
                echo '<li><span>' . $lbl . '</span><span class="missed-count">' . $cnt . ' overdue</span></li>';
            }
            echo '</ul>';
        }

        echo '<h2>Task List</h2>
<table>
<thead><tr>
  <th>#</th><th>Task Name</th><th>Category</th><th>Priority</th>
  <th>Start Date</th><th>Due Date</th><th>Status</th>
</tr></thead><tbody>';

        foreach ($rows as $r) {
            $status = $r['status'] ?? 'pending';
            $isOD   = $status !== 'done' && $status !== 'cancelled' && ($r['due_date'] ?? '') < date('Y-m-d');
            $bClass = $isOD ? 'b-overdue' : 'b-' . $status;
            $label  = $isOD ? 'Overdue' : ucfirst(str_replace('_', ' ', $status));
            echo '<tr>
  <td>' . (int)($r['task_id'] ?? 0) . '</td>
  <td>' . htmlspecialchars($r['task_name'] ?? '') . '</td>
  <td>' . htmlspecialchars(ucfirst($r['category'] ?? '')) . '</td>
  <td>' . htmlspecialchars(ucfirst($r['priority'] ?? '')) . '</td>
  <td>' . htmlspecialchars($r['start_date'] ?? '') . '</td>
  <td>' . htmlspecialchars($r['due_date'] ?? '') . '</td>
  <td><span class="badge ' . $bClass . '">' . htmlspecialchars($label) . '</span></td>
</tr>';
        }

        echo '</tbody></table></body></html>';
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
        $projectId  = (int)($_POST['project_id']    ?? 0);
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
        $newId = tm_sp_create_task(
            $uid, $name, $start, $due,
            $cat, $ccat, $pri, $col, $notes, $recur
        );
        // ── End stored procedure call ─────────────────────────────────────────

        // ── CHANGE 1 & 2: Update assigned_to and project_id after SP insert ──
        if ($newId > 0 && ($assignedTo > 0 || $projectId > 0)) {
            tm_exec(
                "UPDATE TM_Tasks SET
                    assigned_to = :p1,
                    project_id  = :p2
                 WHERE task_id = :p3",
                [
                    $assignedTo > 0 ? $assignedTo : null,
                    $projectId  > 0 ? $projectId  : null,
                    $newId,
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
                    "SELECT task_id FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
                    [$bid, $uid]
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
        // Write to TM_AuditLog directly from PHP so the Activity Feed shows this.
        // The stored procedure also writes an audit row inside Oracle, but calling
        // tm_audit() here guarantees it is committed even if the SP's internal
        // COMMIT behaves differently across Oracle XE versions.
        tm_audit($uid, 'create', 'task', $newId, $name,
                 '', "cat:{$cat}, pri:{$pri}, due:{$due}");
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
        $projectId  = array_key_exists('project_id',  $_POST) ? (int)$_POST['project_id']  : -1;
        if (!in_array($recur, ['daily','weekly','monthly','yearly'])) $recur = '';
        $allowed_statuses = ['pending', 'in_progress', 'review', 'done', 'cancelled'];
        if (!in_array($status, $allowed_statuses)) { $status = 'pending'; }

        if ($id <= 0 || !$name || !$start || !$due) {
            if ($isApi) tm_api_err('Invalid task data.');
            tm_flash('error', 'Invalid task data.'); break;
        }

        $oldRow = tm_fetch_one(tm_exec(
            "SELECT task_name, status, priority FROM TM_Tasks
             WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
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
             project_id =CASE WHEN :p13 = -1 THEN project_id  ELSE :p14 END
             WHERE task_id=:p15 AND user_id=:p16",
            [
                $name, $start, $due, $cat, $ccat, $pri, $col, $notes, $status, $recur ?: null,
                $assignedTo, $assignedTo > 0 ? $assignedTo : null, // p11/p12
                $projectId,  $projectId  > 0 ? $projectId  : null, // p13/p14
                $id, $uid,
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

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($isApi) tm_api_err('Invalid task.');
            tm_flash('error', 'Invalid task.'); break;
        }

        $delRow = tm_fetch_one(tm_exec(
            "SELECT task_name FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        ));
        if (!$delRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            tm_flash('error', 'Task not found.'); break;
        }
        $delName = $delRow['task_name'] ?? "task #{$id}";

        tm_exec(
            'DELETE FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2',
            [$id, $uid]
        );
        tm_audit($uid, 'delete', 'task', $id, $delName, $delName, '');
        tm_log_task_change($id, $uid, 'delete');

        if ($isApi) tm_api_ok(['task_id' => $id, 'deleted' => true]);
        tm_flash('success', 'Task deleted.');
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
              FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
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
        // Atomically updates status and writes the audit entry inside Oracle.
        tm_sp_update_status($id, $uid, 'done');
        // ── End stored procedure call ─────────────────────────────────────────

        // ── Recurring: create next occurrence ────────────────────────────────
        $qdRecur    = $qdRow['recurrence'] ?? '';
        $qdDueStr   = $qdRow['due_date']   ?? $qdRow['DUE_DATE']   ?? '';
        $qdStartStr = $qdRow['start_date'] ?? $qdRow['START_DATE'] ?? '';
        $nextFlash  = 'Task marked as done!';
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
                    "INSERT INTO TM_Tasks (user_id, task_name, start_date, due_date, category, custom_category, priority, color, recurrence)
                     VALUES (:p1,:p2,TO_DATE(:p3,'YYYY-MM-DD'),TO_DATE(:p4,'YYYY-MM-DD'),:p5,:p6,:p7,:p8,:p9)",
                    [$uid, $qdName, $nextStart, $nextDue, $qdCat, $qdCcat, $qdPri, $qdCol, $qdRecur]
                );
                $nextFlash = "Done! Next {$qdRecur} recurrence created for {$nextDue}.";
            }
        }
        // ── End recurring ─────────────────────────────────────────────────────

        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => 'done', 'recurrence' => $qdRecur]);
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
            "SELECT task_name, status FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
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
            "UPDATE TM_Tasks SET status=:p1 WHERE task_id=:p2 AND user_id=:p3",
            [$prevStatus, $id, $uid]
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
    'quick_done' => '../TM_Tasks.php',
    default      => (function() {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($ref, 'TM_Dashboard.php') !== false) return '../TM_Dashboard.php';
        if (strpos($ref, 'TM_Tasks.php')     !== false) return '../TM_Tasks.php';
        return '../TM_Calendar.php';
    })(),
};

header('Location: ' . $redirect); exit;