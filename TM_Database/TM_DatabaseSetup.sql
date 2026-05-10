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

-- VERIFY
SELECT 'TM_Users' AS tbl, COUNT(*) AS rows FROM TM_Users
UNION ALL
SELECT 'TM_Tasks', COUNT(*) FROM TM_Tasks;

-- NOTIFICATIONS TABLE
-- Populated by TM_PHP/TM_NotifCron.php (run via cron or manually on login).
-- type: 'overdue' | 'due_today' | 'due_soon'  (due_soon = within 3 days)
-- is_read: 0 = unread, 1 = read
CREATE TABLE TM_Notifications (
    notif_id   NUMBER(10)    NOT NULL,
    user_id    NUMBER(10)    NOT NULL,
    task_id    NUMBER(10),
    type       VARCHAR2(20)  NOT NULL,
    message    VARCHAR2(500) NOT NULL,
    is_read    NUMBER(1)     DEFAULT 0 NOT NULL,
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_notif   PRIMARY KEY (notif_id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_task FOREIGN KEY (task_id)
        REFERENCES TM_Tasks(task_id) ON DELETE SET NULL
);

CREATE SEQUENCE TM_Notif_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_notif_id
    BEFORE INSERT ON TM_Notifications FOR EACH ROW
BEGIN
    IF :NEW.notif_id IS NULL THEN
        SELECT TM_Notif_seq.NEXTVAL INTO :NEW.notif_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_tm_notif_user ON TM_Notifications(user_id, is_read);
CREATE INDEX idx_tm_notif_task ON TM_Notifications(task_id);

COMMIT;

-- =============================================
-- AUDIT LOG TABLE
-- Append this block to the bottom of TM_DatabaseSetup.sql
-- Records every create / edit / delete / status_change event.
-- entity_type: 'task' | 'user'
-- action:      'create' | 'edit' | 'delete' | 'status_change'
-- =============================================

CREATE TABLE TM_AuditLog (
    log_id      NUMBER(10)    NOT NULL,
    user_id     NUMBER(10)    NOT NULL,
    action      VARCHAR2(20)  NOT NULL,
    entity_type VARCHAR2(20)  NOT NULL,
    entity_id   NUMBER(10)    NOT NULL,
    entity_name VARCHAR2(255),
    old_value   VARCHAR2(500),
    new_value   VARCHAR2(500),
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_auditlog  PRIMARY KEY (log_id),
    CONSTRAINT fk_audit_user   FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_audit_action
        CHECK (action IN ('create','edit','delete','status_change')),
    CONSTRAINT chk_audit_entity
        CHECK (entity_type IN ('task','user'))
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

CREATE INDEX idx_tm_audit_user       ON TM_AuditLog(user_id);
CREATE INDEX idx_tm_audit_created_at ON TM_AuditLog(created_at DESC);
CREATE INDEX idx_tm_audit_entity     ON TM_AuditLog(entity_type, entity_id);

COMMIT;

-- VERIFY
SELECT 'TM_AuditLog', COUNT(*) FROM TM_AuditLog;

-- VERIFY (extended)
SELECT 'TM_Users'          AS tbl, COUNT(*) AS rows FROM TM_Users
UNION ALL
SELECT 'TM_Tasks',          COUNT(*) FROM TM_Tasks
UNION ALL
SELECT 'TM_Notifications',  COUNT(*) FROM TM_Notifications;

-- =============================================
-- TASK LINKS TABLE
-- Append this block to the bottom of TM_DatabaseSetup.sql
-- Run in Oracle BEFORE implementing the dependency UI (step 2)
-- and enforcement (step 3).
--
-- Models directed relationships between tasks:
--   link_type = 'blocks'     → task_id cannot be marked done
--                               until depends_on_id is done
--   link_type = 'relates_to' → informational only, no enforcement
--
-- Both task_id and depends_on_id FK to TM_Tasks with ON DELETE CASCADE
-- so removing a task automatically removes every link it was part of.
-- =============================================

CREATE TABLE TM_TaskLinks (
    link_id        NUMBER(10)  NOT NULL,
    task_id        NUMBER(10)  NOT NULL,   -- the task that is blocked
    depends_on_id  NUMBER(10)  NOT NULL,   -- the task that must be done first
    link_type      VARCHAR2(20) NOT NULL,
    created_at     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_tasklinks     PRIMARY KEY (link_id),
    CONSTRAINT fk_tl_task          FOREIGN KEY (task_id)
        REFERENCES TM_Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT fk_tl_depends_on    FOREIGN KEY (depends_on_id)
        REFERENCES TM_Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT chk_tl_link_type    CHECK (link_type IN ('blocks','relates_to')),
    CONSTRAINT uq_tl_pair          UNIQUE (task_id, depends_on_id)
);

CREATE SEQUENCE TM_TaskLinks_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_tasklinks_id
    BEFORE INSERT ON TM_TaskLinks FOR EACH ROW
BEGIN
    IF :NEW.link_id IS NULL THEN
        SELECT TM_TaskLinks_seq.NEXTVAL INTO :NEW.link_id FROM DUAL;
    END IF;
END;
/

-- Index for the most common query pattern:
--   "find all tasks that block task X" (used by enforcement in step 3)
--   "find all tasks that task X blocks" (used by the dependency UI in step 2)
CREATE INDEX idx_tl_task_id       ON TM_TaskLinks(task_id);
CREATE INDEX idx_tl_depends_on_id ON TM_TaskLinks(depends_on_id);

COMMIT;

-- VERIFY (full schema)
SELECT 'TM_Users'         AS tbl, COUNT(*) AS rows FROM TM_Users
UNION ALL
SELECT 'TM_Tasks',         COUNT(*) FROM TM_Tasks
UNION ALL
SELECT 'TM_Notifications', COUNT(*) FROM TM_Notifications
UNION ALL
SELECT 'TM_AuditLog',      COUNT(*) FROM TM_AuditLog
UNION ALL
SELECT 'TM_TaskLinks',     COUNT(*) FROM TM_TaskLinks;
-- =============================================
-- RECURRING TASKS (Feature 6)
-- Run these ALTER statements on existing DB.
-- recurrence: NULL | 'daily' | 'weekly' | 'monthly'
-- =============================================
ALTER TABLE TM_Tasks ADD recurrence VARCHAR2(20) DEFAULT NULL;
ALTER TABLE TM_Tasks ADD CONSTRAINT chk_tm_recurrence
    CHECK (recurrence IS NULL OR recurrence IN ('daily','weekly','monthly'));

COMMIT;

-- =============================================
-- FEATURE 11 — ONBOARDING TOOLTIPS
-- TM_UserPrefs table: stores per-user preference flags.
-- onboarding_done: 0 = walkthrough not yet shown, 1 = completed/dismissed.
-- HCI101 Week 2 (Learnability & Memorability), Week 4 (UCD), Week 10-11
-- (Prototyping — the overlay IS an interactive prototype on the live system).
-- =============================================
CREATE TABLE TM_UserPrefs (
    pref_id          NUMBER(10)   NOT NULL,
    user_id          NUMBER(10)   NOT NULL,
    onboarding_done  NUMBER(1)    DEFAULT 0 NOT NULL,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_userprefs  PRIMARY KEY (pref_id),
    CONSTRAINT fk_prefs_user    FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_prefs_user    UNIQUE (user_id),
    CONSTRAINT chk_onboarding   CHECK (onboarding_done IN (0, 1))
);

CREATE SEQUENCE TM_UserPrefs_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_userprefs_id
    BEFORE INSERT ON TM_UserPrefs FOR EACH ROW
BEGIN
    IF :NEW.pref_id IS NULL THEN
        SELECT TM_UserPrefs_seq.NEXTVAL INTO :NEW.pref_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_tm_userprefs_user ON TM_UserPrefs(user_id);

COMMIT;

-- VERIFY
SELECT 'TM_UserPrefs', COUNT(*) FROM TM_UserPrefs;

-- =============================================
-- SEED ACCOUNTS
-- Run AFTER TM_Org_Schema.sql so that org_id = 1 (Default Organization) exists.
--
-- Admin account
--   Email   : admin@taskmate.com
--   Password: Admin@1234
--
-- Basic user account
--   Email   : user@taskmate.com
--   Password: User@1234
-- =============================================
INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone, role, status, org_id)
VALUES (
    'admin',
    'admin@taskmate.com',
    '$2b$12$xNmjViR5UbqqQlPV1xEtjutCX9UNelr0YzhuvGTi63PaxQS24.yv.',
    'Admin',
    'User',
    '09000000001',
    'admin',
    'active',
    1
);

INSERT INTO TM_Users (username, email, password_hash, first_name, last_name, phone, role, status, org_id)
VALUES (
    'basicuser',
    'user@taskmate.com',
    '$2b$12$gNy7pJQFFmJzrvz0KUTJYOq9U2VdOY.flmBZ0hwmvyuAuLC3fe5UG',
    'Basic',
    'User',
    '09000000002',
    'user',
    'active',
    1
);

COMMIT;

-- VERIFY seed accounts
SELECT username, email, role, status FROM TM_Users WHERE username IN ('admin', 'basicuser');