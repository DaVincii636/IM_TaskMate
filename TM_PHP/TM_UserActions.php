<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

// Moderators can VIEW the user list but cannot make changes
tm_require_role('moderator');

$action   = $_POST['action'] ?? '';
$is_admin = tm_is_admin(); // Only admins can write
$uid      = tm_uid();      // The admin/moderator performing the action

// ── Audit helper (mirrors the one in TM_TaskActions.php) ──────────────────────
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
        if (!$is_admin) { tm_flash('error', 'Insufficient permissions.'); break; }
        $fn   = trim($_POST['firstName'] ?? '');
        $ln   = trim($_POST['lastName']  ?? '');
        $em   = trim($_POST['email']     ?? '');
        $ph   = trim($_POST['phone']     ?? '');
        $pw   = $_POST['password']       ?? '';
        $role = in_array($_POST['role'] ?? '', ['user','moderator','admin'])
                ? $_POST['role'] : 'user';

        if (!$fn || !$ln || !$em || !$ph || !$pw) {
            tm_flash('error', 'All fields are required.'); break;
        }
        if (strlen($pw) < 6) {
            tm_flash('error', 'Password must be at least 6 characters.'); break;
        }
        $chk = tm_exec('SELECT COUNT(*) FROM TM_Users WHERE email = :p1', [$em]);
        if ((int)tm_scalar($chk) > 0) {
            tm_flash('error', 'Email already exists.'); break;
        }
        $un = strtolower(explode('@', $em)[0]) . '_' . rand(100, 999);
        tm_exec(
            'INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone, role)
             VALUES (:p1, :p2, :p3, :p4, :p5, :p6, :p7)',
            [$un, $em, password_hash($pw, PASSWORD_BCRYPT), $fn, $ln, $ph, $role]
        );
        $newIdRow = tm_fetch_one(tm_exec(
            "SELECT TM_Users_seq.CURRVAL AS new_id FROM DUAL"
        ));
        $newId = (int)($newIdRow['NEW_ID'] ?? $newIdRow['new_id'] ?? 0);
        tm_audit($uid, 'create', 'user', $newId, "$fn $ln",
                 '', "role:{$role}, email:{$em}");
        tm_flash('success', "User '$fn $ln' added.");
        break;

    case 'edit':
        if (!$is_admin) { tm_flash('error', 'Insufficient permissions.'); break; }
        $id   = (int)($_POST['id'] ?? 0);
        $fn   = trim($_POST['firstName'] ?? '');
        $ln   = trim($_POST['lastName']  ?? '');
        $ph   = trim($_POST['phone']     ?? '');
        $pw   = $_POST['password']       ?? '';
        $role = in_array($_POST['role'] ?? '', ['user','moderator','admin'])
                ? $_POST['role'] : 'user';

        if ($id <= 0 || !$fn || !$ln || !$ph) {
            tm_flash('error', 'Required fields missing.'); break;
        }
        if ($pw && strlen($pw) < 6) {
            tm_flash('error', 'New password must be at least 6 characters.'); break;
        }

        // Snapshot old role before overwriting
        $oldRow  = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_Users WHERE user_id=:p1", [$id]
        ));
        $oldRole = $oldRow['ROLE'] ?? $oldRow['role'] ?? 'user';

        if ($pw) {
            tm_exec(
                'UPDATE TM_Users SET first_name=:p1, last_name=:p2, phone=:p3, password_hash=:p4, role=:p5 WHERE user_id=:p6',
                [$fn, $ln, $ph, password_hash($pw, PASSWORD_BCRYPT), $role, $id]
            );
        } else {
            tm_exec(
                'UPDATE TM_Users SET first_name=:p1, last_name=:p2, phone=:p3, role=:p4 WHERE user_id=:p5',
                [$fn, $ln, $ph, $role, $id]
            );
        }
        tm_audit($uid, 'edit', 'user', $id, "$fn $ln",
                 "role:{$oldRole}", "role:{$role}");
        tm_flash('success', "User '$fn $ln' updated.");
        break;

    case 'delete':
        if (!$is_admin) { tm_flash('error', 'Insufficient permissions.'); break; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid user.'); break; }

        $delRow  = tm_fetch_one(tm_exec(
            "SELECT first_name, last_name, role FROM TM_Users WHERE user_id=:p1", [$id]
        ));
        $delName = trim(($delRow['FIRST_NAME'] ?? $delRow['first_name'] ?? '') . ' ' .
                        ($delRow['LAST_NAME']  ?? $delRow['last_name']  ?? '')) ?: "user #{$id}";
        $delRole = $delRow['ROLE'] ?? $delRow['role'] ?? 'user';

        tm_exec('DELETE FROM TM_Users WHERE user_id = :p1', [$id]);
        tm_audit($uid, 'delete', 'user', $id, $delName,
                 "role:{$delRole}", '');
        tm_flash('success', 'User deleted.');
        break;
}

header('Location: ../TM_UserList.php'); exit;