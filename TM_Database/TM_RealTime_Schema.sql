-- =============================================
-- TM_RealTime_Schema.sql
-- COLLABORATION & MULTI-USER — Change 5
-- Real-time collaboration via polling
-- Run this AFTER TM_Collab_Schema.sql
-- =============================================

-- ──────────────────────────────────────────────
-- CHANGE 5a: Add updated_at to TM_Tasks
-- Tracks the last time any field on a task changed
-- so the polling endpoint can return only deltas.
-- ──────────────────────────────────────────────
ALTER TABLE TM_Tasks ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Back-fill existing rows so the column is never NULL
UPDATE TM_Tasks SET updated_at = created_at WHERE updated_at IS NULL;
COMMIT;

-- Auto-update updated_at on every row change
CREATE OR REPLACE TRIGGER trg_tm_tasks_updated_at
    BEFORE UPDATE ON TM_Tasks
    FOR EACH ROW
BEGIN
    :NEW.updated_at := CURRENT_TIMESTAMP;
END;
/

-- ──────────────────────────────────────────────
-- CHANGE 5b: TM_TaskChangeLog
-- Lightweight audit log written by the trigger
-- above.  The polling endpoint queries this table
-- instead of scanning TM_Tasks, keeping it fast
-- even with thousands of tasks.
-- ──────────────────────────────────────────────
CREATE TABLE TM_TaskChangeLog (
    change_id   NUMBER(10)   NOT NULL,
    task_id     NUMBER(10)   NOT NULL,
    changed_by  NUMBER(10),              -- user who made the change (NULL = system)
    change_type VARCHAR2(20) NOT NULL,   -- 'update' | 'delete' | 'create'
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

-- Index for the polling query: "give me changes after timestamp T"
CREATE INDEX idx_cl_changed_at ON TM_TaskChangeLog(changed_at DESC);
CREATE INDEX idx_cl_task_id    ON TM_TaskChangeLog(task_id);

-- ──────────────────────────────────────────────
-- CHANGE 5c: TM_ActivePresence
-- Tracks which users are currently viewing which
-- tasks/projects.  Rows older than 60 s are stale
-- and ignored by the poller.
-- ──────────────────────────────────────────────
CREATE TABLE TM_ActivePresence (
    presence_id NUMBER(10)  NOT NULL,
    user_id     NUMBER(10)  NOT NULL,
    page_type   VARCHAR2(20) DEFAULT 'dashboard',  -- 'dashboard' | 'tasks' | 'task_detail'
    task_id     NUMBER(10),                         -- NULL unless viewing a specific task
    project_id  NUMBER(10),
    last_ping   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_presence  PRIMARY KEY (presence_id),
    CONSTRAINT fk_pres_user    FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_pres_user    UNIQUE (user_id)    -- one row per user, upserted
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

CREATE INDEX idx_pres_task    ON TM_ActivePresence(task_id);
CREATE INDEX idx_pres_ping    ON TM_ActivePresence(last_ping DESC);

-- ──────────────────────────────────────────────
-- VERIFICATION
-- ──────────────────────────────────────────────
SELECT 'TM_TaskChangeLog'  AS tbl, COUNT(*) AS rows FROM TM_TaskChangeLog
UNION ALL
SELECT 'TM_ActivePresence', COUNT(*) FROM TM_ActivePresence;

COMMIT;
