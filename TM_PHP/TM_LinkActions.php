<?php
// ============================================================
// TM_PHP/TM_LinkActions.php
// Handles saving dependency links for a task.
//
// POST params:
//   action      = 'save_links'
//   task_id     = the task being edited
//   blocker_ids = comma-separated list of task_ids that block this task
//                 (empty string = remove all blockers for this task)
//
// Called by the edit modal's save flow in TM_Calendar.js.
// Always responds with JSON.
// ============================================================
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

header('Content-Type: application/json');

$action  = $_POST['action'] ?? '';
$uid     = tm_uid();

if ($action !== 'save_links') {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$taskId     = (int)($_POST['task_id']     ?? 0);
$blockerRaw = trim($_POST['blocker_ids']  ?? '');

if ($taskId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid task']);
    exit;
}

// Verify the task belongs to this user
$ownerRow = tm_fetch_one(tm_exec(
    "SELECT task_id FROM TM_Tasks WHERE task_id = :p1 AND user_id = :p2",
    [$taskId, $uid]
));
if (!$ownerRow) {
    echo json_encode(['ok' => false, 'error' => 'Task not found']);
    exit;
}

// Parse and validate blocker IDs
$blockerIds = [];
if ($blockerRaw !== '') {
    foreach (explode(',', $blockerRaw) as $raw) {
        $bid = (int)trim($raw);
        // Must be a positive int, not the task itself, and owned by this user
        if ($bid > 0 && $bid !== $taskId) {
            $blockerIds[] = $bid;
        }
    }
    $blockerIds = array_unique($blockerIds);
}

try {
    // 1. Delete all existing 'blocks' links where this task is the blocked one
    tm_exec(
        "DELETE FROM TM_TaskLinks
         WHERE task_id = :p1 AND link_type = 'blocks'",
        [$taskId]
    );

    // 2. Insert the new set
    foreach ($blockerIds as $bid) {
        // Verify blocker also belongs to this user (security — no cross-user links)
        $blockerOwner = tm_fetch_one(tm_exec(
            "SELECT task_id FROM TM_Tasks WHERE task_id = :p1 AND user_id = :p2",
            [$bid, $uid]
        ));
        if (!$blockerOwner) continue; // silently skip invalid IDs

        // UNIQUE constraint on (task_id, depends_on_id) — use MERGE to avoid duplicates
        tm_exec(
            "MERGE INTO TM_TaskLinks tl
             USING (SELECT :p1 AS task_id, :p2 AS depends_on_id FROM DUAL) src
             ON (tl.task_id = src.task_id AND tl.depends_on_id = src.depends_on_id)
             WHEN NOT MATCHED THEN
                INSERT (task_id, depends_on_id, link_type)
                VALUES (:p3, :p4, 'blocks')",
            [$taskId, $bid, $taskId, $bid]
        );
    }

    echo json_encode(['ok' => true, 'saved' => count($blockerIds)]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
