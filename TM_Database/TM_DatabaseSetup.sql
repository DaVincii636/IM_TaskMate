-- USERS TABLE
CREATE TABLE TM_Users (
    user_id       NUMBER(10)    NOT NULL,
    username      VARCHAR2(50)  NOT NULL,
    email         VARCHAR2(100) NOT NULL,
    password_hash VARCHAR2(255) NOT NULL,
    first_name    VARCHAR2(100),
    last_name     VARCHAR2(100),
    phone         VARCHAR2(20),
    role          VARCHAR2(20)  DEFAULT 'user',
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_users    PRIMARY KEY (user_id),
    CONSTRAINT uq_tm_username UNIQUE (username),
    CONSTRAINT uq_tm_email    UNIQUE (email)
);

-- Add role constraint (run once on existing DB)
ALTER TABLE TM_Users ADD CONSTRAINT chk_tm_role 
    CHECK (role IN ('user', 'moderator', 'admin'));

-- TASKS TABLE

CREATE TABLE TM_Tasks (
    task_id         NUMBER(10)    NOT NULL,
    user_id         NUMBER(10)    NOT NULL,
    task_name       VARCHAR2(255) NOT NULL,
    start_date      DATE          NOT NULL,
    due_date        DATE          NOT NULL,
    category        VARCHAR2(50)  DEFAULT 'errands',
    custom_category VARCHAR2(100),
    priority        VARCHAR2(20)  DEFAULT 'mid',
    color           VARCHAR2(20)  DEFAULT '#ef4444',
    notes           CLOB,
    status          VARCHAR2(20)  DEFAULT 'pending',
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_tasks  PRIMARY KEY (task_id),
    CONSTRAINT fk_tm_user   FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE
);

-- SEQUENCES 
CREATE SEQUENCE TM_Users_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;
CREATE SEQUENCE TM_Tasks_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

-- TRIGGERS
CREATE OR REPLACE TRIGGER trg_tm_users_id
    BEFORE INSERT ON TM_Users FOR EACH ROW
BEGIN
    IF :NEW.user_id IS NULL THEN
        SELECT TM_Users_seq.NEXTVAL INTO :NEW.user_id FROM DUAL;
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_tm_tasks_id
    BEFORE INSERT ON TM_Tasks FOR EACH ROW
BEGIN
    IF :NEW.task_id IS NULL THEN
        SELECT TM_Tasks_seq.NEXTVAL INTO :NEW.task_id FROM DUAL;
    END IF;
END;
/

-- INDEXES
CREATE INDEX idx_tm_tasks_user_id  ON TM_Tasks(user_id);
CREATE INDEX idx_tm_tasks_due_date ON TM_Tasks(due_date);
CREATE INDEX idx_tm_users_email    ON TM_Users(email);

COMMIT;

-- AUDIT LOG TABLE
-- Stores every create / edit / delete / status-change event across the system.
-- entity_type: 'task' | 'user'
-- action:      'create' | 'edit' | 'delete' | 'status_change'
-- old_value / new_value: VARCHAR2 snapshots (JSON-style summary, optional)
CREATE TABLE TM_AuditLog (
    log_id      NUMBER(10)     NOT NULL,
    user_id     NUMBER(10)     NOT NULL,           -- who performed the action
    action      VARCHAR2(30)   NOT NULL,           -- create | edit | delete | status_change
    entity_type VARCHAR2(20)   NOT NULL,           -- task | user
    entity_id   NUMBER(10)     NOT NULL,           -- task_id or user_id affected
    entity_name VARCHAR2(255),                     -- snapshot of the name at action time
    old_value   VARCHAR2(500),                     -- brief before-state (optional)
    new_value   VARCHAR2(500),                     -- brief after-state  (optional)
    created_at  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_auditlog PRIMARY KEY (log_id),
    CONSTRAINT fk_audit_user  FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE
);

CREATE SEQUENCE TM_AuditLog_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_auditlog_id
    BEFORE INSERT ON TM_AuditLog FOR EACH ROW
BEGIN
    IF :NEW.log_id IS NULL THEN
        SELECT TM_AuditLog_seq.NEXTVAL INTO :NEW.log_id FROM DUAL;
    END IF;
END;
/

-- Index for fast per-user feed and per-entity lookups
CREATE INDEX idx_tm_audit_user       ON TM_AuditLog(user_id);
CREATE INDEX idx_tm_audit_entity     ON TM_AuditLog(entity_type, entity_id);
CREATE INDEX idx_tm_audit_created_at ON TM_AuditLog(created_at DESC);

-- VERIFY
SELECT 'TM_Users'    AS tbl, COUNT(*) AS rows FROM TM_Users
UNION ALL
SELECT 'TM_Tasks',   COUNT(*) FROM TM_Tasks
UNION ALL
SELECT 'TM_AuditLog', COUNT(*) FROM TM_AuditLog;