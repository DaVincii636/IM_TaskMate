<?php
/**
 * TM_PHP/TM_TaskModal.php
 * ─────────────────────────────────────────────────────────────
 * Shared partial: task VIEW modal + EDIT modal + DELETE confirm.
 * Include once per page, anywhere after <body>.
 *
 * Requires:
 *   - TM_Session.php and TM_DB.php already loaded
 *   - Font Awesome 6 already linked
 *   - TM_Style.css already linked  (provides .modal-*, .pc-modal-*, .form-*)
 *
 * The page must pass a PHP array $allTasksForModal before including,
 * OR the partial will query it itself using tm_uid().
 *
 * Usage in a page:
 *   require_once 'TM_PHP/TM_TaskModal.php';
 *
 * Making a row/card clickable:
 *   Add  data-task-id="<?= $id ?>"  to any <tr>, <div>, etc.
 *   The JS in this partial will open the view modal on click.
 */

if (!isset($allTasksForModal)) {
    // Fetch full task data — include both tasks owned by AND delegated to this user.
    // Feature 10: after delegation user_id changes to the new owner, so owned tasks
    // cover that case. We also pull tasks where assigned_to = uid in case a future
    // flow keeps the original owner but sets assigned_to.
    $_modal_uid = tm_uid();
    $_modal_stmt = tm_exec(
        "SELECT task_id, task_name,
                TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD')   AS due_date,
                category, custom_category, priority, color, notes, status, recurrence
         FROM TM_Tasks
         WHERE user_id = :p1
            OR assigned_to = :p2
         ORDER BY due_date ASC",
        [$_modal_uid, $_modal_uid]
    );
    $allTasksForModal = tm_fetch_all($_modal_stmt);
    // Resolve CLOB/LOB
    $allTasksForModal = array_map(function($row) {
        if (isset($row['notes'])) {
            if ($row['notes'] instanceof OCILob)    $row['notes'] = $row['notes']->load();
            elseif (is_resource($row['notes']))     $row['notes'] = stream_get_contents($row['notes']);
            $row['notes'] = (string)($row['notes'] ?? '');
        }
        return $row;
    }, $allTasksForModal);
    // Deduplicate by task_id (in case user_id = assigned_to on the same row)
    $_seen = [];
    $allTasksForModal = array_filter($allTasksForModal, function($r) use (&$_seen) {
        $id = $r['task_id'] ?? $r['TASK_ID'] ?? null;
        if ($id === null || isset($_seen[$id])) return false;
        $_seen[$id] = true;
        return true;
    });
    $allTasksForModal = array_values($allTasksForModal);
}

// Encode for JS
$_modalTasksJson = json_encode($allTasksForModal,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if ($_modalTasksJson === false) $_modalTasksJson = '[]';
?>

<!-- ══════════════════════════════════════════════════════════
     TASK VIEW MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="taskViewModal">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <div class="modal-title">Task Details</div>
            <button class="modal-close" onclick="closeModal('taskViewModal')">&#x2715;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <!-- filled by JS -->
        </div>
        <div class="modal-footer" style="justify-content:space-between;">
            <button type="button" class="btn-cancel"
                    style="color:#ef4444;"
                    id="viewModalDeleteBtn"
                    onclick="tmOpenDeleteFromView()">
                <i class="fa-solid fa-trash" style="margin-right:4px;"></i>Delete
            </button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn-cancel"
                        onclick="closeModal('taskViewModal')">Close</button>
                <button type="button" class="btn-save"
                        id="viewModalEditBtn"
                        onclick="tmOpenEditFromView()">
                    <i class="fa-solid fa-pen" style="margin-right:4px;"></i>Edit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT TASK MODAL  (same fields as Calendar)
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editTaskModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Edit Task</div>
            <button class="modal-close" onclick="closeModal('editTaskModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TaskActions.php" id="editTaskForm">
            <input type="hidden" name="action" value="edit"/>
            <input type="hidden" name="id"     id="editTaskId"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Task Name</label>
                    <input type="text" name="name" class="form-input" id="editTaskName" required/>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="startDate" class="form-input" id="editTaskStart" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="dueDate" class="form-input" id="editTaskDue" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <div class="category-options" id="tmEditCatOptions">
                        <button type="button" class="cat-btn" data-cat="errands">Errands</button>
                        <button type="button" class="cat-btn" data-cat="school">School</button>
                        <button type="button" class="cat-btn" data-cat="medicine">Medicine</button>
                        <button type="button" class="cat-btn" data-cat="others">Others</button>
                    </div>
                    <input type="hidden" name="category" id="tmEditCategoryInput" value="errands"/>
                    <div id="tmEditOthersWrap" style="display:none;margin-top:8px">
                        <input type="text" name="customCategory" class="form-input"
                               id="tmEditCustomCat" placeholder="Specify category..."/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <div class="priority-options" id="tmEditPriorityOptions">
                        <button type="button" class="priority-btn high" data-priority="high">High</button>
                        <button type="button" class="priority-btn mid"  data-priority="mid">Mid</button>
                        <button type="button" class="priority-btn low"  data-priority="low">Low</button>
                    </div>
                    <input type="hidden" name="priority" id="tmEditPriorityInput" value="mid"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Task Color</label>
                    <div class="tm-color-picker-row" id="tmEditColorRow"></div>
                    <input type="hidden" name="color" id="tmEditColorInput" value="#ef4444"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input" id="tmEditTaskStatus">
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="review">Review</option>
                        <option value="done">Done</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <!-- ── CHANGE 2: Project selector ─────────────── -->
                <div class="form-group" id="tmEditProjectGroup">
                    <label class="form-label">
                        <i class="fa-solid fa-folder" style="margin-right:4px;color:var(--gray-400)"></i>
                        Project
                    </label>
                    <select name="project_id" class="form-input" id="tmEditProjectSelect">
                        <option value="">— Personal (no project) —</option>
                    </select>
                    <input type="hidden" name="project_id" id="tmEditProjectInput" value=""/>
                </div>

                <!-- ── CHANGE 1: Assign to user ───────────────── -->
                <div class="form-group" id="tmEditAssignGroup">
                    <label class="form-label">
                        <i class="fa-solid fa-user-plus" style="margin-right:4px;color:var(--gray-400)"></i>
                        Assign To
                    </label>
                    <select name="assigned_to" class="form-input" id="tmEditAssignSelect">
                        <option value="">— Unassigned —</option>
                    </select>
                </div>

                <!-- ── FEATURE 10: Delegate / Reassign task (moderator+ only) ── -->
                <div class="form-group" id="tmReassignGroup" style="display:none;">
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-right-left" style="color:var(--gray-400)"></i>
                        Delegate Task To
                        <span style="font-size:10px;font-weight:600;background:#fef9c3;color:#92400e;padding:2px 8px;border-radius:50px;letter-spacing:.03em;">MODERATOR+</span>
                    </label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select id="tmReassignSelect" class="form-input" style="flex:1;">
                            <option value="">— Pick a user —</option>
                        </select>
                        <button type="button" id="tmReassignBtn"
                                style="padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;
                                       background:var(--black);color:#fff;border:none;cursor:pointer;
                                       display:inline-flex;align-items:center;gap:6px;white-space:nowrap;
                                       font-family:'Poppins',sans-serif;transition:opacity .15s;"
                                onmouseover="this.style.opacity='.85'"
                                onmouseout="this.style.opacity='1'"
                                onclick="tmDoReassign()">
                            <i class="fa-solid fa-right-left"></i> Delegate
                        </button>
                    </div>
                    <div id="tmReassignFeedback" style="font-size:12px;margin-top:5px;color:var(--gray-500);"></div>
                </div>
                <div class="form-group dep-group" id="tmEditDepGroup">
                    <label class="form-label">Must Complete First</label>
                    <select id="tmEditDepSelect" class="form-input dep-select">
                        <option value="">— Pick a task —</option>
                    </select>
                    <div class="dep-selected" id="tmEditDepSelected"></div>
                    <input type="hidden" id="tmEditDepBlockerIds" name="blocker_ids" value=""/>
                </div>
                <div class="form-group">
                    <label class="form-label">Recurrence</label>
                    <select name="recurrence" class="form-input" id="tmEditRecurrence">
                        <option value="">— None (one-time) —</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input tm-auto-expand" id="tmEditTaskNotes"
                              placeholder="Optional notes… use @username to notify teammates" rows="3"
                              style="resize:none;overflow:hidden;"></textarea>
                    <!-- @mention autocomplete suggestions -->
                    <div id="editMentionSuggestions" class="tm-mention-suggestions" style="display:none;"></div>
                </div>

                <!-- ── CHANGE 3: Comments section ─────────────────────────── -->
                <div class="form-group" id="tmEditCommentsSection" style="margin-top:1.25rem;">
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-comments" style="color:var(--gray-400)"></i>
                        Comments
                        <span id="tmCommentCount" style="color:var(--gray-400);font-weight:400;font-size:11px;"></span>
                    </label>
                    <!-- comment list -->
                    <div id="tmCommentList"
                         style="max-height:200px;overflow-y:auto;margin-bottom:.6rem;display:flex;flex-direction:column;gap:.5rem;">
                        <div id="tmCommentsLoading" style="text-align:center;padding:.75rem;color:var(--gray-400);font-size:12px;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                        </div>
                    </div>
                    <!-- new comment input -->
                    <div style="display:flex;gap:8px;align-items:flex-end;">
                        <div style="flex:1;position:relative;">
                            <textarea id="tmNewCommentInput"
                                      class="form-input tm-auto-expand"
                                      placeholder="Add a comment… use @username to mention someone"
                                      rows="2"
                                      style="resize:none;overflow:hidden;font-size:13px;"></textarea>
                            <div id="commentMentionSuggestions" class="tm-mention-suggestions" style="display:none;"></div>
                        </div>
                        <button type="button" class="btn-save"
                                id="tmPostCommentBtn"
                                style="padding:8px 14px;flex-shrink:0;"
                                onclick="tmPostComment()">
                            <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" style="color:#ef4444;"
                        onclick="tmOpenDeleteFromEdit()">Delete</button>
                <button type="button" class="btn-cancel"
                        onclick="closeModal('editTaskModal')">Cancel</button>
                <button type="button" class="btn-save"
                        onclick="tmOpenSaveConfirm()">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     DATE ERROR pc-modal  (non-browser alert replacement)
     ══════════════════════════════════════════════════════════ -->
<div id="tmDateErrorModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(239,68,68,.12)">
            <i class="fa-solid fa-calendar-xmark" style="color:#ef4444"></i>
        </div>
        <div class="pc-modal-title">Invalid Dates</div>
        <div class="pc-modal-body" id="tmDateErrorModalText">Due date cannot be before start date.</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-confirm-blue"
                    onclick="document.getElementById('tmDateErrorModal').classList.remove('active')">
                <i class="fa-solid fa-check"></i> OK
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SAVE CONFIRM pc-modal
     ══════════════════════════════════════════════════════════ -->
<div id="tmSaveTaskModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(59,130,246,.12)">
            <i class="fa-solid fa-floppy-disk" style="color:#3b82f6"></i>
        </div>
        <div class="pc-modal-title">Save Changes?</div>
        <div class="pc-modal-body" id="tmSaveTaskModalText">Save changes to this task?</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel"
                    onclick="document.getElementById('tmSaveTaskModal').classList.remove('active')">Cancel</button>
            <button class="pc-modal-confirm-blue"
                    onclick="document.getElementById('editTaskForm').submit()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     DELETE CONFIRM pc-modal
     ══════════════════════════════════════════════════════════ -->
<div id="tmDeleteTaskModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(239,68,68,.12)">
            <i class="fa-solid fa-trash" style="color:#ef4444"></i>
        </div>
        <div class="pc-modal-title">Delete Task?</div>
        <div class="pc-modal-body">
            Delete <strong id="tmDeleteTaskName"></strong>?
            This <strong>cannot be undone</strong>.
        </div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel"
                    onclick="document.getElementById('tmDeleteTaskModal').classList.remove('active')">Cancel</button>
            <button class="pc-modal-confirm-red"
                    onclick="document.getElementById('tmDeleteTaskForm').submit()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<!-- hidden delete form -->
<form method="post" action="TM_PHP/TM_TaskActions.php"
      id="tmDeleteTaskForm" style="display:none">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="id"     id="tmDeleteTaskId"/>
</form>

<!-- ══════════════════════════════════════════════════════════
     STYLES  (color swatches + view-modal layout)
     ══════════════════════════════════════════════════════════ -->
<style>
/* ── Color swatches (same as TM_Calendar.css but scoped here) ── */
.tm-color-picker-row {
    display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;
}
.tm-color-swatch {
    width: 28px; height: 28px; border-radius: 50%;
    cursor: pointer; border: 2px solid transparent;
    transition: transform .15s, border-color .15s;
}
.tm-color-swatch:hover    { transform: scale(1.18); }
.tm-color-swatch.selected { border-color: var(--black); transform: scale(1.18); }

/* ── View modal body layout ───────────────────────────────── */
.vm-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .6rem 1.25rem;
    margin-bottom: .75rem;
}
.vm-field { display: flex; flex-direction: column; gap: 3px; }
.vm-field.full { grid-column: 1 / -1; }
.vm-label {
    font-size: 11px; font-weight: 700; letter-spacing: .05em;
    text-transform: uppercase; color: var(--gray-400);
}
.vm-value {
    font-size: 13px; font-weight: 500; color: var(--black);
    line-height: 1.5;
}
.vm-notes {
    background: var(--bg); border-radius: 8px;
    padding: .6rem .75rem; font-size: 13px;
    color: var(--gray-500); line-height: 1.6;
    white-space: pre-wrap; word-break: break-word;
    max-height: 140px; overflow-y: auto;
}
.vm-color-dot {
    display: inline-block; width: 12px; height: 12px;
    border-radius: 50%; vertical-align: middle; margin-right: 6px;
}

/* ── Clickable rows / cards ───────────────────────────────── */
[data-task-id] { cursor: pointer; }

/* ── pc-modal blue confirm button ────────────────────────── */
.pc-modal-confirm-blue {
    padding: 9px 22px; border-radius: 50px;
    font-size: 13px; font-weight: 700;
    background: linear-gradient(135deg,#3b82f6,#2563eb);
    color: #fff; border: none; cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: all .2s;
    display: inline-flex; align-items: center; gap: 6px;
}
.pc-modal-confirm-blue:hover { opacity: .9; transform: translateY(-1px); }
</style>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    // ── Auto-expand helper (used inline & by event listeners) ─
    function autoExpand(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    // ── Task data keyed by id ─────────────────────────────
    const RAW  = <?= $_modalTasksJson ?>;
    const TASKS = {};
    RAW.forEach(function (r) {
        var id = r['task_id'] || r['TASK_ID'];
        TASKS[id] = {
            id:       id,
            recurrence: r['recurrence'] || r['RECURRENCE'] || '',
            name:     r['task_name']        || r['TASK_NAME']        || '',
            start:    r['start_date']       || r['START_DATE']       || '',
            due:      r['due_date']         || r['DUE_DATE']         || '',
            cat:      r['category']         || r['CATEGORY']         || '',
            ccat:     r['custom_category']  || r['CUSTOM_CATEGORY']  || '',
            pri:      r['priority']         || r['PRIORITY']         || 'mid',
            color:    r['color']            || r['COLOR']            || '#ef4444',
            notes:    r['notes']            || r['NOTES']            || '',
            status:   (r['status'] || r['STATUS'] || 'pending').toString().toLowerCase(),
        };
    });

    // ── Colour palette (matches Calendar) ─────────────────
    const ROYGBIV = [
        { name:'Red',    hex:'#ef4444' },
        { name:'Orange', hex:'#f97316' },
        { name:'Yellow', hex:'#eab308' },
        { name:'Green',  hex:'#22c55e' },
        { name:'Blue',   hex:'#3b82f6' },
        { name:'Indigo', hex:'#6366f1' },
        { name:'Violet', hex:'#a855f7' },
    ];

    // ── Helpers ────────────────────────────────────────────
    function esc(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function friendlyDate(s) {
        if (!s) return '—';
        var p = s.split('-');
        var months = ['Jan','Feb','Mar','Apr','May','Jun',
                      'Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(p[1],10)-1] + ' ' + parseInt(p[2],10) + ', ' + p[0];
    }
    function statusLabel(s) {
        return {pending:'Pending',in_progress:'In Progress',
                review:'Review',done:'Done',done_late:'Done Late',cancelled:'Cancelled'}[s] || s;
    }
    function statusClass(s) {
        return {pending:'status-pending',in_progress:'status-in-progress',
                review:'status-review',done:'status-done',done_late:'status-done-late',
                cancelled:'status-cancelled'}[s] || 'status-pending';
    }
    function priLabel(p) {
        return {high:'High',mid:'Mid',low:'Low'}[p] || p;
    }
    function priClass(p) {
        return {high:'pri-high',mid:'pri-mid',low:'pri-low'}[p] || 'pri-mid';
    }
    function catLabel(cat, ccat) {
        if (cat === 'others' && ccat) return esc(ccat);
        return cat.charAt(0).toUpperCase() + cat.slice(1);
    }

    // ── State: which task is currently shown ───────────────
    var _currentTaskId = null;

    // ── Open VIEW modal ────────────────────────────────────
    window.tmOpenView = function (id) {
        var t = TASKS[id];
        if (!t) return;
        _currentTaskId = id;

        var isOverdue = t.due < new Date().toISOString().slice(0,10)
                        && t.status !== 'done' && t.status !== 'done_late' && t.status !== 'cancelled';

        document.getElementById('viewModalBody').innerHTML =
            // ── Colour hero strip ──────────────────────────────
            '<div style="height:4px;border-radius:4px;background:' + esc(t.color) + ';margin:-1px 0 1.25rem;"></div>'

            // ── Task name row ──────────────────────────────────
          + '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:1rem;">'
          +   '<span style="width:13px;height:13px;border-radius:50%;flex-shrink:0;margin-top:3px;background:' + esc(t.color) + ';display:inline-block;box-shadow:0 0 0 3px ' + esc(t.color) + '33;"></span>'
          +   '<span style="font-size:16px;font-weight:700;color:var(--black);line-height:1.35;">' + esc(t.name) + '</span>'
          + '</div>'

            // ── Status + Priority + Overdue badges ────────────
          + '<div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:1.25rem;">'
          +   '<span class="status-pill ' + statusClass(t.status) + '">' + statusLabel(t.status) + '</span>'
          +   '<span class="pri-pill ' + priClass(t.pri) + '">' + priLabel(t.pri) + '</span>'
          +   (isOverdue ? '<span style="background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;padding:3px 10px;border-radius:50px;">Overdue</span>' : '')
          + '</div>'

            // ── Divider ────────────────────────────────────────
          + '<div style="height:1px;background:var(--gray-100);margin-bottom:1.1rem;"></div>'

            // ── Info grid ──────────────────────────────────────
          + '<div class="vm-grid">'
          +   '<div class="vm-field">'
          +     '<span class="vm-label">Category</span>'
          +     '<span class="vm-value">' + catLabel(t.cat, t.ccat) + '</span>'
          +   '</div>'
          +   '<div class="vm-field">'
          +     '<span class="vm-label">Start Date</span>'
          +     '<span class="vm-value">' + friendlyDate(t.start) + '</span>'
          +   '</div>'
          +   '<div class="vm-field">'
          +     '<span class="vm-label">Due Date</span>'
          +     '<span class="vm-value" style="' + (isOverdue ? 'color:#ef4444;font-weight:700;' : '') + '">'
          +       friendlyDate(t.due)
          +     '</span>'
          +   '</div>'
          + '</div>'

            // ── Notes ──────────────────────────────────────────
          + (t.notes
              ? '<div class="vm-field" style="margin-top:.9rem;">'
              +   '<span class="vm-label">Notes</span>'
              +   '<div class="vm-notes" style="margin-top:5px;">' + esc(t.notes) + '</div>'
              + '</div>'
              : '');

        openModal('taskViewModal');
    };

    // ── From view modal → edit modal ───────────────────────
    window.tmOpenEditFromView = function () {
        closeModal('taskViewModal');
        tmOpenEdit(_currentTaskId);
    };

    // ── From view modal → delete confirm ──────────────────
    window.tmOpenDeleteFromView = function () {
        closeModal('taskViewModal');
        tmSetupDelete(_currentTaskId);
        document.getElementById('tmDeleteTaskModal').classList.add('active');
    };

    // ── Open EDIT modal directly ───────────────────────────
    window.tmOpenEdit = function (id) {
        var t = TASKS[id];
        if (!t) return;
        _currentTaskId = id;

        document.getElementById('editTaskId').value    = t.id;
        document.getElementById('editTaskName').value  = t.name;
        document.getElementById('editTaskStart').value = t.start;
        document.getElementById('editTaskDue').value   = t.due;
        document.getElementById('editTaskNotes') && (document.getElementById('tmEditTaskNotes').value = t.notes);
        document.getElementById('tmEditTaskNotes').value  = t.notes;
        autoExpand(document.getElementById('tmEditTaskNotes'));
        document.getElementById('tmEditCategoryInput').value = t.cat;
        document.getElementById('tmEditPriorityInput').value = t.pri;
        document.getElementById('tmEditColorInput').value    = t.color;
        document.getElementById('tmEditTaskStatus').value    = (t.status || 'pending').toLowerCase();
        document.getElementById('tmEditRecurrence').value    = t.recurrence || '';

        // Category buttons
        document.querySelectorAll('#tmEditCatOptions .cat-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.cat === t.cat);
        });
        document.getElementById('tmEditOthersWrap').style.display =
            t.cat === 'others' ? 'block' : 'none';
        document.getElementById('tmEditCustomCat').value = t.ccat || '';

        // Priority buttons
        document.querySelectorAll('#tmEditPriorityOptions .priority-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.priority === t.pri);
        });

        // Color swatches
        buildSwatches('tmEditColorRow', 'tmEditColorInput', t.color);

        // Load dep links for this task
        if (typeof window.tmEditDepLoad === 'function') window.tmEditDepLoad(id);

        openModal('editTaskModal');
    };

    // ── From edit modal → delete confirm ──────────────────
    window.tmOpenDeleteFromEdit = function () {
        closeModal('editTaskModal');
        tmSetupDelete(_currentTaskId);
        document.getElementById('tmDeleteTaskModal').classList.add('active');
    };

    function tmSetupDelete(id) {
        var t = TASKS[id];
        if (!t) return;
        document.getElementById('tmDeleteTaskName').textContent = t.name;
        document.getElementById('tmDeleteTaskId').value = t.id;
    }

    // ── Save confirm ───────────────────────────────────────
    window.tmOpenSaveConfirm = function () {
        var nameEl  = document.getElementById('editTaskName');
        var startEl = document.getElementById('editTaskStart');
        var dueEl   = document.getElementById('editTaskDue');

        // Name check
        if (!nameEl || !nameEl.value.trim()) {
            nameEl && nameEl.focus();
            return;
        }
        // Start date check
        if (!startEl || !startEl.value) {
            startEl && startEl.focus();
            return;
        }
        // Due date check
        if (!dueEl || !dueEl.value) {
            dueEl && dueEl.focus();
            return;
        }
        // Date logic check — use custom modal, never browser alert/confirm
        if (dueEl.value < startEl.value) {
            var errModal = document.getElementById('tmDateErrorModal');
            var errText  = document.getElementById('tmDateErrorModalText');
            if (errText) errText.textContent = 'Due date (' + dueEl.value + ') cannot be before start date (' + startEl.value + ').';
            if (errModal) errModal.classList.add('active');
            return;
        }

        var name = nameEl.value || 'this task';
        document.getElementById('tmSaveTaskModalText').innerHTML =
            'Save changes to <strong>' + esc(name) + '</strong>?';
        document.getElementById('tmSaveTaskModal').classList.add('active');
    };

    // ── Actually submit with link persistence ──────────────
    // Replace the confirm button's direct submit with a fetch-then-submit
    document.addEventListener('DOMContentLoaded', function() {
        var confirmBtn = document.querySelector('#tmSaveTaskModal .pc-modal-confirm-blue');
        if (confirmBtn) {
            // Remove old inline onclick
            confirmBtn.removeAttribute('onclick');
            confirmBtn.addEventListener('click', function() {
                var taskId = document.getElementById('editTaskId').value;
                var blockerIds = (document.getElementById('tmEditDepBlockerIds') || {}).value || '';
                var fd = new FormData();
                fd.append('action', 'save_links');
                fd.append('task_id', taskId);
                fd.append('blocker_ids', blockerIds);
                fetch('TM_PHP/TM_LinkActions.php', { method: 'POST', body: fd })
                    .finally(function() {
                        document.getElementById('editTaskForm').submit();
                    });
            });
        }
    });

    // ── Build color swatches ───────────────────────────────
    function buildSwatches(rowId, inputId, selected) {
        var row = document.getElementById(rowId);
        if (!row) return;
        row.innerHTML = '';
        ROYGBIV.forEach(function (c) {
            var sw = document.createElement('div');
            sw.className = 'tm-color-swatch' + (c.hex === selected ? ' selected' : '');
            sw.style.background = c.hex;
            sw.title = c.name;
            sw.addEventListener('click', function () {
                row.querySelectorAll('.tm-color-swatch').forEach(function (s) {
                    s.classList.remove('selected');
                });
                sw.classList.add('selected');
                document.getElementById(inputId).value = c.hex;
            });
            row.appendChild(sw);
        });
    }

    // ── Bind category buttons ──────────────────────────────
    document.querySelectorAll('#tmEditCatOptions .cat-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#tmEditCatOptions .cat-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            document.getElementById('tmEditCategoryInput').value = btn.dataset.cat;
            document.getElementById('tmEditOthersWrap').style.display =
                btn.dataset.cat === 'others' ? 'block' : 'none';
        });
    });

    // ── Bind priority buttons ──────────────────────────────
    document.querySelectorAll('#tmEditPriorityOptions .priority-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#tmEditPriorityOptions .priority-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            document.getElementById('tmEditPriorityInput').value = btn.dataset.priority;
        });
    });

    // ── Delegate clicks on any [data-task-id] element ──────
    document.addEventListener('click', function (e) {
        // Ignore clicks inside a modal
        if (e.target.closest('.modal-overlay')) return;
        // Ignore the quick-done button specifically
        if (e.target.closest('.btn-quick-done')) return;
        // Ignore drag operations on kanban cards
        if (e.target.closest('.kanban-card') && window._tmDragging) return;

        var el = e.target.closest('[data-task-id]');
        if (!el) return;
        var id = el.getAttribute('data-task-id');
        if (id) tmOpenView(id);
    });

    // ── Daily recurrence: keep start & due in sync ────────
    (function () {
        var recEl   = document.getElementById('tmEditRecurrence');
        var startEl = document.getElementById('editTaskStart');
        var dueEl   = document.getElementById('editTaskDue');
        if (!recEl || !startEl || !dueEl) return;

        function syncDates(changedField) {
            if (recEl.value !== 'daily') return;
            if (changedField === 'start') dueEl.value   = startEl.value;
            else                          startEl.value = dueEl.value;
        }

        recEl.addEventListener('change', function () {
            if (recEl.value === 'daily' && startEl.value) {
                dueEl.value = startEl.value;
            }
        });
        startEl.addEventListener('change', function () { syncDates('start'); });
        dueEl.addEventListener('change',   function () { syncDates('due');   });
    })();
    document.querySelectorAll('textarea.tm-auto-expand').forEach(function (ta) {
        ta.addEventListener('input', function () { autoExpand(ta); });
    });

    // ── Dep UI for TM_TaskModal Edit modal ────────────────
    (function() {
        function toNum(v) { return parseInt(v, 10) || 0; }
        function escDep(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function fmtDate(s) {
            if (!s) return '';
            var p = s.split('-');
            var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[parseInt(p[1],10)-1]+' '+parseInt(p[2],10);
        }
        function getAvailTasks() {
            var raw = (typeof window.serverTasks !== 'undefined') ? window.serverTasks : RAW;
            return raw.map(function(t) {
                return { Id: toNum(t.task_id||t.Id||t.TASK_ID),
                         Name: t.task_name||t.Name||t.TASK_NAME||'',
                         Status: t.status||t.Status||t.STATUS||'pending',
                         Color: t.color||t.Color||t.COLOR||'#888',
                         DueDate: t.due_date||t.DueDate||t.DUE_DATE||'' };
            });
        }

        var _selected  = [];
        var _editingId = null;

        function renderDep() {
            var container = document.getElementById('tmEditDepSelected');
            var hidden    = document.getElementById('tmEditDepBlockerIds');
            if (!container || !hidden) return;
            container.innerHTML = '';
            hidden.value = _selected.map(function(s){return s.id;}).join(',');
            _selected.forEach(function(s) {
                var chip = document.createElement('div');
                chip.className = 'dep-chip';
                chip.innerHTML =
                    '<span class="dep-chip-dot" style="background:'+escDep(s.color)+'"></span>'+
                    '<span class="dep-chip-name">'+escDep(s.name)+'</span>'+
                    (s.dueDate?'<span class="dep-chip-due">'+fmtDate(s.dueDate)+'</span>':'')+
                    '<button type="button" class="dep-chip-remove" title="Remove"><i class="fa-solid fa-xmark"></i></button>';
                chip.querySelector('.dep-chip-remove').addEventListener('click', function(){
                    _selected = _selected.filter(function(x){return x.id!==s.id;});
                    renderDep(); buildDepSelect();
                });
                container.appendChild(chip);
            });
            buildDepSelect();
        }

        function buildDepSelect() {
            var sel   = document.getElementById('tmEditDepSelect');
            var group = document.getElementById('tmEditDepGroup');
            if (!sel) return;

            // Only show tasks that are due BEFORE this task's due date
            var currentDue = (document.getElementById('editTaskDue') || {}).value || '';

            var tasks = getAvailTasks();
            var eligible = tasks.filter(function(t){
                return t.Id !== _editingId &&
                       !['done','done_late','cancelled'].includes((t.Status||'').toLowerCase()) &&
                       !_selected.find(function(s){return s.id===t.Id;}) &&
                       // Only include tasks due on or before this task's due date
                       (currentDue === '' || t.DueDate === '' || t.DueDate <= currentDue);
            });

            sel.innerHTML = '<option value="">— Pick a task —</option>';
            eligible.forEach(function(t){
                var opt = document.createElement('option');
                opt.value = t.Id;
                opt.textContent = t.Name+(t.DueDate?'  ·  '+fmtDate(t.DueDate):'');
                sel.appendChild(opt);
            });

            // Hide the entire section when no eligible tasks AND no existing blockers
            if (group) {
                var hasContent = eligible.length > 0 || _selected.length > 0;
                group.style.display = hasContent ? '' : 'none';
            }
        }

        var selEl = document.getElementById('tmEditDepSelect');
        if (selEl) {
            selEl.addEventListener('change', function(){
                var id = toNum(this.value);
                if (!id) return;
                var task = getAvailTasks().find(function(t){return t.Id===id;});
                if (task && !_selected.find(function(s){return s.id===id;})) {
                    _selected.push({id:task.Id,name:task.Name,
                                    color:task.Color||'#888',dueDate:task.DueDate||''});
                    renderDep();
                }
                this.value='';
            });
        }

        // Rebuild dep select whenever the due date changes (updates eligible task list)
        var dueEl = document.getElementById('editTaskDue');
        if (dueEl) {
            dueEl.addEventListener('change', function() { buildDepSelect(); });
        }

        window.tmEditDepLoad = function(taskId) {
            _editingId = toNum(taskId);
            _selected  = [];
            buildDepSelect();
            renderDep();
            fetch('TM_PHP/TM_GetLinks.php?task_id='+encodeURIComponent(taskId))
                .then(function(r){return r.json();})
                .then(function(data){
                    if (!data.ok) return;
                    var tasks = getAvailTasks();
                    _selected = (data.blockers||[]).map(function(b){
                        var numId = toNum(b.id);
                        var match = tasks.find(function(t){return t.Id===numId;})||{};
                        return {id:numId,name:b.name,color:match.Color||'#888',dueDate:match.DueDate||''};
                    });
                    renderDep();
                }).catch(function(){});
        };

        buildDepSelect();
        renderDep();
    })();

})();
</script>

<!-- ══════════════════════════════════════════════════════════
     COLLABORATION STYLES (Changes 1–4)
     ══════════════════════════════════════════════════════════ -->
<style>
/* ── Comments ─────────────────────────────────────────────── */
.tm-comment-item {
    background: var(--bg);
    border-radius: 8px;
    padding: .55rem .75rem;
    font-size: 13px;
    line-height: 1.5;
}
.tm-comment-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 3px;
}
.tm-comment-author {
    font-weight: 700;
    font-size: 12px;
    color: var(--black);
}
.tm-comment-time {
    font-size: 11px;
    color: var(--gray-400);
}
.tm-comment-text {
    color: var(--gray-500);
    white-space: pre-wrap;
    word-break: break-word;
}
.tm-comment-text .mention {
    color: var(--primary, #3b82f6);
    font-weight: 600;
}
.tm-comment-delete {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--gray-400);
    padding: 0 4px;
    margin-left: auto;
    font-size: 11px;
    opacity: 0;
    transition: opacity .15s;
}
.tm-comment-item:hover .tm-comment-delete { opacity: 1; }
.tm-comment-delete:hover { color: #ef4444; }

/* ── @mention autocomplete ────────────────────────────────── */
.tm-mention-suggestions {
    position: absolute;
    z-index: 9999;
    background: var(--white, #fff);
    border: 1px solid var(--gray-200, #e5e7eb);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    min-width: 160px;
    max-height: 160px;
    overflow-y: auto;
    font-size: 13px;
}
.tm-mention-item {
    padding: 7px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}
.tm-mention-item:hover { background: var(--bg, #f9fafb); }
.tm-mention-item .mname { font-weight: 600; }
.tm-mention-item .mfull { color: var(--gray-400); font-size: 11px; }

/* ── Assigned-to pill in view modal ───────────────────────── */
.vm-assign-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #eff6ff;
    color: #1d4ed8;
    border-radius: 50px;
    padding: 3px 10px;
    font-size: 12px;
    font-weight: 600;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     COLLABORATION JAVASCRIPT (Changes 1–4)
     ══════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    // ── Cached users list (shared by all mention autocompletes) ──
    var _allUsers    = null;
    var _usersLoaded = false;

    function fetchUsers(cb) {
        if (_usersLoaded) { cb(_allUsers); return; }
        fetch('TM_PHP/TM_CollabActions.php?action=list_users')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                _allUsers    = d.ok ? (d.data || []) : [];
                _usersLoaded = true;
                cb(_allUsers);
            }).catch(function () { cb([]); });
    }

    // ── Populate assign dropdown ──────────────────────────────────
    function populateAssignSelect(selId, currentAssignedTo) {
        var sel = document.getElementById(selId);
        if (!sel) return;
        fetchUsers(function (users) {
            // Remove previously added options (keep first "Unassigned")
            while (sel.options.length > 1) sel.remove(1);
            users.forEach(function (u) {
                var opt = document.createElement('option');
                opt.value = u.user_id;
                opt.textContent = u.full_name
                    ? u.full_name + ' (@' + u.username + ')'
                    : '@' + u.username;
                if (parseInt(currentAssignedTo, 10) === u.user_id) opt.selected = true;
                sel.appendChild(opt);
            });
        });
    }

    // ── Populate project dropdown ─────────────────────────────────
    function populateProjectSelect(selId, currentProjectId) {
        var sel = document.getElementById(selId);
        if (!sel) return;
        fetch('TM_PHP/TM_CollabActions.php?action=list_projects')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                while (sel.options.length > 1) sel.remove(1);
                (d.data || []).forEach(function (p) {
                    var opt      = document.createElement('option');
                    opt.value    = p.project_id;
                    opt.textContent = p.name;
                    if (parseInt(currentProjectId, 10) === p.project_id) opt.selected = true;
                    sel.appendChild(opt);
                });
            }).catch(function () {});
    }

    // ── Patch tmOpenEdit to load collab fields ────────────────────
    var _origOpenEdit = window.tmOpenEdit;
    window.tmOpenEdit = function (id) {
        _origOpenEdit(id);

        // Fetch latest task data (assigned_to + project_id) from server
        fetch('TM_PHP/TM_CollabActions.php?action=get_task_collab&task_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                var assignedTo = d.assigned_to || 0;
                var projectId  = d.project_id  || 0;
                populateAssignSelect('tmEditAssignSelect', assignedTo);
                populateProjectSelect('tmEditProjectSelect', projectId);
            }).catch(function () {
                // If endpoint not yet available, still populate dropdowns
                populateAssignSelect('tmEditAssignSelect', 0);
                populateProjectSelect('tmEditProjectSelect', 0);
            });

        // Load comments for this task
        tmLoadComments(id);

        // Init mention autocomplete on notes
        tmInitMentionAutocomplete(
            document.getElementById('tmEditTaskNotes'),
            document.getElementById('editMentionSuggestions')
        );
        // Init mention autocomplete on comment input
        tmInitMentionAutocomplete(
            document.getElementById('tmNewCommentInput'),
            document.getElementById('commentMentionSuggestions')
        );
    };

    // ── CHANGE 3: Load & render comments ─────────────────────────
    var _currentCommentTaskId = null;

    window.tmLoadComments = function (taskId) {
        _currentCommentTaskId = taskId;
        var list  = document.getElementById('tmCommentList');
        var count = document.getElementById('tmCommentCount');
        if (!list) return;
        list.innerHTML = '<div id="tmCommentsLoading" style="text-align:center;padding:.75rem;color:var(--gray-400);font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';

        fetch('TM_PHP/TM_CollabActions.php?action=get_comments&task_id=' + encodeURIComponent(taskId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                list.innerHTML = '';
                if (!d.ok || !d.data || d.data.length === 0) {
                    list.innerHTML = '<div style="text-align:center;color:var(--gray-400);font-size:12px;padding:.5rem;">No comments yet.</div>';
                    if (count) count.textContent = '';
                    return;
                }
                if (count) count.textContent = '(' + d.data.length + ')';
                d.data.forEach(function (c) {
                    list.appendChild(tmBuildCommentEl(c));
                });
                list.scrollTop = list.scrollHeight;
            }).catch(function () {
                list.innerHTML = '<div style="color:#ef4444;font-size:12px;padding:.5rem;">Failed to load comments.</div>';
            });
    };

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function highlightMentions(text) {
        return escHtml(text).replace(/@([\w]+)/g,
            '<span class="mention">@$1</span>');
    }

    function tmBuildCommentEl(c) {
        var el = document.createElement('div');
        el.className = 'tm-comment-item';
        el.dataset.commentId = c.comment_id;

        var displayName = c.full_name && c.full_name.trim()
            ? c.full_name + ' (@' + escHtml(c.username) + ')'
            : '@' + escHtml(c.username);

        el.innerHTML =
            '<div class="tm-comment-meta">' +
                '<span class="tm-comment-author">' + displayName + '</span>' +
                '<span class="tm-comment-time">' + escHtml(c.created_fmt) + '</span>' +
                '<button class="tm-comment-delete" title="Delete comment" ' +
                        'onclick="tmDeleteComment(' + c.comment_id + ',this)">' +
                    '<i class="fa-solid fa-trash-can"></i>' +
                '</button>' +
            '</div>' +
            '<div class="tm-comment-text">' + highlightMentions(c.content) + '</div>';
        return el;
    }

    window.tmPostComment = function () {
        var inp    = document.getElementById('tmNewCommentInput');
        var btn    = document.getElementById('tmPostCommentBtn');
        var content = inp ? inp.value.trim() : '';
        if (!content || !_currentCommentTaskId) return;

        btn && (btn.disabled = true);
        var fd = new FormData();
        fd.append('action',  'add_comment');
        fd.append('task_id', _currentCommentTaskId);
        fd.append('content', content);

        fetch('TM_PHP/TM_CollabActions.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { alert(d.error || 'Failed to post comment'); return; }
                inp.value = '';
                inp.style.height = 'auto';
                tmLoadComments(_currentCommentTaskId);
            })
            .catch(function () { alert('Network error posting comment.'); })
            .finally(function () { btn && (btn.disabled = false); });
    };

    window.tmDeleteComment = function (commentId, btnEl) {
        if (!confirm('Delete this comment?')) return;
        var fd = new FormData();
        fd.append('action',     'delete_comment');
        fd.append('comment_id', commentId);
        fetch('TM_PHP/TM_CollabActions.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { alert(d.error || 'Failed to delete'); return; }
                var item = btnEl ? btnEl.closest('.tm-comment-item') : null;
                if (item) item.remove();
                // Update count
                var list  = document.getElementById('tmCommentList');
                var count = document.getElementById('tmCommentCount');
                if (list && count) {
                    var remaining = list.querySelectorAll('.tm-comment-item').length;
                    count.textContent = remaining > 0 ? '(' + remaining + ')' : '';
                }
            }).catch(function () {});
    };

    // ── CHANGE 4: @mention autocomplete ──────────────────────────
    window.tmInitMentionAutocomplete = function (textarea, suggestBox) {
        if (!textarea || !suggestBox) return;

        textarea.addEventListener('input', function () {
            var text     = textarea.value;
            var cursor   = textarea.selectionStart;
            var before   = text.slice(0, cursor);
            var atMatch  = before.match(/@([\w]*)$/);

            if (!atMatch) {
                suggestBox.style.display = 'none';
                return;
            }

            var query = atMatch[1].toLowerCase();
            fetchUsers(function (users) {
                var matches = users.filter(function (u) {
                    return u.username.toLowerCase().startsWith(query) ||
                           (u.full_name || '').toLowerCase().startsWith(query);
                }).slice(0, 6);

                if (matches.length === 0) {
                    suggestBox.style.display = 'none';
                    return;
                }

                suggestBox.innerHTML = '';
                matches.forEach(function (u) {
                    var item = document.createElement('div');
                    item.className = 'tm-mention-item';
                    item.innerHTML =
                        '<span class="mname">@' + escHtml(u.username) + '</span>' +
                        (u.full_name ? '<span class="mfull">' + escHtml(u.full_name) + '</span>' : '');
                    item.addEventListener('mousedown', function (e) {
                        e.preventDefault(); // prevent textarea blur
                        // Replace the @partial with the full @username
                        var newBefore = before.replace(/@([\w]*)$/, '@' + u.username + ' ');
                        textarea.value = newBefore + text.slice(cursor);
                        textarea.selectionStart = textarea.selectionEnd = newBefore.length;
                        suggestBox.style.display = 'none';
                        textarea.focus();
                    });
                    suggestBox.appendChild(item);
                });
                suggestBox.style.display = 'block';
            });
        });

        textarea.addEventListener('blur', function () {
            // Small delay so mousedown on suggestion fires first
            setTimeout(function () { suggestBox.style.display = 'none'; }, 150);
        });
    };

    // ── View modal: show assigned user ────────────────────────────
    var _origOpenView = window.tmOpenView;
    window.tmOpenView = function (id) {
        _origOpenView(id);
        // Fetch collab details and inject assigned-to pill into view modal
        fetch('TM_PHP/TM_CollabActions.php?action=get_task_collab&task_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok || !d.assigned_username) return;
                var body = document.getElementById('viewModalBody');
                if (!body) return;
                // Append assign pill if not already there
                if (!body.querySelector('.vm-assign-pill')) {
                    var pill = document.createElement('div');
                    pill.style.cssText = 'margin-top:.75rem;';
                    pill.innerHTML =
                        '<span class="vm-label" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-400);">Assigned To</span><br>' +
                        '<span class="vm-assign-pill"><i class="fa-solid fa-user"></i>' +
                        escHtml(d.assigned_username) + '</span>';
                    body.appendChild(pill);
                }
            }).catch(function () {});
    };

    // ── FEATURE 10: Reassign / Delegate ──────────────────────────
    // Show the Delegate section only for moderators/admins.
    // PHP embeds a flag so JS knows whether to show it.
    var _isModerator = <?= tm_is_moderator() ? 'true' : 'false' ?>;
    var _reassignTaskId = null;

    // Populate the reassign user dropdown (reuses fetchUsers)
    function populateReassignSelect(currentOwnerId) {
        var sel = document.getElementById('tmReassignSelect');
        if (!sel) return;
        fetchUsers(function (users) {
            sel.innerHTML = '<option value="">— Pick a user —</option>';
            users.forEach(function (u) {
                if (u.user_id === currentOwnerId) return; // skip current owner
                var opt = document.createElement('option');
                opt.value = u.user_id;
                opt.textContent = u.full_name
                    ? u.full_name + ' (@' + u.username + ')'
                    : '@' + u.username;
                sel.appendChild(opt);
            });
        });
    }

    // Patch tmOpenEdit to show/hide and populate the reassign panel
    var _origOpenEditCollab = window.tmOpenEdit;
    window.tmOpenEdit = function (id) {
        _origOpenEditCollab(id);
        _reassignTaskId = id;

        var grp = document.getElementById('tmReassignGroup');
        var fb  = document.getElementById('tmReassignFeedback');
        if (fb) fb.textContent = '';

        if (_isModerator && grp) {
            grp.style.display = '';
            // Fetch current owner to exclude from dropdown
            fetch('TM_PHP/TM_CollabActions.php?action=get_task_collab&task_id=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var ownerId = d.ok ? (d.owner_id || 0) : 0;
                    populateReassignSelect(ownerId);
                }).catch(function () { populateReassignSelect(0); });
        } else if (grp) {
            grp.style.display = 'none';
        }
    };

    // Execute delegation via AJAX — no full page reload needed
    window.tmDoReassign = function () {
        var sel = document.getElementById('tmReassignSelect');
        var fb  = document.getElementById('tmReassignFeedback');
        var btn = document.getElementById('tmReassignBtn');
        if (!sel || !_reassignTaskId) return;

        var toUserId = parseInt(sel.value, 10);
        if (!toUserId) {
            if (fb) { fb.style.color = '#ef4444'; fb.textContent = 'Please select a user to delegate to.'; }
            return;
        }

        btn && (btn.disabled = true);
        if (fb) { fb.style.color = 'var(--gray-500)'; fb.textContent = 'Delegating…'; }

        var fd = new FormData();
        fd.append('action',      'reassign');
        fd.append('task_id',     _reassignTaskId);
        fd.append('to_user_id',  toUserId);

        fetch('TM_PHP/TM_TaskActions.php?format=json', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    if (fb) { fb.style.color = '#15803d'; fb.textContent = '✓ Task delegated successfully. Reloading…'; }
                    setTimeout(function () { window.location.reload(); }, 900);
                } else {
                    if (fb) { fb.style.color = '#ef4444'; fb.textContent = d.error || 'Delegation failed.'; }
                    btn && (btn.disabled = false);
                }
            }).catch(function () {
                if (fb) { fb.style.color = '#ef4444'; fb.textContent = 'Network error. Please try again.'; }
                btn && (btn.disabled = false);
            });
    };
    // ── END FEATURE 10 ───────────────────────────────────────────

})();
</script>
