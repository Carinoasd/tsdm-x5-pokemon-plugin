<?php
/** Exact map membership for the production wild encounter selector. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('IN_DISCUZ', 1);
$tokens = token_get_all(file_get_contents(__DIR__ . '/../../plugin/api/battle.php'));
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $start = $i;
    while (++$i < count($tokens) && (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)) {}
    if ($tokens[$i][1] !== 'generate_wild_pokemon_legacy') continue;
    $body = ''; $depth = 0; $opened = false;
    for ($j = $start; $j < count($tokens); $j++) {
        $token = $tokens[$j];
        $body .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $depth++; $opened = true; }
        elseif ($token === '}' && --$depth === 0 && $opened) break;
    }
    eval($body);
    break;
}
function pm_table($table) { return $table; }
function pm_sql($sql, ...$args)
{
    return preg_replace_callback('/%([sd])/', function ($match) use (&$args) {
        $value = array_shift($args);
        return $match[1] === 'd' ? (string)(int)$value : "'" . addslashes($value) . "'";
    }, $sql);
}
function get_map_boss_config_from_map($map) { return null; }
function api_error($message, $code) { throw new RuntimeException($message, $code); }
class DB
{
    public static $species = [];
    public static function fetch_first($sql)
    {
        // Deterministic ORDER BY rand(): return the first qualifying row. The
        // first fixture is deliberately an unrelated species with a similar ID.
        if (preg_match("/mapid LIKE '%(\d+)%'/", $sql, $m)) {
            $matches = function ($mapid) use ($m) { return strpos($mapid, $m[1]) !== false; };
        } elseif (preg_match("/FIND_IN_SET\((\d+), REPLACE\(mapid, ' ', ''\)\) > 0/", $sql, $m)) {
            $matches = function ($mapid) use ($m) { return in_array($m[1], explode(',', str_replace(' ', '', $mapid)), true); };
        } else {
            throw new RuntimeException('Unexpected encounter query: ' . $sql);
        }
        foreach (self::$species as $species) {
            if ($species['mapid'] === '999' || $matches($species['mapid'])) return $species;
        }
        return false;
    }
}
$settings = [];
$passed = $failed = 0;
function check($condition, $label)
{
    if ($condition) { $GLOBALS['passed']++; echo "PASS $label\n"; }
    else { $GLOBALS['failed']++; echo "FAIL $label\n"; }
}
function species($id, $mapid) { return ['id' => $id, 'mapid' => $mapid, 'capture' => 100, 'met' => 101]; }
foreach (['1', '3,1,7', '3, 1, 7'] as $membership) {
    DB::$species = [species(10, '10,21'), species(1, $membership)];
    $wild = generate_wild_pokemon_legacy(['id' => 1, 'min_level' => 5, 'max_level' => 5], 1);
    check($wild['npcid'] === 1, 'Map 1 excludes maps 10/21 and accepts membership ' . $membership);
    check($wild['level'] === 5 && !$wild['is_boss'], 'Normal encounter preserves map level and kind');
}
DB::$species = [species(10, '10,21')];
try { generate_wild_pokemon_legacy(['id' => 1, 'min_level' => 5, 'max_level' => 5], 1); $code = 200; }
catch (RuntimeException $error) { $code = $error->getCode(); }
check($code === 500, 'A map with no matching species does not borrow another maps encounter');
DB::$species = [species(999, '999')];
$wild = generate_wild_pokemon_legacy(['id' => 42, 'min_level' => 5, 'max_level' => 5], 1);
check($wild['npcid'] === 999, 'Global map 999 species remain available');
echo "Battle encounters: $passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
