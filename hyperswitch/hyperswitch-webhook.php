<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../init.php');
require_once(dirname(__FILE__).'/hyperswitch.php');

class HyperswitchWebhookHandler
{
    private $hyperswitch;
    private $logger;

    const PAYMENT_SUCCEEDED = 'payment_succeeded';
	const PAYMENT_FAILED = 'payment_failed';
	const PAYMENT_PROCESSING = 'payment_processing';
	const ACTION_REQURIED = 'action_required';
	const REFUND_SUCCEEDED = 'refund_succeeded';

    protected $eventsArray = [ 
		self::PAYMENT_SUCCEEDED,
		self::PAYMENT_FAILED,
		self::PAYMENT_PROCESSING,
		self::ACTION_REQURIED,
		self::REFUND_SUCCEEDED,
		// self::REFUND_FAILED,
		// self::DISPUTE_OPENED,
		// self::DISPUTE_EXPIRED,
		// self::DISPUTE_ACCEPTED,
		// self::DISPUTE_CANCELLED,
		// self::DISPUTE_CHALLENGED,
		// self::DISPUTE_WON,
		// self::DISPUTE_LOST
	];
    
    public function __construct()
    {
        $this->hyperswitch = new Hyperswitch();
        $this->logger = new FileLogger(0);
        $this->logger->setFilename(_PS_ROOT_DIR_ . "/log/hyperswitch_webhook.log");
    }

    public function handleWebhook()
    {
        try {
            //get the JSON payload
            $payload = file_get_contents('php://input');
            if ( ! empty ( $payload ) ) {
				$data = json_decode( $payload, true );

				$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_512'];

                if (json_last_error() !== 0){
                    return;
                }
        
                $enabled = Configuration::get('HYPERSWITCH_ENABLE_WEBHOOK');

            if (($enabled === 'on' ) and (empty($data['event_type']) === false)){
                $payment_id = $data['content']['object']['payment_id'];

                if ($this->verifyWebhookSignature($data, $signature, $payload) === false){
                    return;
                }
                switch ($data['event_type']) {
                    case self::PAYMENT_SUCCEEDED:
                $this->handlePaymentSucceeded($data);
                        break;
                    case self::PAYMENT_FAILED:
                        $this->handlePaymentFailed($data);
                        break;
                    case self::REFUND_SUCCEEDED:
                        $this->handleRefundSucceeded($data);
                        break;
                    default:
                        $this->logger->log("Unhandled webhook event type: " . $data['type']);
                }
            }
        }
            http_response_code(200);
            die('Webhook processed successfully');

        } catch (Exception $e) {
            $this->logger->log("Webhook Error: " . $e->getMessage());
            http_response_code(400);
            die($e->getMessage());
        }
    }

    private function verifyWebhookSignature($data, $signature, $payload)
    {
        if (empty($signature)) {
            throw new Exception('No signature found in request');
        }

        $webhookSecret = Configuration::get('HYPERSWITCH_WEBHOOK_SECRET');
        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        $signature_verification_result = $computedSignature === $signature;

		if (
			(isset($data['event_type'])) and
			(in_array( $data['event_type'], $this->eventsArray) && $signature_verification_result )
		) {
			return true;
		}

		return false;
    
    }
    private function handlePaymentSucceeded($data)
    {
        $payment_id = $data['content']['object']['payment_id'];
        $payment_method = $data['content']['object']['payment_method'];
        $order_id = null;
        if ( isset ( $data['content']['object']['metadata']['order_num'] ) ) {
			$order_id = $data['content']['object']['metadata']['order_num'];
		} else {
			$payment_intent = $this->hyperswitch->getPaymentIntent( $payment_id );
			$order_id = $payment_intent['metadata']['order_num'];
		}

        if (!$order_id) {
            throw new Exception('Order not found for payment id: ' . $payment_id);
        }

        $order = new Order($order_id);
        
        // Update order status to payment accepted
        $order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
        $this->logger->log("Payment succeeded for order " . $order_id);
    }

    private function handlePaymentFailed($data)
    {
        $payment_id = $data['content']['object']['payment_id'];
        $order_id = null;
        if ( isset ( $data['content']['object']['metadata']['order_num'] ) ) {
			$order_id = $data['content']['object']['metadata']['order_num'];
		} else {
			$payment_intent = $this->hyperswitch->getPaymentIntent( $payment_id );
			$order_id = $payment_intent['metadata']['order_num'];
		}
        if (!$order_id) {
            throw new Exception('Order not found for payment intent: ' . $payment_id);
        }

        $order = new Order($order_id);
        
        // Update order status to payment error
        $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
        $this->logger->log("Payment failed for order " . $order_id);
    }

    private function handleRefundSucceeded($data)
    {
        $payment_id = $data['content']['object']['payment_id'];
        $order_id = null;
        if ( isset ( $data['content']['object']['metadata']['order_num'] ) ) {
			$order_id = $data['content']['object']['metadata']['order_num'];
		} else {
			$payment_intent = $this->hyperswitch->getPaymentIntent( $payment_id );
			$order_id = $payment_intent['metadata']['order_num'];
		}

        if ($order_id=null) {
            throw new Exception('Order not found for payment intent: ' . $payment_id);
        }

        $order = new Order($order_id);
        
        // Update order status to refunded
        $order->setCurrentState(Configuration::get('PS_OS_REFUND'));
        $this->logger->log("Refund succeeded for order " . $order_id);
    }
}
