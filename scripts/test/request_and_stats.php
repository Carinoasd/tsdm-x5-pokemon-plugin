<?php
/** Exercise production request parsing and user statistics without a live forum. */
define('IN_DISCUZ', true);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function load_api_functions($file, $names)
{
    $tokens = token_get_all(file_get_contents($file));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $start = $i;
        while (++$i < count($tokens) && (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)) {}
        $name = $tokens[$i][1];
        $depth = 0;
        $body = '';
        $opened = false;
        for ($j = $start; $j < count($tokens); $j++) {
            $token = $tokens[$j];
            $body .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($token === '}' && --$depth === 0 && $opened) {
                break;
            }
        }
        if (in_array($name, $names, true)) eval($body);
        $i = $j;
    }
}

load_api_functions(__DIR__ . '/../../plugin/api/index.php', ['get_param', 'pm_sql', 'pm_sql_v', 'pm_table', 'validate_uid', 'require_login']);
load_api_functions(__DIR__ . '/../../plugin/api/user.php', ['api_get_user_stats']);
require __DIR__ . '/../../plugin/api/constants.php';

class StatsResponse extends RuntimeException
{
    public $data;
    public function __construct($data) { parent::__construct('success'); $this->data = $data; }
}
function api_success($data) { throw new StatsResponse($data); }
function api_error($message, $code) { throw new RuntimeException($message, $code); }

class DB
{
    public static $queries = [];
    public static $pets = [];
    public static $items = [];

    public static function fetch_first($sql, $args = [])
    {
        // Discuz formats placeholders only for array arguments. A scalar UID
        // leaves the literal %d in SQL, which is invalid at the database.
        if ($args && is_array($args)) $sql = vsprintf($sql, $args);
        if (strpos($sql, '%d') !== false) throw new RuntimeException('Unexpanded SQL placeholder');
        self::$queries[] = $sql;
        if (!preg_match('/WHERE uid=(\d+)\s*$/', $sql, $matches)) throw new RuntimeException('Missing user filter');
        $uid = (int) $matches[1];
        if (strpos($sql, 'FROM pm_mypm') !== false) {
            $pets = array_values(array_filter(self::$pets, function ($p) use ($uid) { return $p['uid'] === $uid; }));
            return [
                'total' => count($pets),
                'active' => count(array_filter($pets, function ($p) { return $p['site'] === 1; })),
                'total_levels' => array_sum(array_column($pets, 'level')),
                'max_level' => $pets ? max(array_column($pets, 'level')) : null,
            ];
        }
        if (strpos($sql, 'FROM pm_myitem') !== false) {
            $items = array_values(array_filter(self::$items, function ($p) use ($uid) { return $p['uid'] === $uid; }));
            return ['total_items' => count($items), 'total_quantity' => array_sum(array_column($items, 'nums'))];
        }
        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }
}

$passed = 0;
$failed = 0;
function check($name, $test)
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: $name: {$error->getMessage()}\n";
    }
}
function expect_equal($actual, $expected)
{
    if ($actual !== $expected) throw new RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual]));
}

foreach ([null, 0, '', false, 'fallback'] as $default) {
    check('POST value with default ' . var_export($default, true), function () use ($default) {
        $_GET = [];
        $_POST = ['pokemon_id' => '42'];
        expect_equal(get_param('pokemon_id', $default), '42');
    });
}
check('GET retains precedence', function () {
    $_GET = ['pokemon_id' => '7'];
    $_POST = ['pokemon_id' => '42'];
    expect_equal(get_param('pokemon_id', 0), '7');
});
check('Zero and empty POST values are retained', function () {
    $_GET = [];
    $_POST = ['zero' => '0', 'empty' => ''];
    expect_equal(get_param('zero', 1), '0');
    expect_equal(get_param('empty', 'fallback'), '');
});
check('Absent parameters return the default', function () {
    $_GET = $_POST = [];
    expect_equal(get_param('missing', 13), 13);
    expect_equal(get_param('missing'), null);
});

foreach ([7, 8] as $uid) {
    check('Statistics query and values for user ' . $uid, function () use ($uid) {
        global $_G;
        $_G = ['uid' => $uid];
        DB::$queries = [];
        DB::$pets = [
            ['uid' => 7, 'site' => 1, 'level' => 12],
            ['uid' => 7, 'site' => 3, 'level' => 3],
            ['uid' => 99, 'site' => 1, 'level' => 100],
        ];
        DB::$items = [['uid' => 7, 'nums' => 4], ['uid' => 99, 'nums' => 100]];
        try {
            api_get_user_stats();
            throw new RuntimeException('Missing response');
        } catch (StatsResponse $response) {
            expect_equal($response->data['pokemon_stats'], $uid === 7
                ? ['total_owned' => 2, 'active_pokemon' => 1, 'total_levels' => 15, 'highest_level' => 12]
                : ['total_owned' => 0, 'active_pokemon' => 0, 'total_levels' => 0, 'highest_level' => 0]);
            expect_equal($response->data['inventory_stats'], $uid === 7
                ? ['total_items' => 1, 'total_quantity' => 4]
                : ['total_items' => 0, 'total_quantity' => 0]);
            expect_equal(count(DB::$queries), 2);
        }
    });
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
