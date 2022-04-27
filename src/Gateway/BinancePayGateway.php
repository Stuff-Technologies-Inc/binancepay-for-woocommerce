<?php

declare( strict_types=1 );

namespace BinancePay\WC\Gateway;

use BinancePay\WC\Client\BinanceOrder;
use BinancePay\WC\Helper\BinanceApiHelper;
use BinancePay\WC\Helper\GreenfieldApiWebhook;
use BinancePay\WC\Helper\Logger;
use BinancePay\WC\Helper\OrderStates;
use BinancePay\WC\Helper\PreciseNumber;

class BinancePayGateway extends \WC_Payment_Gateway {
	protected $apiClient;

	public function __construct() {
		// General gateway setup.
		$this->id                 = 'binancepay';
		//$this->icon              = $this->getIcon();
		$this->has_fields        = false;
		$this->order_button_text = __( 'Place order', 'binancepay-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user facing set variables.
		$this->title        = $this->get_option('title', 'BinancePay');
		$this->description  = $this->get_option('description', 'You will be redirected to BinancePay to complete your purchase.');

		// Admin facing title and description.
		$this->method_title       = 'BinancePay';
		$this->method_description = __('BinancePay gateway supporting all available cryptocurrencies.', 'binancepay-for-woocommerce');

		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = BINANCEPAY_VERSION;

		// Actions.
		add_action('woocommerce_api_binancepay', [$this, 'processWebhook']);
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'       => __( 'Enabled/Disabled', 'binancepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable this payment gateway.', 'binancepay-for-woocommerce' ),
				'default'     => 'no',
				'value'       => 'yes',
				'desc_tip'    => false,
			],
			'url'       => [
				'title'       => __( 'BinancePay API URL', 'binancepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Sandbox or production API endpoint url.', 'binancepay-for-woocommerce' ),
				'default'     => 'https://bpay.binanceapi.com',
				'desc_tip'    => true,
			],
			'apikey'       => [
				'title'       => __( 'API Key (Merchant)', 'binancepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Provide the merchant API key from your BinancePay merchant account.', 'binancepay-for-woocommerce' ),
				'default'     => null,
				'desc_tip'    => true,
			],
			'apisecret'       => [
				'title'       => __( 'API Secret', 'binancepay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Provide the merchant API secret from your BinancePay merchant account.', 'binancepay-for-woocommerce' ),
				'default'     => null,
				'desc_tip'    => true,
			],
			'title'       => [
				'title'       => __( 'Customer Text', 'binancepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Controls the name of this payment method as displayed to the customer during checkout.', 'binancepay-for-woocommerce' ),
				'default'     => $this->title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Customer Message', 'binancepay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'binancepay-for-woocommerce' ),
				'default'     => $this->description,
				'desc_tip'    => true,
			]
		];
	}

	public function process_admin_options() {
		parent::process_admin_options();

		// todo fetch signature and store it.
		//$this->update_option('');
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment( $orderId ) {
		/*
		if ( ! $this->apiHelper->configured ) {
			Logger::debug( 'BinancePay Server API connection not configured, aborting. Please go to BinancePay Server settings and set it up.' );
			// todo: show error notice/make sure it fails
			throw new \Exception( __( "Can't process order. Please contact us if the problem persists.", 'binancepay-for-woocommerce' ) );
		}
		*/

		// Load the order and check it.
		$order = new \WC_Order( $orderId );
		if ( $order->get_id() === 0 ) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug( $message, true );
			throw new \Exception( $message );
		}

		// Check for existing invoice and redirect instead.
		/*
		if ( $this->validInvoiceExists( $orderId ) ) {
			$existingInvoiceId = get_post_meta( $orderId, 'BinancePay_id', true );
			Logger::debug( 'Found existing BinancePay Server invoice and redirecting to it. Invoice id: ' . $existingInvoiceId );

			return [
				'result'   => 'success',
				'redirect' => $this->apiHelper->getInvoiceRedirectUrl( $existingInvoiceId ),
			];
		}
		*/

		// Create an invoice.
		Logger::debug( 'Creating Order on BinancePay.' );
		if ( $binanceOrder = $this->createBinanceOrder( $order ) ) {

			// Todo: update order status and BinancePay meta data.

			Logger::debug( 'Binance order creation successful, redirecting user.' );

			Logger::debug($binanceOrder, true);

			return [
				'result'   => 'success',
				'redirect' => $binanceOrder['data']['checkoutUrl'],
				'orderId' => $order->get_id(),
			];
		}
	}

	public function getId(): string {
		return $this->id;
	}

	/**
	 * Get custom gateway icon, if any.
	 */
	public function getIcon(): string {
		return BINANCEPAY_PLUGIN_URL . 'assets/images/binancepay-logo.svg';
	}

	/**
	 * Process webhooks from BinancePay.
	 */
	public function processWebhook() {
		// todo binancepay: this is currently not needed
		if ($rawPostData = file_get_contents("php://input")) {
			// Validate webhook request.
			// Note: getallheaders() CamelCases all headers for PHP-FPM/Nginx but for others maybe not, so "BinancePay-Sig" may becomes "Btcpay-Sig".
			$headers = getallheaders();
			foreach ($headers as $key => $value) {
				if (strtolower($key) === 'binancepay-sig') {
					$signature = $value;
				}
			}

			if (!isset($signature) || !$this->apiHelper->validWebhookRequest($signature, $rawPostData)) {
				Logger::debug('Failed to validate signature of webhook request.');
				wp_die('Webhook request validation failed.');
			}

			try {
				$postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

				if (!isset($postData->invoiceId)) {
					Logger::debug('No BinancePay invoiceId provided, aborting.');
					wp_die('No BinancePay invoiceId provided, aborting.');
				}

				// Load the order by metadata field BinancePay_id
				$orders = wc_get_orders([
					'meta_key' => 'BinancePay_id',
					'meta_value' => $postData->invoiceId
				]);

				// Abort if no orders found.
				if (count($orders) === 0) {
					Logger::debug('Could not load order by BinancePay invoiceId: ' . $postData->invoiceId);
					wp_die('No order found for this invoiceId.', '', ['response' => 404]);
				}

				// TODO: Handle multiple matching orders.
				if (count($orders) > 1) {
					Logger::debug('Found multiple orders for invoiceId: ' . $postData->invoiceId);
					Logger::debug(print_r($orders, true));
					wp_die('Multiple orders found for this invoiceId, aborting.');
				}

				$this->processOrderStatus($orders[0], $postData);

			} catch (\Throwable $e) {
				Logger::debug('Error decoding webook payload: ' . $e->getMessage());
				Logger::debug($rawPostData);
			}
		}
	}

	protected function processOrderStatus(\WC_Order $order, \stdClass $webhookData) {
		if (!in_array($webhookData->type, GreenfieldApiWebhook::WEBHOOK_EVENTS)) {
			Logger::debug('Webhook event received but ignored: ' . $webhookData->type);
			return;
		}

		Logger::debug('Updating order status with webhook event received for processing: ' . $webhookData->type);
		// Get configured order states or fall back to defaults.
		if (!$configuredOrderStates = get_option('binancepay_order_states')) {
			$configuredOrderStates = (new OrderStates())->getDefaultOrderStateMappings();
		}

		switch ($webhookData->type) {
			case 'InvoiceReceivedPayment':
				if ($webhookData->afterExpiration) {
					if ($order->get_status() === $configuredOrderStates[OrderStates::EXPIRED]) {
						$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
						$order->add_order_note(__('Invoice payment received after invoice was already expired.', 'binancepay-for-woocommerce'));
					}
				} else {
					// No need to change order status here, only leave a note.
					$order->add_order_note(__('Invoice (partial) payment received. Waiting for full payment.', 'binancepay-for-woocommerce'));
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
			case 'InvoiceProcessing': // The invoice is paid in full.
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PROCESSING]);
				if ($webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment received fully with overpayment, waiting for settlement.', 'binancepay-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice payment received fully, waiting for settlement.', 'binancepay-for-woocommerce'));
				}
				break;
			case 'InvoiceInvalid':
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::INVALID]);
				if ($webhookData->manuallyMarked) {
					$order->add_order_note(__('Invoice manually marked invalid.', 'binancepay-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice became invalid.', 'binancepay-for-woocommerce'));
				}
				break;
			case 'InvoiceExpired':
				if ($webhookData->partiallyPaid) {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
					$order->add_order_note(__('Invoice expired but was paid partially, please check.', 'binancepay-for-woocommerce'));
				} else {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED]);
					$order->add_order_note(__('Invoice expired.', 'binancepay-for-woocommerce'));
				}
				break;
			case 'InvoiceSettled':
				$order->payment_complete();
				if ($webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment settled but was overpaid.', 'binancepay-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED_PAID_OVER]);
				} else {
					$order->add_order_note(__('Invoice payment settled.', 'binancepay-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED]);
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
		}
	}

	/**
	 * Checks if the order has already a BinancePay invoice set and checks if it is still
	 * valid to avoid creating multiple invoices for the same order on BinancePay Server end.
	 *
	 * @param int $orderId
	 *
	 * @return mixed Returns false if no valid invoice found or the invoice id.
	 */
	protected function validInvoiceExists( int $orderId ): bool {
		// Check order metadata for BinancePay_id.
		if ( $invoiceId = get_post_meta( $orderId, 'BinancePay_id', true ) ) {
			// Validate the order status on BinancePay server.
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			try {
				Logger::debug( 'Trying to fetch existing invoice from BinancePay Server.' );
				$invoice       = $client->getInvoice( $this->apiHelper->storeId, $invoiceId );
				$invalidStates = [ 'Expired', 'Invalid' ];
				if ( in_array( $invoice->getData()['status'], $invalidStates ) ) {
					return false;
				} else {
					return true;
				}
			} catch ( \Throwable $e ) {
				Logger::debug( $e->getMessage() );
			}
		}

		return false;
	}

	/**
	 * Update WC order status (if a valid mapping is set).
	 */
	public function updateWCOrderStatus(\WC_Order $order, string $status): void {
		if ($status !== OrderStates::IGNORE) {
			$order->update_status($status);
		}
	}

	public function updateWCOrderPayments(\WC_Order $order): void {
		// Load payment data from API.
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$allPaymentData = $client->getPaymentMethods($this->apiHelper->storeId, $order->get_meta('BinancePay_id'));

			foreach ($allPaymentData as $payment) {
				// Only continue if the payment method has payments made.
				if ((float) $payment->getTotalPaid() > 0.0) {
					$paymentMethod = $payment->getPaymentMethod();
					// Update order meta data.
					update_post_meta( $order->get_id(), "BinancePay_{$paymentMethod}_destination", $payment->getDestination() ?? '' );
					update_post_meta( $order->get_id(), "BinancePay_{$paymentMethod}_amount", $payment->getAmount() ?? '' );
					update_post_meta( $order->get_id(), "BinancePay_{$paymentMethod}_paid", $payment->getTotalPaid() ?? '' );
					update_post_meta( $order->get_id(), "BinancePay_{$paymentMethod}_networkFee", $payment->getNetworkFee() ?? '' );
					update_post_meta( $order->get_id(), "BinancePay_{$paymentMethod}_rate", $payment->getRate() ?? '' );
					if ((float) $payment->getRate() > 0.0) {
						$formattedRate = number_format((float) $payment->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
						update_post_meta( $order->get_id(), "BinancePay_{$paymentMethod}_rateFormatted", $formattedRate );
					}
				}
			}
		} catch (\Throwable $e) {
			Logger::debug( 'Error processing payment data for invoice: ' . $order->get_meta('BinancePay_id') . ' and order ID: ' . $order->get_id() );
			Logger::debug($e->getMessage());
		}
	}

	/**
	 * Create an invoice on BinancePay Server.
	 */
	public function createBinanceOrder( \WC_Order $order ): ?array {
		// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
		$orderNumber = $order->get_order_number();
		Logger::debug( 'Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id() );

		$currency = $order->get_currency();
		$amount = (float) $order->get_total(); // unlike method signature suggests, it returns string.
		$stableCoin = 'USDT'; // Todo get from options.
		$stableCoinRate = BinanceApiHelper::getExchangeRate($stableCoin, $currency);
		$stableCoinAmount = $amount / $stableCoinRate;

		// Create the invoice on BinancePay Server.
		$client = new BinanceOrder(
			$this->get_option('url', null),
			$this->get_option('apikey', null),
			$this->get_option('apisecret', null)
		);

		try {
			$binancePayOrder = $client->createOrder(
				$order->get_checkout_order_received_url(),
				$order->get_cancel_order_url(),
				PreciseNumber::parseFloat($stableCoinAmount),
				$stableCoin,
				$orderNumber
			);

			Logger::debug('BincancePayOrder: ' . print_r($binancePayOrder, true));

			$order->update_meta_data('BinancePay_prepayId', $binancePayOrder['data']['prepayId'] );
			$order->update_meta_data('BinancePay_checkoutUrl', $binancePayOrder['data']['checkoutUrl'] );
			$order->update_meta_data('BinancePay_stableCoin', $stableCoin);
			$order->update_meta_data('BinancePay_stableCoinRate', $stableCoinRate);
			$order->update_meta_data('BinancePay_stableCoinCalculatedAmount', $stableCoinAmount);
			$order->save();

			return $binancePayOrder;

		} catch ( \Throwable $e ) {
			Logger::debug( $e->getMessage(), true );
			Logger::debug ($e->getTraceAsString(), true);
			// todo handle order exists as below:
			//			[status] => FAIL
			//			[code] => 400201
			//    [errorMessage] => merchantTradeNo is invalid or duplicated
		}

		return null;
	}

	/**
	 * Maps customer billing metadata.
	 */
	protected function prepareCustomerMetadata( \WC_Order $order ): array {
		return [
			'buyerEmail'    => $order->get_billing_email(),
			'buyerName'     => $order->get_formatted_billing_full_name(),
			'buyerAddress1' => $order->get_billing_address_1(),
			'buyerAddress2' => $order->get_billing_address_2(),
			'buyerCity'     => $order->get_billing_city(),
			'buyerState'    => $order->get_billing_state(),
			'buyerZip'      => $order->get_billing_postcode(),
			'buyerCountry'  => $order->get_billing_country()
		];
	}
}
