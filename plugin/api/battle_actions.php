<?php
/** Atomic battle requests. Loaded only by the battle API. */
if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

function battle_action_canonical($value)
{
    if (!is_array($value)) return $value;
    if ($value && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
    foreach ($value as $key => $entry) $value[$key] = battle_action_canonical($entry);
    return $value;
}

function battle_action_rollback()
{
    if (!empty($GLOBALS['battle_action_context']['transaction'])) {
        $GLOBALS['battle_action_context']['transaction'] = false;
        DB::query('ROLLBACK');
    }
}

function battle_action_error($code)
{
    // Recover may expire an abandoned battle before reporting that none exists.
    // Keep that cleanup, while every failed mutation always rolls back.
    if ($code === 404 && !empty($GLOBALS['battle_action_context']['transaction'])
        && $GLOBALS['battle_action_context']['action'] === 'recover') {
        DB::query('COMMIT');
        $GLOBALS['battle_action_context'] = null;
    } else {
        battle_action_rollback();
    }
}

// Endpoint-local transactions join the outer request transaction. Direct callers
// without the dispatcher retain the original transaction behavior.
function battle_action_transaction_begin()
{
    if (empty($GLOBALS['battle_action_context']['transaction'])) DB::query('START TRANSACTION');
}

function battle_action_transaction_commit()
{
    if (empty($GLOBALS['battle_action_context']['transaction'])) DB::query('COMMIT');
}

function battle_action_transaction_rollback()
{
    if (!empty($GLOBALS['battle_action_context']['transaction'])) battle_action_rollback();
    else DB::query('ROLLBACK');
}

function battle_action_fail($message, $code, $error_code)
{
    battle_action_rollback();
    api_error($message, $code, null, $error_code);
}

/** Returns a stored success envelope, or null when the endpoint must execute. */
function battle_action_begin($action, $input = null)
{
    $mutations = ['start', 'turn', 'flee', 'capture', 'use_item', 'use_item_on_skill', 'switch_pokemon', 'replace_pokemon'];
    if ($action !== 'recover' && !in_array($action, $mutations, true)) return null;
    require_login();
    global $_G;
    if ($input === null) $input = get_json_input();
    if (!is_array($input)) battle_action_fail('无效的战斗请求', 400, 'invalid_battle_request');
    $key = $action !== 'recover' && isset($input['request_id']) ? $input['request_id'] : null;
    if ($key !== null) {
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{16,64}\z/D', $key)
            || !isset($input['engine_battle_id'], $input['expected_revision'])
            || !is_int($input['engine_battle_id']) || $input['engine_battle_id'] < 0
            || !is_int($input['expected_revision']) || $input['expected_revision'] < 0
            || ($action === 'start' && ($input['engine_battle_id'] !== 0 || $input['expected_revision'] !== 0))
            || ($action !== 'start' && $input['engine_battle_id'] === 0)) {
            battle_action_fail('无效的战斗请求编号或版本', 400, 'invalid_battle_request');
        }
    }
    // DDL may implicitly commit in MySQL. Finish every lazy schema change before
    // acquiring the account lock or modifying gameplay data.
    battle_ensure_tables();
    $uid = intval($_G['uid']);
    $hash = hash('sha256', json_encode(battle_action_canonical([
        'uid' => $uid, 'username' => strval($_G['username']), 'action' => $action,
        'query' => isset($_GET) ? $_GET : [], 'form' => isset($_POST) ? $_POST : [], 'json' => $input,
    ]), JSON_UNESCAPED_UNICODE));
    DB::query('START TRANSACTION');
    $GLOBALS['battle_action_context'] = [
        'transaction' => true, 'uid' => $uid, 'action' => $action,
        'request_id' => $key, 'payload_hash' => $hash, 'battle_id' => 0,
    ];
    $account = DB::fetch_first(pm_sql('SELECT uid FROM ' . pm_table('pm_usersdata') . ' WHERE uid = %d FOR UPDATE', $uid));
    if (!$account) battle_action_fail('用户数据不存在', 404, 'battle_account_missing');

    if ($key !== null) {
        $receipt = DB::fetch_first(pm_sql(
            'SELECT * FROM ' . pm_table('pm_battle_action') . ' WHERE uid = %d AND request_id = %s FOR UPDATE', $uid, $key
        ));
        if ($receipt) {
            if ($receipt['action'] !== $action || $receipt['payload_hash'] !== $hash) {
                battle_action_fail('此请求编号已用于其他操作', 409, 'request_id_conflict');
            }
            if (intval($receipt['created_at']) < time() - 30 * 86400 || $receipt['response_json'] === null) {
                battle_action_fail('此请求已过重送期限，请恢复战斗状态', 409, 'request_expired');
            }
            $response = json_decode($receipt['response_json'], true);
            if (!is_array($response) || empty($response['success'])) throw new RuntimeException('Invalid battle receipt');
            DB::query('COMMIT');
            $GLOBALS['battle_action_context'] = null;
            return $response;
        }
    }
    $current = DB::fetch_first(pm_sql(
        "SELECT * FROM " . pm_table('pm_battle') . " WHERE uid = %d AND phase IN ('active', 'awaiting_switch') ORDER BY id DESC LIMIT 1 FOR UPDATE", $uid
    ));
    if ($key !== null) {
        $valid = $action === 'start' ? !$current : ($current
            && intval($current['id']) === $input['engine_battle_id']
            && intval($current['revision']) === $input['expected_revision']
            && (intval($current['updated_at']) === 0 || intval($current['updated_at']) >= time() - 86400));
        if (!$valid) battle_action_fail('战斗状态已改变，请恢复后重试', 409, 'battle_state_conflict');
    }
    if ($current) $GLOBALS['battle_action_context']['battle_id'] = intval($current['id']);
    return null;
}

/** Attach authoritative reconnect data, save the response, then commit once. */
function battle_action_finalize($response)
{
    global $_G;
    $context = isset($GLOBALS['battle_action_context']) ? $GLOBALS['battle_action_context'] : null;
    if ($context && !$context['transaction']) throw new RuntimeException('Battle request was rolled back');
    $is_scene = isset($response['data']['battle_id']) && is_string($response['data']['battle_id']);
    if (!$context && !$is_scene) return $response;
    $uid = $context ? $context['uid'] : intval($_G['uid']);
    // Legacy start may expire the old encounter and create a fresh one.
    $battle_id = $context && $context['action'] !== 'start' ? $context['battle_id'] : 0;
    $row = DB::fetch_first($battle_id > 0
        ? pm_sql('SELECT * FROM ' . pm_table('pm_battle') . ' WHERE id = %d AND uid = %d FOR UPDATE', $battle_id, $uid)
        : pm_sql('SELECT * FROM ' . pm_table('pm_battle') . ' WHERE uid = %d ORDER BY id DESC LIMIT 1 FOR UPDATE', $uid));
    // PP item selection only opens a menu; it does not advance the battle.
    if ($row && $context && $context['action'] !== 'recover' && empty($response['data']['requires_skill_selection'])) {
        DB::query(pm_sql('UPDATE ' . pm_table('pm_battle') . ' SET revision = revision + 1 WHERE id = %d AND uid = %d', $row['id'], $uid));
        $row['revision'] = intval($row['revision']) + 1;
    }
    if (is_array($response['data'])) {
        $response['data']['engine_battle_id'] = $row ? intval($row['id']) : 0;
        $response['data']['revision'] = $row ? intval($row['revision']) : 0;
        $response['data']['phase'] = $row ? $row['phase'] : 'ended';
    }
    if ($context && $context['request_id'] !== null) {
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) throw new RuntimeException('Battle response cannot be encoded');
        DB::query(pm_sql('INSERT INTO ' . pm_table('pm_battle_action') . '
            (uid, request_id, action, payload_hash, battle_id, response_json, created_at)
            VALUES (%d, %s, %s, %s, %d, %s, %d)',
            $uid, $context['request_id'], $context['action'], $context['payload_hash'],
            $row ? $row['id'] : 0, $encoded, time()));
        // Retain small tombstones so an expired start key can never start again.
        DB::query(pm_sql('UPDATE ' . pm_table('pm_battle_action') . ' SET response_json = NULL
            WHERE uid = %d AND created_at < %d AND response_json IS NOT NULL', $uid, time() - 30 * 86400));
    }
    if ($context && $context['transaction']) {
        DB::query('COMMIT');
        $GLOBALS['battle_action_context'] = null;
    }
    return $response;
}
