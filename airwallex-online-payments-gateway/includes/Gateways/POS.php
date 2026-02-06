<?php

namespace Airwallex\Gateways;

use Airwallex\Client\CardClient;
use Airwallex\PayappsPlugin\CommonLibrary\Exception\RequestException;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Terminal\GetList as GetTerminalList;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Terminal\ProcessPaymentIntent as ProcessPaymentIntentInTerminal;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Terminal as StructTerminal;
use Airwallex\Struct\PaymentIntent;
use WC_AJAX;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Terminal\Retrieve as RetrieveTerminal;
use Airwallex\Services\Util;

defined('ABSPATH') || exit();

class POS extends AirwallexGatewayLocalPaymentMethod {
    use AirwallexGatewayTrait;

    const GATEWAY_ID = 'pos';
    const ROUTE_SLUG = 'airwallex_pos';
    const PAYMENT_METHOD_TYPE_NAME = 'pos';

    protected static $instance = null;

    public function __construct() {
        $this->id = 'airwallex_' . self::GATEWAY_ID;
        $this->paymentMethodType = self::GATEWAY_ID;
        $this->paymentMethodName = 'POS';
        $this->method_title = __('Airwallex - POS', 'airwallex-online-payments-gateway');
        $this->method_description = __('Accept POS payments with your Airwallex account', 'airwallex-online-payments-gateway');
        $this->supports = ['products', 'refunds'];
        $this->tabTitle = __('POS', 'airwallex-online-payments-gateway');

        parent::__construct();
    }

    public function enqueueAdminScripts() {
        $this->enqueueAdminSettingsScripts();
        wp_add_inline_script(
            'airwallex-admin-settings',
            'var awxAdminPOSSettings = ' . wp_json_encode($this->getSettingsScriptData()),
            'before'
        );
    }

    public function getSettingsScriptData() {
        return [
            'nonce' => [
                'getPOSTerminals' => wp_create_nonce('wc-airwallex-get-pos-terminals'),
            ],
            'ajaxUrl' => [
                'getPOSTerminals' => WC_AJAX::get_endpoint('airwallex_get_pos_terminals'),
            ],
            'boundTerminal' => $this->getBoundTerminal(),
            'trans' => [
                'id' => __("ID", "airwallex-online-payments-gateway"),
                'nickName' => __("Nick Name", "airwallex-online-payments-gateway"),
                'serialNumber' => __("Serial Number", "airwallex-online-payments-gateway"),
                'boundDevice' => __("Currently bound device:", "airwallex-online-payments-gateway"),
                'prev' => __("Prev", "airwallex-online-payments-gateway"),
                'next' => __("Next", "airwallex-online-payments-gateway"),
                'noDevice' => __("No POS devices available", "airwallex-online-payments-gateway"),
            ]
        ];
    }

    public function getBoundTerminal() {
        $terminalId = $this->get_option('bind_' . Util::getEnvironment() . '_pos_device');
        if (empty($terminalId)) return [];
        try {
            /** @var StructTerminal $terminal */
            $terminal = (new RetrieveTerminal())->setTerminalId($terminalId)->send();
            return $this->getTerminalData($terminal);
        } catch (Exception $e) {
            LogService::getInstance()->error(__METHOD__, $e->getMessage());
            return [];
        }
    }

    public function isAvailable() {
        return empty($this->get_option('bind_' . Util::getEnvironment() . '_pos_device')) ? 'no' : 'yes';
    }

    public function getTerminalData($terminal) {
        return [
            'id' => $terminal->getId(),
            'nick_name' => $terminal->getNickname(),
            'serial_number' => $terminal->getSerialNumber(),
            'model' => $terminal->getModel(),
        ];
    }

    public function getPOSTerminals() {
        check_ajax_referer('wc-airwallex-get-pos-terminals', 'security');
        if (empty(WC()->session)) {
            wp_send_json_success(array('data' => []));
        }
        $page = isset($_GET['page']) ? wc_clean(wp_unslash($_GET['page'])) : '';
        try {
            $terminals = (new GetTerminalList())->setPage($page)->setPageSize(5)->setStatus(StructTerminal::STATUS_ACTIVE)->send();
        } catch (Exception $e) {
            LogService::getInstance()->error(__METHOD__, $e->getMessage());
            wp_send_json_success(array('data' => []));
        }
        $data = [];

        /** @var StructTerminal $terminal */
        foreach ($terminals->getItems() as $terminal) {
            $data[] = $this->getTerminalData($terminal);
        }
        wp_send_json_success(array('data' => $data, 'page_after' => $terminals->getPageAfter(), 'page_before' => $terminals->getPageBefore()));
    }

    public function get_form_fields() {
        return apply_filters( // phpcs:ignore
            'wc_airwallex_settings', // phpcs:ignore
            [
                'enabled' => array(
                    'title' => __('Enable/Disable', 'airwallex-online-payments-gateway'),
                    'label' => __('Enable Airwallex POS', 'airwallex-online-payments-gateway'),
                    'type' => 'check_is_enabled',
                    'description' => '',
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'airwallex-online-payments-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'airwallex-online-payments-gateway'),
                    'default' => __('POS', 'airwallex-online-payments-gateway'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'airwallex-online-payments-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the description which the user sees during checkout.', 'airwallex-online-payments-gateway'),
                    'default' => __('Pay with POS', 'airwallex-online-payments-gateway'),
                    'desc_tip' => true,
                ),
                'bind_' . Util::getEnvironment() . '_pos_device' => array(
                    'title' => __('Bind POS device', 'airwallex-online-payments-gateway'),
                    'label' => '',
                    'type' => 'pos_device_selector',
                    'description' => '',
                    'default' => get_option('bind_' . Util::getEnvironment() . '_pos_device') ?: '',
                ),
            ]
        );
    }

    public function getLPMMethodScriptData($data) {
        $data['paymentMethods'][] = $this->id;
        $data['paymentMethodNames']['POS'] = __("POS", 'airwallex-online-payments-gateway');

        return $data;
    }

    public function getPaymentMethod($order, $paymentIntentId) {
        $billing = $this->getBillingDetail($order);
        $countryCode = isset($billing['address']['country_code']) ? $billing['address']['country_code'] : '';

        return [
            'type' => 'pos',
            'pos' => [
                'billing' => $billing,
                'country_code' => $countryCode,
                'flow' => 'webqr',
                'intent_id' => $paymentIntentId,
                'shopper_email' => isset($billing['email']) ? $billing['email'] : '',
                'shopper_name' => $order->get_formatted_billing_full_name(),
                'shopper_phone' => isset($billing['phone_number']) ? $billing['phone_number'] : '',
            ],
        ];
    }

    public function getPaymentMethodOptions() {
        return [
            'POS' => [
                'auto_capture' => true,
            ]
        ];
    }

    public function payment_fields() {
        echo sprintf(
            '<p style="display: flex; align-items: center;"><span>%s</span><span class="wc-airwallex-loader"></span></p>',
            wp_kses_post($this->description)
        );
    }


    public function process_payment($order_id) {
        try {
            $order = wc_get_order($order_id);
            if (empty($order)) {
                LogService::getInstance()->debug(__METHOD__ . ' - can not find order', array('orderId' => $order_id));
                throw new Exception('Can not find order');
            }

            $terminalId = $this->get_option('bind_' . Util::getEnvironment() . '_pos_device');
            if (empty($terminalId)) {
                throw new Exception('POS device id is required');
            }

            LogService::getInstance()->debug(__METHOD__ . ' - before create intent', array('orderId' => $order_id));
            $paymentIntent = CardClient::getInstance()->createPaymentIntent($order->get_total(), $order->get_id(), $this->is_submit_order_details(), null, self::PAYMENT_METHOD_TYPE_NAME);
            if (in_array($paymentIntent->getStatus(), PaymentIntent::SUCCESS_STATUSES, true)) {
                return [
                    'result' => 'success',
                    'redirect' => $order->get_checkout_order_received_url(),
                ];
            }
            WC()->session->set('airwallex_order', $order_id);
            WC()->session->set('airwallex_payment_intent_id', $paymentIntent->getId());
            $order->update_meta_data(OrderService::META_KEY_INTENT_ID, $paymentIntent->getId());
            $order->save();

            (new ProcessPaymentIntentInTerminal())->setTerminalId($terminalId)->setPaymentIntentId($paymentIntent->getId())->send();

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_order_received_url(),
            ];
        } catch (RequestException $e) {
            OrderService::getInstance()->setTemporaryOrderStateAfterDecline($order);
            LogService::getInstance()->error($e->getMessage(), __METHOD__);
            $error = json_decode($e->getMessage(), true);
            if (is_array($error) && isset($error['code'])) {
                if ($error['code'] === ProcessPaymentIntentInTerminal::ERROR_TERMINAL_UNAVAILABLE) {
                    throw new Exception(__('The terminal is temporarily unavailable. Try again later or take action directly on the terminal.', 'airwallex-online-payments-gateway'));
                }
                if ($error['code'] === ProcessPaymentIntentInTerminal::ERROR_TERMINAL_BUSY) {
                    throw new Exception(__('The terminal is busy. Please wait a moment and try again.', 'airwallex-online-payments-gateway'));
                }
            }
            throw new Exception($e->getMessage());
        } catch (Exception $e) {
            OrderService::getInstance()->setTemporaryOrderStateAfterDecline($order);
            LogService::getInstance()->error(__METHOD__ . ' - process a payment in terminal.', $e->getMessage());
            throw new Exception(__($e->getMessage(), 'airwallex-online-payments-gateway'));
        }
    }

    public function generate_pos_device_selector_html($key, $data) {
        $fieldKey = $this->get_field_key($key);
        $value = $this->get_option($key);

        ob_start();
        include AIRWALLEX_PLUGIN_PATH . 'html/admin/POS-selector.php';
        return ob_get_clean();
    }
}
