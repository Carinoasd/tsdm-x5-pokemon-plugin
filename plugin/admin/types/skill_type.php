<?php

function translate_skill_type_raw_to_id($str)
{
  switch ($str) {
    case '物攻':
      return 'physical_damage';
    case '特攻':
      return 'special_damage';
    default:
      return 'others';
  }
}

function translate_skill_type_obj_to_raw($obj)
{
  if (isset($obj["physical_damage"])) {
    return [
      '物攻',
      translate_kind_id_to_chinese_kind($obj["physical_damage"][0]),
      intval($obj["physical_damage"][1])
    ];
  } else if (isset($obj["special_damage"])) {
    return [
      '特攻',
      translate_kind_id_to_chinese_kind($obj["special_damage"][0]),
      intval($obj["special_damage"][1])
    ];
  } else {
    return [
      '其他',
      translate_kind_id_to_chinese_kind($obj["others"][0]),
      intval($obj["others"][1])
    ];
  }
}

function new_skill_effect($type, $pokemon_kind, $num)
{
  $ret = [];

  switch ($type) {
    case 'physical_damage':
      $ret["physical_damage"] = [$pokemon_kind, intval($num)];
      break;
    case 'special_damage':
      $ret["special_damage"] = [$pokemon_kind, intval($num)];
      break;
    case 'others':
      $ret["others"] = [$pokemon_kind, intval($num)];
      break;
    default:
      $json_ret = [];
      $json_ret["success"] = false;
      $json_ret["reason"] = "未知的技能效果类型 $type $pokemon_kind $num";
      exit(json_encode($json_ret, JSON_UNESCAPED_UNICODE));
  }

  return $ret;
}

function new_skill_type(
  $id,
  $name,
  $available_pokemons,
  $description,

  $min_level_limit,
  $use_times_limit,
  $effect
) {
  $ret = [];
  $ret["_TYPE"] = "skill_type";

  $ret["id"] = intval($id);
  $ret["name"] = $name;
  $ret["available_pokemons"] = $available_pokemons;
  $ret["description"] = $description;

  $ret["min_level_limit"] = intval($min_level_limit);
  $ret["use_times_limit"] = intval($use_times_limit);
  $ret["effect"] = $effect;

  return $ret;
}
