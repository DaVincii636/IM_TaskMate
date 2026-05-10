-- =============================================
-- TM_SeedAccounts.sql
-- Run this LAST, after all other schema files.
-- Inserts two ready-to-use accounts into TM_Users.
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

-- Verify
SELECT user_id, username, email, role, status FROM TM_Users WHERE username IN ('admin', 'basicuser');
