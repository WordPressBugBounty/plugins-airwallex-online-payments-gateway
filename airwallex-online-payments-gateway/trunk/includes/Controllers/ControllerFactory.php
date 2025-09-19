<?php

namespace Airwallex\Controllers;

defined( 'ABSPATH' ) || exit();

class ControllerFactory {
    private static $quoteController;
    private static $orderController;
    private static $paymentConsentController;
    private static $paymentSessionController;
    private static $connectionFlowController;
    private static $airwallexController;
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

    /**
     * Get PaymentConsentController instance
     * 
     * @return PaymentConsentController
     */
    public static function createPaymentConsentController() {
        if (self::$paymentConsentController) {
            return self::$paymentConsentController;
        }
        self::$paymentConsentController = new PaymentConsentController();
        return self::$paymentConsentController;
    }

    /**
     * Set PaymentConsentController instance
     * 
     * @param PaymentConsentController $paymentConsentController
     */
    public static function setPaymentConsentController($paymentConsentController) {
        self::$paymentConsentController = $paymentConsentController;
    }

    /**
     * Get PaymentSessionController instance
     * 
     * @return PaymentSessionController
     */
    public static function createPaymentSessionController() {
        if (self::$paymentSessionController) {
            return self::$paymentSessionController;
        }
        self::$paymentSessionController = new PaymentSessionController();
        return self::$paymentSessionController;
    }

    /**
     * Set PaymentSessionController instance
     * 
     * @param PaymentSessionController $paymentSessionController
     */
    public static function setPaymentSessionController($paymentSessionController) {
        self::$paymentSessionController = $paymentSessionController;
    }

    /**
     * Get ConnectionFlowController instance
     * 
     * @return ConnectionFlowController
     */
    public static function createConnectionFlowController() {
        if (self::$connectionFlowController) {
            return self::$connectionFlowController;
        }
        self::$connectionFlowController = new ConnectionFlowController();
        return self::$connectionFlowController;
    }

    /**
     * Set ConnectionFlowController instance
     * 
     * @param ConnectionFlowController $connectionFlowController
     */
    public static function setConnectionFlowController($connectionFlowController) {
        self::$connectionFlowController = $connectionFlowController;
    }

    /**
     * Get AirwallexController instance
     * 
     * @return AirwallexController
     */
    public static function createAirwallexController() {
        if (self::$airwallexController) {
            return self::$airwallexController;
        }
        self::$airwallexController = new AirwallexController();
        return self::$airwallexController;
    }

    /**
     * Set AirwallexController instance
     * 
     * @param AirwallexController $airwallexController
     */
    public static function setAirwallexController($airwallexController) {
        self::$airwallexController = $airwallexController;
    }

    /**
     * Get GatewaySettingsController instance
     * 
     * @return GatewaySettingsController
     */
    public static function createGatewaySettingsController() {
        if (self::$gatewaySettingsController) {
            return self::$gatewaySettingsController;
        }
        self::$gatewaySettingsController = new GatewaySettingsController();
        return self::$gatewaySettingsController;
    }

    /**
     * Set GatewaySettingsController instance
     * 
     * @param GatewaySettingsController $gatewaySettingsController
     */
    public static function setGatewaySettingsController($gatewaySettingsController) {
        self::$gatewaySettingsController = $gatewaySettingsController;
    }
}
