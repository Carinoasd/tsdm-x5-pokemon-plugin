<?php

namespace pokemon;

use discuz_table;
use DB;

if(!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

class table_pm_myitem extends discuz_table {

    public static function t() {
        static $_instance;
        if(!isset($_instance)) {
            $_instance = new self();
        }
        return $_instance;
    }

    public function __construct() {
        $this->_table = 'pm_myitem';
        $this->_pk = 'id';
        parent::__construct();
    }

    public function fetch_all_by_uid($uid) {
        return DB::fetch_all('SELECT * FROM %t WHERE uid=%d', [$this->_table, $uid]);
    }

    public function count_by_uid_itemid($uid, $itemid) {
        return DB::result_first('SELECT COUNT(*) FROM %t WHERE uid=%d AND itemid=%s', [$this->_table, $uid, $itemid]);
    }

    public function fetch_by_uid_itemid($uid, $itemid) {
        return DB::fetch_first('SELECT * FROM %t WHERE uid=%d AND itemid=%s', [$this->_table, $uid, $itemid]);
    }
}
