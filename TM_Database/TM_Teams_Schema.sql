-- =============================================
-- FEATURE 8 — DEPARTMENT / TEAM GROUPING
-- Run this file once on your existing DB, after
-- TM_Org_Schema.sql (org_id must exist on TM_Users).
--
-- Creates:
--   TM_Teams        — one row per team/department
--   TM_TeamMembers  — junction: user <-> team (with manager flag)
--   VW_Team_Tasks   — convenience view: tasks scoped to a team
--   sp_add_team_member    — safely add a member (no dupe)
--   sp_remove_team_member — remove a member
-- =============================================

-- 1. TEAMS TABLE
CREATE TABLE TM_Teams (
    team_id     NUMBER(10)    NOT NULL,
    org_id      NUMBER(10)    NOT NULL,
    team_name   VARCHAR2(100) NOT NULL,
    team_desc VARCHAR2(500),
    created_by  NUMBER(10)    NOT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_teams       PRIMARY KEY (team_id),
    CONSTRAINT fk_teams_org      FOREIGN KEY (org_id)
        REFERENCES TM_Organizations(org_id) ON DELETE CASCADE,
    CONSTRAINT fk_teams_creator  FOREIGN KEY (created_by)
        REFERENCES TM_Users(user_id),
    CONSTRAINT uq_team_name_org  UNIQUE (org_id, team_name)
);

CREATE SEQUENCE TM_Teams_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_teams_id
    BEFORE INSERT ON TM_Teams FOR EACH ROW
BEGIN
    IF :NEW.team_id IS NULL THEN
        SELECT TM_Teams_seq.NEXTVAL INTO :NEW.team_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_tm_teams_org ON TM_Teams(org_id);

-- 2. TEAM MEMBERS JUNCTION TABLE
-- is_manager = 1 means this member is the team lead/manager.
-- A user can belong to multiple teams.
CREATE TABLE TM_TeamMembers (
    member_id  NUMBER(10) NOT NULL,
    team_id    NUMBER(10) NOT NULL,
    user_id    NUMBER(10) NOT NULL,
    is_manager NUMBER(1)  DEFAULT 0 NOT NULL,
    joined_at  TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_tm_team_members   PRIMARY KEY (member_id),
    CONSTRAINT fk_tm_mem_team       FOREIGN KEY (team_id)
        REFERENCES TM_Teams(team_id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_mem_user       FOREIGN KEY (user_id)
        REFERENCES TM_Users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_team_member       UNIQUE (team_id, user_id),
    CONSTRAINT chk_tm_is_manager    CHECK (is_manager IN (0, 1))
);

CREATE SEQUENCE TM_TeamMembers_seq START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER trg_tm_team_members_id
    BEFORE INSERT ON TM_TeamMembers FOR EACH ROW
BEGIN
    IF :NEW.member_id IS NULL THEN
        SELECT TM_TeamMembers_seq.NEXTVAL INTO :NEW.member_id FROM DUAL;
    END IF;
END;
/

CREATE INDEX idx_tm_mem_team ON TM_TeamMembers(team_id);
CREATE INDEX idx_tm_mem_user ON TM_TeamMembers(user_id);

-- 3. CONVENIENCE VIEW: tasks belonging to a team
-- Join pattern: task -> task owner -> team membership -> team
-- Used by TM_Tasks.php and TM_Analytics.php for the team filter.
CREATE OR REPLACE VIEW VW_Team_Tasks AS
SELECT
    t.task_id,
    t.user_id,
    t.org_id,
    t.task_name,
    t.category,
    t.custom_category,
    t.priority,
    t.status,
    t.due_date,
    t.start_date,
    t.color,
    tm.team_id,
    te.team_name,
    te.org_id AS team_org_id
FROM TM_Tasks t
JOIN TM_TeamMembers tm ON tm.user_id = t.user_id
JOIN TM_Teams       te ON te.team_id = tm.team_id;

-- 4. STORED PROCEDURE: add a member to a team
-- Silently skips if the user is already a member.
CREATE OR REPLACE PROCEDURE sp_add_team_member (
    p_team_id    IN NUMBER,
    p_user_id    IN NUMBER,
    p_is_manager IN NUMBER DEFAULT 0,
    p_added_by   IN NUMBER
) AS
    v_count NUMBER;
    v_tname VARCHAR2(100);
    v_uname VARCHAR2(200);
BEGIN
    SELECT COUNT(*) INTO v_count
    FROM TM_TeamMembers
    WHERE team_id = p_team_id AND user_id = p_user_id;

    IF v_count = 0 THEN
        INSERT INTO TM_TeamMembers (team_id, user_id, is_manager)
        VALUES (p_team_id, p_user_id, p_is_manager);

        SELECT team_name INTO v_tname FROM TM_Teams WHERE team_id = p_team_id;
        SELECT first_name || ' ' || last_name INTO v_uname FROM TM_Users WHERE user_id = p_user_id;

        INSERT INTO TM_AuditLog
            (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
        VALUES
            (p_added_by, 'edit', 'user', p_user_id, v_uname,
             '', 'added_to_team:' || v_tname);

        COMMIT;
    END IF;
END;
/

-- 5. STORED PROCEDURE: remove a member from a team
CREATE OR REPLACE PROCEDURE sp_remove_team_member (
    p_team_id    IN NUMBER,
    p_user_id    IN NUMBER,
    p_removed_by IN NUMBER
) AS
    v_tname VARCHAR2(100);
    v_uname VARCHAR2(200);
BEGIN
    SELECT team_name INTO v_tname FROM TM_Teams WHERE team_id = p_team_id;
    SELECT first_name || ' ' || last_name INTO v_uname FROM TM_Users WHERE user_id = p_user_id;

    DELETE FROM TM_TeamMembers
    WHERE team_id = p_team_id AND user_id = p_user_id;

    INSERT INTO TM_AuditLog
        (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
    VALUES
        (p_removed_by, 'edit', 'user', p_user_id, v_uname,
         'team:' || v_tname, 'removed_from_team');

    COMMIT;
EXCEPTION
    WHEN NO_DATA_FOUND THEN NULL;
END;
/

COMMIT;

-- VERIFY
SELECT 'TM_Teams'        AS tbl, COUNT(*) AS rows FROM TM_Teams
UNION ALL
SELECT 'TM_TeamMembers',          COUNT(*) FROM TM_TeamMembers;