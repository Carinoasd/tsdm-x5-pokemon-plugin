<?php

namespace pokemon;

use discuz_table;
use DB;

if(!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

class table_pm_mypm extends discuz_table {

    public static function t() {
        static $_instance;
        if(!isset($_instance)) {
            $_instance = new self();
        }
        return $_instance;
    }

    public function __construct() {
        $this->_table = 'pm_mypm';
        $this->_pk = 'id';
        parent::__construct();
    }

    public function fetch_all_by_uid($uid) {
        return DB::fetch_all('SELECT * FROM %t WHERE uid=%d ORDER BY level DESC', [$this->_table, $uid]);
    }

    public function fetch_by_uid_state($uid, $state) {
        return DB::fetch_all('SELECT * FROM %t WHERE uid=%d AND state=%d', [$this->_table, $uid, $state]);
    }

    public function count_by_uid($uid) {
        return DB::result_first('SELECT COUNT(*) FROM %t WHERE uid=%d', [$this->_table, $uid]);
    }

    public function update_level($id, $level) {
        DB::query('UPDATE %t SET level=%d WHERE id=%d', [$this->_table, $level, $id]);
    }
}
