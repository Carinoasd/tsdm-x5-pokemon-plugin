<?php
/** Real admin dispatcher pet writes, each run confined to its own disposable schema. */
error_reporting(E_ALL);
set_error_handler(function ($s, $m, $f, $l) { throw new ErrorException($m, 0, $s, $f, $l); });
define('IN_DISCUZ', true);
class DB {
    public static $connection, $barrier, $fault;
    public static function table($name) { return $name; }
    private static function wait() {
        $base = self::$barrier; self::$barrier = null;
        file_put_contents($base . '.ready', 'ready');
        $until = microtime(true) + 10;
        while (!is_file($base . '.resume')) {
            if (microtime(true) > $until) throw new RuntimeException('Pet-write barrier timed out');
            usleep(10000); clearstatcache();
        }
    }
    public static function query($sql) {
        if (self::$barrier && preg_match('/^SELECT .*FROM pm_usersdata .*FOR UPDATE$/i', $sql)) self::wait();
        $result = self::$connection->query($sql);
        if (self::$fault && preg_match(self::$fault, $sql)) throw new RuntimeException('Injected pet write failure');
        return $result;
    }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
    public static function fetch_all($sql) { return self::query($sql)->fetch_all(MYSQLI_ASSOC); }
    public static function result_first($sql) {
        $row = self::query($sql)->fetch_row();
        // Old handlers have no owner lock: stop after their unsafe count instead.
        if (self::$barrier && preg_match('/SELECT COUNT\(\*\) FROM pm_mypm/i', $sql)) self::wait();
        return $row ? $row[0] : null;
    }
    public static function insert_id() { return self::$connection->insert_id; }
    public static function affected_rows() { return self::$connection->affected_rows; }
}
require __DIR__ . '/../../plugin/admin/routes.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = DB::$connection = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
if (($argv[1] ?? '') === '--worker') {
    [,,$database,$base] = $argv;
    if (!preg_match('/^tsdm_test_adminpets_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Invalid worker schema');
    $db->select_db($database);
    $request = json_decode(file_get_contents($base . '.payload'), true, 512, JSON_THROW_ON_ERROR);
    $db->query("SET SESSION sql_mode='" . ($request['strict'] ? 'STRICT_ALL_TABLES' : '') . "'");
    DB::$barrier = $request['barrier'] ? $base : null; DB::$fault = $request['fault'];
    register_shutdown_function(function () use ($base, $db) {
        file_put_contents($base . '.transaction', (string)$db->query('SELECT @@in_transaction')->fetch_row()[0]);
    });
    $_POST = $request['params'];
    try { require __DIR__ . '/../../plugin/admin/dispatch.php'; }
    catch (Throwable $error) { echo json_encode(['success'=>false,'reason'=>$error->getMessage()]); }
    exit;
}
function start_pet_request($action, $payload, $barrier = false, $fault = null) {
    $base = sys_get_temp_dir() . '/tsdm_adminpets_' . bin2hex(random_bytes(8));
    file_put_contents($base . '.payload', json_encode(['params'=>['action'=>$action,'id'=>$payload['id'] ?? 0,'data'=>json_encode($payload)],
        'strict'=>$GLOBALS['strict'],'barrier'=>$barrier,'fault'=>$fault], JSON_THROW_ON_ERROR));
    $command = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
    array_push($command, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', __FILE__, '--worker', $GLOBALS['database'], $base);
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start pet worker');
    fclose($pipes[0]);
    $worker = ['base'=>$base,'process'=>$process,'out'=>$pipes[1],'err'=>$pipes[2]];
    $GLOBALS['workers'][] = $worker;
    return $worker;
}
function finish_pet_request($worker) {
    $output = stream_get_contents($worker['out']); $error = stream_get_contents($worker['err']);
    if ($error !== '') throw new RuntimeException($error);
    $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (trim(file_get_contents($worker['base'] . '.transaction')) !== '0') throw new RuntimeException('Handler leaked a transaction');
    return $response;
}
function pet_request($action, $payload, $fault = null) { return finish_pet_request(start_pet_request($action, $payload, false, $fault)); }
function ready_pet_request($worker) {
    $until = microtime(true) + 10;
    while (!is_file($worker['base'] . '.ready')) {
        if (microtime(true) > $until || !proc_get_status($worker['process'])['running']) throw new RuntimeException('Worker did not reach pet-write barrier');
        usleep(10000); clearstatcache();
    }
}
function reset_admin_pets() {
    DB::query('DELETE FROM pm_myskill'); DB::query('DELETE FROM pm_mypm'); DB::query('UPDATE pm_usersdata SET npcid=0');
    DB::query("INSERT INTO pm_mypm (id,uid,species_id,pmname,nickname,site,level,hp,state) VALUES (11,7,1,'Species','First',1,10,30,1),(12,7,1,'Species','Reserve',2,10,30,1)");
}
function pet_snapshot() { return [DB::fetch_all('SELECT * FROM pm_mypm ORDER BY id'), DB::fetch_all('SELECT * FROM pm_myskill ORDER BY id')]; }
function pet_heads() { return array_map('intval', array_column(DB::fetch_all('SELECT id FROM pm_mypm WHERE uid=7 AND site=1 ORDER BY id'), 'id')); }
$checks = $failures = 0;
function check_admin_pet($ok, $label) {
    $GLOBALS['checks']++; if (!$ok) $GLOBALS['failures']++;
    echo ($ok ? 'PASS ' : 'FAIL '), ($GLOBALS['strict'] ? 'strict ' : 'non-strict '), $label, "\n";
}
$database = 'tsdm_test_adminpets_' . bin2hex(random_bytes(8)); $workers = [];
$db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->select_db($database);
    $db->multi_query(file_get_contents(__DIR__ . '/../../docker/init.d/02-pokemon-schema.sql'));
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    DB::query('INSERT INTO pm_usersdata (uid,money) VALUES (7,100),(8,100)');
    DB::query("INSERT INTO pm_data (id,name,xs,hp,mapid,effort_values) VALUES (1,'Species','普通',50,'','{}')");
    DB::query("INSERT INTO pm_skill (id,name,available_pokemons,description) VALUES (10,'Move','k,1,k','')");
    foreach ([false, true] as $strict) {
        $db->query("SET SESSION sql_mode='" . ($strict ? 'STRICT_ALL_TABLES' : '') . "'");
        reset_admin_pets(); $edit = get_pokemon_info(12)[0]; $edit['site'] = 'header';
        $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet($r['success'] && pet_heads() === [12] && (int)DB::fetch_first('SELECT site FROM pm_mypm WHERE id=11')['site'] === 2, 'Set header demotes the previous leader');
        reset_admin_pets(); $edit = get_pokemon_info(11)[0]; $edit['site'] = 'store';
        $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet($r['success'] && pet_heads() === [12], 'Moving the leader appoints a replacement');
        reset_admin_pets(); DB::query('DELETE FROM pm_mypm WHERE id=12'); $before = pet_snapshot();
        $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet(!$r['success'] && pet_snapshot() === $before, 'Only leader cannot move away without replacement');

        reset_admin_pets(); $grant = get_pokemon_info(12)[0]; $grant['id'] = 0; $grant['name'] = "Trainer's \\新伙伴"; $grant['site'] = 'header';
        $r = pet_request('insert::pokemon_info', $grant); $new = $r['data'][0] ?? [];
        check_admin_pet($r['success'] && pet_heads() === [$new['id']] && $new['name'] === $grant['name'], 'Header grant preserves the name and replaces the leader');
        check_admin_pet((int)DB::fetch_first('SELECT hp FROM pm_mypm WHERE id=' . $new['id'])['hp'] === 30, 'Normal grant retains full initial HP');
        reset_admin_pets(); $grant['site'] = 'store'; $r = pet_request('insert::pokemon_info', $grant);
        check_admin_pet($r['success'] && $r['data'][0]['site'] === 'store' && pet_heads() === [11], 'Ordinary storage grant preserves the leader');
        DB::query('DELETE FROM pm_mypm'); $r = pet_request('insert::pokemon_info', $grant);
        check_admin_pet($r['success'] && $r['data'][0]['site'] === 'header' && count(pet_heads()) === 1, 'First grant becomes the leader like a first capture');

        reset_admin_pets(); DB::query('INSERT INTO pm_myskill (uid,petid,skillid,skillnum) VALUES (7,12,10,20)');
        $edit = get_pokemon_info(12)[0]; $edit['name'] = 'Changed'; $edit['skills'][0]['count'] = 15;
        $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet($r['success'] && $r['data'][0]['name'] === 'Changed' && $r['data'][0]['skills'][0]['count'] === 15, 'Name and skill PP update together');
        DB::query('DELETE FROM pm_myskill'); $before = pet_snapshot(); $edit['name'] = 'Stale';
        $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet(!$r['success'] && pet_snapshot() === $before, 'Forgotten skill rejects the entire stale edit');

        foreach (['set', 'insert', 'delete'] as $op) {
            reset_admin_pets(); DB::query('INSERT INTO pm_myskill (uid,petid,skillid,skillnum) VALUES (7,11,10,20)');
            $payload = get_pokemon_info(11)[0]; $payload['name'] = 'Atomic'; $payload['site'] = 'store'; $payload['skills'][0]['count'] = 15;
            if ($op === 'insert') { $payload['id'] = 0; $payload['site'] = 'header'; }
            $before = pet_snapshot();
            $pattern = $op === 'insert' ? '/^INSERT\s+into pm_myskill/i' : ($op === 'set' ? '/^UPDATE pm_myskill/i' : '/^DELETE FROM pm_myskill/i');
            $r = pet_request($op . '::pokemon_info', $payload, $pattern);
            check_admin_pet(!$r['success'] && strpos($r['reason'], 'Injected') !== false && pet_snapshot() === $before, "$op failure rolls back pets, leader and skills");
        }

        reset_admin_pets(); $before = pet_snapshot(); $edit = get_pokemon_info(12)[0]; $edit['owner'] = 8;
        $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet(!$r['success'] && pet_snapshot() === $before, 'Owner remains immutable');
        foreach ([['type_id'=>999], ['owner'=>999], ['skills'=>[['type_id'=>999,'count'=>1]]], ['skills'=>[['type_id'=>10,'count'=>1],['type_id'=>10,'count'=>2]]]] as $invalid) {
            $before = pet_snapshot(); $r = pet_request('insert::pokemon_info', array_replace($grant, $invalid));
            check_admin_pet(!$r['success'] && pet_snapshot() === $before, 'Invalid grant reference or duplicate skill does not write');
        }

        reset_admin_pets(); DB::query('UPDATE pm_usersdata SET npcid=25 WHERE uid=7');
        foreach (['edit_leader', 'promote_reserve', 'grant_leader', 'delete_leader'] as $scenario) {
            $before = pet_snapshot(); $payload = get_pokemon_info($scenario === 'promote_reserve' ? 12 : 11)[0];
            $payload['name'] = 'Battle mutation'; $payload['site'] = 'header';
            $op = $scenario === 'grant_leader' ? 'insert' : ($scenario === 'delete_leader' ? 'delete' : 'set');
            if ($op === 'insert') $payload['id'] = 0;
            $r = pet_request($op . '::pokemon_info', $payload);
            check_admin_pet(!$r['success'] && pet_snapshot() === $before, "$scenario preserves an active battle");
        }
        $edit = get_pokemon_info(12)[0]; $edit['name'] = 'Idle reserve'; $r = pet_request('set::pokemon_info', $edit);
        check_admin_pet($r['success'] && $r['data'][0]['name'] === 'Idle reserve', 'Battle still permits an idle reserve edit');

        reset_admin_pets(); DB::query('UPDATE pm_mypm SET site=5 WHERE id=12'); $r = pet_request('delete::pokemon_info', ['id'=>11]);
        check_admin_pet($r['success'] && pet_heads() === [12], 'Deleting the leader can promote an old numbered storage pet');
        $before = pet_snapshot(); $r = pet_request('delete::pokemon_info', ['id'=>12]);
        check_admin_pet(!$r['success'] && pet_snapshot() === $before, 'Last pet deletion is rejected');

        reset_admin_pets();
        $a = start_pet_request('delete::pokemon_info', ['id'=>11], true); ready_pet_request($a);
        $b = start_pet_request('delete::pokemon_info', ['id'=>12], true); ready_pet_request($b);
        file_put_contents($a['base'] . '.resume', 'resume'); file_put_contents($b['base'] . '.resume', 'resume');
        $results = [finish_pet_request($a), finish_pet_request($b)];
        check_admin_pet(count(array_filter($results, fn($r) => $r['success'])) === 1, 'Concurrent deletion allows only one of the final two requests');
        check_admin_pet((int)DB::result_first('SELECT COUNT(*) FROM pm_mypm WHERE uid=7') === 1 && count(pet_heads()) === 1, 'Concurrent deletion preserves one pet and one leader');
    }
    echo "Admin Pokemon database: $checks checks, $failures failures.\n";
} finally {
    foreach ($workers as $child) {
        if (proc_get_status($child['process'])['running']) proc_terminate($child['process']);
        fclose($child['out']); fclose($child['err']); proc_close($child['process']);
        foreach (['.payload','.ready','.resume','.transaction'] as $suffix) if (is_file($child['base'] . $suffix)) unlink($child['base'] . $suffix);
    }
    if (!preg_match('/^tsdm_test_adminpets_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Unsafe cleanup');
    $db->query("DROP DATABASE `$database`"); $db->close();
}
exit($failures ? 1 : 0);
