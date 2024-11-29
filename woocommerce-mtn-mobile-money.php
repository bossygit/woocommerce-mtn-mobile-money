<?php
/*
Plugin Name: WooCommerce MTN Mobile Money Payment Gateway
Description: Accept MTN Mobile Money payments through WooCommerce.
Version: 1.0
Author: Bienvenu KITUTU
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    // Initialize the gateway
    add_action( 'plugins_loaded', 'wc_mtn_mobile_money_init', 11 );

    function wc_mtn_mobile_money_init() {
       class WC_Gateway_MTN_Mobile_Money extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'mtn_mobile_money';
            $this->icon = plugin_dir_url(__FILE__) . 'momo-logo.jpg';
            $this->has_fields = true;
            $this->method_title = 'MTN Mobile Money';
            $this->method_description = 'Permet les paiements avec MTN Mobile Money.';

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user settings variables
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->primary_key = $this->get_option( 'primary_key' );
            $this->secondary_key = $this->get_option( 'secondary_key' );
            $this->api_user = $this->get_option( 'api_user' );
            $this->api_key = $this->get_option( 'api_key' );
            $this->test_mode = $this->get_option( 'test_mode' );
            
            // Set API URL based on mode
            $this->api_base_url = $this->test_mode === 'yes' ? 'https://sandbox.momodeveloper.mtn.com' : 'https://momodeveloper.mtn.com';

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // Payment listener/API hook
            add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_callback' ) );
        }

        // Other methods will go here
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Activer le paiement MTN Mobile Money',
                    'default'     => 'yes',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => "Titre que l'utilisateur voit lors du paiement.",
                    'default'     => 'MTN Mobile Money',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => "Description que l'utilisateur voit lors du paiement.",
                    'default'     => 'Payez avec MTN Mobile Money.',
                ),
                'primary_key' => array(
                    'title'       => 'Primary key',
                    'type'        => 'password',
                ),
                'secondary_key' => array(
                    'title'       => 'Secondary key',
                    'type'        => 'password',
                ),
                'api_user' => array(
                    'title'       => 'API User',
                    'type'        => 'text',
                ),
                'api_key' => array(
                    'title'       => 'API Key',
                    'type'        => 'password',
                ),

                    'test_mode' => array(
                    'title' => 'Mode test',
                    'type' => 'checkbox',
                    'label' => 'Activer le mode test',
                    'default' => 'yes',
                ),
  
            );
        }
        
        // Les champs de paiement
        public function payment_fields() {
            if ( $this->description ) {
                echo wpautop( wp_kses_post( $this->description ) );
            }
            ?>
        
            <fieldset>
                <p class="form-row form-row-wide">
                    <label for="mtn_mobile_money_phone"><?php _e( 'Numéro Mobile Money', 'woocommerce' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="mtn_mobile_money_phone" id="mtn_mobile_money_phone" placeholder="Entrez votre numéro MTN Mobile Money" required />
                </p>
            </fieldset>
        
            <?php
        }
        
        
        // Handle OAuth2 token generation
        public function get_access_token() {
            error_log('Get access token function');
            $url = $this->api_base_url . '/collection/token/';
            
            $auth = base64_encode("$this->api_user:" . $this->api_key);
        
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => "Basic $auth",
                    'Ocp-Apim-Subscription-Key' => $this->primary_key,
                ),
                'timeout' => 60, // Timeout in seconds
            ));
        
            // Check for errors
            if (is_wp_error($response)) {
                error_log('Access token error: ' . $response->get_error_message());
                return false;
            }
        
        	$token_array = json_decode('{"token_results":[' . $response['body'] . ']}');
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);
            error_log('Corps : ' . $body);

            return $token_array->token_results[0]->access_token ?? false;
        }   
        
        // Request payment from MTN Mobile Money 
        public function request_payment($order,$access_token,$uuid) {
            
            error_log("Requete de paiement");
            $url = $this->api_base_url ."/collection/v1_0/requesttopay";
            $msisdn = $_POST["mtn_mobile_money_phone"] ?? '';
            
            $amount = $order->get_total(); 
            $order_id = $order->get_id();
            
            error_log("Numero msidn : ".$msisdn);
            
        $curl_post_data = array(            
            'amount' => $amount,
			'currency' => 'EUR',
            'externalId' => $uuid,
            'payer' => array('partyIdType' => 'MSISDN','partyId' => $msisdn ), 
            'payerMessage' => 'Online Payment for order number '.$order_id,
            'payeeNote' => 'Online Payment for order number '.$order_id
        );
        
        $data_string = json_encode($curl_post_data);		
		$response = wp_remote_post($url,
		array('headers' => array(
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $access_token,
		'X-Reference-Id' => $uuid,
		'X-Target-Environment' => 'sandbox',
		'Ocp-Apim-Subscription-Key' => $this->primary_key
		),
		'body'    => $data_string));	
		
		// Check for errors

        $statusCode = wp_remote_retrieve_response_code( $response );
		error_log('Status code requete : '.$statusCode); 
        if (is_wp_error($response) || $statusCode != 202 ) {
                error_log('Payment request error: ' . $response->get_error_message());
                return false;
            }
            
        return true;
        
        
        }
        
        // Payment processing 
        public function process_payment($order_id){
            error_log("Process paiement fonction");
            $order = wc_get_order($order_id);
            $uuid = wp_generate_uuid4();
            
            // Fetch Mobile Mobile access token
            $access_token = $this->get_access_token();
            if(!$access_token){
                wc_add_notice("Impossible de traiter le paiement : erreur de jeton d'accès", 'error');
                return;
            }
            
            // Call payment API to initiate the payment
            $payment_response = $this->request_payment($order, $access_token,$uuid);
            if ($payment_response) {
                // Save UUID and payment info to order meta
                update_post_meta($order_id, '_mtn_payment_uuid', $uuid);
        
                // Redirect to intermediary page with order ID as parameter
                return array(
                    'result' => 'success',
                    'redirect' => home_url('/mobile-money-intermediary/?order_id=' . $order_id)
                );
            } else {
                wc_add_notice('L initiation du paiement a échoué' , 'error');
                return;
            }
        }



    }
    }

        // Add the gateway to WooCommerce
        add_filter( 'woocommerce_payment_gateways', 'wc_add_mtn_mobile_money_gateway' );

        function wc_add_mtn_mobile_money_gateway( $methods ) {
            $methods[] = 'WC_Gateway_MTN_Mobile_Money';
            return $methods;
        }
}
