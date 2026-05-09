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
    $bound = [];  // keep bound values alive until oci_execute
    foreach ($params as $i => $val) {
        $bound[$i] = $val;
        oci_bind_by_name($stmt, ':p' . ($i + 1), $bound[$i], -1);
    }
    oci_execute($stmt);
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
 * Insert one row into TM_AuditLog.
 * Defined here (in TM_DB.php) so every action handler can use it
 * without redeclaring it — which would cause a fatal error when two
 * handlers are included in the same request.
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