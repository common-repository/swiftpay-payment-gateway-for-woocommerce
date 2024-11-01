<?php
/*
 * Plugin Name: SwiftPay Payment Gateway for WooCommerce
 * Description: SwiftPay plugin
 * Author: SwiftPay
 * Author URI: https://www.swiftpay.ph
 * Version: 1.2.2
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'swiftpay_add_gateway_class');
function swiftpay_add_gateway_class($gateways) {
    $gateways[] = 'WC_Swiftpay_Gateway'; // class name
    return $gateways;
}

function swiftpay_log($message) {
    $pluginlog = '/tmp/swiftpay.log';
//    error_log($message . "\n", 3, $pluginlog);
}

add_action('plugins_loaded', 'swiftpay_init_gateway_class');
function swiftpay_init_gateway_class() {

    class WC_Swiftpay_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->plugin_version = '1.2.2';

            $this->id = 'swiftpay'; // payment gateway plugin ID
            $this->icon = 'https://assets.swiftpay.ph/img/swiftpay_logo_32.png'; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true;
            $this->method_title = 'Payments via SwiftPay';
            $this->method_description = 'SwiftPay WooCommerce payment plugin'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = 'Online Transfer';
            $this->description = '';    // description that is displayed on the checkout page, above the institutions
            $this->enabled = $this->get_option('enabled');
            $this->qa_env = $this->get_option('qa_env');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->priorityMode = 'yes' === $this->get_option('priorityMode');
            update_option('swiftpay_priorityMode', $this->priorityMode);
            swiftpay_log("set swiftpay_priorityMode: " . $this->priorityMode);
            $this->access_key = $this->testmode ? $this->get_option('test_access_key') : $this->get_option('live_access_key');
            $this->secret_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('live_secret_key');

            $this->gateway_url = $this->testmode ? "api.pay.sandbox.live.swiftpay.ph" : "api.pay.live.swiftpay.ph";
            if ($this->qa_env) $this->gateway_url = "api.pay.qa.swiftpay.ph";

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Include custom js/css
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

            // Webhooks (example: http://host/wordpress/?wc-api=swiftpay_webhook -> webhook())
            add_action('woocommerce_api_' . 'swiftpay_webhook', array($this, 'webhook'));

            // Version info (example: http://host/wordpress/?wc-api=swiftpay_status -> status())
            add_action('woocommerce_api_' . 'swiftpay_status', array($this, 'status'));

        }

        /**
         * Plugin options
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable SwiftPay Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'testmode' => array(
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test keys. In this mode, the transactions will NOT result in debiting money from customer accounts.',
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'test_access_key' => array(
                    'title' => 'TEST Access Key',
                    'type' => 'text'
                ),
                'test_secret_key' => array(
                    'title' => 'TEST Secret Key',
                    'type' => 'password',
                ),
                'live_access_key' => array(
                    'title' => 'LIVE Access Key',
                    'type' => 'text'
                ),
                'live_secret_key' => array(
                    'title' => 'LIVE Secret Key',
                    'type' => 'password',
                ),
                'priorityMode' => array(
                    'title' => 'Priority Mode',
                    'label' => 'Enable priority Mode',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'yes'
                ),
            );

            if (isset($_GET['swiftpay_admin']) && $_GET['swiftpay_admin'] == "true") {
                $this->form_fields['qa_env'] = array(
                    'title' => 'QA Environment',
                    'label' => 'INTERNAL QA SwiftPay environment',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'desc_tip' => true,
                );
            }
        }

        /**
         * You will need it if you want your custom credit card form, Step 4 is about it
         */
        public function payment_fields() {
            //description before the payment form
            if ($this->testmode) {
                $this->description .= ' <strong>TEST MODE ENABLED!</strong><div style="font-size: 12px; margin-bottom: 10px;">The transaction will be simulated and will not affect the bank account balance.</div>';
            } else {
                $this->description .= ' Pay instantly from your regular savings account at BDO, BPI, UnionBank and 27 other banks.';
            }
            $this->description = trim($this->description);
            echo wpautop(wp_kses_post($this->description));

            echo '<fieldset id="wc-' . esc_attr($this->id) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;border:none;">';

            // Add this action hook if you want your custom payment gateway to support it
            do_action('woocommerce_credit_card_form_start', $this->id);

            $swiftPayEnabled = $this->enabled;
            $swiftPayTestMode = $this->testmode;
            $accessKey = $this->access_key;
            $pluginVersion = $this->plugin_version;

            echo "
              <script>
                window.SwiftPay={};
                window.SwiftPay.enabled='${swiftPayEnabled}';
                window.SwiftPay.testMode='${swiftPayTestMode}';
                window.SwiftPay.accessKey='${accessKey}';
                window.SwiftPay.pluginVersion='${pluginVersion}';
              </script>";
            echo '
        <div id="swiftpay_div"></div>
	    <input id="swiftpay_data" name="swiftpay_data" type="hidden" autocomplete="off" value="">

		<div class="clear"></div>';
            do_action('woocommerce_credit_card_form_end', $this->id);
            echo '<div class="clear"></div></fieldset>';
        }

        public function payment_scripts() {
            if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
                return;
            }

            // if our payment gateway is disabled, we do not have to enqueue JS/CSS
            if ('no' === $this->enabled) {
                return;
            }

            wp_register_style('woocommerce_swiftpay_css', plugins_url('swiftpay.css', __FILE__));
            wp_enqueue_style('woocommerce_swiftpay_css');

            wp_register_script('woocommerce_swiftpay_js', "https://assets.swiftpay.ph/woo/swiftpay_woo.js", array('jquery', 'json2'));  // can also include other scripts defined by name
            wp_localize_script('woocommerce_swiftpay_js', 'params', array(
                'apiKey' => $this->access_key,
                'testMode' => $this->testmode
            ));
            wp_enqueue_script('woocommerce_swiftpay_js');


        }

        /*
          * Fields validation
         */
        public function validate_fields() {
            if ((empty($this->access_key) || empty($this->secret_key))) {
                wc_add_notice('Access/Secret Key not provided for SwiftPay. Please set the API key in the SwiftPay configuration!', 'error');
                return false;
            }

            if (empty($_POST['billing_first_name'])) {
                wc_add_notice('First name is required!', 'error');
                return false;
            }

            return true;

        }

        public function process_payment($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $order->set_payment_method('SwiftPay');
            $order->set_payment_method_title('SwiftPay');
            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', __('Awaiting SwiftPay payment', 'woocommerce'));

            // prepare redirect URL:
            $swiftpayData = sanitize_text_field($_POST['swiftpay_data']);
            $amount = $order->calculate_totals();
            $amount = number_format((float)$amount, 2, '.', '');
            $gatewayUrl = $this->gateway_url;
            $currency = $order->get_currency() ? $order->get_currency() : 'PHP';


            $params = array(
                'x_access_key' => $this->access_key,
                'x_reference_no' => $order_id,
                'x_amount' => $amount,
                'x_currency' => $currency,
                'pv' => $this->plugin_version
            );
            $swiftPayURL = add_query_arg($params, "https://${gatewayUrl}/api/bootstrap");

            $signature = $this->calculate_signature($params);
            $swiftPayURL .= "&signature=${signature}";

            /**
             * Appends the optional swiftpay data to the url.
             * NOTE: the string cannot contain any parameters starting with x_
             *       as they won't be included in the signature
             */
            if ($swiftpayData) {
                $swiftPayURL .= $swiftpayData;
            }

            /**
             * Send data on the order
             */
            $this->sendData($order, $order_id, null);

            // Redirect to SwiftPay
            return array(
                'result' => 'success',
                'redirect' => $swiftPayURL
            );

        }


        /**
         * Once payment is processed (EXECUTED|CANCELED|REJECTED) SwiftPay will call merchant callback URL (POST)
         * Callback payload: JSON
         * {
         * "x_payment_id": "...", // payment ID granted by SwiftPay, required
         * "x_payment_status": "EXECUTED|CANCELED|REJECTED", // payment status on SwiftPay side, required
         * "x_reference_no": "...", // merchant ref no (required)
         * "signature": "..." // HMAC signature
         * }
         */
        public function webhook() {
            global $woocommerce;
//            update_option('webhook_debug', $_GET);

            $checkout_page_url = function_exists('wc_get_cart_url') ?
                wc_get_checkout_url() : $woocommerce->cart->get_checkout_url();

            $reference_no = sanitize_text_field($_GET['x_reference_no']);
            $order = wc_get_order($reference_no);
            $signature = $this->calculate_signature($_GET);

            if ($signature !== sanitize_text_field($_GET['signature'])) {
                $order->cancel_order('Signature invalid');
                wc_add_notice('Signature invalid for ' . $reference_no . " (${signature}). Please contact SwiftPay plugin administrator.", 'error');
                wp_redirect($checkout_page_url);
                return;
            }

            $paymentId = sanitize_text_field($_GET['x_payment_id']);
            //$oldPaymentId = $order->get_meta_data()['swiftpay_payment_id'];
            if ($paymentId) {
                $order->add_meta_data('swiftpay_payment_id', $paymentId, true);
            }

            $payment_status = sanitize_text_field($_GET['x_payment_status']);
            $order_status = $order->get_status();

            switch ($payment_status) {
                case 'PENDING':
                    wc_add_notice(__('Payment error:', 'woothemes') . 'Payment is still pending.', 'error');
                    wp_redirect($checkout_page_url);

                    break;
                case 'EXECUTED':
                    if ($order_status !== 'completed') {
                        $order_note = "SwiftPay payment COMPLETED\n" .
                            "Payment ID: " . $paymentId . "\n";
                        $order->add_order_note($order_note, /* is_customer_note */ 1);
                        $order->payment_complete();
                        $order->reduce_order_stock();
                    }

                    // Remove cart
                    $woocommerce->cart->empty_cart();

                    // eventually redirect to confirmation page
                    $redirectUrl = $this->get_return_url($order);
                    wp_redirect($redirectUrl);
                    break;

                case 'REJECTED':
                    if ($order_status !== 'failed') {
                        $order_note = "SwiftPay payment REJECTED\n" .
                            "Payment ID: " . $paymentId . "\n";
                        $order->add_order_note($order_note, /* is_customer_note */ 1);
                        $order->update_status('failed');
                    }
                    wc_add_notice(__('Payment error:', 'woothemes') . 'Payment was rejected.', 'error');
                    wp_redirect($checkout_page_url);
                    break;

                case 'CANCELED':
                    if ($order_status !== 'cancelled') {
                        $order_note = "SwiftPay payment CANCELED by the customer\n" .
                            "Payment ID: " . $paymentId . "\n";
                        $order->add_order_note($order_note, /* is_customer_note */ 1);
                        $order->cancel_order('Payment was canceled.');
                    }
                    wc_add_notice(__('Payment error:', 'woothemes') . 'Payment was canceled.', 'error');
                    wp_redirect($checkout_page_url);
                    break;
            }

        }



        public function status() {
            $status = array(
                'version' => $this->plugin_version,
                'enabled' => $this->enabled === "yes",
                'testMode' => $this->testmode,
            );
            wp_send_json( $status, 200);
        }

        /**
         * POST /api/orders
         *
         * Input body: JSON
         * {
         * "x_access_key": "...", // merchant access key, required
         * "x_payment_id": "...", // payment ID (passed through callback), required
         * "x_reference_no": "...", // merchant ref no (required)
         * "details": {}, // order JSON details - structure is up to the caller, required
         * "signature": "..." // HMAC signature
         * }
         */
        public function sendData($order, $reference_no) {
            $data = $order->get_data();

            $items = array();
            foreach ($order->get_items() as $item_id => $item) {
                $item_data = $item->get_data(); // Get WooCommerce order item meta data in an unprotected array
                array_push($items, array(
                    'name' => $item_data['name'],
                    'product_id' => $item_data['product_id'],
                    'total' => $item_data['total'],
                    'quantity' => $item_data['quantity']
                ));
            }
            $data['items'] = $items;

            $xElements = array_filter($data, function ($v, $k) {
                return $v === 0 || !empty($v);  // filter out all falsy values (except for 0)
            }, ARRAY_FILTER_USE_BOTH);

            $signature = $this->calculate_signature(array(
                'x_access_key' => $this->access_key,
                'x_payment_id' => null,
                'x_reference_no' => $reference_no
            ));

            $root = array(
                'x_access_key' => $this->access_key,
                'x_payment_id' => null,
                'x_reference_no' => $reference_no,
                'details' => $xElements,
                'signature' => $signature
            );

            $gateway_url = $this->gateway_url;
            $url = "https://${gateway_url}/api/orders";
            $options = array(
                'http' => array(
                    'method' => 'POST',
                    'content' => json_encode($root),
                    'header' => "Content-Type: application/json\r\n" .
                        "Accept: application/json\r\n"
                )
            );

            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
        }

        /**
         * Calculates the HMAC/SHA256 signature of a parameter array.
         * All elements without a x_ prefix are ignored. The array is sorted alphabetically by key, concatenated
         * without using any separators and the resulting string is then passed to the hashing function.
         *
         * @param $params array of key -> value pairs
         * @return string the calculated signature
         */
        function calculate_signature($params) {
            // filter out elements with x_ prefix
            $xElements = array_filter($params, function ($k) {
                return substr($k, 0, 2) === "x_";
            }, ARRAY_FILTER_USE_KEY);

            // sort by keys
            ksort($xElements);

            // concatenate params
            $payload = "";
            foreach ($xElements as $k => $v) {
                $payload .= "$k$v";
            }

            return hash_hmac('sha256', $payload, $this->secret_key);
        }
    }

    // ----------- utils --------------

    function console_log($output, $with_script_tags = true) {
        $js_code = 'console.log(' . json_encode($output, JSON_HEX_TAG) .
            ');';
        if ($with_script_tags) {
            $js_code = '<script>' . $js_code . '</script>';
        }
        echo $js_code;
    }

}

add_action( 'template_redirect', 'define_default_payment_gateway' );
function define_default_payment_gateway(){
    $swiftpay_priorityMode = get_option('swiftpay_priorityMode');
    swiftpay_log("get swiftpay_priorityMode: " . $swiftpay_priorityMode);
    if( $swiftpay_priorityMode && is_checkout() && ! is_wc_endpoint_url() ) {
        $default_payment_id = 'swiftpay';
        WC()->session->set( 'chosen_payment_method', $default_payment_id );
    }
}

?>
