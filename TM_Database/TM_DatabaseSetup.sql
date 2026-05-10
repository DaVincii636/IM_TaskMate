-- =============================================
-- TM_DatabaseSetup.sql  (Run 1st)
-- Core tables, sequences, triggers, indexes.
-- =============================================

-- ── USERS ────────────────────────────────────
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
    CONSTRAINT uq_tm_email    UNIQUE (email),
    CONSTRAINT chk_tm_role    CHECK (role IN ('user', 'moderator', 'org_admin', 'admin'))
);

CREATE SEQUENCE TM_Users_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_users_id
    BEFORE INSERT ON TM_Users FOR EACH ROW
BEGIN
    IF :NEW.user_id IS NULL THEN
        SELECT TM_Users_seq.NEXTVAL INTO :NEW.user_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_tm_users_email ON TM_Users(email);

-- ── TASKS ─────────────────────────────────────
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
    recurrence      VARCHAR2(20)  DEFAULT NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_tasks        PRIMARY KEY (task_id),
    CONSTRAINT fk_tm_user         FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_tm_recurrence  CHECK (recurrence IS NULL OR recurrence IN ('daily','weekly','monthly'))
);

CREATE SEQUENCE TM_Tasks_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_tasks_id
    BEFORE INSERT ON TM_Tasks FOR EACH ROW
BEGIN
    IF :NEW.task_id IS NULL THEN
        SELECT TM_Tasks_seq.NEXTVAL INTO :NEW.task_id FROM DUAL;
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_tm_tasks_updated_at
    BEFORE UPDATE ON TM_Tasks FOR EACH ROW
BEGIN
    :NEW.updated_at := CURRENT_TIMESTAMP;
END;
/

CREATE INDEX idx_tm_tasks_user_id  ON TM_Tasks(user_id);
CREATE INDEX idx_tm_tasks_due_date ON TM_Tasks(due_date);

-- ── NOTIFICATIONS ─────────────────────────────
CREATE TABLE TM_Notifications (
    notif_id     NUMBER(10)    NOT NULL,
    user_id      NUMBER(10)    NOT NULL,
    task_id      NUMBER(10),
    type         VARCHAR2(20)  NOT NULL,
    message      VARCHAR2(500) NOT NULL,
    is_read      NUMBER(1)     DEFAULT 0 NOT NULL,
    source_type  VARCHAR2(20)  DEFAULT NULL,
    mentioned_by NUMBER(10)    DEFAULT NULL,
    comment_id   NUMBER(10)    DEFAULT NULL,
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_notif        PRIMARY KEY (notif_id),
    CONSTRAINT fk_notif_user      FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_task      FOREIGN KEY (task_id)
        REFERENCES TM_Tasks(task_id) ON DELETE SET NULL,
    CONSTRAINT fk_notif_mentioned FOREIGN KEY (mentioned_by)
        REFERENCES TM_Users(user_id) ON DELETE SET NULL
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

-- ── AUDIT LOG ─────────────────────────────────
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
    CONSTRAINT pk_tm_auditlog   PRIMARY KEY (log_id),
    CONSTRAINT fk_audit_user    FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_audit_action CHECK (action IN ('create','edit','delete','status_change')),
    CONSTRAINT chk_audit_entity CHECK (entity_type IN ('task','user'))
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

-- ── TASK LINKS ────────────────────────────────
CREATE TABLE TM_TaskLinks (
    link_id       NUMBER(10)   NOT NULL,
    task_id       NUMBER(10)   NOT NULL,
    depends_on_id NUMBER(10)   NOT NULL,
    link_type     VARCHAR2(20) NOT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_tasklinks  PRIMARY KEY (link_id),
    CONSTRAINT fk_tl_task       FOREIGN KEY (task_id)
        REFERENCES TM_Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT fk_tl_depends_on FOREIGN KEY (depends_on_id)
        REFERENCES TM_Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT chk_tl_link_type CHECK (link_type IN ('blocks','relates_to')),
    CONSTRAINT uq_tl_pair       UNIQUE (task_id, depends_on_id)
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

CREATE INDEX idx_tl_task_id       ON TM_TaskLinks(task_id);
CREATE INDEX idx_tl_depends_on_id ON TM_TaskLinks(depends_on_id);

-- ── USER PREFS ────────────────────────────────
CREATE TABLE TM_UserPrefs (
    pref_id         NUMBER(10) NOT NULL,
    user_id         NUMBER(10) NOT NULL,
    onboarding_done NUMBER(1)  DEFAULT 0 NOT NULL,
    updated_at      TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_userprefs PRIMARY KEY (pref_id),
    CONSTRAINT fk_prefs_user   FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_prefs_user   UNIQUE (user_id),
    CONSTRAINT chk_onboarding  CHECK (onboarding_done IN (0, 1))
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
