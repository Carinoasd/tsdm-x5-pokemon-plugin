<?php
// Exercise the real API bootstrap, response lifecycle and presentation sinks.
if (isset($argv[1])) {
    define('IN_DISCUZ', true);
    define('API_ROUTED', true);
    define('API_ENDPOINT', 'pokemon');
    $case = $argv[1];
    $_G = ['uid'=>7, 'groupid'=>10, 'adminid'=>0, 'member'=>['username'=>'Trainer'],
        'cache'=>['plugin'=>['pokemon'=>['is_open'=>$case === 'closed' ? 0 : 1]]]];
    if ($case === 'replica') $_G['config']['db']['slave'] = ['test-replica'];
    $_SERVER['HTTP_X_PM_FORMHASH'] = $case === 'csrf' ? 'incorrect' : 'test-formhash';
    function formhash() { return 'test-formhash'; }
    class DB {
        static $queries = [];
        static $state = 100;
        static $snapshot = null;
        static function table($name) { return 'pre_' . $name; }
        static function result_first($sql) {
            self::$queries[] = $sql;
            return $GLOBALS['case'] === 'busy' && strpos($sql, 'GET_LOCK') !== false ? 0 : 1;
        }
        static function query($sql) {
            self::$queries[] = $sql;
            if ($GLOBALS['case'] === 'start-failure' && $sql === 'START TRANSACTION') return false;
            if ($GLOBALS['case'] === 'commit-failure' && $sql === 'COMMIT') return false;
            if ($sql === 'START TRANSACTION') self::$snapshot = self::$state;
            if ($sql === 'ROLLBACK') self::$state = self::$snapshot;
            if ($sql === 'test change') self::$state = 0;
            return true;
        }
    }
    ob_start();
    // Append observer after the real transaction shutdown hook has run.
    register_shutdown_function(function () {
        register_shutdown_function(function () {
            $body = ob_get_clean();
            echo json_encode(['response'=>json_decode($body, true), 'queries'=>DB::$queries,
                'state'=>DB::$state, 'raw'=>$body]);
        });
    });
    require dirname(__DIR__, 2) . '/plugin/api/index.php';
    DB::query('test change');
    if ($case === 'success' || $case === 'commit-failure') api_success(['saved'=>true]);
    if ($case === 'json-success') api_json(['success'=>true]);
    if ($case === 'error') api_error('Rejected', 400);
    if ($case === 'json-error') api_json(['success'=>false]);
    if ($case === 'exception') throw new Exception('private diagnostic');
    exit;
}
function check_api($condition, $name) {
    if (!$condition) throw new RuntimeException($name);
}
foreach (['success','json-success','error','json-error','exception','early-exit','closed','csrf','busy','start-failure','commit-failure','replica'] as $case) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, $case], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check_api(proc_close($process) === 0, "Child failed: $case $err");
    $result = json_decode($out, true);
    check_api(is_array($result), "Invalid output: $case $out");
    $queries = $result['queries'];
    if (in_array($case, ['closed','csrf','busy','replica'], true)) {
        check_api($result['response']['success'] === false, "Request accepted: $case");
        check_api(!in_array('START TRANSACTION', $queries, true), "Started rejected request: $case");
        check_api($result['state'] === 100, "Rejected request mutated data: $case");
    } elseif ($case === 'start-failure') {
        check_api($result['response']['success'] === false && $result['state'] === 100, 'Failed start changed data');
        check_api(strpos(end($queries), 'RELEASE_LOCK') !== false, 'Failed start retained lock');
    } else {
        $commit = in_array($case, ['success','json-success'], true);
        check_api(in_array($commit ? 'COMMIT' : 'ROLLBACK', $queries, true), "Missing completion: $case");
        check_api($result['state'] === ($commit ? 0 : 100), "Wrong resulting state: $case");
        check_api(strpos(end($queries), 'RELEASE_LOCK') !== false, "Lock not released: $case");
        if ($case === 'exception') check_api(strpos($result['raw'], 'private diagnostic') === false, 'Diagnostic leaked');
    }
    echo "PASS API lifecycle $case\n";
}
// The public legacy entrypoint must decline requests without reading files.
$pipes = [];
$process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/plugin/index.php'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
$body = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
check_api(proc_close($process) === 0 && $body === '', 'Legacy proxy returned content');

define('IN_DISCUZ', true);
$_G = ['uid'=>7, 'groupid'=>10, 'member'=>['username'=>'Trainer'], 'cache'=>['plugin'=>['pokemon'=>[]]]];
require dirname(__DIR__, 2) . '/plugin/security.php';
check_api(!pm_is_staff(), 'Ordinary player bypassed closed mode');
$_G['cache']['plugin']['pokemon']['poke_smgly'] = ' Trainer, Other ';
check_api(pm_is_staff(), 'Configured staff was rejected');
$_G['uid'] = 0;
check_api(!pm_is_staff(), 'Guest inherited staff access');
require dirname(__DIR__, 2) . '/plugin/postspm.class.php';
$method = new ReflectionMethod('plugin_pokemon_forum', 'first_pet_html');
$html = $method->invoke(null, ['species_id'=>25, 'nickname'=>"Trainer's <name>", 'level'=>5]);
check_api(strpos($html, '&lt;name&gt;') !== false && strpos($html, '<name>') === false, 'Nickname HTML was not encoded');
echo "PASS Legacy entrypoint, maintenance permissions and nickname encoding\n";
