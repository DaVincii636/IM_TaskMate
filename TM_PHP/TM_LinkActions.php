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

switch ($action) {

    case 'save_links':
        // Persist dependency (blocker) links when a task is edited.
        // Replaces existing links entirely with the new set.
        $taskId     = (int)($_POST['task_id']     ?? 0);
        $blockerRaw = trim($_POST['blocker_ids']  ?? '');

        if ($taskId <= 0) {
            if ($isApi) tm_api_err('Invalid task_id.');
            break;
        }
        // Verify the task belongs to this user
        $ownerRow = tm_fetch_one(tm_exec(
            "SELECT task_id FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
            [$taskId, $uid]
        ));
        if (!$ownerRow) {
            if ($isApi) tm_api_err('Task not found.', 404);
            break;
        }

        // Delete all existing blocker links for this task
        tm_exec(
            "DELETE FROM TM_TaskLinks WHERE task_id=:p1 AND link_type='blocks'",
            [$taskId]
        );

        // Insert the new set (empty string = remove all, which we already did)
        if ($blockerRaw !== '') {
            foreach (explode(',', $blockerRaw) as $rawBid) {
                $bid = (int)trim($rawBid);
                if ($bid <= 0 || $bid === $taskId) continue;
                // Verify the blocker task belongs to this user
                $bo = tm_fetch_one(tm_exec(
                    "SELECT task_id FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
                    [$bid, $uid]
                ));
                if (!$bo) continue;
                tm_exec(
                    "INSERT INTO TM_TaskLinks (task_id, depends_on_id, link_type)
                     VALUES (:p1, :p2, 'blocks')",
                    [$taskId, $bid]
                );
            }
        }

        if ($isApi) tm_api_ok(['task_id' => $taskId, 'saved' => true]);
        // save_links is always called via fetch(); no redirect needed
        break;


        $name  = trim($_POST['name']           ?? '');
        $start = trim($_POST['startDate']      ?? '');
        $due   = trim($_POST['dueDate']        ?? '');
        $cat   = trim($_POST['category']       ?? 'errands');
        $ccat  = trim($_POST['customCategory'] ?? '');
        $pri   = trim($_POST['priority']       ?? 'mid');
        $col   = trim($_POST['color']          ?? '#ef4444');
        $notes = trim($_POST['notes']          ?? '');

        if (!$name || !$start || !$due) {
            if ($isApi) tm_api_err('Name and dates are required.');
            tm_flash('error', 'Name and dates are required.'); break;
        }
        if ($start > $due) {
            if ($isApi) tm_api_err('Start date cannot be after due date.');
            tm_flash('error', 'Start date cannot be after due date.'); break;
        }
        tm_exec(
            "INSERT INTO TM_Tasks (user_id, task_name, start_date, due_date, category, custom_category, priority, color, notes)
             VALUES (:p1, :p2, TO_DATE(:p3,'YYYY-MM-DD'), TO_DATE(:p4,'YYYY-MM-DD'), :p5, :p6, :p7, :p8, :p9)",
            [$uid, $name, $start, $due, $cat, $ccat, $pri, $col, $notes]
        );
        $newIdRow = tm_fetch_one(tm_exec(
            "SELECT TM_Tasks_seq.CURRVAL AS new_id FROM DUAL"
        ));
        $newId = (int)($newIdRow['NEW_ID'] ?? $newIdRow['new_id'] ?? 0);
        tm_audit($uid, 'create', 'task', $newId, $name,
                 '', "cat:{$cat}, pri:{$pri}, due:{$due}");

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
             status=:p9
             WHERE task_id=:p10 AND user_id=:p11",
            [$name, $start, $due, $cat, $ccat, $pri, $col, $notes, $status, $id, $uid]
        );

        $auditAction = ($status !== $oldStatus) ? 'status_change' : 'edit';
        tm_audit($uid, $auditAction, 'task', $id, $name,
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
            "SELECT task_name, status FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2",
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

        tm_exec(
            "UPDATE TM_Tasks SET status='done' WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        );
        tm_audit($uid, 'status_change', 'task', $id, $qdName,
                 "status:{$qdOldSt}", "status:done");

        if ($isApi) tm_api_ok(['task_id' => $id, 'status' => 'done']);
        tm_flash('success', 'Task marked as done!');
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
    default      => '../TM_Calendar.php',
};
header('Location: ' . $redirect); exit;