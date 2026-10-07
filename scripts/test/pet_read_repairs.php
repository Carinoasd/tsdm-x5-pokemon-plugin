<?php
/** Real read-repair helpers against snapshots changed by a competing request. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);
require __DIR__ . '/../../plugin/api/utils.php';
$tokens = token_get_all(file_get_contents(__DIR__ . '/../../plugin/api/pokemon.php'));
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $start = $i;
    while (++$i < count($tokens) && (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)) {}
    if ($tokens[$i][1] !== 'check_and_update_critical_state') continue;
    $body = ''; $depth = 0; $opened = false;
    for ($j = $start; $j < count($tokens); $j++) {
        $token = $tokens[$j]; $body .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $depth++; $opened = true; }
        elseif ($token === '}' && --$depth === 0 && $opened) break;
    }
    eval($body);
    break;
}
function pm_table($table) { return $table; }
function pm_sql_v($sql, $args) { return vsprintf($sql, $args); }
function pm_sql($sql, ...$args) { return pm_sql_v($sql, $args); }
class DB
{
    public static $row;
    public static function query($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (!preg_match('/^UPDATE pm_mypm SET (.+) WHERE (.+)$/', $sql, $match)) {
            throw new RuntimeException('Unexpected repair SQL: ' . $sql);
        }
        foreach (explode(' AND ', $match[2]) as $condition) {
            if (!preg_match('/^(\w+) = (-?\d+)$/', $condition, $field)) throw new RuntimeException($condition);
            if ((int)self::$row[$field[1]] !== (int)$field[2]) return;
        }
        foreach (explode(', ', $match[1]) as $assignment) {
            if (!preg_match('/^(\w+) = (-?\d+)$/', $assignment, $field)) throw new RuntimeException($assignment);
            self::$row[$field[1]] = (int)$field[2];
        }
    }
}
$passed = 0;
function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['passed']++;
    echo 'PASS ', $message, PHP_EOL;
}
function pet($hp = 200, $state = 1)
{
    return ['id' => 11, 'uid' => 7, 'hp' => $hp, 'state' => $state, 'statetime' => 1,
        'species_id' => 1, 'level' => 50, 'hpg' => 0, 'hpn' => 0, 'is_shiny' => 0,
        'equipmentid1' => 0, 'equipmentid2' => 0, 'equipmentid3' => 0, 'equipmentid4' => 0];
}
$snapshot = DB::$row = pet(0);
DB::$row['hp'] = 100;
check_and_update_critical_state($snapshot);
check(DB::$row['hp'] === 100 && DB::$row['state'] === 1, 'A stale fainted snapshot cannot undo healing');
$snapshot = DB::$row = pet(50, 0);
DB::$row['state'] = 1;
check_and_update_critical_state($snapshot);
check(DB::$row['state'] === 1, 'A stale critical snapshot cannot weaken a cured Pokemon');
$snapshot = DB::$row = pet(0);
check_and_update_critical_state($snapshot);
check(DB::$row['state'] === 0, 'An unchanged zero-HP Pokemon is marked fainted');
$snapshot = DB::$row = pet(50, 0);
check_and_update_critical_state($snapshot);
check(DB::$row['state'] === 20, 'An unchanged living critical Pokemon recovers to weak state');

foreach (['uid', 'hp', 'state', 'species_id', 'level', 'hpg', 'hpn', 'is_shiny',
    'equipmentid1', 'equipmentid2', 'equipmentid3', 'equipmentid4'] as $changed) {
    $snapshot = DB::$row = pet();
    DB::$row[$changed]++;
    $expected = DB::$row;
    $result = api_validate_and_correct_hp($snapshot, null, 100);
    check(DB::$row === $expected && $result['hp'] === 100,
        'Old HP maximum does not overwrite a changed ' . $changed . ' snapshot');
}
$snapshot = DB::$row = pet();
$result = api_validate_and_correct_hp($snapshot, null, 100);
check(DB::$row['hp'] === 100 && $snapshot['hp'] === '100' && $result['corrected'],
    'Uncontested legacy over-healing is still corrected');
$snapshot = DB::$row = pet(50);
$result = api_validate_and_correct_hp($snapshot, 225, 100);
check(DB::$row['hp'] === 100 && $result['hp'] === 100,
    'Explicit target HP compares the original persisted HP, not the target');
$snapshot = DB::$row = pet(-5);
$result = api_validate_and_correct_hp($snapshot, null, 100);
check(DB::$row['hp'] === 0 && $result['hp'] === 0, 'Uncontested negative HP is corrected to zero');
$snapshot = DB::$row = pet(50);
$result = api_validate_and_correct_hp($snapshot, 75, 100);
check(DB::$row['hp'] === 50 && $result['hp'] === 75 && !$result['corrected'],
    'Valid explicit target HP remains the calling mutation responsibility');
echo "Pet read repair checks passed: $passed", PHP_EOL;
