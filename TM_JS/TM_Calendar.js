const CalendarApp = (() => {

    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    const ROYGBIV = [
        { name: 'Red', hex: '#ef4444' },
        { name: 'Orange', hex: '#f97316' },
        { name: 'Yellow', hex: '#eab308' },
        { name: 'Green', hex: '#22c55e' },
        { name: 'Blue', hex: '#3b82f6' },
        { name: 'Indigo', hex: '#6366f1' },
        { name: 'Violet', hex: '#a855f7' },
    ];

    let tasks = Array.isArray(serverTasks) ? serverTasks : [];
    let today = new Date();
    // Restore last viewed month/year from sessionStorage so calendar stays
    // on the user's current view after adding or editing a task.
    let _savedYear  = parseInt(sessionStorage.getItem('tm_cal_year')  || '', 10);
    let _savedMonth = parseInt(sessionStorage.getItem('tm_cal_month') || '', 10);
    let viewYear  = (!isNaN(_savedYear)  && _savedYear  >= 1900 && _savedYear  <= 2100) ? _savedYear  : today.getFullYear();
    let viewMonth = (!isNaN(_savedMonth) && _savedMonth >= 0    && _savedMonth <= 11)   ? _savedMonth : today.getMonth();
    let isGantt = false;

    // ---- Helpers ----
    function daysInMonth(y, m) { return new Date(y, m + 1, 0).getDate(); }
    function firstDay(y, m) { return new Date(y, m, 1).getDay(); }
    function isToday(y, m, d) { return today.getFullYear() === y && today.getMonth() === m && today.getDate() === d; }
    function pad(n) { return String(n).padStart(2, '0'); }
    function dateStr(y, m, d) { return `${y}-${pad(m + 1)}-${pad(d)}`; }
    function friendlyDate(s) {
        if (!s) return '';
        const [y, m, d] = s.split('-').map(Number);
        return `${MONTHS[m - 1].slice(0, 3)} ${d}, ${y}`;
    }

    function getTasksForDay(y, m, d) {
        const ds = dateStr(y, m, d);
        return tasks.filter(t => t.StartDate <= ds && t.DueDate >= ds);
    }

    function getTasksForMonth(y, m) {
        const start = dateStr(y, m, 1);
        const end = dateStr(y, m, daysInMonth(y, m));
        return tasks.filter(t => t.StartDate <= end && t.DueDate >= start);
    }

    // ---- Render ----
    function render() {
        document.getElementById('monthYearLabel').textContent = `${MONTHS[viewMonth]} ${viewYear}`;
        document.getElementById('yearInput').value = viewYear;
        // Persist so page reload (after add/edit) returns to the same view
        sessionStorage.setItem('tm_cal_year',  viewYear);
        sessionStorage.setItem('tm_cal_month', viewMonth);
        isGantt ? renderGantt() : renderCalendar();
    }

    function renderCalendar() {
        const grid = document.getElementById('calendarDays');
        grid.innerHTML = '';
        const total = daysInMonth(viewYear, viewMonth);
        const first = firstDay(viewYear, viewMonth);
        const prevTotal = daysInMonth(viewYear, viewMonth === 0 ? 11 : viewMonth - 1);

        // Prev month padding
        for (let i = first - 1; i >= 0; i--) {
            grid.appendChild(makeCell(prevTotal - i, true,
                viewYear, viewMonth === 0 ? 11 : viewMonth - 1));
        }
        // Current month
        for (let d = 1; d <= total; d++) {
            grid.appendChild(makeCell(d, false, viewYear, viewMonth));
        }
        // Next month padding
        const rem = (first + total) % 7;
        for (let d = 1; d <= (rem === 0 ? 0 : 7 - rem); d++) {
            grid.appendChild(makeCell(d, true, viewYear, viewMonth === 11 ? 0 : viewMonth + 1));
        }
    }

    function makeCell(day, isOther, y, m) {
        const cell = document.createElement('div');
        cell.className = 'day-cell' + (isOther ? ' other-month' : '') + (isToday(y, m, day) ? ' today' : '');

        const num = document.createElement('div');
        num.className = 'day-num';
        num.textContent = day;
        cell.appendChild(num);

        const dayTasks = getTasksForDay(y, m, day);
        const wrap = document.createElement('div');
        wrap.className = 'day-tasks';

        dayTasks.slice(0, 3).forEach(t => {
            const dot = document.createElement('div');
            dot.className = 'task-dot';
            dot.innerHTML = `<div class="task-dot-indicator" style="background:${t.Color}"></div>
                             <span class="task-dot-name">${t.Name}</span>`;
            dot.addEventListener('mouseenter', e => showTooltip(e, t));
            dot.addEventListener('mouseleave', hideTooltip);
            dot.addEventListener('click', () => openEdit(t));
            wrap.appendChild(dot);
        });

        if (dayTasks.length > 3) {
            const more = document.createElement('div');
            more.className = 'more-tasks';
            more.textContent = `+${dayTasks.length - 3} more`;
            wrap.appendChild(more);
        }

        cell.appendChild(wrap);
        return cell;
    }

    function renderGantt() {
        const total = daysInMonth(viewYear, viewMonth);
        const monthTasks = getTasksForMonth(viewYear, viewMonth);

        // Header
        const header = document.getElementById('ganttDaysHeader');
        header.innerHTML = '';
        for (let d = 1; d <= total; d++) {
            const div = document.createElement('div');
            div.className = 'gantt-day-header' + (isToday(viewYear, viewMonth, d) ? ' today-col' : '');
            div.textContent = d;
            header.appendChild(div);
        }

        // Body
        const body = document.getElementById('ganttBody');
        body.innerHTML = '';

        if (!monthTasks.length) {
            body.innerHTML = `<div class="empty-gantt">
                <div class="empty-gantt-text">No tasks this month</div>
                <div class="empty-gantt-sub">Click "Add Task" to get started</div>
            </div>`;
            return;
        }

        monthTasks.forEach(t => {
            const row = document.createElement('div');
            row.className = 'gantt-row';

            const info = document.createElement('div');
            info.className = 'gantt-task-info';
            info.innerHTML = `<div class="gantt-task-name">${t.Name}</div>
                              <div class="gantt-task-cat">${t.Category === 'others' ? (t.CustomCategory || 'Others') : t.Category}</div>`;
            row.appendChild(info);

            const timeline = document.createElement('div');
            timeline.className = 'gantt-timeline';

            // Grid lines
            const lines = document.createElement('div');
            lines.className = 'gantt-grid-lines';
            for (let d = 1; d <= total; d++) {
                const l = document.createElement('div');
                l.className = 'gantt-grid-line';
                lines.appendChild(l);
            }
            timeline.appendChild(lines);

            // Today line
            if (today.getFullYear() === viewYear && today.getMonth() === viewMonth) {
                const tl = document.createElement('div');
                tl.className = 'gantt-today-line';
                tl.style.left = ((today.getDate() - 0.5) / total * 100) + '%';
                timeline.appendChild(tl);
            }

            // Bar
            const ms = new Date(viewYear, viewMonth, 1);
            const me = new Date(viewYear, viewMonth, total);
            const ts = new Date(t.StartDate + 'T00:00:00');
            const te = new Date(t.DueDate + 'T00:00:00');
            const cs = ts < ms ? ms : ts;
            const ce = te > me ? me : te;
            const left = ((cs.getDate() - 1) / total * 100);
            const width = ((ce.getDate() - cs.getDate() + 1) / total * 100);

            const bar = document.createElement('div');
            bar.className = 'gantt-bar';
            bar.style.left = left + '%';
            bar.style.width = width + '%';
            bar.style.background = t.Color;
            bar.innerHTML = `<span class="gantt-bar-label">${t.Name}</span>`;
            bar.addEventListener('mouseenter', e => showTooltip(e, t));
            bar.addEventListener('mouseleave', hideTooltip);
            bar.addEventListener('click', () => openEdit(t));
            timeline.appendChild(bar);

            row.appendChild(timeline);
            body.appendChild(row);
        });
    }

    // ---- Tooltip ----
    const tooltip = document.getElementById('taskTooltip');

    const STATUS_LABELS = {
        pending: '⏳ Pending', in_progress: '🔄 In Progress',
        review: '🔍 Review', done: '✅ Done', cancelled: '❌ Cancelled'
    };

    function showTooltip(e, t) {
        const statusLabel = STATUS_LABELS[t.Status] || '⏳ Pending';
        tooltip.innerHTML = `
            <div class="tooltip-name">${t.Name}</div>
            <div class="tooltip-cat">${t.Category === 'others' ? (t.CustomCategory || 'Others') : t.Category}</div>
            <div class="tooltip-dates">📅 ${friendlyDate(t.StartDate)} → ${friendlyDate(t.DueDate)}</div>
            ${t.Notes ? `<div style="margin-top:6px;font-size:11px;color:#c4c4c4">${t.Notes}</div>` : ''}
            <span class="tooltip-priority">${t.Priority} Priority</span>
            <span class="tooltip-priority" style="margin-left:6px">${statusLabel}</span>`;
        tooltip.classList.add('visible');
        posTooltip(e);
    }

    function hideTooltip() { tooltip.classList.remove('visible'); }

    function posTooltip(e) {
        tooltip.style.left = Math.min(e.clientX + 14, window.innerWidth - 230) + 'px';
        tooltip.style.top = Math.min(e.clientY - 10, window.innerHeight - 170) + 'px';
    }

    document.addEventListener('mousemove', e => {
        if (tooltip.classList.contains('visible')) posTooltip(e);
    });

    // ---- Color swatches ----
    function buildSwatches(rowId, inputId, defaultColor) {
        const row = document.getElementById(rowId);
        if (!row) return;
        row.innerHTML = '';
        ROYGBIV.forEach(c => {
            const sw = document.createElement('div');
            sw.className = 'color-swatch' + (c.hex === defaultColor ? ' selected' : '');
            sw.dataset.color = c.hex;
            sw.style.background = c.hex;
            sw.title = c.name;
            sw.addEventListener('click', () => {
                row.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
                sw.classList.add('selected');
                document.getElementById(inputId).value = c.hex;
            });
            row.appendChild(sw);
        });
    }

    // ---- Category buttons ----
    function bindCatBtns(containerSelector, inputId, othersWrapId) {
        document.querySelectorAll(`${containerSelector} .cat-btn`).forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll(`${containerSelector} .cat-btn`).forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(inputId).value = btn.dataset.cat;
                document.getElementById(othersWrapId).style.display = btn.dataset.cat === 'others' ? 'block' : 'none';
            });
        });
    }

    function bindPriorityBtns(containerSelector, inputId) {
        document.querySelectorAll(`${containerSelector} .priority-btn`).forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll(`${containerSelector} .priority-btn`).forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(inputId).value = btn.dataset.priority;
            });
        });
    }

    // ---- Open Edit Modal ----
    function openEdit(t) {
        document.getElementById('editTaskId').value = t.Id;
        document.getElementById('editTaskName').value = t.Name;
        document.getElementById('editTaskStart').value = t.StartDate;
        document.getElementById('editTaskDue').value = t.DueDate;
        document.getElementById('editTaskNotes').value = t.Notes || '';
        // Auto-expand notes after populating
        (function () {
            var ta = document.getElementById('editTaskNotes');
            if (ta) { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; }
        })();
        document.getElementById('deleteTaskId').value = t.Id;
        document.getElementById('editCategoryInput').value = t.Category;
        document.getElementById('editPriorityInput').value = t.Priority;
        document.getElementById('editColorInput').value = t.Color;

        // Set recurrence dropdown
        var recEl = document.getElementById('calEditRecurrence');
        if (recEl) recEl.value = t.Recurrence || '';

        // Set status dropdown
        const statusEl = document.getElementById('editTaskStatus');
        if (statusEl) statusEl.value = t.Status || 'pending';

        // Set active cat btn
        document.querySelectorAll('#editCatOptions .cat-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.cat === t.Category);
        });
        document.getElementById('editOthersWrap').style.display = t.Category === 'others' ? 'block' : 'none';
        document.getElementById('editCustomCat').value = t.CustomCategory || '';

        // Set active priority btn
        document.querySelectorAll('#editPriorityOptions .priority-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.priority === t.Priority);
        });

        // Build swatches
        buildSwatches('editColorRow', 'editColorInput', t.Color);

        // ── Load existing dependency links for this task ──────────────────────
        depLoadExisting(t.Id);

        openModal('editTaskModal');
    }

    // ---- Init ----
    function init() {
        // Build add swatches
        buildSwatches('addColorRow', 'addColorInput', '#ef4444');
        buildSwatches('editColorRow', 'editColorInput', '#ef4444');

        // Bind category & priority for add modal
        bindCatBtns('#addTaskModal .category-options', 'addCategoryInput', 'addOthersWrap');
        bindPriorityBtns('#addTaskModal .priority-options', 'addPriorityInput');

        // Bind category & priority for edit modal
        bindCatBtns('#editCatOptions', 'editCategoryInput', 'editOthersWrap');
        bindPriorityBtns('#editPriorityOptions', 'editPriorityInput');

        // Nav buttons
        document.getElementById('prevMonth').addEventListener('click', () => {
            viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } render();
        });
        document.getElementById('nextMonth').addEventListener('click', () => {
            viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } render();
        });
        document.getElementById('btnToday').addEventListener('click', () => {
            viewYear = today.getFullYear(); viewMonth = today.getMonth();
            sessionStorage.removeItem('tm_cal_year');
            sessionStorage.removeItem('tm_cal_month');
            render();
        });
        document.getElementById('yearInput').addEventListener('change', function () {
            const y = parseInt(this.value);
            if (y >= 1900 && y <= 2100) { viewYear = y; render(); }
        });

        // Add task button
        document.getElementById('btnAddTask').addEventListener('click', () => {
            if (typeof window.depResetAdd === 'function') window.depResetAdd();
            openModal('addTaskModal');
        });

        // Gantt toggle
        document.getElementById('ganttToggle').addEventListener('change', function () {
            isGantt = this.checked;
            document.getElementById('calendarWrapper').classList.toggle('hidden', isGantt);
            document.getElementById('ganttWrapper').classList.toggle('active', isGantt);
            render();
        });

        render();
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', CalendarApp.init);
// ── Dependency UI ─────────────────────────────────────────────────────────────
// makeDepUI(selId, containerId, hiddenId) creates an independent dep instance
// for each modal (edit and add). Each instance has its own _selected list.
//
// ROOT-CAUSE NOTE: Oracle OCI8 returns all column values as PHP strings, so
// task_id arrives in JSON as a string ("42"), not a number (42). The JS .map()
// therefore gives t.Id a string value. We normalise every id to a Number via
// toNum() before any comparison so strict equality (===) always works.
(function () {
    'use strict';

    function toNum(v) { return parseInt(v, 10) || 0; }

    function escDep(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtDate(s) {
        if (!s) return '';
        const p = s.split('-');
        const months = ['Jan','Feb','Mar','Apr','May','Jun',
                        'Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(p[1],10)-1] + ' ' + parseInt(p[2],10);
    }

    // Return all serverTasks with Id coerced to Number
    function getTasks() {
        return (typeof serverTasks !== 'undefined' ? serverTasks : [])
            .map(t => ({ Id: toNum(t.Id), Name: t.Name, Status: t.Status,
                         Color: t.Color, DueDate: t.DueDate }));
    }

    // ── Factory: creates one dep UI instance ─────────────────────────────────
    function makeDepUI(selId, containerId, hiddenId) {
        let _selected  = [];
        let _editingId = null;

        function buildSelect() {
            const sel = document.getElementById(selId);
            if (!sel) return;
            const eligible = getTasks().filter(t =>
                t.Id !== _editingId &&
                !['done', 'cancelled'].includes((t.Status || '').toLowerCase()) &&
                !_selected.find(s => s.id === t.Id)
            );
            sel.innerHTML = '<option value="">— Pick a task —</option>';
            eligible.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.Id;
                opt.textContent = t.Name + (t.DueDate ? '  ·  ' + fmtDate(t.DueDate) : '');
                sel.appendChild(opt);
            });
        }

        function render() {
            const container = document.getElementById(containerId);
            const hidden    = document.getElementById(hiddenId);
            if (!container || !hidden) return;

            container.innerHTML = '';
            hidden.value = _selected.map(s => s.id).join(',');

            _selected.forEach(s => {
                const chip = document.createElement('div');
                chip.className = 'dep-chip';
                chip.innerHTML =
                    '<span class="dep-chip-dot" style="background:' + escDep(s.color) + '"></span>' +
                    '<span class="dep-chip-name">' + escDep(s.name) + '</span>' +
                    (s.dueDate ? '<span class="dep-chip-due">' + fmtDate(s.dueDate) + '</span>' : '') +
                    '<button type="button" class="dep-chip-remove" title="Remove">' +
                        '<i class="fa-solid fa-xmark"></i>' +
                    '</button>';
                chip.querySelector('.dep-chip-remove').addEventListener('click', () => {
                    _selected = _selected.filter(x => x.id !== s.id);
                    render();
                    buildSelect();
                });
                container.appendChild(chip);
            });

            buildSelect();
        }

        // Wire the <select> change event
        document.addEventListener('DOMContentLoaded', function () {
            const sel = document.getElementById(selId);
            if (!sel) return;
            sel.addEventListener('change', function () {
                const id = toNum(this.value);
                if (!id) return;
                const task = getTasks().find(t => t.Id === id);
                if (task && !_selected.find(s => s.id === id)) {
                    _selected.push({ id: task.Id, name: task.Name,
                                     color: task.Color || '#888', dueDate: task.DueDate || '' });
                    render();
                }
                this.value = '';
            });
        });

        return {
            loadExisting: function (taskId) {
                _editingId = toNum(taskId);
                _selected  = [];
                buildSelect();
                render();
                fetch('TM_PHP/TM_GetLinks.php?task_id=' + encodeURIComponent(taskId))
                    .then(r => r.json())
                    .then(data => {
                        if (!data.ok) return;
                        const tasks = getTasks();
                        _selected = (data.blockers || []).map(b => {
                            const numId = toNum(b.id);
                            const match = tasks.find(t => t.Id === numId) || {};
                            return { id: numId, name: b.name,
                                     color: match.Color || '#888', dueDate: match.DueDate || '' };
                        });
                        render();
                    })
                    .catch(() => {});
            },
            reset: function () {
                _editingId = null;
                _selected  = [];
                buildSelect();
                render();
            }
        };
    }

    // ── Instantiate for edit modal ────────────────────────────────────────────
    const editDep = makeDepUI('depSelect', 'depSelected', 'depBlockerIds');
    window.depLoadExisting = function (taskId) { editDep.loadExisting(taskId); };

    // ── Instantiate for add modal ─────────────────────────────────────────────
    const addDep = makeDepUI('addDepSelect', 'addDepSelected', 'addDepBlockerIds');
    window.depResetAdd = function () { addDep.reset(); };

})();
