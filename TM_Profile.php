<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash = tm_get_flash();
$uid   = tm_uid();

// Fetch fresh user record — use TO_CHAR so Oracle TIMESTAMP comes back as a
// plain string that PHP's strtotime() can parse (raw OCIDate/LOB returns
// cause strtotime to fall back to 1970-01-01).
$userRow = tm_fetch_one(tm_exec(
    "SELECT first_name, last_name, email, phone, role,
            TO_CHAR(created_at,'YYYY-MM-DD') AS created_at
     FROM TM_Users WHERE user_id=:p1",
    [$uid]
));
if (!$userRow) {
    header('Location: TM_Dashboard.php'); exit;
}

$firstName = $userRow['first_name'] ?? $userRow['FIRST_NAME'] ?? '';
$lastName  = $userRow['last_name']  ?? $userRow['LAST_NAME']  ?? '';
$email     = $userRow['email']      ?? $userRow['EMAIL']      ?? '';
$phone     = $userRow['phone']      ?? $userRow['PHONE']      ?? '';
$role      = $userRow['role']       ?? $userRow['ROLE']       ?? 'user';
$createdAt = $userRow['created_at'] ?? $userRow['CREATED_AT'] ?? '';

// Task summary for the profile sidebar
function _p_count($sql, $uid) {
    $row = tm_fetch_one(tm_exec($sql, [$uid]));
    if (!$row || !is_array($row)) return 0;
    $val = reset($row);
    return (int)($val ?? 0);
}
$cntTotal   = _p_count("SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1", $uid);
$cntDone    = _p_count("SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1 AND status='done'", $uid);
$cntPending = _p_count("SELECT COUNT(*) AS n FROM TM_Tasks WHERE user_id=:p1 AND status NOT IN ('done','cancelled')", $uid);

// Feature 8: My Teams — show membership info on profile
$teamsStmt = tm_exec(
    "SELECT t.team_id, t.team_name, t.team_desc,
            tm.is_manager,
            (SELECT COUNT(*) FROM TM_TeamMembers x WHERE x.team_id = t.team_id) AS member_count
     FROM TM_Teams t
     JOIN TM_TeamMembers tm ON tm.team_id = t.team_id
     WHERE tm.user_id = :p1
     ORDER BY t.team_name ASC",
    [$uid]
);
$myTeams = tm_fetch_all($teamsStmt);

require_once 'TM_PHP/TM_NavNotif.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>My Profile - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
/* ── Profile page layout ────────────────────────────────── */
.profile-page {
    max-width: 860px;
    margin: 2.5rem auto;
    padding: 0 1.25rem 4rem;
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 680px) {
    .profile-page { grid-template-columns: 1fr; }
}

/* ── Sidebar card ───────────────────────────────────────── */
.profile-sidebar {
    background: var(--white);
    border-radius: var(--radius-md);
    border: 1px solid var(--gray-100);
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    padding: 1.75rem 1.25rem;
    text-align: center;
}
.profile-avatar {
    width: 72px; height: 72px;
    background: var(--black);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.75rem; font-weight: 900;
    color: var(--white);
    margin: 0 auto 1rem;
}
.profile-name {
    font-size: 1rem; font-weight: 700; margin-bottom: 3px;
}
.profile-role {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .07em; color: var(--gray-400);
    margin-bottom: 1rem;
}
.profile-email {
    font-size: 12px; color: var(--gray-500); margin-bottom: 1.25rem;
    word-break: break-all;
}
.profile-stats {
    display: flex; gap: 0; border-top: 1px solid var(--gray-100);
    padding-top: 1rem; margin-top: .25rem;
}
.profile-stat {
    flex: 1; text-align: center;
}
.profile-stat + .profile-stat {
    border-left: 1px solid var(--gray-100);
}
.profile-stat-num {
    font-size: 1.3rem; font-weight: 800; line-height: 1;
}
.profile-stat-label {
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--gray-400); margin-top: 3px;
}
.profile-member-since {
    font-size: 11px; color: var(--gray-400); margin-top: 1rem;
}

/* ── Main form card ─────────────────────────────────────── */
.profile-card {
    background: var(--white);
    border-radius: var(--radius-md);
    border: 1px solid var(--gray-100);
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    overflow: hidden;
}
.profile-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-100);
    font-size: 14px; font-weight: 700;
}
.profile-card-body {
    padding: 1.5rem;
}
.profile-section-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .08em; color: var(--gray-400);
    margin-bottom: .75rem; padding-bottom: .4rem;
    border-bottom: 1px solid var(--gray-100);
}
.form-row-2 {
    display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
    margin-bottom: 1rem;
}
@media (max-width: 480px) {
    .form-row-2 { grid-template-columns: 1fr; }
}
.profile-form-group {
    margin-bottom: 1rem;
}
.profile-form-group label {
    display: block; font-size: 12px; font-weight: 600;
    margin-bottom: 5px; color: var(--gray-500);
}
.profile-form-group input {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'Poppins', sans-serif;
    font-size: 13px; color: var(--black);
    transition: border-color .18s, box-shadow .18s;
    background: var(--white);
}
.profile-form-group input:focus {
    border-color: var(--black);
    box-shadow: 0 0 0 3px rgba(10,10,10,.08);
    outline: none;
}
.profile-form-group input[readonly] {
    background: var(--bg); color: var(--gray-400); cursor: not-allowed;
}
.profile-pw-hint {
    font-size: 11px; color: var(--gray-400); margin-top: 4px;
}
.pw-input-wrap {
    position: relative;
}
.pw-input-wrap input {
    padding-right: 40px;
}
.pw-toggle {
    position: absolute; right: 10px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; color: var(--gray-400);
    cursor: pointer; padding: 0; font-size: 14px;
}
.pw-toggle:hover { color: var(--black); }

.profile-actions {
    display: flex; justify-content: flex-end; gap: .75rem;
    margin-top: 1.5rem; padding-top: 1rem;
    border-top: 1px solid var(--gray-100);
}
.btn-profile-save {
    padding: 9px 24px;
    background: var(--black); color: var(--white);
    border: none; border-radius: 100px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px; font-weight: 600;
    cursor: pointer; transition: opacity .18s;
}
.btn-profile-save:hover { opacity: .85; }
.btn-profile-cancel {
    padding: 9px 20px;
    background: var(--white); color: var(--gray-500);
    border: 1.5px solid var(--border); border-radius: 100px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px; font-weight: 600;
    cursor: pointer; transition: background .18s;
    text-decoration: none; display: inline-flex; align-items: center;
}
.btn-profile-cancel:hover { background: var(--bg); }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars($firstName) ?></strong></span>
        <a href="TM_Profile.php" class="btn-logout" title="My Profile"
           style="display:inline-flex;align-items:center;gap:5px;">
            <i class="fa-solid fa-user-circle"></i>
        </a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"     class="btn-logout">Tasks</a>
        <a href="TM_Activity.php"  class="btn-logout">Activity</a>
        <a href="TM_Analytics.php" class="btn-logout">Analytics</a>
        <!-- Global Search (Feature 5) -->
        <form class="navbar-search" action="TM_Tasks.php" method="get">
            <input type="hidden" name="view" value="all"/>
            <input type="text" name="q" class="navbar-search-input"
                   placeholder="Search tasks..." autocomplete="off"
                   value="<?= isset($_GET['q']) ? htmlspecialchars($_GET['q']) : '' ?>"/>
            <button type="submit" class="navbar-search-btn" title="Search">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </form>
        <?= $tm_notif_bell_html ?>
        <a href="#" class="btn-logout" id="logoutBtn">Log Out</a>
    </div>
</nav>

<!-- Logout Modal -->
<div id="logoutModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(231,76,60,.12);">
            <i class="fa-solid fa-arrow-right-from-bracket" style="color:#e74c3c;"></i>
        </div>
        <div class="pc-modal-title">Log Out?</div>
        <div class="pc-modal-body">You'll need to sign in again to access your tasks.</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" id="logoutCancel">Cancel</button>
            <a href="TM_PHP/TM_AuthActions.php?action=logout" class="pc-modal-confirm-red">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out
            </a>
        </div>
    </div>
</div>

<?php if ($flash): ?>
<div class="<?= $flash['type']==='error' ? 'validation-summary' : 'success-banner' ?>" style="display:none">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="profile-page">

    <!-- Sidebar -->
    <aside class="profile-sidebar">
        <div class="profile-avatar">
            <?= strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1)) ?>
        </div>
        <div class="profile-name"><?= htmlspecialchars($firstName . ' ' . $lastName) ?></div>
        <div class="profile-role"><?= htmlspecialchars(ucfirst($role)) ?></div>
        <div class="profile-email"><?= htmlspecialchars($email) ?></div>
        <div class="profile-stats">
            <div class="profile-stat">
                <div class="profile-stat-num"><?= $cntTotal ?></div>
                <div class="profile-stat-label">Total</div>
            </div>
            <div class="profile-stat">
                <div class="profile-stat-num"><?= $cntDone ?></div>
                <div class="profile-stat-label">Done</div>
            </div>
            <div class="profile-stat">
                <div class="profile-stat-num"><?= $cntPending ?></div>
                <div class="profile-stat-label">Active</div>
            </div>
        </div>
        <?php
        // $createdAt is already a 'YYYY-MM-DD' string from TO_CHAR above.
        // Only render if it's a non-empty string (guards against NULL or OCI resource).
        $memberSinceTs = is_string($createdAt) && $createdAt !== '' ? strtotime($createdAt) : false;
        ?>
        <?php if ($memberSinceTs !== false && $memberSinceTs > 0): ?>
        <div class="profile-member-since">
            Member since <?= htmlspecialchars(date('M Y', $memberSinceTs)) ?>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Form card -->
    <div class="profile-card">
        <div class="profile-card-header">
            <i class="fa-solid fa-user-pen" style="margin-right:8px;color:var(--gray-400)"></i>
            Edit Profile
        </div>
        <div class="profile-card-body">
            <form method="post" action="TM_PHP/TM_UserActions.php" id="profileForm">
                <input type="hidden" name="action" value="update_self"/>

                <!-- Personal info -->
                <div class="profile-section-label">Personal Information</div>
                <div class="form-row-2">
                    <div class="profile-form-group">
                        <label for="prof_fname">First Name</label>
                        <input type="text" id="prof_fname" name="firstName"
                               value="<?= htmlspecialchars($firstName) ?>" required/>
                    </div>
                    <div class="profile-form-group">
                        <label for="prof_lname">Last Name</label>
                        <input type="text" id="prof_lname" name="lastName"
                               value="<?= htmlspecialchars($lastName) ?>" required/>
                    </div>
                </div>
                <div class="profile-form-group">
                    <label>Email <span style="font-size:11px;font-weight:400;color:var(--gray-400)">(read-only)</span></label>
                    <input type="email" value="<?= htmlspecialchars($email) ?>" readonly/>
                </div>
                <div class="profile-form-group">
                    <label for="prof_phone">Phone Number</label>
                    <input type="tel" id="prof_phone" name="phone"
                           value="<?= htmlspecialchars($phone) ?>"
                           required maxlength="11" pattern="[0-9]{11}"
                           inputmode="numeric"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'')"/>
                </div>

                <!-- Change password -->
                <div class="profile-section-label" style="margin-top:1.5rem">Change Password <span style="font-size:11px;font-weight:400;color:var(--gray-400)">(optional)</span></div>
                <div class="profile-form-group">
                    <label for="prof_curpw">Current Password</label>
                    <div class="pw-input-wrap">
                        <input type="password" id="prof_curpw" name="currentPassword"
                               placeholder="Enter current password" autocomplete="current-password"/>
                        <button type="button" class="pw-toggle" data-target="prof_curpw">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="profile-form-group">
                        <label for="prof_newpw">New Password</label>
                        <div class="pw-input-wrap">
                            <input type="password" id="prof_newpw" name="newPassword"
                                   placeholder="Min. 6 characters" autocomplete="new-password"/>
                            <button type="button" class="pw-toggle" data-target="prof_newpw">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="profile-pw-hint">Leave blank to keep current password.</div>
                    </div>
                    <div class="profile-form-group">
                        <label for="prof_newpw2">Confirm New Password</label>
                        <div class="pw-input-wrap">
                            <input type="password" id="prof_newpw2" name="confirmPassword"
                                   placeholder="Repeat new password" autocomplete="new-password"/>
                            <button type="button" class="pw-toggle" data-target="prof_newpw2">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="profile-actions">
                    <a href="TM_Dashboard.php" class="btn-profile-cancel">Cancel</a>
                    <button type="submit" class="btn-profile-save">
                        <i class="fa-solid fa-floppy-disk"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

</div><!-- /.profile-page -->

<!-- ── My Teams Section ─────────────────────────────────────────────────── -->
<?php if (!empty($myTeams)): ?>
<div style="max-width:860px;margin:0 auto 3rem;padding:0 1.25rem;">
    <div class="profile-card">
        <div class="profile-card-header">
            <i class="fa-solid fa-users" style="margin-right:8px;color:var(--gray-400)"></i>
            My Teams
            <span style="font-size:12px;font-weight:400;color:var(--gray-400);margin-left:8px;"><?= count($myTeams) ?> team<?= count($myTeams) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="profile-card-body" style="padding:1rem 1.5rem;">
            <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($myTeams as $t):
                $tname   = $t['TEAM_NAME']    ?? $t['team_name']    ?? '';
                $tdesc   = $t['TEAM_DESC']    ?? $t['team_desc']    ?? '';
                $isMgr   = (int)($t['IS_MANAGER']  ?? $t['is_manager']  ?? 0);
                $mcount  = (int)($t['MEMBER_COUNT'] ?? $t['member_count'] ?? 0);
                $tid     = (int)($t['TEAM_ID']      ?? $t['team_id']      ?? 0);
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:.75rem 1rem;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:var(--bg);">
                <div style="width:38px;height:38px;border-radius:50%;background:var(--black);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-users" style="color:#fff;font-size:14px;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:700;color:var(--black);">
                        <?= htmlspecialchars($tname) ?>
                        <?php if ($isMgr): ?>
                        <span style="font-size:10px;font-weight:700;background:#fef9c3;color:#92400e;padding:2px 8px;border-radius:50px;margin-left:6px;vertical-align:middle;">MANAGER</span>
                        <?php else: ?>
                        <span style="font-size:10px;font-weight:700;background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:50px;margin-left:6px;vertical-align:middle;">MEMBER</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($tdesc): ?>
                    <div style="font-size:12px;color:var(--gray-500);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($tdesc) ?></div>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:11px;color:var(--gray-400);"><i class="fa-solid fa-user" style="margin-right:3px;"></i><?= $mcount ?> member<?= $mcount !== 1 ? 's' : '' ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<script>
// Logout modal
(function(){
    var btn=document.getElementById('logoutBtn');
    var modal=document.getElementById('logoutModal');
    var cancel=document.getElementById('logoutCancel');
    if(btn) btn.addEventListener('click',function(e){e.preventDefault();modal.classList.add('active');});
    if(cancel) cancel.addEventListener('click',function(){modal.classList.remove('active');});
    if(modal) modal.addEventListener('click',function(e){if(e.target===modal)modal.classList.remove('active');});
})();

// Password toggle
document.querySelectorAll('.pw-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(this.dataset.target);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        this.innerHTML = input.type === 'password'
            ? '<i class="fa-solid fa-eye"></i>'
            : '<i class="fa-solid fa-eye-slash"></i>';
    });
});

// Client-side password confirmation check
document.getElementById('profileForm').addEventListener('submit', function(e) {
    var newPw  = document.getElementById('prof_newpw').value;
    var newPw2 = document.getElementById('prof_newpw2').value;
    if (newPw && newPw !== newPw2) {
        e.preventDefault();
        showToast('New passwords do not match.', 'error');
        document.getElementById('prof_newpw2').focus();
    }
    if (newPw && newPw.length < 6) {
        e.preventDefault();
        showToast('New password must be at least 6 characters.', 'error');
    }
});
</script>
<script src="TM_JS/TM_App.js"></script>
</body>
</html>
