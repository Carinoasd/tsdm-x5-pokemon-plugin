<?php

function list_pokemon_info($uid, $from, $count)
{
  $ret = [];
  $rows = DB::fetch_all("SELECT * from pm_mypm where `uid`='$uid' limit $from,$count");
  foreach ($rows as $query) {
      $pokemon_id = intval($query['id']);
      $skills = [];
      $skill_rows = DB::fetch_all("SELECT * from pm_myskill where `uid`='$uid' and `petid`='$pokemon_id'");
      foreach ($skill_rows as $query_skill) {
          array_push($skills, new_pokemon_skill_info($query_skill['skillid'], $query_skill['skillnum']));
        }

      $item = new_pokemon_info(
        $pokemon_id,
        intval($query['species_id']),
        $uid,
        $query['nickname'],
        translate_pokemon_site_id_to_label(intval($query['site'])),
        intval($query['level']),
        intval($query['exp']),
        intval($query['good']),
        intval($query['ballid']),
        intval($query['is_shiny']) == 1,
        translate_pokemon_status_id_to_label(intval($query['state'])),
        translate_pokemon_sex_id_to_label(intval($query['sex'])),
        new_pokemon_attributes(
          intval($query['hpg']),
          intval($query['atkg']),
          intval($query['defg']),
          intval($query['spatkg']),
          intval($query['spdefg']),
          intval($query['sdg'])
        ),
        new_pokemon_attributes(
          intval($query['hpn']),
          intval($query['atkn']),
          intval($query['defn']),
          intval($query['spatkn']),
          intval($query['spdefn']),
          intval($query['sdn'])
        ),
        $skills,
        [
          intval($query['equipmentid1']) > 0 ? $query['equipmentid1'] : null,
          intval($query['equipmentid2']) > 0 ? $query['equipmentid2'] : null,
          intval($query['equipmentid3']) > 0 ? $query['equipmentid3'] : null,
          intval($query['equipmentid4']) > 0 ? $query['equipmentid4'] : null,
        ]
      );
      array_push($ret, $item);
    }

  return $ret;
}

function get_pokemon_info($id)
{
  $id = intval($id);
  $ret = [];

  if ($query = DB::fetch_first("SELECT * from pm_mypm where `id`='$id'")) {
    $uid = intval($query['uid']);
    $pokemon_id = intval($query['id']);
    $skills = [];
    $skill_rows = DB::fetch_all("SELECT * from pm_myskill where `uid`='$uid' and `petid`='$pokemon_id'");
    foreach ($skill_rows as $query_skill) {
        array_push($skills, new_pokemon_skill_info($query_skill['skillid'], $query_skill['skillnum']));
      }

    array_push($ret, new_pokemon_info(
      $pokemon_id,
      intval($query['species_id']),
      $uid,
      $query['nickname'],
      translate_pokemon_site_id_to_label(intval($query['site'])),
      intval($query['level']),
      intval($query['exp']),
      intval($query['good']),
      intval($query['ballid']),
      intval($query['is_shiny']) == 1,
      translate_pokemon_status_id_to_label(intval($query['state'])),
      translate_pokemon_sex_id_to_label(intval($query['sex'])),

      new_pokemon_attributes(
        intval($query['hpg']),
        intval($query['atkg']),
        intval($query['defg']),
        intval($query['spatkg']),
        intval($query['spdefg']),
        intval($query['sdg'])
      ),
      new_pokemon_attributes(
        intval($query['hpn']),
        intval($query['atkn']),
        intval($query['defn']),
        intval($query['spatkn']),
        intval($query['spdefn']),
        intval($query['sdn'])
      ),
      $skills,
      [
        intval($query['equipmentid1']) > 0 ? $query['equipmentid1'] : null,
        intval($query['equipmentid2']) > 0 ? $query['equipmentid2'] : null,
        intval($query['equipmentid3']) > 0 ? $query['equipmentid3'] : null,
        intval($query['equipmentid4']) > 0 ? $query['equipmentid4'] : null,
      ]
    ));

    return $ret;
  } else {
    $json_ret = [];
    $json_ret["success"] = false;
    $json_ret["reason"] = "无法查询宠物信息 #$id";
    exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }
}

function admin_pokemon_fail($reason, $rollback = false)
{
  if ($rollback) DB::query('ROLLBACK');
  exit(json_encode(['success' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE));
}

function admin_pokemon_lock_owner($uid)
{
  $owner = DB::fetch_first("SELECT uid, npcid FROM pm_usersdata WHERE uid=$uid FOR UPDATE");
  if (!$owner) admin_pokemon_fail("未找到用户 #$uid", true);
  return $owner;
}

function admin_pokemon_validate_skills($skills, $uid, $petid = null)
{
  if (!is_array($skills)) admin_pokemon_fail('技能数据格式错误', true);
  $seen = [];
  foreach ($skills as $skill) {
    if (!is_array($skill)) admin_pokemon_fail('技能数据格式错误', true);
    $id = $skill['type_id'] ?? null;
    $count = $skill['count'] ?? null;
    if ((!is_int($id) && !is_string($id)) || !filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 16777215]]) ||
        (!is_int($count) && !is_string($count)) || filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 32767]]) === false) {
      admin_pokemon_fail('技能编号或次数无效', true);
    }
    $id = intval($id);
    if (isset($seen[$id])) admin_pokemon_fail('技能不能重复', true);
    $seen[$id] = true;
    if ($petid === null) {
      $row = DB::fetch_first("SELECT id FROM pm_skill WHERE id=$id FOR UPDATE");
    } else {
      $row = DB::fetch_first("SELECT id FROM pm_myskill WHERE uid=$uid AND petid=$petid AND skillid=$id FOR UPDATE");
    }
    if (!$row) admin_pokemon_fail("宠物信息更新失败，未找到技能信息 #$id", true);
  }
}

function admin_pokemon_replacement($uid, $id)
{
  $replacement = DB::fetch_first("SELECT id FROM pm_mypm WHERE uid=$uid AND id!=$id ORDER BY site ASC, id ASC LIMIT 1 FOR UPDATE");
  if (!$replacement) admin_pokemon_fail('必须保留一只首位宠物', true);
  $next_id = intval($replacement['id']);
  DB::query("UPDATE pm_mypm SET site=1 WHERE id=$next_id AND uid=$uid");
}

function admin_pokemon_prepare_site($owner, $id, $old_site, $site)
{
  $uid = intval($owner['uid']);
  if (intval($owner['npcid']) > 0 && ($old_site === 1 || $site === 1)) {
    admin_pokemon_fail('战斗中的首位宠物无法修改或替换', true);
  }
  if ($old_site === null && !DB::fetch_first("SELECT id FROM pm_mypm WHERE uid=$uid AND site=1 FOR UPDATE")) {
    // As with the first capture, a first grant must leave the player a leader.
    if (intval($owner['npcid']) > 0) admin_pokemon_fail('战斗中无法替换首位宠物', true);
    $site = 1;
  }
  if ($site === 1) {
    DB::query("UPDATE pm_mypm SET site=2 WHERE uid=$uid AND site=1 AND id!=$id");
  } elseif ($old_site === 1) {
    admin_pokemon_replacement($uid, $id);
  }
  return $site;
}

function set_pokemon_info($info)
{
  $id = intval($info["id"]);
  $uid = intval($info['owner'] ?? 0);
  // Translate before starting the transaction: these legacy helpers may exit.
  $site = translate_pokemon_site_label_to_id($info['site'] ?? 'header');
  $state = translate_pokemon_status_label_to_id($info['status'] ?? 'normal');
  DB::query('START TRANSACTION');
  try {
  $owner = admin_pokemon_lock_owner($uid);
  if ($query = DB::fetch_first("SELECT * from pm_mypm where `id`='$id' FOR UPDATE")) {
    // 提前检查，禁止修改持有用户
    if (intval($query['uid']) != intval($info["owner"])) {
      admin_pokemon_fail("无法修改宠物信息，禁止修改持有用户信息 #$id", true);
    }

    admin_pokemon_validate_skills($info['skills'] ?? [], $uid, $id);
    $site = admin_pokemon_prepare_site($owner, $id, intval($query['site']), $site);

    if ($query['nickname'] != ($info["name"] ?? $query['nickname'])) {
      DB::query("UPDATE pm_mypm set `nickname`='" . addslashes($info["name"] ?? $query['nickname']) . "' where `id`='$id'");
    }
    if (intval($query['site']) != $site) {
      DB::query("UPDATE pm_mypm set `site`='$site' where `id`='$id'");
    }

    if (intval($query['level']) != intval($info["level"] ?? $query['level'])) {
      DB::query("UPDATE pm_mypm set `level`='" . intval($info["level"] ?? $query['level']) . "' where `id`='$id'");
    }
    if (intval($query['exp']) != intval($info["experience"] ?? $query['exp'])) {
      DB::query("UPDATE pm_mypm set `exp`='" . intval($info["experience"] ?? $query['exp']) . "' where `id`='$id'");
    }
    if (intval($query['good']) != intval($info["intimacy"] ?? $query['good'])) {
      DB::query("UPDATE pm_mypm set `good`='" . intval($info["intimacy"] ?? $query['good']) . "' where `id`='$id'");
    }
    if (intval($query['ballid']) != intval($info["using_ball_id"] ?? $query['ballid'])) {
      DB::query("UPDATE pm_mypm set `ballid`='" . intval($info["using_ball_id"] ?? $query['ballid']) . "' where `id`='$id'");
    }
    if (boolval($query['is_shiny']) != boolval($info["is_shiny"] ?? false)) {
      DB::query("UPDATE pm_mypm set `is_shiny`='" . (boolval($info["is_shiny"] ?? false) ? 1 : 0) . "' where `id`='$id'");
    }
    if ($query['state'] != $state) {
      DB::query("UPDATE pm_mypm set `state`='$state' where `id`='$id'");
    }
    if ($query['sex'] != translate_pokemon_sex_label_to_id($info["sex"] ?? "male")) {
      DB::query("UPDATE pm_mypm set `sex` ='" . translate_pokemon_sex_label_to_id($info["sex"] ?? "male") . "' where `id`='$id'");
    }

    $new_hpg = intval($info["statistic"]["hit_points"]);
    $new_atkg = intval($info["statistic"]["attack"]);
    $new_defg = intval($info["statistic"]["defense"]);
    $new_spatkg = intval($info["statistic"]["special_attack"]);
    $new_spdefg = intval($info["statistic"]["special_defense"]);
    $new_sdg = intval($info["statistic"]["speed"]);
    if (
      intval($query['hpg']) != $new_hpg ||
      intval($query['atkg']) != $new_atkg ||
      intval($query['defg']) != $new_defg ||
      intval($query['spatkg']) != $new_spatkg ||
      intval($query['spdefg']) != $new_spdefg ||
      intval($query['sdg']) != $new_sdg
    ) {
      DB::query("UPDATE pm_mypm set hpg=$new_hpg, atkg=$new_atkg, defg=$new_defg, spatkg=$new_spatkg, spdefg=$new_spdefg, sdg=$new_sdg where id='$id'");
    }
    $new_hpn = intval($info["base_points"]["hit_points"]);
    $new_atkn = intval($info["base_points"]["attack"]);
    $new_defn = intval($info["base_points"]["defense"]);
    $new_spatkn = intval($info["base_points"]["special_attack"]);
    $new_spdefn = intval($info["base_points"]["special_defense"]);
    $new_sdn = intval($info["base_points"]["speed"]);
    if (
      intval($query['hpn']) != $new_hpn ||
      intval($query['atkn']) != $new_atkn ||
      intval($query['defn']) != $new_defn ||
      intval($query['spatkn']) != $new_spatkn ||
      intval($query['spdefn']) != $new_spdefn ||
      intval($query['sdn']) != $new_sdn
    ) {
      DB::query("UPDATE pm_mypm set hpn=$new_hpn, atkn=$new_atkn, defn=$new_defn, spatkn=$new_spatkn, spdefn=$new_spdefn, sdn=$new_sdn where id='$id'");
    }

    if (intval($query['equipmentid1']) != intval($info["armor_slots_id"][0] ?? 0)) {
      DB::query("UPDATE pm_mypm set `equipmentid1`='" . intval($info["armor_slots_id"][0] ?? 0) . "' where `id`='$id'");
    }
    if (intval($query['equipmentid2']) != intval($info["armor_slots_id"][1] ?? 0)) {
      DB::query("UPDATE pm_mypm set `equipmentid2`='" . intval($info["armor_slots_id"][1] ?? 0) . "' where `id`='$id'");
    }
    if (intval($query['equipmentid3']) != intval($info["armor_slots_id"][2] ?? 0)) {
      DB::query("UPDATE pm_mypm set `equipmentid3`='" . intval($info["armor_slots_id"][2] ?? 0) . "' where `id`='$id'");
    }
    if (intval($query['equipmentid4']) != intval($info["armor_slots_id"][3] ?? 0)) {
      DB::query("UPDATE pm_mypm set `equipmentid4`='" . intval($info["armor_slots_id"][3] ?? 0) . "' where `id`='$id'");
    }

    // 额外更新技能数据表
    if (!empty($info["skills"])) {
    foreach ($info["skills"] as $skill) {
      $uid = intval($info["owner"] ?? $query['uid']);
      $petid = intval($id);
      $skillid = intval($skill["type_id"]);
      $skillnum = intval($skill["count"]);

      if ($query = DB::fetch_first("SELECT * FROM pm_myskill WHERE `uid`='$uid' AND `petid`='$petid' AND `skillid`='$skillid'")) {
        if (intval($query['skillnum']) != $skillnum) {
          DB::query("UPDATE pm_myskill set `skillnum`='$skillnum' where `uid`='$uid' AND `petid`='$petid' AND `skillid`='$skillid'");
        }
      } else {
        admin_pokemon_fail("宠物信息更新失败，未找到技能信息 #$id", true);
      }
    }
    }
  } else {
    admin_pokemon_fail("宠物信息更新失败，未找到 #$id", true);
  }
  DB::query('COMMIT');
  } catch (Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
  }
}

function insert_pokemon_info($info)
{
  $pmno = intval($info["type_id"]);
  $uid = intval($info["owner"]);
  $nowname = strval($info["name"]);
  $site = translate_pokemon_site_label_to_id($info["site"]);

  $level = intval($info["level"]);
  $exp = intval($info["experience"]);
  $good = intval($info["intimacy"]);
  $ballid = intval($info["using_ball_id"]);
  $sg = boolval($info["is_shiny"]) ? 1 : 0;
  $state = translate_pokemon_status_label_to_id($info["status"]);
  $sex = translate_pokemon_sex_label_to_id($info["sex"]);

  $hpg = intval($info["statistic"]["hit_points"]);
  $atkg = intval($info["statistic"]["attack"]);
  $defg = intval($info["statistic"]["defense"]);
  $spatkg = intval($info["statistic"]["special_attack"]);
  $spdefg = intval($info["statistic"]["special_defense"]);
  $sdg = intval($info["statistic"]["speed"]);

  $hpn = intval($info["base_points"]["hit_points"]);
  $atkn = intval($info["base_points"]["attack"]);
  $defn = intval($info["base_points"]["defense"]);
  $spatkn = intval($info["base_points"]["special_attack"]);
  $spdefn = intval($info["base_points"]["special_defense"]);
  $sdn = intval($info["base_points"]["speed"]);

  $equipmentid1 = is_null($info["armor_slots_id"][0]) ? 0 : intval($info["armor_slots_id"][0]);
  $equipmentid2 = is_null($info["armor_slots_id"][1]) ? 0 : intval($info["armor_slots_id"][1]);
  $equipmentid3 = is_null($info["armor_slots_id"][2]) ? 0 : intval($info["armor_slots_id"][2]);
  $equipmentid4 = is_null($info["armor_slots_id"][3]) ? 0 : intval($info["armor_slots_id"][3]);

  DB::query('START TRANSACTION');
  try {
  $owner = admin_pokemon_lock_owner($uid);
  // Validate all references before changing either the leader or the new pet.
  $type_data = DB::fetch_first("SELECT * from pm_data where id='$pmno' FOR UPDATE");
  if (!$type_data) {
    admin_pokemon_fail("未找到宠物类型 #$pmno", true);
  }
  admin_pokemon_validate_skills($info['skills'] ?? [], $uid);
  $site = admin_pokemon_prepare_site($owner, 0, null, $site);

  $pmname = addslashes($type_data['name']);
  $sx = addslashes($type_data['xs']);
  $nickname = addslashes($nowname);
  $current_time = time();

  DB::query("INSERT into pm_mypm (
      `species_id`, `uid`, `pmname`, `nickname`, `site`,
      `level`, `exp`, `good`, `sex`, `sx`,
      `ballid`, `is_shiny`, `state`, `statetime`, `gduptime`,
      `initialuid`, `created_at`,
      `hpg`, `atkg`, `defg`, `spatkg`, `spdefg`, `sdg`,
      `hpn`, `atkn`, `defn`, `spatkn`, `spdefn`, `sdn`,
      `equipmentid1`, `equipmentid2`, `equipmentid3`, `equipmentid4`
    ) values (
      '$pmno', '$uid', '$pmname', '$nickname', '$site',
      '$level', '$exp', '$good', '$sex', '$sx',
      '$ballid', '$sg', '$state', '$current_time', '$current_time',
      '$uid', '$current_time',
      '$hpg', '$atkg', '$defg', '$spatkg', '$spdefg', '$sdg',
      '$hpn', '$atkn', '$defn', '$spatkn', '$spdefn', '$sdn',
      '$equipmentid1', '$equipmentid2', '$equipmentid3', '$equipmentid4'
    )");

  $new_id = intval(DB::insert_id());

  // 满血入库，血量口径与 api_calculate_pokemon_max_hp 保持一致
  $max_hp = api_calculate_pokemon_max_hp(
    [
      'species_id' => $pmno,
      'level' => $level,
      'hpg' => $hpg,
      'hpn' => $hpn,
      'state' => $state,
      'is_shiny' => $sg,
      'equipmentid1' => $equipmentid1,
      'equipmentid2' => $equipmentid2,
      'equipmentid3' => $equipmentid3,
      'equipmentid4' => $equipmentid4,
    ],
    $type_data
  );
  DB::query("UPDATE pm_mypm set `hp`='$max_hp' WHERE `id`='$new_id'");

  // 额外更新技能数据表
  foreach (($info["skills"] ?? []) as $skill) {
    $skillid = intval($skill["type_id"]);
    $skillnum = intval($skill["count"]);

    DB::query("INSERT into pm_myskill (
        `uid`, `petid`, `skillid`, `skillnum`
      ) values (
        '$uid', '$new_id', '$skillid', '$skillnum'
      )");
  }
  DB::query('COMMIT');
  } catch (Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
  }
  return $new_id;
}

function delete_pokemon_info($id)
{
  $id = intval($id);

  // Discover the immutable owner before taking the shared account lock.
  $pokemon = DB::fetch_first("SELECT `uid`, `site` FROM pm_mypm WHERE `id`='$id'");
  if (!$pokemon) {
    admin_pokemon_fail("未找到宠物 #$id");
  }

  $uid = intval($pokemon['uid']);
  DB::query('START TRANSACTION');
  try {
  $owner = admin_pokemon_lock_owner($uid);
  $pokemon = DB::fetch_first("SELECT uid, site FROM pm_mypm WHERE id=$id AND uid=$uid FOR UPDATE");
  if (!$pokemon) admin_pokemon_fail("未找到宠物 #$id", true);
  $site = intval($pokemon['site']);
  if ($site === 1 && intval($owner['npcid']) > 0) admin_pokemon_fail('战斗中的首位宠物无法放生', true);

  // 检查玩家的宠物总数
  $total_count = intval(DB::result_first("SELECT COUNT(*) FROM pm_mypm WHERE `uid`='$uid'"));
  if ($total_count <= 1) {
    admin_pokemon_fail('无法放生最后一只宠物', true);
  }

  // 如果是首位宠物（site=1），需要选择替补
  if ($site === 1) {
    admin_pokemon_replacement($uid, $id);
  }

  // 删除宠物
  DB::query("DELETE FROM pm_mypm WHERE `id`='$id' AND uid=$uid");
  DB::query("DELETE FROM pm_myskill WHERE `petid`='$id' AND uid=$uid");
  DB::query('COMMIT');
  } catch (Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
  }
}
