<?php
/** Real MariaDB + independent PHP CGI requests. No running Discuz site needed. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$database = 'tsdm_test_' . bin2hex(random_bytes(8));
$db->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->select_db($database);
putenv('TSDM_TEST_DB=' . $database);
$passed = 0;
$contracts = [];
$workers = [];

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['passed']++;
    echo 'PASS ', $message, PHP_EOL;
}
function scalar($sql) { return $GLOBALS['db']->query($sql)->fetch_row()[0]; }
function begin_request($endpoint, $action, $input = null, $extra = [])
{
    $root = realpath(__DIR__ . '/support/live_api.php');
    $base = tempnam(sys_get_temp_dir(), 'tsdm-api-');
    $body = $input === null ? '' : json_encode($input, JSON_THROW_ON_ERROR);
    $env = array_merge(getenv(), [
        'REDIRECT_STATUS' => '200', 'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REQUEST_METHOD' => $input === null ? 'GET' : 'POST',
        'SCRIPT_FILENAME' => $root, 'SCRIPT_NAME' => '/live_api.php',
        'QUERY_STRING' => 'action=' . rawurlencode($action),
        'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string)strlen($body),
        'HTTP_X_PM_FORMHASH' => 'test-formhash', 'TSDM_TEST_ENDPOINT' => $endpoint,
        'TSDM_TEST_READY' => $base . '.ready', 'TSDM_TEST_UID' => '7',
        'TSDM_TEST_FAIL_SQL' => '',
    ], $extra);
    $cgi = getenv('TSDM_PHP_CGI') ?: 'php-cgi';
    $command = [$cgi];
    if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
    array_push($command, '-d', 'display_errors=0', '-d', 'log_errors=1');
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $base, 'w'],
        2 => ['file', $base . '.err', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start PHP CGI');
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $worker = ['process' => $process, 'base' => $base, 'start' => microtime(true)];
    $GLOBALS['workers'][] = $worker;
    return $worker;
}
function finish_request($worker)
{
    while (proc_get_status($worker['process'])['running']) {
        if (microtime(true) - $worker['start'] > 25) {
            proc_terminate($worker['process']);
            throw new RuntimeException('PHP CGI request timed out');
        }
        usleep(10000);
    }
    proc_close($worker['process']);
    $raw = file_get_contents($worker['base']);
    $error = file_get_contents($worker['base'] . '.err');
    $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
    $response = json_decode($parts[1] ?? '', true);
    if (!is_array($response)) throw new RuntimeException('Invalid CGI response: ' . $raw . $error);
    // Warnings must never disappear behind an otherwise valid success envelope.
    if ($error !== '' && !str_contains($error, 'Injected database failure')) throw new RuntimeException($error);
    return $response;
}
function request($endpoint, $action, $input = null, $extra = [])
{
    return finish_request(begin_request($endpoint, $action, $input, $extra));
}
function race($endpoint, $action, $inputs)
{
    $db = $GLOBALS['db'];
    $db->begin_transaction();
    $db->query('SELECT uid FROM pm_usersdata WHERE uid = 7 FOR UPDATE');
    $requests = [];
    try {
        foreach ($inputs as $input) $requests[] = begin_request($endpoint, $action, $input);
        $deadline = microtime(true) + 10;
        foreach ($requests as $worker) {
            while (!is_file($worker['base'] . '.ready')) {
                if (microtime(true) > $deadline || !proc_get_status($worker['process'])['running']) {
                    throw new RuntimeException('Worker did not reach account lock: ' . file_get_contents($worker['base']) . file_get_contents($worker['base'] . '.err'));
                }
                clearstatcache();
                usleep(10000);
            }
        }
    } finally {
        $db->commit();
    }
    return array_map('finish_request', $requests);
}
function action_input($scene, $key, $fields = [])
{
    return array_merge(['request_id' => $key, 'engine_battle_id' => $scene['engine_battle_id'],
        'expected_revision' => $scene['revision']], $fields);
}

try {
    $schema = file_get_contents(__DIR__ . '/../../docker/init.d/02-pokemon-schema.sql');
    $db->multi_query($schema);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    $db->query('CREATE TABLE common_member (uid INT PRIMARY KEY, username VARCHAR(60) NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO common_member VALUES (7, 'fixture-player-7'), (8, 'fixture-player-8')");
    $db->query('INSERT INTO pm_usersdata (uid, money) VALUES (7, 100), (8, 100)');
    $db->query("INSERT INTO pm_data (id, name, hp, atk, def, spatk, spdef, speed, mapid, effort_values, drop_money, strength, met, capture) VALUES
        (1, 'Fixture ally', 100, 50, 100, 50, 100, 100, '', '{}', '1,1', 1, 100, 100),
        (2, 'Fixture enemy', 100, 1, 100, 1, 100, 1, '1', '{}', '1,1', 1, 100, 100)");
    $db->query("INSERT INTO pm_map (id, name, min_level, max_level, site, boss_config) VALUES (1, 'Fixture map', 50, 50, '', '')");
    $db->query("UPDATE pm_data SET xs = '普通'");
    $db->query("INSERT INTO pm_mypm (id, uid, pmname, species_id, level, hp, state, site) VALUES
        (501, 7, 'Fixture ally', 1, 50, 50, 1, 1), (502, 8, 'Fixture ally', 1, 50, 50, 1, 1)");
    $db->query("INSERT INTO pm_skill (id, available_pokemons, name, description, power, max_uses) VALUES (12, '1,2', 'Fixture move', '', 1, 20)");
    $db->query('INSERT INTO pm_myskill (id, uid, petid, skillid, skillnum) VALUES (701, 7, 501, 12, 2), (702, 8, 502, 12, 2)');
    $db->query("INSERT INTO pm_itemdata (id, name, type, module, effects, equipment, shop, money) VALUES
        (17, 'Fixture potion', 1, 'hp20', '{\"hp\":20}', '{}', 0, 0),
        (18, 'Fixture ether', 1, 'pp5', '{}', '{}', 0, 0),
        (24, 'Fixture purchase', 1, 'hp20', '{\"hp\":20}', '{}', 1, 60)");
    $db->query("INSERT INTO pm_myitem (uid, itemid, nums) VALUES (7, '17', 10), (7, '18', 10), (8, '17', 10)");

    $purchases = race('shop', 'buy', [['item_id' => 24, 'quantity' => 1], ['item_id' => 24, 'quantity' => 1]]);
    check(count(array_filter($purchases, fn($r) => $r['success'])) === 1, 'Concurrent purchases only spend available money');
    check((int)scalar('SELECT money FROM pm_usersdata WHERE uid = 7') === 40 && (int)scalar("SELECT SUM(nums) FROM pm_myitem WHERE uid = 7 AND itemid = '24'") === 1, 'Money and purchased inventory commit together');

    $db->query('DROP TABLE pm_battle_action');
    $db->query('ALTER TABLE pm_battle DROP COLUMN revision');
    $empty = request('battle', 'recover');
    check(!$empty['success'] && $empty['code'] === 404, 'Recover reports an empty battle after upgrading an old schema');
    check((bool)$db->query("SHOW COLUMNS FROM pm_battle LIKE 'revision'")->fetch_assoc()
        && (int)scalar('SELECT COUNT(*) FROM pm_battle_action') === 0, 'Old installations create revision and receipt schema before transactions');

    $start = ['request_id' => 'fixture-start-0001', 'engine_battle_id' => 0, 'expected_revision' => 0, 'map_id' => 1];
    $starts = race('battle', 'start', [$start, $start]);
    check($starts[0]['success'] && $starts[0] === $starts[1], 'Concurrent duplicate starts replay the exact committed response');
    check((int)scalar('SELECT COUNT(*) FROM pm_battle WHERE uid = 7') === 1, 'Duplicate start creates exactly one battle');
    $scene = $contracts['start'] = $starts[0]['data'];
    check($scene['engine_battle_id'] > 0 && $scene['revision'] === 1, 'Start returns authoritative battle ID and revision');
    $contracts['items'] = request('battle', 'get_battle_items')['data'];
    $contracts['inventory'] = request('user', 'inventory')['data'];
    $preview = action_input($scene, 'fixture-preview-01', ['item_id' => 18]);
    $selection = request('battle', 'use_item', $preview);
    check($selection['success'] && $selection['data']['requires_skill_selection'], 'PP item preview offers actual owned skill records');
    $contracts['pp_selection'] = $selection['data'];
    check((int)scalar('SELECT revision FROM pm_battle WHERE uid = 7') === $scene['revision'], 'Opening the PP picker does not change battle revision');

    $pp = action_input($scene, 'fixture-pp-use-001', ['item_id' => 18, 'skill_record_id' => 701]);
    $pp_responses = race('battle', 'use_item_on_skill', [$pp, $pp]);
    check($pp_responses[0]['success'] && $pp_responses[0] === $pp_responses[1], 'Concurrent duplicate PP items return one outcome');
    check((int)scalar("SELECT nums FROM pm_myitem WHERE uid = 7 AND itemid = '18'") === 9 && (int)scalar('SELECT skillnum FROM pm_myskill WHERE id = 701') === 7, 'PP and item quantity change exactly once');
    $scene = $contracts['pp_used'] = $pp_responses[0]['data'];

    $heal = action_input($scene, 'fixture-heal-00001', ['item_id' => 17]);
    $other_heal = array_merge($heal, ['request_id' => 'fixture-heal-00002']);
    $heals = race('battle', 'use_item', [$heal, $other_heal]);
    $successes = array_values(array_filter($heals, fn($r) => $r['success']));
    $failures = array_values(array_filter($heals, fn($r) => !$r['success']));
    check(count($successes) === 1 && count($failures) === 1 && $failures[0]['error_code'] === 'battle_state_conflict', 'Different actions based on one revision reject the stale competitor');
    check((int)scalar("SELECT nums FROM pm_myitem WHERE uid = 7 AND itemid = '17'") === 9, 'Competing heal consumes one potion');
    $scene = $contracts['healed'] = $successes[0]['data'];
    check((int)scalar('SELECT hp FROM pm_mypm WHERE id = 501') === $scene['my_pokemon']['hp'], 'Persisted HP matches the committed response');

    $rollback = action_input($scene, 'fixture-rollback-01', ['item_id' => 17]);
    $old_hp = scalar('SELECT hp FROM pm_mypm WHERE id = 501');
    $old_events = scalar('SELECT COUNT(*) FROM pm_battle_event');
    $failed = request('battle', 'use_item', $rollback, ['TSDM_TEST_FAIL_SQL' => 'INSERT INTO pm_battle_action']);
    check(!$failed['success'] && $failed['code'] === 500, 'Failure after gameplay writes returns an error');
    check((int)scalar("SELECT nums FROM pm_myitem WHERE uid = 7 AND itemid = '17'") === 9 && scalar('SELECT hp FROM pm_mypm WHERE id = 501') === $old_hp && scalar('SELECT COUNT(*) FROM pm_battle_event') === $old_events, 'Receipt failure rolls back items, HP and battle events');
    $retry = request('battle', 'use_item', $rollback);
    check($retry['success'], 'Rolled-back request can retry successfully with the same key');
    $scene = $contracts['retried'] = $retry['data'];
    check(request('battle', 'use_item', $rollback) === $retry, 'Lost response retry returns the original complete envelope');
    $conflict = request('battle', 'use_item', array_merge($rollback, ['item_id' => 18]));
    check(!$conflict['success'] && $conflict['error_code'] === 'request_id_conflict', 'Reusing a key with another payload is rejected');

    $turn = action_input($scene, 'fixture-turn-00001', ['skill_id' => 12]);
    $turns = race('battle', 'turn', [$turn, $turn]);
    check($turns[0]['success'] && $turns[0] === $turns[1], 'Duplicate skill turns resolve exactly once');
    $scene = $contracts['turn'] = $turns[0]['data'];
    check($scene['revision'] === $turn['expected_revision'] + 1, 'One committed turn advances revision once');
    $recover = request('battle', 'recover');
    check($recover['success'] && $recover['data']['engine_battle_id'] === $scene['engine_battle_id'] && $recover['data']['revision'] === $scene['revision'], 'Reconnect returns current battle and revision');
    $contracts['recover'] = $recover['data'];
    $contracts['log'] = request('battle', 'battle_log', null, ['QUERY_STRING' => 'action=battle_log&battle_id=' . $scene['engine_battle_id']])['data'];
    check(count($contracts['log']['turns']) > 0, 'Battle log contains grouped persisted turns');

    $second = request('battle', 'start', $start, ['TSDM_TEST_UID' => '8']);
    check($second['success'] && $second['data']['engine_battle_id'] !== $scene['engine_battle_id'], 'Request keys are isolated by account');
    $foreign = request('battle', 'battle_log', null, ['TSDM_TEST_UID' => '8', 'QUERY_STRING' => 'action=battle_log&battle_id=' . $scene['engine_battle_id']]);
    check(!$foreign['success'], 'Another account cannot read a private battle log');

    $db->query("INSERT INTO pm_itemdata (id, name, type, effects, equipment, captmax, ballid) VALUES (19, 'Fixture ball', 2, '{}', '{}', 255, 1)");
    $db->query("INSERT INTO pm_myitem (uid, itemid, nums) VALUES (7, '19', 2)");
    $capture = action_input($scene, 'fixture-capture-01', ['ball_id' => 19]);
    $captures = race('battle', 'capture', [$capture, $capture]);
    check($captures[0]['success'] && $captures[0] === $captures[1] && $captures[0]['data']['status'] === 'captured', 'Concurrent captures return the same terminal result');
    check((int)scalar("SELECT nums FROM pm_myitem WHERE uid = 7 AND itemid = '19'") === 1
        && (int)scalar('SELECT COUNT(*) FROM pm_mypm WHERE uid = 7') === 2, 'Capture consumes one ball and grants one Pokemon');
    $contracts['captured'] = $captures[0]['data'];
    check(request('battle', 'capture', $capture) === $captures[0], 'Lost terminal capture response remains replayable');
    check(request('battle', 'turn', $turn) === $turns[0], 'Committed receipt remains replayable after battle ends');
    $db->query("UPDATE pm_battle_action SET created_at = 1 WHERE uid = 7 AND request_id = 'fixture-start-0001'");
    $expired = request('battle', 'start', $start);
    check(!$expired['success'] && $expired['error_code'] === 'request_expired' && (int)scalar('SELECT COUNT(*) FROM pm_battle WHERE uid = 7') === 1, 'Expired start key cannot create a new battle');

    if ($output = getenv('TSDM_API_CONTRACT_FIXTURES')) {
        file_put_contents($output, json_encode($contracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    echo "Real database checks passed: $passed", PHP_EOL;
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) { proc_terminate($worker['process']); proc_close($worker['process']); }
        foreach (['', '.err', '.ready'] as $suffix) if (is_file($worker['base'] . $suffix)) unlink($worker['base'] . $suffix);
    }
    // This name is generated here, never accepted from a caller or site config.
    $db->query('DROP DATABASE `' . $database . '`');
    $db->close();
}
