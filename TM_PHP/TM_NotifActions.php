<?php
// ============================================================
// TM_NotifActions.php — Notification read actions
// ============================================================
// Called via fetch() from TM_App.js.
// Always responds with JSON.
// ============================================================
require_once 'TM_Session.php';
require_once 'TM_DB.php';

header('Content-Type: application/json');
tm_require_login();

$uid    = tm_uid();
$action = $_POST['action'] ?? '';

switch ($action) {

    // Mark a single notification as read
    case 'mark_read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid id']); exit; }
        tm_exec(
            "UPDATE TM_Notifications SET is_read=1
             WHERE notif_id=:p1 AND user_id=:p2",
            [$id, $uid]
        );
        echo json_encode(['ok' => true]);
        break;

    // Mark all of this user's notifications as read
    case 'mark_all_read':
        tm_exec(
            "UPDATE TM_Notifications SET is_read=1
             WHERE user_id=:p1 AND is_read=0",
            [$uid]
        );
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
exit;
