-- =============================================
-- TM_Department_Upgrade.sql
-- Run AFTER all existing schema scripts.
--
-- Changes:
--   1. Add org_head_id to TM_Organizations
--      → The designated head of an organization.
--      → Can create/manage departments like an org_admin.
--
--   2. Add dept_head_id to TM_Teams
--      → Each department can have its own separate head.
--
-- NOTE: dept_head_id and org_head_id are nullable;
--       no head is required.
-- =============================================

-- ── 1. Organization Head ─────────────────────
ALTER TABLE TM_Organizations
    ADD org_head_id NUMBER(10) DEFAULT NULL;

ALTER TABLE TM_Organizations
    ADD CONSTRAINT fk_org_head
        FOREIGN KEY (org_head_id)
        REFERENCES TM_Users(user_id)
        ON DELETE SET NULL;

CREATE INDEX idx_tm_org_head ON TM_Organizations(org_head_id);

-- ── 2. Department (Team) Head ─────────────────
ALTER TABLE TM_Teams
    ADD dept_head_id NUMBER(10) DEFAULT NULL;

ALTER TABLE TM_Teams
    ADD CONSTRAINT fk_dept_head
        FOREIGN KEY (dept_head_id)
        REFERENCES TM_Users(user_id)
        ON DELETE SET NULL;

CREATE INDEX idx_tm_dept_head ON TM_Teams(dept_head_id);

COMMIT;
