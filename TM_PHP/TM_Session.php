<?php
// =============================================
// TM_Session.php — Session helpers
// =============================================
if (session_status() === PHP_SESSION_NONE) session_start();

function tm_is_logged_in(): bool {
    return !empty($_SESSION['tm_user_id']);
}

function tm_require_login(): void {
    if (!tm_is_logged_in()) {
        header('Location: ../TM_Login.php');
        exit;
    }
}

function tm_uid(): int {
    return (int)($_SESSION['tm_user_id'] ?? 0);
}

function tm_uname(): string {
    return $_SESSION['tm_first_name'] ?? 'User';
}

function tm_role(): string {
    return $_SESSION['tm_role'] ?? 'user';
}

function tm_require_role(string $role): void {
    tm_require_login();
    // 'admin' required → only admin passes
    // 'moderator' required → both admin and moderator pass
    if ($role === 'moderator') {
        if (!tm_is_moderator()) {
            header('Location: ../TM_Dashboard.php');
            exit;
        }
    } else {
        if (tm_role() !== $role) {
            header('Location: ../TM_Dashboard.php');
            exit;
        }
    }
}

function tm_flash(string $type, string $msg): void {
    $_SESSION['tm_flash'] = ['type' => $type, 'msg' => $msg];
}

function tm_get_flash(): ?array {
    if (!isset($_SESSION['tm_flash'])) return null;
    $f = $_SESSION['tm_flash'];
    unset($_SESSION['tm_flash']);
    return $f;
}

function tm_is_admin(): bool {
    return tm_role() === 'admin';
}

function tm_is_moderator(): bool {
    return in_array(tm_role(), ['admin', 'moderator']);
}