-- =============================================
-- TM_SeedAccounts.sql  (Run 7th — LAST)
-- Inserts two ready-to-use accounts.
-- Depends on: TM_Org_Schema.sql (org_id = 1 must exist)
--
-- Admin   → admin@taskmate.com  / Admin@1234
-- User    → user@taskmate.com   / User@1234
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
