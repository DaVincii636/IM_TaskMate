<?php
// ============================================================
// TM_PHP/TM_GetLinks.php
// Returns the existing 'blocks' dependency links for a task.
//
// GET params:
//   task_id = the task whose blockers to fetch
//
// Response: { ok: true, blockers: [{id, name}, ...] }
// Called by the dependency UI in TM_Calendar.js when the
// edit modal opens.
// ============================================================
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

header('Content-Type: application/json');

$taskId = (int)($_GET['task_id'] ?? 0);
$uid    = tm_uid();

if ($taskId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid task_id']);
    exit;
}

// Verify access: owned by, assigned to, or an org-wide task in this user's org
$oid      = tm_org_id();
$ownerRow = tm_fetch_one(tm_exec(
    "SELECT task_id FROM TM_Tasks
      WHERE task_id = :p1
        AND (user_id = :p2 OR assigned_to = :p3 OR (is_org_task = 1 AND org_id = :p4))",
    [$taskId, $uid, $uid, $oid]
));
if (!$ownerRow) {
    echo json_encode(['ok' => false, 'error' => 'Task not found']);
    exit;
}

// Fetch all tasks that currently block this task
$rows = tm_fetch_all(tm_exec(
    "SELECT t.task_id, t.task_name
     FROM TM_TaskLinks tl
     JOIN TM_Tasks t ON t.task_id = tl.depends_on_id
     WHERE tl.task_id    = :p1
       AND tl.link_type  = 'blocks'
     ORDER BY t.due_date ASC",
    [$taskId]
));

$blockers = array_map(function ($row) {
    return [
        'id'   => (int)($row['TASK_ID']   ?? $row['task_id']),
        'name' => $row['TASK_NAME'] ?? $row['task_name'] ?? '',
    ];
}, $rows);

echo json_encode(['ok' => true, 'blockers' => $blockers]);
