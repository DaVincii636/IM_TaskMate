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
    int    $commentId  = 0
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
            $authorId,
            $commentId > 0 ? $commentId : null,
        ];

        // Oracle doesn't accept PHP null directly for NUMBER columns in all drivers;
        // use a CASE-style INSERT to handle nulls gracefully
        tm_exec(
            "INSERT INTO TM_Notifications
                (user_id, task_id, type, message, is_read, source_type, mentioned_by, comment_id)
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
         LEFT JOIN TM_ProjectMembers pm
               ON  pm.project_id = t.project_id
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
        // Only admin users may fetch the full user list for task assignment
        if (!tm_is_admin()) {
            echo json_encode(['ok' => false, 'error' => 'Insufficient permissions.']);
            exit;
        }
        $stmt = tm_exec(
            "SELECT user_id, username, first_name, last_name
             FROM TM_Users
             WHERE user_id <> :p1
               AND org_id  =  :p2
             ORDER BY username ASC",
            [$uid, $oid]
        );
        $users = array_map(function ($r) {
            $full = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return [
                'user_id'   => (int)$r['user_id'],
                'full_name' => $full ?: ('User #' . (int)$r['user_id']),
            ];
        }, tm_fetch_all($stmt));
        echo json_encode(['ok' => true, 'data' => $users]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // CHANGE 2 — Projects / Shared Workspaces
    // ──────────────────────────────────────────────────────────

    /**
     * create_project
     * POST: name, description?, color?
     */
    case 'create_project': {
        $name  = trim($_POST['name']        ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $color = trim($_POST['color']       ?? '#3b82f6');

        if (!$name) {
            echo json_encode(['ok' => false, 'error' => 'Project name is required']); exit;
        }

        // Insert project
        $newId = 0;
        $plsql = "DECLARE v_id NUMBER; BEGIN
                      INSERT INTO TM_Projects (name, description, color, created_by)
                      VALUES (:p1, :p2, :p3, :p4)
                      RETURNING project_id INTO v_id;
                      :p5 := v_id;
                  END;";
        global $conn;
        $stmt = oci_parse($conn, $plsql);
        oci_bind_by_name($stmt, ':p1', $name,  150);
        oci_bind_by_name($stmt, ':p2', $desc,  500);
        oci_bind_by_name($stmt, ':p3', $color,  20);
        oci_bind_by_name($stmt, ':p4', $uid,    10);
        oci_bind_by_name($stmt, ':p5', $newId,  10);
        oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
        oci_free_statement($stmt);

        // Auto-add creator as owner member
        if ($newId > 0) {
            tm_exec(
                "INSERT INTO TM_ProjectMembers (project_id, user_id, role)
                 VALUES (:p1, :p2, 'owner')",
                [$newId, $uid]
            );
        }

        echo json_encode(['ok' => true, 'project_id' => (int)$newId, 'name' => $name]);
        exit;
    }

    /**
     * list_projects
     * GET: Returns all projects the current user belongs to.
     */
    case 'list_projects': {
        $stmt = tm_exec(
            "SELECT p.project_id, p.name, p.description, p.color,
                    pm.role,
                    (SELECT COUNT(*) FROM TM_ProjectMembers m2
                     WHERE m2.project_id = p.project_id) AS member_count,
                    (SELECT COUNT(*) FROM TM_Tasks t
                     WHERE t.project_id = p.project_id) AS task_count
             FROM TM_Projects p
             JOIN TM_ProjectMembers pm
               ON pm.project_id = p.project_id
              AND pm.user_id    = :p1
             ORDER BY p.name ASC",
            [$uid]
        );
        $projects = array_map(function ($r) {
            return [
                'project_id'   => (int)$r['project_id'],
                'name'         => $r['name']         ?? '',
                'description'  => $r['description']  ?? '',
                'color'        => $r['color']         ?? '#3b82f6',
                'role'         => $r['role']          ?? 'member',
                'member_count' => (int)$r['member_count'],
                'task_count'   => (int)$r['task_count'],
            ];
        }, tm_fetch_all($stmt));
        echo json_encode(['ok' => true, 'data' => $projects]);
        exit;
    }

    /**
     * add_project_member
     * POST: project_id, email (invite by email address)
     */
    case 'add_project_member': {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $email     = trim($_POST['email'] ?? '');

        if ($projectId <= 0 || !$email) {
            echo json_encode(['ok' => false, 'error' => 'project_id and email required']); exit;
        }

        // Caller must be owner
        $ownerCheck = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_ProjectMembers
             WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $uid]
        ));
        if (!$ownerCheck || $ownerCheck['role'] !== 'owner') {
            echo json_encode(['ok' => false, 'error' => 'Only the project owner can add members']); exit;
        }

        $targetRow = tm_fetch_one(tm_exec(
            "SELECT user_id, username FROM TM_Users WHERE LOWER(email) = LOWER(:p1)",
            [$email]
        ));
        if (!$targetRow) {
            echo json_encode(['ok' => false, 'error' => "No account found for '{$email}'"]); exit;
        }
        $targetId = (int)$targetRow['user_id'];

        // Idempotent insert
        $existing = tm_fetch_one(tm_exec(
            "SELECT member_id FROM TM_ProjectMembers
             WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $targetId]
        ));
        if ($existing) {
            echo json_encode(['ok' => true, 'info' => 'Already a member']); exit;
        }

        tm_exec(
            "INSERT INTO TM_ProjectMembers (project_id, user_id, role)
             VALUES (:p1, :p2, 'member')",
            [$projectId, $targetId]
        );

        echo json_encode(['ok' => true, 'added_user_id' => $targetId, 'username' => $targetRow['username']]);
        exit;
    }

    /**
     * get_project_members
     * GET: project_id
     */
    case 'get_project_members': {
        $projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
        if ($projectId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'project_id required']); exit;
        }
        // Caller must be a member
        $access = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_ProjectMembers WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $uid]
        ));
        if (!$access) {
            echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
        }
        $stmt = tm_exec(
            "SELECT u.user_id, u.username, u.first_name, u.last_name, pm.role, pm.joined_at
             FROM TM_Users u
             JOIN TM_ProjectMembers pm ON pm.user_id = u.user_id
             WHERE pm.project_id = :p1
             ORDER BY pm.role DESC, u.username ASC",
            [$projectId]
        );
        $members = array_map(function ($r) {
            $full = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return [
                'user_id'   => (int)$r['user_id'],
                'full_name' => $full ?: ('User #' . (int)$r['user_id']),
                'role'      => $r['role'] ?? 'member',
            ];
        }, tm_fetch_all($stmt));
        echo json_encode(['ok' => true, 'data' => $members]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // CHANGE 3 — Comments
    // ──────────────────────────────────────────────────────────

    /**
     * get_comments
     * GET: task_id
     */
    case 'get_comments': {
        $taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'task_id required']); exit;
        }

        // Access check: must own, be assigned, or be a project member
        $access = tm_get_task_for_user($taskId, $uid);
        if (!$access) {
            echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
        }

        $stmt = tm_exec(
            "SELECT c.comment_id, c.user_id, u.username, u.first_name, u.last_name,
                    c.content,
                    TO_CHAR(c.created_at, 'Mon DD, YYYY HH24:MI') AS created_fmt
             FROM TM_Comments c
             JOIN TM_Users u ON u.user_id = c.user_id
             WHERE c.task_id = :p1
             ORDER BY c.created_at ASC",
            [$taskId]
        );
        $raw = tm_fetch_all($stmt);
        $comments = array_map(function ($r) {
            // Resolve CLOB
            $content = $r['content'] ?? '';
            if ($content instanceof OCILob) $content = $content->load();
            elseif (is_resource($content))  $content = stream_get_contents($content);
            return [
                'comment_id'  => (int)$r['comment_id'],
                'user_id'     => (int)$r['user_id'],
                'username'    => $r['username']   ?? '',
                'full_name'   => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'content'     => (string)$content,
                'created_fmt' => $r['created_fmt'] ?? '',
            ];
        }, $raw);

        echo json_encode(['ok' => true, 'data' => $comments]);
        exit;
    }

    /**
     * add_comment
     * POST: task_id, content
     * Side-effect: parses @mentions and inserts TM_Notifications
     */
    case 'add_comment': {
        $taskId  = (int)($_POST['task_id']  ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($taskId <= 0 || $content === '') {
            echo json_encode(['ok' => false, 'error' => 'task_id and content required']); exit;
        }

        // Access check
        $taskRow = tm_get_task_for_user($taskId, $uid);
        if (!$taskRow) {
            echo json_encode(['ok' => false, 'error' => 'Task not found or access denied']); exit;
        }
        $taskName = $taskRow['task_name'] ?? "task #{$taskId}";

        // Insert comment and get its new ID
        $newCommentId = 0;
        $plsql = "DECLARE v_id NUMBER; BEGIN
                      INSERT INTO TM_Comments (task_id, user_id, content)
                      VALUES (:p1, :p2, :p3)
                      RETURNING comment_id INTO v_id;
                      :p4 := v_id;
                  END;";
        global $conn;
        $stmt = oci_parse($conn, $plsql);
        oci_bind_by_name($stmt, ':p1', $taskId,      10);
        oci_bind_by_name($stmt, ':p2', $uid,         10);
        oci_bind_by_name($stmt, ':p3', $content,     -1);
        oci_bind_by_name($stmt, ':p4', $newCommentId, 10);
        oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
        oci_free_statement($stmt);

        // CHANGE 4 — process @mentions
        $authorName = tm_get_username($uid);
        tm_process_mentions($content, $taskId, $uid, $authorName, $taskName, 'task_comment', (int)$newCommentId);

        echo json_encode([
            'ok'         => true,
            'comment_id' => (int)$newCommentId,
            'username'   => $authorName,
        ]);
        exit;
    }

    /**
     * delete_comment
     * POST: comment_id
     * Only the comment author may delete their own comment.
     */
    case 'delete_comment': {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'comment_id required']); exit;
        }
        $row = tm_fetch_one(tm_exec(
            "SELECT comment_id FROM TM_Comments WHERE comment_id = :p1 AND user_id = :p2",
            [$commentId, $uid]
        ));
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Comment not found or access denied']); exit;
        }
        tm_exec("DELETE FROM TM_Comments WHERE comment_id = :p1", [$commentId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // CHANGE 4 — @mention in task notes (on task save)
    // Called from TM_TaskActions.php edit/add after saving notes.
    // ──────────────────────────────────────────────────────────

    /**
     * process_note_mentions
     * POST: task_id, notes (the saved notes text)
     * Parses @mentions from notes and fires notifications.
     */
    case 'process_note_mentions': {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $notes  = trim($_POST['notes']   ?? '');

        if ($taskId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'task_id required']); exit;
        }

        $taskRow = tm_get_task_for_user($taskId, $uid);
        if (!$taskRow) {
            echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
        }

        $authorName = tm_get_username($uid);
        $taskName   = $taskRow['task_name'] ?? "task #{$taskId}";
        tm_process_mentions($notes, $taskId, $uid, $authorName, $taskName, 'task_note');

        echo json_encode(['ok' => true]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // remove_project_member
    // POST: project_id, user_id
    // Only the project owner may remove members (cannot remove themselves).
    // ──────────────────────────────────────────────────────────
    case 'remove_project_member': {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $targetId  = (int)($_POST['user_id']    ?? 0);

        if ($projectId <= 0 || $targetId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'project_id and user_id required']); exit;
        }

        // Caller must be owner
        $callerRole = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_ProjectMembers WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $uid]
        ));
        if (!$callerRole || $callerRole['role'] !== 'owner') {
            echo json_encode(['ok' => false, 'error' => 'Only the project owner can remove members']); exit;
        }

        // Cannot remove yourself (owner must delete the project instead)
        if ($targetId === $uid) {
            echo json_encode(['ok' => false, 'error' => 'Owner cannot remove themselves. Delete the project instead.']); exit;
        }

        tm_exec(
            "DELETE FROM TM_ProjectMembers WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $targetId]
        );

        echo json_encode(['ok' => true]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // delete_project
    // POST: project_id
    // Only the project owner may delete it.
    // Cascade: TM_ProjectMembers rows are deleted via FK ON DELETE CASCADE.
    // Tasks that referenced this project have their project_id set to NULL
    // via FK ON DELETE SET NULL.
    // ──────────────────────────────────────────────────────────
    case 'delete_project': {
        $projectId = (int)($_POST['project_id'] ?? 0);

        if ($projectId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'project_id required']); exit;
        }

        // Caller must be owner
        $callerRole = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_ProjectMembers WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $uid]
        ));
        if (!$callerRole || $callerRole['role'] !== 'owner') {
            echo json_encode(['ok' => false, 'error' => 'Only the project owner can delete the project']); exit;
        }

        // Nullify project_id on tasks before deleting (in case FK isn't CASCADE SET NULL in this Oracle version)
        tm_exec(
            "UPDATE TM_Tasks SET project_id = NULL WHERE project_id = :p1",
            [$projectId]
        );

        // Delete members first (FK cascade should handle it, but be explicit)
        tm_exec("DELETE FROM TM_ProjectMembers WHERE project_id = :p1", [$projectId]);

        // Delete the project
        tm_exec("DELETE FROM TM_Projects WHERE project_id = :p1", [$projectId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // update_project
    // POST: project_id, name, description?, color?
    // Only the project owner may update it.
    // ──────────────────────────────────────────────────────────
    case 'update_project': {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $name      = trim($_POST['name']        ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $color     = trim($_POST['color']       ?? '#3b82f6');

        if ($projectId <= 0 || !$name) {
            echo json_encode(['ok' => false, 'error' => 'project_id and name required']); exit;
        }

        // Caller must be owner
        $callerRole = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_ProjectMembers WHERE project_id = :p1 AND user_id = :p2",
            [$projectId, $uid]
        ));
        if (!$callerRole || $callerRole['role'] !== 'owner') {
            echo json_encode(['ok' => false, 'error' => 'Only the project owner can edit the project']); exit;
        }

        tm_exec(
            "UPDATE TM_Projects SET name = :p1, description = :p2, color = :p3
             WHERE project_id = :p4",
            [$name, $desc, $color, $projectId]
        );

        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * get_project_tasks
     * GET: project_id
     * Returns all tasks linked to this project that the user can see.
     */
    case 'get_project_tasks': {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if (!$projectId) { echo json_encode(['ok' => false, 'error' => 'Missing project_id']); exit; }

        try {
            // FIX: Show all tasks in the project if the user is a member or owner.
            // The old JOIN/filter hid tasks the user neither owns nor is assigned to,
            // even though they're a project member — causing "Failed to load tasks".
            // Now we verify membership once, then return all tasks for the project.
            $memberChk = tm_fetch_one(tm_exec(
                "SELECT COUNT(*) AS cnt FROM TM_ProjectMembers
                 WHERE project_id = :p1 AND user_id = :p2",
                [$projectId, $uid]
            ));
            $ownerChk = tm_fetch_one(tm_exec(
                "SELECT COUNT(*) AS cnt FROM TM_Projects
                 WHERE project_id = :p1 AND owner_id = :p2",
                [$projectId, $uid]
            ));
            $isMember = (int)($memberChk['cnt'] ?? $memberChk['CNT'] ?? 0) > 0
                     || (int)($ownerChk['cnt']  ?? $ownerChk['CNT']  ?? 0) > 0;
            if (!$isMember) {
                echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
            }
            $stmt = tm_exec(
                "SELECT t.task_id, t.task_name AS name, t.status,
                        TO_CHAR(t.due_date, 'YYYY-MM-DD') AS due_date
                 FROM TM_Tasks t
                 WHERE t.project_id = :p1
                 ORDER BY t.due_date ASC, t.task_name ASC",
                [$projectId]
            );
            // OCI8 returns uppercase keys — normalise to lowercase for JS
            $rows = array_map(
                fn($r) => array_change_key_case($r, CASE_LOWER),
                tm_fetch_all($stmt)
            );
            echo json_encode(['ok' => true, 'data' => $rows]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => 'Failed to load tasks: ' . $e->getMessage()]);
        }
        exit;
    }

    /**
     * get_unlinked_tasks
     * GET: project_id
     * Returns tasks owned by or assigned to this user that are NOT yet in this project.
     */
    case 'get_unlinked_tasks': {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if (!$projectId) { echo json_encode(['ok' => false, 'error' => 'Missing project_id']); exit; }

        try {
            // OCI8: each named placeholder must appear exactly once.
            // :p1 = uid (user_id), :p2 = projectId (exclude), :p3 = uid (assigned_to), :p4 = oid (org)
            $stmt = tm_exec(
                "SELECT t.task_id, t.task_name AS name,
                        TO_CHAR(t.due_date, 'YYYY-MM-DD') AS due_date
                 FROM TM_Tasks t
                 WHERE (t.user_id = :p1 OR t.assigned_to = :p3
                        OR (t.is_org_task = 1 AND t.org_id = :p4))
                   AND (t.project_id IS NULL OR t.project_id != :p2)
                   AND t.status NOT IN ('done', 'done_late', 'cancelled')
                 ORDER BY t.task_name ASC",
                [$uid, $projectId, $uid, $oid]
            );
            $rows = array_map(
                fn($r) => array_change_key_case($r, CASE_LOWER),
                tm_fetch_all($stmt)
            );
            echo json_encode(['ok' => true, 'data' => $rows]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => 'Failed to load tasks: ' . $e->getMessage()]);
        }
        exit;
    }

    /**
     * link_task_project
     * POST: task_id, project_id
     * Sets project_id on a task the user owns or is assigned to.
     */
    case 'link_task_project': {
        $taskId    = (int)($_POST['task_id']    ?? 0);
        $projectId = (int)($_POST['project_id'] ?? 0);
        if (!$taskId || !$projectId) { echo json_encode(['ok' => false, 'error' => 'Missing task_id or project_id']); exit; }

        // Verify user owns the task or is assigned to it
        $chk = tm_exec(
            "SELECT task_id FROM TM_Tasks WHERE task_id = :p1 AND (user_id = :p2 OR assigned_to = :p3)",
            [$taskId, $uid, $uid]
        );
        if (!tm_fetch_one($chk)) { echo json_encode(['ok' => false, 'error' => 'Task not found or permission denied']); exit; }

        tm_exec("UPDATE TM_Tasks SET project_id = :p1 WHERE task_id = :p2", [$projectId, $taskId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * unlink_task_project
     * POST: task_id, project_id
     * Clears project_id from a task (sets to NULL).
     */
    case 'unlink_task_project': {
        $taskId    = (int)($_POST['task_id']    ?? 0);
        $projectId = (int)($_POST['project_id'] ?? 0);
        if (!$taskId || !$projectId) { echo json_encode(['ok' => false, 'error' => 'Missing params']); exit; }

        $chk = tm_exec(
            "SELECT task_id FROM TM_Tasks WHERE task_id = :p1 AND project_id = :p2 AND (user_id = :p3 OR assigned_to = :p4)",
            [$taskId, $projectId, $uid, $uid]
        );
        if (!tm_fetch_one($chk)) { echo json_encode(['ok' => false, 'error' => 'Task not found or permission denied']); exit; }

        tm_exec("UPDATE TM_Tasks SET project_id = NULL WHERE task_id = :p1", [$taskId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * get_task_collab
     * GET: task_id
     * Returns assigned_to (with full name), project_id/name/color,
     * team_id/name, and owner_id for a task.
     */
    case 'get_task_collab': {
        $taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'task_id required']); exit;
        }
        $isMod = tm_is_moderator();
        $taskCond = $isMod
            ? "t.task_id = :p1 AND t.org_id = :p2"
            : "t.task_id = :p1 AND (t.user_id = :p2 OR t.assigned_to = :p3
               OR EXISTS (SELECT 1 FROM TM_ProjectMembers pm
                          WHERE pm.project_id = t.project_id AND pm.user_id = :p4))";
        $taskParams = $isMod ? [$taskId, $oid] : [$taskId, $uid, $uid, $uid];

        try {
            $row = tm_fetch_one(tm_exec(
                "SELECT t.user_id AS owner_id, t.assigned_to, t.project_id, t.org_id,
                        u.first_name  AS asgn_first,  u.last_name  AS asgn_last,
                        p.name        AS project_name, p.color      AS project_color,
                        p.team_id,
                        tm.team_name,
                        o.org_name
                 FROM TM_Tasks t
                 LEFT JOIN TM_Users        u  ON u.user_id    = t.assigned_to
                 LEFT JOIN TM_Projects     p  ON p.project_id = t.project_id
                 LEFT JOIN TM_Teams        tm ON tm.team_id   = p.team_id
                 LEFT JOIN TM_Organizations o  ON o.org_id     = t.org_id
                 WHERE $taskCond",
                $taskParams
            ));
            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'Not found']); exit;
            }
            // OCI8 returns uppercase keys — normalise
            $r = array_change_key_case($row, CASE_LOWER);
            $fullName = trim(($r['asgn_first'] ?? '') . ' ' . ($r['asgn_last'] ?? '')) ?: null;

            // Fetch prerequisite/blocker tasks (tasks that must be done before this one)
            $blockerRows = tm_fetch_all(tm_exec(
                "SELECT t.task_id, t.task_name, t.status
                 FROM TM_TaskLinks tl
                 JOIN TM_Tasks t ON t.task_id = tl.depends_on_id
                 WHERE tl.task_id   = :p1
                   AND tl.link_type = 'blocks'
                 ORDER BY t.due_date ASC",
                [$taskId]
            ));
            $blockers = array_map(function ($br) {
                $br = array_change_key_case($br, CASE_LOWER);
                return [
                    'id'     => (int)($br['task_id']   ?? 0),
                    'name'   => $br['task_name'] ?? '',
                    'status' => $br['status']    ?? 'pending',
                ];
            }, $blockerRows);

            echo json_encode([
                'ok'                 => true,
                'owner_id'           => $r['owner_id']      ? (int)$r['owner_id']    : null,
                'assigned_to'        => $r['assigned_to']   ? (int)$r['assigned_to'] : null,
                'assigned_full_name' => $fullName,
                'project_id'         => $r['project_id']    ? (int)$r['project_id']  : null,
                'project_name'       => $r['project_name']  ?? null,
                'project_color'      => $r['project_color'] ?? null,
                'team_id'            => $r['team_id']       ? (int)$r['team_id']     : null,
                'team_name'          => $r['team_name']      ?? null,
                'org_name'           => $r['org_name']       ?? null,
                'blockers'           => $blockers,
            ]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => 'Failed to load task details: ' . $e->getMessage()]);
        }
        exit;
    }

    default:
        echo json_encode(['ok' => false, 'error' => "Unknown action: '{$action}'"]);
        exit;
}
