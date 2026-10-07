<?php
/** Real get/set config handlers with deterministic interleaving on isolated MariaDB. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);
class DB {
    public static $connection;
    public static $barrier;
    private static $error = 0;
    public static function table($name) { return $name; }
    public static function errno() { return self::$error; }
    public static function query($sql, $option = []) {
        if (self::$barrier && preg_match("/^INSERT INTO pm_config .* VALUES \\('medical_price',/s", $sql)) {
            $base = self::$barrier; self::$barrier = null;
            file_put_contents($base . '.ready', 'ready');
            $until = microtime(true) + 10;
            while (!is_file($base . '.resume')) {
                if (microtime(true) > $until) throw new RuntimeException('Configuration barrier timed out');
                usleep(10000); clearstatcache();
            }
        }
        self::$error = 0;
        try { return self::$connection->query($sql); }
        catch (mysqli_sql_exception $error) {
            self::$error = $error->getCode();
            if ($option === 'SILENT') return false;
            throw $error;
        }
    }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
    public static function fetch_all($sql) { return self::query($sql)->fetch_all(MYSQLI_ASSOC); }
}
require __DIR__ . '/../../plugin/admin/routes.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = DB::$connection = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
if (($argv[1] ?? '') === '--worker') {
    [,,$database,$action,$base,$mode] = $argv;
    if (!preg_match('/^tsdm_test_configrace_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Invalid isolated worker schema');
    $db->select_db($database);
    $db->query("SET SESSION sql_mode = '" . ($mode === 'strict' ? 'STRICT_ALL_TABLES' : '') . "'");
    DB::$barrier = $base;
    try {
        $result = $action === 'read' ? get_global_config() : set_global_config(['medical_price'=>99]);
        echo json_encode(['success'=>true,'price'=>$result[0]['medical_price']]);
    } catch (Throwable $error) { echo json_encode(['success'=>false,'error'=>$error->getMessage()]); }
    $db->close(); exit;
}
function config_worker($database, $action, $mode) {
    $base = sys_get_temp_dir() . '/tsdm_configrace_' . bin2hex(random_bytes(8));
    $command = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
    array_push($command, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', __FILE__, '--worker', $database, $action, $base, $mode);
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start configuration worker');
    fclose($pipes[0]);
    return ['base'=>$base,'process'=>$process,'out'=>$pipes[1],'err'=>$pipes[2]];
}
function config_ready($worker) {
    $until = microtime(true) + 10;
    while (!is_file($worker['base'] . '.ready')) {
        if (microtime(true) > $until || !proc_get_status($worker['process'])['running']) throw new RuntimeException('Worker did not reach configuration insert');
        usleep(10000); clearstatcache();
    }
}
function config_result($worker) {
    $output = stream_get_contents($worker['out']); $error = stream_get_contents($worker['err']);
    if ($error !== '') throw new RuntimeException($error);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
$checks = $failures = 0;
function check_config_race($condition, $label) {
    $GLOBALS['checks']++;
    if (!$condition) $GLOBALS['failures']++;
    echo ($condition ? 'PASS ' : 'FAIL '), $label, "\n";
}
$database = 'tsdm_test_configrace_' . bin2hex(random_bytes(8));
$db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$workers = [];
try {
    $db->select_db($database);
    $db->multi_query(file_get_contents(__DIR__ . '/../../docker/init.d/02-pokemon-schema.sql'));
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    foreach (['non-strict', 'strict'] as $mode) {
        $db->query("SET SESSION sql_mode = '" . ($mode === 'strict' ? 'STRICT_ALL_TABLES' : '') . "'");
        $db->query('DELETE FROM pm_config');
        $db->query("INSERT INTO pm_config VALUES ('news_announcements','[]','string')");
        $defaults = get_global_config()[0];
        check_config_race($defaults['is_open'] === '1' && $defaults['medical_price'] === '10', "$mode existing default wire values remain compatible");
        foreach (['two_readers', 'reader_after_admin', 'admin_after_reader'] as $scenario) {
            $db->query("DELETE FROM pm_config WHERE `key`='medical_price'");
            $batch = [];
            $first = config_worker($database, $scenario === 'admin_after_reader' ? 'write' : 'read', $mode);
            $workers[] = $first; $batch[] = $first; config_ready($first);
            if ($scenario === 'two_readers') {
                $second = config_worker($database, 'read', $mode);
                $workers[] = $second; $batch[] = $second; config_ready($second);
            } elseif ($scenario === 'reader_after_admin') {
                set_global_config(['medical_price'=>99]);
            } else {
                get_global_config();
            }
            foreach ($batch as $child) file_put_contents($child['base'] . '.resume', 'resume');
            $results = array_map('config_result', $batch);
            $expected = $scenario === 'two_readers' ? 10 : 99;
            check_config_race(count(array_filter($results, function ($r) { return $r['success']; })) === count($results), "$mode $scenario all callers succeed");
            check_config_race(count(array_filter($results, function ($r) use ($expected) { return $r['success'] && (int)$r['price'] === $expected; })) === count($results),
                "$mode $scenario returns the stored default or explicit value");
            $row = DB::fetch_first("SELECT value,data_type FROM pm_config WHERE `key`='medical_price'");
            check_config_race((int)$row['value'] === $expected && $row['data_type'] === ($scenario === 'two_readers' ? 'string' : 'integer'),
                "$mode $scenario preserves the correct value and type");
        }
    }
    try {
        $unknown = "owner's unknown option";
        $saved = set_global_config([$unknown=>'ignored']);
        check_config_race(!array_key_exists($unknown, $saved[0]) && !DB::fetch_first(pm_sql('SELECT * FROM pm_config WHERE `key` = %s', $unknown)),
            'An unknown quoted key remains safely ignored');
    } catch (Throwable $error) { check_config_race(false, 'Unknown quoted key: ' . $error->getMessage()); }
    foreach (['string'=>"Trainer's \\path", 'integer'=>99, 'boolean'=>false] as $type=>$value) {
        try {
            $key = "owner's custom " . $type;
            DB::query(pm_sql('INSERT INTO pm_config (`key`, `value`, `data_type`) VALUES (%s, %s, %s)', $key, '1', $type));
            $saved = set_global_config([$key=>$value]);
            check_config_race($saved[0][$key] === $value, "Existing quoted custom $type keys remain editable");
        } catch (Throwable $error) { check_config_race(false, "Custom quoted $type key: " . $error->getMessage()); }
    }
    echo "Configuration database races: $checks checks, $failures failures.\n";
} finally {
    foreach ($workers as $child) {
        if (proc_get_status($child['process'])['running']) proc_terminate($child['process']);
        fclose($child['out']); fclose($child['err']); proc_close($child['process']);
        foreach (['.ready','.resume'] as $suffix) if (is_file($child['base'] . $suffix)) unlink($child['base'] . $suffix);
    }
    if (!preg_match('/^tsdm_test_configrace_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Unsafe configuration cleanup');
    $db->query("DROP DATABASE `$database`"); $db->close();
}
exit($failures ? 1 : 0);
