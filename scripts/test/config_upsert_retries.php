<?php
/** Native SILENT/errno behavior for default and explicit configuration writes. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);
class DB {
    public static $rows = [];
    public static $errors = [];
    public static $after_conflict = null;
    public static $attempts = 0;
    public static $reads = 0;
    private static $error = 0;
    public static function errno() { return self::$error; }
    public static function fetch_first($sql) {
        self::$reads++;
        if (!preg_match("/^SELECT \\* FROM pm_config WHERE `key` = '([^']+)'$/D", $sql, $match)) throw new RuntimeException('Unexpected read');
        return self::$rows[$match[1]] ?? false;
    }
    public static function query($sql, $option = []) {
        if ($option !== 'SILENT') throw new RuntimeException('Expected native SILENT option');
        self::$attempts++;
        self::$error = array_shift(self::$errors) ?? 0;
        if (self::$error) {
            if (self::$after_conflict !== null) self::$rows['medical_price'] = self::$after_conflict;
            return false;
        }
        if (!preg_match("/^INSERT INTO pm_config .* VALUES \\('([^']+)', '((?:\\\\.|[^'\\\\])*)', '([^']+)'\\) ON DUPLICATE KEY UPDATE (.*)$/sD", $sql, $match)) throw new RuntimeException('Unexpected upsert');
        $replace = $match[4] === '`value` = VALUES(`value`), `data_type` = VALUES(`data_type`)';
        if (!$replace && $match[4] !== '`key` = `key`') throw new RuntimeException('Unexpected conflict clause');
        if ($replace || !isset(self::$rows[$match[1]])) self::$rows[$match[1]] = ['value'=>stripslashes($match[2]), 'data_type'=>$match[3]];
        return 0;
    }
    public static function reset($errors = []) {
        self::$rows = [];
        self::$errors = $errors;
        self::$after_conflict = null;
        self::$attempts = self::$reads = self::$error = 0;
    }
}
require __DIR__ . '/../../plugin/admin/routes.php';
require __DIR__ . '/../../plugin/config_schema.php';
$checks = 0;
function check_upsert($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
foreach ([false, true] as $replace) {
    foreach ([[],[1213],[1205],[1213,1205]] as $errors) {
        DB::reset($errors);
        pm_config_upsert('medical_price', '99', 'integer', $replace);
        check_upsert(DB::$attempts === count($errors)+1 && DB::$rows['medical_price'] === ['value'=>'99','data_type'=>'integer'], 'Only the failed statement retries and zero is success');
    }
    foreach ([[1213,1205,1213],[1142],[1064]] as $errors) {
        DB::reset($errors);
        try { pm_config_upsert('medical_price', '99', 'integer', $replace); $message = ''; }
        catch (RuntimeException $error) { $message = $error->getMessage(); }
        check_upsert($message === (count($errors) === 3 ? 'Configuration write lock conflict' : 'Configuration write failed')
            && DB::$attempts === count($errors) && DB::$rows === [], 'Retries stop after three attempts and never swallow other errors');
    }
}
DB::reset([1213]);
DB::$after_conflict = ['value'=>'10','data_type'=>'string'];
pm_config_upsert('medical_price', '99', 'integer', true);
check_upsert(DB::$attempts === 2 && DB::$reads === 0 && DB::$rows['medical_price'] === ['value'=>'99','data_type'=>'integer'],
    'Explicit save retries its own typed value even after another writer inserts a default');
DB::reset([1205]);
DB::$after_conflict = ['value'=>'99','data_type'=>'integer'];
pm_config_upsert('medical_price', '10', 'string');
check_upsert(DB::$attempts === 1 && DB::$reads === 1 && DB::$rows['medical_price']['value'] === '99', 'Default accepts a concurrent administrator save');
DB::reset([1213,1213,1213]);
DB::$after_conflict = ['value'=>'10','data_type'=>'string'];
try { pm_config_upsert('medical_price', '99', 'integer', true); $message = ''; }
catch (RuntimeException $error) { $message = $error->getMessage(); }
check_upsert($message === 'Configuration write lock conflict' && DB::$attempts === 3 && DB::$rows['medical_price']['value'] === '10',
    'A conflicting row cannot make an exhausted explicit save appear successful');
DB::reset();
DB::$rows['medical_price'] = ['value'=>'99','data_type'=>'integer'];
pm_config_upsert('medical_price', '10', 'string');
check_upsert(DB::$rows['medical_price'] === ['value'=>'99','data_type'=>'integer'], 'Default upsert preserves existing value and type');
DB::reset();
$text = "Trainer's \\path";
pm_config_upsert('ann_title', $text, 'string', true);
check_upsert(DB::$rows['ann_title']['value'] === $text, 'Text is escaped exactly once');
echo "Configuration upsert retries: $checks passed.\n";
