<?php
/*
    Plugin Name: PGateWC
    Author: Mark E. Greene
    Author URI: www.avant5.com
    Version: 0.0.3
    Description: PGateWC : Paypal REST API Gateway for WooCommerce

    @avant5

    Requires PaypalSDK 1.7.4+

    Special thanks to Igor Benic (@igorbenic) for the code this is based on
    See also: https://ibenic.com/how-to-create-a-custom-woocommerce-payment-gateway/

    Functions, variables and constants prefixed with 'woo' have been renamed to avoid confusion over
    which things are specific to this plugin and which are Woocommerce core elements and functions.

    TODO
    - Processing refunds
    - Admin panel tooltips

*/

defined( 'ABSPATH' ) || exit;

define( 'PGATEWC_DIR', plugin_dir_path( __FILE__ )); 
add_action( 'plugins_loaded', 'pgatewc_main' );

use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\ExecutePayment;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Refund;
use PayPal\Api\Sale;
use PayPal\Api\Transaction;


function pgatewc_main() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

	class PGateWCG extends WC_Payment_Gateway {
		/**
		 * API Context used for PayPal Authorization
		 * @var null
		 */
		public $apiContext = null;
		
		/**
		 * Constructor for your shipping class
		 *
		 * @access public
		 * @return void
		 */
        // Pay with PayPal, Credit or Debit card
		public function __construct() {
		    	$this->id                 	= 'pgatewc'; 
		    	$this->method_title       	= __( 'PGateWC Paypal Gateway', 'pgatewc' );  
		    	$this->method_description 	= __( 'Custom REST API Paypal Payment Gateway', 'pgatewc' );
		    	$this->title              	= __( 'Paypal', 'pgatewc' );
			$this->has_fields = false;
			$this->supports = array( 
				'products','refunds'
			);
			$this->get_paypal_sdk();
		   	// Load the settings.
        	$this->init_form_fields();
        	$this->init_settings();
            $this->enabled 		= $this->get_option('enabled');
            $this->description    = $this->get_option( 'description' );
		    
		    	add_action( 'check_pgatewc', array( $this, 'check_response') );
		    	// Save settings
  			if ( is_admin() ) {
  				// Versions over 2.0
  				// Save our administration options. Since we are not going to be doing anything special
  				// we have not defined 'process_admin_options' in this class so the method in the parent
  				// class will be used instead
  				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
  			}	
        }




        private function get_paypal_sdk() {
            require_once PGATEWC_DIR . 'includes/paypal-sdk/autoload.php';
        }



        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable', 'pgatewc' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable PGate WC', 'pgatewc' ),
                    'default' => 'no'
                ),
                'description' => array(
                    'title' => __( 'Description', 'pgatewc' ),
                    'type' => 'text',
                    'default' => 'Pay securely with credit or debit card, or your Paypal account.'
                ),
                'sandbox' => array(
                    'title' => __( 'Sandbox Mode', 'pgatewc' ),
                    'type' => 'checkbox',
                    'label' => __( 'Sandbox Mode', 'pgatewc' ),
                    'default' => 'yes'
                ),
                'checkout_button_text' => array(
                    'title' => __( 'Checkout button text', 'pgatewc' ),
                    'type' => 'text',
                    'default' => 'Proceed to Paypal'
                ),
                'client_id' => array(
                    'title' => __( 'Client ID', 'pgatewc' ),
                    'type' => 'text',
                    'default' => ''
                ),
                'client_secret' => array(
                    'title' => __( 'Client Secret', 'pgatewc' ),
                    'type' => 'password',
                    'default' => ''
                ),
            );
        }


        // hacked PayPalConstants.php to set always in Live mode
        private function get_api_context(){
            $client_id =  $this->get_option('client_id');
            $client_secret =  $this->get_option('client_secret');
            $this->apiContext = new ApiContext(new OAuthTokenCredential(
                $client_id,
                $client_secret
            ));

            // 'mode' => 'Sandbox' for testing option DEBUG :delete this comment after testing
            $theMode = ($this->get_option('sandbox'))?'Sandbox':'Live';
            $this->apiContext->setConfig(
                array(
                    'mode' => 'Sandbox',
                    'log.LogEnabled' => true,
                    'log.FileName' => 'PayPal.log',
                    'log.LogLevel' => 'DEBUG', // PLEASE USE `INFO` LEVEL FOR LOGGING IN LIVE ENVIRONMENTS
                )
            );
        } // end get_api_context()



        public function process_payment( $order_id ) {
            global $woocommerce;
            $order = new WC_Order( $order_id );
            $this->get_api_context();
            $payer = new Payer();
            $payer->setPaymentMethod("paypal");
            
            $all_items = array();
            $subtotal = 0;
            // Products
            foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
                $itemObject = new Item();
                $itemObject->setCurrency( get_woocommerce_currency() );
                if ( 'fee' === $item['type'] ) {
                    $itemObject->setName( __( 'Fee', 'pgatewc' ) );
                    $itemObject->setQuantity(1);
                    $itemObject->setPrice( $item['line_total'] ); 
                    $subtotal += $item['line_total'];
                } else {
                    $product          = $order->get_product_from_item( $item );
                    $sku              = $product ? $product->get_sku() : '';
                    $itemObject->setName( $item['name'] );
                    $itemObject->setQuantity( $item['qty'] );
                    $itemObject->setPrice( $order->get_item_subtotal( $item, false ) );
                    $subtotal += $order->get_item_subtotal( $item, false ) * $item['qty'];
                    if( $sku ) {
                        $itemObject->setSku( $sku );
                    }  
                }
                $all_items[] = $itemObject;
            }
             
            $itemList = new ItemList();
            $itemList->setItems( $all_items );
            // ### Additional payment details
            // Use this optional field to set additional
            // payment information such as tax, shipping
            // charges etc.
            $details = new Details();
            $details->setShipping( $order->get_total_shipping() )
                ->setTax( $order->get_total_tax() )
                ->setSubtotal( $subtotal );
            $amount = new Amount();
            $amount->setCurrency( get_woocommerce_currency() )
                ->setTotal( $order->get_total() )
                ->setDetails($details);
            $transaction = new Transaction();
            $transaction->setAmount($amount)
                ->setItemList($itemList)
                ->setInvoiceNumber(uniqid());
            $baseUrl = $this->get_return_url( $order );
            if( strpos( $baseUrl, '?') !== false ) {
                $baseUrl .= '&';
            } else {
                $baseUrl .= '?';
            }
            $redirectUrls = new RedirectUrls();
            $redirectUrls->setReturnUrl( $baseUrl . 'pgatewc=true&order_id=' . $order_id )
                ->setCancelUrl( $baseUrl . 'pgatewc=cancel&order_id=' . $order_id );
                $payment = new Payment();
            $payment->setIntent("sale")
                ->setPayer($payer)
                ->setRedirectUrls($redirectUrls)
                ->setTransactions(array($transaction));
            try {
                $payment->create($this->apiContext);
                $approvalUrl = $payment->getApprovalLink();
                return array(
                    'result' => 'success',
                    'redirect' => $approvalUrl
                );
            } catch (Exception $ex) {
                wc_add_notice(  $ex->getMessage(), 'error' );
            }
            return array(
                    'result' => 'failure',
                    'redirect' => ''
            );
        } // process_payment()




        public function check_response() {
            
            global $woocommerce;
             
            if( isset( $_GET['pgatewc'] ) ) {
             
                $pgatewc = $_GET['pgatewc'];
                $order_id = $_GET['order_id'];
                if( $order_id == 0 || $order_id == '' ) {
                    return;
                }
                $order = new WC_Order( $order_id );
                if( $order->has_status('completed') || $order->has_status('processing')) {
                    return;
                }
                if( $pgatewc == 'true' ) {
                    $this->get_api_context();
                    $paymentId = $_GET['paymentId'];
                    $payment = Payment::get($paymentId, $this->apiContext);
                    $execution = new PaymentExecution();
                    $execution->setPayerId($_GET['PayerID']);
                    $transaction = new Transaction();
                        $amount = new Amount();
                        $details = new Details();
                        $subtotal = 0;
                    // Products
                    foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
                        if ( 'fee' === $item['type'] ) {
                            $subtotal += $item['line_total'];
                        } else {
                            $subtotal += $order->get_item_subtotal( $item, false ) * $item['qty'];
                    
                        }
                    }
                      $details->setShipping( $order->get_total_shipping() )
                     ->setTax( $order->get_total_tax() )
                     ->setSubtotal( $subtotal );
                 
                    
                      $amount = new Amount();
                      $amount->setCurrency( get_woocommerce_currency() )
                           ->setTotal( $order->get_total() )
                             ->setDetails($details);
          
                          $transaction->setAmount($amount);
          
                          $execution->addTransaction($transaction);
                    try { 
                    
                          $result = $payment->execute($execution, $this->apiContext);
                          $json = json_decode($result,true);
                          $transactionID = $json['transactions'][0]['related_resources'][0]['sale']['id'];
                          //$order->add_order_note($result); // DEBUG
                          
                    } catch (Exception $ex) { 
                    
                          $data = json_decode( $ex->getData());
                          
                          wc_add_notice(  $ex->getMessage(), 'error' );
                    
                          $order->update_status('failed', sprintf( __( '%s payment failed! Transaction ID: %d', 'woocommerce' ), $this->title, $paymentId ) . ' ' . $ex->getMessage() );
                          return;
                    }

                    // Payment complete
                      $order->payment_complete( $transactionID );
                      // Add order note
                      $order->add_order_note( sprintf( __( '%s payment approved! Payment ID: %s Transaction ID: %s', 'woocommerce' ), $this->title, $paymentId, $transactionID ) );
              
                      // Remove cart
                      $woocommerce->cart->empty_cart();
          
                }

                if( $pgatewc == 'cancel' ) { 
                /**
                 * Should this mark the order as cancelled?
                 * The cart is still intact and valid, only the payment is cancelled
                 * This method kills the order and a duplicate is created at payment time
                 */

                    // $order does not need to be instantiated again
                    //$order = new WC_Order( $order_id );

                    $order->update_status('cancelled', sprintf( __( '%s payment cancelled.', 'woocommerce' ), $this->title ) );

                    // alternatively could return to cart? get_cart_url()
                    $return_page = $woocommerce->cart->get_checkout_url();
                    wp_safe_redirect( $return_page );
                    exit;
                    return;
                }

              }

              return;
        } // check_response()


        public function process_refund($order_id, $amount = null, $reason = '') {
            /**
             * Process the return.
             * See https://developer.paypal.com/docs/api/quickstart/refund-payment/ for SDK details
             */

            global $woocommerce;
            $order = wc_get_order( $order_id );

            $order->add_order_note('In Refund, amount: '.$amount );
            $order->add_order_note('Currency: '. $order->get_currency() );

            $this->get_api_context();
            $amt = new Amount();
            $amt->setTotal($amount)
            ->setCurrency( $order->get_currency() );

            $refund = new Refund();
            $refund->setAmount($amt);

            $sale = new Sale();
            $sale->setId( $order->get_transaction_id() );
            $order->add_order_note('TID: '. $order->get_transaction_id() );
            
            try {
                $refundedSale = $sale->refund($refund, $this->apiContext);
                $order->add_order_note('Refunded');
            } catch (PayPal\Exception\PayPalConnectionException $ex) {
                //echo $ex->getCode();
                //echo $ex->getData();
                $order->add_order_note('error: '. $ex );
            } catch (Exception $ex) {
                $order->add_order_note('error: '. $ex );
            }

            return true;
        }
        


	}
}


/**
 * Add Gateway class to all payment gateway methods
 */
function pgatewc( $methods ) {
	
	$methods[] = 'PGateWCG'; 
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'pgatewc' );



add_action( 'init', 'check_for_pgatewc' );
function check_for_pgatewc() {
	if( isset($_GET['pgatewc'])) {
	  // Start the gateways
		WC()->payment_gateways();
		do_action( 'check_pgatewc' );
	}
	
}


add_filter( 'woocommerce_order_button_text', 'pgatewc_custom_order_button' ); 
function pgatewc_custom_order_button() {
    $opt = get_option('woocommerce_pgatewc_settings');
    $opt = $opt['checkout_button_text'];
    return __( $opt, 'woocommerce' );
}
?>