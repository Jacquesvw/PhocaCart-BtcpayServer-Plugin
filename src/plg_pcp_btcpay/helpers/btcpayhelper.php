<?php
/**
 * This is free and unencumbered software released into the public domain.
 *
 * Anyone is free to copy, modify, publish, use, compile, sell, or
 * distribute this software, either in source code form or as a compiled
 * binary, for any purpose, commercial or non-commercial, and by any
 * means.
 *
 * In jurisdictions that recognize copyright laws, the author or authors
 * of this software dedicate any and all copyright interest in the
 * software to the public domain. We make this dedication for the benefit
 * of the public at large and to the detriment of our heirs and
 * successors. We intend this dedication to be an overt act of
 * relinquishment in perpetuity of all present and future rights to this
 * software under copyright law.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 * For more information, please refer to <http://unlicense.org/>
 */

defined('_JEXEC') or die('Restricted access');

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Util\PreciseNumber;
use BTCPayServer\Client\Webhook;

/**
 * Helper class for BTCPay Server interactions.
 */
class BTCPayHelper
{
	private string $host;
	private string $apiKey;
	private string $storeId;
	private array $invoiceDetails = [];
	public ?string $lastError = null;
	
	const ERROR_INVALID_PAYLOAD = 1;
	const ERROR_JSON_DECODE = 2;
	const ERROR_INVALID_SIGNATURE = 3;
	const ERROR_NO_INVOICE_ID = 4;
	const ERROR_NO_INVOICE_FOUND = 5;
	
	/**
	 * BTCPayHelper constructor.
	 *
	 * @param string $host BTCPay Server host.
	 * @param string $apiKey API key for authentication.
	 * @param string $storeId Store ID.
	 */
	public function __construct(string $host, string $apiKey, string $storeId) {
		require_once __DIR__ . '/vendor/autoload.php';
		
		$this->host = $host;
		$this->apiKey = $apiKey;
		$this->storeId = $storeId;
	}



	/**
	 * Creates a new invoice.
	 *
	 * @param string $orderId Order ID.
	 * @param null|int|float|string $amount Amount to be invoiced.
	 * @param string $currency Currency code.
	 * @param string|null $buyerEmail Buyer's email address.
	 * @param array|null $customerData Additional customer data.
	 * @param string|null $redirectURL URL to redirect after payment.
	 * @return array|null The created invoice details or null on failure.
	 */
	public function createInvoice(string $orderId, null|int|float|string $amount, string $currency, ?string $buyerEmail = null, ?array $customerData = null, ?string $redirectURL = null): ?array {
		$metaData = [];
		
		if (is_array($customerData)) {
			$metaData = [
				'buyerName' => null,
				'buyerAddress1' => null,
				'buyerAddress2' => null,
				'buyerCity' => null,
				'buyerState' => null,
				'buyerZip' => null,
				'buyerCountry' => null,
				'buyerPhone' => null,
				'posData' => null,
				'itemDesc' => null,
				'itemCode' => null,
				'physical' => null,
				'taxIncluded' => null
			];
			
			$metaData = array_merge($metaData, $customerData);
		}
		
		if (isset($amount)) {
			$amount = PreciseNumber::parseString($amount);
		}
		
		try {
			$client = new Invoice($this->host, $this->apiKey);
			
			$checkoutOptions = new InvoiceCheckoutOptions();
			$checkoutOptions->setRedirectURL($redirectURL);
			
			$invoice = $client->createInvoice(
				$this->storeId,
				$currency,
				$amount,
				$orderId,
				$buyerEmail,
				$metaData,
				$checkoutOptions
			);
			
			$invoiceDetails = $invoice->getData();
			$invoiceDetails['totalPaid'] = 0;
			$invoiceDetails['due'] = $invoiceDetails['amount'];
			$invoiceDetails['paymentMethod'] = $invoiceDetails['checkout']['paymentMethods'][0] ?? null;
			
			return $this->invoiceDetails[$invoiceDetails['id']] = $invoiceDetails;
		} catch (\Throwable $e) {
			$this->lastError = $e->getMessage();
			return null;
		}
	}



	/**
	 * Retrieves an invoice by its ID.
	 *
	 * @param string $invoiceId The ID of the invoice.
	 * @param bool $forceUpdate If true, forces fetching the latest invoice data.
	 * @return array|null The invoice details or null on failure.
	 */
	public function getInvoice(string $invoiceId, bool $forceUpdate = false): ?array {
		if (($this->invoiceDetails[$invoiceId] ?? false) && !$forceUpdate) {
			return $this->invoiceDetails[$invoiceId];
		}
		
		try {
			$client = new Invoice($this->host, $this->apiKey);
			$invoice = $client->getInvoice($this->storeId, $invoiceId);
			$payments = $client->getPaymentMethods($this->storeId, $invoiceId);
			$invoiceDetails = $invoice->getData();
			$invoiceDetails['totalPaid'] = bcmul($payments[0]['totalPaid'] ?? '0', $payments[0]['rate'] ?? '0', 2);
			$invoiceDetails['due'] = bcmul($payments[0]['due'] ?? '0', $payments[0]['rate'] ?? '0', 2);
			$invoiceDetails['paymentMethod'] = $payments[0]['paymentMethod'] ?? $payments[0]['paymentMethodId'] ?? null; // BTCPay 2.0.0 compatibility: paymentMethod was renamed to paymentMethodId
			
			return $this->invoiceDetails[$invoiceId] = $invoiceDetails;
		} catch (\Throwable $e) {
			$this->lastError = $e->getMessage();
			return null;
		}
	}



	/**
	 * Validates the incoming webhook payload.
	 *
	 * @param string $secret The secret for webhook validation.
	 * @param string $raw_post_data Raw POST data received.
	 * @param array $headers HTTP headers received.
	 * @return int|array Returns error code on failure or invoice details on success.
	 */
	public function validateWebhook(string $secret, string $raw_post_data, array $headers): int|array {
		// Could not read from the php://input stream or invalid BTCPayServer payload received.
		if (false === $raw_post_data) {
			$this->lastError = 'Invalid payload received.';
			return self::ERROR_INVALID_PAYLOAD;
		}
		
		$payload = json_decode($raw_post_data, false, 512);
		
		// Could not decode the JSON payload from BTCPay.
		if (null === $payload) {
			$this->lastError = 'Failed to decode JSON payload.';
			return self::ERROR_JSON_DECODE;
		}
		
		// Verify hmac256
		$sig = null;
		foreach ($headers as $key => $value) {
			if (strtolower($key) === 'btcpay-sig') {
				$sig = $value;
				break;
			}
		}
		
		if (null === $sig) {
			$this->lastError = 'BTCPay signature not found in headers.';
			return self::ERROR_INVALID_SIGNATURE;
		}
		
		$webhookClient = new Webhook($this->host, $this->apiKey);
		
		// Invalid BTCPayServer payment notification message received - signature did not match.
		if (!$webhookClient->isIncomingWebhookRequestValid($raw_post_data, $sig, $secret)) {
			$this->lastError = 'Invalid BTCPay Server signature.';
			return self::ERROR_INVALID_SIGNATURE;
		}
		
		// Invalid BTCPayServer payment notification message received - did not receive invoice ID.
		if (empty($payload->invoiceId)) {
			$this->lastError = 'No invoice ID found in the payload.';
			return self::ERROR_NO_INVOICE_ID;
		}
		
		// Load an existing invoice with the provided invoiceId.
		$invoice = $this->getInvoice($payload->invoiceId, true);
		
		// No BTCPayServer invoice data returned
		if (null === $invoice) {
			$this->lastError = 'No invoice found with the provided invoice ID.';
			return self::ERROR_NO_INVOICE_FOUND;
		}
		
		return $invoice;
	}



	/**
	 * Creates a new webhook for BTCPay Server.
	 *
	 * @param string $webhookUrl The URL to be called by the webhook.
	 * @param array $specificEvents The specific events that will trigger the webhook.
	 * @return array|null The webhook details or null on failure.
	 */
	public function createWebhook(string $webhookUrl, array $specificEvents): ?array {
		try {
			$client = new Webhook($this->host, $this->apiKey);
			$webhook = $client->createWebhook($this->storeId, $webhookUrl, $specificEvents, null);
			return $webhook->getData();
		} catch (Throwable $e) {
			$this->lastError = $e->getMessage();
			return null;
		}
	}



	/**
	 * Updates an existing webhook for BTCPay Server.
	 *
	 * @param string $webhookUrl The URL to be called by the webhook.
	 * @param string $webhookId The ID of the webhook to be updated.
	 * @param array $specificEvents The specific events that will trigger the webhook.
	 * @return array|null The webhook details or null on failure.
	 */
	public function updateWebhook(string $webhookId, string $webhookUrl, array $specificEvents): ?array {
		try {
			$client = new Webhook($this->host, $this->apiKey);
			$webhook = $client->updateWebhook($this->storeId, $webhookUrl, $webhookId, $specificEvents);
			return $webhook->getData();
		} catch (Throwable $e) {
			$this->lastError = $e->getMessage();
			return null;
		}
	}



	/**
	 * Retrieves the details of a specific webhook for BTCPay Server.
	 *
	 * @param string $webhookId The ID of the webhook to be retrieved.
	 * @return array|null The webhook details or null on failure.
	 */
	public function getWebhook(string $webhookId): ?array {
		try {
			$client = new Webhook($this->host, $this->apiKey);
			$webhook = $client->getWebhook($this->storeId, $webhookId);
			return $webhook->getData();
		} catch (Throwable $e) {
			$this->lastError = $e->getMessage();
			return null;
		}
	}
}
