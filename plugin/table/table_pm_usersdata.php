<?php

namespace pokemon;

use discuz_table;
use DB;

if(!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

class table_pm_usersdata extends discuz_table {

    public static function t() {
        static $_instance;
        if(!isset($_instance)) {
            $_instance = new self();
        }
        return $_instance;
    }

    public function __construct() {
        $this->_table = 'pm_usersdata';
        $this->_pk = 'uid';
        parent::__construct();
    }

    public function fetch_by_uid($uid) {
        return DB::fetch_first('SELECT * FROM %t WHERE uid=%d', [$this->_table, $uid]);
    }

    public function update_money($uid, $amount) {
        DB::query('UPDATE %t SET money = money + %d WHERE uid=%d', [$this->_table, $amount, $uid]);
    }

    public function update_exp($uid, $exp) {
        DB::query('UPDATE %t SET fullexp = fullexp + %d WHERE uid=%d', [$this->_table, $exp, $uid]);
    }
}
