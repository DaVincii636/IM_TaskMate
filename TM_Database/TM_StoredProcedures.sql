-- =============================================
-- TM_StoredProcedures.sql  (Run 6th)
-- Core PL/SQL procedures for task operations.
-- Depends on: TM_DatabaseSetup.sql
-- =============================================

-- ── TM_CreateTask ─────────────────────────────
CREATE OR REPLACE PROCEDURE TM_CreateTask (
    p_user_id         IN  TM_Tasks.user_id%TYPE,
    p_task_name       IN  TM_Tasks.task_name%TYPE,
    p_start_date      IN  VARCHAR2,
    p_due_date        IN  VARCHAR2,
    p_category        IN  TM_Tasks.category%TYPE,
    p_custom_category IN  TM_Tasks.custom_category%TYPE,
    p_priority        IN  TM_Tasks.priority%TYPE,
    p_color           IN  TM_Tasks.color%TYPE,
    p_notes           IN  TM_Tasks.notes%TYPE,
    p_recurrence      IN  TM_Tasks.recurrence%TYPE,
    p_new_task_id     OUT TM_Tasks.task_id%TYPE,
    p_org_id          IN  TM_Tasks.org_id%TYPE DEFAULT 1,
    p_is_org_task     IN  TM_Tasks.is_org_task%TYPE DEFAULT 0
) AS
    v_audit_new VARCHAR2(500);
BEGIN
    INSERT INTO TM_Tasks (
        user_id, org_id, task_name, start_date, due_date,
        category, custom_category, priority, color, notes, recurrence,
        is_org_task
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
        NULLIF(p_recurrence, ''),
        p_is_org_task
    );

    SELECT TM_Tasks_seq.CURRVAL INTO p_new_task_id FROM DUAL;

    v_audit_new := SUBSTR(
        'cat:' || p_category || ', pri:' || p_priority || ', due:' || p_due_date,
        1, 500
    );

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

-- ── TM_UpdateTaskStatus ───────────────────────
CREATE OR REPLACE PROCEDURE TM_UpdateTaskStatus (
    p_task_id    IN TM_Tasks.task_id%TYPE,
    p_user_id    IN TM_Tasks.user_id%TYPE,
    p_new_status IN TM_Tasks.status%TYPE
) AS
    v_old_status TM_Tasks.status%TYPE;
    v_task_name  TM_Tasks.task_name%TYPE;
BEGIN
    SELECT status, task_name
      INTO v_old_status, v_task_name
      FROM TM_Tasks
     WHERE task_id = p_task_id
       AND user_id = p_user_id
       FOR UPDATE;

    UPDATE TM_Tasks
       SET status = p_new_status
     WHERE task_id = p_task_id
       AND user_id = p_user_id;

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

-- ── TM_WriteAuditLog ──────────────────────────
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
EXCEPTION
    WHEN OTHERS THEN NULL;
END TM_WriteAuditLog;
/

-- ── USER STATUS PROCEDURES ────────────────────
CREATE OR REPLACE PROCEDURE sp_approve_user (
    p_user_id  IN NUMBER,
    p_admin_id IN NUMBER
) AS
    v_name VARCHAR2(200);
BEGIN
    SELECT first_name || ' ' || last_name
    INTO   v_name
    FROM   TM_Users
    WHERE  user_id = p_user_id AND status = 'pending';

    UPDATE TM_Users SET status = 'active' WHERE user_id = p_user_id;

    INSERT INTO TM_AuditLog (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
    VALUES (p_admin_id, 'edit', 'user', p_user_id, v_name, 'status:pending', 'status:active');

    COMMIT;
END;
/

CREATE OR REPLACE PROCEDURE sp_suspend_user (
    p_user_id  IN NUMBER,
    p_admin_id IN NUMBER
) AS
    v_name   VARCHAR2(200);
    v_status VARCHAR2(20);
BEGIN
    SELECT first_name || ' ' || last_name, status
    INTO   v_name, v_status
    FROM   TM_Users
    WHERE  user_id = p_user_id;

    UPDATE TM_Users SET status = 'suspended' WHERE user_id = p_user_id;

    INSERT INTO TM_AuditLog (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
    VALUES (p_admin_id, 'edit', 'user', p_user_id, v_name, 'status:' || v_status, 'status:suspended');

    COMMIT;
END;
/

COMMIT;
