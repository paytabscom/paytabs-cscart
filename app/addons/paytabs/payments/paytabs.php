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
    $iframe_mode = (bool)PaytabsAdapter::getConfig($processor_data, 'iframe_mode');

    $session = Tygh::$app['session'];
    $cid = ($session->getID());

    $callback_url = fn_url("payment_notification.callback?payment=paytabs", AREA, 'current');
    $return_url = fn_url("payment_notification.return?payment=paytabs&iframe_mode=" . $iframe_mode, AREA, 'current');
    $callback_url = str_replace('localhost', '7b12-154-178-192-49.eu.ngrok.io', $callback_url);
    $return_url = fn_link_attach($return_url, $session->getName() . '=' . $session->getID());

    $total = $order_info['total'];

    $products = $order_info['products'];
    $products_str = implode(' || ', array_map(function ($p) {
        return $p['product'] . " ({$p['amount']})";
    }, $products));

    $currency = CART_SECONDARY_CURRENCY;

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

    $_logPaypage = json_encode($paypage);
    paytabs_error_log("Create paypage result: sucess ? {$success} message {$message}", 1);

    if ($success) {
        $url = $paypage->redirect_url;
        fn_create_payment_form($url, [], 'PayTabs server', false, 'get');
    } else {
        // Here Error
        $pp_response["reason_text"] = $message;

        fn_finish_payment($order_id, $pp_response, false);
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

    paytabs_error_log("session {session: $session_id } iframe: {$ifm_mode}");

    if ($ifm_mode ) {
        if($session_id) {
            $order_id = fn_callback_frame($session_id);
        }else{
            //on cancellation the user_defined will be null and session_id will be false
            paytabs_error_log("iframe order {$order_id} canceled ", 3);
            return;
        }
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
    $pp_response = array();
    if ($success || $is_on_hold || $is_pending) {
        $pp_response['reason_text'] = $res_msg;
        $pp_response['order_status'] = $processor_data['processor_params']['order_status_after_payment'];
        $pp_response['transaction_id'] = $transaction_ref;
        $pp_response['responseCode'] = $response_code;
        $pp_response['responseMsg'] = $res_msg;

        if (fn_check_payment_script('paytabs.php', $order_id)) {
            fn_finish_payment($order_id, $pp_response, true);
            paytabs_error_log("finish payment transaction_ref {$transaction_ref} success {$success}  holding {$is_on_hold} pending {$is_pending} of order {$order_id} ", 1);
        }
    } else {
        //show the error message
        $pp_response['order_status'] = 'F';
        $pp_response['reason_text'] = $res_msg;
        $pp_response['transaction_id'] = $transaction_ref;

        fn_update_order_payment_info($order_id, $pp_response);
        fn_change_order_status($order_id, $pp_response['order_status'], '', false);
        fn_finish_payment($order_id, $pp_response, false);
        paytabs_error_log("failed payment :  success {$success}  holding {$is_on_hold} pending {$is_pending} of order {$order_id} and pp_response " . json_encode($pp_response), 1);

    }
    exit;
    //fn_order_placement_routines('route', $order_id);
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
    paytabs_error_log("iframe:order_once{$order_id} with session {$session_id} "."cart:" . json_encode($cart)."auth:" . $auth);
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
    paytabs_error_log("iframe:order_once converted to order_id {$order_id} ");
    return $order_id;
}

function fn_retrun()
{
    sleep(5);
    $param_paymentRef = 'tranRef';
    if(isset($_REQUEST["iframe_mode"]) && $_REQUEST["iframe_mode"]){
        $order_nonce = $_POST['cartId'];
        if (!key_exists($param_paymentRef, $_POST)) {
            return;
        }

        if(isset($_POST["respStatus"]) && $_POST["respStatus"]==="C"){
            fn_set_notification('E', 'Error', 'The Payment has been cancelled !');
            fn_order_placement_routines('checkout_redirect');
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
            // payment gateway takes to much time to process payment: show notice
            fn_set_notification('E', 'Error', 'Payment notification timeout has been exceeded');
            fn_order_placement_routines('checkout_redirect');
        }
    }else {
        $order_id = $_POST['cartId'];
        $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
        $processor_data = fn_get_payment_method_data($payment_id);
        $paytabs_api = PaytabsAdapter::getPaytabsApi($processor_data);
        // Verify payment
        $payment_ref = $_POST[$param_paymentRef];
        $verify_response = $paytabs_api->verify_payment($payment_ref);
        $_logVerify = json_encode($verify_response);
        paytabs_error_log("Return: {$order_id}, [{$_logVerify}]", 1);

        $orderId = $verify_response->cart_id;
        if ($orderId != $order_id) {
            paytabs_error_log("Mis Match Order id {$order_id} , [{$_logVerify}]", 2);
        }

        fn_order_placement_routines('route', $order_id);
    }
    exit;
}

//

class PaytabsAdapter
{
    static function getPaytabsApi($processor_data)
    {
        $paytabs_admin = $processor_data["processor_params"];

        $endpoint = $paytabs_admin['endpoint'];
        $profile_id = intval($paytabs_admin['profile_id']);
        $serverKey = $paytabs_admin['server_key'];

        return PaytabsApi::getInstance($endpoint, $profile_id, $serverKey);
    }

    static function getConfig($processor_data, $key)
    {
        $paytabs_admin = $processor_data["processor_params"];

        $value = $paytabs_admin[$key];

        return $value;
    }
}
