<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

// Moderators can VIEW the user list but cannot make changes
tm_require_role('moderator');

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$is_admin = tm_is_admin();
$uid      = tm_uid();

// ── JSON API detection ────────────────────────────────────────────────────────
// Mirrors the detection in TM_TaskActions.php.
// tm_api_ok() / tm_api_err() are defined in TM_DB.php.
$isApi = (($_GET['format'] ?? '') === 'json')
      || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

// ── Read endpoint: GET ?action=list&format=json ───────────────────────────────
// Admin/moderator only. Returns the full user list as a JSON array.
if ($action === 'list') {
    if (!$isApi) tm_api_err('This endpoint requires ?format=json or Accept: application/json', 406);
    $stmt = tm_exec(
        'SELECT user_id, username, email, first_name, last_name, phone, role
         FROM TM_Users ORDER BY user_id ASC'
    );
    $rows = tm_fetch_all($stmt);
    $users = array_map(function ($row) {
        $row['user_id'] = (int)$row['user_id'];
        unset($row['password_hash']); // never expose password hashes via API
        return $row;
    }, $rows);
    tm_api_ok($users);
}

switch ($action) {

    case 'add':
        if (!$is_admin) {
            if ($isApi) tm_api_err('Insufficient permissions.', 403);
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $fn   = trim($_POST['firstName'] ?? '');
        $ln   = trim($_POST['lastName']  ?? '');
        $em   = trim($_POST['email']     ?? '');
        $ph   = trim($_POST['phone']     ?? '');
        $pw   = $_POST['password']       ?? '';
        $role = in_array($_POST['role'] ?? '', ['user','moderator','admin'])
                ? $_POST['role'] : 'user';

        if (!$fn || !$ln || !$em || !$ph || !$pw) {
            if ($isApi) tm_api_err('All fields are required.');
            tm_flash('error', 'All fields are required.'); break;
        }
        if (strlen($pw) < 6) {
            if ($isApi) tm_api_err('Password must be at least 6 characters.');
            tm_flash('error', 'Password must be at least 6 characters.'); break;
        }
        $chk = tm_exec('SELECT COUNT(*) FROM TM_Users WHERE email = :p1', [$em]);
        if ((int)tm_scalar($chk) > 0) {
            if ($isApi) tm_api_err('Email already exists.', 409);
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
        $newId = (int)($newIdRow['new_id'] ?? 0);
        tm_audit($uid, 'create', 'user', $newId, "$fn $ln",
                 '', "role:{$role}, email:{$em}");
        if ($isApi) tm_api_ok(['user_id' => $newId, 'username' => $un, 'role' => $role]);
        tm_flash('success', "User '$fn $ln' added.");
        break;

    case 'edit':
        if (!$is_admin) {
            if ($isApi) tm_api_err('Insufficient permissions.', 403);
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $id   = (int)($_POST['id'] ?? 0);
        $fn   = trim($_POST['firstName'] ?? '');
        $ln   = trim($_POST['lastName']  ?? '');
        $ph   = trim($_POST['phone']     ?? '');
        $pw   = $_POST['password']       ?? '';
        $role = in_array($_POST['role'] ?? '', ['user','moderator','admin'])
                ? $_POST['role'] : 'user';

        if ($id <= 0 || !$fn || !$ln || !$ph) {
            if ($isApi) tm_api_err('Required fields missing.');
            tm_flash('error', 'Required fields missing.'); break;
        }
        if ($pw && strlen($pw) < 6) {
            if ($isApi) tm_api_err('New password must be at least 6 characters.');
            tm_flash('error', 'New password must be at least 6 characters.'); break;
        }

        $oldRow  = tm_fetch_one(tm_exec(
            "SELECT role FROM TM_Users WHERE user_id=:p1", [$id]
        ));
        if (!$oldRow) {
            if ($isApi) tm_api_err('User not found.', 404);
            tm_flash('error', 'User not found.'); break;
        }
        $oldRole = $oldRow['role'] ?? 'user';

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
        if ($isApi) tm_api_ok(['user_id' => $id, 'role' => $role]);
        tm_flash('success', "User '$fn $ln' updated.");
        break;

    case 'delete':
        if (!$is_admin) {
            if ($isApi) tm_api_err('Insufficient permissions.', 403);
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($isApi) tm_api_err('Invalid user.');
            tm_flash('error', 'Invalid user.'); break;
        }

        $delRow  = tm_fetch_one(tm_exec(
            "SELECT first_name, last_name, role FROM TM_Users WHERE user_id=:p1", [$id]
        ));
        if (!$delRow) {
            if ($isApi) tm_api_err('User not found.', 404);
            tm_flash('error', 'User not found.'); break;
        }
        $delName = trim(($delRow['first_name'] ?? '') . ' ' . ($delRow['last_name'] ?? ''))
                   ?: "user #{$id}";
        $delRole = $delRow['role'] ?? 'user';

        tm_exec('DELETE FROM TM_Users WHERE user_id = :p1', [$id]);
        tm_audit($uid, 'delete', 'user', $id, $delName, "role:{$delRole}", '');
        if ($isApi) tm_api_ok(['user_id' => $id, 'deleted' => true]);
        tm_flash('success', 'User deleted.');
        break;

    // ── FEATURE 7: Approve a pending user ────────────────────────────────────
    case 'approve':
        if (!$is_admin) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid user.'); break; }

        $row = tm_fetch_one(tm_exec(
            "SELECT first_name, last_name, status FROM TM_Users WHERE user_id=:p1", [$id]
        ));
        if (!$row) { tm_flash('error', 'User not found.'); break; }

        tm_exec("UPDATE TM_Users SET status='active' WHERE user_id=:p1", [$id]);
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        tm_audit($uid, 'edit', 'user', $id, $name, 'status:pending', 'status:active');
        tm_flash('success', "User '$name' approved and activated.");
        break;

    // ── FEATURE 7: Suspend a user ─────────────────────────────────────────────
    case 'suspend':
        if (!$is_admin) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid user.'); break; }

        $row = tm_fetch_one(tm_exec(
            "SELECT first_name, last_name, status FROM TM_Users WHERE user_id=:p1", [$id]
        ));
        if (!$row) { tm_flash('error', 'User not found.'); break; }

        $oldStatus = $row['status'] ?? 'active';
        tm_exec("UPDATE TM_Users SET status='suspended' WHERE user_id=:p1", [$id]);
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        tm_audit($uid, 'edit', 'user', $id, $name, "status:{$oldStatus}", 'status:suspended');
        tm_flash('success', "User '$name' suspended.");
        break;

    // ── FEATURE 9: Bulk CSV Import ────────────────────────────────────────────
    // Expected CSV columns (header row required):
    //   first_name, last_name, email, phone, password, role
    // Role defaults to 'user' if omitted or invalid.
    // All imported accounts are set to 'active' (admin-initiated, no approval needed).
    case 'csv_import':
        if (!$is_admin) {
            tm_flash('error', 'Insufficient permissions.'); break;
        }
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            tm_flash('error', 'CSV file upload failed. Please try again.'); break;
        }

        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) { tm_flash('error', 'Could not open uploaded file.'); break; }

        // Read header row and normalise column names
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); tm_flash('error', 'CSV file is empty.'); break; }
        $header = array_map('trim', array_map('strtolower', $header));

        $required = ['first_name', 'last_name', 'email', 'phone', 'password'];
        $missing  = array_diff($required, $header);
        if ($missing) {
            fclose($fh);
            tm_flash('error', 'CSV is missing required columns: ' . implode(', ', $missing));
            break;
        }

        $colIdx = array_flip($header); // column name → index

        $success = 0;
        $skipped = [];
        $rowNum  = 1; // 1 = header already consumed

        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            // Map by column name to handle any column order
            $fn   = trim($row[$colIdx['first_name']] ?? '');
            $ln   = trim($row[$colIdx['last_name']]  ?? '');
            $em   = trim($row[$colIdx['email']]      ?? '');
            $ph   = trim($row[$colIdx['phone']]      ?? '');
            $pw   = $row[$colIdx['password']]        ?? '';
            $role = trim($row[$colIdx['role'] ?? -1] ?? 'user');

            if (!in_array($role, ['user', 'moderator', 'admin'])) $role = 'user';

            // Validate required fields
            if (!$fn || !$ln || !$em || !$ph || !$pw) {
                $skipped[] = "Row {$rowNum}: missing required field(s)";
                continue;
            }
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = "Row {$rowNum}: invalid email '{$em}'";
                continue;
            }
            if (strlen($pw) < 6) {
                $skipped[] = "Row {$rowNum}: password too short for '{$em}'";
                continue;
            }

            // Check for duplicate email
            $chk = tm_exec('SELECT COUNT(*) FROM TM_Users WHERE email=:p1', [$em]);
            if ((int)tm_scalar($chk) > 0) {
                $skipped[] = "Row {$rowNum}: email '{$em}' already exists";
                continue;
            }

            $un   = strtolower(explode('@', $em)[0]) . '_' . rand(100, 999);
            $hash = password_hash($pw, PASSWORD_BCRYPT);

            tm_exec(
                'INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone, role, status)
                 VALUES (:p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8)',
                [$un, $em, $hash, $fn, $ln, $ph, $role, 'active']
            );
            $success++;
        }
        fclose($fh);

        // Store import summary in session for display on Admin Panel
        $_SESSION['tm_csv_import_summary'] = [
            'success' => $success,
            'skipped' => $skipped,
        ];

        $msg = "{$success} user(s) imported successfully.";
        if ($skipped) $msg .= ' ' . count($skipped) . ' row(s) skipped (see import summary).';
        tm_flash('success', $msg);
        break;


    case 'update_self':
        // ── Self-service profile update (Feature 8) ───────────────────────────
        // Any logged-in user can update their own name, phone, and password.
        // No role check needed — tm_require_login() at top already enforces login.
        $fn  = trim($_POST['firstName'] ?? '');
        $ln  = trim($_POST['lastName']  ?? '');
        $ph  = trim($_POST['phone']     ?? '');
        $pw  = $_POST['newPassword']    ?? '';
        $cur = $_POST['currentPassword'] ?? '';

        if (!$fn || !$ln || !$ph) {
            if ($isApi) tm_api_err('Name and phone are required.');
            tm_flash('error', 'Name and phone are required.'); break;
        }
        if ($pw && strlen($pw) < 6) {
            if ($isApi) tm_api_err('New password must be at least 6 characters.');
            tm_flash('error', 'New password must be at least 6 characters.'); break;
        }

        // If user wants to change password, verify current password first
        if ($pw) {
            $hashRow = tm_fetch_one(tm_exec(
                'SELECT password_hash FROM TM_Users WHERE user_id=:p1', [$uid]
            ));
            if (!$hashRow || !password_verify($cur, $hashRow['password_hash'])) {
                if ($isApi) tm_api_err('Current password is incorrect.', 403);
                tm_flash('error', 'Current password is incorrect.'); break;
            }
            tm_exec(
                'UPDATE TM_Users SET first_name=:p1, last_name=:p2, phone=:p3, password_hash=:p4, updated_at=CURRENT_TIMESTAMP WHERE user_id=:p5',
                [$fn, $ln, $ph, password_hash($pw, PASSWORD_BCRYPT), $uid]
            );
        } else {
            tm_exec(
                'UPDATE TM_Users SET first_name=:p1, last_name=:p2, phone=:p3, updated_at=CURRENT_TIMESTAMP WHERE user_id=:p4',
                [$fn, $ln, $ph, $uid]
            );
        }

        // Keep session name in sync
        $_SESSION['tm_first_name'] = $fn;

        tm_audit($uid, 'edit', 'user', $uid, "$fn $ln", '', 'self-update');
        if ($isApi) tm_api_ok(['user_id' => $uid]);
        tm_flash('success', 'Profile updated successfully!');
        header('Location: ../TM_Profile.php'); exit;

    default:
        if ($isApi) tm_api_err("Unknown action: '{$action}'", 400);
        break;
}

header('Location: ../TM_UserList.php'); exit;