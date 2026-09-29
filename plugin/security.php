<?php
defined('IN_DISCUZ') || exit('Access Denied');

// 后台与 JSON API 共用的请求安全检查（formhash）：游戏页与管理页在 fetch 外包一层，
// 同源请求自动带 X-Pm-Formhash 头；跨站页面无法设置自定义头（会触发 CORS 预检失败），
// 也拿不到 formhash。

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
}
