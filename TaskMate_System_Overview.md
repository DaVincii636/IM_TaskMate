# TaskMate — System Overview
### Full Feature List & Database Reference
---

## WHAT IS TASKMATE?

TaskMate is a web-based task management system built with PHP and an Oracle database. It allows users to create, organize, and track personal tasks through a calendar and list interface, with real-time notifications, activity tracking, analytics, and a REST-style JSON API. It supports multiple user roles and includes a full admin panel for user management.

---

## PAGES & WHAT EACH ONE DOES

| Page | File | Who Can Access |
|---|---|---|
| Landing / Welcome | TM_Landing.php | Public |
| Login | TM_Login.php | Public |
| Register | TM_Register.php | Public |
| Dashboard | TM_Dashboard.php | All logged-in users |
| Calendar | TM_Calendar.php | All logged-in users |
| Tasks (All / Missing / Done) | TM_Tasks.php | All logged-in users |
| Activity Feed | TM_Activity.php | All logged-in users |
| Analytics | TM_Analytics.php | All logged-in users |
| Admin Panel (User List) | TM_UserList.php | Admin & Moderator only |

---

## SYSTEM FEATURES

### 1. User Authentication
- Register with first name, last name, email, phone, and password
- Passwords are hashed using PHP's bcrypt (PASSWORD_BCRYPT) — never stored in plain text
- Login creates a server-side session; logout destroys it
- Session guards on every page — unauthenticated users are redirected to login automatically

### 2. Role-Based Access Control
- Three roles: **user**, **moderator**, **admin**
- Enforced server-side on every protected route via `tm_require_role()`
- **User** — can manage their own tasks only
- **Moderator** — can view the Admin Panel and user list, but cannot make changes
- **Admin** — full access: can add, edit, and delete users; can change user roles
- Admin Panel link only appears in the navbar for admin and moderator accounts

### 3. Dashboard
- Displays four live stat cards: Total Tasks, Pending, Done, Overdue
- Shows an "Upcoming Tasks" table — tasks due within the next 7 days
- Quick-action buttons linking directly to All, Missing, and Done views

### 4. Task Management (Full CRUD)
- **Create** a task with: name, start date, due date, category, custom category, priority (low / mid / high), color tag, and notes
- **Edit** any field on an existing task including status
- **Delete** a task (also removes all linked notifications, dependencies, and audit entries via cascade)
- **Quick-Done** button — marks a task done in one click from the task list

### 5. Task Status Workflow
- Five statuses: **pending → in_progress → review → done → cancelled**
- Status is selectable from the edit modal
- Tasks with blockers (dependencies) cannot be marked done until the blocking task is finished — the system enforces this and shows an error message

### 6. Calendar View
- Monthly calendar displaying all tasks on their due dates
- Color-coded by the task's chosen color
- Click any task to open its full edit modal
- Add new tasks directly from the calendar

### 7. Tasks Page — Three Filtered Views
- **All** — every task belonging to the logged-in user, with count badge
- **Missing** — tasks where due date has passed and status is not done or cancelled (overdue)
- **Done** — tasks with status = done
- Server-side search and filter bar on all three views: filter by name (keyword), category, priority, and date range
- URL parameters are used for filters, making results bookmarkable and shareable

### 8. Notification System
- Automatic notifications generated on every page load via the built-in cron
- Three notification types:
  - **Overdue** — task's due date has passed
  - **Due Today** — task is due today
  - **Due Soon** — task is due within 3 days
- Bell icon in the navbar shows unread count badge (up to 99+)
- Dropdown lists the 15 most recent notifications with type icon and timestamp
- Mark individual notifications as read by clicking them
- "Mark all as read" button in the dropdown header
- Duplicate prevention — the system will not create a second notification of the same type for the same task on the same day

### 9. Activity Feed
- Chronological log of everything the logged-in user has done: task creates, edits, deletes, and status changes
- Quick-stats strip at the top: total counts of Created, Edited, Status Changed, and Deleted events
- Filter by action type (Created / Edited / Status Changed / Deleted) and entity type (Tasks / Users)
- Relative timestamps ("2h ago", "3d ago") alongside exact date and time
- Paginated at 25 events per page with numbered page navigation

### 10. Analytics Page
- **Completion Rate by Week** — bar/line chart of tasks completed vs. tasks due for each of the last 8 weeks
- **Most-Missed Deadlines by Category** — which categories have the most overdue tasks
- **Average Completion Time** — average number of days between a task's start date and when it was marked done
- **Current Streak** — consecutive days on which at least one task was completed

### 11. Task Dependencies
- Tasks can be linked with a "blocks" relationship — Task A blocks Task B
- Set from the Dependencies section inside the task edit modal
- System enforces the dependency: if Task B has a blocker that is not yet done or cancelled, the Quick-Done action is rejected with a clear error message
- Blockers are shown as a count indicator on task cards

### 12. Admin Panel
- Searchable, filterable list of all users in the system
- Admins can: add new users, edit user details and roles, delete users
- Moderators can view the list but all edit/delete controls are hidden from them
- Activity tab showing recent system-wide audit events (admin view)

### 13. JSON API Layer
- All action handlers support JSON response mode
- Activate by adding `?format=json` to any request URL, or by sending the `Accept: application/json` HTTP header
- **Task endpoints** (TM_PHP/TM_TaskActions.php):
  - `GET ?action=list&format=json` — returns the user's full task list as a JSON array
  - `POST action=add&format=json` — creates a task, returns the new task_id
  - `POST action=edit&format=json` — updates a task, returns updated status
  - `POST action=delete&format=json` — deletes a task, confirms deletion
  - `POST action=quick_done&format=json` — marks task done, returns new status
- **User endpoints** (TM_PHP/TM_UserActions.php, admin/moderator only):
  - `GET ?action=list&format=json` — returns full user list
  - `POST action=add/edit/delete&format=json` — user management actions
- All responses follow the format: `{"ok": true, "data": ...}` on success or `{"ok": false, "error": "..."}` on failure
- Enables integration with mobile apps, browser extensions, and automation scripts

### 14. Audit Logging
- Every create, edit, delete, and status change is recorded automatically
- Stores: who did it, what action, which entity (task or user), the entity name, old value, and new value
- Powers both the Activity Feed (user-facing) and the Admin Panel activity tab
- Errors in logging are silently swallowed so a logging failure never blocks the actual action

---

## DATABASE TABLES

### TM_Users
Stores every registered account.

| Column | Type | Description |
|---|---|---|
| user_id | NUMBER(10) | Primary key, auto-incremented via sequence |
| username | VARCHAR2(50) | Unique username, auto-generated from email on registration |
| email | VARCHAR2(100) | Unique email address used for login |
| password_hash | VARCHAR2(255) | bcrypt hash — plain text password is never stored |
| first_name | VARCHAR2(100) | User's first name |
| last_name | VARCHAR2(100) | User's last name |
| phone | VARCHAR2(20) | Contact phone number |
| role | VARCHAR2(20) | One of: user, moderator, admin (default: user) |
| created_at | TIMESTAMP | When the account was created |
| updated_at | TIMESTAMP | When the account was last updated |

**Constraints:** Primary key on user_id. Unique on username and email. Check constraint limits role to the three allowed values.

---

### TM_Tasks
Stores every task created by any user.

| Column | Type | Description |
|---|---|---|
| task_id | NUMBER(10) | Primary key, auto-incremented via sequence |
| user_id | NUMBER(10) | Foreign key → TM_Users. Deletes cascade |
| task_name | VARCHAR2(255) | The task title/name |
| start_date | DATE | When work on the task begins |
| due_date | DATE | When the task must be completed |
| category | VARCHAR2(50) | Preset category (errands, school, work, etc.) |
| custom_category | VARCHAR2(100) | User-defined category when "others" is selected |
| priority | VARCHAR2(20) | low, mid, or high (default: mid) |
| color | VARCHAR2(20) | Hex color code for visual tagging (e.g. #ef4444) |
| notes | CLOB | Long-form notes, stored as a Character Large Object |
| status | VARCHAR2(20) | pending, in_progress, review, done, or cancelled (default: pending) |
| created_at | TIMESTAMP | When the task was created |

**Constraints:** Primary key on task_id. Foreign key on user_id with ON DELETE CASCADE (deleting a user removes all their tasks). Indexed on user_id and due_date for query performance.

---

### TM_Notifications
Stores in-app deadline alerts for each user.

| Column | Type | Description |
|---|---|---|
| notif_id | NUMBER(10) | Primary key, auto-incremented via sequence |
| user_id | NUMBER(10) | Foreign key → TM_Users. Deletes cascade |
| task_id | NUMBER(10) | Foreign key → TM_Tasks. Set to NULL if task is deleted |
| type | VARCHAR2(20) | overdue, due_today, or due_soon |
| message | VARCHAR2(500) | Human-readable notification text |
| is_read | NUMBER(1) | 0 = unread, 1 = read (default: 0) |
| created_at | TIMESTAMP | When the notification was generated |

**Constraints:** Primary key on notif_id. Foreign key on user_id (cascade delete). Foreign key on task_id (set null on delete — the notification is kept even if the task is removed). Indexed on (user_id, is_read) for the bell dropdown query.

---

### TM_AuditLog
Records every significant action taken in the system.

| Column | Type | Description |
|---|---|---|
| log_id | NUMBER(10) | Primary key, auto-incremented via sequence |
| user_id | NUMBER(10) | Foreign key → TM_Users. Deletes cascade |
| action | VARCHAR2(20) | One of: create, edit, delete, status_change |
| entity_type | VARCHAR2(20) | What was acted on: task or user |
| entity_id | NUMBER(10) | The ID of the task or user that was changed |
| entity_name | VARCHAR2(255) | Name of the entity at the time of the action |
| old_value | VARCHAR2(500) | The value before the change (e.g. "status:pending, pri:mid") |
| new_value | VARCHAR2(500) | The value after the change |
| created_at | TIMESTAMP | Exact timestamp of the action |

**Constraints:** Primary key on log_id. Foreign key on user_id with ON DELETE CASCADE. Check constraints limit action and entity_type to their allowed values. Indexed on user_id, created_at (descending), and (entity_type, entity_id).

---

### TM_TaskLinks
Stores dependency relationships between tasks.

| Column | Type | Description |
|---|---|---|
| link_id | NUMBER(10) | Primary key, auto-incremented via sequence |
| task_id | NUMBER(10) | The task that is being blocked |
| depends_on_id | NUMBER(10) | The task that must be completed first |
| link_type | VARCHAR2(20) | blocks (enforced) or relates_to (informational only) |
| created_at | TIMESTAMP | When the link was created |

**Constraints:** Primary key on link_id. Foreign keys on both task_id and depends_on_id with ON DELETE CASCADE — removing either task automatically removes the link. Unique constraint on the (task_id, depends_on_id) pair so the same link cannot be created twice. Check constraint limits link_type to blocks or relates_to. Indexed on both task_id and depends_on_id.

---

## SUPPORTING PHP FILES (BACKEND)

| File | Purpose |
|---|---|
| TM_PHP/TM_DB.php | Oracle database connection and query helpers (tm_exec, tm_fetch_all, tm_fetch_one, tm_scalar) |
| TM_PHP/TM_Session.php | Session management, login guards, role checks, flash messages |
| TM_PHP/TM_TaskActions.php | Handles all task create/edit/delete/done actions + JSON API endpoints |
| TM_PHP/TM_UserActions.php | Handles all user add/edit/delete actions + JSON API endpoints |
| TM_PHP/TM_NotifActions.php | Handles mark-as-read and mark-all-read notification actions |
| TM_PHP/TM_NotifCron.php | Generates overdue / due-today / due-soon notifications for all active tasks |
| TM_PHP/TM_NavNotif.php | Renders the notification bell HTML partial included in every navbar |
| TM_PHP/TM_LinkActions.php | Handles saving and updating task dependency links |
| TM_PHP/TM_GetLinks.php | Returns the current dependency links for a task (used by the edit modal) |
| TM_PHP/TM_AuthActions.php | Handles login, registration, and logout |
| TM_PHP/TM_TaskModal.php | Renders the shared add/edit task modal HTML partial |

---

## TECHNOLOGY STACK

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, JavaScript (vanilla) |
| Backend | PHP 8 |
| Database | Oracle Database XE (via OCI8 PHP extension) |
| Icons | Font Awesome 6 |
| Typography | Poppins (Google Fonts, self-hosted) |
| Server | Apache via XAMPP |
