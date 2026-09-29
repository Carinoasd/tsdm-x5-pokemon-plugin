<?php
/** Behavioral regressions for battle authorization, with an in-memory DB. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Load actual endpoint functions without running the Discuz dispatcher.
$wanted = ['api_start_battle', 'api_use_skill', 'api_replace_pokemon', 'api_normalize_skill_category'];
$tokens = token_get_all(file_get_contents(__DIR__ . '/../../plugin/api/battle.php'));
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $start = $i;
    while (++$i < count($tokens) && (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)) {}
    $name = $tokens[$i][1];
    $depth = 0;
    $body = '';
    $opened = false;
    for ($j = $start; $j < count($tokens); $j++) {
        $token = $tokens[$j];
        $body .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            $opened = true;
        } elseif ($token === '}') {
            $depth--;
            if ($opened && $depth === 0) break;
        }
    }
    if (in_array($name, $wanted, true)) eval($body);
    $i = $j;
}
foreach ($wanted as $name) {
    if (!function_exists($name)) throw new RuntimeException("Function not loaded: $name");
}

class BattleResponse extends RuntimeException
{
    public $data;
    public function __construct($message, $code, $data = null) {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}

function api_error($message, $code) { throw new BattleResponse($message, $code); }
function api_success($data) { throw new BattleResponse('success', 200, $data); }
function require_login() {}
function get_json_input() { return $GLOBALS['input']; }
function get_param($name, $default) { return $default; }
function pm_table($name) { return $name; }
function pm_sql($sql, ...$args) { return vsprintf($sql, $args); }
function api_my_usersdata($uid) { return $GLOBALS['user']; }
function api_my_pokemon($username) {
    foreach (DB::$pets as $pet) {
        if ($pet['uid'] === $GLOBALS['_G']['uid'] && $pet['site'] === 1) return $pet;
    }
    return false;
}
function pm_data($id) { return ['name' => 'Wild', 'strength' => 1, 'xs' => 'normal', 'sex' => 500]; }
function battle_calc_my_stats($data, $pokemon) { return [100, 20, 20, 20, 20, $GLOBALS['speed']]; }
function battle_calc_npc_stats($data, $user, $strength) { return [100, 20, 20, 20, 20, 20]; }
function battle_calc_new_npc_stats($data, $level, $strength) { return [100, 20, 20, 20, 20, 20]; }
function calculate_damage_legacy(...$args) { $GLOBALS['damage_calls']++; return 10; }
function calculate_counter_damage_legacy(...$args) { $GLOBALS['counter_calls']++; return $GLOBALS['counter_damage']; }
function api_calculate_pokemon_max_hp($pokemon) { return 100; }
function api_validate_and_correct_hp($pokemon, $hp, $max_hp) { return ['hp' => $hp]; }
function build_battle_response($user, $pokemon, ...$args) { return ['my_pokemon' => $pokemon, 'wild_pokemon' => ['name' => 'Wild']]; }
function generate_wild_pokemon_legacy($map, $strength, $boss) {
    $GLOBALS['generated']++;
    return ['npcid' => 25, 'level' => 10, 'capture' => 100];
}
function calculate_rewards(...$args) { return ['experience' => 1]; }
function clear_battle_state($uid) { $GLOBALS['user']['npcid'] = 0; }
function apply_rewards($uid, $pokemon, $rewards) { $GLOBALS['reward_calls']++; return null; }
function handle_my_pokemon_fainted($uid, $id) { return [false, true]; }

class DB
{
    public static $pets;
    public static $skills;
    public static $learned;
    public static $maps;
    public static $writes;

    public static function fetch_first($sql) {
        if (preg_match('/FROM pm_skill WHERE id = (\d+)/', $sql, $m)) return self::$skills[(int)$m[1]] ?? false;
        if (preg_match('/FROM pm_myskill\s+WHERE skillid = (\d+) AND uid = (\d+) AND petid = (\d+)/', $sql, $m)) {
            foreach (self::$learned as $row) {
                if ($row['skillid'] === (int)$m[1] && $row['uid'] === (int)$m[2] && $row['petid'] === (int)$m[3]) return $row;
            }
            return false;
        }
        if (preg_match('/FROM pm_map WHERE id = (\d+)/', $sql, $m)) return self::$maps[(int)$m[1]] ?? false;
        if (preg_match('/FROM pm_mypm\s+WHERE uid = (\d+) AND id = (\d+) AND site < 3 AND hp > 0 AND state != 0/', $sql, $m)) {
            $pet = self::$pets[(int)$m[2]] ?? false;
            return $pet && $pet['uid'] === (int)$m[1] && $pet['site'] < 3 && $pet['hp'] > 0 && $pet['state'] !== 0 ? $pet : false;
        }
        throw new RuntimeException("Unexpected read: $sql");
    }
    public static function fetch_all($sql) {
        if (!preg_match('/FROM pm_mypm\s+WHERE uid = (\d+) AND site < 3 AND hp > 0 AND state != 0/', $sql, $m)) throw new RuntimeException("Unexpected list: $sql");
        return array_values(array_filter(self::$pets, function ($pet) use ($m) {
            return $pet['uid'] === (int)$m[1] && $pet['site'] < 3 && $pet['hp'] > 0 && $pet['state'] !== 0;
        }));
    }
    public static function query($sql) {
        self::$writes[] = $sql;
        if (strpos($sql, 'UPDATE pm_myskill') === 0) {
            if (!preg_match('/WHERE id = (\d+) AND uid = (\d+) AND petid = (\d+) AND skillnum > 0/', $sql, $m)) throw new RuntimeException('PP write must select one owned, usable record');
            foreach (self::$learned as &$row) {
                if ($row['id'] === (int)$m[1] && $row['uid'] === (int)$m[2] && $row['petid'] === (int)$m[3] && $row['skillnum'] > 0) $row['skillnum']--;
            }
            unset($row);
        } elseif (preg_match('/UPDATE pm_mypm SET (hp|site) = (\d+) WHERE id = (\d+)/', $sql, $m)) {
            self::$pets[(int)$m[3]][$m[1]] = (int)$m[2];
        } elseif (strpos($sql, 'UPDATE pm_usersdata') === 0) {
            if (preg_match('/SET hp = (\d+)/', $sql, $m)) $GLOBALS['user']['hp'] = (int)$m[1];
            else $GLOBALS['user']['npcid'] = 25;
        } else {
            throw new RuntimeException("Unexpected write: $sql");
        }
    }
}

function reset_battle() {
    $GLOBALS['_G'] = ['uid' => 7, 'username' => 'test-player'];
    $GLOBALS['input'] = ['skill_id' => 4, 'map_id' => 2, 'pokemon_id' => 11];
    $GLOBALS['user'] = ['npcid' => 25, 'level' => 10, 'hp' => 100, 'hpg' => 100, 'strength' => 1];
    $GLOBALS['speed'] = 20; // Equal speed avoids random evasion in either attack order.
    $GLOBALS['counter_damage'] = 1;
    $GLOBALS['damage_calls'] = $GLOBALS['counter_calls'] = $GLOBALS['generated'] = $GLOBALS['reward_calls'] = 0;
    DB::$pets = [
        10 => ['id' => 10, 'uid' => 7, 'species_id' => 1, 'hp' => 100, 'state' => 1, 'site' => 1, 'level' => 10, 'nickname' => 'Active'],
        11 => ['id' => 11, 'uid' => 7, 'species_id' => 2, 'hp' => 100, 'state' => 1, 'site' => 2, 'level' => 10, 'nickname' => 'Reserve'],
    ];
    DB::$skills = [4 => ['id' => 4, 'name' => 'Learned move', 'power' => 40, 'max_uses' => 10, 'element' => 'normal', 'category' => '物攻']];
    DB::$learned = [ ['id' => 20, 'skillid' => 4, 'uid' => 7, 'petid' => 10, 'skillnum' => 2] ];
    DB::$maps = [2 => ['id' => 2, 'is_enabled' => 1]];
    DB::$writes = [];
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function response($function, $code, $message = null) {
    try { $function(); } catch (BattleResponse $response) {
        check($response->getCode() === $code, 'Unexpected response code: ' . $response->getMessage());
        if ($message !== null) check($response->getMessage() === $message, 'Unexpected response message');
        return $response->data;
    }
    throw new RuntimeException('Endpoint returned without a response');
}
function rejected_turn($code, $message) {
    response('api_use_skill', $code, $message);
    check(DB::$writes === [], 'Rejected skill changed stored state');
    check($GLOBALS['damage_calls'] === 0 && $GLOBALS['counter_calls'] === 0 && $GLOBALS['reward_calls'] === 0, 'Rejected skill produced combat effects');
}
function run_case($name, $test) { reset_battle(); $test(); echo "PASS $name\n"; }

run_case('Unknown skill is rejected', function () {
    $GLOBALS['input']['skill_id'] = 99;
    rejected_turn(404, 'Skill not found');
});
run_case('Unlearned skill is rejected', function () {
    DB::$learned = [];
    rejected_turn(400, 'Pokemon has not learned this skill');
});
run_case('Another pet skill is rejected', function () {
    DB::$learned[0]['petid'] = 11;
    rejected_turn(400, 'Pokemon has not learned this skill');
});
run_case('Another user skill is rejected', function () {
    DB::$learned[0]['uid'] = 8;
    rejected_turn(400, 'Pokemon has not learned this skill');
});
foreach ([20, 19] as $speed) {
    run_case("Depleted PP is rejected before combat at speed $speed", function () use ($speed) {
        $GLOBALS['speed'] = $speed;
        DB::$learned[0]['skillnum'] = 0;
        rejected_turn(400, 'Skill PP is depleted');
    });
    run_case("Owned skill consumes exactly one PP at speed $speed", function () use ($speed) {
        $GLOBALS['speed'] = $speed;
        $data = response('api_use_skill', 200);
        check($data['status'] === 'active' && $GLOBALS['damage_calls'] === 1 && $GLOBALS['counter_calls'] === 1, 'Valid combat did not finish a turn');
        check(DB::$learned[0]['skillnum'] === 1 && $GLOBALS['user']['hp'] === 90 && DB::$pets[10]['hp'] === 99, 'Combat state or PP incorrect');
    });
    run_case("Victory consumes PP and grants one reward at speed $speed", function () use ($speed) {
        $GLOBALS['speed'] = $speed;
        $GLOBALS['user']['hp'] = 1;
        $data = response('api_use_skill', 200);
        check($data['status'] === 'victory' && $data['battle_over'] === true, 'Victory response changed');
        check(DB::$learned[0]['skillnum'] === 1 && $GLOBALS['reward_calls'] === 1 && $GLOBALS['user']['npcid'] === 0, 'Victory state or rewards incorrect');
    });
}
run_case('Unlimited skill still needs ownership', function () {
    DB::$skills[4]['max_uses'] = 0;
    DB::$learned = [];
    rejected_turn(400, 'Pokemon has not learned this skill');
});
run_case('Owned unlimited skill remains usable without PP', function () {
    DB::$skills[4]['max_uses'] = 0;
    DB::$learned[0]['skillnum'] = 0;
    response('api_use_skill', 200);
    check(DB::$learned[0]['skillnum'] === 0 && $GLOBALS['damage_calls'] === 1, 'Unlimited skill behavior changed');
});
run_case('One remaining PP is consumed without touching another record', function () {
    DB::$learned[0]['skillnum'] = 1;
    DB::$learned[] = ['id' => 21, 'skillid' => 4, 'uid' => 7, 'petid' => 11, 'skillnum' => 3];
    response('api_use_skill', 200);
    check(DB::$learned[0]['skillnum'] === 0 && DB::$learned[1]['skillnum'] === 3, 'PP update changed an unrelated skill');
});
run_case('Pet defeated before attacking retains PP', function () {
    $GLOBALS['speed'] = 19;
    $GLOBALS['counter_damage'] = 100;
    $data = response('api_use_skill', 200);
    check($data['status'] === 'defeat' && $data['can_continue_switch'] === true, 'Defeat response changed');
    check(DB::$learned[0]['skillnum'] === 2 && $GLOBALS['damage_calls'] === 0, 'Pet that could not attack consumed PP');
});
run_case('Basic attack works without learned skills', function () {
    $GLOBALS['input']['skill_id'] = 0;
    DB::$learned = [];
    response('api_use_skill', 200);
    check($GLOBALS['damage_calls'] === 1, 'Basic attack failed');
});
run_case('Fainted pet cannot attack', function () {
    DB::$pets[10]['hp'] = 0;
    rejected_turn(400, '你的宠物已晕倒，请先更换宠物！');
});
run_case('Pet in critical state cannot attack', function () {
    DB::$pets[10]['state'] = 0;
    rejected_turn(400, '你的宠物已晕倒，请先更换宠物！');
});
run_case('Disabled map cannot start an encounter', function () {
    $GLOBALS['user']['npcid'] = 0;
    DB::$maps[2]['is_enabled'] = 0;
    response('api_start_battle', 400, 'Map is disabled');
    check(DB::$writes === [] && $GLOBALS['generated'] === 0, 'Disabled map created an encounter');
});
run_case('Missing map retains the existing error', function () {
    $GLOBALS['user']['npcid'] = 0;
    DB::$maps = [];
    response('api_start_battle', 404, 'Map not found');
    check(DB::$writes === [], 'Missing map changed state');
});
run_case('Enabled map starts an encounter', function () {
    $GLOBALS['user']['npcid'] = 0;
    response('api_start_battle', 200);
    check($GLOBALS['generated'] === 1 && $GLOBALS['user']['npcid'] === 25, 'Enabled map failed to start');
});
run_case('Healthy active pet cannot use passive replacement', function () {
    response('api_replace_pokemon', 400, '当前宠物尚未倒下，请使用主动切换');
    check(DB::$writes === [], 'Healthy passive replacement changed sites');
});
foreach ([11, 0] as $id) {
    run_case("Fainted pet can passively replace with selection $id", function () use ($id) {
        DB::$pets[10]['hp'] = 0;
        $GLOBALS['input']['pokemon_id'] = $id;
        $data = response('api_replace_pokemon', 200);
        check(DB::$pets[10]['site'] === 2 && DB::$pets[11]['site'] === 1 && $data['my_pokemon']['id'] === 11, 'Passive replacement did not swap pets');
        check($GLOBALS['counter_calls'] === 0 && $data['battle_over'] === false, 'Passive replacement changed combat behavior');
    });
}
run_case('Passive replacement cannot select another user pet', function () {
    DB::$pets[10]['hp'] = 0;
    DB::$pets[11]['uid'] = 8;
    response('api_replace_pokemon', 400, '指定的宠物不可用');
    check(DB::$writes === [], 'Unauthorized pet changed state');
});
