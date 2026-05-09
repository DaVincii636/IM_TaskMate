SELECT column_name, data_default 
FROM user_tab_columns 
WHERE table_name = 'TM_USERS' AND column_name = 'STATUS';