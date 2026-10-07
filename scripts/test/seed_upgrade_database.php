<?php
/** Full seed and legacy-upgrade checks in disposable MariaDB databases only.
 * Uses TSDM_DB_HOST/PORT/USER/PASSWORD, matching live_database.php.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
$root = dirname(__DIR__, 2);
$databases = [];
$passed = $failed = 0;
define('IN_DISCUZ', true);
define('API_ROUTED', true);
$_G = ['uid' => 1];
$_SERVER['HTTP_X_PM_FORMHASH'] = 'seed-fixture';
function formhash() { return 'seed-fixture'; }
class DB
{
    public static function table($name) { return $name; }
    public static function query($sql) { return $GLOBALS['db']->query($sql); }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
}
require $root . '/plugin/api/index.php';
require $root . '/plugin/api/utils.php';
require $root . '/plugin/api/item_modules.php';

function check($condition, $label, $detail = '')
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS $label\n"; }
    else { $failed++; echo "FAIL $label" . ($detail !== '' ? ': ' . $detail : '') . "\n"; }
}
function fresh_database()
{
    global $db, $databases;
    $name = 'tsdm_test_seed_' . bin2hex(random_bytes(8));
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databases[] = $name; // Only databases created by this process may be dropped.
    echo "Using disposable database $name\n";
    $db->select_db($name);
    return $name;
}
function sql_file($path)
{
    global $db;
    $sql = str_replace("\r\n", "\n", file_get_contents($path));
    // Seed/migration files must operate only in the selected disposable database.
    if (preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+DATABASE\s)/mi', $sql)) {
        throw new RuntimeException('SQL file changes database scope: ' . basename($path));
    }
    $chunks = preg_split('/^DELIMITER\h+(\S+)\h*$/m', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    $delimiter = ';';
    foreach ($chunks as $i => $chunk) {
        if ($i % 2) { $delimiter = $chunk; continue; }
        // DELIMITER is a client directive. Keep the procedure body as one query.
        $statements = $delimiter === ';' ? split_sql($chunk) : explode($delimiter, $chunk);
        foreach ($statements as $statement) {
            if (trim(preg_replace('/--[ \t][^\n]*|\/\*(?!\!)[\s\S]*?\*\//', '', $statement)) === '') continue;
            try {
                $db->multi_query($statement);
                do {
                    if ($result = $db->store_result()) $result->free();
                } while ($db->more_results() && $db->next_result());
            } catch (Throwable $error) {
                throw new RuntimeException(basename($path) . ': ' . $error->getMessage(), 0, $error);
            }
        }
    }
}
function split_sql($sql)
{
    // Match the command-line client's empty-statement handling (dump files may
    // contain ;;), without splitting semicolons inside strings or comments.
    $parts = []; $start = 0; $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i]; $next = $sql[$i + 1] ?? '';
        if ($char === "'" || $char === '"' || $char === '`') {
            for ($i++; $i < $length; $i++) {
                if ($sql[$i] === '\\') { $i++; continue; }
                if ($sql[$i] === $char) {
                    if (($sql[$i + 1] ?? '') === $char) { $i++; continue; }
                    break;
                }
            }
        } elseif ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2); $i = $end === false ? $length : $end + 1;
        } elseif ($char === '#' || ($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ''))) {
            $end = strpos($sql, "\n", $i); $i = $end === false ? $length : $end;
        } elseif ($char === ';') {
            $parts[] = substr($sql, $start, $i - $start); $start = $i + 1;
        }
    }
    $parts[] = substr($sql, $start);
    return $parts;
}
function rows($sql) { return $GLOBALS['db']->query($sql)->fetch_all(MYSQLI_ASSOC); }
function scalar($sql) { return $GLOBALS['db']->query($sql)->fetch_row()[0]; }
function business_snapshot()
{
    $snapshot = [];
    foreach (['pm_config', 'pm_data', 'pm_itemdata', 'pm_map', 'pm_skill', 'pm_evolution',
        'pm_usersdata', 'pm_mypm', 'pm_myitem', 'pm_myskill', 'pm_effect', 'pm_status'] as $table) {
        $snapshot[$table] = rows('SELECT * FROM `' . $table . '` ORDER BY 1');
    }
    return $snapshot;
}

try {
    fresh_database();
    $files = glob($root . '/docker/init.d/*.sql'); sort($files, SORT_STRING);
    foreach ($files as $file) {
        sql_file($file);
        check(true, 'Fresh install imports ' . basename($file));
    }
    foreach (['pm_data', 'pm_itemdata', 'pm_map', 'pm_skill', 'pm_evolution', 'pm_effect', 'pm_status'] as $table) {
        check((int)scalar('SELECT COUNT(*) FROM ' . $table) > 0, $table . ' contains usable seed rows');
    }
    check((int)scalar("SELECT COUNT(*) FROM pre_common_plugin WHERE identifier = 'pokemon' AND available = 1") === 1,
        'Forum seed registers one enabled Pokemon plugin');
    check((int)scalar('SELECT COUNT(*) FROM pm_mypm p LEFT JOIN pm_data d ON d.id=p.species_id WHERE d.id IS NULL') === 0,
        'Seeded owned Pokemon reference existing species');
    check((int)scalar('SELECT COUNT(*) FROM pm_myskill m LEFT JOIN pm_mypm p ON p.id=m.petid AND p.uid=m.uid LEFT JOIN pm_skill s ON s.id=m.skillid WHERE p.id IS NULL OR s.id IS NULL OR m.skillnum > s.max_uses') === 0,
        'Seeded learned skills belong to a Pokemon and have valid PP');
    check((int)scalar('SELECT COUNT(*) FROM pm_myitem m LEFT JOIN pm_itemdata i ON i.id=m.itemid WHERE i.id IS NULL OR m.nums <= 0') === 0,
        'Seeded inventory references available item definitions');
    check((int)scalar('SELECT COUNT(*) FROM pm_skill s LEFT JOIN pm_effect e ON e.id=s.effect_id WHERE s.effect_id > 0 AND e.id IS NULL') === 0,
        'Seeded skill effects reference existing templates');
    $missing_modules = [];
    foreach (rows('SELECT * FROM pm_itemdata WHERE type IN (3,4) AND shop=1') as $item) {
        $module = api_get_item_module($item);
        if ($module === '' || !function_exists($module)) $missing_modules[] = $item['id'] . ':' . $module;
    }
    check(!$missing_modules, 'Purchasable evolution and enhancement items have callable modules', implode(', ', $missing_modules));
    check((int)scalar("SELECT COUNT(*) FROM pm_evolution e LEFT JOIN pm_itemdata i ON i.id=e.condition_value WHERE e.method='item' AND (i.id IS NULL OR i.type != 3)") === 0,
        'Every item evolution rule references an evolution item ID');
    foreach ([[64, 14, 65], [133, 3, 134], [126, 14, null]] as [$species, $item_id, $target]) {
        $db->begin_transaction();
        try {
            $db->query("INSERT INTO pm_mypm (uid, species_id, level, state, site) VALUES (1, $species, 50, 1, 3)");
            $pet_id = $db->insert_id;
            $item = rows('SELECT * FROM pm_itemdata WHERE id=' . $item_id)[0];
            $module = api_get_item_module($item);
            $result = function_exists($module) ? $module($pet_id, $item['name'], $item_id) : 1;
            $actual = (int)scalar('SELECT species_id FROM pm_mypm WHERE id=' . $pet_id);
            check($target === null ? ($result === 1 && $actual === $species) : ($result === 0 && $actual === $target),
                "Seeded item $item_id evolves species $species only into its intended target", "result=$result species=$actual");
        } finally { $db->rollback(); }
    }

    $migration = $argv[1] ?? $root . '/migrations/from-x3/001_x3_to_x5_migration.sql';
    $before = business_snapshot();
    sql_file($migration);
    check(business_snapshot() === $before, 'Upgrade on a new installation preserves all game data');
    sql_file($migration);
    check(business_snapshot() === $before, 'Repeated upgrade preserves all game data');

    // A previously interrupted migration may have moved all but one old column.
    $db->query("ALTER TABLE pm_mypm CHANGE nickname nowname varchar(30) NOT NULL DEFAULT ''");
    sql_file($migration);
    check(rows('SELECT nickname, species_id, is_shiny FROM pm_mypm ORDER BY id') ===
        array_map(fn($p) => ['nickname' => $p['nickname'], 'species_id' => $p['species_id'], 'is_shiny' => $p['is_shiny']], $before['pm_mypm']),
        'Partial Pokemon migration preserves the old nickname and already migrated attributes');

    $repair = $root . '/migrations/seed-fixes/001_item_evolution_ids.sql';
    $correct_rules = rows('SELECT * FROM pm_evolution ORDER BY id');
    sql_file($repair);
    check(rows('SELECT * FROM pm_evolution ORDER BY id') === $correct_rules, 'Seed repair does not change a correct fresh install');
    // Reconstruct exactly the old seed's numbering in this disposable database.
    $db->query("UPDATE pm_evolution e JOIN pm_itemdata i ON i.id=e.condition_value SET e.condition_value=i.upitem WHERE e.method='item'");
    sql_file($repair);
    check(rows('SELECT * FROM pm_evolution ORDER BY id') === $correct_rules, 'Seed repair restores all 79 original item rules');
    sql_file($repair);
    check(rows('SELECT * FROM pm_evolution ORDER BY id') === $correct_rules, 'Seed repair is idempotent');
    $db->query("UPDATE pm_evolution e JOIN pm_itemdata i ON i.id=e.condition_value SET e.condition_value=i.upitem WHERE e.method='item'");
    foreach (['priority=1', "method='custom'", 'to_id=333', "condition_value='custom'", 'from_id=999', 'id=9346'] as $offset => $change) {
        $db->query('UPDATE pm_evolution SET ' . $change . ' WHERE id=' . (341 + $offset));
    }
    $custom = rows('SELECT * FROM pm_evolution WHERE id BETWEEN 341 AND 345 OR id=9346 ORDER BY id');
    sql_file($repair);
    check(rows('SELECT * FROM pm_evolution WHERE id BETWEEN 341 AND 345 OR id=9346 ORDER BY id') === $custom,
        'Seed repair preserves customized IDs, species, method, condition and priority');

    fresh_database();
    sql_file($root . '/docker/init.d/02-pokemon-schema.sql');
    $db->query("INSERT INTO pm_data (id,name,description,speed,is_legendary,mapid,effort_values,drop_money) VALUES (1,'Legacy','Old description',45,1,'1','','')");
    $db->query("INSERT INTO pm_mypm (id,uid,nickname,species_id,is_shiny) VALUES (1,7,'Old nickname',1,1)");
    $db->query("INSERT INTO pm_skill (id,available_pokemons,name,description,level_required,power,max_uses,element) VALUES (1,'k,1,k','Move','Old move',12,75,15,'火')");
    $db->query("INSERT INTO pm_map (id,name,min_level,max_level,experience,site,boss_config) VALUES (1,'Map',2,8,33,'1','{\"boss\":1}')");
    $db->query("INSERT INTO pm_itemdata (id,name,description,type,tpname,effects,equipment) VALUES (1,'Item','Old item',4,'lvupitem','','')");
    // All migrated names are absent, while old rows retain meaningful values.
    $renames = [
        'pm_data' => ['description' => ['txt','varchar(255)'], 'speed' => ['sd','int'], 'is_legendary' => ['god','int']],
        'pm_mypm' => ['nickname' => ['nowname','varchar(30)'], 'species_id' => ['pmno','int'], 'is_shiny' => ['sg','int']],
        'pm_skill' => ['available_pokemons' => ['pmid','mediumtext'], 'description' => ['txt','mediumtext'], 'level_required' => ['lv','int'], 'power' => ['powr','int'], 'max_uses' => ['num','int'], 'element' => ['tn','varchar(6)']],
        'pm_map' => ['is_enabled' => ['kg','int'], 'min_level' => ['minlevel','int'], 'max_level' => ['maxlevel','int'], 'experience' => ['exp','int'], 'boss_config' => ['expn','text']],
        'pm_itemdata' => ['description' => ['txt','varchar(255)']],
    ];
    foreach ($renames as $table => $columns) foreach ($columns as $new => [$old, $type]) {
        $db->query("ALTER TABLE `$table` CHANGE `$new` `$old` $type NULL");
    }
    foreach (['pm_data' => ['hpn'=>3,'atkn'=>4,'defn'=>5,'spatkn'=>6,'spdefn'=>7,'sdn'=>8,'minmoney'=>10,'maxmoney'=>20],
        'pm_itemdata' => ['addhp'=>20,'addexp'=>30,'addlv'=>1,'addgood'=>4,'equipment_hp'=>10,'equipment_atk'=>11,'equipment_def'=>12,'equipment_spatk'=>13,'equipment_spdef'=>14,'equipment_sd'=>15]] as $table => $columns) {
        foreach ($columns as $column => $value) {
            $db->query("ALTER TABLE `$table` ADD `$column` int NULL");
            $db->query("UPDATE `$table` SET `$column`=$value WHERE id=1");
        }
    }
    sql_file($migration);
    $species = rows('SELECT * FROM pm_data WHERE id=1')[0];
    check($species['description'] === 'Old description' && (int)$species['speed'] === 45 && (int)$species['is_legendary'] === 1
        && json_decode($species['effort_values'], true) === ['hp'=>3,'atk'=>4,'def'=>5,'spatk'=>6,'spdef'=>7,'spd'=>8]
        && json_decode($species['drop_money'], true) === [10,20], 'Legacy species preserves text, stats and JSON values');
    $pet = rows('SELECT nickname,species_id,is_shiny FROM pm_mypm WHERE id=1')[0];
    check($pet === ['nickname'=>'Old nickname','species_id'=>'1','is_shiny'=>'1'], 'Legacy Pokemon preserves nickname, species and shiny state');
    $skill = rows('SELECT available_pokemons,description,level_required,power,max_uses,element FROM pm_skill WHERE id=1')[0];
    check($skill === ['available_pokemons'=>'k,1,k','description'=>'Old move','level_required'=>'12','power'=>'75','max_uses'=>'15','element'=>'火'], 'Legacy skill preserves learnability, PP, power and type');
    check(rows('SELECT min_level,max_level,experience,boss_config FROM pm_map WHERE id=1')[0] ===
        ['min_level'=>'2','max_level'=>'8','experience'=>'33','boss_config'=>'{"boss":1}'], 'Legacy map preserves level range and boss configuration');
    $item = rows('SELECT * FROM pm_itemdata WHERE id=1')[0];
    check($item['description'] === 'Old item' && $item['module'] === 'lvupitem'
        && json_decode($item['effects'], true) === ['hp'=>20,'exp'=>30,'level'=>1,'intimacy'=>4]
        && json_decode($item['equipment'], true) === ['hp'=>10,'atk'=>11,'def'=>12,'spatk'=>13,'spdef'=>14,'spd'=>15],
        'Legacy item preserves effects, equipment bonuses and its callable module');
    $converted = business_snapshot();
    sql_file($migration);
    check(business_snapshot() === $converted, 'A completed legacy upgrade is safe to rerun');
    // Each table has just one surviving old field, including fields previously
    // missing from has_old or UPDATE WHERE predicates.
    foreach (['pm_data'=>['speed','sd','int'], 'pm_mypm'=>['nickname','nowname','varchar(30)'],
        'pm_skill'=>['power','powr','int'], 'pm_map'=>['max_level','maxlevel','int'],
        'pm_itemdata'=>['description','txt','varchar(255)']] as $table => [$new,$old,$type]) {
        $db->query("ALTER TABLE `$table` CHANGE `$new` `$old` $type NULL");
    }
    sql_file($migration);
    $partial = business_snapshot();
    // Column order can change after ALTER; compare row values by column name.
    foreach ($converted as $table => $old_rows) {
        check($partial[$table] == $old_rows, 'Partial migration preserves ' . $table . ' data');
    }
    $db->query('ALTER TABLE pm_data ADD atkn int NULL');
    $db->query("UPDATE pm_data SET atkn=9, effort_values='' WHERE id=1");
    $db->query('ALTER TABLE pm_itemdata ADD equipment_sd int NULL');
    $db->query("UPDATE pm_itemdata SET equipment_sd=27, equipment='' WHERE id=1");
    sql_file($migration);
    check(json_decode(scalar('SELECT effort_values FROM pm_data WHERE id=1'), true)['atk'] === 9,
        'A lone legacy effort column is migrated before being dropped');
    check(json_decode(scalar('SELECT equipment FROM pm_itemdata WHERE id=1'), true)['spd'] === 27,
        'A lone legacy equipment speed column is migrated before being dropped');
    check((int)scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='pm_data' AND COLUMN_NAME IN ('txt','sd','atkn')) OR (TABLE_NAME='pm_itemdata' AND COLUMN_NAME IN ('txt','equipment_sd')))") === 0,
        'Successful partial upgrades remove the consumed legacy columns');
} catch (Throwable $error) {
    check(false, 'Seed/upgrade execution', $error->getMessage());
} finally {
    $db->query('UNLOCK TABLES');
    $db->rollback();
    foreach ($databases as $name) {
        if (!preg_match('/^tsdm_test_seed_[a-f0-9]{16}$/D', $name)) throw new RuntimeException('Unsafe cleanup target');
        $db->query('DROP DATABASE `' . $name . '`');
    }
    $db->close();
}
echo "Seed and upgrade database: $passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
