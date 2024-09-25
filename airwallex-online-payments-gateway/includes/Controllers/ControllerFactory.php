<?php

namespace Airwallex\Controllers;

defined( 'ABSPATH' ) || exit();

class ControllerFactory {
    private static $quoteController;
    private static $orderController;
    private static $gatewaySettingsController;

    /**
     * Get QuoteController instance
     * 
     * @return QuoteController
     */
    public static function createQuoteController() {
        if (self::$quoteController) {
            return self::$quoteController;
        }
        self::$quoteController = new QuoteController();
        return self::$quoteController;
    }

    /**
     * Set QuoteController instance
     * 
     * @param QuoteController $quoteController
     */
    public static function setQuoteController($quoteController) {
        self::$quoteController = $quoteController;
    }

    /**
     * Get OrderController instance
     * 
     * @return OrderController
     */
    public static function createOrderController() {
        if (self::$orderController) {
            return self::$orderController;
        }
        self::$orderController = new OrderController();
        return self::$orderController;
    }

    /**
     * Set OrderController instance
     * 
     * @param OrderController $orderController
     */
    public static function setOrderController($orderController) {
        self::$orderController = $orderController;
    }
}
