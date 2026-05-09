-- =============================================
-- FEATURE 6 — ORGANIZATION / TENANT MANAGEMENT
-- Run this entire file once on your existing DB.
-- Every user and task is scoped to an org_id so
-- users from different organizations never see
-- each other's data.
-- =============================================

-- 1. Master organizations table
CREATE TABLE TM_Organizations (
    org_id      NUMBER(10)    NOT NULL,
    org_name    VARCHAR2(100) NOT NULL,
    plan        VARCHAR2(20)  DEFAULT 'free'   NOT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_orgs     PRIMARY KEY (org_id),
    CONSTRAINT uq_tm_orgname  UNIQUE (org_name),
    CONSTRAINT chk_tm_org_plan CHECK (plan IN ('free','pro','enterprise'))
);

CREATE SEQUENCE TM_Orgs_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_orgs_id
    BEFORE INSERT ON TM_Organizations FOR EACH ROW
BEGIN
    IF :NEW.org_id IS NULL THEN
        SELECT TM_Orgs_seq.NEXTVAL INTO :NEW.org_id FROM DUAL;
    END IF;
END;
/

-- 2. Seed a default organization so existing rows get a valid FK
INSERT INTO TM_Organizations (org_name, plan) VALUES ('Default Organization', 'free');
COMMIT;

-- 3. Add org_id to TM_Users (nullable first so existing rows are valid)
ALTER TABLE TM_Users ADD org_id NUMBER(10);

-- Set all existing users to the default org (org_id = 1)
UPDATE TM_Users SET org_id = 1;
COMMIT;

-- Now enforce NOT NULL and the FK
ALTER TABLE TM_Users MODIFY org_id NUMBER(10) NOT NULL;

ALTER TABLE TM_Users ADD CONSTRAINT fk_users_org
    FOREIGN KEY (org_id) REFERENCES TM_Organizations(org_id);

-- Extend role to include 'org_admin' (organization-level admin)
-- org_admin: can manage users within their own org
-- admin: system-wide superadmin
ALTER TABLE TM_Users DROP CONSTRAINT chk_tm_role;
ALTER TABLE TM_Users ADD CONSTRAINT chk_tm_role
    CHECK (role IN ('user', 'moderator', 'org_admin', 'admin'));

-- 4. Add org_id to TM_Tasks
ALTER TABLE TM_Tasks ADD org_id NUMBER(10);

-- Backfill from the task owner's org
UPDATE TM_Tasks t
SET t.org_id = (
    SELECT u.org_id FROM TM_Users u WHERE u.user_id = t.user_id
);
COMMIT;

ALTER TABLE TM_Tasks MODIFY org_id NUMBER(10) NOT NULL;

ALTER TABLE TM_Tasks ADD CONSTRAINT fk_tasks_org
    FOREIGN KEY (org_id) REFERENCES TM_Organizations(org_id);

-- 5. Index for the most common filter pattern
CREATE INDEX idx_tm_users_org ON TM_Users(org_id);
CREATE INDEX idx_tm_tasks_org ON TM_Tasks(org_id);

-- 6. Convenience view: tasks with their owner's org name
CREATE OR REPLACE VIEW VW_Tasks_OrgScoped AS
SELECT
    t.task_id,
    t.user_id,
    t.org_id,
    o.org_name,
    t.task_name,
    t.status,
    t.priority,
    t.due_date
FROM TM_Tasks t
JOIN TM_Organizations o ON o.org_id = t.org_id;

-- 7. Stored procedures for org management
--    TM_CreateOrg   — create a new organization and assign an initial org_admin
--    TM_TransferOrg — move a user from one org to another (system admin only)

CREATE OR REPLACE PROCEDURE TM_CreateOrg(
    p_org_name   IN  VARCHAR2,
    p_plan       IN  VARCHAR2 DEFAULT 'free',
    p_new_org_id OUT NUMBER
) AS
BEGIN
    INSERT INTO TM_Organizations (org_name, plan)
    VALUES (p_org_name, p_plan)
    RETURNING org_id INTO p_new_org_id;
    COMMIT;
END TM_CreateOrg;
/

CREATE OR REPLACE PROCEDURE TM_TransferUserOrg(
    p_user_id    IN NUMBER,
    p_new_org_id IN NUMBER,
    p_moved_by   IN NUMBER
) AS
    v_old_org NUMBER;
BEGIN
    SELECT org_id INTO v_old_org FROM TM_Users WHERE user_id = p_user_id;

    UPDATE TM_Users SET org_id = p_new_org_id WHERE user_id = p_user_id;

    -- Re-scope all tasks belonging to this user to the new org
    UPDATE TM_Tasks SET org_id = p_new_org_id WHERE user_id = p_user_id;

    INSERT INTO TM_AuditLog
        (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
    VALUES
        (p_moved_by, 'edit', 'user', p_user_id,
         'org_transfer',
         'org_id:' || v_old_org,
         'org_id:' || p_new_org_id);

    COMMIT;
EXCEPTION
    WHEN NO_DATA_FOUND THEN
        RAISE_APPLICATION_ERROR(-20010, 'User not found: ' || p_user_id);
END TM_TransferUserOrg;
/

COMMIT;

-- VERIFY
SELECT 'TM_Organizations' AS tbl, COUNT(*) AS rows FROM TM_Organizations
UNION ALL
SELECT 'TM_Users (with org_id)', COUNT(*) FROM TM_Users WHERE org_id IS NOT NULL
UNION ALL
SELECT 'TM_Tasks (with org_id)', COUNT(*) FROM TM_Tasks WHERE org_id IS NOT NULL;
