<?php
// Only invoked by x2_export_regressions.py with its generated, trusted fixtures.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$name = 'tsdm_test_x2_export_' . bin2hex(random_bytes(8));
$owned = false;
function import_fixture($db, $path) {
    $db->multi_query(file_get_contents($path));
    do { if ($rows = $db->store_result()) $rows->free(); }
    while ($db->more_results() && $db->next_result());
}
function config_values($db) {
    $values = [];
    foreach ($db->query('SELECT `key`, `value` FROM pm_config ORDER BY `key`') as $row) {
        $values[$row['key']] = $row['value'];
    }
    ksort($values);
    return $values;
}
try {
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
    $owned = true;
    $db->select_db($name);
    $db->set_charset('utf8mb4');
    $db->query("SET SESSION sql_mode = ''");
    $db->query('CREATE TABLE pm_config (`key` VARCHAR(255) PRIMARY KEY, `value` LONGTEXT NULL, data_type VARCHAR(20))');
    $expected = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    ksort($expected);
    import_fixture($db, $argv[1]);
    $original = config_values($db);
    if ($original !== $expected) throw new RuntimeException('Original SQL differs from independent expected values');
    $db->query('TRUNCATE pm_config');
    import_fixture($db, $argv[2]);
    $converted = config_values($db);
    if ($converted !== $expected) throw new RuntimeException('Exported SQL changes original values');
    echo count($expected) . " original and converted values match\n";
} finally {
    try {
        if ($owned && preg_match('/^tsdm_test_x2_export_[a-f0-9]{16}$/D', $name)) {
            $db->query('DROP DATABASE `' . $name . '`');
        }
    } finally {
        $db->close();
    }
}
