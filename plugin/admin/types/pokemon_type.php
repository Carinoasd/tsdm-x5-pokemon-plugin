<?php

function translate_chinese_kind_to_kind_id($str)
{
  switch ($str) {
    case "普通":
      return "normal";
      break;
    case "火":
      return "fire";
      break;
    case "水":
      return "water";
      break;
    case "草":
      return "grass";
      break;
    case "电":
      return "electric";
      break;
    case "冰":
      return "ice";
      break;
    case "格斗":
      return "fighting";
      break;
    case "毒":
      return "poison";
      break;
    case "地面":
      return "ground";
      break;
    case "飞行":
      return "flying";
      break;
    case "超能":
      return "psychic";
      break;
    case "虫":
      return "bug";
      break;
    case "岩石":
      return "rock";
      break;
    case "幽灵":
      return "ghost";
      break;
    case "龙":
      return "dragon";
      break;
    case "恶":
      return "dark";
      break;
    case "钢":
      return "steel";
      break;
    case "妖精":
      return "fairy";
      break;
    default:
      return null;
  }
}

function translate_kind_id_to_chinese_kind($id)
{
  switch ($id) {
    case "normal":
      return "普通";
      break;
    case "fire":
      return "火";
      break;
    case "water":
      return "水";
      break;
    case "grass":
      return "草";
      break;
    case "electric":
      return "电";
      break;
    case "ice":
      return "冰";
      break;
    case "fighting":
      return "格斗";
      break;
    case "poison":
      return "毒";
      break;
    case "ground":
      return "地面";
      break;
    case "flying":
      return "飞行";
      break;
    case "psychic":
      return "超能";
      break;
    case "bug":
      return "虫";
      break;
    case "rock":
      return "岩石";
      break;
    case "ghost":
      return "幽灵";
      break;
    case "dragon":
      return "龙";
      break;
    case "dark":
      return "恶";
      break;
    case "steel":
      return "钢";
      break;
    case "fairy":
      return "妖精";
      break;
    default:
      return null;
  }
}

function new_pokemon_attributes(
  $hit_points,
  $attack,
  $defense,
  $special_attack,
  $special_defense,
  $speed
) {
  $ret = [];

  $ret["hit_points"] = intval($hit_points);
  $ret["attack"] = intval($attack);
  $ret["defense"] = intval($defense);
  $ret["special_attack"] = intval($special_attack);
  $ret["special_defense"] = intval($special_defense);
  $ret["speed"] = intval($speed);

  return $ret;
}

function new_pokemon_type(
  $id,
  $name,
  $description,

  $cost,
  $is_selling,

  $sex_weight,
  $initial_statistic,
  $initial_base_points,
  $kind,
  $is_legendary,

  $map_ids,
  $evolution_info_ids,
  $capture_weight,
  $meet_weight,
  $birth_order,
  $strength_weight,
  $drop_money_range
) {
  $ret = [];
  $ret["_TYPE"] = "pokemon_type";

  $ret["id"] = $id;
  $ret["name"] = $name;
  $ret["description"] = $description;

  $ret["cost"] = $cost;
  $ret["is_selling"] = $is_selling;

  $ret["sex_weight"] = $sex_weight;
  $ret["initial_statistic"] = $initial_statistic;
  $ret["initial_base_points"] = $initial_base_points;
  $ret["kind"] = $kind;
  $ret["is_legendary"] = $is_legendary;

  $ret["map_ids"] = $map_ids;
  $ret["evolution_info_ids"] = $evolution_info_ids;

  $ret["capture_weight"] = $capture_weight;
  $ret["meet_weight"] = $meet_weight;
  $ret["birth_order"] = $birth_order;
  $ret["strength_weight"] = $strength_weight;
  $ret["drop_money_range"] = $drop_money_range;

  return $ret;
}
