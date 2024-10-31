<?php

class hyperswitch extends PaymentModule{
    
    //configuration keys
    const HYPERSWITCH_API_KEY = 'HYPERSWITCH_API_KEY';
    const HYPERSWITCH_PUBLISHABLE_KEY = 'HYPERSWITCH_PUBLISHABLE_KEY'; //for frontend
    const HYPERSWITCH_WEBHOOK_SECRET = 'HYPERSWITCH_WEBHOOK SECRET';
    const HYPERSWITCH_MERCHANT_ID = 'HYPERSWITCH_MERCHANT_ID';
    const HYPERSWITCH_TEST_MODE = 'HYPERSWITCH_TEST_MODE';
    const HYPERSWITCH_PAYMENT_METHODS = 'HYPERSWITCH_PAYMENT_METHODS';
    const HYPERSWITCH_WEBHOOK_WAIT_TIME = 'HYPERSWITCH_WEBHOOK_WAIT_TIME';
    

    private $_postErrors = [];
    private $_html = '';
    private $hyperswitchApiInstance = null;

    private $api_endpoints =[
        'test' => 'https://sandbox.hyperswitch.io',
        'live' => 'https://api.hyperswitch.io'
    ];

    public function __construct(){
        
        $this->name                   = 'hyperswitch';
        $this->displayName            = 'Hyperswitch';
        $this->tab                    = 'payments_gateways';
        $this->version                = '1.0.0';
        $this->author                 = 'Sanya Kapoor';
        $this->need_instance          = 1;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->display                = true;
        $this->bootstrap              = true;
        $this->controllers            = ['validation'];

        parent::__construct();

        $this->description            = $this->l('Accept payments through multiple providers using Hyperswitch.');
        $this->confirmUninstall       = $this->l('Are you sure you want to uninstall this module?');
    
    }

    public function install(){
        Configuration::updateValue('HYPERSWITCH_API_KEY', '');
        Configuration::updateValue('HYPERSWITCH_PUBLISHABLE_KEY', '');
        Configuration::updateValue('HYPERSWITCH_WEBHOOK_SECRET', '');
        Configuration::updateValue('HYPERSWITCH_MERCHANT_ID', '');
        Configuration::updateValue('HYPERSWITCH_TEST_MODE', '');
        Configuration::updateValue('HYPERSWITCH_PAYMENT_METHODS', '');
        Configuration::updateValue('HYPERSWITCH_WEBHOOK_WAIT_TIME', '');
        
        $db = \Db::getInstance();

        $result = $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'hyperswitch_transaction` (
            `id_transaction` int(11) NOT NULL AUTO_INCREMENT,
            `id_order` int(11) NOT NULL,
            `transaction_id` varchar(255) NOT NULL,
            `payment_intent_id` varchar(255) NOT NULL,
            `amount` decimal(20,6) NOT NULL,
            `currency` varchar(3) NOT NULL,
            `status` varchar(50) NOT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id_transaction`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');

        if (!parent::install()) {
            return false;
        }

        //register hooks
        if (!$this->registerHook('paymentOptions') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('orderConfirmation') ||
            !$this->registerHook('displayAdminOrderMainBottom') ||
            !$this->registerHook('actionOrderStatusUpdate') ||
            !$this->registerHook('displayPaymentTop')) {
            return false;
        }
        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('HYPERSWITCH_API_KEY');
        Configuration::deleteByName('HYPERSWITCH_PUBLISHABLE_KEY');
        Configuration::deleteByName('HYPERSWITCH_WEBHOOK_SECRET');
        Configuration::deleteByName('HYPERSWITCH_MERCHANT_ID');
        Configuration::deleteByName('HYPERSWITCH_TEST_MODE');
        Configuration::deleteByName('HYPERSWITCH_PAYMENT_METHODS');
        Configuration::deleteByName('HYPERSWITCH_WEBHOOK_WAIT_TIME');

        $db = \Db::getInstance();

        $result = $db->execute("DROP TABLE IF EXISTS `hyperswitch_transaction`");

        return parent::uninstall();
    }

    public function getContent(){
        $this->_html = '';

        if (Tools::isSubmit('submit' . $this->name))
        {
            $this->_postValidation();

            if (empty($this->_postErrors))
            {
                $this->_postProcess();
            }
            else
            {
                foreach ($this->_postErrors AS $err)
                {
                    $this->_html .= "<div class='alert error'>{$err}</div>";
                }
            }
        } else
        {
            $this->_html .= "<br />";
        }
        
        $this->_displayHyperswitch();
        $this->_html .=$this->_displayForm();

        return $this->_html;
    }

    private function _displayForm(){
    //default values
    $defaultValues = array(
        'HYPERSWITCH_API_KEY' => Configuration::get('HYPERSWITCH_API_KEY'),
        'HYPERSWITCH_PUBLISHABLE_KEY' => Configuration::get('HYPERSWITCH_PUBLISHABLE_KEY'),
        'HYPERSWITCH_WEBHOOK_SECRET' => Configuration::get('HYPERSWITCH_WEBHOOK_SECRET'),
        'HYPERSWITCH_TEST_MODE' => Configuration::get('HYPERSWITCH_TEST_MODE'),
        'HYPERSWITCH_MERCHANT_ID' => Configuration::get('HYPERSWITCH_MERCHANT_ID'),
        'HYPERSWITCH_PAYMENT_METHODS' => json_decode(Configuration::get('HYPERSWITCH_PAYMENT_METHODS'), true)
    );

    //generate webhook URL
    $webhookUrl = $this->context->link->getModuleLink(
        $this->name,
        'webhook',
        array(),
        true
    );

    //form fields
    $fields_form[0]['form'] = array(
        'legend' => array(
            'title' => $this->l('Hyperswitch Configuration'),
            'icon' => 'icon-cogs'
        ),
        'input' => array(
            array(
                'type' => 'switch',
                'label' => $this->l('Sandbox Mode'),
                'name' => 'HYPERSWITCH_TEST_MODE',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 'test',
                        'label' => $this->l('Yes')
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 'live',
                        'label' => $this->l('No')
                    )
                ),
                'desc' => $this->l('Use test mode for development and testing')
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API Key'),
                'name' => 'HYPERSWITCH_API_KEY',
                'required' => true,
                'desc' => $this->l('Your Hyperswitch API Key (Secret Key)')
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Publishable Key'),
                'name' => 'HYPERSWITCH_PUBLISHABLE_KEY',
                'required' => true,
                'desc' => $this->l('Your Hyperswitch Publishable Key')
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Merchant ID'),
                'name' => 'HYPERSWITCH_MERCHANT_ID',
                'required' => true,
                'desc' => $this->l('Your Hyperswitch Merchant ID')
            ),
            array(
                'type' => 'html',
                'name' => 'WEBHOOK_INFO',
                'html_content' => '<div class="alert alert-info">' .
                    $this->l('Webhook URL: ') . $webhookUrl .
                    '<br>' . $this->l('Use this URL in your Hyperswitch dashboard to configure webhooks') .
                    '</div>'
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Webhook Secret'),
                'name' => 'HYPERSWITCH_WEBHOOK_SECRET',
                'desc' => $this->l('Secret key for webhook verification')
            )
        ),
        'submit' => array(
            'title' => $this->l('Submit'),
            'class' => 'btn btn-default pull-right',
            'name'  => 'submit' . $this->name
        )
    );

    $helper = new HelperForm();
    
    //module, token and currentIndex
    $helper->module = $this;
    $helper->name_controller = $this->name;
    $helper->token = Tools::getAdminTokenLite('AdminModules');
    $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
    
    //language
    $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
    $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
    
    //title and toolbar
    $helper->title = $this->displayName;
    $helper->show_toolbar = true;
    $helper->toolbar_scroll = true;
    $helper->submit_action = 'submit' . $this->name;
    
    //loading current values
    $helper->fields_value = $defaultValues;
    
    return $helper->generateForm($fields_form);
    }

    public function hookHeader(){
        //**loads js for integration**

        //only loads on checkout and order confirmation pages
    if ($this->context->controller instanceof OrderController ||
    $this->context->controller instanceof OrderConfirmationController) {
    
    $testMode = (bool)Configuration::get('HYPERSWITCH_TEST_MODE');
    
    //adding hyperswitch.js
    Media::addJsDef(array(
        'hyperswitch_api_key' => Configuration::get('HYPERSWITCH_API_KEY'),
        'hyperswitch_public_key' => Configuration::get('HYPERSWITCH_PUBLISHABLE_KEY'),
        'hyperswitch_test_mode' => $testMode,
        'hyperswitch_module_dir' => $this->_path,
        'hyperswitch_merchant_id' => Configuration::get('HYPERSWITCH_MERCHANT_ID')
    ));

    //module's JavaScript and CSS
    $this->context->controller->registerJavascript('hyperswitch-local-script',$this->_path . 'views/js/hyperswitch.js');
    $this->context->controller->registerStylesheet('hyperswitch-style-script',$this->_path . 'views/css/hyperswitch.css');
    
        }
    }

    public function hookOrderConfirmation($params)
    {
        /** Handle order confirmation page **/
        $order = $params['order'];
    
        if (!$order || $order->module !== $this->name) {
            return;
        }
    
        $payments = $order->getOrderPayments();
        if (count($payments) < 1) {
            return;
        }
    
        $payment = $payments[0];
        $paymentId = $payment->transaction_id; // Hyperswitch payment_id
    
        try {
            // Get the Hyperswitch API instance
            $hyperswitchApi = $this->getHyperswitchApiInstance();
            
            // Prepare the update data
            $updateData = [
                'metadata' => [
                    'prestashop_order_id' => (string)$order->id,
                    'prestashop_cart_id' => (string)$order->id_cart,
                    'prestashop_reference' => $order->reference
                ]
            ];
    
            // Make API call to update payment
            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ];
    
            // Use array access for the API call
            $response = $hyperswitchApi['payments']['update']([
                'payment_id' => $paymentId,
                'data' => $updateData,
                'headers' => $headers
            ]);
    
            // Verify the update was successful
            if ($response && isset($response['status']) && $response['status'] === 'succeeded') {
                // Add success log
                PrestaShopLogger::addLog(
                    sprintf('Hyperswitch payment %s successfully updated with order details', $paymentId),
                    1, // Info level
                    null,
                    'Hyperswitch',
                    (int)$order->id,
                    true
                );
    
                // Return payment confirmation message
                return $this->displayConfirmation(
                    $this->l('Payment successful') . 
                    '<br>Payment ID: <code>' . $paymentId . '</code>' .
                    '<br>Status: ' . $response['status']
                );
            }
    
            throw new Exception('Payment update failed: Invalid response from Hyperswitch');
    
        } catch (Exception $e) {
            // Log the error
            PrestaShopLogger::addLog(
                sprintf('Hyperswitch payment update failed: %s', $e->getMessage()),
                3, // Error level
                null,
                'Hyperswitch',
                (int)$order->id,
                true
            );
    
            // Return basic confirmation without showing the error to customer
            return $this->displayConfirmation(
                $this->l('Payment successful') . 
                '<br>Payment ID: <code>' . $paymentId . '</code>'
            );
        }
    }

    private function _postValidation(){
        //**validate config form values*/

        if (Tools::isSubmit('submit' . $this->name)) {
            //validate API keys
            if (!Tools::getValue('HYPERSWITCH_API_KEY')) {
                $this->_postErrors[] = $this->l('API Key is required.');
            }

            //validate Merchant ID
            if (!Tools::getValue('HYPERSWITCH_MERCHANT_ID')) {
                $this->_postErrors[] = $this->l('Merchant ID is required.');
            }
            if (!Tools::getValue('HYPERSWITCH_PUBLISHABLE_KEY')) {
                $this->_postErrors[] = $this->l('Publishable Key is required.');
            }

            //validate API mode
            if (!in_array(
                Tools::getValue('HYPERSWITCH_TEST_MODE'),
                ['test', 'live']
            )) {
                $this->_postErrors[] = $this->l('Invalid API mode selected.');
            }

            //validate webhook URL
            $webhookUrl = $this->context->link->getModuleLink(
                $this->name,
                'webhook',
                [],
                true
            );
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                $this->_postErrors[] = $this->l('Invalid webhook URL configuration.');
            }
        }
    }
    
    private function _postProcess(){
        //**save config values and handle webhook setup*/

        if (Tools::isSubmit('submit' . $this->name)) {
            //save configuration
            Configuration::updateValue(
                'HYPERSWITCH_API_KEY',
                Tools::getValue('HYPERSWITCH_API_KEY')
            );
            Configuration::updateValue(
                'HYPERSWITCH_PUBLISHABLE_KEY',
                Tools::getValue('HYPERSWITCH_PUBLISHABLE_KEY')
            );
            Configuration::updateValue(
                'HYPERSWITCH_MERCHANT_ID',
                Tools::getValue('HYPERSWITCH_MERCHANT_ID')
            );
            Configuration::updateValue(
                'HYPERSWITCH_TEST_MODE',
                Tools::getValue('HYPERSWITCH_TEST_MODE')
            );

            $webhookWaitTime = Tools::getValue('HYPERSWITCH_WEBHOOK_WAIT_TIME') ? Tools::getValue('WEBHOOK_WAIT_TIME') : 300;
            Configuration::updateValue(
                'HYPERSWITCH_WEBHOOK_WAIT_TIME', 
                $webhookWaitTime);

            //generate and save webhook secret if not exists
            if (!Configuration::get('HYPERSWITCH_WEBHOOK_SECRET')) {
                $webhookSecret = $this->createWebhookSecret();
                Configuration::updateValue('HYPERSWITCH_WEBHOOK_SECRET', $webhookSecret);
            }

            //auto-enable webhook
            $this->autoEnableWebhook();
        }
        $this->_html .= $this->context->controller->confirmations[] = $this->l('Settings updated successfully');

    }

    protected function createWebhookSecret()
    {
        return bin2hex(random_bytes(20));
    }

    protected function autoEnableWebhook()
    {
        try {
            $webhookUrl = $this->context->link->getModuleLink(
                $this->name,
                'webhook',
                [],
                true
            );

            $webhookSecret = Configuration::get('HYPERSWITCH_WEBHOOK_SECRET') ? Configuration::get('HYPERSWITCH_WEBHOOK_SECRET'): $this->createWebhookSecret();

            $events = [
                'payment.succeeded',
                'payment.failed',
                'payment.cancelled',
                'refund.succeeded',
                'refund.failed'
            ];

            // Check if webhook already exists
            $existingWebhooks = $this->webhookAPI('GET', 'webhooks');
            foreach ($existingWebhooks['data'] as $webhook) {
                if ($webhook['url'] === $webhookUrl) {
                    // Update existing webhook
                    $response = $this->webhookAPI('POST', 'webhooks/' . $webhook['id'], [
                        'url' => $webhookUrl,
                        'events' => $events,
                        'active' => true,
                        'secret' => $webhookSecret
                    ]);
                    return ['success' => true, 'message' => 'Webhook updated successfully'];
                }
            }

            // Create new webhook
            $response = $this->webhookAPI('POST', 'webhooks', [
                'url' => $webhookUrl,
                'events' => $events,
                'active' => true,
                'secret' => $webhookSecret
            ]);

            PrestaShopLogger::addLog(
                'Hyperswitch webhook created successfully',
                1,
                null,
                'HyperswitchPayment',
                null
            );

            return ['success' => true, 'message' => 'Webhook created successfully'];

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Hyperswitch webhook setup failed: ' . $e->getMessage(),
                3,
                null,
                'HyperswitchPayment',
                null
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function hookPaymentOptions($params)
    {
        //**payment option added to checkout page */
        if (!$this->active) {
            return [];
        }

        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name)
               ->setCallToActionText($this->l('Pay with Hyperswitch'))
               ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
               ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/hyperswitch-logo.png'));

        
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
        ]);

        return [$option];
    }

    public function hookPaymentReturn($params)
    {
        //**display payment return content */
        if (!$this->active) {
            return '';
        }

        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order || $order->module !== $this->name) {
            return '';
        }

        $currentState = $order->getCurrentState();
        $successStates = [
            Configuration::get('PS_OS_PAYMENT'),
            Configuration::get('PS_OS_OUTOFSTOCK'),
            Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
        ];

        if (in_array($currentState, $successStates)) {
            $this->context->smarty->assign([
                'order' => $order,
                'status' => 'success',
                'shop_name' => $this->context->shop->name,
                'reference' => $order->reference,
                'contact_url' => $this->context->link->getPageLink('contact', true)
            ]);
        } else {
            $this->context->smarty->assign([
                'status' => 'failed',
                'shop_name' => $this->context->shop->name,
                'contact_url' => $this->context->link->getPageLink('contact', true)
            ]);
        }

        return $this->display(__FILE__, 'views/templates/hook/payment-return.tpl');
    }

    protected function webhookAPI($method, $endpoint, $data = null)
    {
        $apiKey = Configuration::get('HYPERSWITCH_API_KEY');
        $mode = Configuration::get('HYPERSWITCH_TEST_MODE');
        
        $baseUrl = $mode === 'live' 
            ? 'https://api.hyperswitch.io/'
            : 'https://sandbox.hyperswitch.io/';

        $url = $baseUrl . trim($endpoint, '/');

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new Exception('API Request failed: ' . $error);
        }

        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception(
                isset($responseData['error']) 
                    ? $responseData['error']['message'] 
                    : 'API request failed with status ' . $httpCode
            );
        }

        return $responseData;
    }

    private function _displayhyperswitch()
    {
        $this->_html .= "<img src='/modules/hyperswitch/views/img/hyperswitch_logo.jpg' style='float:left; margin-right: 15px; max-width: 50px; height: auto;' />";
        $this->_html .= "<div style='margin-left: 65px; margin-bottom: 20px;'>
            <p>Hyperswitch is a global payments orchestrator that connects with multiple payment processors.</p>
            <p>This module allows you to accept payments using Hyperswitch.</p>
            </div>";
    }

    protected function getHyperswitchApiInstance()
    {
        if ($this->hyperswitchApiInstance === null) {
            $apiKey = Configuration::get('HYPERSWITCH_API_KEY');
            $mode = Configuration::get('HYPERSWITCH_TEST_MODE');
            
            $baseUrl = $mode === 'live'
                ? 'https://api.hyperswitch.io/'
                : 'https://sandbox.hyperswitch.io/';

            $this->hyperswitchApiInstance = [
                'apiKey' => $apiKey,
                'baseUrl' => $baseUrl
            ];
        }

        return $this->hyperswitchApiInstance;
    }
}