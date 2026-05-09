-- =============================================
-- TM_StoredProcedures.sql
-- PL/SQL Stored Procedures for TaskMate core operations.
--
-- Feature 9 — IM101 Week 12 (PL/SQL Procedures):
--   Benefits realised here:
--   1. Performance  — compiled once, reused from Oracle shared SQL cache
--   2. Security     — PHP never touches TM_Tasks/TM_AuditLog directly;
--                     only EXECUTE privilege on procedures is needed
--   3. Integrity    — INSERT + audit happen atomically in one call
--   4. Maintenance  — change logic once in Oracle, not across PHP files
--   5. Reuse        — any future module (cron, admin panel) calls the
--                     same procedure instead of reimplementing SQL
--
-- Run this file ONCE in Oracle SQL Developer AFTER TM_DatabaseSetup.sql.
-- Re-running is safe: CREATE OR REPLACE overwrites existing bodies.
-- =============================================


-- ─────────────────────────────────────────────
-- PROCEDURE: TM_CreateTask
-- Inserts one task row and writes a 'create' audit entry.
-- OUT p_new_task_id returns the generated task_id.
-- ─────────────────────────────────────────────
CREATE OR REPLACE PROCEDURE TM_CreateTask (
    p_user_id         IN  TM_Tasks.user_id%TYPE,
    p_task_name       IN  TM_Tasks.task_name%TYPE,
    p_start_date      IN  VARCHAR2,   -- 'YYYY-MM-DD'
    p_due_date        IN  VARCHAR2,   -- 'YYYY-MM-DD'
    p_category        IN  TM_Tasks.category%TYPE,
    p_custom_category IN  TM_Tasks.custom_category%TYPE,
    p_priority        IN  TM_Tasks.priority%TYPE,
    p_color           IN  TM_Tasks.color%TYPE,
    p_notes           IN  TM_Tasks.notes%TYPE,
    p_recurrence      IN  TM_Tasks.recurrence%TYPE,
    p_new_task_id     OUT TM_Tasks.task_id%TYPE,
    -- Feature 6: org_id required so every task is tenant-scoped from creation
    p_org_id          IN  TM_Tasks.org_id%TYPE DEFAULT 1
) AS
    v_audit_new VARCHAR2(500);
BEGIN
    -- Insert the task; trigger trg_tm_tasks_id populates task_id via sequence
    INSERT INTO TM_Tasks (
        user_id, org_id, task_name, start_date, due_date,
        category, custom_category, priority, color, notes, recurrence
    ) VALUES (
        p_user_id,
        p_org_id,
        p_task_name,
        TO_DATE(p_start_date, 'YYYY-MM-DD'),
        TO_DATE(p_due_date,   'YYYY-MM-DD'),
        p_category,
        p_custom_category,
        p_priority,
        p_color,
        p_notes,
        NULLIF(p_recurrence, '')
    );

    -- Retrieve the auto-generated task_id
    SELECT TM_Tasks_seq.CURRVAL INTO p_new_task_id FROM DUAL;

    -- Build audit summary
    v_audit_new := SUBSTR(
        'cat:' || p_category || ', pri:' || p_priority || ', due:' || p_due_date,
        1, 500
    );

    -- Write audit log atomically with the insert
    INSERT INTO TM_AuditLog (
        user_id, action, entity_type, entity_id,
        entity_name, old_value, new_value
    ) VALUES (
        p_user_id, 'create', 'task', p_new_task_id,
        SUBSTR(p_task_name, 1, 255), '', v_audit_new
    );

    COMMIT;
EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        RAISE;
END TM_CreateTask;
/


-- ─────────────────────────────────────────────
-- PROCEDURE: TM_UpdateTaskStatus
-- Updates a task's status and writes a 'status_change' audit entry.
-- Enforces ownership: raises ORA-20001 if task not found for user.
-- ─────────────────────────────────────────────
CREATE OR REPLACE PROCEDURE TM_UpdateTaskStatus (
    p_task_id    IN TM_Tasks.task_id%TYPE,
    p_user_id    IN TM_Tasks.user_id%TYPE,
    p_new_status IN TM_Tasks.status%TYPE
) AS
    v_old_status TM_Tasks.status%TYPE;
    v_task_name  TM_Tasks.task_name%TYPE;
BEGIN
    -- Fetch current state; lock the row for update
    SELECT status, task_name
      INTO v_old_status, v_task_name
      FROM TM_Tasks
     WHERE task_id = p_task_id
       AND user_id = p_user_id
       FOR UPDATE;

    -- Update status
    UPDATE TM_Tasks
       SET status = p_new_status
     WHERE task_id = p_task_id
       AND user_id = p_user_id;

    -- Write audit entry
    INSERT INTO TM_AuditLog (
        user_id, action, entity_type, entity_id,
        entity_name, old_value, new_value
    ) VALUES (
        p_user_id, 'status_change', 'task', p_task_id,
        SUBSTR(v_task_name, 1, 255),
        SUBSTR('status:' || v_old_status, 1, 500),
        SUBSTR('status:' || p_new_status, 1, 500)
    );

    COMMIT;
EXCEPTION
    WHEN NO_DATA_FOUND THEN
        RAISE_APPLICATION_ERROR(-20001, 'Task not found or access denied.');
    WHEN OTHERS THEN
        ROLLBACK;
        RAISE;
END TM_UpdateTaskStatus;
/


-- ─────────────────────────────────────────────
-- PROCEDURE: TM_WriteAuditLog
-- Standalone wrapper used by PHP for any other audit entries
-- (edit, delete) that don't fit the specialised procedures above.
-- Mirrors IM101 Week 13: converting the tm_audit() PHP helper into
-- a named Oracle stored procedure for better security and reuse.
-- ─────────────────────────────────────────────
CREATE OR REPLACE PROCEDURE TM_WriteAuditLog (
    p_user_id     IN TM_AuditLog.user_id%TYPE,
    p_action      IN TM_AuditLog.action%TYPE,
    p_entity_type IN TM_AuditLog.entity_type%TYPE,
    p_entity_id   IN TM_AuditLog.entity_id%TYPE,
    p_entity_name IN TM_AuditLog.entity_name%TYPE,
    p_old_value   IN TM_AuditLog.old_value%TYPE  DEFAULT '',
    p_new_value   IN TM_AuditLog.new_value%TYPE  DEFAULT ''
) AS
BEGIN
    INSERT INTO TM_AuditLog (
        user_id, action, entity_type, entity_id,
        entity_name, old_value, new_value
    ) VALUES (
        p_user_id,
        p_action,
        p_entity_type,
        p_entity_id,
        SUBSTR(p_entity_name, 1, 255),
        SUBSTR(p_old_value,   1, 500),
        SUBSTR(p_new_value,   1, 500)
    );
    -- No COMMIT here: caller may be inside a larger transaction
EXCEPTION
    WHEN OTHERS THEN NULL;  -- audit must never block the real action
END TM_WriteAuditLog;
/


-- ─────────────────────────────────────────────
-- GRANT execute to the PHP connection user.
-- Replace SYSTEM with the schema/user your PHP connects as
-- if it differs (e.g. TASKMATE_APP).
-- ─────────────────────────────────────────────
-- GRANT EXECUTE ON TM_CreateTask       TO SYSTEM;
-- GRANT EXECUTE ON TM_UpdateTaskStatus TO SYSTEM;
-- GRANT EXECUTE ON TM_WriteAuditLog    TO SYSTEM;

-- Verify procedures were created
SELECT object_name, object_type, status
  FROM user_objects
 WHERE object_type = 'PROCEDURE'
   AND object_name IN ('TM_CreateTask','TM_UpdateTaskStatus','TM_WriteAuditLog')
 ORDER BY object_name;
