<?php
// =============================================
// TM_OnboardingActions.php
// Feature 11 — Onboarding Tooltips (HCI101 Week 2: Learnability & Memorability)
//
// Handles a single POST action: mark_done
//   Upserts a row into TM_UserPrefs so the walkthrough is never shown again.
//
// Called by TM_JS/TM_Onboarding.js via fetch() when the user clicks
// "Got it!" on the final tooltip step or dismisses the overlay.
// =============================================
require_once 'TM_Session.php';
require_once 'TM_DB.php';

tm_require_login();

$action = $_POST['action'] ?? '';
$uid    = tm_uid();

if ($action === 'mark_done') {
    // MERGE / UPSERT: insert a prefs row if none exists, otherwise update it.
    // Oracle does not have INSERT … ON CONFLICT; use MERGE instead.
    tm_exec(
        "MERGE INTO TM_UserPrefs dst
         USING (SELECT :p1 AS user_id FROM DUAL) src
         ON (dst.user_id = src.user_id)
         WHEN MATCHED THEN
             UPDATE SET onboarding_done = 1, updated_at = CURRENT_TIMESTAMP
         WHEN NOT MATCHED THEN
             INSERT (user_id, onboarding_done)
             VALUES (:p2, 1)",
        [$uid, $uid]
    );
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Unknown action
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
