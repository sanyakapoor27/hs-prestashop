<?php

require_once __DIR__.'/../../hyperswitch.php';
require_once __DIR__.'/../../hyperswitch-webhook.php';
class HyperswitchValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $api_key = Configuration::get('HYPERSWITCH_API_KEY');
        $merchant_id = Configuration::get('HYPERSWITCH_MERCHANT_ID');
        $webhook_secret = Configuration::get('HYPERSWITCH_WEBHOOK_SECRET');

        // Handle cart context
        if (isset($this->context->cart->id) === false && 
            is_numeric($_REQUEST['cart_id']) === true) 
        {
            $this->context->cart = new Cart($_REQUEST['cart_id']);
            $this->context->customer = new Customer($this->context->cart->id_customer);
        }

        $cart = $this->context->cart;

        // Basic cart validation
        if (($cart->id_customer === 0) || 
            ($cart->id_address_delivery === 0) || 
            ($cart->id_address_invoice === 0) || 
            (!$this->module->active))
        {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorized = false;

        // Check if payment method is still enabled
        foreach (Module::getPaymentModules() as $module)
        {
            if ($module['name'] == 'hyperswitch')
            {
                $authorized = true;
                break;
            }
        }

        if (!$authorized)
        {
            die($this->module->getTranslator()->trans('This payment method is not available.', array(), 'Modules.Hyperswitch.Shop'));
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer))
        {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (int) intval($cart->getOrderTotal(true, Cart::BOTH) * 100);


        try {
            $mode = Configuration::get('HYPERSWITCH_TEST_MODE');
            $baseUrl = $mode === 'live' 
            ? 'https://api.hyperswitch.io/payments'
            : 'https://sandbox.hyperswitch.io/payments';

            if (isset( $client_secret)){
				$payment_id = "";
				$parts = explode( "_secret", $client_secret );
				if (count($parts) === 2){
					$payment_id = $parts[0];
				}
				$baseUrl = $baseUrl . "/" . $payment_id;
			}

            $paymentData = json_encode([
                "amount" => $total,
                "currency" => $currency->iso_code,
                "description" => "Order payment",
                "return_url" => $this->context->link->getModuleLink($this->module->name, 'validation', [], true)
            ]);
    
            // Initialize cURL
            $ch = curl_init($baseUrl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'api-key: ' . $api_key
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $paymentData);
    
            // Execute the request and parse the response
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
                PrestaShopLogger::addLog("cURL Error: " . $curlError, 4);
                curl_close($ch);
                throw new Exception("cURL Error: " . $curlError);
            }
            
            curl_close($ch);

            if (empty($response)) {
                PrestaShopLogger::addLog("Empty response from Hyperswitch API.", 4);
                throw new Exception('Received empty response from Hyperswitch API.');
            }
            
            // Log or output the raw response to debug
            PrestaShopLogger::addLog("Raw cURL Response: " . $response, 1);
            
            $payment = json_decode($response, true);
            PrestaShopLogger::addLog("Raw cURL Response: " . print_r($response, true), 1); // Logging the raw string response
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonError = json_last_error_msg();
                PrestaShopLogger::addLog("JSON Decode Error: " . $jsonError, 4);
                throw new Exception('JSON Decode Error: ' . $jsonError);
            }
            
            // Log the decoded response for further analysis
            PrestaShopLogger::addLog("Decoded JSON Response: " . print_r($payment, true), 1);

            if ($payment === null) {
                throw new Exception('Invalid JSON response: ' . json_last_error_msg());
            }
            $clientSecret = isset( $payment['client_secret'] ) ? $payment['client_secret'] : null;
			$paymentId = isset( $payment['payment_id'] ) ? $payment['payment_id'] : null;
			$error = isset( $payment['error'] ) ? $payment['error'] : null;
    
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);
    
            if (!is_array($payment) || isset($payment['error'])) {
                PrestaShopLogger::addLog("Payment Error: " . (isset($payment['error']) ? print_r($payment['error'], true) : 'No error message'), 4);
                throw new Exception(isset($payment['error']) ? $payment['error']['message'] : 'Invalid payment response');
            }
            
    
            // Handle the next action (if a redirect is required for 3DS)
            if (isset($payment['next_action']['redirect_to_url'])) {
                Tools::redirect($payment['next_action']['redirect_to_url']);
            }    

            // Add payment to hyperswitch_transaction table
            $db = \Db::getInstance();
            $db->insert('hyperswitch_transaction', [
                'id_order' => (int)Order::getIdByCartId($cart->id),
                'transaction_id' => pSQL($paymentId),
                'amount' => (float)($payment['amount'] / 100),
                'currency' => pSQL($payment['currency']),
                'status' => pSQL($payment['status']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $paymentMethod = "hyperswitch." . ($payment['content']['object']['payment_method'] ?? 'default');

            $ret = $this->module->validateOrder(
                $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $cart->getOrderTotal(true, Cart::BOTH),
                $paymentMethod,
                'Payment by Hyperswitch using ' . ($payment['content']['object']['payment_method'] ?? 'default'),
                NULL,
                false,
                $customer->secure_key
            );

            PrestaShopLogger::addLog("Payment Successful for Order#".$cart->id.". Hyperswitch payment id: ".$paymentId, 1);

            // Redirect to order confirmation
            $query = http_build_query([
                'controller' => 'order-confirmation',
                'id_cart' => (int) $cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => Order::getIdByCartId($cart->id),
                'key' => $customer->secure_key,
            ], '', '&');

            Tools::redirect('index.php?' . $query);
        }
        catch(Exception $e)
        {
            $error = $e->getMessage();
            PrestaShopLogger::addLog("Payment Failed for Order# ".$cart->id.". Hyperswitch payment id: ".$paymentId. " Error: ". $error, 4);

            echo 'Error! Please contact the seller directly for assistance.</br>';
            echo 'Order Id: '.$cart->id.'</br>';
            echo 'Hyperswitch Payment Id: '.$paymentId.'</br>';
            echo 'Error: '.$error.'</br>';

            exit;
        }
    }
}