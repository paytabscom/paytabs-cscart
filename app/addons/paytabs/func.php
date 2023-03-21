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
    $db = Tygh::$app['db'];

    $processor_id = $db->getField(
        'SELECT processor_id FROM ?:payment_processors WHERE processor_script = ?s',
        'paytabs.php'
    );

    if (!$processor_id) {
        return;
    }

    $db->query('DELETE FROM ?:payment_processors WHERE processor_id = ?i', $processor_id);
    $db->query('DELETE FROM ?:payments WHERE processor_id = ?i', $processor_id);
    $db->query("DELETE FROM ?:payment_descriptions WHERE payment_id IN (SELECT payment_id FROM ?:payments WHERE processor_id = ?i)", $processor_id);


}


function fn_is_paytabs_refund_performed($return_id)
{
    $return_data = fn_get_return_info($return_id);
    $return_data['extra'] = empty($return_data['extra']) ? array() : unserialize($return_data['extra']);
    return !empty($return_data['extra']['paytabs_refund_transaction_id']);
}

function fn_is_paytabs_processor($processor_id = 0)
{
    return (bool)db_get_field("SELECT 1 FROM ?:payment_processors WHERE processor_id = ?i AND addon = ?s", $processor_id, 'paytabs');
}

function fn_process_refund($order_info, $amount = null, $reason = '', $type = 'Full',$return_id)
{

    $currency = $order_info["secondary_currency"];
    $order_id = $order_info["order_id"];
    $transaction_id = $order_info["payment_info"]["transaction_id"];

    if (!$order_id) {
        fn_set_notification('E', __('error'), "Missing order number!");
        return false;
    }

    if (!$amount) {
        fn_set_notification('E', __('error'), "Missing amount!");
        return false;
    }

    if (!$transaction_id) {
        fn_set_notification('E', __('error'), "Missing Transaction ID !");
        return false;
    }


    if (empty($reason)) $reason = 'Admin request';

    require_once(DIR_ROOT . '/app/addons/paytabs/payments/paytabs_core.php');

    $pt_refundHolder = new PaytabsFollowupHolder();
    $pt_refundHolder
        ->set02Transaction(PaytabsEnum::TRAN_TYPE_REFUND, PaytabsEnum::TRAN_CLASS_ECOM)
        ->set03Cart($order_id, $currency, $amount, $reason)
        ->set30TransactionInfo($transaction_id)
        ->set99PluginInfo('cs-cart', PRODUCT_VERSION, PAYTABS_PAYPAGE_VERSION);
    $values = $pt_refundHolder->pt_build();
    $db = Tygh::$app['db'];
    $payment_id = $db->getField("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
    $processor_data = fn_get_payment_method_data($payment_id);
    $paytabs_admin = $processor_data["processor_params"];
    $endpoint = $paytabs_admin['endpoint'];
    $profile_id = intval($paytabs_admin['profile_id']);
    $serverKey = $paytabs_admin['server_key'];
    $paytabsApi = PaytabsApi::getInstance($endpoint, $profile_id, $serverKey);
    $refundRes = $paytabsApi->request_followup($values);
    $tran_ref = @$refundRes->tran_ref;
    $success = $refundRes->success;
    $message = $refundRes->message;
    $pending_success = $refundRes->pending_success;
    Tygh::$app['db']->query("UPDATE ?:rma_returns SET comment = CONCAT(comment,'-', ?s ) WHERE return_id = ?i", $message, $return_id);

    PaytabsHelper::log("Refund request done,tran_ref {$tran_ref} ,  payment_id {$payment_id} ,  Order {$order_id} - {$success} {$message} {$tran_ref}", 1);

    if ($success) {
        return $tran_ref;
    } else if ($pending_success) {
        return $tran_ref;
    } else {
        fn_set_notification('E', __('error'), $message);
        return false;
    }
}

function fn_paytabs_rma_update_details_post(&$data, &$show_confirmation_page, &$show_confirmation, &$is_refund, &$_data, &$confirmed)
{
    $change_return_status = $data['change_return_status'];
    if (($show_confirmation == false ||
            ($show_confirmation == true && $confirmed == 'Y')) && $is_refund == 'Y') {
        $order_info = fn_get_order_info($change_return_status['order_id']);
        $amount = 0;
        $st_inv = fn_get_statuses(STATUSES_RETURN);

        if ($change_return_status['status_to'] != $change_return_status['status_from'] &&
            $st_inv[$change_return_status['status_to']]['params']['inventory'] != 'D') {

            if (!empty($order_info['payment_info']['transaction_id']) && !fn_is_paytabs_refund_performed($change_return_status['return_id'])) {
                $return_data = fn_get_return_info($change_return_status['return_id']);
                if (!empty($order_info['returned_products'])) {
                    foreach ($order_info['returned_products'] as $cart_id => $product) {
                        if (isset($return_data['items']['A'][$cart_id])) {
                            $amount += $product['subtotal'];
                        }
                    }
                } elseif (!empty($order_info['products'])) {
                    foreach ($order_info['products'] as $cart_id => $product) {
                        if (isset($product['extra']['returns']) && isset($return_data['items']['A'][$cart_id])) {
                            foreach ($product['extra']['returns'] as $return_id => $product_return_data) {
                                $amount += $return_data['items']['A'][$cart_id]['price'] * $product_return_data['amount'];
                            }
                        }
                    }
                }

                if ($amount != $order_info['subtotal'] || fn_allowed_for('MULTIVENDOR')) {
                    $refund_type = 'Partial';
                } else {
                    $refund_type = 'Full';
                }

                $result = fn_process_refund($order_info, $amount, '', $refund_type,$change_return_status['return_id']);
                if ($result) {
                    $extra = empty($return_data['extra']) ? array() : unserialize($return_data['extra']);
                    $extra['paytabs_refund_transaction_id'] = $result;
                    Tygh::$app['db']->query("UPDATE ?:rma_returns SET extra = ?s WHERE return_id = ?i", serialize($extra), $change_return_status['return_id']);
                    fn_set_notification('N', __('notice'), 'Refund Done Successfully');
                } else {
                    Tygh::$app['db']->query("UPDATE ?:rma_returns SET status = ?s WHERE return_id = ?i", "R", $change_return_status['return_id']);
                    fn_set_notification('E', __('error'), 'Failed To Refund Try Again Later!');
                }
            } else {
                fn_set_notification('E', __('error'), 'This order has been refunded before! ');
            }
        } else {
            fn_set_notification('E', __('error'), 'You didn\'t change the status! ');
        }
    }
}
