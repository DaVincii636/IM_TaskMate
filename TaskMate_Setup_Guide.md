# TaskMate — Setup & Run Guide
### How to get the system running from a fresh repository clone

---

## WHAT YOU NEED TO INSTALL FIRST

Before touching the project, make sure these are installed on your machine:

| Software | Purpose | Where to get it |
|---|---|---|
| XAMPP | Runs Apache (web server) and PHP | https://www.apachefriends.org |
| Oracle Database XE | The database | https://www.oracle.com/database/technologies/xe-downloads.html |
| Oracle Instant Client | Lets PHP talk to Oracle | https://www.oracle.com/database/technologies/instant-client/downloads.html |
| Git | To clone the repository | https://git-scm.com |

---

## STEP 1 — CLONE THE REPOSITORY

Open a terminal (Command Prompt, PowerShell, or Git Bash) and run:

```
git clone <your-repository-url-here>
```

This creates a folder called `IM_TaskMate-main`. Move or copy that entire folder into XAMPP's web root:

```
C:\xampp\htdocs\TaskMate
```

After this step, the folder structure should look like:

```
C:\xampp\htdocs\TaskMate\
    TM_Calendar.php
    TM_Dashboard.php
    TM_PHP\
    TM_CSS\
    TM_Database\
    TM_JS\
    ... (and all other files)
```

---

## STEP 2 — ENABLE THE OCI8 EXTENSION IN PHP

XAMPP's PHP does not connect to Oracle by default. You need to enable it manually.

1. Open XAMPP Control Panel
2. Next to Apache, click **Config → php.ini**
3. Press **Ctrl+F** and search for: `extension=oci8`
4. If the line has a semicolon at the start, remove it so it reads exactly:
   ```
   extension=oci8
   ```
5. Save the file

> If `extension=oci8` does not exist in your php.ini at all, add this line in the **extensions section**:
> ```
> extension=oci8_19
> ```
> (The exact name depends on your PHP and Instant Client version. Check your `C:\xampp\php\ext\` folder for a file named `php_oci8*.dll` and use that name without `php_` and `.dll`.)

---

## STEP 3 — ADD ORACLE INSTANT CLIENT TO YOUR SYSTEM PATH

Oracle Instant Client is a set of DLL files PHP needs to communicate with Oracle.

1. Download and extract Instant Client to a folder, for example:
   ```
   C:\oracle\instantclient_21_x
   ```
2. Add that folder to your **System PATH**:
   - Press **Win + S** → search "Environment Variables" → click "Edit the system environment variables"
   - Click **Environment Variables**
   - Under **System variables**, find `Path` → click **Edit**
   - Click **New** and paste the path to your Instant Client folder (e.g. `C:\oracle\instantclient_21_x`)
   - Click OK on all dialogs
3. **Restart your computer** (or at minimum restart XAMPP) for the PATH change to take effect

---

## STEP 4 — CREATE THE DATABASE TABLES IN ORACLE

1. Open **Oracle SQL Developer** (or any Oracle SQL client you use)
2. Connect to your Oracle XE database using your credentials
3. Open the file:
   ```
   C:\xampp\htdocs\TaskMate\TM_Database\TM_DatabaseSetup.sql
   ```
4. Run the entire script. It will create all five tables:
   - TM_Users
   - TM_Tasks
   - TM_Notifications
   - TM_AuditLog
   - TM_TaskLinks
5. It will also create all sequences, triggers, and indexes automatically

To verify the tables were created, run:
```sql
SELECT table_name FROM user_tables WHERE table_name LIKE 'TM_%' ORDER BY table_name;
```
You should see all five table names returned.

---

## STEP 5 — UPDATE THE DATABASE CREDENTIALS IN TM_DB.php

`TM_DB.php` is already in the repository under `TM_PHP/`. You just need to update one line to match your own Oracle credentials.

1. Open:
   ```
   C:\xampp\htdocs\TaskMate\TM_PHP\TM_DB.php
   ```
2. Find this line near the top:
   ```php
   $conn = oci_connect('SYSTEM', '0r4cl3', 'localhost/XE');
   ```
3. Replace the three values to match your Oracle setup:
   - **Argument 1** — your Oracle username (usually `SYSTEM`)
   - **Argument 2** — your Oracle password (whatever you set when you installed Oracle XE)
   - **Argument 3** — your connection string (usually `localhost/XE` for Oracle XE)

   For example, if your password is `mypassword`:
   ```php
   $conn = oci_connect('SYSTEM', 'mypassword', 'localhost/XE');
   ```
4. Save the file

---

## STEP 6 — START APACHE IN XAMPP

1. Open XAMPP Control Panel
2. Click **Start** next to **Apache**
3. The status should turn green — Apache is now running
4. You do NOT need to start MySQL — TaskMate uses Oracle, not MySQL

---

## STEP 7 — OPEN THE SYSTEM IN YOUR BROWSER

Navigate to:
```
http://localhost/TaskMate/TM_Landing.php
```

You should see the TaskMate landing page.

---

## STEP 8 — CREATE YOUR FIRST ACCOUNT

1. Click **Get Started** or go to:
   ```
   http://localhost/TaskMate/TM_Register.php
   ```
2. Fill in your first name, last name, email, phone, and password
3. Click Register — you will be redirected to the login page
4. Log in with the email and password you just registered

---

## STEP 9 — CREATE AN ADMIN ACCOUNT (OPTIONAL BUT RECOMMENDED FOR TESTING)

The registration page creates regular user accounts only. To test admin features (Admin Panel, user management), you need to manually set a user's role in the database.

After registering, run this in Oracle SQL Developer — replace the email with the one you registered:

```sql
UPDATE TM_Users SET role = 'admin' WHERE email = 'your@email.com';
COMMIT;
```

Log out and log back in. The **Admin Panel** link will now appear in the navbar.

---

## COMMON PROBLEMS & FIXES

**"The PHP OCI8 extension is not enabled"**
→ You missed Step 2. Enable `extension=oci8` in php.ini and restart Apache.

**"Database connection failed"**
→ Your credentials in TM_DB.php are wrong, or Oracle XE is not running. Open Oracle services in Windows and make sure OracleServiceXE is started.

**Blank white page with no error**
→ PHP has a fatal error it's not displaying. Open `C:\xampp\php\php.ini`, find `display_errors`, set it to `On`, and restart Apache. Reload the page to see the actual error.

**Page loads but redirects to login immediately**
→ Your session expired or you are not logged in. Go to `TM_Login.php` and log in first.

**Tables already exist error when running the SQL script**
→ Run `DROP TABLE TM_TaskLinks CASCADE CONSTRAINTS;` and repeat for each TM_ table in reverse order (TaskLinks → AuditLog → Notifications → Tasks → Users), then re-run the setup script from scratch.

---

## QUICK TEST CHECKLIST

After setup, go through these to confirm everything works:

- [ ] Landing page loads at `http://localhost/TaskMate/TM_Landing.php`
- [ ] Registration creates a new account
- [ ] Login redirects to the Dashboard with stat cards
- [ ] Creating a task shows it on the Calendar
- [ ] Editing a task saves changes correctly
- [ ] The Tasks page shows All / Missing / Done tabs with counts
- [ ] The notification bell appears in the navbar (badge shows after a task is overdue)
- [ ] The Activity Feed shows your create/edit events
- [ ] The Analytics page loads charts and stats
- [ ] Admin Panel is accessible after setting role = 'admin' in the DB
- [ ] JSON API returns data at: `http://localhost/TaskMate/TM_PHP/TM_TaskActions.php?action=list&format=json` (must be logged in first)
