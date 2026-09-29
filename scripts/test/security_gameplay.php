<?php

/**
 * Offline regression tests executing production endpoint functions with a fake DB.
 * Run: php scripts/test/security_gameplay.php
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class GameplayResponse extends RuntimeException
{
    public $success;
    public $data;
    public function __construct($success, $data)
    {
        parent::__construct($success ? 'success' : $data);
        $this->success = $success;
        $this->data = $data;
    }
}

class DB
{
    public static $reads = [];
    public static $writes = [];
    public static $affected = 1;
    public static $equipmentOwner = false;

    public static function fetch_first($sql)
    {
        if (strpos($sql, 'SELECT id, nickname') !== false) {
            // Respect the actual WHERE clause: the old query excluded this pet.
            return strpos($sql, 'id!=') !== false ? false : self::$equipmentOwner;
        }
        if (!self::$reads) {
            throw new RuntimeException('Unexpected query: ' . $sql);
        }
        return array_shift(self::$reads);
    }

    public static function result_first($sql)
    {
        return self::fetch_first($sql);
    }

    public static function query($sql)
    {
        self::$writes[] = $sql;
    }

    public static function affected_rows()
    {
        return self::$affected;
    }
}

function require_login() {}
function get_json_input() { return $GLOBALS['input']; }
function validate_id($value, $name = '') { return (int) $value; }
function validate_uid($value) { return (int) $value; }
function validate_required_param($input, $key, $type) { return (int) $input[$key]; }
function validate_optional_param($input, $key, $default, $type, $options)
{
    return isset($input[$key]) ? (int) $input[$key] : $default;
}
function validate_int_range($value, $name, $min, $max)
{
    if ($value < $min || $value > $max) {
        api_error('Invalid ' . $name, 400);
    }
    return (int) $value;
}
function pm_table($name) { return $name; }
function pm_sql($sql, ...$args)
{
    $index = 0;
    return preg_replace_callback('/%([sd])/', function ($match) use ($args, &$index) {
        $value = $args[$index++];
        return $match[1] === 'd' ? (int) $value : "'" . addslashes($value) . "'";
    }, $sql);
}
function api_error($message, $code = 400) { throw new GameplayResponse(false, $message); }
function api_success($data) { throw new GameplayResponse(true, $data); }
function api_calculate_pokemon_max_hp($pet) { return 100; }
function calculate_pokemon_full_stats($pet, $base)
{
    $stats = ['base_hp' => 100, 'total_hp' => 100];
    foreach (['hp', 'atk', 'def', 'spatk', 'spdef', 'speed'] as $stat) {
        $stats['equipment_' . $stat] = 0;
        $stats['base_' . $stat] = 100;
        $stats['total_' . $stat] = 100;
    }
    return $stats;
}

// Tokenize to load only selected real functions, without running request dispatch.
function load_production_functions($path, $names)
{
    $tokens = token_get_all(file_get_contents($path));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $name_index = $i + 1;
        while (is_array($tokens[$name_index]) && $tokens[$name_index][0] === T_WHITESPACE) {
            $name_index++;
        }
        if (!is_array($tokens[$name_index]) || !in_array($tokens[$name_index][1], $names, true)) {
            continue;
        }
        $source = '';
        $depth = 0;
        $started = false;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                $source .= $token[0] === T_DIR ? var_export(dirname($path), true) : $token[1];
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
            } else {
                $source .= $token;
                if ($token === '{') {
                    $depth++;
                    $started = true;
                } elseif ($token === '}') {
                    $depth--;
                    if ($started && $depth === 0) {
                        break;
                    }
                }
            }
        }
        eval($source);
    }
    foreach ($names as $name) {
        if (!function_exists($name)) {
            throw new RuntimeException('Production function not loaded: ' . $name);
        }
    }
}

$root = dirname(__DIR__, 2);
load_production_functions($root . '/plugin/api/pokemon.php', ['api_equip_item']);
load_production_functions($root . '/plugin/api/evolution.php', [
    'api_evolve_pokemon', 'check_evolution_conditions', 'check_user_has_item', 'calculate_max_hp',
]);
load_production_functions($root . '/plugin/api/shop.php', [
    'api_buy_item', 'api_buy_pet', 'add_item_to_inventory', 'debit_shop_money',
]);
$_G = ['uid' => 1];

function verify($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function run_case($name, $input, $reads, $function, $success, $check, $affected = 1, $owner = false)
{
    $GLOBALS['input'] = $input;
    DB::$reads = $reads;
    DB::$writes = [];
    DB::$affected = $affected;
    DB::$equipmentOwner = $owner;
    try {
        $function();
        throw new RuntimeException('Endpoint did not return a response');
    } catch (GameplayResponse $response) {
        verify($response->success === $success, $name . ': unexpected response ' . $response->getMessage());
        $check($response);
    }
    echo "PASS $name\n";
}
function no_writes($response)
{
    verify(DB::$writes === [], 'Rejected action must not mutate state');
}

$pet = ['id' => 7, 'uid' => 1, 'species_id' => 1, 'level' => 20, 'hp' => 100,
    'equipmentid1' => 0, 'equipmentid2' => 0, 'equipmentid3' => 0, 'equipmentid4' => 0];
$equipment = ['id' => 9, 'type' => 5, 'nums' => 1, 'name' => 'Test equipment'];
$equipped_pet = $pet;
$equipped_pet['equipmentid1'] = 9;
run_case('Reject repeated equipment on current pet', ['pokemon_id' => 7, 'myitem_id' => 9, 'slot_index' => 1],
    [$equipped_pet, $equipment], 'api_equip_item', false, 'no_writes', 1, ['id' => 7, 'nickname' => 'Current pet']);
run_case('Reject equipment used by another pet', ['pokemon_id' => 7, 'myitem_id' => 9],
    [$pet, $equipment], 'api_equip_item', false, 'no_writes', 1, ['id' => 8, 'nickname' => 'Other pet']);
run_case('Equip available owned item', ['pokemon_id' => 7, 'myitem_id' => 9],
    [$pet, $equipment, $equipped_pet, []], 'api_equip_item', true, function ($response) {
        verify($response->data['slot_index'] === 0, 'Should use first empty slot');
        verify(count(DB::$writes) === 2, 'Equipment and HP should be updated');
    });

$evolution = ['from_id' => 1, 'to_id' => 2, 'method' => 'item', 'condition_value' => 10];
$stone = ['id' => 11, 'uid' => 1, 'itemid' => 10, 'nums' => 1];
$base = ['id' => 2, 'name' => 'New form', 'hp' => 60];
foreach ([false, null] as $missing) {
    run_case('Reject missing evolution item (' . gettype($missing) . ')', ['pet_id' => 7],
        [$pet, $evolution, $missing, ['name' => 'Stone']], 'api_evolve_pokemon', false, 'no_writes');
}
run_case('Consume stone before evolution', ['pet_id' => 7], [$pet, $evolution, $stone, $base],
    'api_evolve_pokemon', true, function ($response) {
        verify($response->data['new_form'] === 2, 'Evolution should complete');
        verify(count(DB::$writes) === 3, 'Stone decrement, cleanup, and evolution required');
        verify(strpos(DB::$writes[0], 'nums = nums - 1') !== false, 'Stone must be consumed before evolution');
        verify(strpos(DB::$writes[0], 'nums > 0') !== false, 'Consumption must guard stock');
    });
run_case('Reject unavailable stone at consumption', ['pet_id' => 7], [$pet, $evolution, $stone, $base],
    'api_evolve_pokemon', false, function ($response) {
        verify(count(DB::$writes) === 1 && strpos(DB::$writes[0], 'pm_myitem') !== false,
            'Failed item debit must not update Pokemon');
    }, 0);
$level_evolution = $evolution;
$level_evolution['method'] = 'level';
$level_evolution['condition_value'] = 10;
run_case('Level evolution does not consume an item', ['pet_id' => 7], [$pet, $level_evolution, $base],
    'api_evolve_pokemon', true, function ($response) {
        verify(count(DB::$writes) === 1 && strpos(DB::$writes[0], 'pm_mypm') !== false,
            'Level evolution should only update Pokemon');
    });

run_case('Reject insufficient shop funds', ['item_id' => 3], [['money' => 20], ['money' => 10]],
    'api_buy_item', false, 'no_writes');
run_case('Reject failed shop debit before inventory grant', ['item_id' => 3], [['money' => 20], ['money' => 100]],
    'api_buy_item', false, function ($response) {
        verify(count(DB::$writes) === 1 && strpos(DB::$writes[0], 'money >= 20') !== false,
            'Failed guarded debit must not grant inventory');
    }, 0);
run_case('Buy paid item', ['item_id' => 3], [['money' => 20], ['money' => 100], false],
    'api_buy_item', true, function ($response) {
        verify($response->data['remaining_money'] === 80, 'Purchase balance should be correct');
        verify(count(DB::$writes) === 2 && strpos(DB::$writes[1], 'INSERT INTO pm_myitem') !== false,
            'Inventory should be granted after debit');
    });
run_case('Buy multiple items into existing inventory', ['item_id' => 3, 'quantity' => 2],
    [['money' => 20], ['money' => 100], ['id' => 12], ['id' => 12]],
    'api_buy_item', true, function ($response) {
        verify($response->data['remaining_money'] === 60 && $response->data['items_purchased'] === 2,
            'Quantity must be reflected in cost and grant');
        verify(count(DB::$writes) === 3, 'Two items should be granted after one debit');
    });
run_case('Buy free item without a changed-row debit', ['item_id' => 3], [['money' => 0], ['money' => 100], false],
    'api_buy_item', true, function ($response) {
        verify(count(DB::$writes) === 1 && strpos(DB::$writes[0], 'INSERT INTO pm_myitem') !== false,
            'Free purchase should grant without relying on affected rows');
    }, 0);

$shop_pet = ['id' => 2, 'name' => 'Test pet', 'money' => 50, 'sex' => 0, 'xs' => 'fire',
    'hp' => 50, 'atk' => 50, 'def' => 50, 'spatk' => 50, 'spdef' => 50, 'speed' => 50];
run_case('Reject pet purchase when storage is full', ['pokemon_type_id' => 2],
    [$shop_pet, ['money' => 100, 'boxnum' => 10], 10], 'api_buy_pet', false, 'no_writes');
run_case('Reject failed pet debit before creation', ['pokemon_type_id' => 2],
    [$shop_pet, ['money' => 100, 'boxnum' => 10], 0, 0], 'api_buy_pet', false, function ($response) {
        verify(count(DB::$writes) === 1 && strpos(DB::$writes[0], 'money >= 50') !== false,
            'Failed guarded debit must not create Pokemon');
    }, 0);
run_case('Buy paid pet', ['pokemon_type_id' => 2],
    [$shop_pet, ['money' => 100, 'boxnum' => 10], 0, 0], 'api_buy_pet', true, function ($response) {
        verify($response->data['remaining_money'] === 50, 'Pet balance should be correct');
        verify(count(DB::$writes) === 2 && strpos(DB::$writes[1], 'INSERT INTO pm_mypm') !== false,
            'Pokemon should be created after debit');
    });
$free_pet = $shop_pet;
$free_pet['money'] = 0;
run_case('Buy free pet without a changed-row debit', ['pokemon_type_id' => 2],
    [$free_pet, ['money' => 100, 'boxnum' => 10], 0, 0], 'api_buy_pet', true, function ($response) {
        verify(count(DB::$writes) === 1 && strpos(DB::$writes[0], 'INSERT INTO pm_mypm') !== false,
            'Free pet should be created without relying on affected rows');
    }, 0);
$negative_price_pet = $shop_pet;
$negative_price_pet['money'] = -1;
run_case('Reject invalid negative pet price', ['pokemon_type_id' => 2],
    [$negative_price_pet], 'api_buy_pet', false, 'no_writes');
echo "Gameplay security regression tests passed.\n";
