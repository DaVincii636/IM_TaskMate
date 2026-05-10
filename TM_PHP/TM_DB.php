<?php
// =============================================
// TM_DB.php — Oracle DB connection + helpers
// =============================================
if (!function_exists('oci_connect')) {
    die(
        '<b>Configuration Error:</b> The PHP OCI8 extension is not enabled.<br>' .
        'Open <code>php.ini</code>, uncomment <code>extension=oci8</code>, ' .
        'then restart Apache.'
    );
}

$conn = oci_connect('SYSTEM', '0r4cl3', 'localhost/XE');
if (!$conn) {
    $e = oci_error();
    die('Database connection failed: ' . htmlspecialchars($e['message']));
}

/**
 * Execute a SQL statement with positional parameters (:p1, :p2, ...).
 *
 * FIX: oci_bind_by_name holds a reference to the variable, not a copy.
 * We must keep each bound value alive in a separate slot until oci_execute.
 * Binding directly from $params[$i] is safe because array slots persist,
 * but we make it explicit here to avoid any loop-variable aliasing issues.
 */
function tm_exec(string $sql, array $params = []) {
    global $conn;
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        $e = oci_error($conn);
        throw new RuntimeException('oci_parse failed: ' . ($e['message'] ?? 'unknown error'));
    }
    $bound = [];  // keep bound values alive until oci_execute
    foreach ($params as $i => $val) {
        $bound[$i] = $val;
        oci_bind_by_name($stmt, ':p' . ($i + 1), $bound[$i], -1);
    }
    // FIX: check oci_execute result — if it fails and we silently return $stmt,
    // any subsequent oci_fetch_assoc() call triggers ORA-24374 (define not done
    // before fetch) because the statement was never actually executed.
    $ok = oci_execute($stmt);
    if (!$ok) {
        $e = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException('Query failed: ' . ($e['message'] ?? 'unknown error'));
    }
    return $stmt;
}

/**
 * OCI8 returns column names in UPPERCASE by default.
 * Normalise all keys to lowercase for consistency.
 */
function tm_lowercase_keys(array $row): array {
    return array_change_key_case($row, CASE_LOWER);
}

function tm_fetch_one($stmt): ?array {
    $row = oci_fetch_assoc($stmt);
    return $row ? tm_lowercase_keys($row) : null;
}

function tm_fetch_all($stmt): array {
    $rows = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = tm_lowercase_keys($row);
    }
    return $rows;
}

function tm_scalar($stmt) {
    $row = oci_fetch_row($stmt);
    return $row ? $row[0] : null;
}

/**
 * JSON API helpers.
 * Defined here so both TM_TaskActions.php and TM_UserActions.php
 * can use them without redeclaring.
 */
function tm_api_ok(mixed $data = null): void {
    header('Content-Type: application/json');
    $payload = ['ok' => true];
    if ($data !== null) $payload['data'] = $data;
    echo json_encode($payload);
    exit;
}

function tm_api_err(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/**
 * Insert one row into TM_AuditLog via inline SQL.
 * Kept as the fallback for callers that haven't been migrated to the
 * TM_WriteAuditLog stored procedure yet, and for contexts where the
 * TM_WriteAuditLog procedure itself is not available (e.g. fresh install
 * before TM_StoredProcedures.sql has been run).
 *
 * Errors are swallowed so a logging failure never blocks the real action.
 */
function tm_audit(int $userId, string $action, string $entityType,
                  int $entityId, string $entityName,
                  string $oldValue = '', string $newValue = ''): void {
    try {
        tm_exec(
            "INSERT INTO TM_AuditLog
                (user_id, action, entity_type, entity_id, entity_name, old_value, new_value)
             VALUES (:p1, :p2, :p3, :p4, :p5, :p6, :p7)",
            [$userId, $action, $entityType, $entityId,
             substr($entityName, 0, 255),
             substr($oldValue,   0, 500),
             substr($newValue,   0, 500)]
        );
    } catch (Throwable $e) {
        // Never let audit failure surface to the user
    }
}

// =============================================
// Feature 9 — PL/SQL Stored Procedure helpers
// IM101 Week 12: calling named Oracle procedures from PHP.
// PHP uses anonymous PL/SQL blocks (BEGIN … END) with OUT bind variables.
// =============================================

/**
 * Call TM_CreateTask stored procedure.
 *
 * Atomically inserts a new task and writes a 'create' audit entry inside
 * Oracle — PHP never writes to TM_Tasks or TM_AuditLog directly.
 *
 * @return int  The new task_id assigned by Oracle.
 */
function tm_sp_create_task(
    int    $userId,
    string $taskName,
    string $startDate,
    string $dueDate,
    string $category,
    string $customCategory,
    string $priority,
    string $color,
    string $notes,
    string $recurrence,
    int    $orgId = 1,    // Feature 6: tenant scope — defaults to 1 (Default Org)
    int    $isOrgTask = 0 // Feature: org-wide task flag
): int {
    global $conn;

    $plsql = "BEGIN
                  TM_CreateTask(
                      :p_user_id, :p_task_name, :p_start_date, :p_due_date,
                      :p_category, :p_custom_category, :p_priority, :p_color,
                      :p_notes, :p_recurrence, :p_new_task_id, :p_org_id,
                      :p_is_org_task
                  );
              END;";

    $stmt = oci_parse($conn, $plsql);

    // IN parameters
    oci_bind_by_name($stmt, ':p_user_id',         $userId,         -1);
    oci_bind_by_name($stmt, ':p_task_name',        $taskName,       255);
    oci_bind_by_name($stmt, ':p_start_date',       $startDate,      10);
    oci_bind_by_name($stmt, ':p_due_date',         $dueDate,        10);
    oci_bind_by_name($stmt, ':p_category',         $category,       50);
    oci_bind_by_name($stmt, ':p_custom_category',  $customCategory, 100);
    oci_bind_by_name($stmt, ':p_priority',         $priority,       20);
    oci_bind_by_name($stmt, ':p_color',            $color,          20);
    oci_bind_by_name($stmt, ':p_notes',            $notes,          -1);
    oci_bind_by_name($stmt, ':p_recurrence',       $recurrence,     20);
    oci_bind_by_name($stmt, ':p_org_id',           $orgId,          10); // Feature 6
    oci_bind_by_name($stmt, ':p_is_org_task',      $isOrgTask,       1);

    // OUT parameter — Oracle writes the new task_id here
    $newTaskId = 0;
    oci_bind_by_name($stmt, ':p_new_task_id', $newTaskId, 10);

    // Must use OCI_COMMIT_ON_SUCCESS (the default), NOT OCI_NO_AUTO_COMMIT.
    // Passing OCI_NO_AUTO_COMMIT puts the PHP session into manual-commit mode,
    // which suppresses the COMMIT inside the PL/SQL procedure — the task INSERT
    // goes through but the TM_AuditLog INSERT is never committed, so the
    // Activity Feed shows nothing. OCI_COMMIT_ON_SUCCESS lets the procedure's
    // internal COMMIT persist both writes atomically.
    oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
    oci_free_statement($stmt);

    return (int)$newTaskId;
}

/**
 * Call TM_UpdateTaskStatus stored procedure.
 *
 * Updates a task's status and writes a 'status_change' audit entry
 * atomically inside Oracle.
 *
 * Throws a RuntimeException (wrapping ORA-20001) if the task is not
 * found or does not belong to $userId.
 */
function tm_sp_update_status(int $taskId, int $userId, string $newStatus): void {
    global $conn;

    $plsql = "BEGIN
                  TM_UpdateTaskStatus(:p_task_id, :p_user_id, :p_new_status);
              END;";

    $stmt = oci_parse($conn, $plsql);
    oci_bind_by_name($stmt, ':p_task_id',    $taskId,    10);
    oci_bind_by_name($stmt, ':p_user_id',    $userId,    10);
    oci_bind_by_name($stmt, ':p_new_status', $newStatus, 20);

    $ok = @oci_execute($stmt, OCI_NO_AUTO_COMMIT);
    if (!$ok) {
        $err = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException($err['message'] ?? 'TM_UpdateTaskStatus failed');
    }
    oci_free_statement($stmt);
}

/**
 * Call TM_WriteAuditLog stored procedure.
 *
 * Drop-in replacement for tm_audit() that delegates to an Oracle stored
 * procedure instead of issuing inline INSERT SQL from PHP.
 * Errors are swallowed identically to tm_audit().
 */
function tm_audit_sp(int $userId, string $action, string $entityType,
                     int $entityId, string $entityName,
                     string $oldValue = '', string $newValue = ''): void {
    global $conn;
    try {
        $plsql = "BEGIN
                      TM_WriteAuditLog(
                          :p_user_id, :p_action, :p_entity_type, :p_entity_id,
                          :p_entity_name, :p_old_value, :p_new_value
                      );
                  END;";

        $stmt = oci_parse($conn, $plsql);
        // oci_bind_by_name requires a variable reference, not an expression.
        // Assign substr() results to named variables first, then bind those.
        $bindName   = substr($entityName, 0, 255);
        $bindOld    = substr($oldValue,   0, 500);
        $bindNew    = substr($newValue,   0, 500);

        oci_bind_by_name($stmt, ':p_user_id',     $userId,     10);
        oci_bind_by_name($stmt, ':p_action',      $action,     20);
        oci_bind_by_name($stmt, ':p_entity_type', $entityType, 20);
        oci_bind_by_name($stmt, ':p_entity_id',   $entityId,   10);
        oci_bind_by_name($stmt, ':p_entity_name', $bindName,  255);
        oci_bind_by_name($stmt, ':p_old_value',   $bindOld,   500);
        oci_bind_by_name($stmt, ':p_new_value',   $bindNew,   500);

        oci_execute($stmt, OCI_NO_AUTO_COMMIT); // procedure handles its own INSERT
        oci_commit($conn);
        oci_free_statement($stmt);
    } catch (Throwable $e) {
        // Audit must never block the real action
    }
}