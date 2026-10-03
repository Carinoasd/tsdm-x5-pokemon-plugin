<?php
/** Execute both healing endpoints and the real HP helpers without a live forum. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('IN_DISCUZ', true);
require __DIR__ . '/../../plugin/api/utils.php';

$wanted = ['api_heal_pokemon', 'api_heal_and_flee'];
$tokens = token_get_all(file_get_contents(__DIR__ . '/../../plugin/api/user.php'));
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

class HealingResponse extends RuntimeException
{
    public $data;
    public function __construct($message, $code, $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}
function api_success($data) { throw new HealingResponse('success', 200, $data); }
function api_error($message, $code) { throw new HealingResponse($message, $code); }
function require_login() {}
function validate_uid($id) { return (int) $id; }
function validate_id($id, $name) { return (int) $id; }
function get_param($key, $default = null) { return 1; }
function pm_table($name) { return $name; }
function pm_sql($sql, ...$args) { return vsprintf($sql, $args); }

class DB
{
    public static $pet;
    public static $user;
    public static $equipment;
    public static $writes;
    public static $pp;

    public static function fetch_first($sql)
    {
        if (str_contains($sql, 'FROM pm_mypm')) return self::$pet;
        if (str_contains($sql, 'FROM pm_usersdata')) return self::$user;
        if (str_contains($sql, 'SELECT hp FROM pm_data')) return ['hp' => 50];
        if (str_contains($sql, 'SELECT i.equipment') || str_contains($sql, 'FROM pm_itemdata')) {
            return ['equipment' => json_encode(['hp' => self::$equipment])];
        }
        if (str_contains($sql, 'FROM pm_myitem')) return ['itemid' => 1];
        if (str_contains($sql, 'FROM pm_skill')) return ['max_uses' => 25];
        throw new RuntimeException('Unexpected read: ' . $sql);
    }

    public static function fetch_all($sql)
    {
        if (str_contains($sql, 'FROM pm_myskill')) return [['skillid' => 4]];
        throw new RuntimeException('Unexpected list: ' . $sql);
    }

    public static function query($sql)
    {
        self::$writes[] = $sql;
        if (preg_match("/UPDATE pm_mypm SET hp=(\d+), state='1', statetime=(\d+) WHERE id=1 AND uid=7/", $sql, $m)) {
            self::$pet['hp'] = (int) $m[1];
            self::$pet['state'] = 1;
            self::$pet['statetime'] = (int) $m[2];
        } elseif (preg_match('/UPDATE pm_mypm SET hp=(\d+) WHERE id=1 AND uid=7/', $sql, $m)) {
            self::$pet['hp'] = (int) $m[1];
        } elseif (str_contains($sql, 'UPDATE pm_usersdata')) {
            self::$user['npcid'] = 0;
        } elseif (str_contains($sql, 'UPDATE pm_myskill SET skillnum=25 WHERE skillid=4 AND uid=7 AND petid=1')) {
            self::$pp = 25;
        } else {
            throw new RuntimeException('Unexpected write: ' . $sql);
        }
    }
}

function fixture($state, $bonus, $flee, $hp = 10)
{
    $GLOBALS['_G'] = ['uid' => 7];
    DB::$pet = [
        'id' => 1, 'uid' => 7, 'species_id' => 1, 'nickname' => 'Test',
        'hp' => $hp, 'state' => $state, 'statetime' => 123, 'site' => 1,
        'level' => 50, 'hpg' => 0, 'hpn' => 0, 'is_shiny' => 0,
        'equipmentid1' => $bonus ? 1 : 0,
    ];
    DB::$equipment = $bonus;
    DB::$user = ['npcid' => $flee ? 25 : 0];
    DB::$writes = [];
    DB::$pp = 1;
}

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$passed = 0;
$failed = 0;
function run_case($name, $body)
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name: {$e->getMessage()}\n";
    }
}

function response($flee, $code = 200)
{
    try {
        if ($flee) api_heal_and_flee();
        else api_heal_pokemon();
    } catch (HealingResponse $e) {
        check($e->getCode() === $code, 'Unexpected response ' . $e->getMessage());
        return $e->data;
    }
    throw new RuntimeException('Endpoint did not respond');
}

foreach ([false, true] as $flee) {
    $endpoint = $flee ? 'heal_and_flee' : 'heal';
    foreach ([0, 2, 3, 4, 5, 6, 7, 11, 15, 20, 21, 22] as $state) {
        run_case("$endpoint restores state $state and full HP including equipment once", function () use ($state, $flee) {
            fixture($state, 20, $flee);
            $data = response($flee);
            check(DB::$pet['hp'] === 130 && $data['current_hp'] === 130 && $data['max_hp'] === 130, 'Expected full HP 130');
            check(DB::$pet['state'] === 1 && DB::$pet['statetime'] > 123, 'State was not cured');
            check(DB::$pp === 25, 'Skill PP was not restored');
            check(DB::$user['npcid'] === 0, 'Battle was not cleared');
        });
    }
    foreach ([[1, 0, 110], [1, 20, 130], [8, 20, 141], [10, 20, 240]] as $case) {
        run_case("$endpoint preserves healthy state {$case[0]} and equipment {$case[1]}", function () use ($case, $flee) {
            fixture($case[0], $case[1], $flee);
            $data = response($flee);
            check(DB::$pet['hp'] === $case[2] && $data['max_hp'] === $case[2], 'Incorrect healed HP');
            check(DB::$pet['state'] === $case[0] && DB::$pet['statetime'] === 123, 'Healthy state changed');
        });
    }
    run_case("$endpoint cures a fainted pet before calculating boosted HP", function () use ($flee) {
        fixture(10, 20, $flee, 0);
        response($flee);
        check(DB::$pet['hp'] === 130 && DB::$pet['state'] === 1, 'Fainted pet was not reset before healing');
    });
    run_case("$endpoint rejects another user pet without writes", function () use ($flee) {
        fixture(4, 20, $flee);
        DB::$pet['uid'] = 8;
        response($flee, 403);
        check(DB::$writes === [], 'Foreign pet was changed');
    });
    run_case("$endpoint rejects the wrong battle state without writes", function () use ($flee) {
        fixture(4, 20, !$flee);
        response($flee, 400);
        check(DB::$writes === [], 'Rejected healing made writes');
    });
}

echo "Healing tests: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
