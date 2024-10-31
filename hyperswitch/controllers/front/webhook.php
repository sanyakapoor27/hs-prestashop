<?php

require_once __DIR__.'/../../hyperswitch-payment.php'; 
require_once __DIR__.'/../../hyperswitch-webhook.php'; 

class HyperswitchWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $webhook = new HyperswitchWebhookHandler();

        try
        {
            $webhook->handleWebhook();
        }
        catch(Error $e)
        {
            $error = $e->getMessage();

            PrestaShopLogger::addLog("Error: ". $error, 4);

            exit;
        }
    }
}