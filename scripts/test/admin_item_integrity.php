<?php
/** Real admin dispatcher and item handlers with transactional, isolated SQL storage. */
error_reporting(E_ALL);
set_error_handler(function ($s, $m, $f, $l) { throw new ErrorException($m, 0, $s, $f, $l); });
class DB
{
    public static $tables, $snapshot, $race, $fail;
    public static $transaction = false, $locked = false, $affected = 0, $new_id = 0;
    private static function sql($sql) { return strtolower(preg_replace("/'(\d+)'/", '$1', preg_replace('/\s+/', ' ', str_replace('`', '', trim($sql))))); }
    private static function race()
    {
        if (self::$race) {
            self::$tables['pm_myitem'][20]['nums'] += self::$race;
            self::$race = 0;
            if (self::$transaction) self::$snapshot = self::$tables;
        }
    }
    public static function fetch_all($raw)
    {
        $sql = self::sql($raw);
        if (strpos($sql, 'from pm_usersdata') !== false) {
            if (strpos($sql, 'for update') !== false) {
                if (!self::$transaction) throw new RuntimeException('Lock outside transaction');
                self::race(); self::$locked = true;
            }
            preg_match('/uid\s*=\s*(\d+)/', $sql, $m);
            return isset(self::$tables['pm_usersdata'][(int)$m[1]]) ? [self::$tables['pm_usersdata'][(int)$m[1]]] : [];
        }
        if (strpos($sql, 'from pm_itemdata') !== false) {
            preg_match('/id\s*=\s*(\d+)/', $sql, $m);
            return isset(self::$tables['pm_itemdata'][(int)$m[1]]) ? [self::$tables['pm_itemdata'][(int)$m[1]]] : [];
        }
        if (strpos($sql, 'from pm_mypm') !== false) {
            preg_match('/equipmentid1\s*=\s*(\d+)/', $sql, $m); $id = (int)$m[1];
            return array_values(array_filter(self::$tables['pm_mypm'], function ($row) use ($id) {
                for ($i = 1; $i <= 4; $i++) if ($row['equipmentid' . $i] === $id) return true;
                return false;
            }));
        }
        if (strpos($sql, 'from pm_myitem') !== false) {
            $rows = array_values(self::$tables['pm_myitem']);
            if (preg_match('/where id\s*=\s*(\d+)/', $sql, $m)) $rows = array_values(array_filter($rows, fn($r) => $r['id'] === (int)$m[1]));
            if (preg_match('/uid\s*=\s*(\d+)/', $sql, $m)) $rows = array_values(array_filter($rows, fn($r) => $r['uid'] === (int)$m[1]));
            if (preg_match('/itemid\s*=\s*(\d+)/', $sql, $m)) $rows = array_values(array_filter($rows, fn($r) => $r['itemid'] === (int)$m[1]));
            if (!self::$locked && strpos($sql, 'select id, nums') === 0) self::race();
            return $rows;
        }
        throw new RuntimeException('Unexpected read: ' . $raw);
    }
    public static function fetch_first($sql) { return self::fetch_all($sql)[0] ?? false; }
    public static function query($raw)
    {
        $sql = self::sql($raw); self::$affected = 0;
        if ($sql === 'start transaction') { self::$transaction = true; self::$snapshot = self::$tables; return; }
        if ($sql === 'commit' || $sql === 'rollback') {
            if ($sql === 'rollback' && self::$transaction) self::$tables = self::$snapshot;
            self::$snapshot = null; self::$transaction = self::$locked = false; return;
        }
        if (preg_match('/^update pm_myitem set (.+) where id\s*=\s*(\d+)(.*)$/', $sql, $m)) {
            $id = (int)$m[2]; $row = self::$tables['pm_myitem'][$id];
            if (preg_match('/nums <= (\d+)/', $m[3], $limit) && $row['nums'] > (int)$limit[1]) return;
            if (strpos($m[3], 'nums >= 0') !== false && $row['nums'] < 0) return;
            if (preg_match('/nums\s*=\s*nums\s*\+\s*(\d+)/', $m[1], $add)) $row['nums'] += (int)$add[1];
            else if (preg_match('/nums\s*=\s*(\d+)/', $m[1], $num)) $row['nums'] = (int)$num[1];
            if (preg_match('/itemid\s*=\s*(\d+)/', $m[1], $type)) $row['itemid'] = (int)$type[1];
            self::$affected = (int)($row !== self::$tables['pm_myitem'][$id]); self::$tables['pm_myitem'][$id] = $row;
        } elseif (preg_match('/^insert into pm_myitem \( ?uid,itemid,nums ?\) values \( ?(\d+),(\d+),(-?\d+) ?\)$/', $sql, $m)) {
            $id = empty(self::$tables['pm_myitem']) ? 1 : max(array_keys(self::$tables['pm_myitem'])) + 1;
            self::$tables['pm_myitem'][$id] = ['id'=>$id,'uid'=>(int)$m[1],'itemid'=>(int)$m[2],'nums'=>(int)$m[3]];
            self::$new_id = $id; self::$affected = 1;
        } elseif (preg_match('/^delete from pm_myitem where id\s*=\s*(\d+)/', $sql, $m)) {
            unset(self::$tables['pm_myitem'][(int)$m[1]]); self::$affected = 1;
        } else throw new RuntimeException('Unexpected write: ' . $raw);
        if (self::$fail) throw new RuntimeException('Injected write failure');
    }
    public static function insert_id() { return self::$new_id; }
    public static function affected_rows() { return self::$affected; }
}

if (isset($argv[1])) {
    $request = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    DB::$tables = $request['tables']; DB::$race = $request['race'] ?? 0; DB::$fail = $request['fail'] ?? false;
    register_shutdown_function(function () { fwrite(STDERR, json_encode(['tables'=>DB::$tables,'transaction'=>DB::$transaction])); });
    define('IN_DISCUZ', true); $_POST = $request['params'];
    try { require __DIR__ . '/../../plugin/admin/dispatch.php'; }
    catch (Throwable $error) { echo json_encode(['success'=>false,'reason'=>$error->getMessage()]); }
    exit;
}
function item_tables($equipped = false)
{
    return ['pm_usersdata'=>[7=>['uid'=>7]], 'pm_itemdata'=>[1=>['id'=>1],2=>['id'=>2]],
        'pm_myitem'=>[20=>['id'=>20,'uid'=>7,'itemid'=>1,'nums'=>10]],
        'pm_mypm'=> $equipped ? [11=>['id'=>11,'uid'=>7,'equipmentid1'=>20,'equipmentid2'=>0,'equipmentid3'=>0,'equipmentid4'=>0]] : []];
}
function item_request($action, $data, $tables = null, $extra = [])
{
    $request = $extra + ['tables'=>$tables ?? item_tables(), 'params'=>['action'=>$action,'id'=>$data['id'] ?? 0,'data'=>json_encode($data)]];
    $process = proc_open([PHP_BINARY,__FILE__,base64_encode(json_encode($request))], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start admin item worker');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Worker failure: ' . $out . $err);
    return [json_decode($out,true,512,JSON_THROW_ON_ERROR), json_decode($err,true,512,JSON_THROW_ON_ERROR)];
}
$passed = $failed = 0;
function check_item($ok, $label) { if ($ok) { $GLOBALS['passed']++; } else { $GLOBALS['failed']++; echo "FAIL $label\n"; } }
$grant = ['owner'=>7,'type_id'=>1,'count'=>5];
[$r,$s] = item_request('insert::item_info',$grant);
check_item($r['success'] && $s['tables']['pm_myitem'][20]['nums'] === 15 && !$s['transaction'], 'Existing grant commits one increment');
[$r,$s] = item_request('insert::item_info',$grant,null,['race'=>2]);
check_item($r['success'] && $s['tables']['pm_myitem'][20]['nums'] === 17 && !$s['transaction'], 'Grant preserves a concurrent committed increment');
[$r,$s] = item_request('insert::item_info',array_merge($grant,['type_id'=>2]));
check_item($r['success'] && count($s['tables']['pm_myitem']) === 2 && $s['tables']['pm_myitem'][21]['nums'] === 5, 'New catalog item creates one stack');
[$r,$s] = item_request('insert::item_info',array_merge($grant,['type_id'=>2,'count'=>32767]));
check_item($r['success'] && $s['tables']['pm_myitem'][21]['nums'] === 32767 && !$s['transaction'], 'Maximum valid new stack is accepted');
foreach ([0,-1,32768,'2.5',true,'invalid'] as $count) {
    [$r,$s] = item_request('insert::item_info',array_merge($grant,['count'=>$count]));
    check_item(!$r['success'] && $s['tables'] === item_tables() && !$s['transaction'], 'Invalid grant count does not write: '.json_encode($count));
}
$full = item_tables(); $full['pm_myitem'][20]['nums'] = 32766;
[$r,$s] = item_request('insert::item_info',$grant,$full);
check_item(!$r['success'] && $s['tables'] === $full && !$s['transaction'], 'Grant overflow preserves the stack');
foreach ([['owner'=>8],['type_id'=>999]] as $missing) {
    [$r,$s] = item_request('insert::item_info',array_merge($grant,$missing));
    check_item(!$r['success'] && $s['tables'] === item_tables() && !$s['transaction'], 'Grant rejects missing owner or catalog');
}
$edit = ['id'=>20,'owner'=>7,'type_id'=>2,'count'=>4];
[$r,$s] = item_request('set::item_info',$edit);
check_item($r['success'] && $s['tables']['pm_myitem'][20] === ['id'=>20,'uid'=>7,'itemid'=>2,'nums'=>4] && !$s['transaction'], 'Valid catalog and quantity edit commits together');
foreach ([['type_id'=>999],['owner'=>8],['count'=>0],['count'=>32768]] as $bad) {
    [$r,$s] = item_request('set::item_info',array_merge($edit,$bad));
    check_item(!$r['success'] && $s['tables'] === item_tables() && !$s['transaction'], 'Invalid edit preserves all fields: '.json_encode($bad));
}
[$r,$s] = item_request('set::item_info',$edit,item_tables(true));
check_item(!$r['success'] && $s['tables'] === item_tables(true) && !$s['transaction'], 'Equipped stack cannot change catalog type');
[$r,$s] = item_request('set::item_info',array_merge($edit,['type_id'=>1,'count'=>1]),item_tables(true));
check_item($r['success'] && $s['tables']['pm_myitem'][20]['nums'] === 1 && !$s['transaction'], 'Equipped stack allows sufficient quantity');
$occupied = item_tables(true); $occupied['pm_mypm'][11]['equipmentid2'] = 20;
[$r,$s] = item_request('set::item_info',array_merge($edit,['type_id'=>1,'count'=>1]),$occupied);
check_item(!$r['success'] && $s['tables'] === $occupied && !$s['transaction'], 'Quantity cannot fall below existing slot references');
[$r,$s] = item_request('delete::item_info',['id'=>20],item_tables(true));
check_item(!$r['success'] && $s['tables'] === item_tables(true) && !$s['transaction'], 'Deleting equipped inventory preserves the slot target');
[$r,$s] = item_request('delete::item_info',['id'=>20]);
check_item($r['success'] && $s['tables']['pm_myitem'] === [] && !$s['transaction'], 'Unoccupied inventory can be deleted');
[$r,$s] = item_request('delete::item_info',['id'=>999]);
check_item(!$r['success'] && $s['tables'] === item_tables() && !$s['transaction'], 'Missing deletion does not affect another stack');
foreach ([['insert::item_info',$grant],['set::item_info',$edit],['delete::item_info',['id'=>20]]] as [$action,$data]) {
    [$r,$s] = item_request($action,$data,null,['fail'=>true]);
    check_item(!$r['success'] && $s['tables'] === item_tables() && !$s['transaction'], 'Failed mutation rolls back: '.$action);
}
echo "Admin item integrity: $passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
