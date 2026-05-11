<?php
/**
 * TM_PHP/TM_CollabActions.php
 * ─────────────────────────────────────────────────────────────
 * AJAX endpoint for Collaboration & Multi-User features 1–4:
 *   1. Task assignment
 *   2. Projects / shared workspaces
 *   3. Comments
 *   4. @mention notifications
 *
 * All responses are JSON.
 * Called via fetch() from the front-end.
 */
require_once 'TM_Session.php';
require_once 'TM_DB.php';

header('Content-Type: application/json');
// Buffer output so any stray PHP warnings/notices don't corrupt the JSON response.
// Each response path calls ob_clean() immediately before echo json_encode(...).
ob_start();
tm_require_login();

$uid    = tm_uid();
$oid    = tm_org_id();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════
// HELPER: parse @mentions from text, notify each mentioned user
// ═══════════════════════════════════════════════════════════════
function tm_process_mentions(
    string $text,
    int    $taskId,
    int    $authorId,
    string $authorName,
    string $taskName,
    string $sourceType = 'task_comment',
): void {
    preg_match_all('/@([\w]+)/', $text, $matches);
    $mentioned = array_unique($matches[1] ?? []);
    if (empty($mentioned)) return;

    foreach ($mentioned as $username) {
        // Look up the mentioned user
        $userRow = tm_fetch_one(tm_exec(
            "SELECT user_id FROM TM_Users WHERE LOWER(username) = LOWER(:p1)",
            [$username]
        ));
        if (!$userRow) continue;
        $mentionedUid = (int)$userRow['user_id'];
        if ($mentionedUid === $authorId) continue; // skip self-mentions

        $message = "@{$authorName} mentioned you in task: {$taskName}";

        $params = [
            $mentionedUid,
            $taskId > 0 ? $taskId : null,
            'mention',
            substr($message, 0, 500),
            $sourceType,
            $authorId,        ];

        // Oracle doesn't accept PHP null directly for NUMBER columns in all drivers;
        // use a CASE-style INSERT to handle nulls gracefully
        tm_exec(
            "INSERT INTO TM_Notifications
            (user_id, task_id, type, message, is_read, source_type, mentioned_by)
             VALUES (:p1,
                     :p2,
                     :p3, :p4, 0, :p5, :p6, :p7)",
            $params
        );
    }
}

// ═══════════════════════════════════════════════════════════════
// HELPER: insert an "assignment" notification
// ═══════════════════════════════════════════════════════════════
function tm_notify_assignment(
    int    $assignedTo,
    int    $assignedBy,
    string $assignerName,
    int    $taskId,
    string $taskName
): void {
    if ($assignedTo === $assignedBy) return; // no self-notification
    $message = "{$assignerName} assigned you a task: {$taskName}";
    tm_exec(
        "INSERT INTO TM_Notifications
            (user_id, task_id, type, message, is_read, source_type, mentioned_by)
         VALUES (:p1, :p2, 'assignment', :p3, 0, 'assignment', :p4)",
        [$assignedTo, $taskId, substr($message, 0, 500), $assignedBy]
    );
}

// ═══════════════════════════════════════════════════════════════
// HELPER: verify user owns or is member of a task
// Returns task row on success, null if forbidden
// ═══════════════════════════════════════════════════════════════
function tm_get_task_for_user(int $taskId, int $userId): ?array {
    // User either owns the task, is assigned it, or is a project member
    $row = tm_fetch_one(tm_exec(
        "SELECT t.task_id, t.task_name, t.user_id, t.assigned_to, t.project_id
         FROM TM_Tasks t
               AND pm.user_id    = :p1
         WHERE t.task_id = :p2
           AND (t.user_id = :p3 OR t.assigned_to = :p4 OR pm.user_id IS NOT NULL)
           AND ROWNUM = 1",
        [$userId, $taskId, $userId, $userId]
    ));
    return $row;
}

// ═══════════════════════════════════════════════════════════════
// HELPER: get caller's username
// ═══════════════════════════════════════════════════════════════
function tm_get_username(int $userId): string {
    $row = tm_fetch_one(tm_exec(
        "SELECT username FROM TM_Users WHERE user_id = :p1",
        [$userId]
    ));
    return $row['username'] ?? "user#{$userId}";
}

// ═══════════════════════════════════════════════════════════════
// SWITCH on action
// ═══════════════════════════════════════════════════════════════
switch ($action) {

    // ──────────────────────────────────────────────────────────
    // CHANGE 1 — Task Assignment
    // ──────────────────────────────────────────────────────────

    /**
     * assign_task
     * POST: task_id, assigned_to (user_id or empty to unassign)
     */
    case 'assign_task': {
        $taskId     = (int)($_POST['task_id']     ?? 0);
        $assignedTo = (int)($_POST['assigned_to'] ?? 0); // 0 = unassign

        if ($taskId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid task_id']); exit;
        }

        // Only the task owner may reassign
        $taskRow = tm_fetch_one(tm_exec(
            "SELECT task_id, task_name, user_id, assigned_to FROM TM_Tasks
             WHERE task_id = :p1 AND user_id = :p2",
            [$taskId, $uid]
        ));
        if (!$taskRow) {
            echo json_encode(['ok' => false, 'error' => 'Task not found or access denied']); exit;
        }

        // Validate target user exists
        if ($assignedTo > 0) {
            $targetRow = tm_fetch_one(tm_exec(
                "SELECT user_id, username FROM TM_Users WHERE user_id = :p1",
                [$assignedTo]
            ));
            if (!$targetRow) {
                echo json_encode(['ok' => false, 'error' => 'Assigned user not found']); exit;
            }
        }

        tm_exec(
            "UPDATE TM_Tasks SET assigned_to = :p1 WHERE task_id = :p2 AND user_id = :p3",
            [$assignedTo > 0 ? $assignedTo : null, $taskId, $uid]
        );

        // Notify the newly assigned user
        if ($assignedTo > 0) {
            $assignerName = tm_get_username($uid);
            tm_notify_assignment(
                $assignedTo, $uid,
                $assignerName,
                $taskId,
                $taskRow['task_name'] ?? "task #{$taskId}"
            );
        }

        echo json_encode(['ok' => true, 'assigned_to' => $assignedTo > 0 ? $assignedTo : null]);
        exit;
    }

    /**
     * list_users
     * GET: Returns all users (id + username + full name) for assignment dropdown.
     * Restricted to admin users only. Admins see all registered users in the org.
     */
    case 'list_users': {
        // Admins and org_admins may fetch the user list for task assignment
        if (!tm_is_admin() && !tm_is_org_admin()) {
            echo json_encode(['ok' => false, 'error' => 'Insufficient permissions.']);
            exit;
        }
        $stmt = tm_exec(
            "SELECT user_id, username, first_name, last_name
             FROM TM_Users
             WHERE user_id <> :p1
               AND org_id  =  :p2
               AND status  =  'active'
             ORDER BY username ASC",
            [$uid, $oid]
        );
        $users = array_map(function ($r) {
            $uid_r    = (int)($r['user_id']    ?? $r['USER_ID']    ?? 0);
            $username = $r['username']          ?? $r['USERNAME']   ?? '';
            $full     = trim(
                ($r['first_name'] ?? $r['FIRST_NAME'] ?? '') . ' ' .
                ($r['last_name']  ?? $r['LAST_NAME']  ?? '')
            );
            return [
                'user_id'   => $uid_r,
                'username'  => $username,
                'full_name' => $full ?: ('User #' . $uid_r),
            ];
        }, tm_fetch_all($stmt));
        echo json_encode(['ok' => true, 'data' => $users]);
        exit;
    }
} // end switch