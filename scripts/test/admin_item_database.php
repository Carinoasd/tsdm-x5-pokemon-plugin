<?php
/** Independent real admin requests against an owned, disposable MariaDB database. */
error_reporting(E_ALL);
set_error_handler(function ($s, $m, $f, $l) { throw new ErrorException($m, 0, $s, $f, $l); });
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function item_db_connect($database = '')
{
    $db = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
        getenv('TSDM_DB_PASSWORD') ?: '', $database, (int)(getenv('TSDM_DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4');
    $db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
    $db->query('SET SESSION innodb_lock_wait_timeout = 10');
    return $db;
}
class DB
{
    public static $connection, $request, $ready = false;
    public static function query($sql)
    {
        if (!self::$ready && strpos($sql, 'pm_usersdata') !== false && stripos($sql, 'FOR UPDATE') !== false) {
            self::$ready = true; file_put_contents(self::$request['ready'], 'ready');
        }
        $result = self::$connection->query($sql);
        // Throw after the actual write so the test verifies rollback, not just rejection.
        if (!empty(self::$request['fail']) && stripos($sql, self::$request['fail']) === 0) throw new RuntimeException('Injected write failure');
        return $result;
    }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
    public static function fetch_all($sql) { return self::query($sql)->fetch_all(MYSQLI_ASSOC); }
    public static function insert_id() { return self::$connection->insert_id; }
    public static function affected_rows() { return self::$connection->affected_rows; }
}
if (($argv[1] ?? '') === '--worker') {
    $request = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/^tsdm_test_admin_item_[0-9a-f]{16}$/D', $request['database'])) throw new RuntimeException('Invalid test database');
    DB::$connection = item_db_connect($request['database']);
    $marker = DB::$connection->query('SELECT token FROM test_owner')->fetch_row()[0];
    if (!hash_equals($marker, $request['token'])) throw new RuntimeException('Test database ownership mismatch');
    DB::$request = $request;
    define('IN_DISCUZ', true);
    $_POST = $request['params'];
    try { require __DIR__ . '/../../plugin/admin/dispatch.php'; }
    catch (Throwable $error) { echo json_encode(['success'=>false,'reason'=>$error->getMessage()]); }
    exit;
}

$db = item_db_connect();
$database = 'tsdm_test_admin_item_' . bin2hex(random_bytes(8));
$token = bin2hex(random_bytes(16));
$owned = false; $workers = []; $passed = 0;
function item_db_check($ok, $label) { if (!$ok) throw new RuntimeException($label); $GLOBALS['passed']++; echo "PASS $label\n"; }
function item_db_scalar($sql) { return $GLOBALS['db']->query($sql)->fetch_row()[0]; }
function item_db_reset($quantity = 10, $equipped = false)
{
    $db = $GLOBALS['db'];
    $db->query('DELETE FROM pm_myitem');
    $db->query("INSERT INTO pm_myitem (id,uid,itemid,nums) VALUES (20,7,'1',$quantity)");
    $equipment = $equipped ? 20 : 0;
    $db->query("UPDATE pm_mypm SET equipmentid1=$equipment,equipmentid2=0,hp=162 WHERE id=11");
}
function item_db_begin($action, $data, $fail = '')
{
    $base = tempnam(sys_get_temp_dir(), 'tsdm-admin-item-');
    $request = ['database'=>$GLOBALS['database'],'token'=>$GLOBALS['token'],'ready'=>$base.'.ready','fail'=>$fail,
        'params'=>['action'=>$action,'id'=>$data['id'] ?? 0,'data'=>json_encode($data)]];
    $cmd = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) array_push($cmd, '-c', $ini);
    array_push($cmd, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', __FILE__, '--worker', base64_encode(json_encode($request)));
    $process = proc_open($cmd,[0=>['pipe','r'],1=>['file',$base,'w'],2=>['file',$base.'.err','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start admin item request');
    fclose($pipes[0]);
    $worker = ['process'=>$process,'base'=>$base,'start'=>microtime(true)];
    $GLOBALS['workers'][] = $worker;
    return $worker;
}
function item_db_finish($worker)
{
    while (proc_get_status($worker['process'])['running']) {
        if (microtime(true) - $worker['start'] > 15) { proc_terminate($worker['process']); throw new RuntimeException('Admin item request timed out'); }
        usleep(10000);
    }
    proc_close($worker['process']);
    $out = file_get_contents($worker['base']); $err = file_get_contents($worker['base'].'.err');
    if ($err !== '') throw new RuntimeException($err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function item_db_request($action, $data, $fail = '') { return item_db_finish(item_db_begin($action,$data,$fail)); }
function item_db_race($action, $inputs, $before_unlock = null)
{
    $db = $GLOBALS['db']; $pending = [];
    $db->begin_transaction(); $db->query('SELECT uid FROM pm_usersdata WHERE uid=7 FOR UPDATE');
    try {
        foreach ($inputs as $data) $pending[] = item_db_begin($action,$data);
        foreach ($pending as $worker) {
            while (!is_file($worker['base'].'.ready')) {
                if (microtime(true) - $worker['start'] > 8 || !proc_get_status($worker['process'])['running']) {
                    throw new RuntimeException('Admin item worker did not reach the account lock');
                }
                clearstatcache(); usleep(10000);
            }
        }
        if ($before_unlock) $before_unlock();
        $db->commit();
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    return array_map('item_db_finish',$pending);
}
try {
    $db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $owned = true;
    $db->select_db($database);
    $db->query('CREATE TABLE test_owner (token VARCHAR(32) NOT NULL)');
    $db->query("INSERT INTO test_owner VALUES ('$token')");
    $schema = file_get_contents(__DIR__ . '/../../docker/init.d/02-pokemon-schema.sql');
    if (preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+DATABASE\s)/mi',$schema)) throw new RuntimeException('Schema changes database scope');
    $db->multi_query($schema);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    $db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
    $db->query('INSERT INTO pm_usersdata (uid) VALUES (7)');
    $db->query("INSERT INTO pm_itemdata (id,name,type,equipment,effects) VALUES (1,'Armor',5,'{\"hp\":100}','{}'),(2,'Other armor',5,'{}','{}')");
    $db->query('INSERT INTO pm_mypm (id,uid,species_id,site,level,hp,state) VALUES (11,7,1,1,20,162,1)');
    $grant = ['owner'=>7,'type_id'=>1,'count'=>5];
    $edit = ['id'=>20,'owner'=>7,'type_id'=>1,'count'=>1];

    item_db_reset();
    $r = item_db_race('insert::item_info',[$grant,array_merge($grant,['count'=>7])]);
    item_db_check($r[0]['success'] && $r[1]['success'] && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 22,
        'Concurrent grants add both quantities to the existing stack');
    $new = array_merge($grant,['type_id'=>2]);
    $r = item_db_race('insert::item_info',[$new,$new]);
    item_db_check($r[0]['success'] && $r[1]['success'] && (int)item_db_scalar("SELECT COUNT(*) FROM pm_myitem WHERE uid=7 AND itemid='2'") === 1
        && (int)item_db_scalar("SELECT nums FROM pm_myitem WHERE uid=7 AND itemid='2'") === 10, 'Concurrent first grants create one stack');
    item_db_reset(32760);
    $r = item_db_race('insert::item_info',[$grant,$grant]);
    item_db_check(count(array_filter($r,fn($v)=>$v['success'])) === 1 && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 32765,
        'Concurrent grants cannot overflow signed SMALLINT quantity');
    item_db_reset();
    $r = item_db_race('insert::item_info',[$grant],function () { $GLOBALS['db']->query('UPDATE pm_myitem SET nums=nums+2 WHERE id=20'); });
    item_db_check($r[0]['success'] && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 17,
        'Grant preserves a quantity change committed while waiting');
    foreach (['delete::item_info','set::item_info'] as $action) {
        item_db_reset();
        $r = item_db_race($action,[$action === 'delete::item_info' ? ['id'=>20] : array_merge($edit,['type_id'=>2])],
            function () { $GLOBALS['db']->query('UPDATE pm_mypm SET equipmentid1=20 WHERE id=11'); });
        item_db_check(!$r[0]['success'] && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 10
            && (int)item_db_scalar('SELECT itemid FROM pm_myitem WHERE id=20') === 1
            && (int)item_db_scalar('SELECT equipmentid1 FROM pm_mypm WHERE id=11') === 20,
            $action . ' rechecks equipment attached before its account lock');
    }
    item_db_reset(10,true);
    $r = item_db_request('delete::item_info',['id'=>20]);
    item_db_check(!$r['success'] && (int)item_db_scalar('SELECT COUNT(*) FROM pm_myitem WHERE id=20') === 1
        && (int)item_db_scalar('SELECT hp FROM pm_mypm WHERE id=11') === 162, 'Equipped delete preserves inventory and HP');
    $r = item_db_request('set::item_info',$edit);
    item_db_check($r['success'] && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 1, 'Valid equipped quantity edit succeeds');
    $db->query('UPDATE pm_mypm SET equipmentid2=20 WHERE id=11');
    $db->query('UPDATE pm_myitem SET nums=10 WHERE id=20');
    $r = item_db_request('set::item_info',$edit);
    item_db_check(!$r['success'] && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 10, 'Existing slot references constrain quantity edits');
    item_db_reset();
    $r = item_db_request('set::item_info',array_merge($edit,['type_id'=>999]));
    item_db_check(!$r['success'] && (int)item_db_scalar('SELECT itemid FROM pm_myitem WHERE id=20') === 1
        && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 10, 'Missing catalog type cannot partially edit inventory');
    foreach ([['insert::item_info',$grant,'UPDATE pm_myitem'],['set::item_info',array_merge($edit,['type_id'=>2]),'UPDATE pm_myitem'],
        ['delete::item_info',['id'=>20],'DELETE FROM pm_myitem']] as [$action,$data,$failure]) {
        $r = item_db_request($action,$data,$failure);
        item_db_check(!$r['success'] && ($r['reason'] ?? '') === 'Injected write failure'
            && (int)item_db_scalar('SELECT itemid FROM pm_myitem WHERE id=20') === 1
            && (int)item_db_scalar('SELECT nums FROM pm_myitem WHERE id=20') === 10, $action . ' rolls back an actual write failure');
    }
    $r = item_db_request('delete::item_info',['id'=>20]);
    item_db_check($r['success'] && (int)item_db_scalar('SELECT COUNT(*) FROM pm_myitem WHERE id=20') === 0, 'Unoccupied inventory deletes normally');
    echo "Admin item database: $passed passed.\n";
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) { proc_terminate($worker['process']); proc_close($worker['process']); }
        foreach (['','.err','.ready'] as $suffix) if (is_file($worker['base'].$suffix)) unlink($worker['base'].$suffix);
    }
    if ($owned && preg_match('/^tsdm_test_admin_item_[0-9a-f]{16}$/D',$database)) {
        $db->rollback(); $db->query("DROP DATABASE `$database`");
    }
    $db->close();
}
