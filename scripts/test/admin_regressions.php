<?php
/**
 * Exercise production admin reads, skill writes, and config dispatch with an
 * in-memory database. Subprocesses isolate handlers that terminate with exit().
 * Run: php scripts/test/admin_regressions.php
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class DB
{
    public static $pets = [];
    public static $writes = [];
    public static $forbid_writes = false;
    public static $configs = [
        'medical_price' => ['key' => 'medical_price', 'value' => '10', 'data_type' => 'integer'],
        'is_open' => ['key' => 'is_open', 'value' => '1', 'data_type' => 'boolean'],
        'ann_title' => ['key' => 'ann_title', 'value' => 'old', 'data_type' => 'string'],
    ];

    public static function fetch_first($sql)
    {
        if (preg_match('/SELECT \* from pm_mypm where `id`=\'(\d+)\'/i', $sql, $match)) {
            return self::$pets[(int) $match[1]] ?? false;
        }
        if (preg_match('/SELECT \* from pm_skill where id=\d+/i', $sql)) {
            return [
                'id' => 10, 'name' => 'old name', 'description' => 'old description',
                'level_required' => 1, 'max_uses' => 10,
                'category' => '物攻', 'element' => '普通', 'power' => 40,
            ];
        }
        if (preg_match('/SELECT \* from pm_config where `key`=\'([^\']+)\'/i', $sql, $match)) {
            return self::$configs[$match[1]] ?? false;
        }
        if ($sql === 'SELECT id from pm_skill order by id desc limit 1') {
            return ['id' => 10];
        }
        throw new RuntimeException('Unexpected read: ' . $sql);
    }

    public static function fetch_all($sql)
    {
        if (preg_match('/SELECT \* from pm_mypm where `uid`=\'(\d+)\' limit (\d+),(\d+)/i', $sql, $match)) {
            $pets = array_values(array_filter(self::$pets, function ($pet) use ($match) {
                return $pet['uid'] === (int) $match[1];
            }));
            return array_slice($pets, (int) $match[2], (int) $match[3]);
        }
        if (strpos($sql, 'SELECT * from pm_myskill ') === 0) return [];
        if ($sql === 'SELECT * from pm_config') return array_values(self::$configs);
        throw new RuntimeException('Unexpected read: ' . $sql);
    }

    public static function query($sql)
    {
        if (self::$forbid_writes) {
            throw new RuntimeException('Unexpected write before rejecting effect: ' . $sql);
        }
        self::$writes[] = $sql;
        if (preg_match('/UPDATE pm_config SET `value`=\'([^\']*)\' WHERE `key`=\'([^\']+)\'/i', $sql, $match)) {
            self::$configs[$match[2]]['value'] = stripslashes($match[1]);
        } elseif (preg_match('/INSERT INTO pm_config .* VALUES \(\'([^\']+)\', \'([^\']*)\', \'([^\']+)\'\)/', $sql, $match)) {
            self::$configs[$match[1]] = ['key' => $match[1], 'value' => stripslashes($match[2]), 'data_type' => $match[3]];
        }
        return true;
    }
}

function skill_payload($effect)
{
    return [
        'id' => 10, 'name' => 'new name', 'description' => 'new description',
        'available_pokemons' => [1], 'min_level_limit' => 2, 'use_times_limit' => 15,
        'effect' => $effect,
    ];
}

$plugin = __DIR__ . '/../../plugin';
$mode = $argv[1] ?? '';
if ($mode === 'config') {
    define('IN_DISCUZ', true);
    $_POST = [
        'action' => 'set::global_config',
        'data' => json_encode(['medical_price' => 42, 'is_open' => false, 'ann_title' => 'saved title']),
    ];
    require $plugin . '/admin/dispatch.php';
    throw new RuntimeException('Dispatcher should terminate');
}

require $plugin . '/admin/types/pokemon_type.php';
require $plugin . '/admin/types/pokemon_info.php';
require $plugin . '/admin/types/skill_type.php';
require $plugin . '/admin/routes/pokemon_info.php';
require $plugin . '/admin/routes/skill_type.php';

if ($mode === 'reject') {
    DB::$forbid_writes = true;
    $effect = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    $fn = $argv[2] === 'insert' ? 'insert_skill_type' : 'set_skill_type';
    $fn(skill_payload($effect));
    throw new RuntimeException('Unsupported effect was accepted');
}

function check_admin($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

function run_admin_child($arguments)
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start admin test subprocess');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    check_admin($status === 0 && $stderr === '', 'Child failed: ' . $stderr . $stdout);
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

// Every valid game status must survive both admin read routes without a write.
$expected_labels = [
    'critical', 'normal', 'sick1', 'sick2', 'sick3', 'hungry1', 'hungry2', 'tired',
    'excited1', 'excited2', 'excited3', 'hurt', 'happy1', 'happy2', 'happy3', 'shock',
    'self_love1', 'self_love2', 'angry1', 'angry2', 'dead', 'weak2', 'weak3',
];
foreach ($expected_labels as $state => $label) {
    $pet = array_fill_keys([
        'exp', 'good', 'ballid', 'is_shiny', 'hpg', 'atkg', 'defg', 'spatkg', 'spdefg', 'sdg',
        'hpn', 'atkn', 'defn', 'spatkn', 'spdefn', 'sdn',
        'equipmentid1', 'equipmentid2', 'equipmentid3', 'equipmentid4',
    ], 0);
    $pet += ['id' => $state + 100, 'uid' => 7, 'species_id' => 1, 'nickname' => 'test',
        'site' => 2, 'level' => 5, 'state' => (string) $state, 'sex' => 1];
    DB::$pets[$pet['id']] = $pet;
    check_admin(get_pokemon_info($pet['id'])[0]['status'] === $label, 'Wrong detail status: ' . $state);
    check_admin(translate_pokemon_status_label_to_id($label) === $state, 'Wrong reverse status: ' . $label);
    check_admin(translate_pokemon_status_label_to_id((string) $state) === $state, 'Numeric status rejected: ' . $state);
}
$before = DB::$pets;
$listed = list_pokemon_info(7, 0, count($expected_labels));
check_admin(array_column($listed, 'status') === $expected_labels, 'List changes game statuses');
check_admin(DB::$writes === [] && DB::$pets === $before, 'Admin read mutated a pet');

// Existing effect representations still translate and save through real routes.
foreach (['physical_damage' => '物攻', 'special_damage' => '特攻', 'others' => '其他'] as $kind => $category) {
    $effect = [$kind => ['fire', 65]];
    check_admin(translate_skill_type_obj_to_raw($effect) === [$category, '火', 65], 'Legacy effect changed');
    DB::$writes = [];
    set_skill_type(skill_payload($effect));
    check_admin(in_array("UPDATE pm_skill set power='65' where id=10", DB::$writes, true), 'Legacy skill update failed');
    DB::$writes = [];
    check_admin(insert_skill_type(skill_payload($effect)) === 11, 'Legacy skill insert failed');
    check_admin(count(DB::$writes) === 1 && strpos(DB::$writes[0], "'$category', '火', 65") !== false, 'Legacy insert loses effect');
}

// Reject every unsupported tagged effect on both entry points before any write.
$unsupported = [
    ['type' => 'stat_boost', 'stat' => 'attack', 'stages' => 1, 'target' => 'my_self'],
    ['type' => 'inflict_status', 'effect' => 'burn', 'chance' => 10],
    ['type' => 'heal', 'percent' => 50],
    ['type' => 'priority', 'kind' => 'normal', 'power' => 40, 'priority' => 1],
    ['type' => 'recoil', 'kind' => 'normal', 'power' => 120, 'recoil_percent' => 25],
    ['type' => 'one_hit_ko', 'kind' => 'normal'],
    ['type' => 'fixed_damage', 'damage' => 40],
    null, ['others' => ['normal']], ['others' => ['unknown', 40]],
];
foreach ($unsupported as $effect) {
    foreach (['set', 'insert'] as $operation) {
        $response = run_admin_child(['reject', $operation, json_encode($effect)]);
        check_admin($response['success'] === false && !empty($response['reason']), 'Missing rejection response');
    }
}

// Real dispatcher must return the saved configuration, including typed values.
$response = run_admin_child(['config']);
check_admin($response['success'] === true, 'Config save failed');
$saved = $response['data'][0] ?? [];
check_admin(($saved['_TYPE'] ?? '') === 'global_config', 'Config result is missing');
check_admin($saved['medical_price'] === 42 && $saved['is_open'] === false && $saved['ann_title'] === 'saved title', 'Config result is stale or has wrong types');

echo "OK: admin status reads, legacy/unsupported skill writes, and config save response\n";
