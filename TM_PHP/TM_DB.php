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