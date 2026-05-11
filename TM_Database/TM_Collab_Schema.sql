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

