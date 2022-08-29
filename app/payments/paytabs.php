<?php

use Tygh\Http;
use Tygh\Registry;

define('PAYTABS_PAYPAGE_VERSION', '2.1.0');
define('PAYTABS_DEBUG_FILE', DIR_ROOT . "/var/debug_paytabs.log");

defined('BOOTSTRAP') or die('Access denied');

require_once(DIR_ROOT . '/app/payments/paytabs_files/paytabs_core.php');

// Return from paytabs spayment server
$payment_completed = defined('PAYMENT_NOTIFICATION');

if ($payment_completed) {
    paymentComplete();
} else {
    paymentPrepare($processor_data, $order_info, $order_id);
}


//

function paytabs_error_log($message)
{
    $_prefix = date('c') . ' [PayTabs (2)]: ';
    error_log($_prefix . $message . PHP_EOL, 3, PAYTABS_DEBUG_FILE);
}


function paymentPrepare($processor_data, $order_info, $order_id)
{
    $paytabs_api = PaytabsAdapter::getPaytabsApi($processor_data);

    $return_url = fn_url("payment_notification?payment=paytabs", AREA, 'current');


    $total = $order_info['total'];

    $products = $order_info['products'];
    $products_str = implode(' || ', array_map(function ($p) {
        return $p['product'] . " ({$p['amount']})";
    }, $products));

    $currency = $order_info["secondary_currency"];

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
            null,
            $s_phone,
            $s_address,
            $s_city,
            $s_state,
            $s_country,
            $s_zipcode,
            null
        )
        ->set06HideShipping(false)
        ->set07URLs($return_url, null)
        ->set08Lang($lang_code)
        ->set99PluginInfo('CS-Cart', PRODUCT_VERSION, PAYTABS_PAYPAGE_VERSION);

    $post_data = $pt_holder->pt_build();

    $paypage = $paytabs_api->create_pay_page($post_data);

    $success = $paypage->success;
    $message = $paypage->message;

    $_logPaypage = json_encode($paypage);
    paytabs_error_log("Create paypage result: {$_logPaypage}");

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
function paymentComplete()
{
    $param_paymentRef = 'tranRef';
    $order_id = $_POST['cartId'];

    if (!key_exists($param_paymentRef, $_POST)) {
        //Not post or payment_reference not posted then error

        paytabs_error_log("Callback failed for Order {$order_id}, [tranRef] not defined");

        fn_order_placement_routines('route', $order_id);
        return;
    }


    $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
    $processor_data = fn_get_payment_method_data($payment_id);

    $paytabs_api = PaytabsAdapter::getPaytabsApi($processor_data);


    // Verify payment

    $payment_ref = $_POST[$param_paymentRef];
    $verify_response = $paytabs_api->verify_payment($payment_ref);
    $_logVerify = json_encode($verify_response);

    $success = $verify_response->success;
    $message = $verify_response->message;
    $orderId = $verify_response->cart_id;

    if ($orderId != $order_id) {
        paytabs_error_log("Callback failed for Order {$order_id}, Order mismatch [{$_logVerify}]");
        return;
    }

    $pp_response = array();

    if ($success) {
        $pp_response['reason_text'] = $message;
        $pp_response['order_status'] = $processor_data['processor_params']['order_status_after_payment'];
        $pp_response['transaction_id'] = $payment_ref;

        if (fn_check_payment_script('paytabs.php', $orderId)) {
            fn_finish_payment($orderId, $pp_response, true);
        }
    } else {
        paytabs_error_log("Callback failed for Order {$orderId}, response [{$_logVerify}]");

        //show the error message

        $pp_response['order_status'] = 'F';
        $pp_response['reason_text'] = $message;

        if ($payment_result = $verify_response->payment_result) {
            $pp_response['errorcode'] = $payment_result->response_status;
            $pp_response['errorName'] = $payment_result->response_code;
        }

        fn_update_order_payment_info($orderId, $pp_response);
        fn_change_order_status($orderId, $pp_response['order_status'], '', false);

        fn_finish_payment($orderId, $pp_response, false);
    }

    fn_order_placement_routines('route', $orderId);
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
}
