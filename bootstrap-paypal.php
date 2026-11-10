<?php
// bootstrap-paypal.php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

$paypalClient = PaypalServerSdkClientBuilder::init()
    ->clientCredentialsAuthCredentials(
        ClientCredentialsAuthCredentialsBuilder::init(
            PAYPAL_CLIENT_ID,
            PAYPAL_CLIENT_SECRET
        )
    )
    ->environment(
        PAYPAL_MODE === 'sandbox'
            ? Environment::SANDBOX
            : Environment::PRODUCTION
    )
    ->build();
