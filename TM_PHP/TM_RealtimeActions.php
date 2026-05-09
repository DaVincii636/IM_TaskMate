<?php
/**
 * TM_PHP/TM_RealtimeActions.php
 * ─────────────────────────────────────────────────────────────
 * COLLABORATION & MULTI-USER — Change 5: Real-time collaboration
 *
 * Polling endpoint called every ~5 s by TM_Realtime.js.
 * Returns task changes since a client-supplied timestamp so the
 * dashboard / task list can refresh only the rows that moved.
 *
 * Also handles:
 *   • presence heartbeat  (action=heartbeat)
 *   • who is online       (action=who_is_online)
 *   • task change poll    (action=poll_changes)
 *   • manual log write    (action=log_change)   — called by TM_TaskActions.php
 *
 * All responses are JSON.
 */
require_once 'TM_Session.php';
require_once 'TM_DB.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

tm_require_login();

$uid    = tm_uid();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Write one row to TM_TaskChangeLog.
 * Called internally and from TM_TaskActions.php.
 */
function tm_log_change(int $taskId, int $changedBy, string $changeType = 'update'): void {
    tm_exec(
        "INSERT INTO TM_TaskChangeLog (task_id, changed_by, change_type)
         VALUES (:p1, :p2, :p3)",
        [$taskId, $changedBy, $changeType]
    );
}

/**
 * Upsert the caller's presence row.
 * Oracle MERGE keeps only one row per user (UQ constraint).
 */
function tm_upsert_presence(int $userId, string $pageType, ?int $taskId, ?int $projectId): void {
    global $conn;

    // Check existing presence row
    $existing = tm_fetch_one(tm_exec(
        "SELECT presence_id FROM TM_ActivePresence WHERE user_id = :p1",
        [$userId]
    ));

    if ($existing) {
        // UPDATE existing row
        tm_exec(
            "UPDATE TM_ActivePresence
             SET page_type  = :p1,
                 task_id    = :p2,
                 project_id = :p3,
                 last_ping  = CURRENT_TIMESTAMP
             WHERE user_id  = :p4",
            [$pageType, $taskId, $projectId, $userId]
        );
    } else {
        // INSERT new row
        tm_exec(
            "INSERT INTO TM_ActivePresence
                 (user_id, page_type, task_id, project_id, last_ping)
             VALUES (:p1, :p2, :p3, :p4, CURRENT_TIMESTAMP)",
            [$userId, $pageType, $taskId, $projectId]
        );
    }
}

// ═══════════════════════════════════════════════════════════════
// SWITCH on action
// ═══════════════════════════════════════════════════════════════
switch ($action) {

    // ──────────────────────────────────────────────────────────
    // HEARTBEAT — client pings every 5 s to stay "online"
    // POST: page_type, task_id?, project_id?
    // ──────────────────────────────────────────────────────────
    case 'heartbeat': {
        $pageType  = $_POST['page_type']  ?? 'dashboard';
        $taskId    = isset($_POST['task_id'])    && (int)$_POST['task_id']    > 0
                     ? (int)$_POST['task_id']    : null;
        $projectId = isset($_POST['project_id']) && (int)$_POST['project_id'] > 0
                     ? (int)$_POST['project_id'] : null;

        $allowedPages = ['dashboard', 'tasks', 'task_detail', 'calendar', 'activity'];
        if (!in_array($pageType, $allowedPages, true)) $pageType = 'dashboard';

        tm_upsert_presence($uid, $pageType, $taskId, $projectId);

        echo json_encode(['ok' => true, 'ts' => date('c')]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // WHO IS ONLINE — returns users active in last 60 s
    // GET (no extra params needed)
    // ──────────────────────────────────────────────────────────
    case 'who_is_online': {
        $stmt = tm_exec(
            "SELECT ap.user_id, u.username, u.first_name, u.last_name,
                    ap.page_type, ap.task_id, ap.project_id,
                    TO_CHAR(ap.last_ping, 'HH24:MI:SS') AS last_ping_fmt
             FROM TM_ActivePresence ap
             JOIN TM_Users u ON u.user_id = ap.user_id
             WHERE ap.last_ping >= (CURRENT_TIMESTAMP - INTERVAL '60' SECOND)
               AND ap.user_id  <> :p1
             ORDER BY u.username ASC",
            [$uid]
        );
        $rows = tm_fetch_all($stmt);
        $users = array_map(function ($r) {
            return [
                'user_id'   => (int)$r['user_id'],
                'username'  => $r['username']  ?? '',
                'full_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'page_type' => $r['page_type'] ?? 'dashboard',
                'task_id'   => $r['task_id']   ? (int)$r['task_id'] : null,
                'last_ping' => $r['last_ping_fmt'] ?? '',
            ];
        }, $rows);

        echo json_encode(['ok' => true, 'data' => $users, 'ts' => date('c')]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // POLL CHANGES — return tasks changed since a timestamp
    //
    // GET: since  = ISO-8601 timestamp of last successful poll
    //      scope  = 'mine' (default) | 'shared' | 'project:<id>'
    //
    // Response includes:
    //   changes[]  — task rows that changed (full task payload)
    //   deletes[]  — task_ids that were deleted
    //   comments[] — new comments since `since` (for open task modals)
    //   online[]   — currently-active users (piggy-backed to save a round-trip)
    //   server_ts  — timestamp to use as `since` on next poll
    // ──────────────────────────────────────────────────────────
    case 'poll_changes': {
        $sinceRaw  = $_GET['since']  ?? $_POST['since']  ?? '';
        $scope     = $_GET['scope']  ?? $_POST['scope']  ?? 'mine';
        $taskIdCtx = isset($_GET['task_id']) ? (int)$_GET['task_id'] : null; // for comment polling

        // Parse & validate `since`; default to 30 s ago so first load isn't empty
        $sinceTs = null;
        if ($sinceRaw) {
            // Accept ISO-8601 or Oracle-style  'YYYY-MM-DD HH24:MI:SS'
            $parsed = strtotime($sinceRaw);
            if ($parsed !== false) {
                $sinceTs = date('Y-m-d H:i:s', $parsed);
            }
        }
        if (!$sinceTs) {
            $sinceTs = date('Y-m-d H:i:s', time() - 30);
        }

        // ── Build scope predicate ────────────────────────────────────────────
        // We query TM_TaskChangeLog for the list of changed task_ids,
        // then fetch full rows from TM_Tasks for those ids that the
        // current user is permitted to see.
        $scopeJoin  = '';
        $scopeWhere = "(t.user_id = :uid OR t.assigned_to = :uid2)";
        $scopeParams = [$uid, $uid];

        if (str_starts_with($scope, 'project:')) {
            $projectId = (int)substr($scope, 8);
            if ($projectId > 0) {
                // Verify caller is a member
                $isMember = tm_fetch_one(tm_exec(
                    "SELECT 1 AS ok FROM TM_ProjectMembers
                     WHERE project_id = :p1 AND user_id = :p2",
                    [$projectId, $uid]
                ));
                if ($isMember) {
                    $scopeWhere  = "t.project_id = :pid";
                    $scopeParams = [$projectId];
                }
            }
        }

        // ── 1. Changed task IDs since `since` ───────────────────────────────
        $clStmt = tm_exec(
            "SELECT DISTINCT cl.task_id, MAX(cl.change_type) AS change_type
             FROM TM_TaskChangeLog cl
             WHERE cl.changed_at > TO_TIMESTAMP(:p1, 'YYYY-MM-DD HH24:MI:SS')
             GROUP BY cl.task_id",
            [$sinceTs]
        );
        $changedIds = tm_fetch_all($clStmt);

        $updateIds = [];
        $deleteIds = [];
        foreach ($changedIds as $row) {
            $tid  = (int)$row['task_id'];
            $type = $row['change_type'] ?? 'update';
            if ($type === 'delete') {
                $deleteIds[] = $tid;
            } else {
                $updateIds[] = $tid;
            }
        }

        // ── 2. Fetch full task rows (access-controlled) ──────────────────────
        $changes = [];
        if (!empty($updateIds)) {
            // Build IN list with numbered placeholders
            $inPlaceholders = implode(',', array_map(
                fn($i) => ":tid{$i}",
                range(0, count($updateIds) - 1)
            ));

            // Build param array: scope params first, then task IDs
            $params = [];
            // Bind scope params as named won't mix easily with positional in OCI;
            // we embed uid directly for safety since it's typed int
            $scopeSql  = "(t.user_id = {$uid} OR t.assigned_to = {$uid})";

            if (str_starts_with($scope, 'project:')) {
                $pid = (int)substr($scope, 8);
                $scopeSql = "t.project_id = {$pid}";
            }

            foreach ($updateIds as $i => $tid) {
                $params[":tid{$i}"] = $tid;
            }

            // OCI named-bind approach
            global $conn;
            $sql = "SELECT t.task_id, t.task_name,
                           TO_CHAR(t.start_date,'YYYY-MM-DD') AS start_date,
                           TO_CHAR(t.due_date,'YYYY-MM-DD')   AS due_date,
                           t.category, t.custom_category, t.priority,
                           t.color, t.status, t.assigned_to,
                           TO_CHAR(t.updated_at,'YYYY-MM-DD\"T\"HH24:MI:SS') AS updated_at,
                           u.username AS assigned_username
                    FROM TM_Tasks t
                    LEFT JOIN TM_Users u ON u.user_id = t.assigned_to
                    WHERE {$scopeSql}
                      AND t.task_id IN ({$inPlaceholders})";

            $stmt = oci_parse($conn, $sql);
            foreach ($params as $key => $val) {
                oci_bind_by_name($stmt, $key, $params[$key], -1);
            }
            oci_execute($stmt, OCI_DEFAULT);

            while ($row = oci_fetch_assoc($stmt)) {
                // Normalise keys to lowercase
                $r = array_change_key_case($row, CASE_LOWER);
                $changes[] = [
                    'task_id'           => (int)$r['task_id'],
                    'task_name'         => $r['task_name']         ?? '',
                    'start_date'        => $r['start_date']        ?? '',
                    'due_date'          => $r['due_date']          ?? '',
                    'category'          => $r['category']          ?? '',
                    'custom_category'   => $r['custom_category']   ?? '',
                    'priority'          => $r['priority']          ?? 'mid',
                    'color'             => $r['color']             ?? '#ef4444',
                    'status'            => $r['status']            ?? 'pending',
                    'assigned_to'       => $r['assigned_to']       ? (int)$r['assigned_to'] : null,
                    'assigned_username' => $r['assigned_username'] ?? null,
                    'updated_at'        => $r['updated_at']        ?? '',
                ];
            }
            oci_free_statement($stmt);
        }

        // ── 3. New comments since `since` (only if caller has task context) ──
        $newComments = [];
        if ($taskIdCtx) {
            $cmtStmt = tm_exec(
                "SELECT c.comment_id, c.task_id, c.user_id,
                        u.username, u.first_name, u.last_name,
                        TO_CHAR(c.created_at,'Mon DD, YYYY HH24:MI') AS created_fmt,
                        TO_CHAR(c.created_at,'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_iso
                 FROM TM_Comments c
                 JOIN TM_Users u ON u.user_id = c.user_id
                 WHERE c.task_id = :p1
                   AND c.created_at > TO_TIMESTAMP(:p2,'YYYY-MM-DD HH24:MI:SS')
                 ORDER BY c.created_at ASC",
                [$taskIdCtx, $sinceTs]
            );
            foreach (tm_fetch_all($cmtStmt) as $r) {
                $content = $r['content'] ?? '';
                if ($content instanceof OCILob) $content = $content->load();
                elseif (is_resource($content))  $content = stream_get_contents($content);
                $newComments[] = [
                    'comment_id'  => (int)$r['comment_id'],
                    'task_id'     => (int)$r['task_id'],
                    'user_id'     => (int)$r['user_id'],
                    'username'    => $r['username']    ?? '',
                    'full_name'   => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    'content'     => (string)$content,
                    'created_fmt' => $r['created_fmt'] ?? '',
                    'created_iso' => $r['created_iso'] ?? '',
                ];
            }
        }

        // ── 4. Who is online (piggy-backed) ─────────────────────────────────
        $onlineStmt = tm_exec(
            "SELECT ap.user_id, u.username, u.first_name, u.last_name,
                    ap.page_type, ap.task_id
             FROM TM_ActivePresence ap
             JOIN TM_Users u ON u.user_id = ap.user_id
             WHERE ap.last_ping >= (CURRENT_TIMESTAMP - INTERVAL '60' SECOND)
               AND ap.user_id  <> :p1
             ORDER BY u.username ASC",
            [$uid]
        );
        $online = array_map(function ($r) {
            return [
                'user_id'   => (int)$r['user_id'],
                'username'  => $r['username']  ?? '',
                'full_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'page_type' => $r['page_type'] ?? 'dashboard',
                'task_id'   => $r['task_id']   ? (int)$r['task_id'] : null,
            ];
        }, tm_fetch_all($onlineStmt));

        echo json_encode([
            'ok'        => true,
            'changes'   => $changes,
            'deletes'   => $deleteIds,
            'comments'  => $newComments,
            'online'    => $online,
            'server_ts' => date('c'),          // ISO-8601; use as `since` next time
        ]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // LOG CHANGE — write a changelog entry from server-side code
    // POST: task_id, change_type ('create'|'update'|'delete')
    // Called by TM_TaskActions.php after any mutation.
    // ──────────────────────────────────────────────────────────
    case 'log_change': {
        $taskId     = (int)($_POST['task_id']     ?? 0);
        $changeType = $_POST['change_type'] ?? 'update';

        if ($taskId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'task_id required']); exit;
        }
        if (!in_array($changeType, ['create', 'update', 'delete'], true)) {
            $changeType = 'update';
        }

        tm_log_change($taskId, $uid, $changeType);

        echo json_encode(['ok' => true]);
        exit;
    }

    default:
        echo json_encode(['ok' => false, 'error' => "Unknown action: '{$action}'"]);
        exit;
}
