<?php
/** Request replay and transaction boundary tests; real SQL is covered by MariaDB integration. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', 1);
require __DIR__ . '/../../plugin/api/battle_actions.php';

class ActionError extends RuntimeException {
    public $error_code;
    public function __construct($status, $error_code) { parent::__construct($error_code, $status); $this->error_code = $error_code; }
}
function api_error($message, $code, $debug = null, $error_code = null) { battle_action_error($code); throw new ActionError($code, $error_code); }
function require_login() {}
function pm_table($name) { return $name; }
function pm_sql($sql, ...$args) {
    $i = 0;
    return preg_replace_callback('/%([ds])/', function ($m) use ($args, &$i) {
        $v = $args[$i++]; return $m[1] === 'd' ? (string)intval($v) : "'" . addslashes($v) . "'";
    }, $sql);
}
function battle_ensure_tables() { DB::$log[] = 'ENSURE SCHEMA'; }
function get_json_input() { return []; }

class DB {
    public static $rows = [];
    public static $receipts = [];
    public static $resources = ['pp' => 5, 'items' => 5, 'rewards' => 0];
    public static $log = [];
    public static $snapshot = null;
    public static $fail_receipt = false;
    public static function reset() {
        self::$rows = [7 => ['id' => 7, 'uid' => 1, 'revision' => 3, 'phase' => 'active', 'updated_at' => time()]];
        self::$receipts = []; self::$resources = ['pp' => 5, 'items' => 5, 'rewards' => 0];
        self::$log = []; self::$snapshot = null; self::$fail_receipt = false;
        $GLOBALS['battle_action_context'] = null;
        $GLOBALS['_G'] = ['uid' => 1, 'username' => 'fixture'];
        $_GET = ['action' => 'turn']; $_POST = [];
    }
    public static function fetch_first($sql) {
        $sql = preg_replace('/\s+/', ' ', trim($sql)); self::$log[] = $sql;
        if (preg_match('/^SELECT uid FROM pm_usersdata WHERE uid = (\d+) FOR UPDATE$/', $sql, $m)) return ['uid' => (int)$m[1]];
        if (preg_match("/FROM pm_battle_action WHERE uid = (\d+) AND request_id = '([^']+)'/", $sql, $m)) return self::$receipts[$m[1] . ':' . $m[2]] ?? false;
        if (preg_match("/FROM pm_battle_unit WHERE battle_id = (\d+) AND side = 'enemy'/", $sql)) return ['boss_multiplier' => '3.0000'];
        if (strpos($sql, 'FROM pm_battle ') !== false) {
            preg_match('/uid = (\d+)/', $sql, $m); $uid = (int)$m[1];
            preg_match('/WHERE id = (\d+)/', $sql, $id);
            $rows = array_filter(self::$rows, function ($row) use ($uid, $id, $sql) {
                return $row['uid'] === $uid && (!$id || $row['id'] === (int)$id[1])
                    && (strpos($sql, 'phase IN') === false || in_array($row['phase'], ['active', 'awaiting_switch'], true));
            });
            krsort($rows); return $rows ? reset($rows) : false;
        }
        throw new RuntimeException('Unhandled read: ' . $sql);
    }
    public static function query($sql) {
        $sql = preg_replace('/\s+/', ' ', trim($sql)); self::$log[] = $sql;
        if ($sql === 'START TRANSACTION') {
            if (self::$snapshot !== null) throw new RuntimeException('Nested transaction would commit gameplay early');
            self::$snapshot = [self::$rows, self::$receipts, self::$resources]; return;
        }
        if ($sql === 'COMMIT') { self::$snapshot = null; return; }
        if ($sql === 'ROLLBACK') {
            if (self::$snapshot !== null) list(self::$rows, self::$receipts, self::$resources) = self::$snapshot;
            self::$snapshot = null; return;
        }
        if (preg_match('/^UPDATE pm_battle SET revision = revision \+ 1 WHERE id = (\d+) AND uid = (\d+)$/', $sql, $m)) {
            if (self::$rows[(int)$m[1]]['uid'] !== (int)$m[2]) throw new RuntimeException('Wrong owner');
            self::$rows[(int)$m[1]]['revision']++; return;
        }
        if (preg_match('/^INSERT INTO pm_battle_action .* VALUES \((.*)\)$/', $sql, $m)) {
            if (self::$fail_receipt) throw new RuntimeException('Injected receipt write failure');
            $values = str_getcsv($m[1], ',', "'", '\\');
            $values = array_map(function ($v) { return stripslashes(trim($v, " '\t")); }, $values);
            $row = array_combine(['uid','request_id','action','payload_hash','battle_id','response_json','created_at'], $values);
            $key = $row['uid'] . ':' . $row['request_id'];
            if (isset(self::$receipts[$key])) throw new RuntimeException('Duplicate receipt');
            self::$receipts[$key] = $row; return;
        }
        if (preg_match('/^UPDATE pm_battle_action SET response_json = NULL WHERE uid = (\d+) AND created_at < (\d+)/', $sql, $m)) {
            foreach (self::$receipts as &$row) if ((int)$row['uid'] === (int)$m[1] && (int)$row['created_at'] < (int)$m[2]) $row['response_json'] = null;
            unset($row); return;
        }
        throw new RuntimeException('Unhandled write: ' . $sql);
    }
}
$passed = 0;
function check($condition, $label) { global $passed; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $passed++; echo 'PASS ' . $label . "\n"; }
function input_for($key = 'request-00000001', $revision = 3) { return ['request_id' => $key, 'engine_battle_id' => 7, 'expected_revision' => $revision, 'skill_id' => 4]; }
function expect_error($fn, $status, $machine, $label) {
    try { $fn(); } catch (ActionError $e) { check($e->getCode() === $status && $e->error_code === $machine, $label); return; }
    throw new RuntimeException('Missing expected error: ' . $label);
}
function finish_scene($extra = []) { return battle_action_finalize(['success' => true, 'timestamp' => 1234567, 'data' => array_merge(['battle_id' => 'battle_1', 'status' => 'active'], $extra)]); }

DB::reset();
$input = input_for();
check(battle_action_begin('turn', $input) === null, 'first request executes');
check(DB::$log[0] === 'ENSURE SCHEMA' && DB::$log[1] === 'START TRANSACTION', 'schema prepared before opening transaction');
battle_action_transaction_begin(); DB::$resources['pp']--; battle_action_transaction_commit();
check(DB::$snapshot !== null && !in_array('COMMIT', DB::$log, true), 'inner commit cannot release the request transaction');
$first = finish_scene();
check($first['data']['engine_battle_id'] === 7 && $first['data']['revision'] === 4 && $first['data']['phase'] === 'active', 'response exposes authoritative reconnect metadata');
check(DB::$snapshot === null && count(DB::$receipts) === 1, 'gameplay and receipt commit together');
$reordered = ['skill_id' => 4, 'expected_revision' => 3, 'engine_battle_id' => 7, 'request_id' => 'request-00000001'];
check(battle_action_begin('turn', $reordered) === $first, 'lost response replay is exact despite JSON object key order');
check(DB::$resources['pp'] === 4 && DB::$rows[7]['revision'] === 4, 'replay never consumes PP or advances revision');
expect_error(function () use ($input) { $input['skill_id'] = 5; battle_action_begin('turn', $input); }, 409, 'request_id_conflict', 'key cannot be reused with a different skill');
expect_error(function () use ($input) { battle_action_begin('capture', $input); }, 409, 'request_id_conflict', 'key cannot be reused for another action');
expect_error(function () { battle_action_begin('turn', input_for('request-00000002')); }, 409, 'battle_state_conflict', 'new key with old revision is rejected');
expect_error(function () { $input = input_for('request-00000002', 4); $input['engine_battle_id'] = 8; battle_action_begin('turn', $input); }, 409, 'battle_state_conflict', 'a different battle cannot consume this action');
expect_error(function () { $input = input_for('short'); battle_action_begin('turn', $input); }, 400, 'invalid_battle_request', 'short request ID is rejected');
expect_error(function () { $input = input_for(); $input['expected_revision'] = '3'; battle_action_begin('turn', $input); }, 400, 'invalid_battle_request', 'numeric string revision is rejected');
check(DB::$snapshot === null, 'validation failures leave no transaction');
DB::reset(); battle_action_begin('turn', input_for()); battle_action_rollback();
$refused_success = false;
try { finish_scene(); } catch (RuntimeException $e) { $refused_success = true; }
check($refused_success && !DB::$receipts, 'a rolled back action cannot publish a success receipt');

// Fail after gameplay writes, including failure to record the response.
DB::reset(); battle_action_begin('capture', input_for()); DB::$resources['items']--; DB::$rows[7]['phase'] = 'ended';
DB::$fail_receipt = true;
try { finish_scene(['status' => 'captured']); } catch (RuntimeException $e) { battle_action_rollback(); }
check(DB::$resources['items'] === 5 && DB::$rows[7]['phase'] === 'active' && DB::$rows[7]['revision'] === 3 && !DB::$receipts, 'receipt failure rolls back the capture and consumed item');
DB::$fail_receipt = false;
check(battle_action_begin('capture', input_for()) === null, 'rolled back key may safely retry');
DB::$resources['items']--; DB::$rows[7]['phase'] = 'ended'; $captured = finish_scene(['status' => 'captured']);
DB::$rows[8] = ['id' => 8, 'uid' => 1, 'revision' => 1, 'phase' => 'active', 'updated_at' => time()];
check(battle_action_begin('capture', input_for()) === $captured, 'completed capture replays even after a new battle starts');
check(DB::$resources['items'] === 4 && DB::$rows[8]['revision'] === 1, 'capture replay leaves new battle and inventory unchanged');

// Start replay cannot create a second encounter, even after the first ended.
DB::reset(); DB::$rows = [];
$start = ['request_id' => 'start-0000000001', 'engine_battle_id' => 0, 'expected_revision' => 0, 'map_id' => 1];
check(battle_action_begin('start', $start) === null, 'start accepts zero battle and revision');
DB::$rows[9] = ['id' => 9, 'uid' => 1, 'revision' => 0, 'phase' => 'active', 'updated_at' => time()];
$started = finish_scene(); DB::$rows[9]['phase'] = 'ended';
check(battle_action_begin('start', $start) === $started && count(DB::$rows) === 1, 'start response replay never creates another encounter');
DB::$rows[9]['phase'] = 'active';
expect_error(function () use ($start) { $start['request_id'] = 'start-0000000002'; battle_action_begin('start', $start); }, 409, 'battle_state_conflict', 'fresh start key cannot replace an active battle');

DB::reset(); battle_action_begin('start', ['map_id' => 1]);
DB::$rows[7]['phase'] = 'ended';
DB::$rows[8] = ['id' => 8, 'uid' => 1, 'revision' => 0, 'phase' => 'active', 'updated_at' => time()];
$restarted = finish_scene();
check($restarted['data']['engine_battle_id'] === 8 && $restarted['data']['revision'] === 1 && DB::$rows[7]['revision'] === 3, 'legacy start reports the newly created encounter after expiry');

// All mutations join the same machinery, including old clients.
foreach (['turn', 'flee', 'capture', 'use_item', 'use_item_on_skill', 'switch_pokemon', 'replace_pokemon'] as $action) {
    DB::reset(); battle_action_begin($action, input_for()); DB::$resources['items']--; $response = finish_scene();
    check(battle_action_begin($action, input_for()) === $response && DB::$resources['items'] === 4, $action . ' is replay-safe');
}
DB::reset(); battle_action_begin('turn', ['skill_id' => 4]); DB::$resources['pp']--; finish_scene();
check(!DB::$receipts && DB::$rows[7]['revision'] === 4, 'legacy request remains accepted and advances revision');
expect_error(function () { battle_action_begin('turn', input_for()); }, 409, 'battle_state_conflict', 'legacy action also invalidates stale modern requests');

DB::reset(); battle_action_begin('use_item', input_for());
$selection = finish_scene(['requires_skill_selection' => true]);
check(DB::$rows[7]['revision'] === 3 && $selection['data']['revision'] === 3, 'PP selection does not advance revision');
check(battle_action_begin('use_item', input_for()) === $selection, 'PP selection response also replays');

DB::reset(); battle_action_begin('recover', []); $recovered = finish_scene();
check($recovered['data']['revision'] === 3 && !DB::$receipts && DB::$snapshot === null, 'recover serializes its snapshot without advancing revision');
battle_action_begin('recover', []); DB::$rows[7]['phase'] = 'ended'; battle_action_error(404);
check(DB::$rows[7]['phase'] === 'ended' && DB::$snapshot === null, 'recover 404 commits expiry cleanup');

DB::reset(); battle_action_begin('turn', input_for()); finish_scene();
DB::$receipts['1:request-00000001']['created_at'] = time() - 31 * 86400;
battle_action_begin('turn', input_for('request-00000002', 4)); finish_scene();
check(count(DB::$receipts) === 2 && DB::$receipts['1:request-00000001']['response_json'] === null, 'old response bodies are compacted into tombstones');
expect_error(function () { battle_action_begin('turn', input_for()); }, 409, 'request_expired', 'expired request key can never execute again');

// Ownership is in both the unique key and fingerprint, never supplied by JSON.
DB::reset(); battle_action_begin('turn', input_for()); finish_scene();
$GLOBALS['_G'] = ['uid' => 2, 'username' => 'second'];
DB::$rows[8] = ['id' => 8, 'uid' => 2, 'revision' => 3, 'phase' => 'active', 'updated_at' => time()];
$other = input_for(); $other['engine_battle_id'] = 8;
check(battle_action_begin('turn', $other) === null, 'another user cannot receive the first users receipt');
$owned = finish_scene();
check($owned['data']['engine_battle_id'] === 8 && count(DB::$receipts) === 2, 'same opaque key is independently scoped per user');
foreach (['turn', 'flee', 'capture', 'use_item', 'use_item_on_skill', 'switch_pokemon', 'replace_pokemon', 'recover'] as $action) {
    DB::reset(); DB::$rows[7]['kind'] = 'boss';
    battle_action_begin($action, $action === 'recover' ? [] : input_for());
    $response = finish_scene(['wild_pokemon' => ['id' => 129, 'name' => 'Boss species', 'is_boss' => false]]);
    $wild = $response['data']['wild_pokemon'];
    check($wild['is_boss'] && ($wild['boss_multiplier'] ?? 0) === 3.0 && $wild['name'] === '[BOSS] Boss species',
        $action . ' retains authoritative Boss metadata');
}
DB::reset(); DB::$rows = [];
$variant_start = ['request_id' => 'boss-start-000001', 'engine_battle_id' => 0, 'expected_revision' => 0,
    'map_id' => 1, 'boss_pokemon_type_id' => 129, 'boss_index' => 1];
battle_action_begin('start', $variant_start);
DB::$rows[9] = ['id' => 9, 'uid' => 1, 'revision' => 0, 'phase' => 'active', 'kind' => 'boss', 'updated_at' => time()];
$boss_scene = finish_scene(['wild_pokemon' => ['id' => 129, 'name' => '[BOSS] Boss species', 'is_boss' => true]]);
check($boss_scene['data']['wild_pokemon']['name'] === '[BOSS] Boss species', 'Start does not duplicate the Boss name prefix');
DB::$rows[9]['phase'] = 'ended';
check(json_encode(battle_action_begin('start', $variant_start)) === json_encode($boss_scene), 'Boss variant replay retains the exact authoritative scene');
expect_error(function () use ($variant_start) { $variant_start['boss_index'] = 0; battle_action_begin('start', $variant_start); },
    409, 'request_id_conflict', 'One request key cannot select a different same-species Boss variant');

echo "Battle action tests: $passed passed.\n";
