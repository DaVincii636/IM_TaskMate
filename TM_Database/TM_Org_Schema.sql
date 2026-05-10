-- =============================================
-- TM_Org_Schema.sql  (Run 2nd)
-- Organization / tenant management.
-- Depends on: TM_DatabaseSetup.sql
-- =============================================

-- ── ORGANIZATIONS TABLE ───────────────────────
CREATE TABLE TM_Organizations (
    org_id     NUMBER(10)    NOT NULL,
    org_name   VARCHAR2(100) NOT NULL,
    plan       VARCHAR2(20)  DEFAULT 'free' NOT NULL,
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_orgs      PRIMARY KEY (org_id),
    CONSTRAINT uq_tm_orgname   UNIQUE (org_name),
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

-- Seed default org (org_id = 1) so FK on TM_Users is satisfiable
INSERT INTO TM_Organizations (org_name, plan) VALUES ('Default Organization', 'free');
COMMIT;

-- ── ADD org_id + status TO TM_Users ──────────
ALTER TABLE TM_Users ADD org_id NUMBER(10) DEFAULT 1 NOT NULL;
ALTER TABLE TM_Users ADD status VARCHAR2(20) DEFAULT 'active' NOT NULL;

ALTER TABLE TM_Users ADD CONSTRAINT fk_users_org
    FOREIGN KEY (org_id) REFERENCES TM_Organizations(org_id);

ALTER TABLE TM_Users ADD CONSTRAINT chk_tm_user_status
    CHECK (status IN ('pending', 'active', 'suspended'));

CREATE INDEX idx_tm_users_org    ON TM_Users(org_id);
CREATE INDEX idx_tm_users_status ON TM_Users(status);

-- ── ADD org_id TO TM_Tasks ────────────────────
ALTER TABLE TM_Tasks ADD org_id NUMBER(10) DEFAULT 1 NOT NULL;

ALTER TABLE TM_Tasks ADD CONSTRAINT fk_tasks_org
    FOREIGN KEY (org_id) REFERENCES TM_Organizations(org_id);

CREATE INDEX idx_tm_tasks_org ON TM_Tasks(org_id);

-- ── ORG-SCOPED VIEW ───────────────────────────
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

-- ── ORG MANAGEMENT PROCEDURES ────────────────
CREATE OR REPLACE PROCEDURE TM_CreateOrg (
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

CREATE OR REPLACE PROCEDURE TM_TransferUserOrg (
    p_user_id    IN NUMBER,
    p_new_org_id IN NUMBER,
    p_moved_by   IN NUMBER
) AS
    v_old_org NUMBER;
BEGIN
    SELECT org_id INTO v_old_org FROM TM_Users WHERE user_id = p_user_id;

    UPDATE TM_Users SET org_id = p_new_org_id WHERE user_id = p_user_id;
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
