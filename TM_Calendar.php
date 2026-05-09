<?php
require_once 'TM_PHP/TM_Session.php';
require_once 'TM_PHP/TM_DB.php';
tm_require_login();

$flash = tm_get_flash();
$uid   = tm_uid();

// Load this user's tasks from Oracle
$stmt  = tm_exec(
    "SELECT task_id, task_name, TO_CHAR(start_date,'YYYY-MM-DD') AS start_date,
            TO_CHAR(due_date,'YYYY-MM-DD') AS due_date,
            category, custom_category, priority, color, notes, status
     FROM TM_Tasks WHERE user_id = :p1 ORDER BY start_date ASC",
    [$uid]
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
        <a href="TM_Dashboard.php" class="btn-logout">Home</a>
        <a href="TM_Calendar.php"  class="btn-logout" style="font-weight:700;">Calendar</a>
        <a href="TM_Tasks.php"    class="btn-logout">Tasks</a>
        <a href="TM_Activity.php" class="btn-logout">Activity</a>
        <!-- Notification Bell -->
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
<div class="modal-overlay" id="addTaskModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">New Task</div>
            <button class="modal-close" onclick="closeModal('addTaskModal')">&#x2715;</button>
        </div>
        <form method="post" action="TM_PHP/TM_TaskActions.php">
            <input type="hidden" name="action" value="add"/>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Task Name</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Buy groceries" required/>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="startDate" class="form-input" required/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="dueDate" class="form-input" required/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <div class="category-options">
                        <button type="button" class="cat-btn active" data-cat="errands">Errands</button>
                        <button type="button" class="cat-btn" data-cat="school">School</button>
                        <button type="button" class="cat-btn" data-cat="medicine">Medicine</button>
                        <button type="button" class="cat-btn" data-cat="others">Others</button>
                    </div>
                    <input type="hidden" name="category" id="addCategoryInput" value="errands"/>
                    <div id="addOthersWrap" style="display:none;margin-top:8px">
                        <input type="text" name="customCategory" class="form-input" placeholder="Specify category..."/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <div class="priority-options">
                        <button type="button" class="priority-btn high" data-priority="high">🔴 High</button>
                        <button type="button" class="priority-btn mid active" data-priority="mid">🟡 Mid</button>
                        <button type="button" class="priority-btn low" data-priority="low">🟢 Low</button>
                    </div>
                    <input type="hidden" name="priority" id="addPriorityInput" value="mid"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Task Color</label>
                    <div class="color-picker-row" id="addColorRow"></div>
                    <input type="hidden" name="color" id="addColorInput" value="#ef4444"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input" placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addTaskModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Task</button>
            </div>
        </form>
    </div>
</div>

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
                        <button type="button" class="priority-btn high" data-priority="high">🔴 High</button>
                        <button type="button" class="priority-btn mid" data-priority="mid">🟡 Mid</button>
                        <button type="button" class="priority-btn low" data-priority="low">🟢 Low</button>
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
                        <option value="pending">&#x23F3; Pending</option>
                        <option value="in_progress">&#x1F504; In Progress</option>
                        <option value="review">&#x1F50D; Review</option>
                        <option value="done">&#x2705; Done</option>
                        <option value="cancelled">&#x274C; Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input" id="editTaskNotes" placeholder="Optional notes..."></textarea>
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
            <button class="pc-modal-confirm-blue" onclick="document.getElementById('editTaskForm').submit()">
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
    // Oracle tasks passed from PHP → JS (replaces localStorage)
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
        Status:         t.status || 'pending'
    }));

    function openDeleteTaskModal() {
        const name = document.getElementById('editTaskName').value || 'this task';
        const id   = document.getElementById('editTaskId').value;
        document.getElementById('deleteTaskName').textContent = name;
        document.getElementById('deleteTaskId').value = id;
        closeModal('editTaskModal');
        openPcModal('deleteTaskModal');
    }
    function openSaveTaskModal() {
        const name = document.getElementById('editTaskName').value || 'this task';
        document.getElementById('saveTaskModalText').innerHTML =
            'Save changes to <strong>' + name + '</strong>?';
        openPcModal('saveTaskModal');
    }
    function openPcModal(id)  { document.getElementById(id).classList.add('active'); }
    function closePcModal(id) { document.getElementById(id).classList.remove('active'); }
</script>
<script src="TM_JS/TM_App.js"></script>
<script src="TM_JS/TM_Calendar.js"></script>

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
</body>
</html>