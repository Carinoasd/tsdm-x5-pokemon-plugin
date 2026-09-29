<?php
defined('IN_DISCUZ') || exit('Access Denied');

// 后台与 JSON API 共用的请求安全检查：
// 1. formhash：游戏页与管理页在 fetch 外包一层，同源请求自动带 X-Pm-Formhash 头；
//    跨站页面无法设置自定义头（会触发 CORS 预检失败），也拿不到 formhash。
// 2. SQL 控制台：非管理员（宠物中心版主、poke_smgly 名单）服务端强制只读，
//    且不得查询论坛本体与 UCenter 的表；每次执行写入 data/log 的 pokemonsql 日志。

if (!function_exists('pm_request_formhash')) {
    function pm_request_formhash()
    {
        if (!empty($_SERVER['HTTP_X_PM_FORMHASH'])) {
            return (string) $_SERVER['HTTP_X_PM_FORMHASH'];
        }
        if (isset($_GET['formhash']) && is_string($_GET['formhash'])) {
            return $_GET['formhash'];
        }
        if (isset($_POST['formhash']) && is_string($_POST['formhash'])) {
            return $_POST['formhash'];
        }
        return '';
    }

    function pm_formhash_ok()
    {
        $hash = pm_request_formhash();
        return $hash !== '' && hash_equals(formhash(), $hash);
    }

    function pm_is_full_admin()
    {
        global $_G;
        return $_G['adminid'] == 1 || $_G['groupid'] == 1;
    }

    // 返回拒绝原因；空字符串表示放行。管理员不受限制。
    function pm_sql_console_denied($sql)
    {
        global $_G;
        if (pm_is_full_admin()) {
            return '';
        }

        // 末尾一个分号是常见写法（控制台示例也带），不算多条语句
        $sql = preg_replace('/;\s*$/', '', trim($sql));
        if (!preg_match('/^(select|show|describe|desc|explain)\b/i', $sql)) {
            return '非管理员仅可执行 SELECT/SHOW/DESCRIBE/EXPLAIN 查询语句';
        }
        // 去掉字符串字面量后再做分号、关键字与表名检查
        $bare = strtolower(preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql));
        if (strpos($bare, ';') !== false) {
            return '非管理员不可一次执行多条语句';
        }
        if (preg_match('/^show\b/i', $sql) && !preg_match('/^show\s+(full\s+)?(tables|columns|fields|index|indexes|keys|create\s+table)\b/i', $sql)) {
            return '非管理员仅可使用 SHOW TABLES / COLUMNS / INDEX / CREATE TABLE';
        }
        // EXPLAIN ANALYZE 会真正执行其后的语句；EXPLAIN/DESCRIBE 只允许解释查询
        if (preg_match('/^(explain|describe|desc)\b/', $bare) && preg_match('/\b(analyze|update|delete|insert|replace)\b/', $bare)) {
            return '非管理员仅可解释 SELECT 查询';
        }
        // SELECT ... INTO / FOR UPDATE / LOCK IN SHARE MODE 会写文件、写变量或加锁
        if (preg_match('/\binto\b|\bfor\s+(update|share)\b|\block\s+in\s+share\s+mode\b/', $bare)) {
            return '非管理员不可使用 INTO、FOR UPDATE 或加锁查询';
        }

        // 宠物表固定为不带前缀的 pm_*；论坛本体、UCenter 与系统库一律不给非管理员查询
        $blocked = array('information_schema', 'performance_schema', 'mysql', 'sys', 'ucenter');
        $prefixes = array('uc_');
        if (!empty($_G['config']['db'][1]['tablepre'])) {
            $prefixes[] = $_G['config']['db'][1]['tablepre'];
        }
        if (defined('UC_DBTABLEPRE') && preg_match('/^(?:`?([^`.]+)`?\.)?`?([^`]*)`?$/', UC_DBTABLEPRE, $m)) {
            if ($m[1] !== '') {
                $blocked[] = $m[1];
            }
            if ($m[2] !== '') {
                $prefixes[] = $m[2];
            }
        }
        foreach ($blocked as $word) {
            if (preg_match('/(?<![a-z0-9_$])' . preg_quote(strtolower($word), '/') . '(?![a-z0-9_$])/', $bare)) {
                return '非管理员仅可查询宠物系统的 pm_* 表';
            }
        }
        foreach ($prefixes as $prefix) {
            if (preg_match('/(?<![a-z0-9_$])' . preg_quote(strtolower($prefix), '/') . '/', $bare)) {
                return '非管理员仅可查询宠物系统的 pm_* 表';
            }
        }
        return '';
    }

    function pm_sql_console_log($sql, $result)
    {
        global $_G;
        writelog('pokemonsql', implode("\t", array(
            $_G['timestamp'],
            $_G['uid'],
            $_G['username'],
            $_G['clientip'],
            $result,
            str_replace(array("\r", "\n", "\t"), ' ', $sql),
        )));
    }
}
