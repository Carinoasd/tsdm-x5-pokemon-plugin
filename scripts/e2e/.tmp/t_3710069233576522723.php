<?php
define("IN_DISCUZ", 1);
class DB {
    static $l;
    static function init() { self::$l = new mysqli("tsdm-db", "root", "root", "discuz"); }
    static function fetch_all($s) { $r = self::$l->query($s); if (!$r) return []; $rs = []; while ($w = $r->fetch_assoc()) $rs[] = $w; return $rs; }
    static function fetch_first($s) { $r = self::$l->query($s); if (!$r) return null; return $r->fetch_assoc(); }
    static function result_first($s) { $r = self::$l->query($s); if (!$r) return 0; $w = $r->fetch_row(); return $w ? $w[0] : 0; }
    static function table($t) { return "pre_$t"; }
    static function query($s, $mode = null) { return self::$l->query(is_string($s) ? $s : ""); }
    static function insert_id() { return self::$l->insert_id; }
}
DB::init();
$_SERVER = ["REQUEST_METHOD" => "POST"];
parse_str('action=count::user_info', $_POST);
ob_start();
include "/app/public/source/plugin/pokemon/admin/dispatch.php";
$o = ob_get_clean();
$out = json_decode($o, true);
if ($out === null) {
    $out = ["_raw" => $o, "_parse_error" => json_last_error_msg()];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
