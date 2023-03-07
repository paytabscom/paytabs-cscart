<?php

//use Tygh\Payments\Processors\PayTabs;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

function fn_paytabs_install()
{
    pt_remove_records();

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
    pt_remove_records();
    pt_remove_files();
}

function pt_remove_files()
{
    fn_rm(DIR_ROOT . '/design/backend/templates/views/payments/components/cc_processors/paytabs.tpl');
    fn_rm(DIR_ROOT . '/app/addons/paytabs/addon.xml');
    fn_rm(DIR_ROOT . '/var/langs/en/addons/paytabs.po');
    fn_rm(DIR_ROOT . '/paytabs_logo.png');
    fn_rm(DIR_ROOT . '/README.md');
}

function pt_remove_records()
{
    db_query("DELETE FROM ?:payment_processors WHERE processor_script = ?s", "paytabs.php");
}
