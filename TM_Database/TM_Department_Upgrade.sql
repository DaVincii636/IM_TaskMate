-- =============================================
-- TM_Department_Upgrade.sql
-- Run AFTER all existing schema scripts.
--
-- Changes:
--   1. Add org_head_id to TM_Organizations
--   2. Add dept_head_id to TM_Teams
-- =============================================

-- ── 1. Organization Head ─────────────────────
BEGIN EXECUTE IMMEDIATE 'ALTER TABLE TM_Organizations ADD org_head_id NUMBER(10) DEFAULT NULL';
EXCEPTION WHEN OTHERS THEN NULL; END;
/
BEGIN EXECUTE IMMEDIATE 'ALTER TABLE TM_Organizations ADD CONSTRAINT fk_org_head FOREIGN KEY (org_head_id) REFERENCES TM_Users(user_id) ON DELETE SET NULL';
EXCEPTION WHEN OTHERS THEN NULL; END;
/
BEGIN EXECUTE IMMEDIATE 'CREATE INDEX idx_tm_org_head ON TM_Organizations(org_head_id)';
EXCEPTION WHEN OTHERS THEN NULL; END;
/

-- ── 2. Department (Team) Head ─────────────────
BEGIN EXECUTE IMMEDIATE 'ALTER TABLE TM_Teams ADD dept_head_id NUMBER(10) DEFAULT NULL';
EXCEPTION WHEN OTHERS THEN NULL; END;
/
BEGIN EXECUTE IMMEDIATE 'ALTER TABLE TM_Teams ADD CONSTRAINT fk_dept_head FOREIGN KEY (dept_head_id) REFERENCES TM_Users(user_id) ON DELETE SET NULL';
EXCEPTION WHEN OTHERS THEN NULL; END;
/
BEGIN EXECUTE IMMEDIATE 'CREATE INDEX idx_tm_dept_head ON TM_Teams(dept_head_id)';
EXCEPTION WHEN OTHERS THEN NULL; END;
/

COMMIT;
