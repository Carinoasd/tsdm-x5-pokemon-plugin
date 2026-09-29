<?php
// Load real topic functions without dispatching a request.
define('IN_DISCUZ', true);
class DB {
    static $forums;
    static $access = false;
    static $queries = [];
    static function table($name) { return 'pre_' . $name; }
    static function fetch_first($sql) {
        self::$queries[] = $sql;
        if (strpos($sql, 'forum_access') !== false) return self::$access;
        preg_match('/f.fid=(\d+)/', $sql, $m);
        return self::$forums[intval($m[1])] ?? false;
    }
}
function pm_sql($sql, ...$args) { return vsprintf($sql, $args); }
$source = file_get_contents(dirname(__DIR__, 2) . '/plugin/api/topics.php');
$position = strpos($source, 'function pm_topics_forum_public(');
eval(substr($source, $position));
function check_topics($expected, $name) {
    if (pm_topics_forum_public(2) !== $expected) throw new RuntimeException($name);
    echo "PASS Topics $name\n";
}
$_G = ['uid'=>7];
$public = ['fid'=>2,'fup'=>0,'status'=>1,'password'=>'','viewperm'=>'','formulaperm'=>''];
DB::$forums = [2=>$public]; check_topics(true, 'public forum');
foreach (['password'=>'test-only', 'viewperm'=>'1', 'formulaperm'=>'restriction', 'status'=>0] as $key=>$value) {
    DB::$forums = [2=>array_merge($public, [$key=>$value])];
    check_topics(false, "restricted $key");
}
DB::$forums = [2=>array_merge($public, ['fup'=>1]),1=>array_merge($public,['fid'=>1,'viewperm'=>'1'])];
check_topics(false, 'restricted ancestor');
DB::$forums = [2=>array_merge($public, ['fup'=>1]),1=>array_merge($public,['fid'=>1])];
check_topics(true, 'public ancestor');
DB::$forums = [2=>array_merge($public, ['fup'=>2])]; check_topics(false, 'cyclic ancestor');
DB::$forums = []; check_topics(false, 'missing forum');
DB::$forums = [2=>$public]; DB::$access=['allowview'=>-1]; check_topics(false, 'user access denied');
DB::$access=['allowview'=>1]; check_topics(true, 'user access allowed');
$_G['uid']=0; DB::$access=['allowview'=>-1]; check_topics(true, 'public guest access');
