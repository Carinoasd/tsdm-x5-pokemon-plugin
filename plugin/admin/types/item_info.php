<?php

function new_item_info(
  $id,
  $owner,
  $type_id,
  $count
) {
  $ret = [];
  $ret["_TYPE"] = "item_info";

  $ret["id"] = intval($id);
  $ret["owner"] = $owner;
  $ret["type_id"] = intval($type_id);
  $ret["count"] = intval($count);

  return $ret;
}
