<?php
defined('IN_DISCUZ') || exit('Access Denied');

global $_G;

$uid = intval($_G['uid']);

if (!$uid) {
  showmessage('错误: 没有登录');
}

$table = DB::table('common_member_field_forum');
$hide = boolval($_GET['hide']);

DB::update('common_member_field_forum', [
  'pokemon' => $hide ? '' : serialize(get_my_pm_data()),
], "uid=$uid");

showmessage('已' . ($hide ? '隐藏' : '刷新') . '状态栏', '', [], [
  'alert' => 'right'
]);

function get_my_pm_data()
{
  global $_G;
  $sql = <<<SQL
SELECT `id`, `pmno`, `nowname`, `level`, `site`, `sg` FROM `pm_mypm`
WHERE `uid` = '{$_G['uid']}' AND `site` < 3
SQL;
  $query = DB::query($sql);
  $data = [];
  $creeps = [];
  while ($pet = DB::fetch($query)) {
    if ($pet['site'] == 1) {
      $data['first'] = $pet;
    } else {
      $creeps[] = $pet;
    }
  }
  $data['creeps'] = $creeps;
  return $data;
}
