<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---- LOGOUT ----
if ($action === 'logout') {
    session_destroy();
    header('Location: ../TM_Login.php');
    exit;
}

// ---- LOGIN ----
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pw    = $_POST['password'] ?? '';

    if (!$email || !$pw) {
        tm_flash('error', 'Email and password are required.');
        header('Location: ../TM_Login.php'); exit;
    }

    // Feature 6: also fetch org_id and org_name so we can store them in session
    $stmt = tm_exec(
        'SELECT u.user_id, u.first_name, u.last_name, u.email,
                u.password_hash, u.role, u.status,
                u.org_id, o.org_name
         FROM TM_Users u
         JOIN TM_Organizations o ON o.org_id = u.org_id
         WHERE u.email = :p1',
        [$email]
    );
    $user = tm_fetch_one($stmt);

    if (!$user || !password_verify($pw, $user['password_hash'])) {
        tm_flash('error', 'Invalid email or password.');
        header('Location: ../TM_Login.php'); exit;
    }

    // Feature 7: Block login for non-active accounts
    $status = $user['status'] ?? 'active';
    if ($status === 'pending') {
        tm_flash('error', 'Your account is pending admin approval. Please check back later.');
        header('Location: ../TM_Login.php'); exit;
    }
    if ($status === 'suspended') {
        tm_flash('error', 'Your account has been suspended. Please contact an administrator.');
        header('Location: ../TM_Login.php'); exit;
    }

    $_SESSION['tm_user_id']    = $user['user_id'];
    $_SESSION['tm_first_name'] = $user['first_name'];
    $_SESSION['tm_last_name']  = $user['last_name'];
    $_SESSION['tm_email']      = $user['email'];
    $_SESSION['tm_role']       = $user['role'] ?? 'user';

    // Feature 6: Store org context in session — used by tm_org_id() everywhere
    $_SESSION['tm_org_id']   = (int)($user['org_id']   ?? 1);
    $_SESSION['tm_org_name'] = $user['org_name'] ?? 'Default Organization';

    header('Location: ../TM_Dashboard.php'); exit;
}

// ---- REGISTER ----
if ($action === 'register') {
    $fn  = trim($_POST['firstName']      ?? '');
    $ln  = trim($_POST['lastName']       ?? '');
    $em  = trim($_POST['email']          ?? '');
    $ph  = trim($_POST['phone']          ?? '');
    $pw  = $_POST['password']            ?? '';
    $cpw = $_POST['confirmPassword']     ?? '';

    // Feature 6: allow registrants to supply an org invite code or org name.
    // If omitted, they land in org_id = 1 (Default Organization) and an admin
    // can reassign them later via TM_UserList.php.
    $orgCode = trim($_POST['org_code'] ?? '');

    if (!$fn || !$ln || !$em || !$ph || !$pw) {
        tm_flash('error', 'All fields are required.');
        header('Location: ../TM_Register.php'); exit;
    }
    if (strlen($pw) < 6) {
        tm_flash('error', 'Password must be at least 6 characters.');
        header('Location: ../TM_Register.php'); exit;
    }
    if ($pw !== $cpw) {
        tm_flash('error', 'Passwords do not match.');
        header('Location: ../TM_Register.php'); exit;
    }
    if (!preg_match('/^\d{11}$/', $ph)) {
        tm_flash('error', 'Phone must be exactly 11 digits.');
        header('Location: ../TM_Register.php'); exit;
    }

    $chk = tm_exec('SELECT COUNT(*) FROM TM_Users WHERE email = :p1', [$em]);
    if ((int)tm_scalar($chk) > 0) {
        tm_flash('error', 'An account with this email already exists.');
        header('Location: ../TM_Register.php'); exit;
    }

    // Feature 6: Resolve org_id from invite code (org_name match) or default to 1
    $resolvedOrgId = 1; // Default Organization
    if ($orgCode !== '') {
        $orgRow = tm_fetch_one(tm_exec(
            'SELECT org_id FROM TM_Organizations WHERE LOWER(org_name) = LOWER(:p1)',
            [$orgCode]
        ));
        if ($orgRow) {
            $resolvedOrgId = (int)$orgRow['org_id'];
        } else {
            tm_flash('error', 'Organization not found. Leave blank to use the default.');
            header('Location: ../TM_Register.php'); exit;
        }
    }

    $un   = strtolower(explode('@', $em)[0]) . '_' . rand(100, 999);
    $hash = password_hash($pw, PASSWORD_BCRYPT);

    // Feature 7: Self-registered accounts start as 'pending' until admin approves.
    // Feature 6: org_id is now resolved above — never hardcoded to 1.
    tm_exec(
        'INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone, status, org_id)
         VALUES (:p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8)',
        [$un, $em, $hash, $fn, $ln, $ph, 'pending', $resolvedOrgId]
    );

    tm_flash('success', 'Account created! An administrator will review and activate your account shortly.');
    header('Location: ../TM_Login.php'); exit;
}

header('Location: ../TM_Login.php'); exit;
