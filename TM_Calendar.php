<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash = tm_get_flash();
$uid   = tm_uid();

// ── Feature 8: Team filter ────────────────────────────────────────────────────
$filterTeam = (int)($_GET['team'] ?? 0);

// Load teams the current user belongs to (for the filter dropdown)
$_tstmt  = tm_exec(
    "SELECT t.team_id, t.team_name FROM TM_Teams t
     JOIN TM_TeamMembers m ON m.team_id = t.team_id
     WHERE m.user_id = :p1
     ORDER BY t.team_name",
    [$uid]
);
$myTeams = tm_fetch_all($_tstmt);

// Build the WHERE clause and params based on whether a team filter is active
$taskWhere  = 'user_id = :p1';
$taskParams = [$uid];
$activeTeamName = '';

if ($filterTeam > 0) {
    // Security: verify the current user actually belongs to this team
    $chk = tm_exec(
        'SELECT COUNT(*) FROM TM_TeamMembers WHERE team_id = :p1 AND user_id = :p2',
        [$filterTeam, $uid]
    );
    if ((int)tm_scalar($chk) > 0) {
        // Get all member user_ids in this team
        $mStmt     = tm_exec('SELECT user_id FROM TM_TeamMembers WHERE team_id = :p1', [$filterTeam]);
        $memberIds = array_column(tm_fetch_all($mStmt), 'user_id');
        if (!empty($memberIds)) {
            $inList     = implode(',', array_map('intval', $memberIds));
            $taskWhere  = "user_id IN ($inList)";
            $taskParams = [];
        }
        // Get team name for the UI label
        $tnRow = tm_fetch_one(tm_exec('SELECT team_name FROM TM_Teams WHERE team_id = :p1', [$filterTeam]));
        $activeTeamName = $tnRow['team_name'] ?? '';
    } else {
        $filterTeam = 0; // reset invalid/unauthorised filter
    }
}
// ── End Feature 8 ─────────────────────────────────────────────────────────────

// Load tasks (scoped to user or team)
$stmt  = tm_exec(
    "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
            TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
            category, custom_category, priority, color, notes, status, recurrence
     FROM TM_Tasks WHERE $taskWhere ORDER BY start_date ASC",
    $taskParams
);
$tasks = tm_fetch_all($stmt);
// Convert any CLOB/OCILob/resource objects to plain strings before JSON encoding
$tasks = array_map(function($row) {
    if (isset($row['notes'])) {
        if ($row['notes'] instanceof OCILob) {
            $row['notes'] = $row['notes']->load();
        } elseif (is_resource($row['notes'])) {
            $row['notes'] = stream_get_contents($row['notes']);
        }
        $row['notes'] = (string)($row['notes'] ?? '');
    }
    return $row;
}, $tasks);
$tasksJson = json_encode($tasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if ($tasksJson === false) { $tasksJson = '[]'; } // fallback if encoding fails

// ── Notifications ─────────────────────────────────────────────────────────────
require_once 'TM_PHP/TM_NavNotif.php';

// ── Blocker counts: how many unresolved blockers each task has ────────────────
// Keyed by task_id → count of blocking tasks not yet done.
// Used by JS to show the "Blocked by X" indicator on calendar dots.
$blockerCountRows = tm_fetch_all(tm_exec(
    "SELECT tl.task_id, COUNT(*) AS blocker_count
     FROM TM_TaskLinks tl
     JOIN TM_Tasks blocker ON blocker.task_id = tl.depends_on_id
     WHERE tl.link_type = 'blocks'
       AND blocker.status NOT IN ('done', 'cancelled')
       AND tl.task_id IN (
           SELECT task_id FROM TM_Tasks WHERE $taskWhere
       )
     GROUP BY tl.task_id",
    $taskParams
));
$blockerMap = [];
foreach ($blockerCountRows as $row) {
    $tid = (int)($row['TASK_ID'] ?? $row['task_id']);
    $blockerMap[$tid] = (int)($row['BLOCKER_COUNT'] ?? $row['blocker_count']);
}
$blockerMapJson = json_encode($blockerMap);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Calendar - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="TM_CSS/TM_Calendar.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body>

<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <span class="navbar-user">Hello, <strong><?= htmlspecialchars(tm_uname()) ?></strong></span>
        <a href="TM_Profile.php" class="btn-logout" title="My Profile" style="display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-user-circle"></i></a>
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout" style="font-weight:700;">Calendar</a>
        <a href="TM_Tasks.php"    class="btn-logout">Tasks</a>
        <a href="TM_Activity.php" class="btn-logout">Activity</a>
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

    <div class="calendar-header">
        <div class="calendar-nav">
            <button class="nav-btn" id="prevMonth">&#60;</button>
            <div class="month-year-label" id="monthYearLabel"></div>
            <button class="nav-btn" id="nextMonth">&#62;</button>
            <input type="number" class="year-input" id="yearInput" min="1900" max="2100"/>
        </div>
        <div class="calendar-controls">
            <?php if (!empty($myTeams)): ?>
            <!-- Feature 8: Team filter -->
            <form method="get" action="TM_Calendar.php" style="display:inline-flex;align-items:center;gap:6px;">
                <select name="team" class="filter-select"
                        title="Filter calendar by team"
                        onchange="this.form.submit()"
                        style="font-size:12px;padding:6px 10px;border-radius:50px;border:1.5px solid var(--border);font-family:'Poppins',sans-serif;background:var(--white);cursor:pointer;">
                    <option value="">&#127991; All Teams</option>
                    <?php foreach ($myTeams as $t):
                        $tId = (int)($t['team_id'] ?? 0);
                    ?>
                    <option value="<?= $tId ?>" <?= $filterTeam === $tId ? 'selected' : '' ?>>
                        &#127991; <?= htmlspecialchars($t['team_name'] ?? '') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <div class="toggle-wrap">
                <span class="toggle-label">Gantt View</span>
                <label class="toggle-switch">
                    <input type="checkbox" id="ganttToggle"/>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <button class="btn-today" id="btnToday">Today</button>
            <button class="btn-add-task" id="btnAddTask">+ Add Task</button>
        </div>
    </div>

    <?php if ($filterTeam > 0 && $activeTeamName): ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12px;font-weight:600;color:#15803d;">
        <i class="fa-solid fa-people-group"></i>
        Showing tasks for team: <strong><?= htmlspecialchars($activeTeamName) ?></strong>
        <a href="TM_Calendar.php" style="margin-left:auto;color:#6b7280;font-weight:500;text-decoration:none;" title="Clear filter">&#x2715; Clear</a>
    </div>
    <?php endif; ?>

    <div class="legend-bar">
        <span class="legend-title">Categories:</span>
        <div class="legend-item"><div class="legend-dot" style="background:#f97316"></div><span>Errands</span></div>
        <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div><span>School</span></div>
        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><span>Medicine</span></div>
        <div class="legend-item"><div class="legend-dot" style="background:#9a9a9a"></div><span>Others</span></div>
    </div>

    <?php if ($flash): ?>
        <div class="<?= $flash['type']==='error'?'validation-summary':'success-banner' ?>" style="display:none">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div id="calendarWrapper">
        <div class="calendar-card">
            <div class="calendar-weekdays">
                <div class="weekday-cell">Sun</div><div class="weekday-cell">Mon</div>
                <div class="weekday-cell">Tue</div><div class="weekday-cell">Wed</div>
                <div class="weekday-cell">Thu</div><div class="weekday-cell">Fri</div>
                <div class="weekday-cell">Sat</div>
            </div>
            <div class="calendar-days" id="calendarDays"></div>
        </div>
    </div>

    <div class="gantt-wrapper" id="ganttWrapper">
        <div class="calendar-card">
            <div class="gantt-header">
                <div class="gantt-label-col">Task</div>
                <div class="gantt-days-header" id="ganttDaysHeader"></div>
            </div>
            <div class="gantt-body" id="ganttBody"></div>
        </div>
    </div>

</main>

<!-- ADD TASK MODAL -->
<?php require_once 'TM_PHP/TM_AddTaskModal.php'; ?>

<!-- EDIT TASK MODAL -->
<div class="modal-overlay" id="editTaskModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Edit Task</div>
            <button class="modal-close" onclick="closeModal('editTaskModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TaskActions.php" id="editTaskForm">
            <input type="hidden" name="action" value="edit"/>
            <input type="hidden" name="id" id="editTaskId"/>
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
                    <div class="category-options" id="editCatOptions">
                        <button type="button" class="cat-btn" data-cat="errands">Errands</button>
                        <button type="button" class="cat-btn" data-cat="school">School</button>
                        <button type="button" class="cat-btn" data-cat="medicine">Medicine</button>
                        <button type="button" class="cat-btn" data-cat="others">Others</button>
                    </div>
                    <input type="hidden" name="category" id="editCategoryInput" value="errands"/>
                    <div id="editOthersWrap" style="display:none;margin-top:8px">
                        <input type="text" name="customCategory" class="form-input" id="editCustomCat" placeholder="Specify category..."/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <div class="priority-options" id="editPriorityOptions">
                        <button type="button" class="priority-btn high" data-priority="high">High</button>
                        <button type="button" class="priority-btn mid" data-priority="mid">Mid</button>
                        <button type="button" class="priority-btn low" data-priority="low">Low</button>
                    </div>
                    <input type="hidden" name="priority" id="editPriorityInput" value="mid"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Task Color</label>
                    <div class="color-picker-row" id="editColorRow"></div>
                    <input type="hidden" name="color" id="editColorInput" value="#ef4444"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input" id="editTaskStatus">
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="review">Review</option>
                        <option value="done">Done</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-group dep-group">
                    <label class="form-label">Must Complete First</label>

                    <select id="depSelect" class="form-input dep-select">
                        <option value="">— Pick a task —</option>
                    </select>
                    <div class="dep-selected" id="depSelected"></div>
                    <input type="hidden" id="depBlockerIds" name="blocker_ids" value=""/>
                </div>

                <div class="form-group">
                    <label class="form-label">Recurrence</label>
                    <select name="recurrence" class="form-input" id="calEditRecurrence">
                        <option value="">— None (one-time) —</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input tm-auto-expand" id="editTaskNotes"
                              placeholder="Optional notes..."
                              style="resize:none;overflow:hidden;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" style="color:#ef4444" onclick="openDeleteTaskModal()">Delete</button>
                <button type="button" class="btn-cancel" onclick="closeModal('editTaskModal')">Cancel</button>
                <button type="button" class="btn-save" onclick="openSaveTaskModal()">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- SAVE TASK PC-MODAL -->
<div id="saveTaskModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(59,130,246,.12)">
            <i class="fa-solid fa-floppy-disk" style="color:#3b82f6"></i>
        </div>
        <div class="pc-modal-title">Save Changes?</div>
        <div class="pc-modal-body" id="saveTaskModalText">Save changes to this task?</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('saveTaskModal')">Cancel</button>
            <button class="pc-modal-confirm-blue" onclick="tmDoSave()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- DELETE TASK PC-MODAL -->
<div id="deleteTaskModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(239,68,68,.12)">
            <i class="fa-solid fa-trash" style="color:#ef4444"></i>
        </div>
        <div class="pc-modal-title">Delete Task?</div>
        <div class="pc-modal-body">
            Delete <strong id="deleteTaskName"></strong>? This <strong>cannot be undone</strong>.
        </div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" onclick="closePcModal('deleteTaskModal')">Cancel</button>
            <button class="pc-modal-confirm-red" onclick="document.getElementById('deleteTaskForm').submit()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<form method="post" action="TM_PHP/TM_TaskActions.php" id="deleteTaskForm" style="display:none">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="id" id="deleteTaskId"/>
</form>

<div class="task-tooltip" id="taskTooltip"></div>
<div class="toast" id="toast"></div>

<script>
    // Oracle tasks passed from PHP → JS
    const serverTasks = <?= $tasksJson ?>.map(t => ({
        Id:             t.task_id,
        Name:           t.task_name,
        StartDate:      t.start_date,
        DueDate:        t.due_date,
        Category:       t.category,
        CustomCategory: t.custom_category,
        Priority:       t.priority,
        Color:          t.color,
        Notes:          t.notes,
        Status:         t.status || 'pending',
        Recurrence:     t.recurrence || ''
    }));

    // Map of task_id → unresolved blocker count (for calendar dot indicators)
    const blockerMap = <?= $blockerMapJson ?>;

    function openDeleteTaskModal() {
        const name = document.getElementById('editTaskName').value || 'this task';
        const id   = document.getElementById('editTaskId').value;
        document.getElementById('deleteTaskName').textContent = name;
        document.getElementById('deleteTaskId').value = id;
        closeModal('editTaskModal');
        openPcModal('deleteTaskModal');
    }

    // Save: first persist links via fetch, then submit the edit form
    function openSaveTaskModal() {
        const name = document.getElementById('editTaskName').value || 'this task';
        document.getElementById('saveTaskModalText').innerHTML =
            'Save changes to <strong>' + name + '</strong>?';
        openPcModal('saveTaskModal');
    }

    // Called by the "Save Changes" button inside the save pc-modal
    function tmDoSave() {
        const taskId    = document.getElementById('editTaskId').value;
        const blockerIds = document.getElementById('depBlockerIds').value;

        const fd = new FormData();
        fd.append('action',      'save_links');
        fd.append('task_id',     taskId);
        fd.append('blocker_ids', blockerIds);

        fetch('TM_PHP/TM_LinkActions.php', { method: 'POST', body: fd })
            .finally(() => {
                // Always submit the main edit form regardless of link result
                document.getElementById('editTaskForm').submit();
            });
    }

    function openPcModal(id)  { document.getElementById(id).classList.add('active'); }
    function closePcModal(id) { document.getElementById(id).classList.remove('active'); }
</script>
<script src="TM_JS/TM_App.js"></script>
<script src="TM_JS/TM_Calendar.js"></script>
<script>
// ── Inline validation: Add Task form (Improvement 4) ──────────────────────
(function () {
    var form = document.getElementById('addTaskForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        var ok = validateFields([
            { id: 'addTaskName',  label: 'Task name' },
            { id: 'addTaskStart', label: 'Start date', validate: function (v) {
                if (!v) return 'Start date is required.';
            }},
            { id: 'addTaskDue',   label: 'Due date', validate: function (v) {
                if (!v) return 'Due date is required.';
                var start = document.getElementById('addTaskStart');
                if (start && start.value && v < start.value) return 'Due date cannot be before start date.';
            }},
        ]);
        if (!ok) e.preventDefault();
    });
})();

// ── Inline validation: Edit Task form (Improvement 4) ─────────────────────
(function () {
    // The edit form save goes through tmDoSave() → editTaskForm.submit()
    // Intercept the Save Changes button click instead.
    var origTmOpenSaveConfirm = window.tmOpenSaveConfirm;
    window.tmOpenSaveConfirm = function () {
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
        if (!ok) return; // Don't open confirm modal if invalid
        if (origTmOpenSaveConfirm) origTmOpenSaveConfirm();
    };
})();
</script>

<!-- LOGOUT PC-MODAL -->
<div id="logoutModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(231,76,60,.12)">
            <i class="fa-solid fa-arrow-right-from-bracket" style="color:#e74c3c"></i>
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
<script>
(function(){
    var btn=document.getElementById('logoutBtn');
    var modal=document.getElementById('logoutModal');
    var cancel=document.getElementById('logoutCancel');
    if(btn) btn.addEventListener('click',function(e){e.preventDefault();modal.classList.add('active');});
    if(cancel) cancel.addEventListener('click',function(){modal.classList.remove('active');});
    if(modal) modal.addEventListener('click',function(e){if(e.target===modal)modal.classList.remove('active');});
})();
</script>
<script>
(function () {
    // ── Auto-expand textareas ──────────────────────────────
    function autoExpand(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }
    document.querySelectorAll('textarea.tm-auto-expand').forEach(function (ta) {
        ta.addEventListener('input', function () { autoExpand(ta); });
    });

    // ── Daily recurrence: sync start & due in Add modal ───
    (function () {
        var recEl   = document.getElementById('addRecurrence');
        var startEl = document.getElementById('addTaskStart');
        var dueEl   = document.getElementById('addTaskDue');
        if (!recEl || !startEl || !dueEl) return;
        recEl.addEventListener('change', function () {
            if (recEl.value === 'daily' && startEl.value) dueEl.value = startEl.value;
        });
        startEl.addEventListener('change', function () {
            if (recEl.value === 'daily') dueEl.value = startEl.value;
        });
        dueEl.addEventListener('change', function () {
            if (recEl.value === 'daily') startEl.value = dueEl.value;
        });
    })();

    // ── Daily recurrence: sync start & due in Edit modal ──
    (function () {
        var recEl   = document.getElementById('calEditRecurrence');
        var startEl = document.getElementById('editTaskStart');
        var dueEl   = document.getElementById('editTaskDue');
        if (!recEl || !startEl || !dueEl) return;
        recEl.addEventListener('change', function () {
            if (recEl.value === 'daily' && startEl.value) dueEl.value = startEl.value;
        });
        startEl.addEventListener('change', function () {
            if (recEl.value === 'daily') dueEl.value = startEl.value;
        });
        dueEl.addEventListener('change', function () {
            if (recEl.value === 'daily') startEl.value = dueEl.value;
        });
    })();
})();
</script>
</body>
</html>