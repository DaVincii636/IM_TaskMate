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
             VALUES (?, ?, TO_DATE(?,'YYYY-MM-DD'), TO_DATE(?,'YYYY-MM-DD'), ?, ?, ?, ?, ?)",
            [$uid, $name, $start, $due, $cat, $ccat, $pri, $col, $notes]
        );
        tm_flash('success', 'Task added!');
        break;

    case 'edit':
        $id    = (int)($_POST['id']            ?? 0);
        $name  = trim($_POST['name']           ?? '');
        $start = trim($_POST['startDate']      ?? '');
        $due   = trim($_POST['dueDate']        ?? '');
        $cat   = trim($_POST['category']       ?? 'errands');
        $ccat  = trim($_POST['customCategory'] ?? '');
        $pri   = trim($_POST['priority']       ?? 'mid');
        $col   = trim($_POST['color']          ?? '#ef4444');
        $notes = trim($_POST['notes']          ?? '');

        if ($id <= 0 || !$name || !$start || !$due) {
            tm_flash('error', 'Invalid task data.'); break;
        }
        tm_exec(
            "UPDATE TM_Tasks SET task_name=?,
             start_date=TO_DATE(?,'YYYY-MM-DD'),
             due_date=TO_DATE(?,'YYYY-MM-DD'),
             category=?, custom_category=?,
             priority=?, color=?, notes=?
             WHERE task_id=? AND user_id=?",
            [$name, $start, $due, $cat, $ccat, $pri, $col, $notes, $id, $uid]
        );
        tm_flash('success', 'Task updated!');
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid task.'); break; }
        tm_exec(
            'DELETE FROM TM_Tasks WHERE task_id=? AND user_id=?',
            [$id, $uid]
        );
        tm_flash('success', 'Task deleted.');
        break;
}

header('Location: ../TM_Calendar.php'); exit;