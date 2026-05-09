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

    $stmt = tm_exec(
        'SELECT user_id, first_name, last_name, email, password_hash, role, status FROM TM_Users WHERE email = :p1',
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

    $un   = strtolower(explode('@', $em)[0]) . '_' . rand(100, 999);
    $hash = password_hash($pw, PASSWORD_BCRYPT);

    // Feature 7: Self-registered accounts start as 'pending' until admin approves.
    // Admins adding users directly via the Admin Panel use 'active' (see TM_UserActions.php).
tm_exec(
    'INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone, status, org_id)
     VALUES (:p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8)',
    [$un, $em, $hash, $fn, $ln, $ph, 'pending', 1]
);

    tm_flash('success', 'Account created! An administrator will review and activate your account shortly.');
    header('Location: ../TM_Login.php'); exit;
}

header('Location: ../TM_Login.php'); exit;