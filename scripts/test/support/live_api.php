<?php
/** Test-only Discuz boundary. Every game query executes against real MariaDB. */
if (!preg_match('/^tsdm_test_[a-z0-9_]+$/D', getenv('TSDM_TEST_DB') ?: '')) {
    exit('An isolated TSDM_TEST_DB is required');
}
define('IN_DISCUZ', true);
define('DISCUZ_ROOT', __DIR__ . '/../../../');
$endpoint = getenv('TSDM_TEST_ENDPOINT');
if (!in_array($endpoint, ['battle', 'shop', 'user', 'pokemon', 'config', 'topics', 'avatar'], true)) exit('Invalid test endpoint');
if (getenv('TSDM_TEST_ROUTE_PLUGIN') !== '1') {
    define('API_ROUTED', true);
    define('API_ENDPOINT', $endpoint);
}
function formhash() { return 'test-formhash'; }

class DB
{
    public static $connection;
    private static $barrier = false;
    public static function table($name) { return $name; }
    public static function query($sql, $silent = false)
    {
        if (getenv('TSDM_TEST_PAUSE_NEWS_INSERT') === '1'
            && preg_match('/^INSERT(?: IGNORE)? INTO pm_config/', $sql) && str_contains($sql, 'news_announcements')) {
            $ready = getenv('TSDM_TEST_READY');
            file_put_contents($ready, (string)self::$connection->thread_id);
            $deadline = microtime(true) + 15;
            while (!is_file($ready . '.resume')) {
                if (microtime(true) > $deadline) throw new RuntimeException('Announcement barrier timed out');
                usleep(10000);
                clearstatcache();
            }
        }
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
        try {
            return self::$connection->query($sql);
        } catch (mysqli_sql_exception $error) {
            if ($silent === true || $silent === 'SILENT') return false;
            throw $error;
        }
    }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
    public static function fetch_all($sql)
    {
        $rows = self::query($sql)->fetch_all(MYSQLI_ASSOC);
        if (getenv('TSDM_TEST_PAUSE_PET_LIST') === '1' && str_contains($sql, 'ORDER BY site ASC, id ASC')) {
            $ready = getenv('TSDM_TEST_READY');
            file_put_contents($ready, 'snapshot');
            $deadline = microtime(true) + 15;
            while (!is_file($ready . '.resume')) {
                if (microtime(true) > $deadline) throw new RuntimeException('Pet snapshot barrier timed out');
                usleep(10000);
                clearstatcache();
            }
        }
        return $rows;
    }
    public static function result_first($sql) { $row = self::query($sql)->fetch_row(); return $row ? $row[0] : null; }
    public static function result($result, $row = 0) { $result->data_seek($row); return $result->fetch_row()[0]; }
    public static function insert_id() { return self::$connection->insert_id; }
    public static function affected_rows() { return self::$connection->affected_rows; }
    public static function errno() { return self::$connection->errno; }
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
if (getenv('TSDM_TEST_ROUTE_PLUGIN') === '1') {
    function loadcache($key) {}
    function lang($kind, $key) { return $key; }
    $_G['cache']['plugin']['pokemon'] = ['is_open' => getenv('TSDM_TEST_LEGACY_OPEN'),
        'poke_smgly' => getenv('TSDM_TEST_STAFF') === '1' ? $_G['username'] : ''];
    $_GET['endpoint'] = $endpoint;
    require __DIR__ . '/../../../plugin/pokemon.inc.php';
} else {
    require __DIR__ . '/../../../plugin/api/' . $endpoint . '.php';
}
