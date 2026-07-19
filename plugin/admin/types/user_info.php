<?php

function new_user_info(
  $uid,
  $name,
  $win_count,
  $lose_count,
  $money,
  $exp,
  $pokemon_list,
  $item_list,
  $extra_fields = []
) {
  $ret = [];
  $ret["_TYPE"] = "user_info";

  $ret["id"] = intval($uid);
  $ret["name"] = $name;
  $ret["win_count"] = intval($win_count);
  $ret["lose_count"] = intval($lose_count);
  $ret["money"] = intval($money);
  $ret["experience"] = intval($exp);

  $ret["pokemon_list"] = $pokemon_list;
  $ret["item_list"] = $item_list;

  $ret["total_battles"] = isset($extra_fields['dataall']) ? intval($extra_fields['dataall']) : 0;
  $ret["strength"] = isset($extra_fields['strength']) ? intval($extra_fields['strength']) : 1;
  $ret["str"] = isset($extra_fields['str']) ? intval($extra_fields['str']) : 100;
  $ret["boxnum"] = isset($extra_fields['boxnum']) ? intval($extra_fields['boxnum']) : 9;
  $ret["allure"] = isset($extra_fields['allure']) ? intval($extra_fields['allure']) : 0;
  $ret["capture"] = isset($extra_fields['capture']) ? intval($extra_fields['capture']) : 0;

  return $ret;
}
