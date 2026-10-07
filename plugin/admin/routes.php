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
        case 'contains':
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

// Filter foreign IDs by their display names. Identifiers are internal constants;
// the caller supplies the same already-escaped value used by generate_filter_sql.
function generate_reference_filter_sql($key, $table, $id_column, $name_column, $operator, $value)
{
  if (!in_array($operator, ['equal', 'not_equal', 'contains'], true)) return '';
  if ($operator !== 'contains' && ctype_digit($value) && $value !== '') {
    $id = intval($value);
    if (DB::fetch_first("SELECT `$id_column` FROM $table WHERE `$id_column`=$id")) {
      return generate_filter_sql($key, $operator, $id, 'id');
    }
  }
  $matches = DB::fetch_all("SELECT `$id_column` FROM $table WHERE `$name_column` LIKE '%$value%'");
  $ids = [];
  foreach ($matches as $match) $ids[] = intval($match[$id_column]);
  if (empty($ids)) return $operator === 'not_equal' ? '1=1' : '1=0';
  return "`$key` " . ($operator === 'not_equal' ? 'NOT IN' : 'IN') . ' (' . implode(',', $ids) . ')';
}

include_once __DIR__ . "/types.php";

// 后台路由复用 API 层的共享工具（api_calculate_pokemon_max_hp 等）。
// pm_sql/pm_table 定义在 api/index.php，但该文件在文件末尾对非 endpoint
// 路由直接输出 404 退出，无法在后台上下文引入，这里提供等价实现；
// endpoint 路由（pokemon.inc.php）与后台路由（index=admin）互斥，同一
// 请求内不会出现重复定义。
if (!function_exists('pm_table')) {
  function pm_table($table)
  {
    // Pokemon 系统表名固定为 pm_*，不带 Discuz 前缀，与 api/index.php 保持一致
    return $table;
  }
}

if (!function_exists('pm_sql_v')) {
  function pm_sql_v($sql, $args)
  {
    if (empty($args)) {
      return $sql;
    }
    $i = 0;
    return preg_replace_callback('/%([sd])/', function ($m) use ($args, &$i) {
      $v = isset($args[$i]) ? $args[$i] : '';
      $i++;
      if ($m[1] === 'd') {
        return intval($v);
      }
      return "'" . addslashes($v) . "'";
    }, $sql);
  }
}

if (!function_exists('pm_sql')) {
  function pm_sql($sql)
  {
    $args = func_get_args();
    array_shift($args);
    return pm_sql_v($sql, $args);
  }
}

require_once __DIR__ . "/../api/utils.php";

include_once __DIR__ . "/routes/global_config.php";
include_once __DIR__ . "/routes/item_data.php";
include_once __DIR__ . "/routes/map_data.php";
include_once __DIR__ . "/routes/pokemon_data.php";
include_once __DIR__ . "/routes/user_data.php";
include_once __DIR__ . "/routes/pokemon_info.php";
include_once __DIR__ . "/routes/item_info.php";
include_once __DIR__ . "/routes/evolution_data.php";
include_once __DIR__ . "/routes/skill_type.php";
require_once __DIR__ . "/../api/battle_core.php";
include_once __DIR__ . "/routes/effect_data.php";
include_once __DIR__ . "/routes/file_explorer.php";
