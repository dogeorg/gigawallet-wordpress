<?php
/**
 * Plugin Name: Easy GigaWallet Dogecoin Gateway
 * Plugin URI: https://gigawallet.dogecoin.org
 * Text Domain: gigawallet
 * Description: Accept Dogecoin Payments using GigaWallet backend service without the need of any third party payment processor, banks, extra fees | Your Store, your wallet, your Doge.
 * Author: Dogecoin Foundation
 * Author URI: https://foundation.dogecoin.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 * Version: 0.04
 * Requires at least: 5.6
 * Tested up to: 6.7.1
 * WC requires at least: 5.7
 * WC tested up to: 9.4.2
 */

 // Ignore if access directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// GigaWallet API Bridge
class GigaWalletBridge {

    private $config;     // include GigaWallet Configurations
    public function __construct($config) {
        $this->config = $config;
    }

    // Creates/Gets a GigaWallet Account
    public function account($foreign_id,$payout_address = NULL,$payout_threshold = 0,$payout_frequency = 0,$method = "POST") {

        // Builds the Gigawallet Command
        $command = "/account/" . $foreign_id;
        $data["payout_address"] = $payout_address; // address to receive payments
        $data["payout_threshold"] = strval($payout_threshold); // minimum doge value to reach to then send the payment
        $data["payout_frequency"] = strval($payout_frequency); // wen do we want the payment to be sent        

        // Sends the GigaWallet Command
        return $this->sendGigaCommand($this->config["GigaServer"][0] . ":" . $this->config["GigaPort"][0] . $command, $method, $data);
    }

    // Gets a GigaWallet Account Balance
    public function accountBalance($foreign_id) {

        // Builds the Gigawallet Command
        $command = "/account/" . $foreign_id . "/balance";

        // Sends the GigaWallet Command
        return $this->sendGigaCommand($this->config["GigaServer"][0] . ":" . $this->config["GigaPort"][0] . $command, 'GET', NULL);
    }    

    // Creates a GigaWallet Invoice
    public function invoice($foreign_id,$data) {

        // Builds the Gigawallet Command
        $command = "/account/" . $foreign_id . "/invoice/";

        // Sends the GigaWallet Command
        return $this->sendGigaCommand($this->config["GigaServer"][0]. ":" . $this->config["GigaPort"][0] . $command, 'POST', $data);
    } 

    // Gets one GigaWallet Invoice
    public function GetInvoice($foreign_id,$invoice_id) {

        // Builds the Gigawallet Command
        $command = "/account/".$foreign_id."/invoice/" . $invoice_id . "";

        // Sends the GigaWallet Command
        return $this->sendGigaCommand($this->config["GigaServer"][0] . ":" . $this->config["GigaPort"][0] . $command, 'GET', NULL);
    }      

    // Gets all GigaWallet Invoices from that shibe
    public function GetInvoices($foreign_id,$data) {

        // Builds the Gigawallet Command
        $command = "/account/" . $foreign_id . "/invoices?cursor=".$data["cursor"]."&limit=".$data["limit"]."";
        $data = null;
        // Sends the GigaWallet Command
        return $this->sendGigaCommand($this->config["GigaServer"][0] . ":" . $this->config["GigaPort"][0] . $command, 'GET', $data);
    }      

    // Gets a GigaWallet QR code Invoice
    public function qr($invoice,$fg = "000000",$bg = "ffffff") {

        // Builds the Gigawallet Command
        $command = "/invoice/" . $invoice . "/qr.png?fg=".$fg."&bg=".$bg;

        // Sends the GigaWallet Command
        return  $this->sendGigaCommand($this->config["GigaServer"][1] . ":" . $this->config["GigaPort"][1] . $command, 'GET');
    } 
    
    // Pay to an address
    public function PayTo($foreign_id,$data) {

        // Builds the Gigawallet Command
        $command = "/account/" . $foreign_id . "/pay";
        
        // Deduct dust to the payment to be able to send it successfull because of network fees
        foreach ($data["pay"] as $key => $payment) {
            $data["pay"][$key]["amount"] = floatval($payment["amount"] - $this->config["GigaDust"]);
        }      

        // Sends the GigaWallet Command
        return $this->sendGigaCommand($this->config["GigaServer"][0] . ":" . $this->config["GigaPort"][0] . $command, 'POST', $data);
    }     

    public function sendGigaCommand($url, $method = 'GET', $data = array()) {
        $args = array(
            'headers'     => array('Content-Type' => 'application/json'),
            'method'      => $method,
        );

        if ($method == 'POST') {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception("GigaWallet Error: " . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            throw new Exception("GigaWallet Error: HTTP Response Code - $response_code");
        }

        return wp_remote_retrieve_body($response);
    }    

}    

?>