<?php

/**
 * pm_effect 管理（战斗引擎 2.0 效果模板）。
 *
 * params_json 必须内嵌合法的核心效果声明（{"code":..., ...}），
 * hooks_json 必须是钩子数组；写入前经 battle_core_validate_effect 严格
 * 校验，拒绝未知效果码/钩子/参数，避免"存了不支持的效果把数据搞坏"。
 */

function count_effect_data()
{
  $count = DB::result_first("SELECT count(*) from pm_effect");
  $item = [];
  $item["count"] = intval($count);
  $item["_TYPE"] = "::count";
  return [$item];
}

function list_effect_data($from, $count)
{
  $from = intval($from);
  $count = intval($count);
  $ret = [];
  $rows = DB::fetch_all("SELECT * from pm_effect order by `id` asc limit $from,$count");
  foreach ((array)$rows as $row) {
    $ret[] = effect_row_to_item($row);
  }
  return $ret;
}

function get_effect_data($id)
{
  $id = intval($id);
  $row = DB::fetch_first("SELECT * from pm_effect where id=$id");
  if ($row) {
    return [effect_row_to_item($row)];
  }
  $json_ret = [];
  $json_ret["success"] = false;
  $json_ret["reason"] = "无法查询效果 #$id";
  exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
}

function effect_row_to_item($row)
{
  $item = [
    "id" => intval($row["id"]),
    "code" => $row["code"],
    "kind" => $row["kind"],
    "hooks" => json_decode($row["hooks_json"], true) ?: [],
    "params" => json_decode($row["params_json"], true) ?: [],
    "description" => $row["description"],
    "version" => intval($row["version"]),
  ];
  $item["_TYPE"] = "effect_data";
  return $item;
}

/**
 * 组装并校验一条核心效果声明（来自 hooks/params 字段）。
 * 返回 null 表示数据非法（拒绝写入）。
 */
function build_effect_declaration($hooks, $params, $kind, $version)
{
  if (!is_array($hooks) || !array_is_list($hooks) || empty($hooks)
    || !is_array($params) || !isset($params['code']) || !is_string($params['code'])
    || !is_string($kind) || !is_int($version) || $version < 1 || $version > 4294967295) {
    return null;
  }
  foreach ($hooks as $hook) {
    if (!is_string($hook)) return null;
  }
  // The editor exposes only hooks that skill-mounted effects actually execute.
  if ($kind !== 'move' || count($hooks) !== 1) return null;
  // Validate JSON scalar types before the engine validator casts numbers.
  if ($params['code'] === 'stages_boost') {
    if (!in_array($hooks[0], ['on_after_move', 'on_hit'], true)
      || array_diff(array_keys($params), ['code', 'stat', 'stages', 'target'])) return null;
    if (!isset($params['stat'], $params['stages']) || !is_string($params['stat']) || !is_int($params['stages'])
      || (array_key_exists('target', $params) && !is_string($params['target']))) return null;
  } elseif ($params['code'] === 'status_inflict') {
    if ($hooks !== ['on_hit'] || ($params['status'] ?? null) === 'confusion'
      || array_diff(array_keys($params), ['code', 'status', 'chance'])) return null;
    if (!isset($params['status']) || !is_string($params['status'])
      || (array_key_exists('chance', $params) && !is_int($params['chance']))) return null;
  }
  $effect = [
    "code" => $params["code"],
    "kind" => $kind,
    "hooks" => array_values($hooks),
    "params" => $params,
    "version" => $version,
  ];
  if (battle_core_validate_effect($effect) !== true) {
    return null;
  }
  return $effect;
}

function effect_hooks_match_skill_power($hooks, $power)
{
  return ($hooks === ['on_after_move'] && intval($power) === 0)
    || ($hooks === ['on_hit'] && intval($power) > 0);
}

function effect_row_matches_skill_power($row, $power)
{
  $hooks = json_decode($row['hooks_json'], true);
  $params = json_decode($row['params_json'], true);
  return build_effect_declaration($hooks, $params, $row['kind'], intval($row['version'])) !== null
    && effect_hooks_match_skill_power($hooks, $power);
}

function validate_effect_text($code, $description)
{
  if (!is_string($code) || trim($code) === '' || !preg_match('/^.{1,40}$/usD', $code)) {
    exit(json_encode(['success' => false, 'reason' => 'code 必须是 1 到 40 个字符的非空字符串'], JSON_UNESCAPED_UNICODE));
  }
  if (!is_string($description) || !preg_match('/^.{0,255}$/usD', $description)) {
    exit(json_encode(['success' => false, 'reason' => 'description 必须是不超过 255 个字符的字符串'], JSON_UNESCAPED_UNICODE));
  }
}

function set_effect_data($info)
{
  if (!is_array($info)) {
    exit(json_encode(['success' => false, 'reason' => '效果资料必须是对象'], JSON_UNESCAPED_UNICODE));
  }
  $id = intval($info["id"] ?? 0);
  $row = DB::fetch_first("SELECT * from pm_effect where id=$id");
  if (!$row) {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "效果更新失败，未找到 #$id";
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }

  $hooks = array_key_exists('hooks', $info) ? $info['hooks'] : json_decode($row['hooks_json'], true);
  $params = array_key_exists('params', $info) ? $info['params'] : json_decode($row['params_json'], true);
  $kind = array_key_exists('kind', $info) ? $info['kind'] : $row['kind'];
  $version = array_key_exists('version', $info) ? $info['version'] : intval($row['version']);
  $effect = build_effect_declaration($hooks, $params, $kind, $version);
  if ($effect === null) {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "效果声明校验失败（未知效果码/钩子或参数越界）";
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }

  $code = array_key_exists('code', $info) ? $info['code'] : $row['code'];
  $description = array_key_exists('description', $info) ? $info['description'] : $row['description'];
  validate_effect_text($code, $description);
  $exists = DB::fetch_first("SELECT id from pm_effect where code='" . addslashes($code) . "'");
  if ($exists && intval($exists['id']) !== $id) {
    exit(json_encode(['success' => false, 'reason' => 'code 已存在 #' . $exists['id']], JSON_UNESCAPED_UNICODE));
  }
  $skills = DB::fetch_all("SELECT id, power from pm_skill where effect_id=$id");
  foreach ($skills as $skill) {
    if (!effect_hooks_match_skill_power($effect['hooks'], $skill['power'])) {
      exit(json_encode(['success' => false, 'reason' => '效果时机与引用技能 #' . $skill['id'] . ' 的威力不相容，请先解除关联'], JSON_UNESCAPED_UNICODE));
    }
  }
  $hooks_json = json_encode($effect["hooks"], JSON_UNESCAPED_UNICODE);
  $params_json = json_encode($effect["params"], JSON_UNESCAPED_UNICODE);
  DB::query("UPDATE pm_effect set code='" . addslashes($code) . "', kind='" . addslashes($effect["kind"]) . "', hooks_json='" . addslashes($hooks_json) . "', params_json='" . addslashes($params_json) . "', description='" . addslashes($description) . "', version=" . $effect["version"] . " where id=$id");
}

function insert_effect_data($info)
{
  if (!is_array($info)) {
    exit(json_encode(['success' => false, 'reason' => '效果资料必须是对象'], JSON_UNESCAPED_UNICODE));
  }
  $hooks = $info['hooks'] ?? [];
  $params = $info['params'] ?? [];
  $kind = array_key_exists('kind', $info) ? $info['kind'] : 'move';
  $version = array_key_exists('version', $info) ? $info['version'] : 1;
  $effect = build_effect_declaration($hooks, $params, $kind, $version);
  if ($effect === null) {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "效果声明校验失败（未知效果码/钩子或参数越界）";
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }

  $code = $info['code'] ?? '';
  $description = array_key_exists('description', $info) ? $info['description'] : '';
  validate_effect_text($code, $description);
  $exists = DB::fetch_first("SELECT id from pm_effect where code='" . addslashes($code) . "'");
  if ($exists) {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "code 已存在 #" . $exists["id"];
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }

  $hooks_json = json_encode($effect["hooks"], JSON_UNESCAPED_UNICODE);
  $params_json = json_encode($effect["params"], JSON_UNESCAPED_UNICODE);
  DB::query("INSERT INTO pm_effect (code, kind, hooks_json, params_json, description, version) VALUES (
    '" . addslashes($code) . "', '" . addslashes($effect["kind"]) . "', '" . addslashes($hooks_json) . "', '" . addslashes($params_json) . "', '" . addslashes($description) . "', " . $effect["version"] . "
  )");
  return DB::insert_id();
}

function delete_effect_data($id)
{
  $id = intval($id);
  $in_use = DB::result_first("SELECT count(*) from pm_skill where effect_id=$id");
  if (intval($in_use) > 0) {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "效果 #$id 正被 {$in_use} 个技能引用，先解除关联";
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }
  DB::query("DELETE from pm_effect where id=$id");
}
