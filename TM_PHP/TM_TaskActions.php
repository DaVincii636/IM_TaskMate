<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

$action = $_POST['action'] ?? '';
$uid    = tm_uid();

// ── Audit helper ──────────────────────────────────────────────────────────────
// Silently inserts one row into TM_AuditLog. Errors are intentionally swallowed
// so a logging failure never blocks the actual user action.
function tm_audit(int $userId, string $action, string $entityType,
                  int $entityId, string $entityName,
                  string $oldValue = '', string $newValue = ''): void {
    try {
        tm_exec(
            "INSERT INTO TM_AuditLog
                (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
             VALUES (:p1, :p2, :p3, :p4, :p5, :p6, :p7)",
            [$userId, $action, $entityType, $entityId,
             substr($entityName, 0, 255),
             substr($oldValue,   0, 500),
             substr($newValue,   0, 500)]
        );
    } catch (Throwable $e) {
        // Never let audit failure surface to the user
    }
}

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
        $qdName   = $qdRow['TASK_NAME'] ?? $qdRow['task_name'] ?? "task #{$id}";
        $qdOldSt  = $qdRow['STATUS']    ?? $qdRow['status']    ?? 'pending';

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