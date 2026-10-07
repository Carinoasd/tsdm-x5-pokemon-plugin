<?php
/** Run the real shop endpoints against an in-memory transactional database.
 * Interleavings model a second purchase committing before this request locks
 * the user row. These are deterministic endpoint tests, not MySQL lock tests.
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('IN_DISCUZ', true);
require __DIR__ . '/../../plugin/api/utils.php';

function load_shop_functions($path, $wanted)
{
    $tokens = token_get_all(file_get_contents($path));
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
            $body .= is_array($token) && $token[0] === T_DIR ? var_export(realpath(__DIR__ . '/../../plugin/api'), true) : (is_array($token) ? $token[1] : $token);
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($token === '}' && --$depth === 0 && $opened) break;
        }
        if (in_array($name, $wanted, true)) eval($body);
        $i = $j;
    }
}
load_shop_functions($argv[1] ?? __DIR__ . '/../../plugin/api/shop.php', ['api_buy_item', 'api_buy_pet', 'add_item_to_inventory']);
load_shop_functions(__DIR__ . '/../../plugin/api/index.php', ['pm_sql', 'pm_sql_v', 'validate_int_range']);

class ShopResponse extends RuntimeException
{
    public $data;
    public function __construct($code, $data)
    {
        parent::__construct(is_string($data) ? $data : 'success', $code);
        $this->data = $data;
    }
}
function api_success($data) { throw new ShopResponse(200, $data); }
function api_error($message, $status = 400) { throw new ShopResponse($status, $message); }
function require_login() {}
function pm_table($name) { return $name; }
function validate_uid($value) { return (int) $value; }
function validate_id($value, $name) { return (int) $value; }
function validate_required_param($input, $name, $type) { return (int) $input[$name]; }
function validate_optional_param($input, $name, $default, $type, $options) { return (int) ($input[$name] ?? $default); }
function get_json_input() { return $GLOBALS['input']; }

class DB
{
    public static $user, $items, $pets, $product, $species;
    public static $snapshot, $locked = false, $in_txn = false;
    public static $interleave, $fail_write, $pending_interleave;
    public static $item_writes = 0;

    public static function fetch_first($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (preg_match('/^SELECT \* FROM pm_(itemdata|data) WHERE id = (\d+) AND shop = 1$/', $sql, $m)) {
            $row = $m[1] === 'itemdata' ? self::$product : self::$species;
            return $row && (int) $row['id'] === (int) $m[2] ? $row : false;
        }
        if (preg_match('/^SELECT (?:\*|money) FROM pm_usersdata WHERE uid = 7( FOR UPDATE)?$/', $sql, $m)) {
            $lock = !empty($m[1]);
            $stale = self::$user;
            if (self::$interleave) {
                $callback = self::$interleave;
                self::$interleave = null;
                $callback();
            }
            if ($lock) {
                if (!self::$in_txn) throw new RuntimeException('Lock outside transaction');
                self::$locked = true;
                self::$snapshot = [self::$user, self::$items, self::$pets];
            }
            return $lock ? self::$user : $stale;
        }
        if (preg_match('/^SELECT \* FROM pm_myitem WHERE uid = 7 AND itemid = \'(\d+)\'( FOR UPDATE)?$/', $sql, $m)) {
            if (!empty($m[2]) && (!self::$locked || !self::$in_txn)) throw new RuntimeException('Inventory lock before account lock');
            foreach (self::$items as $item) if ($item['itemid'] === (int) $m[1]) return $item;
            return false;
        }
        throw new RuntimeException('Unexpected read: ' . $sql);
    }

    public static function result_first($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (!preg_match('/^SELECT COUNT\(\*\) FROM pm_mypm WHERE uid = 7(?: AND site (= 1|< 3))?$/', $sql, $m)) {
            throw new RuntimeException('Unexpected count: ' . $sql);
        }
        $count = count(array_filter(self::$pets, function ($pet) use ($m) {
            return $pet['uid'] === 7 && (empty($m[1]) || ($m[1] === '= 1' ? $pet['site'] === 1 : $pet['site'] < 3));
        }));
        // Without a lock another purchase can take a slot after the last read.
        if (!self::$locked && self::$pending_interleave) {
            $callback = self::$pending_interleave;
            self::$pending_interleave = null;
            $callback();
        }
        return $count;
    }

    public static function query($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if ($sql === 'START TRANSACTION') {
            if (self::$in_txn) throw new RuntimeException('Nested transaction');
            self::$in_txn = true;
            self::$snapshot = [self::$user, self::$items, self::$pets];
            return;
        }
        if ($sql === 'COMMIT' || $sql === 'ROLLBACK') {
            if ($sql === 'ROLLBACK' && self::$in_txn) [self::$user, self::$items, self::$pets] = self::$snapshot;
            self::$in_txn = self::$locked = false;
            return;
        }
        if (self::$fail_write && str_contains($sql, self::$fail_write)) throw new RuntimeException('Injected database failure');
        if (preg_match('/^UPDATE pm_usersdata SET money = money - (\d+) WHERE uid = 7$/', $sql, $m)) {
            self::$user['money'] -= (int) $m[1];
        } elseif (preg_match('/^INSERT INTO pm_myitem \(uid, itemid, nums\) VALUES \(7, (\d+), (\d+)\)$/', $sql, $m)) {
            self::$item_writes++;
            self::$items[] = ['id' => count(self::$items) + 1, 'uid' => 7, 'itemid' => (int) $m[1], 'nums' => (int) $m[2]];
        } elseif (preg_match('/^UPDATE pm_myitem SET nums = nums \+ (\d+) WHERE id = (\d+)$/', $sql, $m)) {
            self::$item_writes++;
            // Signed SMALLINT in non-strict SQL mode silently clamps overflow.
            foreach (self::$items as &$item) if ($item['id'] === (int) $m[2]) $item['nums'] = min(32767, $item['nums'] + (int) $m[1]);
            unset($item);
        } elseif (preg_match('/^INSERT INTO pm_mypm \((.+)\) VALUES \((.+)\)$/', $sql, $m)) {
            $fields = explode(', ', $m[1]);
            $values = str_getcsv($m[2], ',', "'", '\\');
            $pet = array_combine($fields, array_map('trim', $values));
            $pet['uid'] = (int) $pet['uid'];
            $pet['site'] = (int) $pet['site'];
            self::$pets[] = $pet;
        } else {
            throw new RuntimeException('Unexpected write: ' . $sql);
        }
    }
}

$GLOBALS['_G'] = ['uid' => 7];
function fixture($money = 100)
{
    $GLOBALS['input'] = ['item_id' => 24, 'quantity' => 2, 'pokemon_type_id' => 1];
    DB::$user = ['uid' => 7, 'money' => $money, 'boxnum' => 9];
    DB::$items = DB::$pets = [];
    DB::$product = ['id' => 24, 'money' => 30];
    DB::$species = ['id' => 1, 'name' => 'Test', 'money' => 60, 'sex' => 50, 'xs' => 'grass', 'hp' => 50, 'atk' => 50, 'def' => 50, 'spatk' => 50, 'spdef' => 50, 'speed' => 50];
    DB::$in_txn = DB::$locked = false;
    DB::$interleave = DB::$pending_interleave = DB::$fail_write = null;
    DB::$item_writes = 0;
}
function invoke($endpoint)
{
    try { $endpoint(); } catch (ShopResponse $response) { return $response; }
    throw new RuntimeException('No response');
}
$passed = 0;
function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['passed']++;
}

foreach (['api_buy_item', 'api_buy_pet'] as $endpoint) {
    fixture();
    $result = invoke($endpoint);
    check($result->getCode() === 200 && DB::$user['money'] === 40 && $result->data['remaining_money'] === 40, "$endpoint charges the purchase once");
    check(!DB::$in_txn, "$endpoint commits before responding");
    check($endpoint === 'api_buy_item' ? DB::$items[0]['nums'] === 2 : count(DB::$pets) === 1 && DB::$pets[0]['site'] === 1, "$endpoint grants the goods");

    fixture(50);
    check(invoke($endpoint)->getCode() === 400 && DB::$user['money'] === 50 && !DB::$items && !DB::$pets, "$endpoint rejects insufficient funds without writes");
    check(!DB::$in_txn, "$endpoint closes rejected transactions");

    fixture();
    DB::$interleave = function () { DB::$user['money'] -= 60; };
    check(invoke($endpoint)->getCode() === 400 && DB::$user['money'] === 40 && !DB::$items && !DB::$pets, "$endpoint rechecks funds after a competing purchase");
    check(!DB::$in_txn, "$endpoint releases its lock on conflict");

    fixture();
    DB::$user = false;
    check(invoke($endpoint)->getCode() === 404 && !DB::$in_txn, "$endpoint closes missing-user transactions");

    fixture();
    // Item failure follows debit; pet debit failure follows insertion.
    DB::$fail_write = $endpoint === 'api_buy_item' ? 'INSERT INTO pm_myitem' : 'UPDATE pm_usersdata';
    try { invoke($endpoint); throw new LogicException('Expected database failure'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'Injected database failure', "$endpoint preserves the database error"); }
    check(DB::$user['money'] === 100 && !DB::$items && !DB::$pets && !DB::$in_txn, "$endpoint rolls back both money and goods after a database failure");

    fixture(0);
    DB::$product['money'] = DB::$species['money'] = 0;
    check(invoke($endpoint)->getCode() === 200 && DB::$user['money'] === 0 && !DB::$in_txn, "$endpoint permits free goods");
}

fixture(1000);
DB::$user['boxnum'] = 1;
DB::$interleave = function () { DB::$pets[] = ['uid' => 7, 'site' => 1]; };
check(invoke('api_buy_pet')->getCode() === 400 && count(DB::$pets) === 1 && DB::$user['money'] === 1000 && !DB::$in_txn, 'A competing purchase can fill the last box slot');

fixture(1000);
DB::$user['boxnum'] = 1;
DB::$pending_interleave = function () { DB::$pets[] = ['uid' => 7, 'site' => 1]; };
check(invoke('api_buy_pet')->getCode() === 200 && count(DB::$pets) === 1, 'A purchase holds the user lock across the capacity check and insert');

fixture(1000);
DB::$interleave = function () { DB::$pets[] = ['uid' => 7, 'site' => 1]; };
check(invoke('api_buy_pet')->data['site'] === 2 && count(DB::$pets) === 2, 'A competing first pet leaves the new pet in reserve');

fixture(1000);
for ($i = 0; $i < 6; $i++) DB::$pets[] = ['uid' => 7, 'site' => $i === 0 ? 1 : 2];
check(invoke('api_buy_pet')->data['site'] === 3, 'A full party sends purchased pets to storage');

fixture();
DB::$user['money'] = -1;
check(invoke('api_buy_item')->getCode() === 400 && !DB::$in_txn && !DB::$items, 'Invalid stored balances roll back before responding');

fixture(10000);
$GLOBALS['input']['quantity'] = 99;
$bulk = invoke('api_buy_item');
check($bulk->getCode() === 200 && DB::$items[0]['nums'] === 99 && DB::$user['money'] === 7030
    && $bulk->data['items_purchased'] === 99 && $bulk->data['total_cost'] === 2970, 'A 99-item order grants and charges the full quantity');
check(DB::$item_writes === 1, 'A new 99-item order uses one inventory write');

fixture(10000);
DB::$items = [['id' => 5, 'uid' => 7, 'itemid' => 24, 'nums' => 32668]];
$GLOBALS['input']['quantity'] = 99;
check(invoke('api_buy_item')->getCode() === 200 && DB::$items[0]['nums'] === 32767
    && DB::$user['money'] === 7030 && DB::$item_writes === 1, 'An existing stack can reach exactly 32767 in one write');

foreach ([[32767, 1], [32766, 2], [32700, 99], [-1, 1]] as [$stock, $quantity]) {
    fixture(10000);
    DB::$items = [['id' => 5, 'uid' => 7, 'itemid' => 24, 'nums' => $stock]];
    $GLOBALS['input']['quantity'] = $quantity;
    check(invoke('api_buy_item')->getCode() === 400 && DB::$items[0]['nums'] === $stock
        && DB::$user['money'] === 10000 && !DB::$in_txn,
        "Invalid stock or capacity rejects the entire purchase: $stock + $quantity");
}

fixture(10000);
DB::$items = [['id' => 5, 'uid' => 7, 'itemid' => 24, 'nums' => 32766]];
$GLOBALS['input']['quantity'] = 1;
DB::$interleave = function () { DB::$items[0]['nums']++; DB::$user['money'] -= 30; };
check(invoke('api_buy_item')->getCode() === 400 && DB::$items[0]['nums'] === 32767
    && DB::$user['money'] === 9970 && !DB::$in_txn, 'A competing purchase can fill the final inventory slot before the account lock');

fixture(10000);
DB::$items = [['id' => 5, 'uid' => 7, 'itemid' => 24, 'nums' => 0]];
$GLOBALS['input']['quantity'] = 99;
check(invoke('api_buy_item')->getCode() === 200 && count(DB::$items) === 1 && DB::$items[0]['nums'] === 99,
    'An existing empty stack is reused');

fixture(10000);
DB::$items = [['id' => 5, 'uid' => 7, 'itemid' => 24, 'nums' => 32767],
    ['id' => 6, 'uid' => 7, 'itemid' => 24, 'nums' => 1]];
$GLOBALS['input']['quantity'] = 1;
check(invoke('api_buy_item')->getCode() === 400 && DB::$items[1]['nums'] === 1,
    'Capacity checks do not silently choose another legacy stack');

fixture(10000);
DB::$items = [['id' => 5, 'uid' => 7, 'itemid' => 24, 'nums' => 10]];
$GLOBALS['input']['quantity'] = 99;
DB::$fail_write = 'UPDATE pm_myitem';
try { invoke('api_buy_item'); throw new LogicException('Expected inventory update failure'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Injected database failure', 'Bulk inventory failure preserves the database error'); }
check(DB::$items[0]['nums'] === 10 && DB::$user['money'] === 10000 && !DB::$in_txn,
    'Failed bulk inventory update rolls back the full debit');

echo "$passed shop transaction assertions passed\n";
