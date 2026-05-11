-- =============================================
-- TM_Verify.sql
-- Run this any time to check the state of the DB.
-- Safe to run repeatedly — -- Seed account check (login-ready users)
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
