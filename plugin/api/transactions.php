<?php
defined('IN_DISCUZ') || exit('Access Denied');

// Serialize requests even before pm_usersdata exists. Plugin tables must use
// InnoDB so failures roll back inventory, encounter state and rewards together.
function pm_api_begin_transaction($uid)
{
    global $_G;
    if (!empty($GLOBALS['pm_api_transaction'])) {
        return;
    }
    $uid = intval($uid);
    if ($uid <= 0) {
        return;
    }
    // Discuz read routing can send SELECT GET_LOCK to a different connection
    // from writes. Fail closed unless all plugin queries use the primary.
    if (!empty($_G['config']['db']['slave'])) {
        api_error('宠物接口需要使用单一主数据库连接', 503);
    }
    $name = 'pokemon:' . substr(hash('sha256', DB::table('common_member') . ':' . $uid), 0, 56);
    if (intval(DB::result_first(pm_sql('SELECT GET_LOCK(%s, 10)', $name))) !== 1) {
        api_error('请求处理中，请稍后重试', 409);
    }
    $GLOBALS['pm_api_lock'] = $name;
    if (DB::query('START TRANSACTION') === false) {
        api_error('Internal Server Error', 500);
    }
    $GLOBALS['pm_api_transaction'] = true;
}

function pm_api_release_lock()
{
    if (!empty($GLOBALS['pm_api_lock'])) {
        $name = $GLOBALS['pm_api_lock'];
        unset($GLOBALS['pm_api_lock']);
        DB::result_first(pm_sql('SELECT RELEASE_LOCK(%s)', $name));
    }
}

function pm_api_commit_transaction()
{
    if (!empty($GLOBALS['pm_api_transaction'])) {
        if (DB::query('COMMIT') === false) {
            api_error('Internal Server Error', 500);
        }
        $GLOBALS['pm_api_transaction'] = false;
    }
    pm_api_release_lock();
}

function pm_api_rollback_transaction()
{
    if (!empty($GLOBALS['pm_api_transaction'])) {
        $GLOBALS['pm_api_transaction'] = false;
        DB::query('ROLLBACK');
    }
    pm_api_release_lock();
}

// Covers early exits from the forum database handler and unfinished routes.
register_shutdown_function('pm_api_rollback_transaction');
