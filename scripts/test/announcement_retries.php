<?php
/** Exercise the native Discuz SILENT/errno contract without retrying other errors. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('IN_DISCUZ', true);
class DB
{
    public static $rows = [];
    public static $errors = [];
    public static $saved_by_other = null;
    public static $attempts = 0;
    private static $error = 0;
    public static function table($name) { return $name; }
    public static function errno() { return self::$error; }
    public static function fetch_first($sql)
    {
        if (!preg_match("/^SELECT \\* FROM pm_config WHERE `key` = '([a-z_]+)'$/D", $sql, $match)) {
            throw new RuntimeException('Unexpected announcement read: ' . $sql);
        }
        return isset(self::$rows[$match[1]]) ? ['value' => self::$rows[$match[1]]] : false;
    }
    public static function query($sql, $arg = [])
    {
        if ($arg !== 'SILENT') throw new RuntimeException('Expected native Discuz SILENT option');
        self::$attempts++;
        self::$error = array_shift(self::$errors) ?? 0;
        if (self::$error) {
            if (self::$saved_by_other !== null) self::$rows['news_announcements'] = json_encode(self::$saved_by_other);
            return false;
        }
        if (!preg_match("/^INSERT INTO pm_config .* VALUES \\('news_announcements', '((?:\\\\.|[^'\\\\])*)', 'string'\\) ON DUPLICATE KEY UPDATE `key` = `key`$/sD", $sql, $match)) {
            throw new RuntimeException('Unexpected announcement insert: ' . $sql);
        }
        if (!isset(self::$rows['news_announcements'])) self::$rows['news_announcements'] = stripslashes($match[1]);
        return 0; // Discuz returns insert_id, including zero for this non-auto-ID table.
    }
    public static function reset($errors = [])
    {
        self::$rows = ['ann_title' => 'Legacy notice', 'ann_url' => 'https://example.com/old'];
        self::$errors = $errors;
        self::$saved_by_other = null;
        self::$attempts = self::$error = 0;
    }
}
require __DIR__ . '/../../plugin/admin/routes.php';
require __DIR__ . '/../../plugin/announcements.php';
$passed = 0;
function expect_news($condition, $label)
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['passed']++;
}
$expected = [['title' => 'Legacy notice', 'url' => 'https://example.com/old']];
foreach ([[], [1213], [1205], [1213, 1205]] as $errors) {
    DB::reset($errors);
    expect_news(pm_get_news_announcements() === $expected && DB::$attempts === count($errors) + 1,
        'A lock conflict may retry only the idempotent migration, and insert_id zero is success');
}
foreach ([[1213, 1205, 1213], [1142], [1064]] as $errors) {
    DB::reset($errors);
    try { pm_get_news_announcements(); $message = ''; }
    catch (RuntimeException $error) { $message = $error->getMessage(); }
    expect_news($message === (count($errors) === 3 ? 'Configuration write lock conflict' : 'Configuration write failed')
        && DB::$attempts === count($errors) && !isset(DB::$rows['news_announcements']),
        'Retries are bounded and non-lock write errors fail immediately');
}
DB::reset([1213]);
DB::$saved_by_other = [['title' => 'New administrator notice', 'url' => 'https://example.com/new']];
expect_news(pm_get_news_announcements() === DB::$saved_by_other && DB::$attempts === 1,
    'A committed administrator list is returned without retrying its insert');
DB::reset();
DB::$rows['news_announcements'] = '[]';
expect_news(pm_get_news_announcements() === [] && DB::$attempts === 0,
    'An explicitly cleared list never migrates again');
DB::reset();
DB::$rows['ann_title'] = "\xff";
try { pm_get_news_announcements(); $message = ''; }
catch (RuntimeException $error) { $message = $error->getMessage(); }
expect_news($message === 'Announcement encoding failed' && DB::$attempts === 0,
    'Encoding failure cannot insert an empty or invalid announcement value');
echo "Announcement retries: $passed passed.\n";
