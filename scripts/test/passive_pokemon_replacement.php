<?php
/**
 * Execute the real passive replacement endpoint against in-memory pet rows.
 * No live forum, combat formulas or concurrent requests are exercised.
 * Run: php scripts/test/passive_pokemon_replacement.php
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Load actual endpoint functions without running the Discuz dispatcher.
$wanted = ['api_replace_pokemon'];
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

class ReplacementResponse extends RuntimeException
{
    public $data;
    public function __construct($message, $code, $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}
function api_error($message, $code) { throw new ReplacementResponse($message, $code); }
function api_success($data) { throw new ReplacementResponse('success', 200, $data); }
function require_login() {}
function get_json_input() { return $GLOBALS['input']; }
function pm_table($name) { return $name; }
function pm_sql($sql, ...$args) { return vsprintf($sql, $args); }
function api_my_usersdata($uid) { return $GLOBALS['user']; }
function api_my_pokemon($username)
{
    foreach (DB::$pets as $pet) {
        if ((int) $pet['uid'] === (int) $GLOBALS['_G']['uid'] && (int) $pet['site'] === 1) return $pet;
    }
    return false;
}
function build_battle_response($user, $pokemon) { return ['my_pokemon' => $pokemon]; }
function calculate_counter_damage_legacy(...$args) { $GLOBALS['counter_calls']++; return 10; }

class DB
{
    public static $pets;
    public static $writes;
    public static $reads;

    private static function eligible($pet, $uid)
    {
        return (int) $pet['uid'] === (int) $uid && (int) $pet['site'] < 3
            && (int) $pet['hp'] > 0 && (int) $pet['state'] !== 0;
    }
    public static function fetch_first($sql)
    {
        self::$reads++;
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (!preg_match('/^SELECT \* FROM pm_mypm WHERE uid = (\d+) AND id = (\d+) AND site < 3 AND hp > 0 AND state != 0$/', $sql, $match)) {
            throw new RuntimeException('Unexpected read: ' . $sql);
        }
        foreach (self::$pets as $pet) {
            if ((int) $pet['id'] === (int) $match[2] && self::eligible($pet, $match[1])) return $pet;
        }
        return false;
    }
    public static function fetch_all($sql)
    {
        self::$reads++;
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (!preg_match('/^SELECT \* FROM pm_mypm WHERE uid = (\d+) AND site < 3 AND hp > 0 AND state != 0 ORDER BY site ASC, id ASC$/', $sql, $match)) {
            throw new RuntimeException('Unexpected list: ' . $sql);
        }
        $pets = array_values(array_filter(self::$pets, function ($pet) use ($match) {
            return self::eligible($pet, $match[1]);
        }));
        usort($pets, function ($a, $b) {
            return ((int) $a['site'] <=> (int) $b['site']) ?: ((int) $a['id'] <=> (int) $b['id']);
        });
        return $pets;
    }
    public static function query($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (!preg_match('/^UPDATE pm_mypm SET site = ([12]) WHERE id = (\d+)$/', $sql, $match)) {
            throw new RuntimeException('Unexpected write: ' . $sql);
        }
        foreach (self::$pets as &$pet) {
            if ((int) $pet['id'] === (int) $match[2]) $pet['site'] = (int) $match[1];
        }
        self::$writes[] = $sql;
    }
}

function reset_battle()
{
    $GLOBALS['_G'] = ['uid' => 7, 'username' => 'test-player'];
    $GLOBALS['input'] = ['pokemon_id' => 11];
    $GLOBALS['user'] = ['npcid' => 25, 'hp' => 90];
    $GLOBALS['counter_calls'] = 0;
    DB::$pets = [
        ['id' => 10, 'uid' => 7, 'hp' => 0, 'state' => 1, 'site' => 1, 'nickname' => 'Active'],
        ['id' => 11, 'uid' => 7, 'hp' => 80, 'state' => 1, 'site' => 2, 'nickname' => 'Reserve'],
    ];
    DB::$writes = [];
    DB::$reads = 0;
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function response($code, $message = null)
{
    try {
        api_replace_pokemon();
    } catch (ReplacementResponse $response) {
        check($response->getCode() === $code, 'Unexpected response: ' . $response->getMessage());
        if ($message !== null) check($response->getMessage() === $message, 'Unexpected response message');
        return $response->data;
    }
    throw new RuntimeException('Endpoint did not respond');
}
function rejected($message, $before_candidates = false)
{
    $snapshot = [DB::$pets, $GLOBALS['user']];
    response(400, $message);
    check(DB::$writes === [] && [DB::$pets, $GLOBALS['user']] === $snapshot, 'Rejected replacement changed state');
    check($GLOBALS['counter_calls'] === 0, 'Rejected replacement caused a counterattack');
    if ($before_candidates) check(DB::$reads === 0, 'Rejected replacement queried reserve pets');
}
function replaced($expected_id)
{
    $snapshot = DB::$pets;
    $user = $GLOBALS['user'];
    $data = response(200);
    check((int) $data['my_pokemon']['id'] === $expected_id, 'Wrong replacement pet');
    check($data['status'] === 'active' && $data['turn'] === 0 && $data['battle_over'] === false
        && $data['can_continue_switch'] === false, 'Replacement response changed');
    foreach ($snapshot as &$pet) {
        if ((int) $pet['id'] === 10) $pet['site'] = 2;
        if ((int) $pet['id'] === $expected_id) $pet['site'] = 1;
    }
    check(DB::$pets === $snapshot && count(DB::$writes) === 2, 'Replacement changed more than the two site fields');
    check($GLOBALS['user'] === $user && $GLOBALS['counter_calls'] === 0, 'Replacement changed wild state or caused a counterattack');
}
$passed = 0;
$failed = 0;
function run_case($name, $test)
{
    global $passed, $failed;
    reset_battle();
    try {
        $test();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL $name: {$error->getMessage()}\n";
    }
}

foreach ([11, 0] as $target) {
    foreach ([1, 100, '1', '100'] as $hp) {
        run_case('Healthy pet HP ' . var_export($hp, true) . " rejects target $target", function () use ($target, $hp) {
            $GLOBALS['input']['pokemon_id'] = $target;
            DB::$pets[0]['hp'] = $hp;
            rejected('当前宠物尚未倒下，请使用主动切换', true);
        });
    }
    run_case("Current pet state zero with positive HP rejects target $target", function () use ($target) {
        $GLOBALS['input']['pokemon_id'] = $target;
        DB::$pets[0]['hp'] = 100;
        DB::$pets[0]['state'] = 0;
        rejected('当前宠物尚未倒下，请使用主动切换', true);
    });
    foreach ([false, true] as $strings) {
        run_case(($strings ? 'Numeric string' : 'Integer') . " fainted pet replaces with target $target", function () use ($target, $strings) {
            $GLOBALS['input']['pokemon_id'] = $target;
            if ($strings) {
                foreach (DB::$pets as &$pet) {
                    foreach (['id', 'uid', 'hp', 'state', 'site'] as $field) $pet[$field] = (string) $pet[$field];
                }
            }
            replaced(11);
        });
    }
    run_case("Missing active pet rejects target $target", function () use ($target) {
        $GLOBALS['input']['pokemon_id'] = $target;
        DB::$pets[0]['site'] = 2;
        rejected('没有上场宠物', true);
    });
    run_case("No active battle error precedes pet eligibility for target $target", function () use ($target) {
        $GLOBALS['input']['pokemon_id'] = $target;
        $GLOBALS['user']['npcid'] = 0;
        if ($target === 0) DB::$pets[0]['site'] = 2;
        else DB::$pets[0]['hp'] = 100;
        rejected('没有进行中的战斗', true);
    });
    $invalid = ['another user' => ['uid', 8], 'box' => ['site', 3], 'fainted' => ['hp', 0], 'state zero' => ['state', 0]];
    foreach ($invalid as $name => $change) {
        run_case("Reject $name reserve with target $target", function () use ($target, $change) {
            $GLOBALS['input']['pokemon_id'] = $target;
            DB::$pets[1][$change[0]] = $change[1];
            rejected($target === 0 ? '没有可用的替补宠物' : '指定的宠物不可用');
        });
    }
}
run_case('Automatic replacement rejects no reserves', function () {
    $GLOBALS['input']['pokemon_id'] = 0;
    DB::$pets = [DB::$pets[0]];
    rejected('没有可用的替补宠物');
});
run_case('Specified missing reserve is rejected', function () {
    $GLOBALS['input']['pokemon_id'] = 99;
    rejected('指定的宠物不可用');
});
run_case('Specified fainted current pet is not an eligible replacement', function () {
    $GLOBALS['input']['pokemon_id'] = 10;
    rejected('指定的宠物不可用');
});
run_case('Automatic replacement filters invalid pets and orders numeric string IDs', function () {
    $GLOBALS['input']['pokemon_id'] = 0;
    $reserve = DB::$pets[1];
    $reserve['id'] = '9';
    DB::$pets[] = $reserve;
    foreach (['uid' => 8, 'site' => 3, 'hp' => 0, 'state' => 0] as $field => $value) {
        $invalid = $reserve;
        $invalid['id'] = count(DB::$pets) - 2;
        $invalid[$field] = $value;
        DB::$pets[] = $invalid;
    }
    replaced(9);
});
run_case('Automatic replacement orders site before ID', function () {
    $GLOBALS['input']['pokemon_id'] = 0;
    // Existing query permits another site=1 row; preserve its ordering rule.
    $reserve = DB::$pets[1];
    $reserve['id'] = '90';
    $reserve['site'] = '1';
    DB::$pets[] = $reserve;
    replaced(90);
});

echo "Passive replacement tests: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
