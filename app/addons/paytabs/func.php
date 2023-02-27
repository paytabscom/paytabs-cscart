<?php

//use Tygh\Payments\Processors\PayTabs;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

function fn_paytabs_install()
{
    fn_paytabs_uninstall();
    $_data = array(
        'processor' => 'PayTabs',
        'processor_script' => 'paytabs.php',
        'processor_template' => 'views/orders/components/payments/cc_outside.tpl',
        'admin_template' => 'paytabs.tpl',
        'callback' => 'N',
        'type' => 'P',
        'addon' => 'paytabs'
    );
    db_query("INSERT INTO ?:payment_processors ?e", $_data);
}
function fn_paytabs_uninstall()
{
    db_query("DELETE FROM ?:payment_processors WHERE processor_script = ?s", "paytabs.php");
}
