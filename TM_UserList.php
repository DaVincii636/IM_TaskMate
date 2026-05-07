<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_role('moderator');

$flash     = tm_get_flash();
$userName  = tm_uname();
$stmt = tm_exec('SELECT user_id, first_name, last_name, email, phone, role FROM TM_Users ORDER BY user_id DESC');
$users     = tm_fetch_all($stmt);
$userCount = count($users);
$is_admin = tm_is_admin();
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
        .table-card{background:var(--white,#fff);border-radius:var(--radius-lg,16px);border:1px solid var(--border,#e5e7eb);overflow:hidden;}
        .table-wrap{overflow-x:auto;}
        .empty-table{text-align:center;padding:60px 20px;}
        .empty-table-icon{font-size:2.5rem;margin-bottom:.75rem;}
        .empty-table-text{font-size:1rem;font-weight:700;color:var(--black,#111);margin-bottom:.25rem;}
        .empty-table-sub{font-size:13px;color:var(--gray-300,#9ca3af);}
        /* Modal overlay (Add/Edit) */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;align-items:center;justify-content:center;}
        .modal-overlay.active{display:flex;}
        .modal-card{background:var(--white,#fff);border-radius:var(--radius-lg,16px);width:90%;max-width:480px;
                    box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;}
        .modal-header{display:flex;align-items:center;justify-content:space-between;
                      padding:1.25rem 1.5rem;background:var(--black,#111);color:#fff;}
        .modal-title{font-size:1rem;font-weight:700;}
        .modal-close{background:none;border:none;color:#fff;font-size:1.1rem;cursor:pointer;line-height:1;}
        .modal-body{padding:1.5rem;}
        .modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:1rem 1.5rem;
                      border-top:1px solid var(--border,#e5e7eb);}
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
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="#" class="btn-logout" id="logoutBtn">Log Out</a>
    </div>
</nav>

<main class="main-container">

    <div class="page-header">
        <div class="page-title">Admin Panel</div>
        <div class="page-subtitle">Manage all registered users and view system stats.</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= $userCount ?></div>
            <div class="stat-desc">Registered accounts</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">System Status</div>
            <div class="stat-value" style="font-size:28px;letter-spacing:-.5px">Active</div>
            <div class="stat-desc">All systems running</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Session</div>
            <div class="stat-value" style="font-size:28px;letter-spacing:-.5px">Live</div>
            <div class="stat-desc">Admin is logged in</div>
        </div>
    </div>

    <div class="admin-bar">
        <span class="admin-badge">⚙ User List</span>
        <?php if ($is_admin): ?>
        <button class="btn-add-user" onclick="openAdminModal('addModal')">Add User</button>
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
                    <div class="empty-table-icon">👥</div>
                    <div class="empty-table-text">No users yet</div>
                    <div class="empty-table-sub">Click "Add User" to get started</div>
                </div>
            <?php else: ?>
                <table id="usersTable">
                    <thead>
                        <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Actions</th></tr>
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
                                    'moderator' => '<span style="background:#ede9fe;color:#7c3aed;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">MOD</span>',
                                    default     => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">USER</span>',
                                };
                                echo $roleLabel;
                                ?>
                            </td>
                            <td>
                                <?php if ($is_admin): ?>
                                <div class="td-actions">
                                    <button class="btn-edit-user"
                                        data-id="<?= $u['user_id'] ?>"
                                        data-fname="<?= htmlspecialchars($u['first_name']) ?>"
                                        data-lname="<?= htmlspecialchars($u['last_name']) ?>"
                                        data-email="<?= htmlspecialchars($u['email']) ?>"
                                        data-phone="<?= htmlspecialchars($u['phone']) ?>"
                                        data-role="<?= htmlspecialchars($u['role'] ?? 'user') ?>"
                                        onclick="openEditModal(this)">Edit</button>
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

</main>

<!-- ── ADD USER MODAL ── -->
<div class="modal-overlay" id="addModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Add New User</div>
            <button class="modal-close" onclick="closeAdminModal('addModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_UserActions.php">
            <input type="hidden" name="action" value="add"/>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="firstName" class="form-input" placeholder="Juan" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="lastName" class="form-input" placeholder="Dela Cruz" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input" placeholder="juan@email.com" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-input" placeholder="09XXXXXXXXX" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Min. 6 characters" required/>
                </div>
                <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" class="form-input">
                    <option value="user">User</option>
                    <option value="moderator">Moderator</option>
                    <option value="admin">Admin</option>
                </select>
                </div>
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
    <div class="modal-card">
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
                <label class="form-label">Role</label>
                <select name="role" class="form-input" id="edit_role">
                    <option value="user">User</option>
                    <option value="moderator">Moderator</option>
                    <option value="admin">Admin</option>
                </select>
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

<div class="toast" id="toast"></div>

<script>
    // Admin modal helpers (separate from Calendar modal helpers)
    function openAdminModal(id)  { document.getElementById(id).classList.add('active'); }
    function closeAdminModal(id) { document.getElementById(id).classList.remove('active'); }

    // pc-modal helpers
    function openPcModal(id)  { document.getElementById(id).classList.add('active'); }
    function closePcModal(id) { document.getElementById(id).classList.remove('active'); }

    // Close on backdrop click
    document.querySelectorAll('.modal-overlay, .pc-modal-overlay').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target === el) el.classList.remove('active');
        });
    });

    // Search filter
    document.getElementById('searchInput').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('tbody tr.user-row').forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    // Edit button
    function openEditModal(btn) {
        document.getElementById('edit_id').value            = btn.dataset.id;
        document.getElementById('edit_fname').value         = btn.dataset.fname;
        document.getElementById('edit_lname').value         = btn.dataset.lname;
        document.getElementById('edit_email_display').value = btn.dataset.email;
        document.getElementById('edit_email_hidden').value  = btn.dataset.email;
        document.getElementById('edit_phone_display').value = btn.dataset.phone;
        document.getElementById('edit_phone_hidden').value  = btn.dataset.phone;
        document.getElementById('edit_role').value          = btn.dataset.role || 'user'; // ← NEW
        openAdminModal('editModal');
    }

    // Delete button
    function openDeleteUserModal(btn) {
        document.getElementById('deleteUserId').value          = btn.dataset.userid;
        document.getElementById('deleteUserName').textContent  = btn.dataset.username;
        openPcModal('deleteUserModal');
    }

    // Logout btn
    document.getElementById('logoutBtn').addEventListener('click', function(e) {
        e.preventDefault();
        openPcModal('logoutModal');
    });
</script>
<script src="TM_JS/TM_App.js"></script>
</body>
</html>