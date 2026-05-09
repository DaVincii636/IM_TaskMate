-- =============================================
-- FEATURE 7 — SELF-SERVICE REGISTRATION WITH ADMIN APPROVAL
-- Run after TM_Feature6_Organizations.sql (org_id must already exist on TM_Users).
-- Adds a `status` column to TM_Users:
--   'pending'   → registered but not yet approved
--   'active'    → approved, can log in
--   'suspended' → blocked by admin
-- =============================================

-- 1. Add status column (nullable first for existing rows)
ALTER TABLE TM_Users ADD status VARCHAR2(20) DEFAULT 'active';

-- All existing users are already active
UPDATE TM_Users SET status = 'active' WHERE status IS NULL;
COMMIT;

ALTER TABLE TM_Users ADD CONSTRAINT chk_tm_user_status
    CHECK (status IN ('pending', 'active', 'suspended'));

-- 2. Index for the admin approval queue query
CREATE INDEX idx_tm_users_status ON TM_Users(status);

-- 3. Stored procedure: approve a pending user
CREATE OR REPLACE PROCEDURE sp_approve_user (
    p_user_id   IN NUMBER,
    p_admin_id  IN NUMBER
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

-- 4. Stored procedure: reject / suspend a user
CREATE OR REPLACE PROCEDURE sp_suspend_user (
    p_user_id   IN NUMBER,
    p_admin_id  IN NUMBER
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

-- VERIFY
SELECT status, COUNT(*) AS cnt FROM TM_Users GROUP BY status;
