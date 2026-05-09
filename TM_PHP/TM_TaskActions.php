<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid    = tm_uid();

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
        // ── HTML report export ────────────────────────────────────────────────
        $total    = count($rows);
        $done     = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'done'));
        $overdue  = count(array_filter($rows, fn($r) =>
            ($r['status'] ?? '') !== 'done' &&
            ($r['status'] ?? '') !== 'cancelled' &&
            ($r['due_date'] ?? '') < date('Y-m-d')
        ));
        $pending  = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'pending'));
        $compRate = $total > 0 ? round($done / $total * 100) : 0;

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.html"');
        header('Cache-Control: no-cache, no-store');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>TaskMate Report — ' . date('Y-m-d') . '</title>
<style>
  body{font-family:system-ui,sans-serif;margin:2rem;color:#111;}
  h1{font-size:1.6rem;margin-bottom:.25rem;}
  .sub{color:#666;font-size:.9rem;margin-bottom:2rem;}
  .stats{display:flex;gap:1.5rem;margin-bottom:2rem;flex-wrap:wrap;}
  .stat{background:#f5f5f5;border-radius:8px;padding:1rem 1.5rem;min-width:120px;text-align:center;}
  .stat-val{font-size:2rem;font-weight:700;}
  .stat-lbl{font-size:.75rem;color:#555;margin-top:.2rem;}
  table{width:100%;border-collapse:collapse;font-size:.85rem;}
  th{background:#111;color:#fff;padding:8px 10px;text-align:left;}
  td{padding:7px 10px;border-bottom:1px solid #e5e5e5;}
  tr:hover td{background:#fafafa;}
  .badge{display:inline-block;padding:2px 8px;border-radius:50px;font-size:.75rem;font-weight:600;}
  .b-done{background:#dcfce7;color:#166534;}
  .b-pending{background:#fef9c3;color:#854d0e;}
  .b-in_progress{background:#dbeafe;color:#1e40af;}
  .b-overdue{background:#fee2e2;color:#991b1b;}
  .b-cancelled{background:#f3f4f6;color:#6b7280;}
  .b-review{background:#ede9fe;color:#5b21b6;}
  @media print{body{margin:1cm;}}
</style></head><body>
<h1>&#x1F4CB; TaskMate — Task Report</h1>
<p class="sub">Generated on ' . date('F j, Y \a\t H:i') . ' &nbsp;·&nbsp; ' . htmlspecialchars(tm_uname()) . '</p>
<div class="stats">
  <div class="stat"><div class="stat-val">' . $total . '</div><div class="stat-lbl">Total Tasks</div></div>
  <div class="stat"><div class="stat-val">' . $done . '</div><div class="stat-lbl">Completed</div></div>
  <div class="stat"><div class="stat-val">' . $overdue . '</div><div class="stat-lbl">Overdue</div></div>
  <div class="stat"><div class="stat-val">' . $pending . '</div><div class="stat-lbl">Pending</div></div>
  <div class="stat"><div class="stat-val">' . $compRate . '%</div><div class="stat-lbl">Completion Rate</div></div>
</div>
<table>
<thead><tr>
  <th>#</th><th>Task Name</th><th>Category</th><th>Priority</th>
  <th>Due Date</th><th>Status</th>
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
        $name  = trim($_POST['name']           ?? '');
        $start = trim($_POST['startDate']      ?? '');
        $due   = trim($_POST['dueDate']        ?? '');
        $cat   = trim($_POST['category']       ?? 'errands');
        $ccat  = trim($_POST['customCategory'] ?? '');
        $pri   = trim($_POST['priority']       ?? 'mid');
        $col   = trim($_POST['color']          ?? '#ef4444');
        $notes = trim($_POST['notes']          ?? '');
        $recur = trim($_POST['recurrence']     ?? '');
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
        tm_flash('success', 'Task added!');
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
             status=:p9, recurrence=:p10
             WHERE task_id=:p11 AND user_id=:p12",
            [$name, $start, $due, $cat, $ccat, $pri, $col, $notes, $status, $recur ?: null, $id, $uid]
        );

        $auditAction = ($status !== $oldStatus) ? 'status_change' : 'edit';
        tm_audit_sp($uid, $auditAction, 'task', $id, $name,
                 "status:{$oldStatus}, pri:{$oldPri}",
                 "status:{$status}, pri:{$pri}");

        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => $status]);
        tm_flash('success', 'Task updated!');
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

switch ($action) {

    case 'add':
        $name  = trim($_POST['name']           ?? '');
        $start = trim($_POST['startDate']      ?? '');
        $due   = trim($_POST['dueDate']        ?? '');
        $cat   = trim($_POST['category']       ?? 'errands');
        $ccat  = trim($_POST['customCategory'] ?? '');
        $pri   = trim($_POST['priority']       ?? 'mid');
        $col   = trim($_POST['color']          ?? '#ef4444');
        $notes = trim($_POST['notes']          ?? '');

        if (!$name || !$start || !$due) {
            tm_flash('error', 'Name and dates are required.'); break;
        }
        if ($start > $due) {
            tm_flash('error', 'Start date cannot be after due date.'); break;
        }
        tm_exec(
            "INSERT INTO TM_Tasks (user_id, task_name, start_date, due_date, category, custom_category, priority, color, notes)
             VALUES (:p1, :p2, TO_DATE(:p3,'YYYY-MM-DD'), TO_DATE(:p4,'YYYY-MM-DD'), :p5, :p6, :p7, :p8, :p9)",
            [$uid, $name, $start, $due, $cat, $ccat, $pri, $col, $notes]
        );
        // Fetch the new task_id for the audit entry
        $newIdRow = tm_fetch_one(tm_exec(
            "SELECT TM_Tasks_seq.CURRVAL AS new_id FROM DUAL"
        ));
        $newId = (int)($newIdRow['NEW_ID'] ?? $newIdRow['new_id'] ?? 0);
        tm_audit($uid, 'create', 'task', $newId, $name,
                 '', "cat:{$cat}, pri:{$pri}, due:{$due}");
        tm_flash('success', 'Task added!');
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
        $allowed_statuses = ['pending', 'in_progress', 'review', 'done', 'cancelled'];
        if (!in_array($status, $allowed_statuses)) { $status = 'pending'; }

        if ($id <= 0 || !$name || !$start || !$due) {
            tm_flash('error', 'Invalid task data.'); break;
        }

        // Snapshot the old state before overwriting
        $oldRow = tm_fetch_one(tm_exec(
            "SELECT task_name, status, priority FROM TM_Tasks
             WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        ));
        $oldName   = $oldRow['TASK_NAME'] ?? $oldRow['task_name'] ?? '';
        $oldStatus = $oldRow['STATUS']    ?? $oldRow['status']    ?? '';
        $oldPri    = $oldRow['PRIORITY']  ?? $oldRow['priority']  ?? '';

        tm_exec(
            "UPDATE TM_Tasks SET task_name=:p1,
             start_date=TO_DATE(:p2,'YYYY-MM-DD'),
             due_date=TO_DATE(:p3,'YYYY-MM-DD'),
             category=:p4, custom_category=:p5,
             priority=:p6, color=:p7, notes=:p8,
             status=:p9
             WHERE task_id=:p10 AND user_id=:p11",
            [$name, $start, $due, $cat, $ccat, $pri, $col, $notes, $status, $id, $uid]
        );

        // Use status_change action type when only the status moved
        $auditAction = ($status !== $oldStatus) ? 'status_change' : 'edit';
        tm_audit($uid, $auditAction, 'task', $id, $name,
                 "status:{$oldStatus}, pri:{$oldPri}",
                 "status:{$status}, pri:{$pri}");
        tm_flash('success', 'Task updated!');
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid task.'); break; }

        // Snapshot name before deletion so the log is readable after the row is gone
        $delRow = tm_fetch_one(tm_exec(
            "SELECT task_name FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        ));
        $delName = $delRow['TASK_NAME'] ?? $delRow['task_name'] ?? "task #{$id}";

        tm_exec(
            'DELETE FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2',
            [$id, $uid]
        );
        tm_audit($uid, 'delete', 'task', $id, $delName, $delName, '');
        tm_flash('success', 'Task deleted.');
        break;

    case 'quick_done':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid task.'); break; }

        $qdRow = tm_fetch_one(tm_exec(
            "SELECT task_name, status FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        ));
        $qdName  = $qdRow['TASK_NAME'] ?? $qdRow['task_name'] ?? "task #{$id}";
        $qdOldSt = $qdRow['STATUS']    ?? $qdRow['status']    ?? 'pending';

        // ── Blocker enforcement ───────────────────────────────────────────────
        // Count blocking tasks that are not yet done or cancelled.
        // If any exist, reject immediately — no update, no audit log entry.
        $blockerRow = tm_fetch_one(tm_exec(
            "SELECT COUNT(*) AS n
             FROM TM_TaskLinks tl
             JOIN TM_Tasks blocker ON blocker.task_id = tl.depends_on_id
             WHERE tl.task_id   = :p1
               AND tl.link_type = 'blocks'
               AND blocker.status NOT IN ('done', 'cancelled')",
            [$id]
        ));
        $blockerCount = (int)($blockerRow['N'] ?? $blockerRow['n'] ?? 0);

        if ($blockerCount > 0) {
            tm_flash('error',
                "Cannot mark done: {$blockerCount} blocking task"
                . ($blockerCount > 1 ? 's are' : ' is')
                . " still pending."
            );
            break; // ← exits switch; no UPDATE, no audit entry
        }
        // ── End blocker enforcement ───────────────────────────────────────────

        tm_exec(
            "UPDATE TM_Tasks SET status='done' WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        );
        tm_audit($uid, 'status_change', 'task', $id, $qdName,
                 "status:{$qdOldSt}", "status:done");
        tm_flash('success', 'Task marked as done!');
        // Redirect back to whichever tasks view the user came from
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($ref, 'TM_Tasks.php') !== false) {
            header('Location: ' . $ref); exit;
        }
        header('Location: ../TM_Tasks.php'); exit;
}

$redirect = match($action) {
    'quick_done' => '../TM_Tasks.php',
    default      => '../TM_Calendar.php',
};
header('Location: ' . $redirect); exit;