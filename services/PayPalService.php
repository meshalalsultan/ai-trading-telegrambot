<?php

require_once __DIR__ . '/../config.php';

class PayPalService
{
    public static function getAccessToken()
    {
        $mode = setting('paypal_mode', 'sandbox');

        if ($mode === 'live') {
            $clientId = setting('paypal_live_client_id');
            $secret = setting('paypal_live_client_secret');
            $baseUrl = 'https://api-m.paypal.com';
        } else {
            $clientId = setting('paypal_sandbox_client_id');
            $secret = setting('paypal_sandbox_client_secret');
            $baseUrl = 'https://api-m.sandbox.paypal.com';
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/v1/oauth2/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $clientId . ':' . $secret,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($response, true);

        return $json['access_token'] ?? null;
    }
}