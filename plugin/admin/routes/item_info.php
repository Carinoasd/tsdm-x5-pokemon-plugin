<?php
function list_item_info($uid, $from, $count)
{
  $ret = [];
  $rows = DB::fetch_all("SELECT * from pm_myitem where `uid`='$uid' limit $from,$count");
  foreach ($rows as $query) {
      array_push($ret, new_item_info(
        intval($query['id']),
        $uid,
        intval($query['itemid']),
        intval($query['nums'])
      ));
    }

  return $ret;
}

function get_item_info($id)
{
  $id = intval($id);
  $ret = [];

  if ($query = DB::fetch_first("SELECT * from pm_myitem where `id`='$id'")) {
    array_push($ret, new_item_info(
      intval($query['id']),
      intval($query['uid']),
      intval($query['itemid']),
      intval($query['nums'])
    ));

    return $ret;
  } else {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "无法查询物品信息 #$id";
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }
}

function admin_item_fail($reason, $rollback = false)
{
  if ($rollback) DB::query('ROLLBACK');
  exit(json_encode(['success' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE));
}

function admin_item_count($value)
{
  // pm_myitem.nums is a signed SMALLINT. Reject coercions and overflow before writing.
  $count = (is_int($value) || is_string($value))
    ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 32767]])
    : false;
  if ($count === false) admin_item_fail('物品数量必须是 1 至 32767 的整数');
  return $count;
}

function admin_item_lock_owner($uid)
{
  if (!DB::fetch_first("SELECT uid FROM pm_usersdata WHERE uid=$uid FOR UPDATE")) {
    admin_item_fail("未找到用户 #$uid", true);
  }
}

function admin_item_equipped_slots($id)
{
  $count = 0;
  $rows = DB::fetch_all("SELECT equipmentid1, equipmentid2, equipmentid3, equipmentid4 FROM pm_mypm
    WHERE equipmentid1=$id OR equipmentid2=$id OR equipmentid3=$id OR equipmentid4=$id");
  foreach ($rows as $row) {
    for ($slot = 1; $slot <= 4; $slot++) {
      if (intval($row['equipmentid' . $slot]) === $id) $count++;
    }
  }
  return $count;
}

function set_item_info($info)
{
  $id = intval($info['id'] ?? 0);
  $uid = intval($info['owner'] ?? 0);
  $itemid = intval($info['type_id'] ?? 0);
  $nums = admin_item_count($info['count'] ?? null);
  DB::query('START TRANSACTION');
  try {
    admin_item_lock_owner($uid);
    $row = DB::fetch_first("SELECT * FROM pm_myitem WHERE id=$id FOR UPDATE");
    if (!$row) admin_item_fail("无法查询物品信息 #$id", true);
    if (intval($row['uid']) !== $uid) admin_item_fail("禁止修改持有用户信息 #$id", true);
    if (!DB::fetch_first("SELECT id FROM pm_itemdata WHERE id=$itemid")) {
      admin_item_fail("未找到物品类型 #$itemid", true);
    }
    $occupied = admin_item_equipped_slots($id);
    if ($occupied && intval($row['itemid']) !== $itemid) {
      admin_item_fail('物品仍在装备中，请先卸下装备再修改类型', true);
    }
    if ($nums < $occupied) admin_item_fail('物品数量不能少于正在使用的装备槽数', true);
    DB::query("UPDATE pm_myitem SET itemid='$itemid', nums=$nums WHERE id=$id AND uid=$uid");
    DB::query('COMMIT');
  } catch (Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
  }
}

function insert_item_info($info)
{
  $uid = intval($info['owner'] ?? 0);
  $itemid = intval($info['type_id'] ?? 0);
  $nums = admin_item_count($info['count'] ?? null);

  // Share the account lock with purchases, item use and equipment changes.
  DB::query('START TRANSACTION');
  try {
    admin_item_lock_owner($uid);
    if (!DB::fetch_first("SELECT id FROM pm_itemdata WHERE id=$itemid")) {
      admin_item_fail("未找到物品类型 #$itemid", true);
    }
    $existing = DB::fetch_first("SELECT id, nums FROM pm_myitem WHERE uid=$uid AND itemid='$itemid' ORDER BY id LIMIT 1 FOR UPDATE");
    if ($existing) {
      $id = intval($existing['id']);
      $limit = 32767 - $nums;
      DB::query("UPDATE pm_myitem SET nums = nums + $nums WHERE id=$id AND uid=$uid AND nums >= 0 AND nums <= $limit");
      if (!DB::affected_rows()) admin_item_fail('物品总数量超出范围，请检查现有数量', true);
    } else {
      DB::query("INSERT INTO pm_myitem (uid,itemid,nums) VALUES ($uid,'$itemid',$nums)");
      $id = intval(DB::insert_id());
    }
    DB::query('COMMIT');
  } catch (Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
  }
  return $id;
}

function delete_item_info($id)
{
  $id = intval($id);
  // Discover the immutable owner, then re-read inventory after taking its lock.
  $row = DB::fetch_first("SELECT uid FROM pm_myitem WHERE id=$id");
  if (!$row) admin_item_fail("无法查询物品信息 #$id");
  $uid = intval($row['uid']);
  DB::query('START TRANSACTION');
  try {
    admin_item_lock_owner($uid);
    $row = DB::fetch_first("SELECT * FROM pm_myitem WHERE id=$id AND uid=$uid FOR UPDATE");
    if (!$row) admin_item_fail("无法查询物品信息 #$id", true);
    if (admin_item_equipped_slots($id) > 0) admin_item_fail('物品仍在装备中，请先卸下装备再删除', true);
    DB::query("DELETE FROM pm_myitem WHERE id=$id AND uid=$uid");
    DB::query('COMMIT');
  } catch (Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
  }
}
