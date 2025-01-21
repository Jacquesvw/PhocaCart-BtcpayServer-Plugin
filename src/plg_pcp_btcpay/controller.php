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

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

if (!class_exists('PhocaCartLoader')) {
	require_once( JPATH_ADMINISTRATOR.'/components/com_phocacart/libraries/loader.php');
}
JLoader::registerPrefix('Phocacart', JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart');

class BtcpayServerController extends BaseController
{
	protected $pluginName = 'btcpay_server';
	
	public function __construct($config = array()) {
		parent::__construct($config);
		
		// Register the BTCPayHelper class
		JLoader::register('BTCPayHelper', __DIR__ . '/helpers/btcpayhelper.php');
		// Register the UtilityHelper class
		JLoader::register('UtilityHelper', __DIR__ . '/helpers/utilityhelper.php');
	}



	/**
	 * Sets up the webhook for BTCPay Server.
	 *
	 * @return void
	 */
	public function setupwebhook() {
		// Initialize the application
		$app = Factory::getApplication();
		$input = $app->input;
		
		// Check if the token is valid for the backend
		if (!$app->isClient('administrator') || !$app->checkToken('post')) {
			echo json_encode(['ok' => false, 'msg' => 'Invalid Token', 'webhook_id' => '', 'secret' => '']);
			$app->close();
		}
		
		// Get the user object
		$user = Factory::getUser();
		// Check if the user has access to the administration interface for PhocaCart
		if (!$user->authorise('core.manage', 'com_phocacart')) {
			echo json_encode(['ok' => false, 'msg' => 'Access Denied', 'webhook_id' => '', 'secret' => '']);
			$app->close();
		}
		
		// Get input data
		$host = htmlspecialchars($input->getString('host', ''), ENT_QUOTES, 'UTF-8');
		$host = filter_var($host, FILTER_SANITIZE_URL);
		
		$apiKey = htmlspecialchars($input->getString('api_key', ''), ENT_QUOTES, 'UTF-8');
		$storeId = htmlspecialchars($input->getString('store_id', ''), ENT_QUOTES, 'UTF-8');
		$secret = htmlspecialchars($input->getString('secret', ''), ENT_QUOTES, 'UTF-8');
		
		$webhookUrl = htmlspecialchars($input->getString('webhook_url', Uri::root(false) . "index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type={$this->pluginName}"), ENT_QUOTES, 'UTF-8');
		$webhookUrl = filter_var($webhookUrl, FILTER_SANITIZE_URL);
		
		$webhookId = htmlspecialchars($input->getString('webhook_id', ''), ENT_QUOTES, 'UTF-8');
		
		// Validate input data and construct an error message if needed
		$message = '';
		$success = false;
		if (empty($host)) {
			$message .= 'BTCPay Server Host is required. ';
		}
		if (empty($apiKey)) {
			$message .= 'BTCPay Server API Key is required. ';
		}
		if (empty($storeId)) {
			$message .= 'BTCPay Server Store ID is required. ';
		}
		
		// If there is an error message, return it and stop the process
		if ($message) {
			echo json_encode(['ok' => $success, 'msg' => trim($message), 'webhook_id' => '', 'secret' => '']);
			$app->close();
		}
		
		// Initialize the BTCPayHelper class
		$btcpay = new BTCPayHelper($host, $apiKey, $storeId);
		
		// Events that will call the webhook
		$specificEvents = [
			'InvoiceCreated',
			//'InvoiceProcessing',
			'InvoiceReceivedPayment', // Use 'InvoiceReceivedPayment' instead of 'InvoiceProcessing', because 'InvoiceProcessing' does not receive 'PaidLate' additional statuses
			'InvoiceExpired',
			'InvoiceSettled',
			'InvoiceInvalid'
		];
		
		// Update the webhook if the webhook ID and secret is available
		if (!empty($webhookId) && !empty($secret)) {
			$result = $btcpay->updateWebhook($webhookId, $webhookUrl, $specificEvents);
			if ($result !== null) {
				$success = true;
				$webhookId = $result['id'];
				$message = 'Webhook successfully updated';
			} else {
				$message = $btcpay->lastError;
			}
		}
		
		// If an existing webhook wasn't updated, create a new one
		if (!$success) {
			$result = $btcpay->createWebhook($webhookUrl, $specificEvents);
			if ($result !== null) {
				$success = true;
				$webhookId = $result['id'];
				$secret = $result['secret'];
				$message = 'Webhook successfully created';
			} else {
				$message = $btcpay->lastError;
			}
		}
		
		// Save the webhook details to the plugin params
		if ($success) {
			$params = [
				'btcpay_server_host' => $host,
				'btcpay_server_api_key' => $apiKey,
				'btcpay_server_store_id' => $storeId,
				'btcpay_server_webhook_secret' => $secret,
				'btcpay_server_webhook_id' => $webhookId
			];
			UtilityHelper::updatePluginParams($this->pluginName, $params);
		}
		
		// Return the success status as a JSON response
		echo json_encode(['ok' => $success, 'msg' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 'webhook_id' => htmlspecialchars($webhookId, ENT_QUOTES, 'UTF-8'), 'secret' => htmlspecialchars($secret, ENT_QUOTES, 'UTF-8')]);
		$app->close();
	}



	/**
	 * Creates a BTCPay Server invoice based on the provided order token.
	 *
	 * @return void
	 */
	public function createbtcpayinvoice() {
		// Initialize the application
		$app = Factory::getApplication();
		$input = $app->input;
		
		// Get input data
		$orderToken = htmlspecialchars($input->getString('o', ''), ENT_QUOTES, 'UTF-8');
		if (empty($orderToken)) {
			echo json_encode(['ok' => false, 'msg' => 'Order token is required.', 'invoice_id' => '']);
			$app->close();
		}
		
		// Get the order ID using the order token
		$orderId = UtilityHelper::getOrderIdByOrderToken($orderToken);
		if ($orderId === null) {
			echo json_encode(['ok' => false, 'msg' => 'Order ID not found for the given token.', 'invoice_id' => '']);
			$app->close();
		}
		
		// Get the order details
		$order = new PhocacartOrderView();
		$orderCommon = $order->getItemCommon($orderId);
		$orderCustomerData = $order->getItemBaS($orderId, 1);
		$orderTotal = $order->getItemTotal($orderId);
		
		if ($orderCommon === null || $orderCustomerData === null || $orderTotal === null) {
			echo json_encode(['ok' => false, 'msg' => 'Order details are incomplete or not found.', 'invoice_id' => '']);
			$app->close();
		}
		
		$paymentId = (int)$orderCommon->payment_id;
		$orderNumber = PhocacartOrder::getOrderNumber($orderId, $orderCommon->date, $orderCommon->order_number);
		$orderCurrencyCode = $orderCommon->currency_code;
		$orderExchangeRate = $orderCommon->currency_exchange_rate ?? 1;
		
		// Initialize the payment class and retrieve method details
		$payment = new PhocacartPayment();
		$paymentMethod = $payment->getPaymentMethod($paymentId);
		$params = $paymentMethod->params;
		
		// Retrieve plugin parameters related to BTCPay Server
		$btcpayServerHost = $params->get('btcpay_server_host', '');
		$btcpayServerApiKey = $params->get('btcpay_server_api_key', '');
		$btcpayServerStoreId = $params->get('btcpay_server_store_id', '');
		
		if (empty($btcpayServerHost) || empty($btcpayServerApiKey) || empty($btcpayServerStoreId)) {
			$errorMessage = "The BTCPay Server payment plugin is not configured correctly. Please check the plugin settings.";
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', (int)$orderId, $errorMessage);
			echo json_encode(['ok' => false, 'msg' => $errorMessage, 'invoice_id' => '']);
			$app->close();
		}
		
		// Get the currency mode from parameters, defaulting to 'default_currency'
		$currencyMode = $params->get('currency_mode', 'default_currency');
		
		if ($currencyMode == 'default_currency') {
			// Try to get the default currency code
			$defaultCurrencyCode = PhocacartCurrency::getDefaultCurrencyCode();
			
			if ($defaultCurrencyCode !== false) {
				// Use the default currency with an exchange rate of 1
				$orderCurrencyCode = $defaultCurrencyCode;
				$orderExchangeRate = 1;
			}
		}
		
		// Initialize totals for order amount and tax
		$orderAmount = 0;
		$orderTaxAmount = 0;
		$price = new PhocacartPrice();
		
		// Calculate gross amount and tax amount
		foreach ($orderTotal as $v) {
			if ($v->type == 'brutto') {
				if ($currencyMode == 'default_currency') {
					// Calculate the rounded 'brutto' amount in default currency
					$orderAmount += $price->roundPrice($v->amount);
				} else {
					// Calculate the rounded 'brutto' amount. Use the amount in the order currency if it's non-zero, otherwise convert using the exchange rate
					$orderAmount += ($v->amount_currency != 0) ? $price->roundPrice($v->amount_currency) : $price->roundPrice($v->amount * $orderExchangeRate);
				}
			} elseif ($v->type == 'tax') {
				if ($currencyMode == 'default_currency') {
					// Calculate the rounded tax amount in default currency
					$orderTaxAmount += $price->roundPrice($v->amount);
				} else {
					// Calculate the rounded tax amount. Use the amount in the order currency if it's non-zero, otherwise convert using the exchange rate
					$orderTaxAmount += ($v->amount_currency != 0) ? $price->roundPrice($v->amount_currency) : $price->roundPrice($v->amount * $orderExchangeRate);
				}
			}
		}
		
		// Determine the outstanding amount by subtracting paid amount from total order amount
		$orderAmountPaid = UtilityHelper::getTotalAmountPaidByOrderNumber($orderNumber);
		$orderAmountDue = max($orderAmount - $orderAmountPaid, 0);
		
		// Check for an existing valid BTCPay Server invoice ID in the database to avoid generating multiple invoices
		$btcpayDbData = UtilityHelper::getBtcpayInvoiceByOrderNumber($orderNumber);
		
		if (!empty($btcpayDbData) && (
			($btcpayDbData['status'] == 'New' && time() < $btcpayDbData['expiration_date']) || 
			($btcpayDbData['status'] == 'Processing') || 
			($btcpayDbData['status'] == 'Expired' && $btcpayDbData['additional_status'] == 'PaidLate' && $orderAmountDue == 0) || 
			($btcpayDbData['status'] == 'Settled' && $orderAmountDue == 0))) {
			
			// An existing invoice ID was found, return it and stop further processing
			echo json_encode(['ok' => true, 'msg' => 'Reusing existing BTCPay Server invoice.', 'invoice_id' => $btcpayDbData['invoice_id']]);
			$app->close();
		}
		
		// Check if the order has already been paid, preventing the creation of a new invoice.
		if ($orderAmountDue == 0) {
			echo json_encode(['ok' => false, 'msg' => 'This order has already been fully paid. No outstanding amount remains.', 'invoice_id' => '']);
			$app->close();
		}
		
		// An existing invoice ID was not found, prepare customer data for a new BTCPay Server invoice
		$btcpayCustomerEmail = null;
		$btcpayCustomerData = [];
		foreach ($params->get('customer_data', []) as $field) {
			switch ($field) {
				case 'name':
					$btcpayCustomerData['buyerName'] = implode(' ', array_filter([
						$orderCustomerData['b']['name_first'] ?? null,
						$orderCustomerData['b']['name_middle'] ?? null,
						$orderCustomerData['b']['name_last'] ?? null
					]));
					break;
				
				case 'email':
					$btcpayCustomerEmail = $orderCustomerData['b']['email'] ?? null;
					break;
				
				case 'phone':
					$btcpayCustomerData['buyerPhone'] = $orderCustomerData['b']['phone_mobile'] ?? null;
					break;
				
				case 'address':
					$btcpayCustomerData = array_merge($btcpayCustomerData, [
						'buyerAddress1' => $orderCustomerData['b']['address_1'] ?? null,
						'buyerAddress2' => $orderCustomerData['b']['address_2'] ?? null,
						'buyerCity' => $orderCustomerData['b']['city'] ?? null,
						'buyerState' => $orderCustomerData['b']['regiontitle'] ?? null,
						'buyerZip' => $orderCustomerData['b']['zip'] ?? null,
						'buyerCountry' => $orderCustomerData['b']['countrytitle'] ?? null
					]);
					break;
				
				case 'tax':
					$btcpayCustomerData['taxIncluded'] = $orderTaxAmount ?? null;
					break;
			}
		}
		
		// Construct the return URL
		$btcpayReturnUrl = filter_var(Uri::root(false) . "index.php?option=com_phocacart&view=response&task=response.paymentnotify&type={$this->pluginName}&pid={$paymentId}&o={$orderToken}", FILTER_SANITIZE_URL);
		
		// Create a new BTCPay Server invoice
		$btcpay = new BTCPayHelper($btcpayServerHost, $btcpayServerApiKey, $btcpayServerStoreId);
		$btcpayInvoice = $btcpay->createInvoice($orderNumber, $orderAmountDue, $orderCurrencyCode, $btcpayCustomerEmail, $btcpayCustomerData, $btcpayReturnUrl);
		
		if ($btcpayInvoice === null || empty($btcpayInvoice['id'])) {
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', (int)$orderId, 'Create Invoice Failed: ' . $btcpay->lastError);
			echo json_encode(['ok' => false, 'msg' => 'An error occurred while processing your payment. Please try again later or contact support.', 'invoice_id' => '']);
			$app->close();
		}
		
		// Store the newly created BTCPay Server invoice and order data in the database to account for potential webhook processing delays
		$btcpayDbData = [
			'invoice_id' => $btcpayInvoice['id'],
			'order_number' => $orderNumber,
			'status' => $btcpayInvoice['status'] ?? null,
			'additional_status' => $btcpayInvoice['additionalStatus'] ?? null,
			'currency_code' => $orderCurrencyCode,
			'amount_due' => $orderAmountDue,
			'amount_paid' => $btcpayInvoice['totalPaid'] ?? 0,
			'creation_date' => $btcpayInvoice['createdTime'] ?? null,
			'expiration_date' => $btcpayInvoice['expirationTime'] ?? null
		];
		
		if (UtilityHelper::upsertBtcpayInvoice($btcpayDbData) === false) {
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'Failed to insert newly created BTCPay Server invoice data in the database');
		}
		
		// Return the success status as a JSON response
		echo json_encode(['ok' => true, 'msg' => 'BTCPay Server invoice created successfully.', 'invoice_id' => htmlspecialchars($btcpayInvoice['id'], ENT_QUOTES, 'UTF-8')]);
		$app->close();
	}
}
