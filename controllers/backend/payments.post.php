<?php

use Tygh\Registry;


if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

/**
 * @var string $mode
 * @var string $action
 * @var array $auth
 */


if ($mode == 'processor') {

    $endpoints = ['a' => 'A', 'b' => 'B'];

    /** @var \Tygh\SmartyEngine\Core $view */
    $view = Tygh::$app['view'];

    $view->assign('endpoints', $endpoints);
}
