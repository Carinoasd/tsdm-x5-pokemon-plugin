<?php

/**
 * 话题数据API
 *
 * 端点:
 * - GET  ?action=list    获取话题列表
 */

// 如果通过路由访问，加载API辅助函数
if (defined('API_ROUTED')) {
  require_once __DIR__ . '/index.php';
} elseif (!defined('IN_DISCUZ')) {
  require_once __DIR__ . '/bootstrap.php';
  require_once __DIR__ . '/index.php';
}

global $_G;

// IDE 类型提示
if (false) {
  function validate_required_param($input, $key, $type = 'string', $options = []) {}
  function validate_optional_param($input, $key, $default = null, $type = 'string', $options = []) {}
  function validate_int_range($value, $name, $min, $max) {}
}

$action = get_param('action', '');

switch ($action) {
  case 'list':
    api_get_topics();
    break;

  default:
    api_error('Invalid action', 400);
}

/**
 * 获取话题列表
 */
function api_get_topics()
{
  global $_G;

  // 从插件配置中获取论坛版块ID
  $settings = isset($_G['cache']['plugin']['pokemon']) ? $_G['cache']['plugin']['pokemon'] : array();
  $fid = isset($settings['fid']) ? intval($settings['fid']) : 0;

  // 如果 fid 没有配置，使用默认值 2
  if (!$fid) {
    $fid = 2;
  }

  // 获取 limit 参数（简化处理，避免验证失败）
  $limit_param = get_param('limit', 6);
  $limit = intval($limit_param);
  if ($limit < 1 || $limit > 20) {
    $limit = 6;
  }

  // 获取新闻公告配置（最多 6 条）
  $news_announcements = array();
  try {
    require_once __DIR__ . '/../announcements.php';
    $news_announcements = array_slice(pm_get_news_announcements(), 0, 6);
  } catch (Exception $e) {
    // 忽略错误，使用空数组
  }

  // 查询最新话题
  // displayorder>=0: 只显示正常帖子和置顶帖子
  // displayorder>0: 置顶帖子
  $topics = array();
  try {
    $fid_escaped = intval($fid);
    $limit_escaped = intval($limit);
    $rows = pm_topics_can_view_forum($fid_escaped) ? DB::fetch_all(pm_sql(
      "SELECT tid, subject, dateline, displayorder, author, authorid, views, replies
           FROM " . DB::table('forum_thread') . "
           WHERE fid = %d AND displayorder >= 0
           ORDER BY displayorder DESC, lastpost DESC
           LIMIT 0, %d",
      $fid_escaped, $limit_escaped
    )) : array();

    foreach ($rows as $topic) {
      $topics[] = [
        'id' => (int) $topic['tid'],
        'title' => $topic['subject'],
        'author' => $topic['author'],
        'author_id' => (int) $topic['authorid'],
        'date' => date('m-d', $topic['dateline']),
        'timestamp' => (int) $topic['dateline'],
        'views' => (int) $topic['views'],
        'replies' => (int) $topic['replies'],
        'is_pinned' => (int) $topic['displayorder'] > 0,
      ];
    }
  } catch (Throwable $e) {
    // 查询失败，返回空数组
  }

  api_success([
    'news_announcements' => $news_announcements,
    'topics' => $topics,
    'total' => count($topics),
  ]);
}

/** X5 uses table classes; X3 exposes the same table methods through C::t(). */
function pm_topics_table($name)
{
  $class = 'table_' . $name;
  return is_callable(array($class, 't')) ? $class::t() : C::t($name);
}

/** Check the native forum boundary before disclosing titles, authors or counts. */
function pm_topics_can_view_forum($fid)
{
  global $_G;

  if (isset($_G['setting']['forumstatus']) && !$_G['setting']['forumstatus']) return false;
  $forum = pm_topics_table('forum_forum')->fetch_info_by_fid($fid);
  if (!$forum || $forum['type'] === 'group' || !empty($forum['redirect'])) return false;
  $uid = (int) ($_G['uid'] ?? 0);
  $adminid = (int) ($_G['adminid'] ?? 0);
  if ($fid == ($_G['setting']['followforumid'] ?? 0) && $adminid !== 1) return false;

  $forum['allowview'] = 0;
  if ($uid && !empty($_G['member']['accessmasks'])) {
    $access = pm_topics_table('forum_access')->fetch_all_by_fid_uid($fid, $uid);
    $forum['allowview'] = (int) ($access[0]['allowview'] ?? 0);
  }
  if ($forum['allowview'] === -1) return false;
  $forum['ismoderator'] = $adminid === 1 || $adminid === 2
    || ($uid && $adminid === 3 && pm_topics_table('forum_moderator')->fetch_uid_by_fid_uid($fid, $uid));

  // The permission helpers use the selected forum. Do not replace the caller's context.
  $had_forum = array_key_exists('forum', $_G);
  $had_fid = array_key_exists('fid', $_G);
  if ($had_forum) $old_forum = &$_G['forum'];
  if ($had_fid) $old_fid = &$_G['fid'];
  unset($_G['forum'], $_G['fid']);
  $_G['forum'] = $forum;
  $_G['fid'] = $fid;
  try {
    if ((int) $forum['status'] === 3) {
      if ((int) $forum['level'] === -1) return false;
      if (!function_exists('groupperm')) require_once libfile('function/group');
      if ($uid && $adminid !== 1) {
        $moderators = !empty($forum['moderators']) ? dunserialize($forum['moderators']) : array();
        $groups = !empty($_G['setting']['group_admingroupids'])
          ? dunserialize($_G['setting']['group_admingroupids']) : array(1 => 1);
        $forum['ismoderator'] = !empty($moderators[$uid]) || !empty($groups[$_G['groupid']]);
        $_G['forum']['ismoderator'] = $forum['ismoderator'];
      }
      $member = pm_topics_table('forum_groupuser')->fetch_userinfo($uid, $fid);
      // Older native helpers read false['level'] after allowing a public outsider.
      // Preserve that result without inventing a member record or emitting a warning.
      $public_outsider = !$member && $forum['type'] === 'sub'
        && $forum['jointype'] >= 0 && !empty($forum['gviewperm']);
      $status = $public_outsider ? '' : groupperm($forum, $uid, '', $member ?: false);
      if ($status !== '' && $status !== 'isgroupuser') return false;
    }
    // forumdisplay permits an empty viewperm. Thread readperm controls the body,
    // so it must not remove otherwise public titles from this list.
    if (!empty($forum['viewperm']) && !$forum['allowview']) {
      if (!function_exists('forumperm') && function_exists('libfile')) {
        require_once libfile('function/core');
      }
      // For a plain guest group list, use the native explicit-group mode. Older
      // X5 helpers otherwise iterate null guest tag/account records on PHP 8.
      $guest_group = !$uid && preg_match('/^[0-9\t]+$/D', $forum['viewperm'])
        ? (int) ($_G['groupid'] ?? 0) : 0;
      if (!function_exists('forumperm') || !forumperm($forum['viewperm'], $guest_group)) return false;
    }
    // Legacy formulaperm() can showmessage()/exit and has no boolean ACL API.
    // Leave those previews to the forum page, preserving its native moderator exemption.
    if (!empty($forum['formulaperm']) && !$forum['ismoderator']) return false;
    if (!empty($forum['password']) && $forum['password'] !== ($_G['cookie']['fidpw' . $fid] ?? null)) return false;
    if (!empty($forum['price']) && !$forum['ismoderator']) {
      $paid = pm_topics_table('common_member_forum_buylog')->get_credits($uid, $fid);
      if ($paid < $forum['price']) return false;
    }
    return true;
  } finally {
    unset($_G['forum'], $_G['fid']);
    if ($had_forum) $_G['forum'] = &$old_forum;
    if ($had_fid) $_G['fid'] = &$old_fid;
  }
}
