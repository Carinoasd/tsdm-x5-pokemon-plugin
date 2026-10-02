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
  // pm_skill 仅能保存 category/element/power。拒绝尚无存储或战斗实现的
  // tagged 效果，避免将治疗等效果静默写成空属性、零威力的「其他」。
  $categories = [
    'physical_damage' => '物攻',
    'special_damage' => '特攻',
    'others' => '其他',
  ];
  if (is_array($obj) && count($obj) === 1) {
    foreach ($categories as $key => $category) {
      $effect = $obj[$key] ?? null;
      if (!is_array($effect) || count($effect) !== 2 || !isset($effect[0], $effect[1])) {
        continue;
      }
      $element = translate_kind_id_to_chinese_kind($effect[0]);
      if ($element !== null && is_numeric($effect[1]) && $effect[1] >= 0) {
        return [$category, $element, intval($effect[1])];
      }
    }
  }

  exit(json_encode([
    'success' => false,
    'reason' => '技能效果无效；当前仅支持物理伤害、特殊伤害和其他技能效果',
  ], JSON_UNESCAPED_UNICODE));
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
