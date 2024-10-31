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

        $paymentId = $_REQUEST['payment_id'];

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
        $total = (string) intval($cart->getOrderTotal(true, Cart::BOTH) * 100);


        try {
            // Hyperswitch API base URL
            $baseUrl = Configuration::get('HYPERSWITCH_TEST_MODE') ? 'https://sandbox.hyperswitch.io/' : 'https://api.hyperswitch.io/';
    
            // Build the payment data payload
            $paymentData = json_encode([
                "amount" => $total,
                "currency" => $currency->iso_code, // e.g., 'USD'
                "authentication_type" => "three_ds",
                "description" => "Order payment",
                "return_url" => $this->context->link->getModuleLink($this->module->name, 'validation', [], true)
            ]);
    
            // Initialize cURL
            $ch = curl_init($baseUrl . 'payments');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $paymentData);
    
            // Execute the request and parse the response
            $response = curl_exec($ch);
            $payment = json_decode($response, true);
    
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);
    
            if (!$payment || isset($payment['error'])) {
                throw new Exception(isset($payment['error']) ? $payment['error']['message'] : 'Invalid payment response');
            }
    
            // Handle the next action (if a redirect is required for 3DS)
            if (isset($payment['next_action']['redirect_to_url'])) {
                Tools::redirect($payment['next_action']['redirect_to_url']);
            }    

            // Verify payment signature
            try
            {
                session_start();
                $attributes = array(
                    'hyperswitch_payment_id' => $_REQUEST['payment_id'],
                    'hyperswitch_signature' => $_POST['hyperswitch_signature']
                );

                // Verify signature using webhook secret
                $expectedSignature = hash_hmac('sha256', 
                    $attributes['payment_id'] . '|' . $attributes['hyperswitch_payment_intent_id'],
                    $webhook_secret
                );

                if ($expectedSignature !== $attributes['hyperswitch_signature']) {
                    throw new Exception('Invalid payment signature');
                }
            }
            catch(Exception $e)
            {
                $error = $e->getMessage();
                PrestaShopLogger::addLog("Payment Failed for Order# ".$cart->id.". Hyperswitch payment id: ".$paymentId. " Error: ". $error, 4);

                echo 'Error! Please contact the seller directly for assistance.</br>';
                echo 'Order Id: '.$cart->id.'</br>';
                echo 'Hyperswitch Payment Id: '.$paymentId.'</br>';
                echo 'Error: '.$e->getMessage().'</br>';

                exit;
            }

            // Add payment to hyperswitch_transaction table
            $db = \Db::getInstance();
            $db->insert('hyperswitch_transaction', [
                'id_order' => (int)Order::getIdByCartId($cart->id),
                'transaction_id' => pSQL($paymentId),
                'payment_intent_id' => pSQL($attributes['hyperswitch_payment_intent_id']),
                'amount' => (float)($payment['amount'] / 100),
                'currency' => pSQL($payment['currency']),
                'status' => pSQL($payment['status']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Validate the order
            $extraData = array(
                'transaction_id' => $paymentId,
                'payment_intent_id' => $attributes['hyperswitch_payment_intent_id']
            );

            $paymentMethod = "hyperswitch." . ($payment['payment_method'] ?? 'default');

            $ret = $this->module->validateOrder(
                $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $cart->getOrderTotal(true, Cart::BOTH),
                $paymentMethod,
                'Payment by Hyperswitch using ' . ($payment['payment_method'] ?? 'default'),
                $extraData,
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