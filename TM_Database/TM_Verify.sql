-- =============================================
-- TM_Verify.sql
-- Run this any time to check the state of the DB.
-- Safe to run repeatedly — SELECT only, no changes.
-- =============================================

-- Table row counts
SELECT 'TM_Organizations' AS tbl, COUNT(*) AS rows FROM TM_Organizations UNION ALL
SELECT 'TM_Users',                COUNT(*) FROM TM_Users                 UNION ALL
SELECT 'TM_Tasks',                COUNT(*) FROM TM_Tasks                 UNION ALL
SELECT 'TM_Notifications',        COUNT(*) FROM TM_Notifications         UNION ALL
SELECT 'TM_AuditLog',             COUNT(*) FROM TM_AuditLog              UNION ALL
SELECT 'TM_TaskLinks',            COUNT(*) FROM TM_TaskLinks             UNION ALL
SELECT 'TM_UserPrefs',            COUNT(*) FROM TM_UserPrefs             UNION ALL
SELECT 'TM_Projects',             COUNT(*) FROM TM_Projects              UNION ALL
SELECT 'TM_ProjectMembers',       COUNT(*) FROM TM_ProjectMembers        UNION ALL
SELECT 'TM_Comments',             COUNT(*) FROM TM_Comments              UNION ALL
SELECT 'TM_TaskChangeLog',        COUNT(*) FROM TM_TaskChangeLog         UNION ALL
SELECT 'TM_ActivePresence',       COUNT(*) FROM TM_ActivePresence        UNION ALL
SELECT 'TM_Teams',                COUNT(*) FROM TM_Teams                 UNION ALL
SELECT 'TM_TeamMembers',          COUNT(*) FROM TM_TeamMembers
ORDER BY tbl;

-- Seed account check (login-ready users)
SELECT user_id, username, email, role, status, org_id
FROM TM_Users
WHERE username IN ('admin', 'basicuser');

-- Procedure status
SELECT object_name, status
FROM user_objects
WHERE object_type = 'PROCEDURE'
  AND object_name IN (
    'TM_CREATETASK', 'TM_UPDATETASKSTATUS', 'TM_WRITEAUDITLOG',
    'TM_CREATEORG',  'TM_TRANSFERUSERORG',
    'SP_ADD_TEAM_MEMBER', 'SP_REMOVE_TEAM_MEMBER',
    'SP_APPROVE_USER', 'SP_SUSPEND_USER'
  )
ORDER BY object_name;

-- User status breakdown
SELECT status, COUNT(*) AS cnt FROM TM_Users GROUP BY status;
