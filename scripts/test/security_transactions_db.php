<?php
// Optional integration test; uses only tables created for this invocation.
// PM_TEST_DB_HOST, PM_TEST_DB_PORT, PM_TEST_DB_USER, PM_TEST_DB_PASSWORD,
// PM_TEST_DB_NAME identify an isolated MariaDB/MySQL database.
if (!getenv('PM_TEST_DB_HOST')) {
    echo "SKIP Transaction integration requires PM_TEST_DB_HOST\n";
    exit;
}
define('IN_DISCUZ', true);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
class DB {
    static $connection;
    static function table($name) { return $GLOBALS['fixture'] . '_' . $name; }
    static function query($sql) { return self::$connection->query($sql); }
    static function result_first($sql) { return self::query($sql)->fetch_row()[0] ?? null; }
}
function pm_sql($sql, ...$args) {
    $i = 0;
    return preg_replace_callback('/%([ds])/', function ($m) use ($args, &$i) {
        $value = $args[$i++];
        return $m[1] === 'd' ? intval($value) : "'" . DB::$connection->real_escape_string($value) . "'";
    }, $sql);
}
function api_error($message, $code) { throw new RuntimeException($message, $code); }
function connect_db() {
    DB::$connection = new mysqli(getenv('PM_TEST_DB_HOST'), getenv('PM_TEST_DB_USER') ?: 'root',
        getenv('PM_TEST_DB_PASSWORD') ?: '', getenv('PM_TEST_DB_NAME') ?: 'pokemon_security_test',
        intval(getenv('PM_TEST_DB_PORT') ?: 3306));
}
connect_db();
require dirname(__DIR__, 2) . '/plugin/api/transactions.php';
if (isset($argv[1])) {
    $fixture = $argv[1];
    if (!preg_match('/^pm_security_[a-f0-9]{16}$/', $fixture)) exit(2);
    $operation = $argv[2];
    try {
        pm_api_begin_transaction(7);
        $row = DB::query("SELECT * FROM $fixture WHERE uid=7")->fetch_assoc();
        // Ensure both processes overlap; the second must wait before reading.
        usleep(200000);
        if ($operation === 'shop') {
            if ($row['money'] < 100) api_error('Insufficient funds', 400);
            DB::query("UPDATE $fixture SET money=money-100, items=items+1 WHERE uid=7 AND money>=100");
        } else {
            if (!$row['npcid']) api_error('Encounter already settled', 400);
            if ($operation === 'capture') {
                if (!$row['balls']) api_error('No ball', 400);
                DB::query("UPDATE $fixture SET balls=balls-1, captures=captures+1, npcid=0 WHERE uid=7 AND balls>0");
            } else {
                DB::query("UPDATE $fixture SET rewards=rewards+1, npcid=0 WHERE uid=7");
            }
        }
        pm_api_commit_transaction();
        echo 'saved';
    } catch (RuntimeException $e) {
        pm_api_rollback_transaction();
        echo 'rejected';
    }
    exit;
}
function assert_db($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$fixture = 'pm_security_' . bin2hex(random_bytes(8));
DB::query("CREATE TABLE $fixture (uid INT PRIMARY KEY, money INT, items INT, npcid INT,
    balls INT, captures INT, rewards INT) ENGINE=InnoDB");
try {
    foreach (['shop','capture','reward'] as $operation) {
        DB::query("DELETE FROM $fixture");
        DB::query("INSERT INTO $fixture VALUES (7,100,0,25,1,0,0)");
        $workers = [];
        for ($i=0; $i<2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY,__FILE__,$fixture,$operation], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
            $workers[] = [$process,$pipes];
        }
        $answers = [];
        foreach ($workers as [$process,$pipes]) {
            $answers[] = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
            assert_db(proc_close($process) === 0, "Worker failed: $err");
        }
        sort($answers);
        assert_db($answers === ['rejected','saved'], "Request was settled twice: $operation");
        $row = DB::query("SELECT * FROM $fixture WHERE uid=7")->fetch_assoc();
        if ($operation === 'shop') assert_db($row['money']==0 && $row['items']==1, 'Overspent or duplicate item');
        if ($operation === 'capture') assert_db($row['balls']==0 && $row['captures']==1, 'Duplicate capture');
        if ($operation === 'reward') assert_db($row['rewards']==1, 'Duplicate reward');
        echo "PASS Concurrent $operation settles once\n";
    }
    pm_api_begin_transaction(7);
    DB::query("UPDATE $fixture SET money=999, items=999 WHERE uid=7");
    pm_api_rollback_transaction();
    $row = DB::query("SELECT * FROM $fixture WHERE uid=7")->fetch_assoc();
    assert_db($row['money']==100 && $row['items']==0, 'Rollback left partial writes');
    echo "PASS Multi-write rollback\n";
} finally {
    pm_api_rollback_transaction();
    DB::query("DROP TABLE $fixture");
}
