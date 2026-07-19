<?php

function generate_filter_sql($key, $operator, $value, $type = 'text')
{
  switch ($type) {
    case 'id':
      switch ($operator) {
        case 'equal':
          return "`$key`='$value'";
        case "not_equal":
          return "`$key`!='$value'";
        default:
          return "";
      }
    case 'text':
      switch ($operator) {
        case 'equal':
          return "`$key` like '%$value%'";
        case "not_equal":
          return "`$key` not like '%$value%'";
        default:
          return "";
      }
    case 'number':
      switch ($operator) {
        case 'equal':
          return "`$key`='$value'";
        case 'not_equal':
          return "`$key`!='$value'";
        case 'greater':
          return "`$key`>'$value'";
        case 'greater_or_equal':
          return "`$key`>='$value'";
        case 'less':
          return "`$key`<'$value'";
        case 'less_or_equal':
          return "`$key`<='$value'";
        default:
          return "";
      }
    default:
  }
  return "";
}

include_once __DIR__ . "/types.php";

include_once __DIR__ . "/routes/global_config.php";
include_once __DIR__ . "/routes/item_data.php";
include_once __DIR__ . "/routes/map_data.php";
include_once __DIR__ . "/routes/pokemon_data.php";
include_once __DIR__ . "/routes/user_data.php";
include_once __DIR__ . "/routes/pokemon_info.php";
include_once __DIR__ . "/routes/item_info.php";
include_once __DIR__ . "/routes/evolution_data.php";
include_once __DIR__ . "/routes/skill_type.php";
include_once __DIR__ . "/routes/sql_console.php";
include_once __DIR__ . "/routes/file_explorer.php";
