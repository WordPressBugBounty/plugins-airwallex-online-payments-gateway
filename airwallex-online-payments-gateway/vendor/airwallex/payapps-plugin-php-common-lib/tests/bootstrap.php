<?php

use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;
use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init;
use Airwallex\PayappsPlugin\CommonLibrary\tests\mock\Cache;

require_once __DIR__ . '/../vendor/autoload.php';

if (!getenv('IS_FROM_GITLAB')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

Init::getInstance([
    'env' => 'demo',
    'client_id' => $_ENV['CLIENT_ID'] ?? getenv('CLIENT_ID'),
    'api_key' => $_ENV['API_KEY'] ?? getenv('API_KEY'),
    'plugin_type' => $_ENV['PLUGIN_TYPE'] ?? 'woo_commerce',
    'plugin_version' => $_ENV['PLUGIN_VERSION'] ?? '1.0.0',
    'platform_version' => $_ENV['PLATFORM_VERSION'] ?? '1.0.0',
]);
CacheManager::setInstance(new Cache());
