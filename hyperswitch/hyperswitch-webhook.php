<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../init.php');
require_once(dirname(__FILE__).'/hyperswitch.php');

class HyperswitchWebhookHandler
{
    private $hyperswitch;
    private $logger;
    
    public function __construct()
    {
        $this->hyperswitch = new Hyperswitch();
        $this->logger = new FileLogger(0);
        $this->logger->setFilename(_PS_ROOT_DIR_ . "/log/hyperswitch_webhook.log");
    }

    public function handleWebhook()
    {
        try {
            // Get the JSON payload
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);

            if (!$data) {
                throw new Exception('Invalid webhook payload');
            }

            // Verify webhook signature
            $this->verifyWebhookSignature($payload);

            // Process based on event type
            switch ($data['type']) {
                case 'payment_succeeded':
                    $this->handlePaymentSucceeded($data);
                    break;
                case 'payment_failed':
                    $this->handlePaymentFailed($data);
                    break;
                case 'refund_succeeded':
                    $this->handleRefundSucceeded($data);
                    break;
                default:
                    $this->logger->log("Unhandled webhook event type: " . $data['type']);
            }

            http_response_code(200);
            die('Webhook processed successfully');

        } catch (Exception $e) {
            $this->logger->log("Webhook Error: " . $e->getMessage());
            http_response_code(400);
            die($e->getMessage());
        }
    }

    private function verifyWebhookSignature($payload)
    {
        $signature = $_SERVER['HTTP_X_HYPERSWITCH_SIGNATURE'] ?? '';
        
        if (empty($signature)) {
            throw new Exception('No signature found in request');
        }

        $webhookSecret = Configuration::get('HYPERSWITCH_WEBHOOK_SECRET');
        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        if (!hash_equals($computedSignature, $signature)) {
            throw new Exception('Invalid signature');
        }
    }

    private function handlePaymentSucceeded($data)
    {
        $paymentIntentId = $data['data']['payment_intent']['id'];
        $orderId = $this->getOrderIdFromPaymentIntent($paymentIntentId);

        if (!$orderId) {
            throw new Exception('Order not found for payment intent: ' . $paymentIntentId);
        }

        $order = new Order($orderId);
        
        // Update order status to payment accepted
        $order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
        $this->logger->log("Payment succeeded for order " . $orderId);
    }

    private function handlePaymentFailed($data)
    {
        $paymentIntentId = $data['data']['payment_intent']['id'];
        $orderId = $this->getOrderIdFromPaymentIntent($paymentIntentId);

        if (!$orderId) {
            throw new Exception('Order not found for payment intent: ' . $paymentIntentId);
        }

        $order = new Order($orderId);
        
        // Update order status to payment error
        $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
        $this->logger->log("Payment failed for order " . $orderId);
    }

    private function handleRefundSucceeded($data)
    {
        $paymentIntentId = $data['data']['payment_intent']['id'];
        $orderId = $this->getOrderIdFromPaymentIntent($paymentIntentId);

        if (!$orderId) {
            throw new Exception('Order not found for payment intent: ' . $paymentIntentId);
        }

        $order = new Order($orderId);
        
        // Update order status to refunded
        $order->setCurrentState(Configuration::get('PS_OS_REFUND'));
        $this->logger->log("Refund succeeded for order " . $orderId);
    }

    private function getOrderIdFromPaymentIntent($paymentIntentId)
    {
        // Query the order from your database using the payment intent ID
        $sql = 'SELECT id_order FROM ' . _DB_PREFIX_ . 'hyperswitch_payment_intent 
                WHERE payment_intent_id = "' . pSQL($paymentIntentId) . '"';
        
        return Db::getInstance()->getValue($sql);
    }
}