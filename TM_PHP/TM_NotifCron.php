<?php
// ============================================================
// TM_NotifCron.php — Notification generator
// ============================================================
// Can be called two ways:
//   1. Via a real server cron:  php /path/to/TM_PHP/TM_NotifCron.php
//   2. Silently on every login: require_once 'TM_NotifCron.php';
//
// It is safe to call multiple times — duplicates are skipped.
// ============================================================
require_once __DIR__ . '/TM_DB.php';

/**
 * Generate notifications for all active users.
 * Skips tasks that already have an unread notification of the same type.
 */
function tm_run_notif_cron(): void {

    // Fetch every non-done, non-cancelled task with its owner's user_id
    $stmt = tm_exec(
        "SELECT t.task_id, t.user_id, t.task_name,
                TRUNC(t.due_date)                        AS due_date,
                TRUNC(SYSDATE)                           AS today,
                TRUNC(t.due_date) - TRUNC(SYSDATE)       AS days_until_due
         FROM TM_Tasks t
         WHERE t.status NOT IN ('done', 'cancelled')"
    );
    $tasks = tm_fetch_all($stmt);

    foreach ($tasks as $t) {
        $taskId      = (int)($t['TASK_ID']        ?? $t['task_id']);
        $userId      = (int)($t['USER_ID']         ?? $t['user_id']);
        $taskName    = $t['TASK_NAME']             ?? $t['task_name'] ?? 'Task';
        $daysUntil   = (int)($t['DAYS_UNTIL_DUE'] ?? $t['days_until_due'] ?? 0);

        // Determine notification type
        if ($daysUntil < 0) {
            $type    = 'overdue';
            $daysDiff = abs($daysUntil);
            $message = "\"$taskName\" is overdue by $daysDiff day" . ($daysDiff === 1 ? '' : 's') . ".";
        } elseif ($daysUntil === 0) {
            $type    = 'due_today';
            $message = "\"$taskName\" is due today.";
        } elseif ($daysUntil <= 3) {
            $type    = 'due_soon';
            $message = "\"$taskName\" is due in $daysUntil day" . ($daysUntil === 1 ? '' : 's') . ".";
        } else {
            continue; // More than 3 days away — no notification needed yet
        }

        // Skip if an unread notification of this type already exists for this task
        $exists = tm_fetch_one(tm_exec(
            "SELECT COUNT(*) AS n FROM TM_Notifications
             WHERE user_id=:p1 AND task_id=:p2 AND type=:p3 AND is_read=0",
            [$userId, $taskId, $type]
        ));
        $count = (int)($exists['N'] ?? $exists['n'] ?? 0);
        if ($count > 0) continue;

        // Insert the new notification
        tm_exec(
            "INSERT INTO TM_Notifications (user_id, task_id, type, message)
             VALUES (:p1, :p2, :p3, :p4)",
            [$userId, $taskId, $type, $message]
        );
    }
}

tm_run_notif_cron();
