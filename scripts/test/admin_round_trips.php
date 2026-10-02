<?php
/** Exercise production admin reads/writes with an isolated in-memory SQL adapter. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class DB
{
    public static $tables = ['pm_evolution' => [], 'pm_data' => [], 'pm_itemdata' => [], 'pm_map' => []];
    public static $writes = [];

    public static function fetch_all($sql)
    {
        if (!preg_match('/^SELECT (?:\*|id|id, name) from (\w+)(.*)$/i', $sql, $match)) {
            throw new RuntimeException('Unexpected read: ' . $sql);
        }
        $rows = array_values(self::$tables[$match[1]]);
        if (preg_match('/where `?id`?\s*=\s*(\d+)/i', $match[2], $id)) {
            $rows = array_values(array_filter($rows, function ($row) use ($id) {
                return $row['id'] === (int) $id[1];
            }));
        }
        if (stripos($match[2], 'order by id desc') !== false) {
            usort($rows, function ($a, $b) { return $b['id'] <=> $a['id']; });
        }
        if (preg_match('/limit (\d+),(\d+)/i', $match[2], $limit)) {
            $rows = array_slice($rows, (int) $limit[1], (int) $limit[2]);
        }
        return $rows;
    }

    public static function fetch_first($sql)
    {
        if (stripos($sql, 'SHOW COLUMNS FROM pm_map') === 0) return ['Type' => 'text'];
        $rows = self::fetch_all($sql);
        return $rows[0] ?? false;
    }

    public static function value($raw)
    {
        $raw = trim($raw);
        if (!preg_match("/^(?:'(?:\\\\.|[^'\\\\])*'|-?\d+)$/s", $raw)) {
            throw new RuntimeException('Malformed SQL value: ' . $raw);
        }
        return $raw[0] === "'" ? stripslashes(substr($raw, 1, -1)) : (int) $raw;
    }

    public static function query($sql)
    {
        self::$writes[] = $sql;
        if (preg_match('/^UPDATE (\w+) set `?(\w+)`?\s*=\s*(.*?) where `?id`?\s*=\s*(\d+)$/is', trim($sql), $match)) {
            self::$tables[$match[1]][(int) $match[4]][$match[2]] = self::value($match[3]);
            return true;
        }
        if (preg_match('/^INSERT INTO (\w+)\s*\((.*?)\)\s*VALUES\s*\((.*?)\)$/is', trim($sql), $match)) {
            $columns = array_map(function ($value) { return trim($value, " \t\r\n`"); }, explode(',', $match[2]));
            $literal = "(?:'(?:\\\\.|[^'\\\\])*'|-?\d+)";
            if (!preg_match('/^' . $literal . '(?:\s*,\s*' . $literal . ')*$/s', trim($match[3]))) {
                throw new RuntimeException('Malformed INSERT values: ' . $match[3]);
            }
            preg_match_all("/'(?:\\\\.|[^'\\\\])*'|-?\d+/s", $match[3], $values);
            if (count($columns) !== count($values[0])) throw new RuntimeException('Malformed INSERT: ' . $sql);
            $row = array_combine($columns, array_map([self::class, 'value'], $values[0]));
            if ($match[1] === 'pm_map') $row += ['experience' => 0];
            self::$tables[$match[1]][$row['id']] = $row;
            return true;
        }
        throw new RuntimeException('Unexpected write: ' . $sql);
    }
}

$plugin = __DIR__ . '/../../plugin';
require $plugin . '/admin/types/pokemon_type.php';
require $plugin . '/admin/types/evolution_info.php';
require $plugin . '/admin/types/item_type.php';
require $plugin . '/admin/types/map_info.php';
require $plugin . '/admin/routes/evolution_data.php';
require $plugin . '/admin/routes/item_data.php';
require $plugin . '/admin/routes/map_data.php';

$passed = 0;
$failed = 0;
function check_round_trip($condition, $message)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        echo "FAIL: $message\n";
    }
}

DB::$tables['pm_data'] = [1 => ['id' => 1, 'name' => 'source'], 2 => ['id' => 2, 'name' => 'target']];
foreach (['<' => 'less', '>' => 'greater', '=' => 'equal'] as $comparison => $label) {
    $row = ['id' => 1, 'from_id' => 1, 'to_id' => 2, 'method' => 'comp_atk_def', 'condition_value' => $comparison, 'priority' => 1];
    DB::$tables['pm_evolution'] = [1 => $row];
    DB::$writes = [];
    $info = get_evolution_info(1)[0];
    check_round_trip($info['condition']['compare_attack_and_defense'] === $label, "Evolution $comparison detail label");
    check_round_trip(list_evolution_info(0, 10)[0]['condition']['compare_attack_and_defense'] === $label, "Evolution $comparison list label");
    set_evolution_info($info);
    check_round_trip(DB::$tables['pm_evolution'][1] === $row && DB::$writes === [], "Evolution $comparison unchanged save preserves rule");
    $inserted = insert_evolution_info($info);
    check_round_trip(DB::$tables['pm_evolution'][$inserted]['condition_value'] === $comparison, "Evolution $comparison copied rule");
}

$kinds = ['normal', 'fire', 'water', 'grass', 'electric', 'ice', 'fighting', 'poison', 'ground', 'flying', 'psychic', 'bug', 'rock', 'ghost', 'dragon', 'dark', 'steel', 'fairy', null];
DB::$tables['pm_itemdata'] = [1 => ['id' => 1]];
foreach ($kinds as $kind) {
    $info = [
        'name' => 'test item', 'img_name' => 'item.png', 'description' => 'test description',
        'is_selling' => true, 'price' => 123, 'tag' => ['drug' => null],
        'limits' => ['min_level' => 5, 'kind_require' => $kind],
        'effects' => array_fill_keys(['add_hit_points', 'add_experience', 'add_level', 'add_intimacy',
            'attribute_add_hit_points', 'attribute_add_attack', 'attribute_add_defense',
            'attribute_add_special_attack', 'attribute_add_special_defense', 'attribute_add_speed', 'capture'], 0),
    ];
    $id = insert_item_type($info);
    $saved = get_item_type($id)[0];
    check_round_trip($saved['limits'] === $info['limits'], 'Item creation preserves required type ' . ($kind ?? 'none'));
    check_round_trip(DB::$tables['pm_itemdata'][$id]['xsask'] === (translate_kind_id_to_chinese_kind($kind) ?? ''), 'Item database type ' . ($kind ?? 'none'));
    DB::$writes = [];
    set_item_type($saved);
    check_round_trip(DB::$writes === [], 'Unchanged item save does not rewrite ' . ($kind ?? 'none'));
}

$bosses = [['pokemon_type_id' => 2, 'level' => 20, 'spawn_chance' => 100, 'boss_multiplier' => 1.25]];
$cases = [
    'wild' => ['mode' => 'wild', 'experience' => 123, 'experience_increase_times' => 4],
    'boss' => ['mode' => 'boss', 'bosses' => $bosses],
    'hybrid' => ['mode' => 'hybrid', 'experience' => 321, 'experience_increase_times' => 0, 'bosses' => $bosses],
];
$base = ['id' => 1, 'name' => 'test map', 'area_type' => 'l', 'is_enabled' => true, 'min_level' => 2, 'max_level' => 50];
$seed = ['id' => 1, 'name' => 'test map', 'site' => 'l', 'is_enabled' => 1, 'min_level' => 2, 'max_level' => 50, 'experience' => 0, 'boss_config' => ''];
foreach ($cases as $mode => $details) {
    foreach (['flat', 'nested'] as $shape) {
        DB::$tables['pm_map'] = [1 => $seed];
        $payload = $base + ($shape === 'flat' ? $details : ['mode' => ['mode' => $mode, 'data' => $details]]);
        foreach (['insert', 'set'] as $operation) {
            $id = $operation === 'insert' ? insert_map_info($payload) : 1;
            if ($operation === 'set') set_map_info($payload);
            $saved = get_map_info($id)[0];
            check_round_trip($saved['mode'] === $mode, "Map $mode $shape $operation preserves mode");
            foreach ($details as $key => $expected) {
                check_round_trip(($saved[$key] ?? null) === $expected, "Map $mode $shape $operation preserves $key");
            }
            $before = DB::$tables['pm_map'][$id];
            DB::$writes = [];
            set_map_info($saved);
            check_round_trip(DB::$tables['pm_map'][$id] === $before && DB::$writes === [], "Map $mode unchanged save preserves data");
        }
    }
}

// Reusing the same map must clear the Boss sentinel when changing mode.
DB::$tables['pm_map'] = [1 => $seed];
foreach (['boss', 'wild', 'boss', 'hybrid'] as $mode) {
    set_map_info($base + $cases[$mode]);
    $saved = get_map_info(1)[0];
    check_round_trip($saved['mode'] === $mode, "Map transition to $mode");
    check_round_trip(DB::$tables['pm_map'][1]['experience'] === ($mode === 'boss' ? -1 : $cases[$mode]['experience']), "Map transition to $mode stores experience");
}

// Names and descriptions are ordinary admin input and must survive SQL quoting.
foreach (["King's Rock", "route\\branch", "owner's \\ path"] as $text) {
    foreach (['insert', 'set'] as $operation) {
        try {
            $item = get_item_type($id = max(array_keys(DB::$tables['pm_itemdata'])))[0];
            $item['name'] = $text;
            $item['description'] = $text;
            if ($operation === 'insert') $id = insert_item_type($item);
            else set_item_type($item);
            $saved = get_item_type($id)[0];
            check_round_trip($saved['name'] === $text && $saved['description'] === $text, "Item $operation preserves quoted text");
        } catch (RuntimeException $error) {
            check_round_trip(false, "Item $operation text failed: " . $error->getMessage());
        }
        try {
            $map = $base + $cases['wild'];
            $map['name'] = $text;
            $id = $operation === 'insert' ? insert_map_info($map) : 1;
            if ($operation === 'set') set_map_info($map);
            check_round_trip(get_map_info($id)[0]['name'] === $text, "Map $operation preserves quoted text");
        } catch (RuntimeException $error) {
            check_round_trip(false, "Map $operation text failed: " . $error->getMessage());
        }
    }
}

echo "Admin round trips: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
