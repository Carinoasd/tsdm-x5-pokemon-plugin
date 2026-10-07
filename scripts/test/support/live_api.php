<?php
/** Test-only Discuz boundary. Every game query executes against real MariaDB. */
if (!preg_match('/^tsdm_test_[a-z0-9_]+$/D', getenv('TSDM_TEST_DB') ?: '')) {
    exit('An isolated TSDM_TEST_DB is required');
}
define('IN_DISCUZ', true);
define('DISCUZ_ROOT', __DIR__ . '/../../../');
define('API_ROUTED', true);
$endpoint = getenv('TSDM_TEST_ENDPOINT');
if (!in_array($endpoint, ['battle', 'shop', 'user', 'pokemon', 'config', 'topics', 'avatar'], true)) exit('Invalid test endpoint');
define('API_ENDPOINT', $endpoint);
function formhash() { return 'test-formhash'; }

class DB
{
    public static $connection;
    private static $barrier = false;
    public static function table($name) { return $name; }
    public static function query($sql, $silent = false)
    {
        // Signal immediately before the account lock, allowing the parent to
        // prove that both independent database connections reached the race.
        $at_lock = stripos($sql, 'FOR UPDATE') !== false
            && (strpos($sql, 'pm_usersdata') !== false || strpos($sql, 'common_member') !== false);
        $at_legacy_insert = getenv('TSDM_TEST_LEGACY_PROFILE') === '1' && strpos($sql, 'INSERT INTO pm_usersdata') !== false;
        if (!self::$barrier && ($at_lock || $at_legacy_insert)) {
            self::$barrier = true;
            if ($path = getenv('TSDM_TEST_READY')) file_put_contents($path, 'ready');
        }
        if (($fail = getenv('TSDM_TEST_FAIL_SQL')) && strpos($sql, $fail) !== false) {
            if (getenv('TSDM_TEST_FAIL_TYPE') === 'TypeError') {
                throw new TypeError('Injected database failure with private implementation details');
            }
            throw new RuntimeException('Injected database failure');
        }
        return self::$connection->query($sql);
    }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
    public static function fetch_all($sql) { return self::query($sql)->fetch_all(MYSQLI_ASSOC); }
    public static function result_first($sql) { $row = self::query($sql)->fetch_row(); return $row ? $row[0] : null; }
    public static function result($result, $row = 0) { $result->data_seek($row); return $result->fetch_row()[0]; }
    public static function insert_id() { return self::$connection->insert_id; }
    public static function affected_rows() { return self::$connection->affected_rows; }
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
DB::$connection = new mysqli(
    getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', getenv('TSDM_TEST_DB'), (int)(getenv('TSDM_DB_PORT') ?: 3306)
);
DB::$connection->set_charset('utf8mb4');
DB::$connection->query(getenv('TSDM_TEST_STRICT') === '1'
    ? "SET SESSION sql_mode = 'STRICT_TRANS_TABLES'" : "SET SESSION sql_mode = ''");
DB::$connection->query('SET SESSION innodb_lock_wait_timeout = 15');
$uid = (int)(getenv('TSDM_TEST_UID') ?: 7);
$_G = ['uid' => $uid, 'username' => 'fixture-player-' . $uid, 'cache' => ['plugin' => ['pokemon' => []]]];
require __DIR__ . '/../../../plugin/api/' . $endpoint . '.php';
