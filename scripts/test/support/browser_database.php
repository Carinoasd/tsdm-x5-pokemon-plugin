<?php
/** Fixture lifecycle for live-player.cjs. Never connects to an existing game database. */
if (PHP_SAPI !== 'cli') exit('CLI only');
$database = getenv('TSDM_TEST_DB') ?: '';
$owner = getenv('TSDM_TEST_OWNER') ?: '';
if (!preg_match('/^tsdm_test_browser_[a-f0-9]{32}$/D', $database)
    || !preg_match('/^[a-f0-9]{32}$/D', $owner)) {
    throw new RuntimeException('A randomly generated browser test database and owner are required');
}
$operation = $argv[1] ?? '';
if (!in_array($operation, ['create', 'snapshot', 'drop'], true)) {
    throw new RuntimeException('Invalid fixture operation');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
if ($operation === 'create') {
    // No IF NOT EXISTS: a collision must never reuse somebody else's database.
    $db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($database);
    $db->query('CREATE TABLE browser_fixture_owner (owner CHAR(32) NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO browser_fixture_owner VALUES ('$owner')");
    $db->multi_query(file_get_contents(__DIR__ . '/../../../docker/init.d/02-pokemon-schema.sql'));
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());

    // Only the Discuz boundary is supplied here; all plugin tables use the real schema.
    $db->query('CREATE TABLE common_member (uid INT PRIMARY KEY, username VARCHAR(60) NOT NULL, groupid INT NOT NULL DEFAULT 10) ENGINE=InnoDB');
    $db->query('CREATE TABLE common_session (uid INT PRIMARY KEY, username VARCHAR(60) NOT NULL, action VARCHAR(10) NOT NULL, lastactivity INT NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE common_member_field_forum (uid INT PRIMARY KEY, pokemon TEXT NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE forum_thread (tid INT PRIMARY KEY, fid INT NOT NULL, subject VARCHAR(255) NOT NULL, dateline INT NOT NULL, displayorder INT NOT NULL, author VARCHAR(60) NOT NULL, authorid INT NOT NULL, views INT NOT NULL, replies INT NOT NULL, lastpost INT NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO common_member VALUES (7, 'fixture-player-7', 10)");
    $db->query("INSERT INTO common_session VALUES (7, 'fixture-player-7', '', 1)");
    $db->query("INSERT INTO common_member_field_forum VALUES (7, '')");
    $db->query("INSERT INTO pm_config VALUES ('news_announcements', '[]', 'string')");
    $db->query('INSERT INTO pm_usersdata (uid, money) VALUES (7, 100)');
    $db->query("INSERT INTO pm_data (id, name, xs, hp, atk, def, spatk, spdef, speed, mapid, effort_values, drop_money, strength, met, capture) VALUES
        (1, '整合测试伙伴', '普通', 100, 50, 100, 50, 100, 100, '', '{}', '1,1', 1, 100, 100),
        (2, '整合测试野怪', '普通', 100, 1, 100, 1, 100, 1, '1', '{}', '1,1', 1, 100, 100)");
    $db->query("INSERT INTO pm_map (id, name, min_level, max_level, site, boss_config, region) VALUES (1, '整合测试草地', 50, 50, 'a', '', 'central')");
    $db->query("INSERT INTO pm_mypm (id, uid, pmname, species_id, level, hp, state, site) VALUES (501, 7, '整合测试伙伴', 1, 50, 100, 1, 1)");
    $db->query("INSERT INTO pm_skill (id, available_pokemons, name, description, power, max_uses, element, category) VALUES (12, '1,2', '整合测试招式', '', 1, 20, '普通', '物攻')");
    $db->query('INSERT INTO pm_myskill (id, uid, petid, skillid, skillnum) VALUES (701, 7, 501, 12, 5)');
    $db->query("INSERT INTO pm_itemdata (id, name, description, tpname, type, module, effects, equipment, shop, money) VALUES
        (24, '整合测试药水', '回复20点HP', 'hp20', 1, 'hp20', '{\"hp\":20}', '{}', 1, 60)");
    echo json_encode(['created' => $database], JSON_THROW_ON_ERROR);
    exit;
}

// Both inspection and deletion require the marker created by this exact run.
$exists = $db->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$database'")->fetch_row();
if (!$exists && $operation === 'drop') {
    echo json_encode(['dropped' => false]);
    exit;
}
$db->select_db($database);
$marker = $db->query('SELECT owner FROM browser_fixture_owner')->fetch_row();
if (!$marker || !hash_equals($owner, $marker[0])) throw new RuntimeException('Fixture ownership does not match');
if ($operation === 'drop') {
    $db->query("DROP DATABASE `$database`");
    echo json_encode(['dropped' => true]);
    exit;
}
$snapshot = [
    'user' => $db->query('SELECT money, npcid FROM pm_usersdata WHERE uid = 7')->fetch_assoc(),
    'inventory' => $db->query('SELECT itemid, nums FROM pm_myitem WHERE uid = 7 ORDER BY itemid')->fetch_all(MYSQLI_ASSOC),
    'skill_pp' => (int)$db->query('SELECT skillnum FROM pm_myskill WHERE id = 701')->fetch_row()[0],
    'pokemon_hp' => (int)$db->query('SELECT hp FROM pm_mypm WHERE id = 501')->fetch_row()[0],
    'battles' => $db->query('SELECT id, turn, revision, phase FROM pm_battle WHERE uid = 7 ORDER BY id')->fetch_all(MYSQLI_ASSOC),
    'receipts' => $db->query('SELECT request_id, action, battle_id, response_json FROM pm_battle_action WHERE uid = 7 ORDER BY action')->fetch_all(MYSQLI_ASSOC),
    'events' => $db->query('SELECT battle_id, turn, type FROM pm_battle_event ORDER BY seq')->fetch_all(MYSQLI_ASSOC),
];
echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
