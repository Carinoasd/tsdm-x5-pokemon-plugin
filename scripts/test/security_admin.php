<?php
// Behavioral checks of real admin routes with an in-memory DB recorder.
// Run with: php scripts/test/security_admin.php
define('IN_DISCUZ', true);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new RuntimeException("$message at $file:$line");
});

class DB
{
    public static $row = [];
    public static $writes = [];
    public static $reads = [];
    public static function fetch_first($sql) { self::$reads[] = $sql; return self::$row; }
    public static function fetch_all($sql) { return [['key'=>'ann_title', 'value'=>'old', 'data_type'=>'string']]; }
    public static function query($sql) { self::$writes[] = $sql; return true; }
}
require_once dirname(__DIR__, 2) . '/plugin/admin/routes.php';

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}
function reset_db($row) { DB::$row = $row; DB::$writes = []; DB::$reads = []; }
function has_write($sql) { check(in_array($sql, DB::$writes, true), "Missing expected write: $sql"); }

$punctuation = "Trainer's \\ handbook";
$quoted = "'Trainer\\'s \\\\ handbook'";
$skill = ['id'=>'007', 'name'=>$punctuation, 'description'=>$punctuation,
    'available_pokemons'=>[25,'001',25], 'min_level_limit'=>'12',
    'use_times_limit'=>'20', 'effect'=>['physical_damage'=>['normal',30]]];
reset_db(['id'=>7, 'name'=>'old', 'description'=>'old', 'level_required'=>1,
    'max_uses'=>1, 'category'=>'物攻', 'element'=>'普通', 'power'=>30]);
set_skill_type($skill);
has_write("UPDATE pm_skill SET name=$quoted WHERE id=7");
has_write("UPDATE pm_skill SET description=$quoted WHERE id=7");
has_write("UPDATE pm_skill set available_pokemons='k,1,25,k' where id=7");
has_write('UPDATE pm_skill SET level_required=12 WHERE id=7');
has_write('UPDATE pm_skill SET max_uses=20 WHERE id=7');

reset_db(['id'=>7]);
check(insert_skill_type($skill) === 8, 'Skill insert ID changed.');
check(strpos(DB::$writes[0], "8, $quoted, 'k,1,25,k', $quoted, 12, 20") !== false,
    'Skill insert lost quoted punctuation or canonical species IDs.');

foreach ([['not-a-number'], [[25]], [true], [0], [-1]] as $invalid) {
    reset_db(['id'=>7, 'name'=>'old']);
    try { set_skill_type(array_merge($skill, ['available_pokemons'=>$invalid]));
        throw new RuntimeException('Invalid species ID was accepted.');
    } catch (InvalidArgumentException $expected) {
        check(DB::$writes === [], 'Invalid species list caused a partial write.');
    }
    reset_db(['id'=>7]);
    try { insert_skill_type(array_merge($skill, ['available_pokemons'=>$invalid]));
        throw new RuntimeException('Invalid inserted species ID was accepted.');
    } catch (InvalidArgumentException $expected) {
        check(DB::$writes === [], 'Invalid inserted species list caused a write.');
    }
}

$map = ['id'=>'007', 'name'=>$punctuation, 'area_type'=>'l',
    'min_level'=>'2', 'max_level'=>'20', 'is_enabled'=>true, 'mode'=>'wild'];
reset_db(['name'=>'old', 'site'=>'g', 'is_enabled'=>0, 'min_level'=>1,
    'max_level'=>10, 'boss_config'=>'']);
set_map_info($map);
has_write("UPDATE pm_map SET name=$quoted WHERE id=7");
has_write("UPDATE pm_map SET site='l' WHERE id=7");
has_write('UPDATE pm_map SET min_level=2 WHERE id=7');
has_write('UPDATE pm_map SET max_level=20 WHERE id=7');
reset_db(['id'=>7]);
check(insert_map_info($map) === 8, 'Map insert ID changed.');
check(strpos(DB::$writes[0], "8, $quoted, 'l', 1, 2, 20, ''") !== false,
    'Map insert changed values.');

reset_db(['id'=>7]);
insert_evolution_info(['source_id'=>'001', 'target_id'=>'002',
    'condition'=>['sex'=>"gender's label"], 'priority'=>'3']);
check(strpos(DB::$writes[0], "8, 1, 2, 'sex', 'gender\\'s label', 3") !== false,
    'Evolution condition must be treated as data.');

reset_db(['data_type'=>'string']);
set_global_config(["trainer's_setting"=>$punctuation]);
check(DB::$reads[0] === "SELECT * FROM pm_config WHERE `key`='trainer\\'s_setting'",
    'Config lookup must quote the key.');
has_write("UPDATE pm_config SET `value`=$quoted WHERE `key`='trainer\\'s_setting'");

$effects = array_fill_keys(['add_hit_points','add_experience','add_level','add_intimacy',
    'attribute_add_hit_points','attribute_add_attack','attribute_add_defense',
    'attribute_add_special_attack','attribute_add_special_defense','attribute_add_speed', 'capture'],0);
$item = ['name'=>$punctuation, 'img_name'=>$punctuation, 'description'=>$punctuation,
    'is_selling'=>true, 'price'=>10, 'tag'=>['special'=>$punctuation],
    'limits'=>['min_level'=>1,'kind_require'=>'普通'], 'effects'=>$effects];
reset_db(['id'=>7]);
check(insert_item_type($item) === 8, 'Item insert ID changed.');
check(substr_count(DB::$writes[0], $quoted) === 4, 'Item text fields must preserve punctuation.');

$item['id'] = '007';
$item['limits']['kind_require'] = 'normal';
reset_db(['name'=>'old', 'tpname'=>'old', 'description'=>'old', 'shop'=>1,
    'money'=>10, 'type'=>4, 'sitemname'=>'old', 'lvask'=>1, 'xsask'=>'普通',
    'effects'=>'{"hp":0,"exp":0,"level":0,"intimacy":0}',
    'equipment'=>'{"hp":0,"atk":0,"def":0,"spatk":0,"spdef":0,"spd":0}', 'captmax'=>0]);
set_item_type($item);
has_write("UPDATE pm_itemdata SET name=$quoted WHERE id=7");
has_write("UPDATE pm_itemdata SET tpname=$quoted WHERE id=7");
has_write("UPDATE pm_itemdata SET description=$quoted WHERE id=7");
has_write("UPDATE pm_itemdata SET sitemname=$quoted WHERE id=7");

$stats = array_fill_keys(['hit_points','attack','defense','special_attack','special_defense','speed'], 10);
$points = array_fill_keys(array_keys($stats), 1);
$pokemon = ['name'=>$punctuation, 'description'=>$punctuation, 'cost'=>50,
    'is_selling'=>true, 'sex_weight'=>null, 'initial_statistic'=>$stats,
    'initial_base_points'=>$points, 'kind'=>['normal',null], 'is_legendary'=>false,
    'map_ids'=>['002',1,2], 'capture_weight'=>true, 'meet_weight'=>true,
    'birth_order'=>1, 'strength_weight'=>1, 'drop_money_range'=>[1,5]];
reset_db(['id'=>7]);
check(insert_pokemon_type($pokemon) === 8, 'Pokemon species insert ID changed.');
check(strpos(DB::$writes[0], "8, $quoted, $quoted, 50, 1, -1") !== false,
    'Species insert must preserve punctuation and scalar values.');
check(strpos(DB::$writes[0], "'普通', '', 0, '1,2', 1, 1") !== false,
    'Species map IDs must be canonical, sorted, and unique.');

reset_db(false);
get_global_config();
has_write("INSERT INTO pm_config (`key`, `value`, `data_type`) VALUES ('version', 'Unknown', 'string')");
has_write("INSERT INTO pm_config (`key`, `value`, `data_type`) VALUES ('medical_price', '10', 'string')");

reset_db([]);
try { insert_pokemon_type(['map_ids'=>['not-a-number']]);
    throw new RuntimeException('Invalid map ID was accepted.');
} catch (InvalidArgumentException $expected) {
    check(DB::$writes === [], 'Invalid map IDs caused a write.');
}
check(pm_admin_id_list(['001',25]) === [1,25], 'ID list normalization changed.');
echo "Admin security behavior checks passed.\n";
