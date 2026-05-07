<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

$action = $_POST['action'] ?? '';
$uid    = tm_uid();

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
        tm_flash('success', 'Task updated!');
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid task.'); break; }
        tm_exec(
            'DELETE FROM TM_Tasks WHERE task_id=:p1 AND user_id=:p2',
            [$id, $uid]
        );
        tm_flash('success', 'Task deleted.');
        break;

    case 'quick_done':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid task.'); break; }
        tm_exec(
            "UPDATE TM_Tasks SET status='done' WHERE task_id=:p1 AND user_id=:p2",
            [$id, $uid]
        );
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