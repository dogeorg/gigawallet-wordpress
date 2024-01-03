<?php
/**
 * Plugin Name: GigaWallet Dogecoin Payment Gateway
 * Plugin URI: https://gigawallet.dogecoin.org
 * Text Domain: gigawallet
 * Description: Accept Dogecoin Payments using GigaWallet backend service without the need of any third party payment processor, banks, extra fees | Your Store, your wallet, your Doge.
 * Author: Dogecoin Foundation
 * Author URI: https://foundation.dogecoin.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 * Version: 0.01
 * Requires at least: 5.6
 * Tested up to: 6.3.1
 * WC requires at least: 5.7
 * WC tested up to: 8.0.3
 */

// Ignore if access directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'plugins_loaded', 'gigawallet_payment_init', 11 );
add_filter( 'woocommerce_currencies', 'gigawallet_bc_add_new_currency' );
add_filter( 'woocommerce_currency_symbol', 'gigawallet_bc_add_new_currency_symbol', 10, 2 );

// The Background task that will check all paid invoices and also send the Payout to the store owner Dogecoin Address
function gigawallet_order_status_callback() {        

    // We include the GigaWallet API Bridge
    include_once dirname( __FILE__ ) . '/inc/gigawallet-api.php';

    // To bypass wordpress on geting single option we have to get all GigaWallet config option here
    $giga_options = get_option( 'woocommerce_gigawallet_payment_settings');

    // The Shibe Dogecoin Payout Address
    $gigawallet_payto = $giga_options['gigawallet_payto'];
    // TheGigawallet minimum dust for the Payout not be stuck on mempool
    $gigawallet_dust = number_format((float)$giga_options['gigawallet_dust'], 8, '.', '');
    // The GigaWallet Server Admin Bind URL
    $gigawallet_adminbind = $giga_options['gigawallet_adminbind'];
    // The GigaWallet Server Admin Bind Port
    $gigawallet_adminbind_port = $giga_options['gigawallet_adminbind_port'];
    // The GigaWallet Server Public Bind URL
    $gigawallet_pubbind = $giga_options['gigawallet_pubbind'];
    // The GigaWallet Server Public Bind Port
    $gigawallet_pubbind_port = $giga_options['gigawallet_pubbind_port'];

    // GigaWallet Admin Server config
    $config["GigaServer"][0] = $gigawallet_adminbind;
    $config["GigaPort"][0] = $gigawallet_adminbind_port;   
    // GigaWallet Public Server config
    $config["GigaServer"][1] = $gigawallet_pubbind;
    $config["GigaPort"][1] = $gigawallet_pubbind_port;
    // GigaWallet Dust config
    $config["GigaDust"] = $gigawallet_dust;

    // we make the connection to GigaWallet server
    $G = new GigaWalletBridge($config);

    // Get all orders with "On Hold" and "Pending Payment" statuses
    $orders = wc_get_orders(array(
        'status' => array('on-hold', 'pending'),
    ));

    // Loop through the orders
    foreach ($orders as $order) {
        // Get the order ID
        $order_id = $order->get_id();

        // we get the gigawallet invoice id AKA (Doge Payment Address)
        $GigaWalletInvoiveId = get_post_meta($order_id, '_gigawallet_invoice_id', true);
        if ($GigaWalletInvoiveId != ""){
            try {
                // we get the invoive from GigaWallet
                $GigaInvoiceGet = json_decode($G->GetInvoice("wordpress",$GigaWalletInvoiveId));    

                // if the invoice on GigaWallet is paid we change the order status on Wordpress to Confirmed
                if ($GigaInvoiceGet->total_payment_confirmed){
                    // Update the order status to "Processing"
                    $order->update_status('processing');

                    // Example: Send notifications to admin and customer
                    $order->notification_email();
                    $order->customer_notification();

                    // Log the event or perform other actions
                    error_log('Order ' . $order_id . ' status updated.');
                };
            }
            catch(Exception $e){
                //throw new Exception("Error",0,$e);
            };               
        };
    };

            // if there is Doge money balance to be sent to the store owner, we will try to send every time
            //  until the balance is zero to be secure on their Dogecoin self-custodial wallet
            try {

                // we create a GigaWallet main Wallet if there is none yet
                $G->account("wordpress");

                // we get the current available balance on GigaWallet 
                $GigaAccountBalanceGet = json_decode($G->accountBalance("wordpress"));
                // if balance is more then zero we try to send the payout
                if ($GigaAccountBalanceGet->CurrentBalance > 0){ 

                    // we send the Dogecoin payment to the Shibe self-custodial wallet using GigaWallet            
                    $data = null;
                    $data["amount"] = $GigaAccountBalanceGet->CurrentBalance;
                    $data["to"] = $gigawallet_payto;
                    $G->PayTo("wordpress",$data);                    
                };  
            }
            catch(Exception $e){
                //throw new Exception("Error",0,$e);
            }      
};

// Schedule the cron job to run every minute
if (!wp_next_scheduled('gigawallet_order_checker_hook')) {
    wp_schedule_event(time(), 'every_minute', 'gigawallet_order_checker_hook');
}

// Hook to run your custom function when the cron job fires
add_action('gigawallet_order_checker_hook', 'gigawallet_order_status_callback');

// Define a custom cron schedule for every minute
function gigawallet_cron_schedules($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60, // 60 seconds (1 minute)
        'display'  => __('Every Minute', 'textdomain'),
    );
    return $schedules;
};

add_filter('cron_schedules', 'gigawallet_cron_schedules');

function gigawallet_bc_add_new_currency( $currencies ) {
     $currencies['DOGE'] = __( 'Dogecoin', 'Dogecoin' );
     return $currencies;
}


function gigawallet_bc_add_new_currency_symbol( $symbol, $currency ) {

     if( $currency == 'DOGE' ) {
          $symbol = 'Ð';
     }
     return $symbol;
}

function gigawallet_payment_init() {

    if( class_exists( 'WC_Payment_Gateway' ) ) {

        class Gigawallet_Gateway_Dogecoin extends WC_Payment_Gateway {
            public function __construct() {
                $this->id   = 'gigawallet_payment';
                $this->icon = apply_filters( 'woocommerce_gigawallet_icon', plugins_url('/assets/icon.svg', __FILE__ ) );
                $this->has_fields = false;
                $this->method_title = __( 'GigaWallet Dogecoin', 'gigawallet-wordpress');
                $this->method_description = __( 'Accepts Dogecoin payments using the GigaWallet Dogecoin payment backend service.', 'gigawallet');

                $this->title = __( 'Dogecoin (Gigawallet)', 'gigawallet');
                $this->gigawallet_payto = $this->get_option( 'gigawallet_payto' );

                $this->gigawallet_dust = (int)$this->get_option( 'gigawallet_dust' );
                $this->gigawallet_confirmations =  (int)$this->get_option( 'gigawallet_confirmations' );
                
                $this->gigawallet_adminbind = $this->get_option( 'gigawallet_adminbind' );
                $this->gigawallet_adminbind_port = $this->get_option( 'gigawallet_adminbind_port' );
                $this->gigawallet_pubbind = $this->get_option( 'gigawallet_pubbind' );
                $this->gigawallet_pubbind_port = $this->get_option( 'gigawallet_pubbind_port' );
                $this->gigawallet_fg = $this->get_option( 'gigawallet_fg' );
                $this->gigawallet_bg = $this->get_option( 'gigawallet_bg' );
                $this->gigawallet_instructions = $this->get_option( 'gigawallet_instructions' ); // field needed to display fields on client side

                // We include the GigaWallet API Bridge
                include_once dirname( __FILE__ ) . '/inc/gigawallet-api.php';

                // GigaWallet Server
                $config["GigaServer"][0] = $this->gigawallet_adminbind;
                $config["GigaServer"][1] = $this->gigawallet_pubbind;
                // GigaWallet Server port
                $config["GigaPort"][0] = $this->gigawallet_adminbind_port;
                $config["GigaPort"][1] = $this->gigawallet_pubbind_port;
                // GigaWallet Dust config
                $config["GigaDust"] = $this->gigawallet_dust;

                // we make the connection to GigaWallet server
                $this->G = new GigaWalletBridge($config);

                // We show the current available Doge balance on GigaWallet
                $this->amount = 0.00;
                try {
                    // we get the current available balance on GigaWallet 
                    $GigaAccountBalanceGet = json_decode($this->G->accountBalance("wordpress"));
                    // if balance more then zero we update
                    if ((int)$GigaAccountBalanceGet->CurrentBalance > 0){
                        $this->amount = number_format((float)$GigaAccountBalanceGet->CurrentBalance, 2, '.', '');
                    };  
                }
                catch(Exception $e){
                    //throw new Exception("Error",0,$e);
                }
                // we always update the current available balance
                $this->update_option( "gigawallet_balance", "Ð ".$this->amount );

                $this->init_form_fields();
                $this->init_settings();

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
                add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ));
                add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
            }


            public function init_form_fields() { 

                $this->form_fields = apply_filters( 'woo_gigawallet_pay_fields', array(
                    'enabled' => array(
                        'title' => __( 'Enable/Disable', 'gigawallet'),
                        'type' => 'checkbox',
                        'label' => __( 'Enable or Disable GigaWallet Dogecoin Payments', 'gigawallet'),
                        'default' => 'no'
                    ),
                    'gigawallet_balance' => array(
                        'title' => __( 'Your GigaWallet Balance', 'gigawallet'),
                        'type' => 'text',
                        'custom_attributes' => array(
                            'readonly' => 'readonly', // This makes the field read-only
                        ),                        
                        'default' => printf(__( 'Ð %s', 'gigawallet' ),esc_html($this->amount)),
                        'desc_tip' => true,
                        'description' => __( 'Your Dogecoin Balance on your GigaWallet Server', 'gigawallet')
                    ),
                    'gigawallet_payto' => array(
                        'title' => __( 'Dogecoin Payout Address', 'gigawallet'),
                        'type' => 'text',
                        'default' => __( 'Dxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Your Dogecoin address to recive your payments', 'gigawallet')
                    ),                    
                    'gigawallet_dust' => array(
                        'title' => __( 'GigaWallet Minimum Dust', 'gigawallet'),
                        'type' => 'float',
                        'default' => __( '0.019', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Minimum dust for the payout not be stuck on mempool', 'gigawallet')
                    ),
                    'gigawallet_confirmations' => array(
                        'title' => __( 'GigaWallet Minimum Confirmations', 'gigawallet'),
                        'type' => 'number',
                        'default' => __( '3', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Minimum Dogecoin Blockchain Confirmations needed to mark an invoice as paid', 'gigawallet')
                    ),                                          
                    'gigawallet_adminbind' => array(
                        'title' => __( 'GigaWallet Admin Server', 'gigawallet'),
                        'type' => 'text',
                        'default' => __( 'localhost', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Your GigaWallet Admin Bind Server', 'gigawallet')
                    ),
                    'gigawallet_adminbind_port' => array(
                        'title' => __( 'GigaWallet Admin Server Port', 'gigawallet'),
                        'type' => 'number',
                        'default' => __( '420', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Your GigaWallet Admin Bind Server Port', 'gigawallet')
                    ),                        
                    'gigawallet_pubbind' => array(
                        'title' => __( 'GigaWallet Public Server', 'gigawallet'),
                        'type' => 'text',
                        'default' => __( 'localhost', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Your GigaWallet Public Bind Server', 'gigawallet')
                    ),
                    'gigawallet_pubbind_port' => array(
                        'title' => __( 'GigaWallet Public Server Port', 'gigawallet'),
                        'type' => 'number',
                        'default' => __( '69', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Your GigaWallet Public Bind Server Port', 'gigawallet')
                    ),
                    'gigawallet_fg' => array(
                        'title' => __( 'GigaWallet QR foregorund', 'gigawallet'),
                        'type' => 'color',
                        'default' => __( '#000000', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Foreground color of the QR image', 'gigawallet')
                    ),
                    'gigawallet_bg' => array(
                        'title' => __( 'GigaWallet QR background', 'gigawallet'),
                        'type' => 'color',
                        'default' => __( '#ffffff', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Background color of the QR image', 'gigawallet')
                    ),                                    
                    'gigawallet_instructions' => array(
                        'title' => __( 'Payment Instructions', 'gigawallet'),
                        'type' => 'text',
                        'default' => __( 'Make sure to pay in full the order.', 'gigawallet'),
                        'desc_tip' => true,
                        'description' => __( 'Instructions shown to the buyer', 'gigawallet')
                    )                      
                ));
            }


    /**
     * Convert value to cripto by request
     *
     * @param mixed $value
     * @param string $from
     * @return mixed
     */
    static public function convert_to_crypto($value, $from='usd') {
      if ($from != 'DOGE'){
        $response = wp_remote_get("https://api.coingecko.com/api/v3/coins/markets?vs_currency=".strtolower(esc_html($from))."&ids=dogecoin&per_page=1&page=1&sparkline=false");
        $price = json_decode($response["body"]);
        $response = $value / $price[0]->current_price;
      }else{
        $response = $value;
      };
      $response = number_format($response, 2, '.', '');

      if ( is_wp_error($response) )
        return false;

       if ($response > 0)
        return trim($response);

      return 0;

    }

     /**
     * Generate DOGE payment fields
     *
     * @return void
     */
    function payment_fields() {
      $total = WC()->cart->total;
      $woo_currency = get_woocommerce_currency();
      $total = $this->convert_to_crypto($total,$woo_currency);
      echo '<h2 style="font-weight: bold;">&ETH; ' . esc_html($total) . '</h2><input type="hidden" name="muchdoge" value="' . esc_html($total) . '" />';
    }

    /**
     * Payment process handler
     *
     * @param int $order_id
     * @return array
     */
    function process_payment($order_id) {
        $order = new WC_Order($order_id);          
     
        // Create a GigaWallet account if needed
        $this->G->account("wordpress" ,esc_html($this->gigawallet_payto),0,0,"POST");

        // Get order items
        $order_items = $order->get_items();

        // we start the generation of the Gigawallet Invoice with all the products

        // Number of gigawallet confirmations to validate payment
        $data["required_confirmations"] = $this->gigawallet_confirmations;

        // Loop through the order items
        $i = 0; // item number 0
        foreach ($order_items as $item_id => $item_data) {
            $data["items"][$i]["type"] = "item"; // item type (item/tax/fee/shipping/discount/donation)
            $data["items"][$i]["name"] = $item_data->get_name(); // item name
            $data["items"][$i]["quantity"] = $item_data->get_quantity(); // item quantity    
            $data["items"][$i]["value"] = $item_data->get_total(); // item value
            $i++;
        }

        // Get the total shipping cost for the order
        $shipping_total = $order->get_shipping_total();

        if ($shipping_total > 0){
            $data["items"][$i]["type"] = "shipping"; // item type (item/tax/fee/shipping/discount/donation)
            $data["items"][$i]["name"] = "shipping"; // item name
            $data["items"][$i]["quantity"] = 1; // item quantity    
            $data["items"][$i]["value"] = $shipping_total; // item value
        }

        // we create the GigaWallet Invoice
        $GigaInvoiceCreate = json_decode($this->G->invoice("wordpress",$data));

        // We store the GigaWallet invoice ID to auto detect the payment to update the status
        update_post_meta($order_id, '_gigawallet_invoice_id', $GigaInvoiceCreate->id);    
        
        // Create redirect URL
        $redirect = get_permalink(wc_get_page_id('pay'));
        $redirect = add_query_arg('order', $order->id, $redirect);
        $redirect = add_query_arg('key', $order->order_key, $redirect);
        $order->reduce_order_stock();

      return array(
        'result'    => 'success',
        'redirect'  => $redirect,
      );
    }

     /**
     * Generate DOGE payment instructions and recipe
     *
     * @return void
     */
     public function receipt_page($order_id){
        $order = new WC_Order($order_id);

        // we get the gigawallet invoice id AKA (Doge Payment Address)
        $this->GigaWalletInvoiveId = get_post_meta($order_id, '_gigawallet_invoice_id', true);

        // We get the custum QR colors for GigaWallet
        $this->fg = str_replace("#", "", $this->gigawallet_fg);
        $this->bg = str_replace("#", "", $this->gigawallet_bg);

        // we echo the QR and Dogecoin Addrees generated by GigaWallet
        echo '<p style="text-align: center"><img style="margin:auto" src="' . esc_html(plugins_url('/assets/wow.gif', __FILE__ )) . '" alt="Gigawallet" /></p>';
        echo '<div class="row"><div style="border-top: 5px solid #ccc; border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px; padding-bottom: 15px">' . esc_html($this->gigawallet_instructions) . '</div><div class="col" style="float:none;margin:auto; text-align: center;max-width: 425px; border: 2px solid #ccc; border-radius: 15px; padding: 10px;"><div style="background-color: #ccc; padding: 10px; border-radius: 15px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px; text-align: center"><h2 style="font-size: 20px; color: rgba(0, 0, 0, 1); font-weight: bold">Ð '. esc_html($order->data["total"]) . '</h2></div>';
        echo '<img src="data:image/png;base64, ' . esc_html(base64_encode($this->G->qr($this->GigaWalletInvoiveId,$this->fg,$this->bg))) . '" style="max-width: 150;" alt="Much pay" />';
        echo '<div style="background-color: #ccc; padding: 10px; border-radius: 15px; border-top-left-radius: 0px; border-top-right-radius: 0px; color: rgba(0, 0, 0, 1)">' . esc_html($this->GigaWalletInvoiveId) . '</div>';
        echo '<br><br><a class="button" href="dogecoin:' . esc_html($this->GigaWalletInvoiveId) . '?amount=' . esc_html($order->data["total"]) . '" target="_blank">Click to Pay with Wallet</a>';

        // we add on private notes the Gigawallet pay to address
        $order->add_order_note("Pay to: ".esc_html($this->GigaWalletInvoiveId), false);

        $order->payment_complete();
        $order->update_status( 'pending',  __( 'Awaiting Dogecoin Payment Confirmation', 'gigawallet') );
        WC()->cart->empty_cart();
      }
  /**
   * Add content to the WC emails.
   *
   * @param WC_Order $order Order object.
   * @param bool     $sent_to_admin  Sent to admin.
   * @param bool     $plain_text Email format: plain text or HTML.
   */
  public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {    
        $order_id = $order->get_id();

        // we get the gigawallet invoice id AKA (Doge Payment Address)
        $this->GigaWalletInvoiveId = get_post_meta($order_id, '_gigawallet_invoice_id', true);

        // We get the custum QR colors for GigaWallet
        $this->fg = str_replace("#", "", $this->gigawallet_fg);
        $this->bg = str_replace("#", "", $this->gigawallet_bg);

        // we echo the QR and Dogecoin Addrees generated by GigaWallet
        echo '<p style="text-align: center"><img style="margin:auto" src="' . esc_html(plugins_url('/assets/wow.gif', __FILE__ )) . '" alt="Gigawallet" /></p>';
        echo '<div class="row"><div style="border-top: 5px solid #ccc; border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px; padding-bottom: 15px">' . esc_html($this->gigawallet_instructions) . '</div><div class="col" style="float:none;margin:auto; text-align: center;max-width: 425px; border: 2px solid #ccc; border-radius: 15px; padding: 10px;"><div style="background-color: #ccc; padding: 10px; border-radius: 15px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px; text-align: center"><h2 style="font-size: 20px; color: rgba(0, 0, 0, 1); font-weight: bold; text-align: center;">Ð '. esc_html($order->data["total"]) . '</h2></div>';
        echo '<p style="text-align: center; font-weight:bold; margin:15px">' . esc_html($this->GigaWalletInvoiveId) . '<p>';
        echo '<div style="background-color: #ccc; padding: 10px; border-radius: 15px; border-top-left-radius: 0px; border-top-right-radius: 0px; color: rgba(0, 0, 0, 1)"></div><br><br>';
  }


        }
    }
}


  add_filter( 'woocommerce_payment_gateways', 'push_to_gigawallet_payment_gateway');

  function push_to_gigawallet_payment_gateway( $gateways ) {
      $gateways[] = 'Gigawallet_Gateway_Dogecoin';
      return $gateways;
  }

