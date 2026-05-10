<?php
/**
 * TM_PHP/TM_AddTaskModal.php
 * ─────────────────────────────────────────────────────────────
 * Shared partial: Add Task modal.
 * Used by TM_Dashboard.php and TM_Calendar.php so they are
 * always identical — no drift between pages.
 *
 * Requires:
 *   - TM_Style.css + TM_Calendar.css already linked on the page
 *   - Font Awesome 6 already linked
 *   - openModal() / closeModal() available (TM_App.js)
 *
 * The form action path must work from the root of the project.
 * Both Dashboard and Calendar live at the root, so the path is:
 *   action="TM_PHP/TM_TaskActions.php"
 *
 * COLLABORATION ADDITIONS (Changes 1 & 2):
 *   - assigned_to: user dropdown populated via TM_CollabActions.php
 *   - project_id:  project dropdown populated via TM_CollabActions.php
 */
?>
<!-- ══════════════════════════════════════════════════════════
     ADD TASK MODAL  (shared partial — TM_PHP/TM_AddTaskModal.php)
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addTaskModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">New Task</div>
            <button class="modal-close" onclick="closeModal('addTaskModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TaskActions.php" id="addTaskForm" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
            <input type="hidden" name="action" value="add"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Task Name</label>
                    <input type="text" name="name" class="form-input" id="addTaskName"
                           placeholder="e.g. Buy groceries" required/>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="startDate" class="form-input" id="addTaskStart" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="dueDate" class="form-input" id="addTaskDue" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <div class="category-options" id="addCatOptions">
                        <button type="button" class="cat-btn active" data-cat="errands">Errands</button>
                        <button type="button" class="cat-btn" data-cat="school">School</button>
                        <button type="button" class="cat-btn" data-cat="medicine">Medicine</button>
                        <button type="button" class="cat-btn" data-cat="others">Others</button>
                    </div>
                    <input type="hidden" name="category" id="addCategoryInput" value="errands"/>
                    <div id="addOthersWrap" style="display:none;margin-top:8px">
                        <input type="text" name="customCategory" class="form-input"
                               placeholder="Specify category..."/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <div class="priority-options" id="addPriorityOptions">
                        <button type="button" class="priority-btn high" data-priority="high">High</button>
                        <button type="button" class="priority-btn mid active" data-priority="mid">Mid</button>
                        <button type="button" class="priority-btn low" data-priority="low">Low</button>
                    </div>
                    <input type="hidden" name="priority" id="addPriorityInput" value="mid"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Task Color</label>
                    <div class="color-picker-row" id="addColorRow"></div>
                    <input type="hidden" name="color" id="addColorInput" value="#ef4444"/>
                </div>
                <div class="form-group dep-group">
                    <label class="form-label">Must Complete First</label>
                    <select id="addDepSelect" class="form-input dep-select">
                        <option value="">— Pick a task —</option>
                    </select>
                    <div class="dep-selected" id="addDepSelected"></div>
                    <input type="hidden" id="addDepBlockerIds" name="blocker_ids" value=""/>
                </div>
                <div class="form-group">
                    <label class="form-label">Recurrence</label>
                    <select name="recurrence" class="form-input" id="addRecurrence">
                        <option value="">— None (one-time) —</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>

                <!-- ── Organization task checkbox (admin only) ──────── -->
                <?php if (function_exists('tm_is_admin') && tm_is_admin()): ?>
                <div class="form-group">
                    <label class="form-label">Organization-wide Task</label>
                    <label class="form-toggle-row">
                        <input type="checkbox" name="is_org_task" id="addIsOrgTask" value="1" class="form-toggle-input"/>
                        <span class="form-toggle-track">
                            <span class="form-toggle-thumb"></span>
                        </span>
                        <span class="form-toggle-text">Mark as a shared task visible to all org members</span>
                    </label>
                </div>

                <!-- ── CHANGE 1: Assign to user (admin only) ──────────── -->
                <div class="form-group" id="addAssignGroup">
                    <label class="form-label">
                        Assign To
                    </label>
                    <select name="assigned_to" class="form-input" id="addAssignSelect">
                        <option value="">— Unassigned —</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input tm-auto-expand" id="addTaskNotes"
                              placeholder="Optional notes…"
                              style="resize:none;overflow:hidden;"></textarea>
                    <!-- @mention autocomplete suggestions -->
                    <div id="addMentionSuggestions" class="tm-mention-suggestions" style="display:none;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel"
                        onclick="closeModal('addTaskModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Task</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Populate assign dropdown when Add modal opens (admin only) ────────────────
(function () {
    var _usersLoaded = false;

    function loadUsers() {
        if (_usersLoaded) return;
        fetch('TM_PHP/TM_CollabActions.php?action=list_users')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                _usersLoaded = true;
                var sel = document.getElementById('addAssignSelect');
                if (!sel) return;
                (data.data || []).forEach(function (u) {
                    var opt = document.createElement('option');
                    opt.value       = u.user_id;
                    opt.textContent = u.full_name
                        ? u.full_name + ' (@' + u.username + ')'
                        : '@' + u.username;
                    sel.appendChild(opt);
                });
            }).catch(function () {});
    }

    // Load on first open of the Add modal
    var overlay = document.getElementById('addTaskModal');
    if (overlay) {
        var observer = new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                if (m.attributeName === 'class' &&
                    overlay.classList.contains('active')) {
                    loadUsers();
                }
            });
        });
        observer.observe(overlay, { attributes: true });
    }

    // ── Org-wide toggle hides Assign To ───────────────────────────────────────
    var orgCheckbox   = document.getElementById('addIsOrgTask');
    var assignGroup   = document.getElementById('addAssignGroup');
    var assignSelect  = document.getElementById('addAssignSelect');
    if (orgCheckbox && assignGroup) {
        orgCheckbox.addEventListener('change', function () {
            if (this.checked) {
                assignGroup.style.display = 'none';
                if (assignSelect) assignSelect.value = '';
            } else {
                assignGroup.style.display = '';
            }
        });
    }

    // ── @mention autocomplete for Add modal notes ─────────────────────────────
    tmInitMentionAutocomplete(
        document.getElementById('addTaskNotes'),
        document.getElementById('addMentionSuggestions')
    );
})();
</script>