<?php
/** Real handlers with deterministic competing requests and rollback failures. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);

function load_mutation_functions($path, $wanted)
{
    $tokens = token_get_all(file_get_contents($path));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $start = $i;
        while (++$i < count($tokens) && (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)) {}
        $name = $tokens[$i][1];
        $depth = 0; $body = ''; $opened = false;
        for ($j = $start; $j < count($tokens); $j++) {
            $token = $tokens[$j];
            $body .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++; $opened = true;
            } elseif ($token === '}' && --$depth === 0 && $opened) break;
        }
        if (in_array($name, $wanted, true)) eval($body);
        $i = $j;
    }
}
load_mutation_functions(__DIR__ . '/../../plugin/api/index.php', ['pm_sql', 'pm_sql_v', 'pm_table', 'validate_uid', 'validate_id', 'validate_string']);
load_mutation_functions(__DIR__ . '/../../plugin/api/utils.php', ['pm_abort_battle_transaction', 'api_calculate_pokemon_max_hp', 'api_get_pet_exp_level', 'api_get_pet_exp_max', 'api_get_pet_exp_max_data']);
load_mutation_functions($argv[1] ?? __DIR__ . '/../../plugin/api/pokemon.php', ['api_release_pokemon', 'api_rename_pokemon', 'api_equip_item', 'api_unequip_item', 'calculate_pokemon_full_stats']);
load_mutation_functions($argv[2] ?? __DIR__ . '/../../plugin/api/item_modules.php', ['lvupitem']);
require $argv[3] ?? __DIR__ . '/../../plugin/api/pokemon_utils.php';

class MutationResponse extends RuntimeException
{
    public $data;
    public function __construct($status, $data) { parent::__construct('response', $status); $this->data = $data; }
}
function require_login() {}
function get_json_input() { return $GLOBALS['input']; }
function api_success($data) { throw new MutationResponse(200, $data); }
function api_error($message, $status = 400) { throw new MutationResponse($status, $message); }
function api_my_usersdata($uid) { return DB::$user; }

class DB
{
    public static $pets, $skills, $user, $snapshot, $race, $fail, $equipment;
    public static $changed = 0;
    public static $locked = false, $transaction = false;
    public static function reset()
    {
        self::$pets = [];
        foreach ([1, 2, 3] as $id) self::$pets[$id] = ['id' => $id, 'uid' => $id === 3 ? 8 : 7,
            'site' => $id === 2 ? 3 : 1, 'state' => 1, 'hp' => 25, 'species_id' => 1,
            'nickname' => 'Pet ' . $id, 'level' => 10, 'exp' => 1061, 'hpg' => 10, 'hpn' => 0, 'is_shiny' => 0,
            'atkg' => 0, 'defg' => 0, 'spatkg' => 0, 'spdefg' => 0, 'sdg' => 0,
            'atkn' => 0, 'defn' => 0, 'spatkn' => 0, 'spdefn' => 0, 'sdn' => 0,
            'equipmentid1' => 0, 'equipmentid2' => 0, 'equipmentid3' => 0, 'equipmentid4' => 0];
        self::$skills = [1 => ['petid' => 1, 'uid' => 7], 2 => ['petid' => 2, 'uid' => 7], 3 => ['petid' => 3, 'uid' => 8]];
        self::$user = ['uid' => 7, 'npcid' => 0];
        self::$snapshot = self::$race = self::$fail = null;
        self::$equipment = [9 => ['id' => 9, 'uid' => 7, 'itemid' => 40, 'type' => 5, 'name' => 'HP bracelet', 'equipment' => '{"hp":100}', 'nums' => 1]];
        self::$changed = 0;
        self::$locked = self::$transaction = false;
        $GLOBALS['_G'] = ['uid' => 7]; $GLOBALS['input'] = ['id' => 1];
    }
    private static function compete()
    {
        if (self::$race) { $race = self::$race; self::$race = null; $race(); }
    }
    public static function fetch_first($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (preg_match('/^SELECT (?:\*|uid|uid, npcid) FROM pm_usersdata WHERE uid = 7 FOR UPDATE$/', $sql)) {
            if (!self::$transaction) throw new RuntimeException('Account lock outside transaction');
            self::compete(); // A previously started request commits before the lock is acquired.
            self::$snapshot = [self::$pets, self::$skills, self::$user];
            self::$locked = true;
            return self::$user;
        }
        if (preg_match('/^SELECT (?:\*|id, site, state, hp) FROM pm_mypm WHERE id = (\d+) AND uid = (\d+)(?: FOR UPDATE)?$/', $sql, $m)) {
            $row = self::$pets[(int)$m[1]] ?? null;
            return $row && $row['uid'] === (int)$m[2] ? $row : false;
        }
        if (preg_match('/^SELECT id FROM pm_mypm WHERE uid = 7 AND id != (\d+) ORDER BY site ASC, id ASC LIMIT 1(?: FOR UPDATE)?$/', $sql, $m)) {
            $rows = array_filter(self::$pets, function ($pet) use ($m) { return $pet['uid'] === 7 && $pet['id'] !== (int)$m[1]; });
            usort($rows, function ($a, $b) { return $a['site'] <=> $b['site'] ?: $a['id'] <=> $b['id']; });
            return $rows ? $rows[0] : false;
        }
        if ($sql === 'SELECT hp FROM pm_data WHERE id = 1') return ['hp' => 50];
        if ($sql === 'SELECT * FROM pm_data WHERE id = 1') return ['hp' => 50, 'atk' => 50, 'def' => 50, 'spatk' => 50, 'spdef' => 50, 'speed' => 50];
        if (preg_match('/FROM pm_myitem m LEFT JOIN pm_itemdata i ON m.itemid=i.id WHERE m.id=(\d+)(?: AND m.uid=(\d+))?(?: FOR UPDATE)?$/', $sql, $m)) {
            if (!self::$locked) self::compete();
            $row = self::$equipment[(int)$m[1]] ?? null;
            return $row && (empty($m[2]) || $row['uid'] === (int)$m[2]) ? $row : false;
        }
        if (preg_match('/^SELECT id, nickname FROM pm_mypm WHERE \(equipmentid1=(\d+) OR equipmentid2=\1 OR equipmentid3=\1 OR equipmentid4=\1\) AND uid=7$/', $sql, $m)) {
            foreach (self::$pets as $pet) for ($slot = 1; $slot <= 4; $slot++) {
                if ($pet['uid'] === 7 && $pet['equipmentid' . $slot] === (int)$m[1]) return $pet;
            }
            return false;
        }
        throw new RuntimeException('Unexpected read: ' . $sql);
    }
    public static function result_first($sql)
    {
        if ($sql !== 'SELECT COUNT(*) FROM pm_mypm WHERE uid = 7') throw new RuntimeException('Unexpected count: ' . $sql);
        $count = count(array_filter(self::$pets, function ($pet) { return $pet['uid'] === 7; }));
        if (!self::$locked) self::compete(); // Old endpoint lets another request invalidate this count.
        return $count;
    }
    public static function query($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if ($sql === 'START TRANSACTION') { self::$transaction = true; self::$snapshot = [self::$pets, self::$skills, self::$user]; return; }
        if ($sql === 'COMMIT' || $sql === 'ROLLBACK') {
            if ($sql === 'ROLLBACK' && self::$transaction) [self::$pets, self::$skills, self::$user] = self::$snapshot;
            self::$transaction = self::$locked = false; return;
        }
        if (self::$fail && strpos($sql, self::$fail) !== false) throw new RuntimeException('Injected write failure');
        self::$changed = 0;
        if (preg_match('/^DELETE FROM pm_mypm WHERE id = (\d+) AND uid = (\d+)$/', $sql, $m)) {
            if (isset(self::$pets[(int)$m[1]]) && self::$pets[(int)$m[1]]['uid'] === (int)$m[2]) unset(self::$pets[(int)$m[1]]);
        } elseif (preg_match('/^DELETE FROM pm_myskill WHERE petid = (\d+) AND uid = (\d+)$/', $sql, $m)) {
            self::$skills = array_filter(self::$skills, function ($skill) use ($m) { return $skill['petid'] !== (int)$m[1] || $skill['uid'] !== (int)$m[2]; });
        } elseif (preg_match('/^UPDATE pm_mypm SET site = 1 WHERE id = (\d+)(?: AND uid = 7)?$/', $sql, $m)) {
            self::$pets[(int)$m[1]]['site'] = 1;
        } elseif (preg_match("/^UPDATE pm_mypm SET nickname = ('(?:\\\\.|[^'\\\\])*') WHERE id = (\\d+) AND uid = (\\d+)$/", $sql, $m)) {
            if (isset(self::$pets[(int)$m[2]]) && self::$pets[(int)$m[2]]['uid'] === (int)$m[3]) self::$pets[(int)$m[2]]['nickname'] = stripslashes(substr($m[1], 1, -1));
        } elseif (preg_match('/^UPDATE pm_mypm SET level = (\d+)(?:, exp = (\d+))? WHERE id = (\d+)$/', $sql, $m)) {
            self::$pets[(int)$m[3]]['level'] = (int)$m[1];
            if ($m[2] !== '') self::$pets[(int)$m[3]]['exp'] = (int)$m[2];
        } elseif (preg_match('/^UPDATE pm_mypm SET hp = (\d+) WHERE id = (\d+)$/', $sql, $m)) {
            self::$pets[(int)$m[2]]['hp'] = (int)$m[1];
        } elseif (preg_match('/^UPDATE pm_mypm pet LEFT JOIN pm_mypm occupier .* SET pet.(equipmentid[1-4])=(\d+) WHERE pet.id=(\d+) AND pet.uid=7 AND pet.\1=0 AND occupier.id IS NULL$/', $sql, $m)) {
            $pet = self::$pets[(int)$m[3]] ?? null;
            if (!$pet || $pet['uid'] !== 7 || $pet[$m[1]] !== 0) return;
            foreach (self::$pets as $row) for ($slot = 1; $slot <= 4; $slot++) {
                if ($row['uid'] === 7 && $row['equipmentid' . $slot] === (int)$m[2]) return;
            }
            self::$pets[(int)$m[3]][$m[1]] = (int)$m[2]; self::$changed = 1;
        } elseif (preg_match('/^UPDATE pm_mypm SET (equipmentid[1-4])=0 WHERE id=(\d+) AND uid=7$/', $sql, $m)) {
            self::$pets[(int)$m[2]][$m[1]] = 0; self::$changed = 1;
        } else throw new RuntimeException('Unexpected write: ' . $sql);
    }
    public static function affected_rows() { return self::$changed; }
}

function request_mutation($function)
{
    try { $function(); } catch (MutationResponse $response) { return $response; }
    throw new RuntimeException('No endpoint response');
}
$passed = $failed = 0;
function check($condition, $label)
{
    if ($condition) { $GLOBALS['passed']++; echo 'PASS ', $label, "\n"; }
    else { $GLOBALS['failed']++; echo 'FAIL ', $label, "\n"; }
}

DB::reset();
DB::$race = function () { unset(DB::$pets[2], DB::$skills[2]); };
$response = request_mutation('api_release_pokemon');
check($response->getCode() === 400 && isset(DB::$pets[1]) && !isset(DB::$pets[2]), 'A competing release cannot delete the final owned Pokemon');
check(!DB::$transaction, 'Last-Pokemon rejection releases the account lock');

DB::reset(); DB::$pets[1]['site'] = 2; DB::$pets[2]['site'] = 1;
DB::$race = function () { DB::$pets[1]['site'] = 1; DB::$pets[2]['site'] = 2; DB::$user['npcid'] = 19; };
$response = request_mutation('api_release_pokemon');
check($response->getCode() === 400 && isset(DB::$pets[1]), 'Recheck battle and current first Pokemon after acquiring the lock');

DB::reset(); $before = [DB::$pets, DB::$skills]; DB::$fail = 'DELETE FROM pm_myskill';
try { api_release_pokemon(); } catch (RuntimeException $error) {}
check([DB::$pets, DB::$skills] === $before, 'A skill deletion failure rolls back pet deletion and first-Pokemon promotion');
check(!DB::$transaction, 'Write failure leaves no open transaction');

DB::reset(); $response = request_mutation('api_release_pokemon');
check($response->getCode() === 200 && !isset(DB::$pets[1]) && !isset(DB::$skills[1]), 'Valid release deletes the pet and its skills');
check(DB::$pets[2]['site'] === 1 && DB::$pets[3]['site'] === 1 && isset(DB::$skills[3]), 'Promote the owned replacement and leave other users untouched');
check(!DB::$transaction, 'Successful release commits');
foreach ([['state', 0], ['hp', 0]] as $case) {
    DB::reset(); DB::$pets[1][$case[0]] = $case[1];
    check(request_mutation('api_release_pokemon')->getCode() === 400 && isset(DB::$pets[1]) && !DB::$transaction, 'Release preserves blocked ' . $case[0] . ' state');
}
DB::reset(); $GLOBALS['input'] = ['id' => 3];
check(request_mutation('api_release_pokemon')->getCode() === 404 && isset(DB::$pets[3]) && !DB::$transaction, 'Cannot release another users pet');

foreach (["O'Brien", 'C:\\pet\\name', '普通名字'] as $name) {
    DB::reset(); $GLOBALS['input'] = ['id' => 1, 'name' => $name];
    check(request_mutation('api_rename_pokemon')->getCode() === 200 && DB::$pets[1]['nickname'] === $name, 'Nickname round trip: ' . $name);
}

DB::reset(); check(lvupitem(1, 'Candy') === 0 && DB::$pets[1]['level'] === 11, 'Candy increases exactly one level');
check(DB::$pets[1]['exp'] === 1539 && api_get_pet_exp_level(1, DB::$pets[1]['exp']) === 11, 'Candy also grants the new level minimum experience');
check(DB::$pets[1]['hp'] === api_calculate_pokemon_max_hp(DB::$pets[1]), 'Candy recalculates HP using its new level');
DB::reset(); DB::$pets[1]['level'] = 1; DB::$pets[1]['exp'] = 0; lvupitem(1, 'Candy');
check(DB::$pets[1]['level'] === 2 && api_get_pet_exp_level(1, DB::$pets[1]['exp']) === 2, 'Level-one candy avoids the zero-experience special case');
DB::reset(); DB::$pets[1]['exp'] = 2000; lvupitem(1, 'Candy');
check(DB::$pets[1]['exp'] === 2000, 'Candy never reduces previously earned experience');
DB::reset(); DB::$pets[1]['level'] = 99; lvupitem(1, 'Candy');
check(DB::$pets[1]['level'] === 100 && api_get_pet_exp_level(1, DB::$pets[1]['exp']) === 100, 'Candy reaches level 100 with consistent experience');
$before = DB::$pets; check(lvupitem(1, 'Candy') === 1 && DB::$pets === $before, 'Level 100 rejects candy without mutation');
check(lvupitem(3, 'Candy') === 1 && DB::$pets === $before, 'Candy cannot change another users Pokemon');

function equipment_fixture($equipped = false)
{
    DB::reset(); DB::$pets[1]['level'] = 50; DB::$pets[1]['hpg'] = 0;
    DB::$pets[1]['equipmentid1'] = $equipped ? 9 : 0;
    DB::$pets[1]['hp'] = $equipped ? 105 : 55;
    $GLOBALS['input'] = ['pokemon_id' => 1, 'myitem_id' => 9, 'slot_index' => 0];
}
equipment_fixture(); DB::$race = function () { DB::$pets[1]['hp'] = 110; };
$response = request_mutation('api_equip_item');
check($response->getCode() === 200 && DB::$pets[1]['hp'] === 210, 'Equip preserves a heal committed before acquiring its lock');
check(DB::$pets[1]['equipmentid1'] === 9 && !DB::$transaction, 'Equip commits the slot and HP together');
equipment_fixture(true); DB::$race = function () { DB::$pets[1]['hp'] = 42; };
$response = request_mutation('api_unequip_item');
check($response->getCode() === 200 && DB::$pets[1]['hp'] === 22, 'Unequip scales the current damaged HP rather than an old snapshot');
check(DB::$pets[1]['equipmentid1'] === 0 && !DB::$transaction, 'Unequip commits the slot and HP together');
foreach (['api_equip_item' => false, 'api_unequip_item' => true] as $handler => $equipped) {
    equipment_fixture($equipped); $before = DB::$pets; DB::$fail = 'UPDATE pm_mypm SET hp';
    try { $handler(); } catch (RuntimeException $error) {}
    check(DB::$pets === $before && !DB::$transaction, $handler . ' rolls back its slot when HP persistence fails');
    equipment_fixture($equipped); DB::$pets[1]['hp'] = 0;
    check(request_mutation($handler)->getCode() === 200 && DB::$pets[1]['hp'] === 0 && !DB::$transaction, $handler . ' cannot revive a fainted Pokemon');
}
equipment_fixture(true); $GLOBALS['input']['slot_index'] = 1; $before = DB::$pets;
check(request_mutation('api_equip_item')->getCode() === 400 && DB::$pets === $before && !DB::$transaction, 'Duplicate equipment rejection leaves no open transaction');
equipment_fixture(); DB::$equipment[9]['uid'] = 8; $before = DB::$pets;
check(request_mutation('api_equip_item')->getCode() === 404 && DB::$pets === $before && !DB::$transaction, 'Cannot equip another users item');
equipment_fixture(); $before = DB::$pets;
check(request_mutation('api_unequip_item')->getCode() === 400 && DB::$pets === $before && !DB::$transaction, 'Empty-slot rejection leaves no open transaction');
foreach (['api_equip_item' => false, 'api_unequip_item' => true] as $handler => $equipped) {
    equipment_fixture($equipped); DB::$user['npcid'] = 19; $before = DB::$pets;
    check(request_mutation($handler)->getCode() === 400 && DB::$pets === $before && !DB::$transaction, $handler . ' rejects active battle equipment changes');
    equipment_fixture($equipped); DB::$user['npcid'] = 19; DB::$pets[1]['site'] = 2;
    check(request_mutation($handler)->getCode() === 200 && !DB::$transaction, $handler . ' still permits changes to a reserve Pokemon');
}

$species = ['id' => 1, 'name' => 'Starter', 'xs' => 'normal',
    'hp' => 50, 'atk' => 50, 'def' => 50, 'spatk' => 50, 'spdef' => 50, 'speed' => 50];
foreach ([-1 => [0], 0 => [2], 1000 => [1], 125 => [1, 2], 500 => [1, 2], 875 => [1, 2]] as $weight => $expected) {
    $species['sex'] = $weight; $observed = [];
    // Fixed seeds cover both sides of mixed ratios without a probabilistic test.
    for ($seed = 0; $seed < 64; $seed++) {
        srand($seed);
        $pet = create_new_pokemon_data($species, 5, 7);
        $observed[$pet['sex']] = true;
    }
    $actual = array_keys($observed); sort($actual);
    check($actual === $expected, 'New Pokemon respects per-thousand gender weight ' . $weight);
}

echo "Pokemon mutation integrity: $passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
