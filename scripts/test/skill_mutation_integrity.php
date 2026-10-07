<?php
/** Real skill handlers under deterministic concurrent requests and write failures. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);
$tokens = token_get_all(file_get_contents(__DIR__ . '/../../plugin/api/pokemon.php'));
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $start = $i;
    while (++$i < count($tokens) && (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)) {}
    $name = $tokens[$i][1]; $depth = 0; $body = ''; $opened = false;
    for ($j = $start; $j < count($tokens); $j++) {
        $token = $tokens[$j]; $body .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $opened = true; }
        elseif ($token === '}' && --$depth === 0 && $opened) break;
    }
    if (in_array($name, ['api_learn_skill', 'api_forget_skill', 'pokemon_can_learn_skill'], true)) eval($body);
    $i = $j;
}
class SkillResponse extends RuntimeException
{
    public $error_code;
    public function __construct($code, $error_code = null) { parent::__construct('response', $code); $this->error_code = $error_code; }
}
function api_error($message, $code = 400, $debug = null, $error_code = null) { throw new SkillResponse($code, $error_code); }
function api_success($data) { throw new SkillResponse(200); }
function require_login() {}
function validate_id($value, $name = '') { return (int)$value; }
function validate_uid($uid) { return (int)$uid; }
function get_json_input() { return $GLOBALS['input']; }
function pm_table($table) { return $table; }
function pm_sql($sql, ...$args) { return vsprintf($sql, $args); }
function api_my_usersdata($uid) { return DB::$user = ['uid' => $uid, 'npcid' => 0]; }
class DB
{
    public static $user, $pet, $skills, $catalog, $race, $race_on, $snapshot, $fail;
    public static $transaction = false, $locked = false;
    public static function reset($count = 3)
    {
        self::$user = ['uid' => 7, 'npcid' => 0];
        self::$pet = ['id' => 11, 'uid' => 7, 'species_id' => 1, 'level' => 20];
        self::$skills = [];
        for ($i = 1; $i <= $count; $i++) self::$skills[] = self::learned($i);
        self::$catalog = [];
        for ($i = 1; $i <= 9; $i++) self::$catalog[$i] = ['id' => $i, 'name' => 'Skill ' . $i,
            'max_uses' => 20, 'level_required' => 5, 'available_pokemons' => '1', 'element' => '普通', 'category' => '物攻'];
        self::$race = self::$snapshot = self::$fail = null;
        self::$race_on = ''; self::$transaction = self::$locked = false;
        $GLOBALS['_G'] = ['uid' => 7];
        $GLOBALS['input'] = ['pokemon_id' => 11, 'skill_id' => 4];
    }
    public static function learned($id) { return ['uid' => 7, 'petid' => 11, 'skillid' => $id, 'skillnum' => 20]; }
    private static function race($point)
    {
        if (self::$race && ($point === 'lock' || (!self::$locked && $point === self::$race_on))) {
            $callback = self::$race; self::$race = null; $callback();
            // A transaction waiting for the account lock cannot roll back the
            // competing request that committed before it acquired that lock.
            if (self::$transaction) self::$snapshot = [self::$user, self::$pet, self::$skills];
        }
    }
    public static function fetch_first($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (strpos($sql, 'FROM pm_usersdata') !== false) {
            if (strpos($sql, 'FOR UPDATE') !== false) { self::race('lock'); self::$locked = true; return self::$user; }
            $row = self::$user; self::race('account'); return $row;
        }
        if (strpos($sql, 'FROM pm_mypm') !== false) {
            return preg_match('/id = (\d+) AND uid = (\d+)/', $sql, $m)
                && (int)$m[1] === self::$pet['id'] && (int)$m[2] === self::$pet['uid'] ? self::$pet : false;
        }
        if (strpos($sql, 'FROM pm_skill ') !== false) {
            preg_match('/WHERE id = (\d+)/', $sql, $m); return self::$catalog[(int)$m[1]] ?? false;
        }
        if (strpos($sql, 'FROM pm_myskill') !== false) {
            preg_match('/petid\s*=\s*(\d+) AND skillid\s*=\s*(\d+)/', $sql, $m);
            $found = false;
            foreach (self::$skills as $row) if ($row['petid'] === (int)$m[1] && $row['skillid'] === (int)$m[2]
                && (strpos($sql, 'uid') === false || $row['uid'] === 7)) $found = $row;
            self::race('existing'); return $found;
        }
        throw new RuntimeException('Unexpected read: ' . $sql);
    }
    public static function result_first($sql)
    {
        $count = count(array_filter(self::$skills, function ($row) use ($sql) {
            return $row['petid'] === 11 && (strpos($sql, 'uid') === false || $row['uid'] === 7);
        }));
        self::race('count'); return $count;
    }
    public static function query($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if ($sql === 'START TRANSACTION') { self::$snapshot = [self::$user, self::$pet, self::$skills]; self::$transaction = true; return; }
        if ($sql === 'COMMIT') { self::$transaction = self::$locked = false; self::$snapshot = null; return; }
        if ($sql === 'ROLLBACK') {
            if (self::$snapshot) [self::$user, self::$pet, self::$skills] = self::$snapshot;
            self::$transaction = self::$locked = false; self::$snapshot = null; return;
        }
        if (preg_match('/^INSERT INTO pm_myskill .*VALUES \((\d+), (\d+), (\d+), (\d+)\)$/', $sql, $m)) {
            self::$skills[] = ['uid' => (int)$m[1], 'petid' => (int)$m[2], 'skillid' => (int)$m[3], 'skillnum' => (int)$m[4]];
        } elseif (preg_match('/^DELETE FROM pm_myskill WHERE petid\s*=\s*(\d+) AND skillid\s*=\s*(\d+)/', $sql, $m)) {
            self::$skills = array_values(array_filter(self::$skills, function ($row) use ($m, $sql) {
                return $row['petid'] !== (int)$m[1] || $row['skillid'] !== (int)$m[2]
                    || (strpos($sql, 'uid') !== false && $row['uid'] !== 7);
            }));
        } else throw new RuntimeException('Unexpected write: ' . $sql);
        if (self::$fail) throw new RuntimeException('Injected write failure');
    }
}
function request_skill($handler)
{
    try { $handler(); } catch (SkillResponse $response) { return $response; }
    throw new RuntimeException('No response');
}
$passed = $failed = 0;
function check_skill($condition, $label)
{
    if ($condition) { $GLOBALS['passed']++; echo "PASS $label\n"; }
    else { $GLOBALS['failed']++; echo "FAIL $label\n"; }
}
DB::reset(); DB::$race_on = 'count'; DB::$race = function () { DB::$skills[] = DB::learned(5); };
$r = request_skill('api_learn_skill');
check_skill($r->error_code === 'skill_slots_full' && count(DB::$skills) === 4, 'Competing learns cannot create a fifth skill');
check_skill(!DB::$transaction, 'Full-slot rejection releases its lock');
DB::reset(0); DB::$race_on = 'existing'; DB::$race = function () { DB::$skills[] = DB::learned(4); };
$r = request_skill('api_learn_skill');
check_skill($r->error_code === 'skill_already_learned' && count(DB::$skills) === 1, 'Competing requests cannot learn the same skill twice');
foreach (['api_learn_skill', 'api_forget_skill'] as $handler) {
    DB::reset(); if ($handler === 'api_forget_skill') $GLOBALS['input']['skill_id'] = 1;
    $before = DB::$skills; DB::$race_on = 'account'; DB::$race = function () { DB::$user['npcid'] = 129; };
    $r = request_skill($handler);
    check_skill($r->error_code === 'skill_battle_restricted' && DB::$skills === $before, "$handler rechecks a battle that started while waiting");
    check_skill(!DB::$transaction && DB::$user['npcid'] === 129, "$handler rejection preserves the competing battle and closes its transaction");
    DB::reset(); if ($handler === 'api_forget_skill') $GLOBALS['input']['skill_id'] = 1;
    $before = DB::$skills; DB::$fail = true;
    try { $handler(); } catch (RuntimeException $error) {}
    check_skill(DB::$skills === $before && !DB::$transaction, "$handler rolls back a failed write");
    DB::reset(); DB::$pet['uid'] = 8; $before = DB::$skills;
    $r = request_skill($handler);
    check_skill($r->getCode() === 404 && DB::$skills === $before && !DB::$transaction, "$handler rejects a foreign pet without writes");
}
DB::reset(); $r = request_skill('api_learn_skill');
check_skill($r->getCode() === 200 && count(DB::$skills) === 4 && !DB::$transaction, 'Valid learn commits the fourth skill at full PP');
DB::reset(); $GLOBALS['input']['skill_id'] = 1; DB::$skills[0]['skillnum'] = 19;
$r = request_skill('api_forget_skill');
check_skill($r->error_code === 'skill_pp_not_full' && count(DB::$skills) === 3 && !DB::$transaction, 'Partial PP rejection keeps its stable error code');
DB::reset(); $GLOBALS['input']['skill_id'] = 1;
$r = request_skill('api_forget_skill');
check_skill($r->getCode() === 200 && count(DB::$skills) === 2 && !DB::$transaction, 'Valid forget removes one full-PP skill and commits');
foreach ([['level_required', 99, 'skill_level_not_met'], ['available_pokemons', '2', 'skill_not_learnable']] as $case) {
    DB::reset(); DB::$catalog[4][$case[0]] = $case[1];
    $r = request_skill('api_learn_skill');
    check_skill($r->error_code === $case[2] && count(DB::$skills) === 3 && !DB::$transaction, 'Preserves rejection contract: ' . $case[2]);
}
DB::reset(); unset(DB::$catalog[4]);
check_skill(request_skill('api_learn_skill')->error_code === 'skill_not_found' && !DB::$transaction, 'Missing skill keeps its error code and rolls back');
DB::reset();
check_skill(request_skill('api_forget_skill')->error_code === 'skill_not_learned' && !DB::$transaction, 'Unlearned skill keeps its error code and rolls back');
echo "Skill mutation integrity: $passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
