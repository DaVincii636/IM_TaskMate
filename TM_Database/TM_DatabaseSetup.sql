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