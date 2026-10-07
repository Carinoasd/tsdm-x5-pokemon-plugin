<?php
/** Real admin handlers against an exclusively created, disposable MariaDB schema. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);
class DB {
    public static $connection;
    public static $queries = [];
    public static $reject_schema_upgrade = false;
    public static function table($name) { return $name; }
    public static function query($sql) {
        self::$queries[] = $sql;
        if (self::$reject_schema_upgrade && stripos($sql, 'ALTER TABLE pm_config') === 0) return false;
        return self::$connection->query($sql);
    }
    public static function fetch_first($sql) { return self::query($sql)->fetch_assoc(); }
    public static function fetch_all($sql) { return self::query($sql)->fetch_all(MYSQLI_ASSOC); }
    public static function result_first($sql) { $row = self::query($sql)->fetch_row(); return $row ? $row[0] : null; }
    public static function insert_id() { return self::$connection->insert_id; }
}
require __DIR__ . '/../../plugin/admin/routes.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = DB::$connection = new mysqli(getenv('TSDM_DB_HOST') ?: '127.0.0.1', getenv('TSDM_DB_USER') ?: 'root',
    getenv('TSDM_DB_PASSWORD') ?: '', '', (int)(getenv('TSDM_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
$database = 'tsdm_test_catalog_' . bin2hex(random_bytes(8));
$db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$checks = $failures = 0;
function check_catalog($condition, $label) {
    $GLOBALS['checks']++;
    if (!$condition) $GLOBALS['failures']++;
    echo ($condition ? 'PASS ' : 'FAIL '), $label, "\n";
}
function catalog_case($label, $callback) {
    try { $callback(); } catch (Throwable $error) { check_catalog(false, $label . ': ' . $error->getMessage()); }
}
function set_old_config_schema() {
    DB::query('TRUNCATE TABLE pm_config');
    DB::query('ALTER TABLE pm_config MODIFY COLUMN `value` VARCHAR(255) NOT NULL');
    DB::query("INSERT INTO pm_config (`key`,`value`,`data_type`) VALUES ('version','unchanged','string'),('medical_price','10','integer')");
}
try {
    $db->select_db($database);
    $db->multi_query(file_get_contents(__DIR__ . '/../../docker/init.d/02-pokemon-schema.sql'));
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    check_catalog(DB::fetch_first("SHOW COLUMNS FROM pm_config LIKE 'value'")['Type'] === 'longtext', 'Fresh schema stores complete configuration JSON');
    $db->query("INSERT INTO pm_skill (id,name,description,available_pokemons,element,category) VALUES (10,'Seed','Seed','k,1,k','普通','物攻')");
    $skill = ['id'=>10,'name'=>'new name','description'=>'new description','available_pokemons'=>[1],
        'min_level_limit'=>1,'use_times_limit'=>35,'effect'=>['physical_damage'=>['normal',40]],'effect_id'=>0];
    foreach ([''=>'non-strict', 'STRICT_ALL_TABLES'=>'strict'] as $mode=>$label) {
        $db->query("SET SESSION sql_mode = '$mode'");
        foreach (['name', 'description'] as $field) {
            catalog_case("$label skill $field", function () use ($skill, $field, $label) {
                $input = $skill; $input[$field] = "Trainer's \\new 技能";
                $id = insert_skill_type($input);
                $row = get_skill_type($id)[0];
                check_catalog($row[$field] === $input[$field] && $row['id'] === $id, "$label skill insert preserves quoted $field and returns its allocated ID");
                $input['id'] = $id; $input[$field] = "Updated's \\test 招式";
                set_skill_type($input);
                check_catalog(get_skill_type($id)[0][$field] === $input[$field], "$label skill edit preserves quoted $field");
            });
        }
        catalog_case("$label skill identity", function () use ($skill, $label) {
            $id = insert_skill_type($skill);
            DB::query("INSERT INTO pm_myskill (uid,petid,skillid,skillnum) VALUES (7,77,$id,10)");
            delete_skill_type($id);
            $next = $skill; $next['name'] = 'Unrelated replacement';
            $next_id = insert_skill_type($next);
            check_catalog($next_id > $id, "$label deleting the highest skill never recycles its ID");
            check_catalog((int)DB::result_first('SELECT COUNT(*) FROM pm_myskill m JOIN pm_skill s ON s.id=m.skillid WHERE m.petid=77') === 0,
                "$label orphaned learned records cannot silently become another skill");
            DB::query('DELETE FROM pm_myskill');
        });

        $news = [];
        for ($i=1; $i<=4; $i++) $news[] = ['title'=>"Trainer's announcement 第 $i 條", 'url'=>'https://example.com/forum/topic?id='.$i.'&view=latest'];
        foreach ([false, true] as $existing) {
            catalog_case("$label announcement save", function () use ($news, $label, $existing) {
                set_old_config_schema();
                if ($existing) DB::query("INSERT INTO pm_config (`key`,`value`,`data_type`) VALUES ('news_announcements','[]','string')");
                DB::$queries = [];
                $saved = set_global_config(['medical_price'=>12, 'news_announcements'=>$news]);
                check_catalog($saved[0]['news_announcements'] === $news, "$label saves complete ".($existing ? 'existing' : 'new').' announcement list');
                check_catalog(json_decode(DB::fetch_first("SELECT value FROM pm_config WHERE `key`='news_announcements'")['value'], true) === $news,
                    "$label long announcement JSON survives storage without truncation");
                check_catalog(DB::fetch_first("SELECT value FROM pm_config WHERE `key`='version'")['value'] === 'unchanged', "$label lazy schema upgrade preserves other configuration");
                $ddl = $write = null;
                foreach (DB::$queries as $i=>$query) {
                    if (stripos($query, 'ALTER TABLE pm_config') === 0 && $ddl === null) $ddl = $i;
                    if (preg_match('/^(INSERT|UPDATE)\b/i', $query) && $write === null) $write = $i;
                }
                check_catalog($ddl !== null && $write !== null && $ddl < $write, "$label schema upgrade precedes every configuration write");
                DB::$queries = [];
                set_global_config(['news_announcements'=>$news]);
                check_catalog(count(array_filter(DB::$queries, function ($q) { return stripos($q, 'ALTER TABLE') === 0; })) === 0,
                    "$label repeat long save does not repeat DDL");
            });
        }
        catalog_case("$label legacy announcement migration", function () use ($label) {
            set_old_config_schema();
            $title = str_repeat('標', 90);
            $url = 'https://example.com/?q=' . str_repeat('a', 190);
            DB::query(pm_sql("INSERT INTO pm_config (`key`,`value`,`data_type`) VALUES ('ann_title',%s,'string'),('ann_url',%s,'string')", $title, $url));
            check_catalog(pm_get_news_announcements() === [['title'=>$title,'url'=>$url]], "$label first reader migrates a legacy announcement larger than 255 characters");
            DB::query("UPDATE pm_config SET value='[]' WHERE `key`='news_announcements'");
            check_catalog(pm_get_news_announcements() === [], "$label explicit empty news still remains empty");
        });
    }
    catalog_case('Explicit migration', function () {
        set_old_config_schema();
        $sql = file_get_contents(__DIR__ . '/../../migrations/2026-10-config-value-capacity.sql');
        DB::query($sql);
        DB::query($sql);
        check_catalog(DB::fetch_first("SHOW COLUMNS FROM pm_config LIKE 'value'")['Type'] === 'longtext', 'Explicit upgrade can run twice');
        check_catalog(DB::fetch_first("SELECT value FROM pm_config WHERE `key`='version'")['value'] === 'unchanged', 'Explicit upgrade preserves configuration data');
    });
    catalog_case('Schema upgrade failure', function () use ($news) {
        set_old_config_schema();
        DB::$reject_schema_upgrade = true;
        try { set_global_config(['medical_price'=>12, 'news_announcements'=>$news]); $failed = false; }
        catch (RuntimeException $error) { $failed = true; }
        finally { DB::$reject_schema_upgrade = false; }
        check_catalog($failed, 'Failed schema upgrade cannot report a successful save');
        check_catalog(DB::fetch_first("SELECT value FROM pm_config WHERE `key`='medical_price'")['value'] === '10', 'Failed schema upgrade leaves every setting unchanged');
    });
    catalog_case('Empty skill catalog', function () use ($skill) {
        DB::query('TRUNCATE TABLE pm_skill');
        $id = insert_skill_type($skill);
        check_catalog($id > 0 && get_skill_type($id)[0]['name'] === $skill['name'], 'First skill in an empty catalog receives a valid generated ID');
    });
    echo "Admin catalog database: $checks checks, $failures failures.\n";
} finally {
    if (!preg_match('/^tsdm_test_catalog_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Unsafe database name');
    $db->query("DROP DATABASE `$database`");
    $db->close();
}
exit($failures ? 1 : 0);
