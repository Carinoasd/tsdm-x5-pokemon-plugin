<?php
/** Real admin dispatch regression tests with an isolated in-memory SQL adapter. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class DB
{
    public static $tables, $writes = [], $last_id = 0;

    public static function fetch_all($sql)
    {
        if (!preg_match('/^SELECT (?:\*|id|id, power) from (pm_effect|pm_skill)(.*)$/i', trim($sql), $match)) {
            throw new RuntimeException('Unexpected read: ' . $sql);
        }
        $rows = array_values(self::$tables[$match[1]]);
        if (preg_match('/where `?id`?\s*=\s*\'?(\d+)/i', $match[2], $id)) {
            $rows = array_values(array_filter($rows, function ($row) use ($id) { return (int) $row['id'] === (int) $id[1]; }));
        }
        if (preg_match('/where effect_id=(\d+)/i', $match[2], $id)) {
            $rows = array_values(array_filter($rows, function ($row) use ($id) { return (int) $row['effect_id'] === (int) $id[1]; }));
        }
        if (preg_match("/where code=('(?:\\\\.|[^'\\\\])*')/i", $match[2], $code)) {
            $value = self::value($code[1]);
            $rows = array_values(array_filter($rows, function ($row) use ($value) { return strcasecmp($row['code'], $value) === 0; }));
        }
        if (stripos($match[2], 'order by id desc') !== false) {
            usort($rows, function ($a, $b) { return (int) $b['id'] <=> (int) $a['id']; });
        }
        if (preg_match('/limit (\d+),(\d+)/i', $match[2], $limit)) {
            $rows = array_slice($rows, (int) $limit[1], (int) $limit[2]);
        }
        return $rows;
    }

    public static function fetch_first($sql) { return self::fetch_all($sql)[0] ?? false; }
    public static function result_first($sql)
    {
        if (strcasecmp($sql, 'SELECT count(*) from pm_effect') === 0) return count(self::$tables['pm_effect']);
        if (preg_match('/^SELECT count\(\*\) from pm_skill where effect_id=(\d+)$/i', $sql, $match)) {
            return count(array_filter(self::$tables['pm_skill'], function ($row) use ($match) { return (int) $row['effect_id'] === (int) $match[1]; }));
        }
        throw new RuntimeException('Unexpected scalar read: ' . $sql);
    }

    public static function value($raw)
    {
        $raw = trim($raw);
        if (!preg_match("/^(?:'(?:\\\\.|[^'\\\\])*'|-?\d+)$/s", $raw)) throw new RuntimeException('Malformed SQL value: ' . $raw);
        return $raw[0] === "'" ? stripslashes(substr($raw, 1, -1)) : (int) $raw;
    }

    public static function query($sql)
    {
        self::$writes[] = $sql;
        $literal = "(?:'(?:\\\\.|[^'\\\\])*'|-?\d+)";
        if (preg_match('/^UPDATE (pm_effect|pm_skill) set (.*?) where id=(\d+)$/is', trim($sql), $match)) {
            $assignment = '`?(\w+)`?\s*=\s*(' . $literal . ')';
            if (!preg_match('/^' . $assignment . '(?:\s*,\s*' . $assignment . ')*$/s', trim($match[2]))) {
                throw new RuntimeException('Malformed UPDATE: ' . $sql);
            }
            preg_match_all('/' . $assignment . '/s', $match[2], $values, PREG_SET_ORDER);
            foreach ($values as $value) self::$tables[$match[1]][(int) $match[3]][$value[1]] = self::value($value[2]);
            return true;
        }
        if (preg_match('/^INSERT INTO (pm_effect|pm_skill)\s*\((.*?)\)\s*VALUES\s*\((.*?)\)$/is', trim($sql), $match)) {
            if (!preg_match('/^' . $literal . '(?:\s*,\s*' . $literal . ')*$/s', trim($match[3]))) throw new RuntimeException('Malformed INSERT: ' . $sql);
            $columns = array_map('trim', explode(',', $match[2]));
            preg_match_all('/' . $literal . '/s', $match[3], $values);
            $row = array_combine($columns, array_map([self::class, 'value'], $values[0]));
            if (!isset($row['id'])) $row['id'] = self::$tables[$match[1]] ? max(array_keys(self::$tables[$match[1]])) + 1 : 1;
            self::$last_id = $row['id'];
            self::$tables[$match[1]][$row['id']] = $row;
            return true;
        }
        if (preg_match('/^DELETE from pm_effect where id=(\d+)$/i', trim($sql), $match)) {
            unset(self::$tables['pm_effect'][(int) $match[1]]);
            return true;
        }
        throw new RuntimeException('Unexpected write: ' . $sql);
    }
    public static function insert_id() { return self::$last_id; }
}

function fixture()
{
    return [
        'pm_effect' => [
            7 => ['id' => 7, 'code' => 'poison_touch', 'kind' => 'move', 'hooks_json' => '["on_hit"]', 'params_json' => '{"code":"status_inflict","status":"poison","chance":30}', 'description' => 'Poison', 'version' => '1'],
            8 => ['id' => 8, 'code' => 'growl', 'kind' => 'move', 'hooks_json' => '["on_after_move"]', 'params_json' => '{"code":"stages_boost","stat":"atk","stages":-1,"target":"opponent"}', 'description' => 'Lower attack', 'version' => '1'],
        ],
        'pm_skill' => [10 => ['id' => 10, 'name' => 'Test', 'available_pokemons' => 'k,1,k', 'description' => 'Original', 'level_required' => 1, 'max_uses' => 10, 'category' => '物攻', 'element' => '普通', 'power' => 40, 'effect_id' => 7]],
    ];
}

if (isset($argv[1])) {
    define('IN_DISCUZ', true);
    $input = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    DB::$tables = $input['tables'];
    $_POST = $input['request'];
    register_shutdown_function(function () {
        fwrite(STDERR, json_encode(['tables' => DB::$tables, 'writes' => DB::$writes], JSON_UNESCAPED_UNICODE));
    });
    require __DIR__ . '/../../plugin/admin/dispatch.php';
    throw new RuntimeException('Dispatcher did not exit');
}

function dispatch_effect($action, $params = [], $tables = null)
{
    $payload = ['tables' => $tables ?? fixture(), 'request' => ['action' => $action] + $params];
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, base64_encode(json_encode($payload))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start dispatch test');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);
    if ($exit_code !== 0) throw new RuntimeException('Dispatch failed: ' . $stdout . $stderr);
    return [json_decode($stdout, true, 512, JSON_THROW_ON_ERROR), json_decode($stderr, true, 512, JSON_THROW_ON_ERROR)];
}
function write_effect($action, $data, $tables = null) { return dispatch_effect($action, ['data' => json_encode($data)], $tables); }
function skill_payload()
{
    return ['id' => 10, 'name' => 'Renamed', 'available_pokemons' => [1], 'description' => 'Changed', 'min_level_limit' => 2, 'use_times_limit' => 15, 'effect' => ['physical_damage' => ['normal', 40]]];
}
function effect_payload()
{
    return ['code' => 'new_effect', 'kind' => 'move', 'hooks' => ['on_hit'], 'params' => ['code' => 'status_inflict', 'status' => 'burn', 'chance' => 25], 'description' => "Trainer's \\ effect", 'version' => 1];
}
$passed = 0;
function check_effect($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['passed']++;
}

[$response, $state] = dispatch_effect('count::effect_data');
check_effect($response['success'] && $response['data'][0]['count'] === 2 && !$state['writes'], 'Effects count routes through dispatcher');
[$response, $state] = dispatch_effect('list::effect_data', ['from' => 0, 'count' => 1]);
check_effect($response['success'] && count($response['data']) === 1 && $response['data'][0]['hooks'] === ['on_hit'], 'Effects list/pagination preserves shape');
[$response] = dispatch_effect('get::effect_data', ['id' => 7]);
check_effect($response['success'] && $response['data'][0]['params']['chance'] === 30, 'Effect get returns decoded parameters');
[$response, $state] = dispatch_effect('get::effect_data', ['id' => 999]);
check_effect(!$response['success'] && !$state['writes'], 'Missing effect returns a JSON error');

$skill_reads = [
    ['list::skill_type', ['from' => 0, 'count' => 10]],
    ['get::skill_type', ['id' => 10]],
    ['filter::skill_type', ['filters' => json_encode([['tag' => 'ID', 'operator' => 'equal', 'value' => '10']])]],
    ['filter::skill_type', ['filters' => json_encode([['tag' => '名称', 'operator' => 'equal', 'value' => 'Test']])]],
    ['filter::skill_type', ['filters' => json_encode([['tag' => '名称', 'operator' => 'equal', 'value' => '10']])]],
];
foreach ($skill_reads as [$action, $params]) {
    [$read, $state] = dispatch_effect($action, $params);
    check_effect($read['success'] && count($read['data']) === 1 && !$state['writes'], 'Skill reader succeeds: ' . $action);
    $payload = $read['data'][0];
    // Match the frontend default for an absent field, then perform a name-only save.
    $payload['effect_id'] = $payload['effect_id'] ?? 0;
    $payload['name'] = 'Renamed';
    [$saved, $state] = write_effect('set::skill_type', $payload, $state['tables']);
    check_effect($saved['success'] && $saved['data'][0]['effect_id'] === 7 && (int) $state['tables']['pm_skill'][10]['effect_id'] === 7, 'Skill read/edit/save preserves binding: ' . $action . ' ' . json_encode($params));
}

foreach ([['missing', null], ['clear', 0], ['string zero', '0'], ['existing', 7], ['numeric string', '7']] as [$name, $id]) {
    $payload = skill_payload();
    if ($name !== 'missing') $payload['effect_id'] = $id;
    [$response, $state] = write_effect('set::skill_type', $payload);
    $expected = $name === 'missing' ? 7 : (int) $id;
    check_effect($response['success'] && $response['data'][0]['effect_id'] === $expected, 'Skill update effect binding ' . $name);
    check_effect((int) $state['tables']['pm_skill'][10]['effect_id'] === $expected && $state['tables']['pm_skill'][10]['name'] === 'Renamed', 'Skill stored binding ' . $name);
}
foreach ([999, -1, 4294967296, 'bad', '1.5', 1.5, true, null, []] as $invalid) {
    foreach (['set', 'insert'] as $operation) {
        $payload = skill_payload();
        $payload['effect_id'] = $invalid;
        [$response, $state] = write_effect($operation . '::skill_type', $payload);
        check_effect(!$response['success'] && !empty($response['reason']) && !$state['writes'], 'Invalid skill binding rejected before writes: ' . json_encode($invalid));
    }
}
foreach ([0, 7] as $id) {
    $payload = skill_payload();
    $payload['effect_id'] = $id;
    [$response, $state] = write_effect('insert::skill_type', $payload);
    check_effect($response['success'] && $response['data'][0]['effect_id'] === $id, 'Skill insert exposes effect binding');
}

// Binding compatibility uses the new power, before any unrelated field is saved.
foreach ([['on_after_move', 0, true], ['on_after_move', 40, false], ['on_hit', 40, true], ['on_hit', 0, false]] as [$hook, $power, $accepted]) {
    $tables = fixture();
    $tables['pm_effect'][8]['hooks_json'] = json_encode([$hook]);
    foreach (['set', 'insert'] as $operation) {
        $payload = skill_payload();
        $payload['effect_id'] = 8;
        $payload['effect'] = ['physical_damage' => ['normal', $power]];
        [$response, $state] = write_effect($operation . '::skill_type', $payload, $tables);
        check_effect($response['success'] === $accepted && ($accepted || !$state['writes']), "Stage binding $hook power $power $operation");
    }
}
foreach ([false, true] as $explicit) {
    $payload = skill_payload();
    if ($explicit) $payload['effect_id'] = 7;
    $payload['effect'] = ['physical_damage' => ['normal', 0]];
    [$response, $state] = write_effect('set::skill_type', $payload);
    check_effect(!$response['success'] && !$state['writes'], 'Power changes cannot invalidate existing binding, even for an old client');
}

// Existing unsupported declarations remain readable and are not silently reset.
$legacy = fixture();
$legacy['pm_effect'][7]['params_json'] = '{"code":"status_inflict","status":"confusion","chance":100}';
[$response, $state] = dispatch_effect('get::effect_data', ['id' => 7], $legacy);
check_effect($response['success'] && $response['data'][0]['params']['status'] === 'confusion' && !$state['writes'], 'Legacy template reads stay lossless');
foreach (['omit', 'preserve', 'clear', 'change_power'] as $mode) {
    $payload = skill_payload();
    if ($mode !== 'omit') $payload['effect_id'] = $mode === 'clear' ? 0 : 7;
    if ($mode === 'change_power') $payload['effect'] = ['physical_damage' => ['normal', 60]];
    [$response, $state] = write_effect('set::skill_type', $payload, $legacy);
    check_effect($response['success'] === ($mode !== 'change_power'), 'Legacy binding behavior ' . $mode);
    check_effect($mode === 'change_power' ? !$state['writes'] : $response['data'][0]['effect_id'] === ($mode === 'clear' ? 0 : 7), 'Legacy binding persistence ' . $mode);
}
$payload = skill_payload();
$payload['effect_id'] = 7;
[$response, $state] = write_effect('insert::skill_type', $payload, $legacy);
check_effect(!$response['success'] && !$state['writes'], 'New skills cannot adopt unsupported legacy effects');

[$response, $state] = write_effect('insert::effect_data', effect_payload());
check_effect($response['success'] && $response['data'][0]['id'] === 9, 'New effect receives generated id and data');
check_effect($response['data'][0]['description'] === effect_payload()['description'], 'Description survives SQL quotes and backslashes');
$created = $state['tables'];
[$response, $state] = write_effect('set::effect_data', ['id' => 9, 'description' => 'Edited'], $created);
check_effect($response['success'] && $response['data'][0]['description'] === 'Edited' && $response['data'][0]['params'] === effect_payload()['params'], 'Partial effect update preserves untouched declaration');
[$response, $state] = dispatch_effect('delete::effect_data', ['id' => 9], $state['tables']);
check_effect($response['success'] && !isset($state['tables']['pm_effect'][9]), 'Unused effect can be deleted');
[$response, $state] = dispatch_effect('delete::effect_data', ['id' => 7]);
check_effect(!$response['success'] && !$state['writes'], 'Referenced effect cannot be deleted');

$payload = effect_payload();
$payload['id'] = 7;
$payload['hooks'] = ['on_after_move'];
$payload['params'] = ['code' => 'stages_boost', 'stat' => 'atk', 'stages' => -1, 'target' => 'opponent'];
[$response, $state] = write_effect('set::effect_data', $payload);
check_effect(!$response['success'] && !$state['writes'], 'Editing a template cannot invalidate a skill that references it');
$payload['hooks'] = ['on_hit'];
[$response, $state] = write_effect('set::effect_data', $payload);
check_effect($response['success'] && $response['data'][0]['params'] === $payload['params'], 'Compatible edits to referenced templates succeed');

foreach (['poison', 'burn', 'paralysis', 'sleep', 'freeze'] as $status) {
    $payload = effect_payload();
    $payload['params']['status'] = $status;
    [$response] = write_effect('insert::effect_data', $payload);
    check_effect($response['success'] && $response['data'][0]['params']['status'] === $status, 'Supported status template: ' . $status);
}
foreach (['on_after_move', 'on_hit'] as $hook) {
    $payload = effect_payload();
    $payload['hooks'] = [$hook];
    $payload['params'] = ['code' => 'stages_boost', 'stat' => 'accuracy', 'stages' => 6, 'target' => 'self'];
    [$response] = write_effect('insert::effect_data', $payload);
    check_effect($response['success'] && $response['data'][0]['hooks'] === [$hook], 'Supported stage template hook: ' . $hook);
}

$invalid_fields = [
    ['code' => ''], ['code' => '   '], ['code' => str_repeat('a', 41)], ['code' => []],
    ['code' => 'GROWL'], ['description' => []], ['description' => str_repeat('x', 256)],
    ['hooks' => []], ['hooks' => ['unknown']], ['hooks' => ['named' => 'on_hit']], ['hooks' => [null]],
    ['hooks' => ['on_after_move']], ['hooks' => ['on_hit', 'on_after_move']], ['hooks' => ['on_battle_start']],
    ['params' => []], ['params' => ['code' => []]], ['params' => ['code' => 'unknown']],
    ['params' => ['code' => 'status_inflict', 'status' => []]],
    ['params' => ['code' => 'status_inflict', 'status' => 'poison', 'chance' => 101]],
    ['params' => ['code' => 'status_inflict', 'status' => 'poison', 'chance' => 1.5]],
    ['params' => ['code' => 'status_inflict', 'status' => 'poison', 'chancee' => 10]],
    ['params' => ['code' => 'status_inflict', 'status' => 'poison', 'target' => 'self']],
    ['params' => ['code' => 'status_inflict', 'status' => 'confusion', 'chance' => 100]],
    ['params' => ['code' => 'stages_boost', 'stat' => 'atk', 'stages' => '2']],
    ['params' => ['code' => 'stages_boost', 'stat' => 'atk', 'stages' => 0]],
    ['params' => ['code' => 'stages_boost', 'stat' => 'atk', 'stages' => 7]],
    ['kind' => []], ['kind' => 'unknown'], ['kind' => 'ability'], ['kind' => 'item'], ['kind' => 'weather'],
    ['version' => 0], ['version' => 1.5], ['version' => '1'], ['version' => null],
];
foreach ($invalid_fields as $invalid) {
    foreach (['set', 'insert'] as $operation) {
        $payload = array_replace(effect_payload(), ['id' => 7], $invalid);
        [$response, $state] = write_effect($operation . '::effect_data', $payload);
        check_effect(!$response['success'] && !empty($response['reason']) && !$state['writes'], 'Invalid effect rejected before writes: ' . json_encode($invalid));
    }
}
foreach ([null, 'invalid'] as $invalid) {
    foreach (['effect_data', 'skill_type'] as $entity) {
        foreach (['set', 'insert'] as $operation) {
            [$response, $state] = write_effect($operation . '::' . $entity, $invalid);
            check_effect(!$response['success'] && !$state['writes'], 'Malformed write payload returns a JSON error');
        }
    }
}
echo "$passed admin effect dispatch assertions passed\n";
