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
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_orgs    PRIMARY KEY (org_id),
    CONSTRAINT uq_tm_orgname UNIQUE (org_name)
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
INSERT INTO TM_Organizations (org_name) VALUES ('Default Organization');
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

COMMIT;

-- VERIFY
SELECT 'TM_Organizations' AS tbl, COUNT(*) AS rows FROM TM_Organizations
UNION ALL
SELECT 'TM_Users (with org_id)', COUNT(*) FROM TM_Users WHERE org_id IS NOT NULL
UNION ALL
SELECT 'TM_Tasks (with org_id)', COUNT(*) FROM TM_Tasks WHERE org_id IS NOT NULL;
