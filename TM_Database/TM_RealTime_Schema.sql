-- =============================================
-- TM_RealTime_Schema.sql  (Run 4th)
-- Real-time collaboration via polling.
-- Depends on: TM_Collab_Schema.sql
-- =============================================

-- ── CHANGE LOG ────────────────────────────────
CREATE TABLE TM_TaskChangeLog (
    change_id   NUMBER(10)   NOT NULL,
    task_id     NUMBER(10)   NOT NULL,
    changed_by  NUMBER(10),
    change_type VARCHAR2(20) NOT NULL,
    changed_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_changelog PRIMARY KEY (change_id),
    CONSTRAINT fk_cl_task      FOREIGN KEY (task_id)
        REFERENCES TM_Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT fk_cl_user      FOREIGN KEY (changed_by)
        REFERENCES TM_Users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_cl_type     CHECK (change_type IN ('create','update','delete'))
);

CREATE SEQUENCE TM_ChangeLog_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_changelog_id
    BEFORE INSERT ON TM_TaskChangeLog FOR EACH ROW
BEGIN
    IF :NEW.change_id IS NULL THEN
        SELECT TM_ChangeLog_seq.NEXTVAL INTO :NEW.change_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_cl_changed_at ON TM_TaskChangeLog(changed_at DESC);
CREATE INDEX idx_cl_task_id    ON TM_TaskChangeLog(task_id);

-- ── ACTIVE PRESENCE ───────────────────────────
CREATE TABLE TM_ActivePresence (
    presence_id NUMBER(10)   NOT NULL,
    user_id     NUMBER(10)   NOT NULL,
    page_type   VARCHAR2(20) DEFAULT 'dashboard',
    task_id     NUMBER(10),
    project_id  NUMBER(10),
    last_ping   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_presence PRIMARY KEY (presence_id),
    CONSTRAINT fk_pres_user   FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_pres_user   UNIQUE (user_id)
);

CREATE SEQUENCE TM_Presence_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_presence_id
    BEFORE INSERT ON TM_ActivePresence FOR EACH ROW
BEGIN
    IF :NEW.presence_id IS NULL THEN
        SELECT TM_Presence_seq.NEXTVAL INTO :NEW.presence_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_pres_task ON TM_ActivePresence(task_id);
CREATE INDEX idx_pres_ping ON TM_ActivePresence(last_ping DESC);

COMMIT;
