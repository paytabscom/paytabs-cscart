<?php

use Tygh\Http;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');
require_once('paytabs_core.php');


$payment_completed = defined('PAYMENT_NOTIFICATION');

if ($payment_completed) {
    paymentComplete($mode);
} else {
    paymentPrepare($processor_data, $order_info, $order_id);
}

function paymentPrepare($processor_data, $order_info, $order_id)
{
    $paytabs_api = PaytabsAdapter::getPaytabsApi($processor_data);

    $hide_shipping = (bool)PaytabsAdapter::getConfig($processor_data, 'hide_shipping');
    $iframe_mode = PaytabsAdapter::getConfig($processor_data, 'iframe_mode') == 'Y';

    $session = Tygh::$app['session'];
    $cid = ($session->getID());

    $callback_url = fn_url("payment_notification.callback?payment=paytabs", AREA, 'current');
    $return_url = fn_url("payment_notification.return?payment=paytabs&iframe_mode=" . $iframe_mode, AREA, 'current');
    $return_url = fn_link_attach($return_url, $session->getName() . '=' . $session->getID());

    $total = $order_info['total'];

    $products = $order_info['products'];
    $products_str = implode(' || ', array_map(function ($p) {
        return $p['product'] . " ({$p['amount']})";
    }, $products));

    $currency = CART_PRIMARY_CURRENCY;

    $firstname = $order_info['b_firstname'];
    $lastname = $order_info['b_lastname'];
    $address = $order_info['b_address'] . ' ' . $order_info['b_address_2'];
    $city = $order_info['b_city'];
    $state = $order_info['b_state'];
    $country = $order_info['b_country'];
    $zipcode = $order_info['b_zipcode'];
    $phone = $order_info['b_phone'];
    $email = $order_info['email'];
    $ip_address = $order_info['ip_address'];

    $s_firstname = $order_info['s_firstname'];
    $s_lastname = $order_info['s_lastname'];
    $s_address = $order_info['s_address'] . ' ' . $order_info['s_address_2'];
    $s_city = $order_info['s_city'];
    $s_state = $order_info['s_state'];
    $s_country = $order_info['s_country'];
    $s_zipcode = $order_info['s_zipcode'];
    $s_phone = $order_info['s_phone'];

    $lang_code = $order_info['lang_code'];

    $pt_holder = new PaytabsRequestHolder();
    $pt_holder
        ->set01PaymentCode('all', false)
        ->set02Transaction(PaytabsEnum::TRAN_TYPE_SALE, PaytabsEnum::TRAN_CLASS_ECOM)
        ->set03Cart($order_id, $currency, $total, $products_str)
        ->set04CustomerDetails(
            "{$firstname} {$lastname}",
            $email,
            $phone,
            $address,
            $city,
            $state,
            $country,
            $zipcode,
            $ip_address
        )
        ->set05ShippingDetails(
            false,
            "{$s_firstname} {$s_lastname}",
            $email,
            $s_phone,
            $s_address,
            $s_city,
            $s_state,
            $s_country,
            $s_zipcode,
            null
        )
        ->set06HideShipping($hide_shipping)
        ->set07URLs($return_url, $callback_url)
        ->set08Lang($lang_code)
        ->set09Framed($iframe_mode, "top")
        ->set50UserDefined($cid, $iframe_mode)
        ->set99PluginInfo('CS-Cart', PRODUCT_VERSION, PAYTABS_PAYPAGE_VERSION);

    $post_data = $pt_holder->pt_build();

    $paypage = $paytabs_api->create_pay_page($post_data);

    $success = $paypage->success;
    $message = $paypage->message;

    // $_logPaypage = json_encode($paypage);
    PaytabsHelper::log("Order {$order_id}, Create paypage result: sucess ? {$success} message {$message}", 1);

    if ($success) {
        $url = $paypage->redirect_url;
        fn_create_payment_form($url, [], 'PayTabs server', false, 'get');
    } else {
        // Here Error
        // $pp_response["reason_text"] = $message;

        // fn_finish_payment($order_id, $pp_response, false);
        fn_set_notification('E', __('warning'), $message, true, '');
        fn_order_placement_routines('route', $order_id, false);

        die;
    }
}


/**
 * Weebhook/Callback called by PayTabs's server after completing the payment
 */
function paymentComplete($mode)
{
    if ($mode === "return") {
        fn_retrun();
    }
    if ($mode === "callback") {
        fn_callback();
    }
}

function fn_callback()
{
    $response_data = PaytabsHelper::read_ipn_response();

    if (!$response_data) {
        return;
    }
    $tran_ref = isset($response_data->tran_ref) ? $response_data->tran_ref : false;
    $order_id = isset($response_data->cart_id) ? $response_data->cart_id : false;

    if (!$tran_ref) {
        return;
    }
    if (!$order_id) {
        return;
    }

    $session_id = isset($response_data->user_defined->udf1) ? ($response_data->user_defined->udf1) : false;
    $ifm_mode = isset($response_data->user_defined->udf2) ? ($response_data->user_defined->udf2) : false;
    //$response_data->payment_result->response_status !=="C"

    PaytabsHelper::log("session {session: $session_id } iframe: {$ifm_mode}");

    if ($ifm_mode) {
        if ($session_id) {
            $order_id = fn_callback_frame($session_id);
        } else {
            //on cancellation the user_defined will be null and session_id will be false
            PaytabsHelper::log("iFrame order {$order_id} canceled", 3);
            return;
        }
    }

    if (!fn_check_payment_script('paytabs.php', $order_id)) {
        die();
    }

    $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
    $processor_data = fn_get_payment_method_data($payment_id);
    $paytabsApi = PaytabsAdapter::getPaytabsApi($processor_data);

    $result = $paytabsApi->read_response(true);
    if (!$result) {
        return;
    }
    $success = $result->success;
    $failed = $result->failed;
    $is_on_hold = $result->is_on_hold;
    $is_pending = $result->is_pending;
    $res_msg = $result->message;
    $transaction_ref = @$result->transaction_id;
    $response_code = @$result->response_code;

    $pp_response = [
        'transaction_id' => $transaction_ref,
        'reason_text' => $res_msg,
        'response_code' => $response_code
    ];

    if ($success) {

        if (!_validate_amount($order_id, $result)) {
            $pp_response['response_code'] = 601;
            $pp_response['response_msg'] = "Order {$order_id}, Transaction/Order amounts are different, check the log";

            fn_finish_payment($order_id, $pp_response, false);
            exit;
        }

        $pp_response['order_status'] = $processor_data['processor_params']['order_status_after_payment'];

        fn_change_order_status($order_id, $processor_data['processor_params']['order_status_after_payment']);
        fn_finish_payment($order_id, $pp_response, false);
    } else if ($is_on_hold || $is_pending) {
        fn_change_order_status($order_id, "O");
    } else {
        // fn_change_order_status($order_id, "F");
        $pp_response['order_status'] = 'F';
        fn_finish_payment($order_id, $pp_response, false);
    }

    PaytabsHelper::log("Finish payment, Tran {$transaction_ref}  success {$success} holding {$is_on_hold} pending {$is_pending}, Order {$order_id} ", 1);

    exit;
    //fn_order_placement_routines('route', $order_id);
}

function _validate_amount($order_id, $trx)
{
    $order = fn_get_order_info($order_id);

    $same_payment =
        strcasecmp($order['secondary_currency'], $trx->cart_currency) == 0
        && $order['total'] == $trx->cart_amount;

    if (!$same_payment) {
        PaytabsHelper::log("Order {$order_id}, Expected ({$order['total']} {$order['secondary_currency']}), Received {$trx->cart_amount} {$trx->tran_currency}", 2);
        return false;
    }

    return true;
}


function fn_callback_frame($session_id)
{
    $response_data = PaytabsHelper::read_ipn_response();
    if (!$response_data) {
        return;
    }
    $order_id = isset($response_data->cart_id) ? $response_data->cart_id : false;
    $order_nonce = $order_id;

    // get cart and auth data from session
    Tygh::$app['session']->resetID($session_id);
    $cart = &Tygh::$app['session']['cart'];
    $auth = &Tygh::$app['session']['auth'];

    PaytabsHelper::log("iFrame: order_once {$order_id}, Session {$session_id}");

    list($order_id, $process_payment) = fn_place_order($cart, $auth);
    // store additional order data
    if (!empty($order_nonce)) {
        db_query('REPLACE INTO ?:order_data ?m', array(
            // add payment data
            array('order_id' => $order_id, 'type' => 'S', 'data' => TIME),
            // store order nonce
            array('order_id' => $order_id, 'type' => 'E', 'data' => $order_nonce)
        ));
    }
    PaytabsHelper::log("iFrame: order_once converted to order_id {$order_id} ");
    return $order_id;
}


function fn_retrun()
{
    // Wait for the Callback response
    sleep(5);
    $param_paymentRef = 'tranRef';
    $iframe_mode = filter_input(INPUT_GET, 'iframe_mode', FILTER_VALIDATE_BOOL);
    $order_id = filter_input(INPUT_POST, 'cartId');
    $tran_ref = filter_input(INPUT_POST, $param_paymentRef);

    if (!$order_id || !$tran_ref) {
        return;
    }
    if ($iframe_mode) {
        $order_nonce = $order_id;
        if (isset($_POST["respStatus"]) && $_POST["respStatus"] === "C") {
            fn_set_notification('E', 'Error', 'The Payment has been cancelled !');
            fn_order_placement_routines('checkout_redirect');
            return;
        }

        $time = $order_id = 0;
        while ($time++ < IFRAME_PAYMENT_NOTIFICATION_TIMEOUT) {
            if ($order_id = db_get_field("SELECT order_id FROM ?:order_data WHERE data = ?s AND type = 'E'", $order_nonce)) {
                break;
            }
            sleep(1);
        }
        if ($order_id) {
            // redirect customer
            fn_order_placement_routines('route', $order_id, false);
        } else {
            // payment gateway takes too much time to process payment: show notice
            fn_set_notification('E', 'Error', 'Payment notification timeout has been exceeded');
            fn_order_placement_routines('checkout_redirect');
        }
    } else {
        fn_order_placement_routines('route', $order_id);
    }
    exit;
}
