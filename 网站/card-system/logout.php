<?php
/**
 * 登出处理
 */

require_once __DIR__ . '/init.php';

Auth::logout();
redirect(SITE_URL);
