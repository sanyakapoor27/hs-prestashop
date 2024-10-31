<?php

require_once __DIR__.'/../../hyperswitch.php';

class HyperswitchOrderModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Content-Type: application/json');
            header('Status:', true, 400);
            exit;
        }

        // Verify and auto-enable webhook if needed
        $webhookLastVerified = Configuration::get('HYPERSWITCH_WEBHOOK_LAST_VERIFY');
        
        if (empty($webhookLastVerified) === true)
        {
            (new Hyperswitch())->autoEnableWebhook();
        }
        elseif ($webhookLastVerified + 86400 < time()) // Check if last verification was more than 24 hours ago
        {
            (new Hyperswitch())->autoEnableWebhook();
        }

        // Get amount in smallest currency unit
        $amount = number_format(($this->context->cart->getOrderTotal() * 100), 0, "", "");

        $code = 400;
        $payment_intent_id = "";

        try
        {
            // Create payment data array
            $paymentData = [
                'amount' => $amount,
                'currency' => $this->context->currency->iso_code,
                'customer' => [
                    'id' => $this->context->customer->id,
                    'email' => $this->context->customer->email,
                    'name' => $this->context->customer->firstname . ' ' . $this->context->customer->lastname
                ],
                'metadata' => [
                    'cart_id' => (string)$this->context->cart->id,
                    'customer_id' => (string)$this->context->customer->id
                ],
                'description' => 'Order for Cart #' . $this->context->cart->id,
                'return_url' => $this->context->link->getModuleLink(
                    'hyperswitch',
                    'validation',
                    [
                        'cart_id' => $this->context->cart->id,
                        'secure_key' => $this->context->customer->secure_key
                    ],
                    true
                )
            ];

            // Create payment intent using Hyperswitch API
            $payment = $this->createPaymentIntent($paymentData);

            $responseContent = [
                'message' => 'Unable to create your payment. Please contact support.',
                'parameters' => []
            ];

            if (null !== $payment && !empty($payment['id']))
            {
                $responseContent = [
                    'success' => true,
                    'payment_intent_id' => $payment['id'],
                    'client_secret' => $payment['client_secret'],
                    'amount' => $amount
                ];

                $code = 200;

                // Store payment intent ID in session
                session_start();
                $_SESSION['hyperswitch_payment_intent'] = $payment['id'];

                // Save to database
                $db = \Db::getInstance();

                // Check if entry exists
                $request = "SELECT `id_transaction` FROM `" . _DB_PREFIX_ . "hyperswitch_transaction` 
                           WHERE `id_cart` = " . (int)$this->context->cart->id;

                $transactionId = $db->getValue($request);

                if(empty($transactionId))
                {
                    // Insert new record
                    $result = $db->insert('hyperswitch_transaction', [
                        'id_cart' => (int)$this->context->cart->id,
                        'payment_intent_id' => pSQL($payment['id']),
                        'amount' => (float)($amount/100), // Convert back to base unit
                        'currency' => pSQL($this->context->currency->iso_code),
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                    PrestaShopLogger::addLog(
                        "New record inserted in hyperswitch_transaction table for cart_id: " . 
                        $this->context->cart->id, 
                        1
                    );
                }
                else
                {
                    // Update existing record
                    $result = $db->update('hyperswitch_transaction', 
                        [
                            'payment_intent_id' => pSQL($payment['id']),
                            'amount' => (float)($amount/100),
                            'currency' => pSQL($this->context->currency->iso_code),
                            'status' => 'pending',
                            'updated_at' => date('Y-m-d H:i:s')
                        ],
                        'id_transaction = ' . (int)$transactionId
                    );

                    PrestaShopLogger::addLog(
                        "Record updated in hyperswitch_transaction table for id: " . $transactionId, 
                        1
                    );
                }
            }
        }
        catch(Exception $e)
        {
            $responseContent = [
                'message' => $e->getMessage(),
                'parameters' => []
            ];

            PrestaShopLogger::addLog(
                "Payment creation failed with error: " . $e->getMessage(),
                3
            );
        }

        header('Content-Type: application/json', true, $code);
        echo json_encode($responseContent);
        exit;
    }

    private function createPaymentIntent($paymentData)
    {
        $apiKey = Configuration::get('HYPERSWITCH_API_KEY');
        $mode = Configuration::get('HYPERSWITCH_TEST_MODE') ? 'test' : 'live';
        
        $baseUrl = $mode === 'live' 
            ? 'https://api.hyperswitch.io'
            : 'https://sandbox.hyperswitch.io';

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init($baseUrl . '/payments');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception('Payment API request failed: ' . curl_error($ch));
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Payment API request failed with status ' . $httpCode);
        }

        return json_decode($response, true);
    }
}