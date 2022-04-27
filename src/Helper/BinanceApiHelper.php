<?php

declare(strict_types=1);

namespace BinancePay\WC\Helper;

use BinancePay\WC\Exception\BinancePayException;

class BinanceApiHelper {
	const RATES_CACHE_KEY = 'binancepay_exchange_rates';

	public static function getExchangeRate(string $stableCoin, string $storeCurrency): float {
		// Replace ticker with Coingecko ID if needed.
		// todo: refactor to more general approach; mappings per rate provider etc.
		if ($stableCoin === 'USDT') {
			$stableCoin = 'tether';
		}

		$storeCurrency = strtolower($storeCurrency);
		$stableCoin = strtolower($stableCoin);

		// Use transients API to cache pm for a few minutes to avoid too many requests to BTCPay Server.
		if ($cachedRates = get_transient(self::RATES_CACHE_KEY)) {
			if (isset($cachedRates[$stableCoin][$storeCurrency])) {
				return (float) $cachedRates[$stableCoin][$storeCurrency];
			}
		}

		// Todo: can be refactored to have ExchangeInterface and implementations for multiple rate providers beside Coingecko.
		$client = new \BinancePay\WC\Client\CoingeckoClient();
		try {
			$rates = $client->getRates([$stableCoin], [$storeCurrency]);
			// Store rates into cache.
			if (isset($rates[$stableCoin][$storeCurrency])) {
				set_transient( self::RATES_CACHE_KEY, $rates,5 * MINUTE_IN_SECONDS );
				return $rates[$stableCoin][$storeCurrency];
			}
		} catch (\Throwable $e) {
			Logger::debug('Error fetching rates: ' . $e->getMessage());
		}

		Logger::debug('Failed to fetch exchange rate for stableCoin: ' . $stableCoin . ' and storeCurrency: ' . $storeCurrency, true);
		throw new BinancePayException('Could not fetch exchange rates, aborting. ', 500);
	}

	// todo: maybe remove static class and make GFConfig object or similar
	public static function getConfig(): array {
		// todo: perf: maybe add caching
		$url = get_option('binancepay_url');
		$key = get_option('binancepay_api_key');
		if ($url && $key) {
			return [
				'url' => $url,
				'api_key' => $key,
				'store_id' => get_option('binancepay_store_id', null),
				'webhook' => get_option('binancepay_webhook', null)
			];
		}
		else {
			return [];
		}
	}

	public static function checkApiConnection(): bool {
		if ($config = self::getConfig()) {
			// todo: replace with server info endpoint.
			$client = new Store($config['url'], $config['api_key']);
			if (!empty($stores = $client->getStores())) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns BinancePay Server invoice url.
	 */
	public function getInvoiceRedirectUrl($invoiceId): ?string {
		if ($this->configured) {
			return $this->url . '/i/' . urlencode($invoiceId);
		}
		return null;
	}

	/**
	 * Check webhook signature to be a valid request.
	 */
	public function validWebhookRequest(string $signature, string $requestData): bool {
		if ($this->configured) {

		}
		return false;
	}

	/**
	 * Checks if the provided API config already exists in options table.
	 */
	public static function apiCredentialsExist(string $apiUrl, string $apiKey, string $storeId): bool {
		if ($config = self::getConfig()) {
			if (
				$config['url'] === $apiUrl &&
				$config['api_key'] === $apiKey &&
				$config['store_id'] === $storeId
			) {
				return true;
			}
		}

		return false;
	}

}
