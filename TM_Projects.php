<?php
/**
 * TM_Projects.php
 * ─────────────────────────────────────────────────────────────
 * Projects management page.
 * Every logged-in user can:
 *   - View and create projects they own or belong to
 *   - Invite / remove members from projects they own
 *   - Edit or delete projects they own
 *
 * Non-owners see their project memberships as read-only.
 */
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$uid   = tm_uid();
$flash = tm_get_flash();

require_once 'TM_PHP/TM_NavNotif.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Projects - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        /* ── Project grid & cards ─────────────────────────────── */
        .proj-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
            margin-top: 4px;
        }
        .proj-card {
            background: var(--white, #fff);
            border: 1.5px solid var(--border, #e5e7eb);
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: box-shadow .2s, transform .15s;
        }
        .proj-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.09); transform: translateY(-1px); }
        .proj-card-bar { height: 5px; }
        .proj-card-body { padding: 18px 20px 14px; flex: 1; }
        .proj-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
        }
        .proj-card-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--black, #111);
            line-height: 1.3;
        }
        .proj-role-badge {
            flex-shrink: 0;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
            padding: 2px 8px;
            border-radius: 50px;
            background: #f3f4f6;
            color: #6b7280;
        }
        .proj-role-badge.owner {
            background: #fef9c3;
            color: #92400e;
        }
        .proj-card-desc {
            font-size: 13px;
            color: var(--gray-500, #6b7280);
            line-height: 1.55;
            margin-bottom: 14px;
            min-height: 20px;
        }
        .proj-meta {
            display: flex;
            gap: 16px;
            font-size: 12px;
            color: var(--gray-400, #9ca3af);
            margin-bottom: 14px;
        }
        .proj-meta i { margin-right: 4px; }
        .proj-card-footer {
            padding: 10px 20px 14px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            border-top: 1px solid var(--border, #e5e7eb);
        }
        /* ── Member chips inside card ─────────────────────────── */
        .proj-member-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
            min-height: 26px;
        }
        .proj-member-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--bg, #f9fafb);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 50px;
            padding: 3px 10px 3px 8px;
            font-size: 12px;
            font-weight: 500;
            color: var(--black, #111);
        }
        .proj-member-chip .chip-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-400, #9ca3af);
            padding: 0;
            margin-left: 2px;
            font-size: 10px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
        }
        .proj-member-chip .chip-remove:hover { color: #ef4444; }
        /* ── Action buttons ───────────────────────────────────── */
        .btn-proj {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid var(--border, #e5e7eb);
            background: var(--bg, #f9fafb);
            color: var(--black, #111);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-proj:hover { background: var(--border, #e5e7eb); }
        .btn-proj-danger {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #ef4444;
        }
        .btn-proj-danger:hover { background: #fee2e2; }
        .btn-proj-primary {
            background: var(--black, #111);
            color: #fff;
            border-color: var(--black, #111);
        }
        .btn-proj-primary:hover { opacity: .85; }
        /* ── Empty state ──────────────────────────────────────── */
        .proj-empty {
            text-align: center;
            padding: 70px 20px;
            color: var(--gray-300, #9ca3af);
        }
        .proj-empty-icon { font-size: 3rem; margin-bottom: .75rem; }
        .proj-empty-title { font-size: 1rem; font-weight: 700; color: var(--black, #111); margin-bottom: .35rem; }
        .proj-empty-sub { font-size: 13px; }
        /* ── Admin bar ────────────────────────────────────────── */
        .proj-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        /* ── Modals ───────────────────────────────────────────── */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
                         z-index:600; align-items:center; justify-content:center; }
        .modal-overlay.active { display:flex; }
        .modal-card { background:var(--white,#fff); border-radius:14px; width:100%;
                      max-width:400px; box-shadow:0 16px 48px rgba(0,0,0,.16);
                      max-height:88vh; overflow-y:auto; }
        .modal-header { display:flex; align-items:center; justify-content:space-between;
                        padding:16px 20px 0; }
        .modal-title { font-size:15px; font-weight:700; color:var(--black,#111); }
        .modal-close { background:none; border:none; font-size:17px; cursor:pointer;
                       color:var(--gray-400,#9ca3af); line-height:1; padding:4px; }
        .modal-close:hover { color:var(--black,#111); }
        .modal-body { padding:16px 20px; }
        .modal-footer { padding:0 20px 16px; display:flex; justify-content:flex-end; gap:10px; }
        .form-group { display:flex; flex-direction:column; gap:5px; margin-bottom:13px; }
        .form-label { font-size:11px; font-weight:600; color:var(--gray-500,#6b7280);
                      text-transform:uppercase; letter-spacing:1.2px; }
        .form-input { width:100%; padding:9px 12px; border:1.5px solid var(--border,#e5e7eb);
                      border-radius:8px; font-size:13px; font-family:'Poppins',sans-serif;
                      color:var(--black,#111); background:var(--bg,#f9fafb); box-sizing:border-box;
                      transition:border-color .2s; }
        .form-input:focus { border-color:var(--black,#111); background:#fff; outline:none; }
        textarea.form-input { resize:vertical; min-height:60px; }
        .btn-cancel-modal { padding:9px 22px; border-radius:50px; font-size:13px; font-weight:600;
                            border:1.5px solid var(--border,#e5e7eb); background:var(--white,#fff);
                            color:var(--gray-500,#6b7280); cursor:pointer; font-family:'Poppins',sans-serif; transition:all .2s; }
        .btn-cancel-modal:hover { background:var(--border,#eee); }
        .btn-save-modal { padding:9px 22px; border-radius:50px; font-size:13px; font-weight:700;
                          background:linear-gradient(135deg,#111,#333); color:#fff; border:none;
                          cursor:pointer; font-family:'Poppins',sans-serif; transition:all .2s; }
        .btn-save-modal:hover { opacity:.9; transform:translateY(-1px); }
        .btn-save-modal:disabled { opacity:.5; cursor:not-allowed; transform:none; }
        /* ── Color picker ─────────────────────────────────────── */
        .color-picker-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
        .color-swatch {
            width:28px; height:28px; border-radius:50%; cursor:pointer;
            border:2px solid transparent; transition:transform .15s, border-color .15s;
        }
        .color-swatch:hover { transform:scale(1.15); }
        .color-swatch.selected { border-color:var(--black,#111); transform:scale(1.15); }
        /* ── Member invite row ────────────────────────────────── */
        .invite-row { display:flex; gap:8px; align-items:flex-end; }
        .invite-row .form-input { flex:1; }
        .btn-invite { padding:11px 16px; border-radius:8px; font-size:13px; font-weight:600;
                      background:var(--black,#111); color:#fff; border:none; cursor:pointer;
                      font-family:'Poppins',sans-serif; white-space:nowrap; }
        .btn-invite:hover { opacity:.85; }
        .invite-feedback { font-size:12px; margin-top:6px; min-height:16px; }
        /* ── Manage-members modal member list ─────────────────── */
        .member-manage-list { display:flex; flex-direction:column; gap:8px; max-height:220px; overflow-y:auto; }
        .member-manage-row {
            display:flex; align-items:center; justify-content:space-between;
            padding:8px 12px; background:var(--bg,#f9fafb);
            border-radius:8px; border:1px solid var(--border,#e5e7eb);
        }
        .member-manage-name { font-size:13px; font-weight:600; color:var(--black,#111); }
        .member-manage-role { font-size:11px; color:var(--gray-400,#9ca3af); margin-top:1px; }
        /* ── Flash banner ─────────────────────────────────────── */
        .flash-success { background:#f0fdf4; border:1.5px solid #86efac; border-radius:10px;
                         padding:12px 18px; font-size:13px; color:#15803d; font-weight:500;
                         margin-bottom:20px; }
        .flash-error { background:#fef2f2; border:1.5px solid #fca5a5; border-radius:10px;
                       padding:12px 18px; font-size:13px; color:#dc2626; font-weight:500;
                       margin-bottom:20px; }
        /* ── Spinner ──────────────────────────────────────────── */
        .proj-loading { text-align:center; padding:60px; color:var(--gray-400,#9ca3af); font-size:13px; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars(tm_uname()) ?></strong></span>
        <a href="TM_Profile.php" class="btn-logout" title="My Profile" style="display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-user-circle"></i></a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout">Calendar</a>
        <a href="TM_Tasks.php"     class="btn-logout">To-Do List</a>
        <a href="TM_Projects.php"  class="btn-logout" style="font-weight:700;">Projects</a>
        <a href="TM_Activity.php"  class="btn-logout">Activity</a>
        <a href="TM_Analytics.php" class="btn-logout">Analytics</a>
        <form class="navbar-search" action="TM_Tasks.php" method="get">
            <input type="hidden" name="view" value="all"/>
            <input type="text" name="q" class="navbar-search-input"
                   placeholder="Search tasks..." autocomplete="off"/>
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
        <div class="page-title">Projects</div>
        <div class="page-subtitle">Organize tasks into shared workspaces. Create a project and invite teammates to collaborate.</div>
    </div>

    <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="proj-bar">
        <span style="font-size:13px;color:var(--gray-400,#9ca3af);" id="projCountLabel">Loading projects…</span>
        <button class="btn-proj btn-proj-primary" onclick="openCreateModal()">
            <i class="fa-solid fa-plus"></i> New Project
        </button>
    </div>

    <!-- Project cards rendered by JS -->
    <div id="projGrid" class="proj-grid">
        <div class="proj-loading"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
    </div>

</main>

<!-- ══════════════════════════════════════════════════════════
     CREATE / EDIT PROJECT MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="projFormModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title" id="projFormTitle">New Project</div>
            <button class="modal-close" onclick="closeModal('projFormModal')">&#x2715;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Project Name <span style="color:#ef4444">*</span></label>
                <input type="text" class="form-input" id="projNameInput" placeholder="e.g. Website Redesign" maxlength="150"/>
                <div style="font-size:11px;color:#ef4444;margin-top:2px;min-height:14px;" id="projNameError"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea class="form-input" id="projDescInput" placeholder="What is this project about? (optional)" maxlength="500"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Color</label>
                <div class="color-picker-row" id="projColorRow"></div>
                <input type="hidden" id="projColorInput" value="#3b82f6"/>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel-modal" onclick="closeModal('projFormModal')">Cancel</button>
            <button class="btn-save-modal" id="projFormSaveBtn" onclick="submitProjForm()">
                <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MANAGE MEMBERS MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="membersModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title" id="membersModalTitle">Members</div>
            <button class="modal-close" onclick="closeModal('membersModal')">&#x2715;</button>
        </div>
        <div class="modal-body">
            <!-- Invite row — only shown to owner -->
            <div id="inviteSection" style="margin-bottom:16px;">
                <label class="form-label" style="margin-bottom:6px;display:block;">Invite by Email</label>
                <div class="invite-row">
                    <input type="email" class="form-input" id="inviteEmailInput"
                           placeholder="teammate@example.com" autocomplete="off"
                           onkeydown="if(event.key==='Enter')doInvite()"/>
                    <button class="btn-invite" onclick="doInvite()">
                        <i class="fa-solid fa-user-plus"></i> Invite
                    </button>
                </div>
                <div class="invite-feedback" id="inviteFeedback"></div>
            </div>

            <label class="form-label" style="margin-bottom:8px;display:block;">Current Members</label>
            <div class="member-manage-list" id="memberManageList">
                <div style="color:var(--gray-400);font-size:13px;text-align:center;padding:20px;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel-modal" onclick="closeModal('membersModal')">Close</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     DELETE CONFIRM pc-modal
     ══════════════════════════════════════════════════════════ -->
<div id="deleteProjModal" class="pc-modal-overlay" style="
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index:700; align-items:center; justify-content:center;">
    <div class="pc-modal-box" style="background:#fff;border-radius:16px;padding:32px 28px;
         max-width:380px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.18);text-align:center;">
        <div style="width:52px;height:52px;border-radius:50%;background:rgba(239,68,68,.12);
                    display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fa-solid fa-trash" style="color:#ef4444;font-size:1.3rem;"></i>
        </div>
        <div style="font-size:16px;font-weight:700;color:var(--black,#111);margin-bottom:8px;">Delete Project?</div>
        <div style="font-size:13px;color:var(--gray-500,#6b7280);margin-bottom:24px;line-height:1.6;">
            Delete <strong id="deleteProjName"></strong>?
            Tasks assigned to it will remain but lose the project link.
            This <strong>cannot be undone</strong>.
        </div>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button style="padding:9px 22px;border-radius:50px;font-size:13px;font-weight:600;
                           border:1.5px solid var(--border,#e5e7eb);background:#fff;
                           color:var(--gray-500,#6b7280);cursor:pointer;font-family:'Poppins',sans-serif;"
                    onclick="document.getElementById('deleteProjModal').style.display='none'">Cancel</button>
            <button style="padding:9px 22px;border-radius:50px;font-size:13px;font-weight:700;
                           background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;
                           cursor:pointer;font-family:'Poppins',sans-serif;"
                    onclick="doDeleteProject()" id="deleteProjConfirmBtn">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ── Color palette (same as rest of the system) ─────────
    var COLORS = [
        { name:'Red',    hex:'#ef4444' },
        { name:'Orange', hex:'#f97316' },
        { name:'Yellow', hex:'#eab308' },
        { name:'Green',  hex:'#22c55e' },
        { name:'Blue',   hex:'#3b82f6' },
        { name:'Indigo', hex:'#6366f1' },
        { name:'Violet', hex:'#a855f7' },
    ];

    // ── State ──────────────────────────────────────────────
    var _projects      = [];       // full list from server
    var _editProjectId = null;     // null = create mode, number = edit mode
    var _membersProjId = null;     // project being managed in members modal
    var _membersProjIsOwner = false;
    var _deleteProjId  = null;

    // ── Helpers ────────────────────────────────────────────
    function esc(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    window.closeModal = closeModal;

    function buildColorSwatches(rowId, inputId, selected) {
        var row = document.getElementById(rowId);
        if (!row) return;
        row.innerHTML = '';
        COLORS.forEach(function(c) {
            var sw = document.createElement('div');
            sw.className = 'color-swatch' + (c.hex === selected ? ' selected' : '');
            sw.style.background = c.hex;
            sw.title = c.name;
            sw.addEventListener('click', function() {
                row.querySelectorAll('.color-swatch').forEach(function(s) { s.classList.remove('selected'); });
                sw.classList.add('selected');
                document.getElementById(inputId).value = c.hex;
            });
            row.appendChild(sw);
        });
    }

    // ── Load & render all projects ─────────────────────────
    function loadProjects() {
        fetch('TM_PHP/TM_CollabActions.php?action=list_projects')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                _projects = d.ok ? (d.data || []) : [];
                renderGrid();
            })
            .catch(function() {
                document.getElementById('projGrid').innerHTML =
                    '<div class="proj-loading" style="color:#ef4444;">Failed to load projects. Please refresh.</div>';
            });
    }

    function renderGrid() {
        var grid  = document.getElementById('projGrid');
        var label = document.getElementById('projCountLabel');
        var n     = _projects.length;
        label.textContent = n === 0 ? 'No projects yet' : n + ' project' + (n === 1 ? '' : 's');

        if (n === 0) {
            grid.innerHTML =
                '<div class="proj-empty" style="grid-column:1/-1;">' +
                    '<div class="proj-empty-icon"><i class="fa-solid fa-folder-open"></i></div>' +
                    '<div class="proj-empty-title">No projects yet</div>' +
                    '<div class="proj-empty-sub">Create your first project to start organizing tasks with your team.</div>' +
                '</div>';
            return;
        }

        grid.innerHTML = _projects.map(function(p) {
            var isOwner = p.role === 'owner';
            var memberCountText = p.member_count + ' member' + (p.member_count === 1 ? '' : 's');
            var taskCountText   = p.task_count   + ' task'   + (p.task_count   === 1 ? '' : 's');
            return [
                '<div class="proj-card" id="pcard-' + p.project_id + '">',
                    '<div class="proj-card-bar" style="background:' + esc(p.color) + ';"></div>',
                    '<div class="proj-card-body">',
                        '<div class="proj-card-header">',
                            '<div class="proj-card-name">' + esc(p.name) + '</div>',
                            '<span class="proj-role-badge ' + (isOwner ? 'owner' : '') + '">' +
                                (isOwner ? 'OWNER' : 'MEMBER') +
                            '</span>',
                        '</div>',
                        '<div class="proj-card-desc">' + (p.description ? esc(p.description) : '<em style="color:var(--gray-300)">No description</em>') + '</div>',
                        '<div class="proj-meta">',
                            '<span><i class="fa-solid fa-users"></i>' + esc(memberCountText) + '</span>',
                            '<span><i class="fa-solid fa-list-check"></i>' + esc(taskCountText) + '</span>',
                        '</div>',
                    '</div>',
                    '<div class="proj-card-footer">',
                        '<button class="btn-proj" onclick="openMembersModal(' + p.project_id + ')">',
                            '<i class="fa-solid fa-users"></i> Members',
                        '</button>',
                        (isOwner
                            ? '<button class="btn-proj" onclick="openEditModal(' + p.project_id + ')">' +
                                  '<i class="fa-solid fa-pen"></i> Edit' +
                              '</button>' +
                              '<button class="btn-proj btn-proj-danger" onclick="confirmDeleteProject(' + p.project_id + ',\'' + esc(p.name) + '\')">' +
                                  '<i class="fa-solid fa-trash"></i> Delete' +
                              '</button>'
                            : ''),
                    '</div>',
                '</div>',
            ].join('');
        }).join('');
    }

    // ── CREATE MODAL ───────────────────────────────────────
    window.openCreateModal = function() {
        _editProjectId = null;
        document.getElementById('projFormTitle').textContent = 'New Project';
        document.getElementById('projNameInput').value = '';
        document.getElementById('projDescInput').value = '';
        document.getElementById('projColorInput').value = '#3b82f6';
        document.getElementById('projNameError').textContent = '';
        buildColorSwatches('projColorRow', 'projColorInput', '#3b82f6');
        openModal('projFormModal');
        setTimeout(function() { document.getElementById('projNameInput').focus(); }, 80);
    };

    // ── EDIT MODAL ─────────────────────────────────────────
    window.openEditModal = function(projId) {
        var p = _projects.find(function(x) { return x.project_id === projId; });
        if (!p) return;
        _editProjectId = projId;
        document.getElementById('projFormTitle').textContent = 'Edit Project';
        document.getElementById('projNameInput').value = p.name;
        document.getElementById('projDescInput').value = p.description || '';
        document.getElementById('projColorInput').value = p.color || '#3b82f6';
        document.getElementById('projNameError').textContent = '';
        buildColorSwatches('projColorRow', 'projColorInput', p.color || '#3b82f6');
        openModal('projFormModal');
        setTimeout(function() { document.getElementById('projNameInput').focus(); }, 80);
    };

    // ── SUBMIT CREATE/EDIT ─────────────────────────────────
    window.submitProjForm = function() {
        var name  = document.getElementById('projNameInput').value.trim();
        var desc  = document.getElementById('projDescInput').value.trim();
        var color = document.getElementById('projColorInput').value;
        var errEl = document.getElementById('projNameError');
        var btn   = document.getElementById('projFormSaveBtn');

        if (!name) {
            errEl.textContent = 'Project name is required.';
            document.getElementById('projNameInput').focus();
            return;
        }
        errEl.textContent = '';

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

        var fd = new FormData();
        fd.append('name', name);
        fd.append('description', desc);
        fd.append('color', color);

        if (_editProjectId) {
            fd.append('action', 'update_project');
            fd.append('project_id', _editProjectId);
        } else {
            fd.append('action', 'create_project');
        }

        fetch('TM_PHP/TM_CollabActions.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.ok) {
                    errEl.textContent = d.error || 'Save failed.';
                    return;
                }
                closeModal('projFormModal');
                loadProjects();
            })
            .catch(function() { errEl.textContent = 'Network error. Please try again.'; })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
            });
    };

    // ── DELETE PROJECT ─────────────────────────────────────
    window.confirmDeleteProject = function(projId, projName) {
        _deleteProjId = projId;
        document.getElementById('deleteProjName').textContent = projName;
        document.getElementById('deleteProjModal').style.display = 'flex';
    };

    window.doDeleteProject = function() {
        if (!_deleteProjId) return;
        var btn = document.getElementById('deleteProjConfirmBtn');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'delete_project');
        fd.append('project_id', _deleteProjId);

        fetch('TM_PHP/TM_CollabActions.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                document.getElementById('deleteProjModal').style.display = 'none';
                if (d.ok) {
                    loadProjects();
                } else {
                    alert(d.error || 'Delete failed.');
                }
            })
            .catch(function() { alert('Network error.'); })
            .finally(function() { btn.disabled = false; });
    };

    // ── MEMBERS MODAL ──────────────────────────────────────
    window.openMembersModal = function(projId) {
        _membersProjId = projId;
        var p = _projects.find(function(x) { return x.project_id === projId; });
        _membersProjIsOwner = p && p.role === 'owner';
        document.getElementById('membersModalTitle').textContent =
            'Members — ' + (p ? esc(p.name) : '');
        document.getElementById('inviteSection').style.display = _membersProjIsOwner ? '' : 'none';
        document.getElementById('inviteUsernameInput').value = '';
        document.getElementById('inviteFeedback').textContent = '';
        loadMembersList();
        openModal('membersModal');
    };

    function loadMembersList() {
        var list = document.getElementById('memberManageList');
        list.innerHTML = '<div style="color:var(--gray-400);font-size:13px;text-align:center;padding:20px;">' +
            '<i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';

        fetch('TM_PHP/TM_CollabActions.php?action=get_project_members&project_id=' + encodeURIComponent(_membersProjId))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.ok || !d.data || d.data.length === 0) {
                    list.innerHTML = '<div style="color:var(--gray-400);font-size:13px;text-align:center;padding:20px;">No members found.</div>';
                    return;
                }
                list.innerHTML = d.data.map(function(m) {
                    var displayName = (m.full_name && m.full_name.trim())
                        ? esc(m.full_name) + ' <span style="color:var(--gray-400);font-weight:400;">@' + esc(m.username) + '</span>'
                        : '@' + esc(m.username);
                    var isOwner = m.role === 'owner';
                    var canRemove = _membersProjIsOwner && !isOwner;
                    return [
                        '<div class="member-manage-row" id="mrow-' + m.user_id + '">',
                            '<div>',
                                '<div class="member-manage-name">' + displayName + '</div>',
                                '<div class="member-manage-role">' + (isOwner ? '👑 Owner' : 'Member') + '</div>',
                            '</div>',
                            (canRemove
                                ? '<button class="btn-proj btn-proj-danger" style="font-size:11px;padding:4px 10px;"' +
                                      ' onclick="removeMember(' + m.user_id + ',\'' + esc(m.username) + '\')">' +
                                      '<i class="fa-solid fa-user-minus"></i> Remove</button>'
                                : ''),
                        '</div>',
                    ].join('');
                }).join('');
            })
            .catch(function() {
                list.innerHTML = '<div style="color:#ef4444;font-size:13px;text-align:center;padding:20px;">Failed to load members.</div>';
            });
    }

    // ── INVITE MEMBER ──────────────────────────────────────
    window.doInvite = function() {
        var inp   = document.getElementById('inviteEmailInput');
        var fb    = document.getElementById('inviteFeedback');
        var email = inp.value.trim();
        if (!email) { fb.style.color = '#ef4444'; fb.textContent = 'Enter an email address.'; return; }

        fb.style.color = 'var(--gray-500)';
        fb.textContent = 'Inviting…';

        var fd = new FormData();
        fd.append('action',     'add_project_member');
        fd.append('project_id', _membersProjId);
        fd.append('email',      email);

        fetch('TM_PHP/TM_CollabActions.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    fb.style.color = '#15803d';
                    fb.textContent = d.info || '✓ Member added successfully.';
                    inp.value = '';
                    loadMembersList();
                    loadProjects(); // refresh member_count on cards
                } else {
                    fb.style.color = '#ef4444';
                    fb.textContent = d.error || 'Failed to add member.';
                }
            })
            .catch(function() { fb.style.color = '#ef4444'; fb.textContent = 'Network error.'; });
    };

    // ── REMOVE MEMBER ──────────────────────────────────────
    window.removeMember = function(userId, username) {
        if (!confirm('Remove @' + username + ' from this project?')) return;

        var fd = new FormData();
        fd.append('action',     'remove_project_member');
        fd.append('project_id', _membersProjId);
        fd.append('user_id',    userId);

        fetch('TM_PHP/TM_CollabActions.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    var row = document.getElementById('mrow-' + userId);
                    if (row) row.remove();
                    loadProjects(); // refresh member_count
                } else {
                    alert(d.error || 'Failed to remove member.');
                }
            })
            .catch(function() { alert('Network error.'); });
    };

    // ── Logout ─────────────────────────────────────────────
    document.getElementById('logoutBtn').addEventListener('click', function(e) {
        e.preventDefault();
        fetch('TM_PHP/TM_AuthActions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=logout'
        }).finally(function() { window.location.href = 'TM_Login.php'; });
    });

    // ── Keyboard: Escape closes any open modal ─────────────
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        ['projFormModal', 'membersModal'].forEach(function(id) {
            document.getElementById(id).classList.remove('active');
        });
        document.getElementById('deleteProjModal').style.display = 'none';
    });

    // ── Kick off ───────────────────────────────────────────
    loadProjects();

})();
</script>

</body>
</html>
