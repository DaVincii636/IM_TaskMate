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
    // Fetch full task data for this user so the modal can populate all fields
    $_modal_stmt = tm_exec(
        "SELECT task_id, task_name,
                TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
                TO_CHAR(due_date,'YYYY-MM-DD')   AS due_date,
                category, custom_category, priority, color, notes, status
         FROM TM_Tasks WHERE user_id = :p1 ORDER BY due_date ASC",
        [tm_uid()]
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
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-header" style="border-bottom:none;padding-bottom:.25rem;">
            <div class="modal-title" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);">Task Details</div>
            <button class="modal-close" onclick="closeModal('taskViewModal')">&#x2715;</button>
        </div>
        <div class="modal-body" id="viewModalBody" style="padding-top:0;overflow:hidden;">
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
                              placeholder="Optional notes..." rows="3"
                              style="resize:none;overflow:hidden;"></textarea>
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
            status:   r['status']           || r['STATUS']           || 'pending',
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
                review:'Review',done:'Done',cancelled:'Cancelled'}[s] || s;
    }
    function statusClass(s) {
        return {pending:'status-pending',in_progress:'status-in-progress',
                review:'status-review',done:'status-done',
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
                        && t.status !== 'done' && t.status !== 'cancelled';

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
          +   (isOverdue ? '<span style="background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;padding:3px 10px;border-radius:50px;">⚠ Overdue</span>' : '')
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
        document.getElementById('tmEditTaskStatus').value    = t.status;
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
        // Inline validation (Improvement 4)
        if (typeof validateFields === 'function') {
            var ok = validateFields([
                { id: 'editTaskName',  label: 'Task name' },
                { id: 'editTaskStart', label: 'Start date', validate: function (v) {
                    if (!v) return 'Start date is required.';
                }},
                { id: 'editTaskDue',   label: 'Due date', validate: function (v) {
                    if (!v) return 'Due date is required.';
                    var start = document.getElementById('editTaskStart');
                    if (start && start.value && v < start.value) return 'Due date cannot be before start date.';
                }},
            ]);
            if (!ok) return;
        }
        var name = document.getElementById('editTaskName').value || 'this task';
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
                       !['done','cancelled'].includes((t.Status||'').toLowerCase()) &&
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
