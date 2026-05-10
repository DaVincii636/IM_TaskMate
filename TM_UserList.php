<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_role('moderator');

$flash     = tm_get_flash();
$userName  = tm_uname();
$is_admin     = tm_is_admin();
$is_org_admin = tm_is_org_admin(); // Feature 8: true for admin and org_admin
$uid          = tm_uid();
$oid       = tm_org_id();

// ── Feature 6: Users — system admins see all, org_admins see own org ─────────
if ($is_admin) {
    $stmt = tm_exec(
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone,
                u.role, u.status, u.org_id,
                o.org_name
         FROM TM_Users u
         LEFT JOIN TM_Organizations o ON o.org_id = u.org_id
         ORDER BY u.user_id DESC'
    );
} else {
    $stmt = tm_exec(
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone,
                u.role, u.status, u.org_id,
                o.org_name
         FROM TM_Users u
         LEFT JOIN TM_Organizations o ON o.org_id = u.org_id
         WHERE u.org_id = :p1
         ORDER BY u.user_id DESC',
        [$oid]
    );
}
$users     = tm_fetch_all($stmt);
$userCount = count($users);

// ── Feature 6: Load all organizations (for admin org panel & dropdowns) ───────
$orgs = [];
$orgsById = [];
if ($is_admin) {
    $orgStmt = tm_exec(
        "SELECT o.org_id, o.org_name,
                TO_CHAR(o.created_at,'YYYY-MM-DD') AS created_at,
                (SELECT COUNT(*) FROM TM_Users u WHERE u.org_id = o.org_id) AS member_count
         FROM TM_Organizations o
         ORDER BY o.org_id ASC"
    );
    $orgs = tm_fetch_all($orgStmt);
    foreach ($orgs as $o) {
        $id = (int)($o['org_id'] ?? $o['ORG_ID'] ?? 0);
        $orgs[array_search($o, $orgs)]['org_id']       = $id;
        $orgs[array_search($o, $orgs)]['member_count'] = (int)($o['member_count'] ?? $o['MEMBER_COUNT'] ?? 0);
        $orgsById[$id] = $o['org_name'] ?? $o['ORG_NAME'] ?? '';
    }
    // Re-index cleanly
    $orgs = array_values(array_map(function($o) {
        return [
            'org_id'       => (int)($o['org_id']       ?? $o['ORG_ID']       ?? 0),
            'org_name'     => $o['org_name']     ?? $o['ORG_NAME']     ?? '',
            'created_at'   => $o['created_at']   ?? $o['CREATED_AT']   ?? '',
            'member_count' => (int)($o['member_count'] ?? $o['MEMBER_COUNT'] ?? 0),
        ];
    }, $orgs));
}


// ── Feature 8: Teams — load teams scoped to this admin's org (or all for sysadmin) ──
$teams = [];
if ($is_admin || $is_org_admin) {
    $teamStmt = $is_admin
        ? tm_exec(
            "SELECT t.team_id, t.team_name, t.team_desc, t.org_id,
                    TO_CHAR(t.created_at,'YYYY-MM-DD') AS created_at,
                    o.org_name,
                    (SELECT COUNT(*) FROM TM_TeamMembers x WHERE x.team_id = t.team_id) AS member_count,
                    u.first_name || ' ' || u.last_name AS creator_name
             FROM TM_Teams t
             JOIN TM_Organizations o ON o.org_id = t.org_id
             JOIN TM_Users u ON u.user_id = t.created_by
             ORDER BY t.org_id, t.team_name ASC"
          )
        : tm_exec(
            "SELECT t.team_id, t.team_name, t.team_desc, t.org_id,
                    TO_CHAR(t.created_at,'YYYY-MM-DD') AS created_at,
                    o.org_name,
                    (SELECT COUNT(*) FROM TM_TeamMembers x WHERE x.team_id = t.team_id) AS member_count,
                    u.first_name || ' ' || u.last_name AS creator_name
             FROM TM_Teams t
             JOIN TM_Organizations o ON o.org_id = t.org_id
             JOIN TM_Users u ON u.user_id = t.created_by
             WHERE t.org_id = :p1
             ORDER BY t.team_name ASC",
            [$oid]
          );
    $rawTeams = tm_fetch_all($teamStmt);
    foreach ($rawTeams as $rt) {
        $teams[] = [
            'team_id'      => (int)($rt['team_id']      ?? $rt['TEAM_ID']      ?? 0),
            'team_name'    => $rt['team_name']    ?? $rt['TEAM_NAME']    ?? '',
            'team_desc'    => $rt['team_desc']    ?? $rt['TEAM_DESC']    ?? '',
            'org_id'       => (int)($rt['org_id']       ?? $rt['ORG_ID']       ?? 0),
            'org_name'     => $rt['org_name']     ?? $rt['ORG_NAME']     ?? '',
            'created_at'   => $rt['created_at']   ?? $rt['CREATED_AT']   ?? '',
            'member_count' => (int)($rt['member_count'] ?? $rt['MEMBER_COUNT'] ?? 0),
            'creator_name' => $rt['creator_name'] ?? $rt['CREATOR_NAME'] ?? '',
        ];
    }
}

// ── Notifications ─────────────────────────────────────────────────────────────
require_once 'TM_PHP/TM_NavNotif.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Panel - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        /* Table */
        table{width:100%;border-collapse:collapse;}
        thead th{padding:12px 16px;font-size:12px;font-weight:700;text-transform:uppercase;
                 letter-spacing:.5px;color:var(--gray-300,#9ca3af);border-bottom:1.5px solid var(--border,#e5e7eb);text-align:left;}
        tbody tr{border-bottom:1px solid var(--border,#e5e7eb);transition:background .15s;}
        tbody tr:hover{background:var(--bg,#f9fafb);}
        td{padding:14px 16px;font-size:14px;color:var(--black,#111);}
        .td-name{font-weight:600;}
        .td-actions{display:flex;gap:8px;}
        .btn-edit-user{padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;border:1px solid var(--border,#e5e7eb);
                       background:var(--bg,#f9fafb);color:var(--black,#111);cursor:pointer;transition:all .2s;font-family:'Poppins',sans-serif;}
        .btn-edit-user:hover{background:var(--border,#e5e7eb);}
        .btn-delete-user{padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;border:1px solid #fca5a5;
                         background:#fef2f2;color:#ef4444;cursor:pointer;transition:all .2s;font-family:'Poppins',sans-serif;}
        .btn-delete-user:hover{background:#fee2e2;}

        /* ── Tab navigation ── */
        .admin-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:2px solid var(--border,#e5e7eb);padding-bottom:0;}
        .admin-tab{padding:10px 22px;font-size:13px;font-weight:600;border:none;background:none;
                   cursor:pointer;color:var(--gray-300,#9ca3af);border-bottom:3px solid transparent;
                   margin-bottom:-2px;transition:all .2s;font-family:'Poppins',sans-serif;border-radius:6px 6px 0 0;}
        .admin-tab:hover{color:var(--black,#111);background:var(--bg,#f9fafb);}
        .admin-tab.active{color:var(--black,#111);border-bottom-color:var(--black,#111);}
        .tab-panel{display:none;}.tab-panel.active{display:block;}

        /* ── Org cards grid ── */
        .org-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-top:4px;}
        .org-card{background:var(--white,#fff);border:1.5px solid var(--border,#e5e7eb);border-radius:14px;
                  padding:20px;transition:box-shadow .2s;}
        .org-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);}
        .org-card-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;}
        .org-card-name{font-size:15px;font-weight:700;color:var(--black,#111);line-height:1.3;}
        .org-card-plan{display:none;
                       padding:2px 8px;border-radius:50px;}
        .org-card-meta{font-size:12px;color:var(--gray-300,#9ca3af);margin-bottom:16px;}
        .org-card-meta span{margin-right:14px;}
        .org-card-actions{display:flex;gap:8px;flex-wrap:wrap;}
        .btn-org-edit{padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;
                      border:1px solid var(--border,#e5e7eb);background:var(--bg,#f9fafb);
                      color:var(--black,#111);cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
        .btn-org-edit:hover{background:var(--border,#eee);}
        .btn-org-delete{padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;
                        border:1px solid #fca5a5;background:#fef2f2;color:#ef4444;
                        cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
        .btn-org-delete:hover{background:#fee2e2;}
        .btn-org-delete:disabled{opacity:.4;cursor:not-allowed;}
        .org-badge{font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;
                   background:#e0f2fe;color:#0369a1;}
        .modal-select{width:100%;padding:10px 12px;border-radius:8px;font-size:13px;
                      border:1.5px solid var(--border,#e5e7eb);font-family:'Poppins',sans-serif;
                      background:var(--white,#fff);color:var(--black,#111);outline:none;}
        .modal-select:focus{border-color:var(--black,#111);}
        .transfer-user-name{font-weight:700;color:var(--black,#111);}
        .org-empty{text-align:center;padding:50px 20px;color:var(--gray-300,#9ca3af);}
        .org-empty-icon{font-size:2.5rem;margin-bottom:.75rem;}
        .org-empty-text{font-size:14px;font-weight:600;color:var(--black,#111);margin-bottom:.25rem;}
        .org-empty-sub{font-size:12px;}
        .table-card{background:var(--white,#fff);border-radius:var(--radius-lg,16px);border:1px solid var(--border,#e5e7eb);overflow:hidden;}
        .table-wrap{overflow-x:auto;}
        .empty-table{text-align:center;padding:60px 20px;}
        .empty-table-icon{font-size:2.5rem;margin-bottom:.75rem;}
        .empty-table-text{font-size:1rem;font-weight:700;color:var(--black,#111);margin-bottom:.25rem;}
        .empty-table-sub{font-size:13px;color:var(--gray-300,#9ca3af);}
        /* Modal overlay (Add/Edit) — layout styles from TM_Style.css */
        .btn-cancel-modal{padding:9px 22px;border-radius:50px;font-size:13px;font-weight:600;
                          border:1.5px solid var(--border,#e5e7eb);background:var(--white,#fff);
                          color:var(--text-mid,#666);cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
        .btn-cancel-modal:hover{background:var(--border,#eee);}
        .btn-save-modal{padding:9px 22px;border-radius:50px;font-size:13px;font-weight:700;
                        background:linear-gradient(135deg,#111,#333);color:#fff;border:none;
                        cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
        .btn-save-modal:hover{opacity:.9;transform:translateY(-1px);}


    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars($userName) ?></strong></span>
        <a href="TM_Profile.php" class="btn-logout" title="My Profile" style="display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-user-circle"></i></a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"     class="btn-logout">To-Do List</a>
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

<main class="main-container">

    <div class="page-header">
        <div class="page-title">Admin Panel</div>
        <div class="page-subtitle"><?php if ($is_admin): ?>Manage all users across every organization, configure organizations, and oversee teams.<?php else: ?>Manage users and teams within your organization.<?php endif; ?></div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label"><?php echo $is_admin ? 'Total Users (All Orgs)' : 'Users in Your Org'; ?></div>
            <div class="stat-value"><?= $userCount ?></div>
            <div class="stat-desc"><?php echo $is_admin ? 'All registered accounts system-wide' : 'Members of your organization'; ?></div>
        </div>
        <?php if ($is_admin): ?>
        <div class="stat-card">
            <div class="stat-label">Organizations</div>
            <div class="stat-value"><?= count($orgs) ?></div>
            <div class="stat-desc">Active organizations in the system</div>
        </div>
        <?php else: ?>
        <div class="stat-card">
            <div class="stat-label">Your Organization</div>
            <div class="stat-value" style="font-size:22px;letter-spacing:-.5px"><?= htmlspecialchars(tm_org_name()) ?></div>
            <div class="stat-desc">You manage users within this org</div>
        </div>
        <?php endif; ?>
        <div class="stat-card">
            <div class="stat-label">Teams</div>
            <div class="stat-value"><?= count($teams) ?></div>
            <div class="stat-desc"><?php echo $is_admin ? 'Teams across all organizations' : 'Teams in your organization'; ?></div>
        </div>
    </div>

    <?php
    // ── Feature 7: Approval queue ─────────────────────────────────────────────
    $pendingStmt = tm_exec(
        "SELECT user_id, first_name, last_name, email, phone FROM TM_Users WHERE status='pending' ORDER BY created_at ASC"
    );
    $pendingUsers = tm_fetch_all($pendingStmt);
    if ($is_admin && !empty($pendingUsers)):
    ?>
    <div class="table-card" style="margin-bottom:24px;border:1.5px solid #fcd34d;">
        <div style="padding:14px 20px;background:#fffbeb;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:10px;">
            <i class="fa-regular fa-clock" style="color:#92400e;font-size:1rem;"></i>
            <strong style="font-size:14px;color:#92400e;">Pending Approval (<?= count($pendingUsers) ?>)</strong>
            <span style="font-size:12px;color:#b45309;">These users registered and are awaiting activation.</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pendingUsers as $i => $pu): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="td-name"><?= htmlspecialchars($pu['first_name'] . ' ' . $pu['last_name']) ?></td>
                    <td><?= htmlspecialchars($pu['email']) ?></td>
                    <td><?= htmlspecialchars($pu['phone']) ?></td>
                    <td>
                        <div class="td-actions">
                            <form method="post" action="TM_PHP/TM_UserActions.php" style="display:inline">
                                <input type="hidden" name="action" value="approve"/>
                                <input type="hidden" name="id" value="<?= $pu['user_id'] ?>"/>
                                <button type="submit" style="padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;border:1px solid #6ee7b7;background:#ecfdf5;color:#065f46;cursor:pointer;font-family:'Poppins',sans-serif;">
                                    Approve
                                </button>
                            </form>
                            <form method="post" action="TM_PHP/TM_UserActions.php" style="display:inline">
                                <input type="hidden" name="action" value="suspend"/>
                                <input type="hidden" name="id" value="<?= $pu['user_id'] ?>"/>
                                <button type="submit" class="btn-delete-user">Reject</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // ── Feature 9: CSV Import summary (shown once after import) ──────────────
    if (!empty($_SESSION['tm_csv_import_summary'])):
        $summary = $_SESSION['tm_csv_import_summary'];
        unset($_SESSION['tm_csv_import_summary']);
    ?>
    <div class="table-card" style="margin-bottom:24px;border:1.5px solid #a5b4fc;">
        <div style="padding:14px 20px;background:#eef2ff;border-bottom:1px solid #c7d2fe;">
            <strong style="font-size:14px;color:#3730a3;">CSV Import Summary</strong>
        </div>
        <div style="padding:16px 20px;font-size:13px;">
            <p style="color:#065f46;margin:0 0 8px"><strong><?= $summary['success'] ?></strong> user(s) imported successfully.</p>
            <?php if (!empty($summary['skipped'])): ?>
            <p style="color:#92400e;margin:0 0 6px"><strong><?= count($summary['skipped']) ?></strong> row(s) skipped:</p>
            <ul style="margin:0;padding-left:20px;color:#78350f;">
                <?php foreach ($summary['skipped'] as $s): ?>
                <li><?= htmlspecialchars($s) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Tab navigation (Users / Organizations) ── -->
    <?php if ($is_admin || $is_org_admin): ?>
    <div class="admin-tabs">
        <button class="admin-tab active" onclick="switchTab('tab-users', this)" title="View and manage user accounts"><i class="fa-solid fa-users" style="margin-right:6px;font-size:12px;"></i>Users</button>
        <?php if ($is_admin): ?>
        <button class="admin-tab" onclick="switchTab('tab-orgs', this)" title="Manage organizations"><i class="fa-solid fa-building" style="margin-right:6px;font-size:12px;"></i>Organizations</button>
        <?php endif; ?>
        <button class="admin-tab" onclick="switchTab('tab-teams', this)" title="Create and manage teams within organizations"><i class="fa-solid fa-people-group" style="margin-right:6px;font-size:12px;"></i>Teams</button>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════
         TAB: USERS (existing content wrapped)
         ══════════════════════════════════════════ -->
    <div class="tab-panel active" id="tab-users">
    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px 18px;margin-bottom:18px;font-size:13px;color:#6b7280;">
        <strong style="color:#111;">Users</strong> — Add, edit, suspend, or remove user accounts.
        <?php if ($is_admin): ?>Assign roles (User, Moderator, Org Admin, Admin) and move users between organizations.<?php else: ?>Assign roles up to Org Admin within your organization.<?php endif; ?>
    </div>
    <div class="admin-bar">
        <span class="admin-badge">User List</span>
        <?php if ($is_admin || $is_org_admin): ?>
        <div style="display:flex;gap:10px;align-items:center;">
            <button class="btn-add-user" onclick="openAdminModal('addModal')">Add User</button>
        </div>
        <?php endif; ?>
    </div>

    <div class="search-wrap">
        <input type="text" class="search-input" id="searchInput" placeholder="Search by name, email, or phone..."/>
    </div>

    <?php if ($flash): ?>
        <div class="<?= $flash['type']==='error'?'validation-summary':'success-banner' ?>" style="display:none">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="table-card">
        <div class="table-wrap">
            <?php if (empty($users)): ?>
                <div class="empty-table">
                    <div class="empty-table-icon"><i class="fa-solid fa-users"></i></div>
                    <div class="empty-table-text">No users yet</div>
                    <div class="empty-table-sub">Click "Add User" to get started</div>
                </div>
            <?php else: ?>
                <table id="usersTable">
                    <thead>
                        <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><?php if ($is_admin): ?><th>Org</th><?php endif; ?><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $i => $u): ?>
                        <tr class="user-row">
                            <td><?= $i + 1 ?></td>
                            <td class="td-name"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><?= htmlspecialchars($u['phone']) ?></td>
                            <td>
                                <?php
                                $roleLabel = match($u['role'] ?? 'user') {
                                    'admin'     => '<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">ADMIN</span>',
                                    'org_admin' => '<span style="background:#fce7f3;color:#9d174d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">ORG ADMIN</span>',
                                    'moderator' => '<span style="background:#ede9fe;color:#7c3aed;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">MOD</span>',
                                    default     => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">USER</span>',
                                };
                                echo $roleLabel;
                                ?>
                            </td>
                            <td>
                                <?php
                                $st = $u['status'] ?? 'active';
                                $stLabel = match($st) {
                                    'active'    => '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">ACTIVE</span>',
                                    'pending'   => '<span style="background:#fef9c3;color:#a16207;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">PENDING</span>',
                                    'suspended' => '<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">SUSPENDED</span>',
                                    default     => '<span>' . htmlspecialchars($st) . '</span>',
                                };
                                echo $stLabel;
                                ?>
                            </td>
                            <?php if ($is_admin): ?>
                            <td><span class="org-badge"><?= htmlspecialchars($u['org_name'] ?? $u['ORG_NAME'] ?? '—') ?></span></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($is_admin || $is_org_admin): ?>
                                <div class="td-actions">
                                    <button class="btn-edit-user"
                                        data-id="<?= $u['user_id'] ?>"
                                        data-fname="<?= htmlspecialchars($u['first_name']) ?>"
                                        data-lname="<?= htmlspecialchars($u['last_name']) ?>"
                                        data-email="<?= htmlspecialchars($u['email']) ?>"
                                        data-phone="<?= htmlspecialchars($u['phone']) ?>"
                                        data-role="<?= htmlspecialchars($u['role'] ?? 'user') ?>"
                                        onclick="openEditModal(this)">Edit</button>
                                    <?php if (($u['status'] ?? 'active') !== 'suspended' && $u['user_id'] !== $uid): ?>
                                    <form method="post" action="TM_PHP/TM_UserActions.php" style="display:inline">
                                        <input type="hidden" name="action" value="suspend"/>
                                        <input type="hidden" name="id" value="<?= $u['user_id'] ?>"/>
                                        <button type="submit" style="padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;cursor:pointer;font-family:'Poppins',sans-serif;">Suspend</button>
                                    </form>
                                    <?php elseif (($u['status'] ?? '') === 'suspended'): ?>
                                    <form method="post" action="TM_PHP/TM_UserActions.php" style="display:inline">
                                        <input type="hidden" name="action" value="approve"/>
                                        <input type="hidden" name="id" value="<?= $u['user_id'] ?>"/>
                                        <button type="submit" style="padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;border:1px solid #6ee7b7;background:#ecfdf5;color:#065f46;cursor:pointer;font-family:'Poppins',sans-serif;">Re-activate</button>
                                    </form>
                                    <?php endif; ?>
                                    <button class="btn-delete-user"
                                        data-userid="<?= $u['user_id'] ?>"
                                        data-username="<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>"
                                        onclick="openDeleteUserModal(this)">Delete</button>
                                </div>
                                <?php else: ?>
                                <span style="font-size:12px;color:#9ca3af">Read-only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- /tab-panel#tab-users -->

    <!-- ══════════════════════════════════════════
         TAB: ORGANIZATIONS (Feature 6)
         Visible to system admins only.
         ══════════════════════════════════════════ -->
    <?php if ($is_admin): ?>
    <div class="tab-panel" id="tab-orgs">
    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px 18px;margin-bottom:18px;font-size:13px;color:#6b7280;">
        <strong style="color:#111;">Organizations</strong> — Create and manage organizations (tenants). Each organization is an isolated workspace. You can rename orgs and delete empty ones. Users can be moved between organizations from the Users tab.
    </div>

        <div class="admin-bar">
            <span class="admin-badge">Organizations</span>
            <button class="btn-add-user" onclick="openAdminModal('addOrgModal')">+ New Organization</button>
        </div>

        <?php if (empty($orgs)): ?>
        <div class="org-empty">
            <div class="org-empty-icon"><i class="fa-solid fa-building"></i></div>
            <div class="org-empty-text">No organizations yet</div>
            <div class="org-empty-sub">Click "New Organization" to create one</div>
        </div>
        <?php else: ?>
        <div class="org-grid">
            <?php foreach ($orgs as $org):
                $memberCount = (int)($org['member_count'] ?? 0);
                $isDefault  = ((int)$org['org_id'] === 1);
            ?>
            <div class="org-card">
                <div class="org-card-header">
                    <div class="org-card-name"><?= htmlspecialchars($org['org_name']) ?></div>
                </div>
                <div class="org-card-meta">
                    <span><i class="fa-solid fa-users" style="font-size:11px;margin-right:3px;"></i><?= $memberCount ?> member<?= $memberCount !== 1 ? 's' : '' ?></span>
                    <span><i class="fa-regular fa-calendar" style="font-size:11px;margin-right:3px;"></i><?= htmlspecialchars($org['created_at']) ?></span>
                    <?php if ($isDefault): ?>
                    <span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-star" style="font-size:11px;margin-right:3px;"></i>Default</span>
                    <?php endif; ?>
                </div>
                <div class="org-card-actions">
                    <button class="btn-org-edit"
                        data-org-id="<?= $org['org_id'] ?>"
                        data-org-name="<?= htmlspecialchars($org['org_name']) ?>"
                        onclick="openEditOrgModal(this)">
                        <i class="fa-solid fa-pen" style="font-size:11px;margin-right:4px;"></i>Edit
                    </button>
                    <button class="btn-org-delete"
                        <?= $memberCount > 0 || $isDefault ? 'disabled title="' . ($isDefault ? 'Cannot delete the Default Org' : 'Transfer all members first') . '"' : '' ?>
                        data-org-id="<?= $org['org_id'] ?>"
                        data-org-name="<?= htmlspecialchars($org['org_name']) ?>"
                        onclick="openDeleteOrgModal(this)">
                        <i class="fa-solid fa-trash" style="font-size:11px;margin-right:4px;"></i>Delete
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Users with org assignment table ── -->
        <div style="margin-top:32px;">
            <div class="admin-bar" style="margin-bottom:12px;">
                <span class="admin-badge">User — Organization Assignment</span>
            </div>
            <div class="table-card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Organization</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $i => $u):
                            $uOrgName = $u['org_name'] ?? $u['ORG_NAME'] ?? '—';
                            $uOrgId   = (int)($u['org_id'] ?? $u['ORG_ID'] ?? 0);
                            $uRole    = $u['role'] ?? 'user';
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                            <td>
                                <?php
                                $roleBadge = match($uRole) {
                                    'admin'     => '<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">ADMIN</span>',
                                    'org_admin' => '<span style="background:#fce7f3;color:#9d174d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">ORG ADMIN</span>',
                                    'moderator' => '<span style="background:#ede9fe;color:#7c3aed;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">MOD</span>',
                                    default     => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">USER</span>',
                                };
                                echo $roleBadge;
                                ?>
                            </td>
                            <td><span class="org-badge"><?= htmlspecialchars($uOrgName) ?></span></td>
                            <td>
                                <?php if ($u['user_id'] !== $uid): ?>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button class="btn-org-edit"
                                        data-uid="<?= $u['user_id'] ?>"
                                        data-uname="<?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>"
                                        data-current-org="<?= $uOrgId ?>"
                                        data-current-role="<?= htmlspecialchars($uRole) ?>"
                                        onclick="openTransferModal(this)">
                                        <i class="fa-solid fa-right-left" style="font-size:11px;margin-right:4px;"></i>Transfer
                                    </button>
                                    <button class="btn-org-edit"
                                        data-uid="<?= $u['user_id'] ?>"
                                        data-uname="<?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>"
                                        data-current-role="<?= htmlspecialchars($uRole) ?>"
                                        onclick="openRoleModal(this)"
                                        style="background:#fdf4ff;border-color:#e9d5ff;color:#7c3aed;">
                                        <i class="fa-solid fa-shield-halved" style="font-size:11px;margin-right:4px;"></i>Set Role
                                    </button>
                                </div>
                                <?php else: ?>
                                <span style="font-size:12px;color:#9ca3af">You</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /tab-panel#tab-orgs -->
    <?php endif; ?>
    <!-- ══════════════════════════════════════════
         FEATURE 8 — TEAMS TAB
         ══════════════════════════════════════════ -->
    <?php if ($is_admin || $is_org_admin): ?>
    <div class="tab-panel" id="tab-teams">
    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px 18px;margin-bottom:18px;font-size:13px;color:#6b7280;">
        <strong style="color:#111;">Teams</strong> — Create teams to group users within an organization for collaborative task assignments. You can add or remove members from a team at any time.
    </div>

        <div class="admin-bar">
            <span class="admin-badge">Teams</span>
            <button class="btn-add-user" onclick="openAdminModal('addTeamModal')">+ New Team</button>
        </div>

        <?php if (empty($teams)): ?>
        <div class="org-empty">
            <div class="org-empty-icon"><i class="fa-solid fa-people-group"></i></div>
            <div class="org-empty-text">No teams yet</div>
            <div class="org-empty-sub">Click "New Team" to create one</div>
        </div>
        <?php else: ?>
        <div class="org-grid">
            <?php foreach ($teams as $team): ?>
            <div class="org-card">
                <div class="org-card-header">
                    <div class="org-card-name"><?= htmlspecialchars($team['team_name']) ?></div>
                    <span class="org-badge"><?= htmlspecialchars($team['org_name']) ?></span>
                </div>
                <?php if (!empty($team['team_desc'])): ?>
                <div style="font-size:12px;color:var(--gray-300,#9ca3af);margin-bottom:8px;line-height:1.5;">
                    <?= htmlspecialchars($team['team_desc']) ?>
                </div>
                <?php endif; ?>
                <div class="org-card-meta">
                    <span><i class="fa-solid fa-users" style="font-size:11px;margin-right:3px;"></i><?= $team['member_count'] ?> member<?= $team['member_count'] !== 1 ? 's' : '' ?></span>
                    <span><i class="fa-regular fa-calendar" style="font-size:11px;margin-right:3px;"></i><?= htmlspecialchars($team['created_at']) ?></span>
                </div>
                <div class="org-card-actions">
                    <button class="btn-org-edit"
                        data-team-id="<?= $team['team_id'] ?>"
                        data-team-name="<?= htmlspecialchars($team['team_name']) ?>"
                        data-team-desc="<?= htmlspecialchars($team['team_desc']) ?>"
                        onclick="openEditTeamModal(this)">
                        <i class="fa-solid fa-pen" style="font-size:11px;margin-right:4px;"></i>Edit
                    </button>
                    <button class="btn-org-edit" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;"
                        data-team-id="<?= $team['team_id'] ?>"
                        data-team-name="<?= htmlspecialchars($team['team_name']) ?>"
                        onclick="openManageMembersModal(this)">
                        <i class="fa-solid fa-user-plus" style="font-size:11px;margin-right:4px;"></i>Members
                    </button>
                    <button class="btn-org-delete"
                        data-team-id="<?= $team['team_id'] ?>"
                        data-team-name="<?= htmlspecialchars($team['team_name']) ?>"
                        onclick="openDeleteTeamModal(this)">
                        <i class="fa-solid fa-trash" style="font-size:11px;margin-right:4px;"></i>Delete
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Team × Member breakdown table -->
        <div style="margin-top:32px;">
            <div class="admin-bar" style="margin-bottom:12px;">
                <span class="admin-badge">Team Membership</span>
            </div>
            <div class="table-card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Team</th>
                                <?php if ($is_admin): ?><th>Org</th><?php endif; ?>
                                <th>Member</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $memberRows = [];
                        foreach ($teams as $team) {
                            $mStmt = tm_exec(
                                "SELECT u.user_id, u.first_name, u.last_name, u.email,
                                        tm.is_manager, tm.team_id
                                 FROM TM_TeamMembers tm
                                 JOIN TM_Users u ON u.user_id = tm.user_id
                                 WHERE tm.team_id = :p1
                                 ORDER BY tm.is_manager DESC, u.first_name ASC",
                                [$team['team_id']]
                            );
                            $members = tm_fetch_all($mStmt);
                            foreach ($members as $m) {
                                $memberRows[] = [
                                    'team_id'    => $team['team_id'],
                                    'team_name'  => $team['team_name'],
                                    'org_name'   => $team['org_name'],
                                    'user_id'    => (int)($m['user_id']    ?? $m['USER_ID']    ?? 0),
                                    'first_name' => $m['first_name'] ?? $m['FIRST_NAME'] ?? '',
                                    'last_name'  => $m['last_name']  ?? $m['LAST_NAME']  ?? '',
                                    'email'      => $m['email']      ?? $m['EMAIL']      ?? '',
                                    'is_manager' => (int)($m['is_manager'] ?? $m['IS_MANAGER'] ?? 0),
                                ];
                            }
                        }
                        if (empty($memberRows)):
                        ?>
                        <tr><td colspan="<?= $is_admin ? 6 : 5 ?>" class="empty-table" style="padding:40px 0;">
                            <div class="empty-table-icon"><i class="fa-solid fa-user-slash"></i></div>
                            <div class="empty-table-text">No members assigned to any team</div>
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($memberRows as $i => $mr): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($mr['team_name']) ?></td>
                            <?php if ($is_admin): ?>
                            <td><span class="org-badge"><?= htmlspecialchars($mr['org_name']) ?></span></td>
                            <?php endif; ?>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars($mr['first_name'] . ' ' . $mr['last_name']) ?></div>
                                <div style="font-size:11px;color:var(--gray-300,#9ca3af);"><?= htmlspecialchars($mr['email']) ?></div>
                            </td>
                            <td>
                                <?php if ($mr['is_manager']): ?>
                                <span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">MANAGER</span>
                                <?php else: ?>
                                <span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">MEMBER</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="td-actions">
                                    <form method="post" action="TM_PHP/TM_TeamActions.php" style="display:inline">
                                        <input type="hidden" name="action" value="set_manager"/>
                                        <input type="hidden" name="team_id" value="<?= $mr['team_id'] ?>"/>
                                        <input type="hidden" name="user_id" value="<?= $mr['user_id'] ?>"/>
                                        <input type="hidden" name="is_manager" value="<?= $mr['is_manager'] ? 0 : 1 ?>"/>
                                        <button type="submit" class="btn-edit-user" title="<?= $mr['is_manager'] ? 'Demote to member' : 'Promote to manager' ?>">
                                            <?= $mr['is_manager'] ? 'Demote' : 'Promote' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="TM_PHP/TM_TeamActions.php" style="display:inline">
                                        <input type="hidden" name="action" value="remove_member"/>
                                        <input type="hidden" name="team_id" value="<?= $mr['team_id'] ?>"/>
                                        <input type="hidden" name="user_id" value="<?= $mr['user_id'] ?>"/>
                                        <button type="submit" class="btn-delete-user"
                                            onclick="return confirm('Remove <?= htmlspecialchars(addslashes($mr['first_name'] . ' ' . $mr['last_name'])) ?> from this team?')">
                                            Remove
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /tab-panel#tab-teams -->
    <?php endif; ?>

</main>

<!-- ── ADD USER MODAL ── -->
<div class="modal-overlay" id="addModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Add New User</div>
            <button class="modal-close" onclick="closeAdminModal('addModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_UserActions.php" id="addUserForm">
            <input type="hidden" name="action" value="add"/>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="firstName" class="form-input" id="add_fname" placeholder="Juan" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="lastName" class="form-input" id="add_lname" placeholder="Dela Cruz" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input" id="add_email" placeholder="juan@email.com" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-input" id="add_phone" placeholder="09XXXXXXXXX" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" id="add_password" placeholder="Min. 6 characters" required/>
                </div>
                <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" class="form-input">
                    <option value="user">User</option>
                    <option value="moderator">Moderator</option>
                    <option value="org_admin">Org Admin</option>
                    <?php if ($is_admin): ?>
                    <option value="admin">Admin</option>
                    <?php endif; ?>
                </select>
                </div>
                <?php if ($is_admin && !empty($orgs)): ?>
                <div class="form-group">
                    <label class="form-label">Organization</label>
                    <select name="org_id" class="form-input">
                        <?php foreach ($orgs as $org): ?>
                        <option value="<?= $org['org_id'] ?>"><?= htmlspecialchars($org['org_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('addModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div class="modal-overlay" id="editModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Edit User</div>
            <button class="modal-close" onclick="closeAdminModal('editModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_UserActions.php" id="editUserForm">
            <input type="hidden" name="action" value="edit"/>
            <input type="hidden" name="id" id="edit_id"/>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="firstName" class="form-input" id="edit_fname" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="lastName" class="form-input" id="edit_lname" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email <span style="font-size:11px;font-weight:400;color:#9ca3af">(read-only)</span></label>
                    <input type="text" class="form-input" id="edit_email_display"
                           style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed" readonly/>
                    <input type="hidden" name="email" id="edit_email_hidden"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone <span style="font-size:11px;font-weight:400;color:#9ca3af">(read-only)</span></label>
                    <input type="tel" class="form-input" id="edit_phone_display"
                           style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed" readonly/>
                    <input type="hidden" name="phone" id="edit_phone_hidden"/>
                </div>
                <div class="form-group">
                <label class="form-label">Role <span style="font-size:11px;font-weight:400;color:#9ca3af">(read-only — use "Set Role" button to change)</span></label>
                <input type="text" class="form-input" id="edit_role_display"
                       style="background:#f3f4f6;color:#6b7280;cursor:not-allowed" readonly/>
                <!-- Pass current role through so the action always has a value -->
                <input type="hidden" name="role" id="edit_role"/>
                </div>
                <div class="form-group">
                    <label class="form-label">New Password <span style="font-size:11px;font-weight:400;color:#9ca3af">(leave blank to keep current)</span></label>
                    <input type="password" name="password" class="form-input" placeholder="Enter new password"/>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('editModal')">Cancel</button>
                <button type="button" class="btn-save-modal" onclick="openPcModal('saveUserModal')">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SAVE USER PC-MODAL ── -->
<div id="saveUserModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(59,130,246,.12)">
            <i class="fa-solid fa-floppy-disk" style="color:#3b82f6"></i>
        </div>
        <div class="pc-modal-title">Save Changes?</div>
        <div class="pc-modal-body">Are you sure you want to save changes to this user's profile?</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('saveUserModal')">Cancel</button>
            <button class="pc-modal-confirm-blue" onclick="document.getElementById('editUserForm').submit()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ── DELETE USER PC-MODAL ── -->
<div id="deleteUserModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(239,68,68,.12)">
            <i class="fa-solid fa-trash" style="color:#ef4444"></i>
        </div>
        <div class="pc-modal-title">Delete User?</div>
        <div class="pc-modal-body">
            Are you sure you want to delete <strong id="deleteUserName"></strong>?
            This action <strong>cannot be undone</strong>.
        </div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('deleteUserModal')">Cancel</button>
            <button class="pc-modal-confirm-red" onclick="document.getElementById('deleteUserForm').submit()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<!-- ── LOGOUT PC-MODAL ── -->
<div id="logoutModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(231,76,60,.12)">
            <i class="fa-solid fa-arrow-right-from-bracket" style="color:#e74c3c"></i>
        </div>
        <div class="pc-modal-title">Log Out?</div>
        <div class="pc-modal-body">You'll need to sign in again to access your tasks.</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('logoutModal')">Cancel</button>
            <a href="TM_PHP/TM_AuthActions.php?action=logout" class="pc-modal-confirm-red">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out
            </a>
        </div>
    </div>
</div>



<form method="post" action="TM_PHP/TM_UserActions.php" id="deleteUserForm" style="display:none">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="id" id="deleteUserId"/>
</form>



<!-- ══════════════════════════════════════════════════════════
     FEATURE 6 — ORGANIZATION MODALS
     ══════════════════════════════════════════════════════════ -->

<!-- ── ADD ORG MODAL ── -->
<div class="modal-overlay" id="addOrgModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">New Organization</div>
            <button class="modal-close" onclick="closeAdminModal('addOrgModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_OrgActions.php">
            <input type="hidden" name="action" value="create_org"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Organization Name</label>
                    <input type="text" name="org_name" class="form-input"
                           placeholder="e.g. Acme Corp" required/>
                </div>
                <div class="form-group">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('addOrgModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Create Organization</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT ORG MODAL ── -->
<div class="modal-overlay" id="editOrgModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Edit Organization</div>
            <button class="modal-close" onclick="closeAdminModal('editOrgModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_OrgActions.php">
            <input type="hidden" name="action" value="edit_org"/>
            <input type="hidden" name="org_id" id="editOrgId"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Organization Name</label>
                    <input type="text" name="org_name" class="form-input" id="editOrgName" required/>
                </div>
                <div class="form-group">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('editOrgModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── DELETE ORG PC-MODAL ── -->
<div id="deleteOrgModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(239,68,68,.12)">
            <i class="fa-solid fa-building-circle-xmark" style="color:#ef4444"></i>
        </div>
        <div class="pc-modal-title">Delete Organization?</div>
        <div class="pc-modal-body">
            Delete <strong id="deleteOrgName"></strong>?
            This <strong>cannot be undone</strong>. All members must be transferred first.
        </div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('deleteOrgModal')">Cancel</button>
            <button class="pc-modal-confirm-red" onclick="document.getElementById('deleteOrgForm').submit()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>
<form method="post" action="TM_PHP/TM_OrgActions.php" id="deleteOrgForm" style="display:none">
    <input type="hidden" name="action" value="delete_org"/>
    <input type="hidden" name="org_id" id="deleteOrgId"/>
</form>

<!-- ── TRANSFER USER MODAL ── -->
<div class="modal-overlay" id="transferUserModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Transfer User to Organization</div>
            <button class="modal-close" onclick="closeAdminModal('transferUserModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_OrgActions.php">
            <input type="hidden" name="action" value="transfer_user"/>
            <input type="hidden" name="user_id" id="transferUserId"/>
            <div class="modal-body">
                <p style="font-size:13px;color:#6b7280;margin:0 0 16px">
                    Transferring <span class="transfer-user-name" id="transferUserName"></span>
                    — all their tasks will move to the new organization too.
                </p>
                <div class="form-group">
                    <label class="form-label">Move to Organization</label>
                    <select name="new_org_id" class="modal-select" id="transferOrgSelect">
                        <?php foreach ($orgs as $org): ?>
                        <option value="<?= $org['org_id'] ?>"><?= htmlspecialchars($org['org_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('transferUserModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SET ROLE MODAL ── -->
<div class="modal-overlay" id="setRoleModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-shield-halved" style="margin-right:6px;"></i>Set User Role</div>
            <button class="modal-close" onclick="closeAdminModal('setRoleModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_OrgActions.php">
            <input type="hidden" name="action" value="set_org_admin"/>
            <input type="hidden" name="user_id" id="setRoleUserId"/>
            <div class="modal-body">
                <p style="font-size:13px;color:#6b7280;margin:0 0 16px">
                    Changing role for <span class="transfer-user-name" id="setRoleUserName"></span>.
                </p>
                <div class="form-group">
                    <label class="form-label">New Role</label>
                    <select name="new_role" class="modal-select" id="setRoleSelect">
                        <option value="user">User — standard task access</option>
                        <option value="moderator">Moderator — can view admin panel</option>
                        <option value="org_admin">Org Admin — manages their own org's users</option>
                        <option value="admin">Admin — full system access</option>
                    </select>
                </div>
                <div style="margin-top:12px;padding:10px 14px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;font-size:12px;color:#92400e;">
                    <i class="fa-solid fa-triangle-exclamation" style="margin-right:5px;"></i><strong>Org Admin</strong> can add, edit, suspend, and delete users within their own organization only.
                    <strong>Admin</strong> has unrestricted access to the entire system.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('setRoleModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Save Role</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     FEATURE 8 — TEAM MODALS
     ══════════════════════════════════════════════════════════ -->

<!-- ── ADD TEAM MODAL ── -->
<div class="modal-overlay" id="addTeamModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">New Team</div>
            <button class="modal-close" onclick="closeAdminModal('addTeamModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TeamActions.php">
            <input type="hidden" name="action" value="create_team"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Team Name</label>
                    <input type="text" name="team_name" class="form-input"
                           placeholder="e.g. Engineering" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Description <span style="font-size:11px;font-weight:400;color:#9ca3af">(optional)</span></label>
                    <input type="text" name="description" class="form-input"
                           placeholder="What does this team do?"/>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('addTeamModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Create Team</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT TEAM MODAL ── -->
<div class="modal-overlay" id="editTeamModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Edit Team</div>
            <button class="modal-close" onclick="closeAdminModal('editTeamModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TeamActions.php">
            <input type="hidden" name="action" value="edit_team"/>
            <input type="hidden" name="team_id" id="editTeamId"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Team Name</label>
                    <input type="text" name="team_name" class="form-input" id="editTeamName" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Description <span style="font-size:11px;font-weight:400;color:#9ca3af">(optional)</span></label>
                    <input type="text" name="description" class="form-input" id="editTeamDesc"/>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('editTeamModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── DELETE TEAM PC-MODAL ── -->
<div id="deleteTeamModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(239,68,68,.12)">
            <i class="fa-solid fa-people-group" style="color:#ef4444"></i>
        </div>
        <div class="pc-modal-title">Delete Team?</div>
        <div class="pc-modal-body">
            Delete <strong id="deleteTeamName"></strong>?
            All members will be removed. This <strong>cannot be undone</strong>.
        </div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('deleteTeamModal')">Cancel</button>
            <button class="pc-modal-confirm-red" onclick="document.getElementById('deleteTeamForm').submit()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>
<form method="post" action="TM_PHP/TM_TeamActions.php" id="deleteTeamForm" style="display:none">
    <input type="hidden" name="action" value="delete_team"/>
    <input type="hidden" name="team_id" id="deleteTeamId"/>
</form>

<!-- ── MANAGE MEMBERS MODAL ── -->
<div class="modal-overlay" id="manageMembersModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Manage Members — <span id="manageMembersTeamName"></span></div>
            <button class="modal-close" onclick="closeAdminModal('manageMembersModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TeamActions.php">
            <input type="hidden" name="action" value="add_member"/>
            <input type="hidden" name="team_id" id="manageMembersTeamId"/>
            <div class="modal-body">
                <p style="font-size:13px;color:#6b7280;margin:0 0 16px">
                    Add a user from your organization to this team.
                </p>
                <div class="form-group">
                    <label class="form-label">Select User</label>
                    <select name="member_user_id" class="modal-select" required>
                        <option value="">— choose a user —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>">
                            <?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '') . ' (' . ($u['email'] ?? '') . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:12px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--black,#111);cursor:pointer;">
                        <input type="checkbox" name="is_manager" value="1" style="width:16px;height:16px;cursor:pointer;"/>
                        Add as team manager
                    </label>
                </div>
                <div style="margin-top:12px;padding:10px 14px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;font-size:12px;color:#92400e;">
                    <i class="fa-solid fa-triangle-exclamation" style="margin-right:5px;"></i>Users already on this team will be skipped.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAdminModal('manageMembersModal')">Cancel</button>
                <button type="submit" class="btn-save-modal">Add Member</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────────────────────────
function openAdminModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('active');
}
function closeAdminModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('active');
}
function openPcModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('active');
}
function closePcModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

// ── Edit User modal ───────────────────────────────────────────────────────────
function openEditModal(btn) {
    document.getElementById('edit_id').value             = btn.dataset.id;
    document.getElementById('edit_fname').value          = btn.dataset.fname;
    document.getElementById('edit_lname').value          = btn.dataset.lname;
    document.getElementById('edit_email_display').value  = btn.dataset.email;
    document.getElementById('edit_email_hidden').value   = btn.dataset.email;
    document.getElementById('edit_phone_display').value  = btn.dataset.phone;
    document.getElementById('edit_phone_hidden').value   = btn.dataset.phone;
    // Role is read-only in this modal — shown for context, changed via "Set Role" button
    var roleVal = btn.dataset.role || 'user';
    var roleLabels = { user: 'User', moderator: 'Moderator', org_admin: 'Org Admin', admin: 'Admin' };
    var roleDisplay = document.getElementById('edit_role_display');
    var roleHidden  = document.getElementById('edit_role');
    if (roleDisplay) roleDisplay.value = roleLabels[roleVal] || roleVal;
    if (roleHidden)  roleHidden.value  = roleVal;
    openAdminModal('editModal');
}

// ── Delete User modal ─────────────────────────────────────────────────────────
function openDeleteUserModal(btn) {
    document.getElementById('deleteUserName').textContent = btn.dataset.username;
    document.getElementById('deleteUserId').value         = btn.dataset.userid;
    openPcModal('deleteUserModal');
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(panelId, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.admin-tab').forEach(function(b) { b.classList.remove('active'); });
    var panel = document.getElementById(panelId);
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');
    // Update URL hash so page reload lands on correct tab
    history.replaceState(null, '', '#' + panelId.replace('tab-', ''));
}

// ── Auto-activate correct tab from URL hash on load ───────────────────────────
(function() {
    var hash = window.location.hash;
    if (hash === '#orgs') {
        var orgTab = document.querySelector('[onclick*="tab-orgs"]');
        if (orgTab) switchTab('tab-orgs', orgTab);
    } else if (hash === '#teams') {
        var teamsTab = document.querySelector('[onclick*="tab-teams"]');
        if (teamsTab) switchTab('tab-teams', teamsTab);
    }
})();

// ── Edit Org modal ────────────────────────────────────────────────────────────
function openEditOrgModal(btn) {
    document.getElementById('editOrgId').value   = btn.dataset.orgId;
    document.getElementById('editOrgName').value = btn.dataset.orgName;
    openAdminModal('editOrgModal');
}

// ── Delete Org modal ──────────────────────────────────────────────────────────
function openDeleteOrgModal(btn) {
    if (btn.disabled) return;
    document.getElementById('deleteOrgId').value          = btn.dataset.orgId;
    document.getElementById('deleteOrgName').textContent  = btn.dataset.orgName;
    openPcModal('deleteOrgModal');
}

// ── Transfer User modal ───────────────────────────────────────────────────────
function openTransferModal(btn) {
    document.getElementById('transferUserId').value        = btn.dataset.uid;
    document.getElementById('transferUserName').textContent = btn.dataset.uname;
    // Pre-select user's current org in the dropdown
    var sel = document.getElementById('transferOrgSelect');
    if (sel) sel.value = btn.dataset.currentOrg || '';
    openAdminModal('transferUserModal');
}

// ── Set Role modal ────────────────────────────────────────────────────────────
function openRoleModal(btn) {
    document.getElementById('setRoleUserId').value          = btn.dataset.uid;
    document.getElementById('setRoleUserName').textContent  = btn.dataset.uname;
    var sel = document.getElementById('setRoleSelect');
    if (sel) sel.value = btn.dataset.currentRole || 'user';
    openAdminModal('setRoleModal');
}

// ── Edit Team modal ───────────────────────────────────────────────────────────
function openEditTeamModal(btn) {
    document.getElementById('editTeamId').value   = btn.dataset.teamId;
    document.getElementById('editTeamName').value = btn.dataset.teamName;
    document.getElementById('editTeamDesc').value = btn.dataset.teamDesc || '';
    openAdminModal('editTeamModal');
}

// ── Delete Team modal ─────────────────────────────────────────────────────────
function openDeleteTeamModal(btn) {
    document.getElementById('deleteTeamId').value          = btn.dataset.teamId;
    document.getElementById('deleteTeamName').textContent  = btn.dataset.teamName;
    openPcModal('deleteTeamModal');
}

// ── Manage Members modal ──────────────────────────────────────────────────────
function openManageMembersModal(btn) {
    document.getElementById('manageMembersTeamId').value          = btn.dataset.teamId;
    document.getElementById('manageMembersTeamName').textContent  = btn.dataset.teamName;
    openAdminModal('manageMembersModal');
}

// ── Logout confirmation ────────────────────────────────────────────────────
(function () {
    var btn    = document.getElementById('logoutBtn');
    var modal  = document.getElementById('logoutModal');
    if (btn && modal) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openPcModal('logoutModal');
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closePcModal('logoutModal');
        });
    }
})();

</script>
