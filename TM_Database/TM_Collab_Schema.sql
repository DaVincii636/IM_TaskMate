-- =============================================
-- TM_Collab_Schema.sql  (Run 3rd)
-- Collaboration: task assignment, projects,
-- comments, and mention notifications.
-- Depends on: TM_Org_Schema.sql
-- =============================================

-- ── CHANGE 1: Task Assignment ─────────────────
ALTER TABLE TM_Tasks ADD assigned_to NUMBER(10) DEFAULT NULL;

ALTER TABLE TM_Tasks ADD CONSTRAINT fk_tm_task_assigned
    FOREIGN KEY (assigned_to) REFERENCES TM_Users(user_id) ON DELETE SET NULL;

CREATE INDEX idx_tm_tasks_assigned ON TM_Tasks(assigned_to);

-- ── CHANGE 2: Projects ────────────────────────
CREATE TABLE TM_Projects (
    project_id  NUMBER(10)    NOT NULL,
    name        VARCHAR2(150) NOT NULL,
    description VARCHAR2(500),
    color       VARCHAR2(20)  DEFAULT '#3b82f6',
    created_by  NUMBER(10)    NOT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_projects  PRIMARY KEY (project_id),
    CONSTRAINT fk_proj_creator FOREIGN KEY (created_by)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE
);

CREATE SEQUENCE TM_Projects_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_projects_id
    BEFORE INSERT ON TM_Projects FOR EACH ROW
BEGIN
    IF :NEW.project_id IS NULL THEN
        SELECT TM_Projects_seq.NEXTVAL INTO :NEW.project_id FROM DUAL;
    END IF;
END;
/

CREATE TABLE TM_ProjectMembers (
    member_id  NUMBER(10)   NOT NULL,
    project_id NUMBER(10)   NOT NULL,
    user_id    NUMBER(10)   NOT NULL,
    role       VARCHAR2(20) DEFAULT 'member',
    joined_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_proj_members PRIMARY KEY (member_id),
    CONSTRAINT fk_pm_project      FOREIGN KEY (project_id)
        REFERENCES TM_Projects(project_id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_user         FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_pm_pair         UNIQUE (project_id, user_id),
    CONSTRAINT chk_pm_role        CHECK (role IN ('owner', 'member'))
);

CREATE SEQUENCE TM_ProjMembers_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_projmembers_id
    BEFORE INSERT ON TM_ProjectMembers FOR EACH ROW
BEGIN
    IF :NEW.member_id IS NULL THEN
        SELECT TM_ProjMembers_seq.NEXTVAL INTO :NEW.member_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_pm_project ON TM_ProjectMembers(project_id);
CREATE INDEX idx_pm_user    ON TM_ProjectMembers(user_id);

ALTER TABLE TM_Tasks ADD project_id NUMBER(10) DEFAULT NULL;

ALTER TABLE TM_Tasks ADD CONSTRAINT fk_tm_task_project
    FOREIGN KEY (project_id) REFERENCES TM_Projects(project_id) ON DELETE SET NULL;

CREATE INDEX idx_tm_tasks_project ON TM_Tasks(project_id);

-- ── CHANGE 3: Comments ────────────────────────
CREATE TABLE TM_Comments (
    comment_id NUMBER(10) NOT NULL,
    task_id    NUMBER(10) NOT NULL,
    user_id    NUMBER(10) NOT NULL,
    content    CLOB       NOT NULL,
    created_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_comments PRIMARY KEY (comment_id),
    CONSTRAINT fk_cmt_task    FOREIGN KEY (task_id)
        REFERENCES TM_Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT fk_cmt_user    FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE
);

CREATE SEQUENCE TM_Comments_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_comments_id
    BEFORE INSERT ON TM_Comments FOR EACH ROW
BEGIN
    IF :NEW.comment_id IS NULL THEN
        SELECT TM_Comments_seq.NEXTVAL INTO :NEW.comment_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_cmt_task ON TM_Comments(task_id);
CREATE INDEX idx_cmt_user ON TM_Comments(user_id);

-- ── CHANGE 4: Wire comment FK into Notifications ──
-- comment_id column was already added in TM_DatabaseSetup.sql;
-- add the FK now that TM_Comments exists.
ALTER TABLE TM_Notifications ADD CONSTRAINT fk_notif_comment
    FOREIGN KEY (comment_id) REFERENCES TM_Comments(comment_id) ON DELETE SET NULL;

COMMIT;
