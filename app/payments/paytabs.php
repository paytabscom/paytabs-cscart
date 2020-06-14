<?php

define('PAYTABS_PAYPAGE_VERSION', '1.0.1');
define('PAYTABS_DEBUG_FILE', DIR_ROOT . "/var/debug_paytabs.log");

defined('BOOTSTRAP') or die('Access denied');

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
    $paytabs_helper = new PaytabsHelper($processor_data);
    $paytabs_api = $paytabs_helper->pt();

    $return_url = fn_url("payment_notification?payment=paytabs&order_id={$order_id}", AREA, 'current');


    $total = $order_info['total'];

    $products = $order_info['products'];
    $products_str = implode(' || ', array_map(function ($p) {
        return $p['product'] . " ({$p['amount']})";
    }, $products));


    $firstname = $order_info['firstname'];
    $lastname = $order_info['lastname'];
    $address = $order_info['b_address'] . ' ' . $order_info['b_address_2'];
    $city = $order_info['b_city'];
    $state = $order_info['b_state'];
    $country = $order_info['b_country'];
    $zipcode = $order_info['b_zipcode'];
    $phone = $order_info['b_phone'];
    $email = $order_info['email'];
    $ip_address = $order_info['ip_address'];


    $post_data = [
        "tran_type"  => "sale",
        "tran_class" => "ecom",

        "cart_id"          => "$order_id",
        "cart_description" => $products_str,
        "cart_amount"      => $total,
        "cart_currency"    => CART_SECONDARY_CURRENCY,

        "callback" => $return_url,
        "return"   => $return_url,

        // "hide_shipping" => true,

        "customer_details" => [
            'name'    => "{$firstname} {$lastname}",
            'email'   => $email,
            'street1' => $address,
            'city'    => $city,
            'state'   => $state,
            'country' => $country,
            'ip'      => $ip_address,
        ],
    ];

    $paypage = $paytabs_api->create_pay_page($post_data);

    $success = isset($paypage->redirect_url) && $paypage->redirect_url != '';

    $_logPaypage = json_encode($paypage);
    paytabs_error_log("Create paypage result: {$_logPaypage}");

    if ($success) {

        $url = $paypage->redirect_url;
        fn_create_payment_form($url, $post_data, 'PayTabs server', false);
    } else {
        // Here Error
        $msg = $paypage->message;
        $pp_response["reason_text"] = $msg;

        fn_finish_payment($order_id, $pp_response, false);
        fn_set_notification('E', __('warning'), $msg, true, '');
        fn_order_placement_routines($order_id);
        die;
    }
}


/**
 * Weebhook/Callback called by PayTabs's server after completing the payment
 */
function paymentComplete()
{
    $order_id = $_REQUEST['order_id'];

    if (!key_exists('tranRef', $_POST)) {
        //Not post or payment_reference not posted then error

        paytabs_error_log("Callback failed for Order {$order_id}, [tranRef] not defined");

        fn_order_placement_routines('route', $order_id);
        return;
    }

    $payment_ref = $_POST['tranRef'];

    $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
    $processor_data = fn_get_payment_method_data($payment_id);

    $paytabs_helper = new PaytabsHelper($processor_data);
    $paytabs_api = $paytabs_helper->pt();


    // Verify payment

    $response_data = $paytabs_api->verify_payment($payment_ref);
    $_logVerify = json_encode($response_data);

    if ($response_data->cart_id != $order_id) {
        paytabs_error_log("Callback failed for Order {$order_id}, Order mismatch [{$_logVerify}]");
        return;
    }

    $paymentDone = $response_data && $response_data->payment_result;

    if (!$paymentDone) {
        paytabs_error_log("Callback failed for Order {$order_id}, response [{$_logVerify}]");

        //show the error message
        $msg = $response_data->message;
        $pp_response['order_status'] = 'F';
        $pp_response['reason_text'] = $msg;
        fn_update_order_payment_info($_REQUEST['order_id'], $pp_response);
        fn_change_order_status($_REQUEST['order_id'], $pp_response['order_status'], '', false);
        return;
    }

    $payment_result = $response_data->payment_result;
    $success = $payment_result->response_status === 'A';

    $pp_response = array();
    $pp_response['reason_text'] = $payment_result->response_message;

    if ($success) {
        $pp_response['order_status'] = $processor_data['processor_params']['order_status_after_payment'];
        $pp_response['transaction_id'] = $payment_ref;
        if (fn_check_payment_script('paytabs.php', $order_id)) {
            fn_finish_payment($order_id, $pp_response, true);
        }
    } else {
        paytabs_error_log("Callback failed for Order {$order_id}, response [{$_logVerify}]");

        //show the error message
        $pp_response['order_status'] = 'F';
        $pp_response['errorcode'] = $payment_result->response_status;
        $pp_response['errorName'] = $payment_result->response_code;

        fn_update_order_payment_info($order_id, $pp_response);
        fn_change_order_status($order_id, $pp_response['order_status'], '', false);

        fn_finish_payment($order_id, $pp_response, false);
    }

    fn_order_placement_routines('route', $order_id);
}

//

class PaytabsHelper
{
    private $config;
    private $paymentMethod;

    public function __construct($processor_data)
    {
        $paytabs_admin = $processor_data["processor_params"];

        $this->config = $paytabs_admin;
        // $this->paymentMethod = $paymentMethod;
    }

    public function pt()
    {
        $profile_id = intval($this->config['profile_id']);
        $secretKey = $this->config['secret_key'];
        // $merchant_email = $paytabs_admin['merchant_email'];

        $pt = new PaytabsApi($profile_id, $secretKey);

        return $pt;
    }

    static function paymentType($key)
    {
        return PaytabsApi::PAYMENT_TYPES[$key]['name'];
    }

    static function paymentAllowed($code, $currencyCode)
    {
        $row = null;
        foreach (PaytabsApi::PAYMENT_TYPES as $key => $value) {
            if ($value['name'] === $code) {
                $row = $value;
                break;
            }
        }
        if (!$row) {
            return false;
        }
        $list = $row['currencies'];
        if ($list == null) {
            return true;
        }

        $currencyCode = strtoupper($currencyCode);

        return in_array($currencyCode, $list);
    }

    public static function getCountryDetails($iso_2)
    {
        $countryPhoneList = array(
            'AD' => array('name' => 'ANDORRA', 'code' => '376'),
            'AE' => array('name' => 'UNITED ARAB EMIRATES', 'code' => '971'),
            'AF' => array('name' => 'AFGHANISTAN', 'code' => '93'),
            'AG' => array('name' => 'ANTIGUA AND BARBUDA', 'code' => '1268'),
            'AI' => array('name' => 'ANGUILLA', 'code' => '1264'),
            'AL' => array('name' => 'ALBANIA', 'code' => '355'),
            'AM' => array('name' => 'ARMENIA', 'code' => '374'),
            'AN' => array('name' => 'NETHERLANDS ANTILLES', 'code' => '599'),
            'AO' => array('name' => 'ANGOLA', 'code' => '244'),
            'AQ' => array('name' => 'ANTARCTICA', 'code' => '672'),
            'AR' => array('name' => 'ARGENTINA', 'code' => '54'),
            'AS' => array('name' => 'AMERICAN SAMOA', 'code' => '1684'),
            'AT' => array('name' => 'AUSTRIA', 'code' => '43'),
            'AU' => array('name' => 'AUSTRALIA', 'code' => '61'),
            'AW' => array('name' => 'ARUBA', 'code' => '297'),
            'AZ' => array('name' => 'AZERBAIJAN', 'code' => '994'),
            'BA' => array('name' => 'BOSNIA AND HERZEGOVINA', 'code' => '387'),
            'BB' => array('name' => 'BARBADOS', 'code' => '1246'),
            'BD' => array('name' => 'BANGLADESH', 'code' => '880'),
            'BE' => array('name' => 'BELGIUM', 'code' => '32'),
            'BF' => array('name' => 'BURKINA FASO', 'code' => '226'),
            'BG' => array('name' => 'BULGARIA', 'code' => '359'),
            'BH' => array('name' => 'BAHRAIN', 'code' => '973'),
            'BI' => array('name' => 'BURUNDI', 'code' => '257'),
            'BJ' => array('name' => 'BENIN', 'code' => '229'),
            'BL' => array('name' => 'SAINT BARTHELEMY', 'code' => '590'),
            'BM' => array('name' => 'BERMUDA', 'code' => '1441'),
            'BN' => array('name' => 'BRUNEI DARUSSALAM', 'code' => '673'),
            'BO' => array('name' => 'BOLIVIA', 'code' => '591'),
            'BR' => array('name' => 'BRAZIL', 'code' => '55'),
            'BS' => array('name' => 'BAHAMAS', 'code' => '1242'),
            'BT' => array('name' => 'BHUTAN', 'code' => '975'),
            'BW' => array('name' => 'BOTSWANA', 'code' => '267'),
            'BY' => array('name' => 'BELARUS', 'code' => '375'),
            'BZ' => array('name' => 'BELIZE', 'code' => '501'),
            'CA' => array('name' => 'CANADA', 'code' => '1'),
            'CC' => array('name' => 'COCOS (KEELING) ISLANDS', 'code' => '61'),
            'CD' => array('name' => 'CONGO, THE DEMOCRATIC REPUBLIC OF THE', 'code' => '243'),
            'CF' => array('name' => 'CENTRAL AFRICAN REPUBLIC', 'code' => '236'),
            'CG' => array('name' => 'CONGO', 'code' => '242'),
            'CH' => array('name' => 'SWITZERLAND', 'code' => '41'),
            'CI' => array('name' => 'COTE D IVOIRE', 'code' => '225'),
            'CK' => array('name' => 'COOK ISLANDS', 'code' => '682'),
            'CL' => array('name' => 'CHILE', 'code' => '56'),
            'CM' => array('name' => 'CAMEROON', 'code' => '237'),
            'CN' => array('name' => 'CHINA', 'code' => '86'),
            'CO' => array('name' => 'COLOMBIA', 'code' => '57'),
            'CR' => array('name' => 'COSTA RICA', 'code' => '506'),
            'CU' => array('name' => 'CUBA', 'code' => '53'),
            'CV' => array('name' => 'CAPE VERDE', 'code' => '238'),
            'CX' => array('name' => 'CHRISTMAS ISLAND', 'code' => '61'),
            'CY' => array('name' => 'CYPRUS', 'code' => '357'),
            'CZ' => array('name' => 'CZECH REPUBLIC', 'code' => '420'),
            'DE' => array('name' => 'GERMANY', 'code' => '49'),
            'DJ' => array('name' => 'DJIBOUTI', 'code' => '253'),
            'DK' => array('name' => 'DENMARK', 'code' => '45'),
            'DM' => array('name' => 'DOMINICA', 'code' => '1767'),
            'DO' => array('name' => 'DOMINICAN REPUBLIC', 'code' => '1809'),
            'DZ' => array('name' => 'ALGERIA', 'code' => '213'),
            'EC' => array('name' => 'ECUADOR', 'code' => '593'),
            'EE' => array('name' => 'ESTONIA', 'code' => '372'),
            'EG' => array('name' => 'EGYPT', 'code' => '20'),
            'ER' => array('name' => 'ERITREA', 'code' => '291'),
            'ES' => array('name' => 'SPAIN', 'code' => '34'),
            'ET' => array('name' => 'ETHIOPIA', 'code' => '251'),
            'FI' => array('name' => 'FINLAND', 'code' => '358'),
            'FJ' => array('name' => 'FIJI', 'code' => '679'),
            'FK' => array('name' => 'FALKLAND ISLANDS (MALVINAS)', 'code' => '500'),
            'FM' => array('name' => 'MICRONESIA, FEDERATED STATES OF', 'code' => '691'),
            'FO' => array('name' => 'FAROE ISLANDS', 'code' => '298'),
            'FR' => array('name' => 'FRANCE', 'code' => '33'),
            'GA' => array('name' => 'GABON', 'code' => '241'),
            'GB' => array('name' => 'UNITED KINGDOM', 'code' => '44'),
            'GD' => array('name' => 'GRENADA', 'code' => '1473'),
            'GE' => array('name' => 'GEORGIA', 'code' => '995'),
            'GH' => array('name' => 'GHANA', 'code' => '233'),
            'GI' => array('name' => 'GIBRALTAR', 'code' => '350'),
            'GL' => array('name' => 'GREENLAND', 'code' => '299'),
            'GM' => array('name' => 'GAMBIA', 'code' => '220'),
            'GN' => array('name' => 'GUINEA', 'code' => '224'),
            'GQ' => array('name' => 'EQUATORIAL GUINEA', 'code' => '240'),
            'GR' => array('name' => 'GREECE', 'code' => '30'),
            'GT' => array('name' => 'GUATEMALA', 'code' => '502'),
            'GU' => array('name' => 'GUAM', 'code' => '1671'),
            'GW' => array('name' => 'GUINEA-BISSAU', 'code' => '245'),
            'GY' => array('name' => 'GUYANA', 'code' => '592'),
            'HK' => array('name' => 'HONG KONG', 'code' => '852'),
            'HN' => array('name' => 'HONDURAS', 'code' => '504'),
            'HR' => array('name' => 'CROATIA', 'code' => '385'),
            'HT' => array('name' => 'HAITI', 'code' => '509'),
            'HU' => array('name' => 'HUNGARY', 'code' => '36'),
            'ID' => array('name' => 'INDONESIA', 'code' => '62'),
            'IE' => array('name' => 'IRELAND', 'code' => '353'),
            'IL' => array('name' => 'ISRAEL', 'code' => '972'),
            'IM' => array('name' => 'ISLE OF MAN', 'code' => '44'),
            'IN' => array('name' => 'INDIA', 'code' => '91'),
            'IQ' => array('name' => 'IRAQ', 'code' => '964'),
            'IR' => array('name' => 'IRAN, ISLAMIC REPUBLIC OF', 'code' => '98'),
            'IS' => array('name' => 'ICELAND', 'code' => '354'),
            'IT' => array('name' => 'ITALY', 'code' => '39'),
            'JM' => array('name' => 'JAMAICA', 'code' => '1876'),
            'JO' => array('name' => 'JORDAN', 'code' => '962'),
            'JP' => array('name' => 'JAPAN', 'code' => '81'),
            'KE' => array('name' => 'KENYA', 'code' => '254'),
            'KG' => array('name' => 'KYRGYZSTAN', 'code' => '996'),
            'KH' => array('name' => 'CAMBODIA', 'code' => '855'),
            'KI' => array('name' => 'KIRIBATI', 'code' => '686'),
            'KM' => array('name' => 'COMOROS', 'code' => '269'),
            'KN' => array('name' => 'SAINT KITTS AND NEVIS', 'code' => '1869'),
            'KP' => array('name' => 'KOREA DEMOCRATIC PEOPLES REPUBLIC OF', 'code' => '850'),
            'KR' => array('name' => 'KOREA REPUBLIC OF', 'code' => '82'),
            'KW' => array('name' => 'KUWAIT', 'code' => '965'),
            'KY' => array('name' => 'CAYMAN ISLANDS', 'code' => '1345'),
            'KZ' => array('name' => 'KAZAKSTAN', 'code' => '7'),
            'LA' => array('name' => 'LAO PEOPLES DEMOCRATIC REPUBLIC', 'code' => '856'),
            'LB' => array('name' => 'LEBANON', 'code' => '961'),
            'LC' => array('name' => 'SAINT LUCIA', 'code' => '1758'),
            'LI' => array('name' => 'LIECHTENSTEIN', 'code' => '423'),
            'LK' => array('name' => 'SRI LANKA', 'code' => '94'),
            'LR' => array('name' => 'LIBERIA', 'code' => '231'),
            'LS' => array('name' => 'LESOTHO', 'code' => '266'),
            'LT' => array('name' => 'LITHUANIA', 'code' => '370'),
            'LU' => array('name' => 'LUXEMBOURG', 'code' => '352'),
            'LV' => array('name' => 'LATVIA', 'code' => '371'),
            'LY' => array('name' => 'LIBYAN ARAB JAMAHIRIYA', 'code' => '218'),
            'MA' => array('name' => 'MOROCCO', 'code' => '212'),
            'MC' => array('name' => 'MONACO', 'code' => '377'),
            'MD' => array('name' => 'MOLDOVA, REPUBLIC OF', 'code' => '373'),
            'ME' => array('name' => 'MONTENEGRO', 'code' => '382'),
            'MF' => array('name' => 'SAINT MARTIN', 'code' => '1599'),
            'MG' => array('name' => 'MADAGASCAR', 'code' => '261'),
            'MH' => array('name' => 'MARSHALL ISLANDS', 'code' => '692'),
            'MK' => array('name' => 'MACEDONIA, THE FORMER YUGOSLAV REPUBLIC OF', 'code' => '389'),
            'ML' => array('name' => 'MALI', 'code' => '223'),
            'MM' => array('name' => 'MYANMAR', 'code' => '95'),
            'MN' => array('name' => 'MONGOLIA', 'code' => '976'),
            'MO' => array('name' => 'MACAU', 'code' => '853'),
            'MP' => array('name' => 'NORTHERN MARIANA ISLANDS', 'code' => '1670'),
            'MR' => array('name' => 'MAURITANIA', 'code' => '222'),
            'MS' => array('name' => 'MONTSERRAT', 'code' => '1664'),
            'MT' => array('name' => 'MALTA', 'code' => '356'),
            'MU' => array('name' => 'MAURITIUS', 'code' => '230'),
            'MV' => array('name' => 'MALDIVES', 'code' => '960'),
            'MW' => array('name' => 'MALAWI', 'code' => '265'),
            'MX' => array('name' => 'MEXICO', 'code' => '52'),
            'MY' => array('name' => 'MALAYSIA', 'code' => '60'),
            'MZ' => array('name' => 'MOZAMBIQUE', 'code' => '258'),
            'NA' => array('name' => 'NAMIBIA', 'code' => '264'),
            'NC' => array('name' => 'NEW CALEDONIA', 'code' => '687'),
            'NE' => array('name' => 'NIGER', 'code' => '227'),
            'NG' => array('name' => 'NIGERIA', 'code' => '234'),
            'NI' => array('name' => 'NICARAGUA', 'code' => '505'),
            'NL' => array('name' => 'NETHERLANDS', 'code' => '31'),
            'NO' => array('name' => 'NORWAY', 'code' => '47'),
            'NP' => array('name' => 'NEPAL', 'code' => '977'),
            'NR' => array('name' => 'NAURU', 'code' => '674'),
            'NU' => array('name' => 'NIUE', 'code' => '683'),
            'NZ' => array('name' => 'NEW ZEALAND', 'code' => '64'),
            'OM' => array('name' => 'OMAN', 'code' => '968'),
            'PA' => array('name' => 'PANAMA', 'code' => '507'),
            'PE' => array('name' => 'PERU', 'code' => '51'),
            'PF' => array('name' => 'FRENCH POLYNESIA', 'code' => '689'),
            'PG' => array('name' => 'PAPUA NEW GUINEA', 'code' => '675'),
            'PH' => array('name' => 'PHILIPPINES', 'code' => '63'),
            'PK' => array('name' => 'PAKISTAN', 'code' => '92'),
            'PL' => array('name' => 'POLAND', 'code' => '48'),
            'PM' => array('name' => 'SAINT PIERRE AND MIQUELON', 'code' => '508'),
            'PN' => array('name' => 'PITCAIRN', 'code' => '870'),
            'PR' => array('name' => 'PUERTO RICO', 'code' => '1'),
            'PS' => array('name' => 'PALESTINE', 'code' => '970'),
            'PT' => array('name' => 'PORTUGAL', 'code' => '351'),
            'PW' => array('name' => 'PALAU', 'code' => '680'),
            'PY' => array('name' => 'PARAGUAY', 'code' => '595'),
            'QA' => array('name' => 'QATAR', 'code' => '974'),
            'RO' => array('name' => 'ROMANIA', 'code' => '40'),
            'RS' => array('name' => 'SERBIA', 'code' => '381'),
            'RU' => array('name' => 'RUSSIAN FEDERATION', 'code' => '7'),
            'RW' => array('name' => 'RWANDA', 'code' => '250'),
            'SA' => array('name' => 'SAUDI ARABIA', 'code' => '966'),
            'SB' => array('name' => 'SOLOMON ISLANDS', 'code' => '677'),
            'SC' => array('name' => 'SEYCHELLES', 'code' => '248'),
            'SD' => array('name' => 'SUDAN', 'code' => '249'),
            'SE' => array('name' => 'SWEDEN', 'code' => '46'),
            'SG' => array('name' => 'SINGAPORE', 'code' => '65'),
            'SH' => array('name' => 'SAINT HELENA', 'code' => '290'),
            'SI' => array('name' => 'SLOVENIA', 'code' => '386'),
            'SK' => array('name' => 'SLOVAKIA', 'code' => '421'),
            'SL' => array('name' => 'SIERRA LEONE', 'code' => '232'),
            'SM' => array('name' => 'SAN MARINO', 'code' => '378'),
            'SN' => array('name' => 'SENEGAL', 'code' => '221'),
            'SO' => array('name' => 'SOMALIA', 'code' => '252'),
            'SR' => array('name' => 'SURINAME', 'code' => '597'),
            'ST' => array('name' => 'SAO TOME AND PRINCIPE', 'code' => '239'),
            'SV' => array('name' => 'EL SALVADOR', 'code' => '503'),
            'SY' => array('name' => 'SYRIAN ARAB REPUBLIC', 'code' => '963'),
            'SZ' => array('name' => 'SWAZILAND', 'code' => '268'),
            'TC' => array('name' => 'TURKS AND CAICOS ISLANDS', 'code' => '1649'),
            'TD' => array('name' => 'CHAD', 'code' => '235'),
            'TG' => array('name' => 'TOGO', 'code' => '228'),
            'TH' => array('name' => 'THAILAND', 'code' => '66'),
            'TJ' => array('name' => 'TAJIKISTAN', 'code' => '992'),
            'TK' => array('name' => 'TOKELAU', 'code' => '690'),
            'TL' => array('name' => 'TIMOR-LESTE', 'code' => '670'),
            'TM' => array('name' => 'TURKMENISTAN', 'code' => '993'),
            'TN' => array('name' => 'TUNISIA', 'code' => '216'),
            'TO' => array('name' => 'TONGA', 'code' => '676'),
            'TR' => array('name' => 'TURKEY', 'code' => '90'),
            'TT' => array('name' => 'TRINIDAD AND TOBAGO', 'code' => '1868'),
            'TV' => array('name' => 'TUVALU', 'code' => '688'),
            'TW' => array('name' => 'TAIWAN, PROVINCE OF CHINA', 'code' => '886'),
            'TZ' => array('name' => 'TANZANIA, UNITED REPUBLIC OF', 'code' => '255'),
            'UA' => array('name' => 'UKRAINE', 'code' => '380'),
            'UG' => array('name' => 'UGANDA', 'code' => '256'),
            'US' => array('name' => 'UNITED STATES', 'code' => '1'),
            'UY' => array('name' => 'URUGUAY', 'code' => '598'),
            'UZ' => array('name' => 'UZBEKISTAN', 'code' => '998'),
            'VA' => array('name' => 'HOLY SEE (VATICAN CITY STATE)', 'code' => '39'),
            'VC' => array('name' => 'SAINT VINCENT AND THE GRENADINES', 'code' => '1784'),
            'VE' => array('name' => 'VENEZUELA', 'code' => '58'),
            'VG' => array('name' => 'VIRGIN ISLANDS, BRITISH', 'code' => '1284'),
            'VI' => array('name' => 'VIRGIN ISLANDS, U.S.', 'code' => '1340'),
            'VN' => array('name' => 'VIET NAM', 'code' => '84'),
            'VU' => array('name' => 'VANUATU', 'code' => '678'),
            'WF' => array('name' => 'WALLIS AND FUTUNA', 'code' => '681'),
            'WS' => array('name' => 'SAMOA', 'code' => '685'),
            'XK' => array('name' => 'KOSOVO', 'code' => '381'),
            'YE' => array('name' => 'YEMEN', 'code' => '967'),
            'YT' => array('name' => 'MAYOTTE', 'code' => '262'),
            'ZA' => array('name' => 'SOUTH AFRICA', 'code' => '27'),
            'ZM' => array('name' => 'ZAMBIA', 'code' => '260'),
            'ZW' => array('name' => 'ZIMBABWE', 'code' => '263')
        );

        $arr = array();

        if (isset($countryPhoneList[$iso_2])) {
            $phcountry = $countryPhoneList[$iso_2];
            $arr['phone'] = $phcountry['code'];
            $arr['country'] = $phcountry['name'];
        }

        return $arr;
    }

    public static function countryGetiso3($iso_2)
    {
        $iso = array(
            'AND' => 'AD',
            'ARE' => 'AE',
            'AFG' => 'AF',
            'ATG' => 'AG',
            'AIA' => 'AI',
            'ALB' => 'AL',
            'ARM' => 'AM',
            'AGO' => 'AO',
            'ATA' => 'AQ',
            'ARG' => 'AR',
            'ASM' => 'AS',
            'AUT' => 'AT',
            'AUS' => 'AU',
            'ABW' => 'AW',
            'ALA' => 'AX',
            'AZE' => 'AZ',
            'BIH' => 'BA',
            'BRB' => 'BB',
            'BGD' => 'BD',
            'BEL' => 'BE',
            'BFA' => 'BF',
            'BGR' => 'BG',
            'BHR' => 'BH',
            'BDI' => 'BI',
            'BEN' => 'BJ',
            'BLM' => 'BL',
            'BMU' => 'BM',
            'BRN' => 'BN',
            'BOL' => 'BO',
            'BES' => 'BQ',
            'BRA' => 'BR',
            'BHS' => 'BS',
            'BTN' => 'BT',
            'BVT' => 'BV',
            'BWA' => 'BW',
            'BLR' => 'BY',
            'BLZ' => 'BZ',
            'CAN' => 'CA',
            'CCK' => 'CC',
            'COD' => 'CD',
            'CAF' => 'CF',
            'COG' => 'CG',
            'CHE' => 'CH',
            'CIV' => 'CI',
            'COK' => 'CK',
            'CHL' => 'CL',
            'CMR' => 'CM',
            'CHN' => 'CN',
            'COL' => 'CO',
            'CRI' => 'CR',
            'CUB' => 'CU',
            'CPV' => 'CV',
            'CUW' => 'CW',
            'CXR' => 'CX',
            'CYP' => 'CY',
            'CZE' => 'CZ',
            'DEU' => 'DE',
            'DJI' => 'DJ',
            'DNK' => 'DK',
            'DMA' => 'DM',
            'DOM' => 'DO',
            'DZA' => 'DZ',
            'ECU' => 'EC',
            'EST' => 'EE',
            'EGY' => 'EG',
            'ESH' => 'EH',
            'ERI' => 'ER',
            'ESP' => 'ES',
            'ETH' => 'ET',
            'FIN' => 'FI',
            'FJI' => 'FJ',
            'FLK' => 'FK',
            'FSM' => 'FM',
            'FRO' => 'FO',
            'FRA' => 'FR',
            'GAB' => 'GA',
            'GBR' => 'GB',
            'GRD' => 'GD',
            'GEO' => 'GE',
            'GUF' => 'GF',
            'GGY' => 'GG',
            'GHA' => 'GH',
            'GIB' => 'GI',
            'GRL' => 'GL',
            'GMB' => 'GM',
            'GIN' => 'GN',
            'GLP' => 'GP',
            'GNQ' => 'GQ',
            'GRC' => 'GR',
            'SGS' => 'GS',
            'GTM' => 'GT',
            'GUM' => 'GU',
            'GNB' => 'GW',
            'GUY' => 'GY',
            'HKG' => 'HK',
            'HMD' => 'HM',
            'HND' => 'HN',
            'HRV' => 'HR',
            'HTI' => 'HT',
            'HUN' => 'HU',
            'IDN' => 'ID',
            'IRL' => 'IE',
            'ISR' => 'IL',
            'IMN' => 'IM',
            'IND' => 'IN',
            'IOT' => 'IO',
            'IRQ' => 'IQ',
            'IRN' => 'IR',
            'ISL' => 'IS',
            'ITA' => 'IT',
            'JEY' => 'JE',
            'JAM' => 'JM',
            'JOR' => 'JO',
            'JPN' => 'JP',
            'KEN' => 'KE',
            'KGZ' => 'KG',
            'KHM' => 'KH',
            'KIR' => 'KI',
            'COM' => 'KM',
            'KNA' => 'KN',
            'PRK' => 'KP',
            'KOR' => 'KR',
            'XKX' => 'XK',
            'KWT' => 'KW',
            'CYM' => 'KY',
            'KAZ' => 'KZ',
            'LAO' => 'LA',
            'LBN' => 'LB',
            'LCA' => 'LC',
            'LIE' => 'LI',
            'LKA' => 'LK',
            'LBR' => 'LR',
            'LSO' => 'LS',
            'LTU' => 'LT',
            'LUX' => 'LU',
            'LVA' => 'LV',
            'LBY' => 'LY',
            'MAR' => 'MA',
            'MCO' => 'MC',
            'MDA' => 'MD',
            'MNE' => 'ME',
            'MAF' => 'MF',
            'MDG' => 'MG',
            'MHL' => 'MH',
            'MKD' => 'MK',
            'MLI' => 'ML',
            'MMR' => 'MM',
            'MNG' => 'MN',
            'MAC' => 'MO',
            'MNP' => 'MP',
            'MTQ' => 'MQ',
            'MRT' => 'MR',
            'MSR' => 'MS',
            'MLT' => 'MT',
            'MUS' => 'MU',
            'MDV' => 'MV',
            'MWI' => 'MW',
            'MEX' => 'MX',
            'MYS' => 'MY',
            'MOZ' => 'MZ',
            'NAM' => 'NA',
            'NCL' => 'NC',
            'NER' => 'NE',
            'NFK' => 'NF',
            'NGA' => 'NG',
            'NIC' => 'NI',
            'NLD' => 'NL',
            'NOR' => 'NO',
            'NPL' => 'NP',
            'NRU' => 'NR',
            'NIU' => 'NU',
            'NZL' => 'NZ',
            'OMN' => 'OM',
            'PAN' => 'PA',
            'PER' => 'PE',
            'PYF' => 'PF',
            'PNG' => 'PG',
            'PHL' => 'PH',
            'PAK' => 'PK',
            'POL' => 'PL',
            'SPM' => 'PM',
            'PCN' => 'PN',
            'PRI' => 'PR',
            'PSE' => 'PS',
            'PRT' => 'PT',
            'PLW' => 'PW',
            'PRY' => 'PY',
            'QAT' => 'QA',
            'REU' => 'RE',
            'ROU' => 'RO',
            'SRB' => 'RS',
            'RUS' => 'RU',
            'RWA' => 'RW',
            'SAU' => 'SA',
            'SLB' => 'SB',
            'SYC' => 'SC',
            'SDN' => 'SD',
            'SSD' => 'SS',
            'SWE' => 'SE',
            'SGP' => 'SG',
            'SHN' => 'SH',
            'SVN' => 'SI',
            'SJM' => 'SJ',
            'SVK' => 'SK',
            'SLE' => 'SL',
            'SMR' => 'SM',
            'SEN' => 'SN',
            'SOM' => 'SO',
            'SUR' => 'SR',
            'STP' => 'ST',
            'SLV' => 'SV',
            'SXM' => 'SX',
            'SYR' => 'SY',
            'SWZ' => 'SZ',
            'TCA' => 'TC',
            'TCD' => 'TD',
            'ATF' => 'TF',
            'TGO' => 'TG',
            'THA' => 'TH',
            'TJK' => 'TJ',
            'TKL' => 'TK',
            'TLS' => 'TL',
            'TKM' => 'TM',
            'TUN' => 'TN',
            'TON' => 'TO',
            'TUR' => 'TR',
            'TTO' => 'TT',
            'TUV' => 'TV',
            'TWN' => 'TW',
            'TZA' => 'TZ',
            'UKR' => 'UA',
            'UGA' => 'UG',
            'UMI' => 'UM',
            'USA' => 'US',
            'URY' => 'UY',
            'UZB' => 'UZ',
            'VAT' => 'VA',
            'VCT' => 'VC',
            'VEN' => 'VE',
            'VGB' => 'VG',
            'VIR' => 'VI',
            'VNM' => 'VN',
            'VUT' => 'VU',
            'WLF' => 'WF',
            'WSM' => 'WS',
            'YEM' => 'YE',
            'MYT' => 'YT',
            'ZAF' => 'ZA',
            'ZMB' => 'ZM',
            'ZWE' => 'ZW',
            'SCG' => 'CS',
            'ANT' => 'AN',
        );

        $iso_3 = "";

        foreach ($iso as $key => $val) {
            if ($val == $iso_2) {
                $iso_3 = $key;
                break;
            }
        }

        return $iso_3;
    }

    public static function getNonEmpty(...$vars)
    {
        foreach ($vars as $var) {
            if (!empty($var)) return $var;
        }
        return false;
    }

    /**
     * @param $items: array of the products, each product has the format ['name' => xx, 'quantity' => x, 'price' =>x]
     * @return array to pass to paypage API in the format ['products_per_title' => 'xx || xx ', 'quantity' => 'xx || xx', 'unit_price' => 'xx || xx']
     */
    public static function prepare_products(array $items)
    {
        $glue = ' || ';

        $products_str = implode($glue, array_map(function ($p) {
            $name = str_replace('||', '/', $p['name']);
            return $name;
        }, $items));

        $quantity = implode($glue, array_map(function ($p) {
            return $p['quantity'];
        }, $items));

        $unit_price = implode($glue, array_map(function ($p) {
            return $p['price'];
        }, $items));


        return [
            'products_per_title' => $products_str,
            'quantity'           => $quantity,
            'unit_price'         => $unit_price,
        ];
    }
}


class PaytabsApi
{
    const PAYMENT_TYPES = [
        '1' => ['name' => 'stcpay', 'currencies' => ['SAR']],
        '2' => ['name' => 'stcpayqr', 'currencies' => ['SAR']],
        '3' => ['name' => 'applepay', 'currencies' => ['AED', 'SAR']],
        '4' => ['name' => 'omannet', 'currencies' => ['OMR']],
        '5' => ['name' => 'mada', 'currencies' => ['SAR']],
        '6' => ['name' => 'creditcard', 'currencies' => null],
        '7' => ['name' => 'sadad', 'currencies' => ['SAR']],
        '8' => ['name' => 'atfawry', 'currencies' => ['EGP']],
        '9' => ['name' => 'knpay', 'currencies' => ['KWD']],
        '10' => ['name' => 'amex', 'currencies' => ['AED', 'SAR']],
        '11' => ['name' => 'valu', 'currencies' => ['EGP']],
    ];
    const URL_AUTHENTICATION = "https://www.paytabs.com/apiv2/validate_secret_key";
    const PAYPAGE_URL = 'https://secure.paytabs.com/payment/request';
    const VERIFY_URL = 'https://secure.paytabs.com/payment/query';

    private $profile_id;
    // private $merchant_email;
    private $secret_key;

    function __construct($profile_id, $secret_key)
    {
        $this->profile_id = $profile_id;
        $this->secret_key = $secret_key;
    }

    function authentication()
    {
        $obj = json_decode($this->runPost(self::URL_AUTHENTICATION, array("merchant_email" => $this->merchant_email, "secret_key" =>  $this->secret_key)), TRUE);

        if ($obj->response_code == "4000") {
            return TRUE;
        }
        return FALSE;
    }

    function create_pay_page($values)
    {
        $serverIP = getHostByName(getHostName());
        $values['ip_merchant'] = PaytabsHelper::getNonEmpty($serverIP, $_SERVER['SERVER_ADDR'], 'NA');

        $values['customer_details']['ip'] = PaytabsHelper::getNonEmpty($values['customer_details']['ip'], $_SERVER['REMOTE_ADDR'], 'NA');

        $paypage = $this->sendRequest(self::PAYPAGE_URL, $values);
        return json_decode($paypage);
    }

    function verify_payment($payment_reference)
    {
        $values['tran_ref'] = $payment_reference;
        return json_decode($this->sendRequest(self::VERIFY_URL, $values));
    }

    private function sendRequest($gateway_url, $values)
    {
        $auth_key = $this->secret_key;

        $headers = [
            'Content-Type: application/json',
            "Authorization: {$auth_key}"
        ];

        $values['profile_id'] = $this->profile_id;
        $post_params = json_encode($values);

        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $gateway_url);
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $post_params);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_HEADER, false);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        @curl_setopt($ch, CURLOPT_VERBOSE, true);
        // @curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = @curl_exec($ch);
        if (!$result) {
            die(curl_error($ch));
        }
        @curl_close($ch);

        return $result;
    }
}
