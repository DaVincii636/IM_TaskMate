<?php
// ============================================================
// TM_PHP/TM_OrgActions.php
// FEATURE 6 — Organization / Tenant Management (Admin Panel)
//
// All organization CRUD actions triggered from TM_UserList.php.
// Only system admins (role = 'admin') may call these endpoints.
//
// Actions (POST):
//   create_org    — create a new organization
//   edit_org      — rename an org
//   delete_org    — delete org (only if empty of users)
//   transfer_user — move a user to a different org
//   set_org_admin — promote / demote a user to org_admin
// ============================================================
require_once 'TM_Session.php';
require_once 'TM_DB.php';

// Only system admins may manage orgs
tm_require_role('admin');

$action = $_POST['action'] ?? '';
$uid    = tm_uid();

switch ($action) {

    // ── Create a new organization ────────────────────────────────────────────
    case 'create_org':
        $name = trim($_POST['org_name'] ?? '');
        if (!$name) {
            tm_flash('error', 'Organization name is required.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        // Check uniqueness
        $chk = tm_exec(
            'SELECT COUNT(*) FROM TM_Organizations WHERE LOWER(org_name) = LOWER(:p1)',
            [$name]
        );
        if ((int)tm_scalar($chk) > 0) {
            tm_flash('error', "An organization named \"{$name}\" already exists.");
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        tm_exec(
            'INSERT INTO TM_Organizations (org_name) VALUES (:p1)',
            [$name]
        );

        $newRow = tm_fetch_one(tm_exec(
            'SELECT org_id FROM TM_Organizations WHERE LOWER(org_name) = LOWER(:p1)',
            [$name]
        ));
        $newOrgId = (int)($newRow['org_id'] ?? $newRow['ORG_ID'] ?? 0);

        tm_audit($uid, 'create', 'user', $newOrgId, $name, '', '');
        tm_flash('success', "Organization \"{$name}\" created.");
        header('Location: ../TM_UserList.php#orgs'); exit;

    // ── Edit an existing organization ────────────────────────────────────────
    case 'edit_org':
        $orgId = (int)($_POST['org_id'] ?? 0);
        $name  = trim($_POST['org_name'] ?? '');
        if ($orgId <= 0 || !$name) {
            tm_flash('error', 'Invalid organization data.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        // Check uniqueness (allow same name for same org)
        $chk = tm_exec(
            'SELECT COUNT(*) FROM TM_Organizations
             WHERE LOWER(org_name) = LOWER(:p1) AND org_id != :p2',
            [$name, $orgId]
        );
        if ((int)tm_scalar($chk) > 0) {
            tm_flash('error', "Another organization named \"{$name}\" already exists.");
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $oldRow = tm_fetch_one(tm_exec(
            'SELECT org_name FROM TM_Organizations WHERE org_id = :p1', [$orgId]
        ));
        if (!$oldRow) {
            tm_flash('error', 'Organization not found.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        tm_exec(
            'UPDATE TM_Organizations SET org_name = :p1 WHERE org_id = :p2',
            [$name, $orgId]
        );

        $oldName = $oldRow['org_name'] ?? $oldRow['ORG_NAME'] ?? '';
        tm_audit($uid, 'edit', 'user', $orgId, $name,
                 "name:{$oldName}",
                 "name:{$name}");
        tm_flash('success', "Organization updated to \"{$name}\".");
        header('Location: ../TM_UserList.php#orgs'); exit;

    // ── Delete an organization ───────────────────────────────────────────────
    // Only allowed when no users remain in the org.
    // The Default Organization (org_id = 1) is protected.
    case 'delete_org':
        $orgId = (int)($_POST['org_id'] ?? 0);
        if ($orgId <= 0) {
            tm_flash('error', 'Invalid organization.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }
        if ($orgId === 1) {
            tm_flash('error', 'The Default Organization cannot be deleted.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $orgRow = tm_fetch_one(tm_exec(
            'SELECT org_name FROM TM_Organizations WHERE org_id = :p1', [$orgId]
        ));
        if (!$orgRow) {
            tm_flash('error', 'Organization not found.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        // Block delete if users still belong to this org
        $memberCount = tm_scalar(tm_exec(
            'SELECT COUNT(*) FROM TM_Users WHERE org_id = :p1', [$orgId]
        ));
        if ((int)$memberCount > 0) {
            tm_flash('error',
                "Cannot delete: {$memberCount} user(s) still belong to this organization. "
                . "Transfer them first.");
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $orgName = $orgRow['org_name'] ?? $orgRow['ORG_NAME'] ?? "org #{$orgId}";
        tm_exec('DELETE FROM TM_Organizations WHERE org_id = :p1', [$orgId]);
        tm_audit($uid, 'delete', 'user', $orgId, $orgName, "org:{$orgName}", '');
        tm_flash('success', "Organization \"{$orgName}\" deleted.");
        header('Location: ../TM_UserList.php#orgs'); exit;

    // ── Transfer a user to a different org ───────────────────────────────────
    // Also re-scopes all their tasks to the new org.
    case 'transfer_user':
        $targetUid = (int)($_POST['user_id']    ?? 0);
        $newOrgId  = (int)($_POST['new_org_id'] ?? 0);

        if ($targetUid <= 0 || $newOrgId <= 0) {
            tm_flash('error', 'Invalid transfer data.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $userRow = tm_fetch_one(tm_exec(
            'SELECT first_name, last_name, org_id FROM TM_Users WHERE user_id = :p1',
            [$targetUid]
        ));
        if (!$userRow) {
            tm_flash('error', 'User not found.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $orgRow = tm_fetch_one(tm_exec(
            'SELECT org_name FROM TM_Organizations WHERE org_id = :p1', [$newOrgId]
        ));
        if (!$orgRow) {
            tm_flash('error', 'Target organization not found.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $oldOrgId = (int)($userRow['org_id'] ?? $userRow['ORG_ID'] ?? 1);
        if ($oldOrgId === $newOrgId) {
            tm_flash('error', 'User is already in that organization.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $userName  = trim(
            ($userRow['first_name'] ?? $userRow['FIRST_NAME'] ?? '') . ' ' .
            ($userRow['last_name']  ?? $userRow['LAST_NAME']  ?? '')
        );
        $newOrgName = $orgRow['org_name'] ?? $orgRow['ORG_NAME'] ?? "org #{$newOrgId}";

        // Move user and all their tasks atomically
        tm_exec('UPDATE TM_Users SET org_id = :p1 WHERE user_id = :p2',
                [$newOrgId, $targetUid]);
        tm_exec('UPDATE TM_Tasks SET org_id = :p1 WHERE user_id = :p2',
                [$newOrgId, $targetUid]);

        tm_audit($uid, 'edit', 'user', $targetUid, $userName,
                 "org_id:{$oldOrgId}", "org_id:{$newOrgId}");
        tm_flash('success', "\"{$userName}\" transferred to \"{$newOrgName}\".");
        header('Location: ../TM_UserList.php#orgs'); exit;

    // ── Promote / demote a user to org_admin ─────────────────────────────────
    case 'set_org_admin':
        $targetUid = (int)($_POST['user_id']   ?? 0);
        $newRole   = trim($_POST['new_role']   ?? 'user');
        if (!in_array($newRole, ['user', 'moderator', 'org_admin', 'admin'])) $newRole = 'user';

        if ($targetUid <= 0) {
            tm_flash('error', 'Invalid user.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $userRow = tm_fetch_one(tm_exec(
            'SELECT first_name, last_name, role FROM TM_Users WHERE user_id = :p1',
            [$targetUid]
        ));
        if (!$userRow) {
            tm_flash('error', 'User not found.');
            header('Location: ../TM_UserList.php#orgs'); exit;
        }

        $oldRole  = $userRow['role'] ?? $userRow['ROLE'] ?? 'user';
        $userName = trim(
            ($userRow['first_name'] ?? $userRow['FIRST_NAME'] ?? '') . ' ' .
            ($userRow['last_name']  ?? $userRow['LAST_NAME']  ?? '')
        );

        tm_exec('UPDATE TM_Users SET role = :p1 WHERE user_id = :p2',
                [$newRole, $targetUid]);
        tm_audit($uid, 'edit', 'user', $targetUid, $userName,
                 "role:{$oldRole}", "role:{$newRole}");
        tm_flash('success', "\"{$userName}\" role updated to " . strtoupper($newRole) . '.');
        header('Location: ../TM_UserList.php#orgs'); exit;

    default:
        tm_flash('error', "Unknown org action: '{$action}'.");
        header('Location: ../TM_UserList.php#orgs'); exit;
}
