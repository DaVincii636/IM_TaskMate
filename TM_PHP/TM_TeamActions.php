<?php
// =============================================
// TM_TeamActions.php — Feature 8: Department CRUD
// Handles: create_team, edit_team, delete_team,
//          add_member, remove_member, set_manager
// Access: admin, org_admin, or org head only.
// =============================================
require_once 'TM_Session.php';
require_once 'TM_DB.php';

// Plain users are blocked; moderators, org_admins, org heads, and admins pass
tm_require_login();

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$uid      = tm_uid();
$oid      = tm_org_id();
$is_admin = tm_is_admin();
$is_org_admin = tm_is_org_admin(); // true for both admin and org_admin

// Check if current user is the org head for their own org
function tm_is_org_head(int $uid, int $oid): bool {
    $row = tm_fetch_one(tm_exec(
        'SELECT org_head_id FROM TM_Organizations WHERE org_id = :p1', [$oid]
    ));
    return $row && (int)($row['org_head_id'] ?? $row['ORG_HEAD_ID'] ?? 0) === $uid;
}
$is_org_head = tm_is_org_head($uid, $oid);

// Can manage departments = admin, org_admin, or org head
$can_manage = $is_org_admin || $is_org_head;

// ── Helpers ───────────────────────────────────────────────────────────────────
function team_org_check(int $teamId, int $orgId, bool $isAdmin): bool {
    // Returns true if the team belongs to the given org (or caller is system admin).
    if ($isAdmin) return true;
    $row = tm_fetch_one(tm_exec(
        'SELECT org_id FROM TM_Teams WHERE team_id = :p1', [$teamId]
    ));
    return $row && (int)$row['org_id'] === $orgId;
}

switch ($action) {

    // ── CREATE DEPARTMENT ─────────────────────────────────────────────────────
    case 'create_team':
        if (!$can_manage) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $name       = trim($_POST['team_name']   ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $deptHeadId = (int)($_POST['dept_head_id'] ?? 0);
        if (!$name) {
            tm_flash('error', 'Department name is required.'); break;
        }

        // Check uniqueness within org
        $chk = tm_exec(
            'SELECT COUNT(*) FROM TM_Teams WHERE org_id = :p1 AND UPPER(team_name) = UPPER(:p2)',
            [$oid, $name]
        );
        if ((int)tm_scalar($chk) > 0) {
            tm_flash('error', "A department named \"$name\" already exists in your organization."); break;
        }

        tm_exec(
            'INSERT INTO TM_Teams (org_id, team_name, team_desc, created_by, dept_head_id)
             VALUES (:p1, :p2, :p3, :p4, :p5)',
            [$oid, $name, $desc ?: null, $uid, $deptHeadId > 0 ? $deptHeadId : null]
        );

        // Get new team_id
        $newRow = tm_fetch_one(tm_exec(
            'SELECT TM_Teams_seq.CURRVAL AS new_id FROM DUAL'
        ));
        $newId = (int)($newRow['new_id'] ?? 0);

        // Auto-add creator as manager
        if ($newId > 0) {
            tm_exec(
                'INSERT INTO TM_TeamMembers (team_id, user_id, is_manager)
                 VALUES (:p1, :p2, 1)',
                [$newId, $uid]
            );
        }

        tm_audit($uid, 'create', 'user', $newId, $name, '', "dept_created:org_id:{$oid}");
        tm_flash('success', "Department \"$name\" created.");
        break;

    // ── EDIT DEPARTMENT ───────────────────────────────────────────────────────
    case 'edit_team':
        if (!$can_manage) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $teamId     = (int)($_POST['team_id']      ?? 0);
        $name       = trim($_POST['team_name']     ?? '');
        $desc       = trim($_POST['description']   ?? '');
        $deptHeadId = (int)($_POST['dept_head_id'] ?? 0);

        if ($teamId <= 0 || !$name) {
            tm_flash('error', 'Department name is required.'); break;
        }
        if (!team_org_check($teamId, $oid, $is_admin)) {
            tm_flash('error', 'Department not found.'); break;
        }

        tm_exec(
            'UPDATE TM_Teams SET team_name = :p1, team_desc = :p2, dept_head_id = :p3 WHERE team_id = :p4',
            [$name, $desc ?: null, $deptHeadId > 0 ? $deptHeadId : null, $teamId]
        );
        tm_audit($uid, 'edit', 'user', $teamId, $name, '', 'dept_updated');
        tm_flash('success', "Department \"$name\" updated.");
        break;

    // ── DELETE DEPARTMENT ─────────────────────────────────────────────────────
    case 'delete_team':
        if (!$can_manage) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $teamId = (int)($_POST['team_id'] ?? 0);
        if ($teamId <= 0 || !team_org_check($teamId, $oid, $is_admin)) {
            tm_flash('error', 'Department not found.'); break;
        }

        $row = tm_fetch_one(tm_exec(
            'SELECT team_name FROM TM_Teams WHERE team_id = :p1', [$teamId]
        ));
        $tname = $row['team_name'] ?? "dept #{$teamId}";

        // CASCADE on TM_TeamMembers handles member rows automatically.
        tm_exec('DELETE FROM TM_Teams WHERE team_id = :p1', [$teamId]);
        tm_audit($uid, 'delete', 'user', $teamId, $tname, '', 'dept_deleted');
        tm_flash('success', "Department \"$tname\" deleted.");
        break;

    // ── ADD MEMBER ────────────────────────────────────────────────────────────
    case 'add_member':
        if (!$can_manage) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $teamId    = (int)($_POST['team_id']    ?? 0);
        $memberId  = (int)($_POST['member_user_id'] ?? 0);
        $isManager = (int)($_POST['is_manager'] ?? 0);

        if ($teamId <= 0 || $memberId <= 0) {
            tm_flash('error', 'Invalid team or user.'); break;
        }
        if (!team_org_check($teamId, $oid, $is_admin)) {
            tm_flash('error', 'Team not found.'); break;
        }

        // Verify the target user is in the same org (org_admins cannot add cross-org users)
        if (!$is_admin) {
            $uRow = tm_fetch_one(tm_exec(
                'SELECT org_id FROM TM_Users WHERE user_id = :p1', [$memberId]
            ));
            if (!$uRow || (int)$uRow['org_id'] !== $oid) {
                tm_flash('error', 'User does not belong to your organization.'); break;
            }
        }

        // Check for existing membership
        $chk = tm_exec(
            'SELECT COUNT(*) FROM TM_TeamMembers WHERE team_id = :p1 AND user_id = :p2',
            [$teamId, $memberId]
        );
        if ((int)tm_scalar($chk) > 0) {
            tm_flash('error', 'User is already a member of this department.'); break;
        }

        tm_exec(
            'INSERT INTO TM_TeamMembers (team_id, user_id, is_manager)
             VALUES (:p1, :p2, :p3)',
            [$teamId, $memberId, $isManager ? 1 : 0]
        );

        $uRow  = tm_fetch_one(tm_exec('SELECT first_name, last_name FROM TM_Users WHERE user_id = :p1', [$memberId]));
        $tRow  = tm_fetch_one(tm_exec('SELECT team_name FROM TM_Teams WHERE team_id = :p1', [$teamId]));
        $uname = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
        $tname = $tRow['team_name'] ?? '';

        // Feature 8: notify the added user
        tm_exec(
            "INSERT INTO TM_Notifications (user_id, task_id, type, message, is_read)
             VALUES (:p1, NULL, 'team_added', :p2, 0)",
            [
                $memberId,
                "You've been added to the department \"" . $tname . "\"" . ($isManager ? ' as a manager' : '') . '.',
            ]
        );

        // If the added user is the current session user, refresh session teams immediately
        if ($memberId === $uid) {
            $sRows = tm_fetch_all(tm_exec(
                "SELECT t.team_id, t.team_name, tm.is_manager
                 FROM TM_Teams t JOIN TM_TeamMembers tm ON tm.team_id = t.team_id
                 WHERE tm.user_id = :p1", [$uid]
            ));
            $_SESSION['tm_teams'] = array_map(fn($r) => [
                'team_id'    => (int)($r['TEAM_ID']    ?? $r['team_id']    ?? 0),
                'team_name'  => $r['TEAM_NAME']  ?? $r['team_name']  ?? '',
                'is_manager' => (int)($r['IS_MANAGER'] ?? $r['is_manager'] ?? 0),
            ], $sRows);
        }

        tm_audit($uid, 'edit', 'user', $memberId, $uname, '', "added_to_dept:{$tname}");
        tm_flash('success', "$uname added to department.");
        break;

    // ── REMOVE MEMBER ─────────────────────────────────────────────────────────
    case 'remove_member':
        if (!$can_manage) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $teamId   = (int)($_POST['team_id']   ?? 0);
        $memberId = (int)($_POST['user_id']   ?? 0);

        if ($teamId <= 0 || $memberId <= 0) {
            tm_flash('error', 'Invalid team or user.'); break;
        }
        if (!team_org_check($teamId, $oid, $is_admin)) {
            tm_flash('error', 'Team not found.'); break;
        }

        $uRow  = tm_fetch_one(tm_exec('SELECT first_name, last_name FROM TM_Users WHERE user_id = :p1', [$memberId]));
        $tRow  = tm_fetch_one(tm_exec('SELECT team_name FROM TM_Teams WHERE team_id = :p1', [$teamId]));
        $uname = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
        $tname = $tRow['team_name'] ?? '';

        tm_exec(
            'DELETE FROM TM_TeamMembers WHERE team_id = :p1 AND user_id = :p2',
            [$teamId, $memberId]
        );

        // Feature 8: notify the removed user (only if they're not removing themselves)
        if ($memberId !== $uid) {
            tm_exec(
                "INSERT INTO TM_Notifications (user_id, task_id, type, message, is_read)
                 VALUES (:p1, NULL, 'team_removed', :p2, 0)",
                [
                    $memberId,
                    "You've been removed from the department \"" . $tname . '".',
                ]
            );
        }

        // If removed user is the current session user, refresh session teams immediately
        if ($memberId === $uid) {
            $sRows = tm_fetch_all(tm_exec(
                "SELECT t.team_id, t.team_name, tm.is_manager
                 FROM TM_Teams t JOIN TM_TeamMembers tm ON tm.team_id = t.team_id
                 WHERE tm.user_id = :p1", [$uid]
            ));
            $_SESSION['tm_teams'] = array_map(fn($r) => [
                'team_id'    => (int)($r['TEAM_ID']    ?? $r['team_id']    ?? 0),
                'team_name'  => $r['TEAM_NAME']  ?? $r['team_name']  ?? '',
                'is_manager' => (int)($r['IS_MANAGER'] ?? $r['is_manager'] ?? 0),
            ], $sRows);
        }

        tm_audit($uid, 'edit', 'user', $memberId, $uname, "team:{$tname}", 'removed_from_team');
        tm_flash('success', "$uname removed from department.");
        break;

    // ── TOGGLE MANAGER FLAG ───────────────────────────────────────────────────
    case 'set_manager':
        if (!$can_manage) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $teamId    = (int)($_POST['team_id']    ?? 0);
        $memberId  = (int)($_POST['user_id']    ?? 0);
        $isManager = (int)($_POST['is_manager'] ?? 0);

        if ($teamId <= 0 || $memberId <= 0) {
            tm_flash('error', 'Invalid team or user.'); break;
        }

        tm_exec(
            'UPDATE TM_TeamMembers SET is_manager = :p1
             WHERE team_id = :p2 AND user_id = :p3',
            [$isManager ? 1 : 0, $teamId, $memberId]
        );
        tm_flash('success', $isManager ? 'User promoted to department manager.' : 'Manager flag removed.');
        break;

    default:
        tm_flash('error', "Unknown action: '{$action}'.");
        break;
}

header('Location: ../TM_UserList.php#departments'); exit;