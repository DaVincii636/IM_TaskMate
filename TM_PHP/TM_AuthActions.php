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
        'SELECT user_id, first_name, last_name, email, password_hash, role FROM TM_Users WHERE email = ?',
        [$email]
    );
    $user = tm_fetch_one($stmt);

    if (!$user || !password_verify($pw, $user['password_hash'])) {
        tm_flash('error', 'Invalid email or password.');
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

    $chk = tm_exec('SELECT COUNT(*) FROM TM_Users WHERE email = ?', [$em]);
    if ((int)tm_scalar($chk) > 0) {
        tm_flash('error', 'An account with this email already exists.');
        header('Location: ../TM_Register.php'); exit;
    }

    $un   = strtolower(explode('@', $em)[0]) . '_' . rand(100, 999);
    $hash = password_hash($pw, PASSWORD_BCRYPT);

    tm_exec(
        'INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$un, $em, $hash, $fn, $ln, $ph]
    );

    tm_flash('success', 'Account created! Please log in.');
    header('Location: ../TM_Login.php'); exit;
}

header('Location: ../TM_Login.php'); exit;