<?php
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'add':
        $fn = trim($_POST['firstName'] ?? '');
        $ln = trim($_POST['lastName']  ?? '');
        $em = trim($_POST['email']     ?? '');
        $ph = trim($_POST['phone']     ?? '');
        $pw = $_POST['password']       ?? '';

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
            'INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone)
             VALUES (:p1, :p2, :p3, :p4, :p5, :p6)',
            [$un, $em, password_hash($pw, PASSWORD_BCRYPT), $fn, $ln, $ph]
        );
        tm_flash('success', "User '$fn $ln' added.");
        break;

    case 'edit':
        $id = (int)($_POST['id'] ?? 0);
        $fn = trim($_POST['firstName'] ?? '');
        $ln = trim($_POST['lastName']  ?? '');
        $ph = trim($_POST['phone']     ?? '');
        $pw = $_POST['password']       ?? '';

        if ($id <= 0 || !$fn || !$ln || !$ph) {
            tm_flash('error', 'Required fields missing.'); break;
        }
        if ($pw && strlen($pw) < 6) {
            tm_flash('error', 'New password must be at least 6 characters.'); break;
        }
        if ($pw) {
            tm_exec(
                'UPDATE TM_Users SET first_name=:p1, last_name=:p2, phone=:p3, password_hash=:p4 WHERE user_id=:p5',
                [$fn, $ln, $ph, password_hash($pw, PASSWORD_BCRYPT), $id]
            );
        } else {
            tm_exec(
                'UPDATE TM_Users SET first_name=:p1, last_name=:p2, phone=:p3 WHERE user_id=:p4',
                [$fn, $ln, $ph, $id]
            );
        }
        tm_flash('success', "User '$fn $ln' updated.");
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { tm_flash('error', 'Invalid user.'); break; }
        tm_exec('DELETE FROM TM_Users WHERE user_id = :p1', [$id]);
        tm_flash('success', 'User deleted.');
        break;
}

header('Location: ../TM_UserList.php'); exit;