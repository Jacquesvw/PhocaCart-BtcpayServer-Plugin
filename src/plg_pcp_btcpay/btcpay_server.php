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

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

jimport( 'joomla.plugin.plugin' );
jimport( 'joomla.filesystem.file');
jimport( 'joomla.html.parameter' );

JLoader::registerPrefix('Phocacart', JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart');

class plgPCPBtcpay_Server extends CMSPlugin
{

	protected $pluginName = 'btcpay_server';
	protected $app;
	
	function __construct(& $subject, $config) {
		parent :: __construct($subject, $config);
		$this->loadLanguage();
		
		// Get the application object
		$this->app = Factory::getApplication();
		// Include the controller file
		require_once __DIR__ . '/controller.php';
		// Register the Ajax function handler
		$this->registerAjaxHandler();
		
		// Register the BTCPayHelper class
		JLoader::register('BTCPayHelper', __DIR__ . '/helpers/btcpayhelper.php');
		// Register the UtilityHelper class
		JLoader::register('UtilityHelper', __DIR__ . '/helpers/utilityhelper.php');
	}



	/**
	 * Registers the Ajax handler for the plugin.
	 *
	 * @return void
	 */
	protected function registerAjaxHandler() {
		// Check if the request is an Ajax request for this plugin
		if ($this->app->input->getCmd('option') === 'com_ajax' && $this->app->input->getCmd('plugin') === $this->pluginName) {
			// Create the controller instance and execute the task
			$controller = new BtcpayServerController();
			$controller->execute($this->app->input->getCmd('task'));
			$controller->redirect();
		}
	}



	/**
	 * Proceed to payment - some method do not have proceed to payment gateway like e.g. cash on delivery
	 *
	 * @param   integer	$proceed  Proceed or not proceed to payment gateway
	 * @param   string	$message  Custom message array set by plugin to override standard messages made by component
	 *
	 * @return  boolean  True
	 */

	function onPCPbeforeProceedToPayment(&$proceed, &$message, $eventData) {
	
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		$proceed = 1;
		$message = array();
		
		return true;
	}



	/**
	 * Handle the payment form setup based on the checkout mode.
	 *
	 * @param object &$form The payment form.
	 * @param object $paramsComponent Component parameters.
	 * @param object $params Plugin parameters.
	 * @param array $order Order details.
	 * @param array $eventData Event data.
	 * @return bool
	 */
	function onPCPbeforeSetPaymentForm(&$form, $paramsComponent, $params, $order, $eventData) {
	
		if (empty($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Extract necessary order details
		$orderId = $order['common']->id;
		$orderNumber = PhocacartOrder::getOrderNumber($orderId, $order['common']->date, $order['common']->order_number);
		$orderToken = htmlspecialchars($order['common']->order_token, ENT_QUOTES, 'UTF-8');
		$paymentId = (int)($order['common']->payment_id ?? 0);
		$orderExchangeRate = $order['common']->currency_exchange_rate ?? 1;
		
		// Get the currency mode from parameters, defaulting to 'default_currency'
		$currencyMode = $params->get('currency_mode', 'default_currency');
		
		// Initialize totals for order amount
		$orderAmount = 0;
		$price = new PhocacartPrice();
		
		// Calculate gross amount
		foreach ($order['total'] as $v) {
			if ($v->type == 'brutto') {
				if ($currencyMode == 'default_currency') {
					// Calculate the rounded 'brutto' amount in default currency
					$orderAmount += $price->roundPrice($v->amount);
				} else {
					// Calculate the rounded 'brutto' amount. Use the amount in the order currency if it's non-zero, otherwise convert using the exchange rate
					$orderAmount += ($v->amount_currency != 0) ? $price->roundPrice($v->amount_currency) : $price->roundPrice($v->amount * $orderExchangeRate);
				}
				break;
			}
		}
		
		// Determine the outstanding amount by subtracting paid amount from total order amount
		$orderAmountPaid = UtilityHelper::getTotalAmountPaidByOrderNumber($orderNumber);
		$orderAmountDue = max($orderAmount - $orderAmountPaid, 0);
		
		// Construct the URLs
		$successUrl = filter_var("/index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type={$this->pluginName}&o={$orderToken}", FILTER_SANITIZE_URL);
		$failureUrl = filter_var("/index.php?option=com_phocacart&view=response&task=response.paymentcancel&type={$this->pluginName}&o={$orderToken}", FILTER_SANITIZE_URL);
		
		// Get any BTCPay Server invoice data for this order if it exists
		$btcpayDbData = UtilityHelper::getBtcpayInvoiceByOrderNumber($orderNumber);
		
		// Redirect to the orders page if the invoice is either processing or settled with no outstanding amount
		if (!empty($btcpayDbData) && (
			($btcpayDbData['status'] == 'Processing') || 
			($btcpayDbData['status'] == 'Expired' && $btcpayDbData['additional_status'] == 'PaidLate' && $orderAmountDue == 0) || 
			($btcpayDbData['status'] == 'Settled' && $orderAmountDue == 0))) {
			
			if ($btcpayDbData['status'] == 'Processing') {
				$successUrl .= "&mid=1";
			} else {
				$successUrl .= "&mid=2";
			}
			
			// Perform the redirection to the determined URL.
			Factory::getApplication()->redirect($successUrl);
			return true;
		}
		
		// Determine checkout mode and prepare the appropriate form
		$checkoutMode = $params->get('checkout_mode', 'auto_generate_invoice');
		$html = []; $js = '';
		if ($checkoutMode === 'show_info_before_payment') {
			$checkoutInfo = $params->get('checkout_info', '');
			$html = [
				'<div id="message">',
					'<p>' . $checkoutInfo . '</p>',
					'<div class="ph-center">',
						'<button type="button" id="pay-now-button" class="btn btn-primary" onclick="getBTCPayInvoice(\'' . $orderToken . '\'); return false;">' . Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_POPUP_INSTRUCTION_BUTTON_TEXT') . '</button>',
					'</div>',
				'</div>'
			];
		} else {
			$link = '<a href="#" id="pay-now-link" class="link-primary" onclick="getBTCPayInvoice(\'' . $orderToken . '\'); return false;">' . Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_POPUP_INSTRUCTION_LINK_TEXT') . '</a>';
			$html = [
				'<div class="ph-center">',
					'<div id="spinner" class="ph-loader"></div>',
					'<div id="message">' . sprintf(Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_POPUP_INSTRUCTION'), $link) . '</div>',
				'</div>'
			];
			
			$js = <<<JS
				window.onload = function() {
					getBTCPayInvoice('$orderToken');
				};
				
			JS;
		}
		
		// JavaScript functions for handling BTCPay invoice and modal
		// Starting from BTCPay Server 2.0, the status options have been updated. Previously, the statuses were 'new', 'paid', 'complete', 'invalid', and 'expired'. These have now been replaced with 'New', 'Processing', 'Settled', 'Invalid', and 'Expired'
		$js .= <<<JS
			function openBTCPayInvoice(invoiceId) {
				window.btcpay.showInvoice(invoiceId);
			}
			
			function getBTCPayInvoice(order_token) {
				const payNowButton = document.getElementById('pay-now-button');
				const payNowLink = document.getElementById('pay-now-link');
				const spinnerElement = document.getElementById('spinner');
				if (payNowButton) {
					payNowButton.disabled = true;
				}
				if (payNowLink) {
					payNowLink.style.pointerEvents = 'none';
					payNowLink.style.opacity = '0.5';
				}
				const xhr = new XMLHttpRequest();
				xhr.open('POST', '/index.php?option=com_ajax&plugin={$this->pluginName}&group=pcp&task=createbtcpayinvoice&format=json', true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				
				xhr.onreadystatechange = function() {
					if (xhr.readyState === XMLHttpRequest.DONE) {
						try {
							const response = JSON.parse(xhr.responseText);
							if (payNowButton) {
								payNowButton.disabled = false;
							}
							if (payNowLink) {
								payNowLink.style.pointerEvents = 'auto';
								payNowLink.style.opacity = '1';
							}
							if (response.ok) {
								openBTCPayInvoice(response.invoice_id);
							} else {
								document.getElementById('message').innerHTML = '<div class="alert alert-danger" role="alert">' + response.msg + '</div>';
								if (spinnerElement) {
									spinnerElement.style.display = 'none';
								}
							}
						} catch (e) {
							document.getElementById('message').innerHTML = '<div class="alert alert-danger" role="alert">Error parsing response</div>';
							if (spinnerElement) {
								spinnerElement.style.display = 'none';
							}
						}
					}
				};
				
				xhr.send('o=' + encodeURIComponent(order_token));
			}
			
			let redirectUrl = '';
			
			if (window.btcpay) {
				window.btcpay.onModalReceiveMessage(function(event) {
					const status = event.data.status;
					const invoiceId = event.data.invoiceId;
					
					if (event && invoiceId && status) {
						const successUrl = '$successUrl';
						const failureUrl = '$failureUrl';
						switch (status) {
							case "paid":
							case "Processing":
								redirectUrl = successUrl + '&mid=1';
								break;
							case "confirmed":
							case "complete":
							case "Settled":
								redirectUrl = successUrl + '&mid=2';
								break;
							case "expired":
							case "Expired":
								redirectUrl = failureUrl + '&mid=1';
								break;
							case "invalid":
							case "Invalid":
								redirectUrl = failureUrl + '&mid=2';
								break;
							default:
								redirectUrl = failureUrl;
								break;
						}
						document.getElementById('message').innerHTML = Joomla.Text._('PLG_PCP_BTCPAY_SERVER_PAYMENT_REDIRECT_MESSAGE').replace('%s', redirectUrl);
					}
					
					const spinnerElement = document.getElementById('spinner');
					if (spinnerElement && (event.data === 'loaded' || event.data === 'close')) {
						spinnerElement.style.display = 'none';
					}
				});
				
				window.btcpay.onModalWillLeave(function () {
					if (redirectUrl) {
						window.location.replace(redirectUrl);
					}
				});
			}
		JS;
		
		$form = implode("\n", $html);
		
		// Load language strings into JavaScript
		Text::script('PLG_PCP_BTCPAY_SERVER_PAYMENT_REDIRECT_MESSAGE');
		
		$document = JFactory::getDocument();
		$btcpayServerHost = rtrim($params->get('btcpay_server_host', ''), '/');
		$document->addScript(filter_var($btcpayServerHost, FILTER_SANITIZE_URL) . '/modal/btcpay.js');
		$document->addScriptDeclaration($js);
		
		return true;
	}



	/**
	 * Event handler for payment webhook task.
	 * 
	 * This function is called when a webhook notification is received from BTCPay Server.
	 * It processes the webhook data, updates the order status, and logs the event.
	 *
	 * URL: $webhook_url = "/index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type={$this->pluginName}&pid=${paymentId}&tmpl=component";
	 *
	 * @param int|string $paymentId   The payment ID provided in the webhook URL, or retrieved by the plugin name if not provided.
	 * @param array      $eventData   The event data containing details about the webhook event.
	 *
	 * @return bool Returns false if the event data is invalid or if an error occurs during processing.
	 */
	function onPCPonPaymentWebhook($paymentId, $eventData) {
	
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Read the webhook data from the request body
		$postData = file_get_contents('php://input');
		$headers = getallheaders();
		
		// If no paymentId (pid) was provided in the webhook URL find the paymentId using the plugin name (type)
		if (empty($paymentId)) {
			$paymentId = PhocacartPayment::getPaymentMethodIdByMethodName($this->pluginName);
		}
		
		// Initialize the payment class and retrieve method details
		$payment = new PhocacartPayment();
		$paymentMethod = $payment->getPaymentMethod((int)$paymentId);
		$params = $paymentMethod->params;
		
		// Retrieve BTCPay server settings from payment parameters
		$btcpayServerHost = $params->get('btcpay_server_host', '');
		$btcpayServerApiKey = $params->get('btcpay_server_api_key', '');
		$btcpayServerStoreId = $params->get('btcpay_server_store_id', '');
		$btcpayServerWebhookSecret = $params->get('btcpay_server_webhook_secret', '');
		
		// Initialize BTCPay server object
		$btcpay = new BTCPayHelper($btcpayServerHost, $btcpayServerApiKey, $btcpayServerStoreId);
		
		// Validate webhook data
		$btcpayInvoice = $btcpay->validateWebhook($btcpayServerWebhookSecret, $postData, $headers);
		
		// Stop processing the webhook data if it is invalid
		if ($btcpayInvoice === null) {
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'Invalid IPN: ' . $btcpay->lastError);
			header("HTTP/1.1 200 OK");
			jexit();
		}
		
		// Extract details from the validated invoice
		$btcpayInvoiceId = $btcpayInvoice['id'] ?? '';
		$btcpayInvoiceStatus = $btcpayInvoice['status'] ?? '';
		$btcpayAdditionalStatus = $btcpayInvoice['additionalStatus'] ?? '';
		$orderNumber = $btcpayInvoice['metadata']['orderId'] ?? '';
		$orderAmount = $btcpayInvoice['amount'] ?? 0;
		$orderAmountPaid = $btcpayInvoice['totalPaid'] ?? 0;
		$orderCurrencyCode = $btcpayInvoice['currency'] ?? '';
		
		// Define default values for order status
		$orderStatus = 0;
		$orderStatusComment = '';
		
		// Determine the new order status based on invoice status
		switch ($btcpayInvoiceStatus) {
			case 'New':
				$orderStatus = $params->get('status_new', 1);
				$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_NEW_COMMENT') . ': ' . Text::_('PLG_PCP_BTCPAY_SERVER_INVOICE_ID_LABEL') . ' ' . $btcpayInvoiceId;
				break;
			
			case 'Processing':
				$orderStatus = $params->get('status_processing', 1);
				$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_PROCESSING_COMMENT');
				break;
			
			case 'Settled':
				$orderStatus = $params->get('status_settled', 1);
				$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_SETTLED_COMMENT') . ': ' . Text::_('PLG_PCP_BTCPAY_SERVER_AMOUNT_PAID_LABEL') . ' ' . $orderCurrencyCode . ' ' . $orderAmountPaid;
				if ($btcpayAdditionalStatus == 'PaidOver') {
					$orderStatus = $params->get('status_paid_over', 1);
					$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_PAID_OVER_COMMENT') . ': ' . Text::_('PLG_PCP_BTCPAY_SERVER_AMOUNT_PAID_OVER_LABEL') . ' ' . $orderCurrencyCode . ' ' . ($orderAmountPaid - $orderAmount);
				} elseif ($btcpayAdditionalStatus == 'Marked') {
					$orderStatusComment .= ' (Marked)';
					$orderAmountPaid = $orderAmount;
				}
				break;
			
			case 'Expired':
				$orderStatus = $params->get('status_expired', 1);
				$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_EXPIRED_COMMENT');
				if ($btcpayAdditionalStatus == 'PaidPartial') {
					$orderStatus = $params->get('status_paid_partial', 1);
					$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_PAID_PARTIAL_COMMENT') . ': ' . Text::_('PLG_PCP_BTCPAY_SERVER_AMOUNT_PAID_PARTIAL_LABEL') . ' ' . $orderCurrencyCode . ' ' . ($orderAmount - $orderAmountPaid);
				} elseif ($btcpayAdditionalStatus == 'PaidLate') {
					$orderStatus = $params->get('status_paid_late', 1);
					$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_PAID_LATE_COMMENT') . ': ' . Text::_('PLG_PCP_BTCPAY_SERVER_AMOUNT_PAID_LATE_LABEL') . ' ' . $orderCurrencyCode . ' ' . $orderAmountPaid;
				}
				break;
			
			case 'Invalid':
				$orderStatus = $params->get('status_invalid', 1);
				$orderStatusComment = Text::_('PLG_PCP_BTCPAY_SERVER_ORDER_STATUS_INVALID_COMMENT');
				if ($btcpayAdditionalStatus == 'Marked') {
					$orderStatusComment .= ' (Marked)';
				}
				break;
			
			default:
				PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'Invalid BTCPay Server invoice status ' . $btcpayInvoiceStatus);
				header("HTTP/1.1 200 OK");
				jexit();
		}
		
		// Update BTCPay invoice and order data in the database
		$btcpayDbData = [
			'invoice_id' => $btcpayInvoiceId,
			'order_number' => $orderNumber,
			'status' => $btcpayInvoiceStatus,
			'additional_status' => $btcpayAdditionalStatus,
			'currency_code' => $orderCurrencyCode,
			'amount_due' => $orderAmount,
			'amount_paid' => $orderAmountPaid,
			'creation_date' => $btcpayInvoice['createdTime'],
			'expiration_date' => $btcpayInvoice['expirationTime']
		];
		
		if (UtilityHelper::upsertBtcpayInvoice($btcpayDbData) === false) {
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'Failed to insert or update BTCPay invoice data in the database');
		}
		
		// Retrieve order ID using the order number
		$orderId = UtilityHelper::getOrderIdByOrderNumber($orderNumber);
		
		// Update the order's status and history
		if ($orderId !== null) {
			// Get the order token using the order ID
			$orderToken = UtilityHelper::getOrderTokenByOrderId($orderId) ?? '';
			
			// Change the status for the specified order in the database
			PhocacartOrderStatus::changeStatusInOrderTable((int)$orderId, (int)$orderStatus);
			
			// Send notification emails based on the new status
			$notify = PhocacartOrderStatus::changeStatus((int)$orderId, (int)$orderStatus, $orderToken);
			
			// Add an entry to the order's status history with the status change and any comments
			PhocacartOrderStatus::setHistory((int)$orderId, (int)$orderStatus, (int)$notify, $orderStatusComment);
		} else {
			// Log an error if the order ID is not found
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'Order status not updated. Order ID not found for order number ' . $orderNumber);
		}
		
		// Confirm receipt of the webhook to BTCPay Server
		header("HTTP/1.1 200 OK");
		jexit();
	}



	/**
	 * Event handler for payment notification task.
	 * 
	 * This function is called when a payment notification is received.
	 * It validates the order token, loads order details into the session, and redirects to the payment page.
	 *
	 * URL: $notify_url = "/index.php?option=com_phocacart&view=response&task=response.paymentnotify&type={$this->pluginName}&pid=${paymentId}&o=${order_token}";
	 *
	 * @param int|string $paymentId   The payment ID provided in the notification URL, or retrieved by the plugin name if not provided.
	 * @param array      $eventData   The event data containing details about the payment notification.
	 *
	 * @return bool Returns true if the payment URL is generated and the user is redirected; false otherwise.
	 */
	function onPCPbeforeCheckPayment($paymentId, $eventData) {
	
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Retrieve the order token from the input
		$input = Factory::getApplication()->input;
		$orderToken = htmlspecialchars($input->getString('o', ''), ENT_QUOTES, 'UTF-8');
		
		if (!empty($orderToken)) {
		
			// Get the order ID using the order token
			$orderId = UtilityHelper::getOrderIdByOrderToken($orderToken);
			
			if ($orderId !== null) {
				// Load order details into the session
				$session = Factory::getSession();
				$proceedPayment = ['orderid' => $orderId];
				$session->set('proceedpayment', $proceedPayment, 'phocaCart');
				
				// Generate the payment URL using Phoca Cart's routing function
				$paymentUrl = PhocacartPath::getRightPathLink(PhocacartRoute::getPaymentRoute());
				
				// Redirect to the payment page
				Factory::getApplication()->redirect($paymentUrl);
				return true;
			} else {
				// Log case where no order is found with the given token
				PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'Invalid or expired order token.');
			}
		} else {
			// Log case where no order token is provided
			PhocacartLog::add(2, 'Payment - BTCPay Server - ERROR', 0, 'No order token provided.');
		}
		
		// Generate the orders URL using Phoca Cart's routing function
		$orderUrl = PhocacartPath::getRightPathLink(PhocacartRoute::getOrdersRoute());
		// Redirect to the orders page
		Factory::getApplication()->redirect($orderUrl);
		return false;
	}



	/**
	 * Event handler for payment received task.
	 * 
	 * This function is intended to be called when a payment is received.
	 * It can be used to perform actions such as clearing the cart.
	 *
	 * URL: $receive_return = "/index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type={$this->pluginName}&mid=1";
	 *
	 * @param int $messageId ID of the message - can be set in PCPbeforeSetPaymentForm.
	 * @param string $message Custom message array set by the plugin to override standard messages made by the component.
	 * @param array $eventData The event data containing details about the payment notification.
	 *
	 * @return bool Returns true if the payment is successfully received; false otherwise.
	 */
	function onPCPafterRecievePayment($messageId, &$message, $eventData) {
	
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Retrieve the order token from the input
		$input = Factory::getApplication()->input;
		$orderToken = htmlspecialchars($input->getString('o', ''), ENT_QUOTES, 'UTF-8');
		
		// Determine if the order contains downloadable products
		$isDownloadable = UtilityHelper::hasDownloadableProductsByOrderToken($orderToken);
		$action = 0;
		
		// Set messages and actions based on the message ID and product type
		switch ($messageId) {
			case 1:
				if ($isDownloadable) {
					// Payment Processing - downloadable items
					$message['order_download'] = Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_PROCESSING_DOWNLOAD_MESSAGE');
					$action = 2;
				} else {
					// Payment Processing - no downloadable items
					$message['order_nodownload'] = Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_PROCESSING_MESSAGE');
					$action = 1;
				}
				break;
			
			case 2:
				if ($isDownloadable) {
					// Payment Confirmed - downloadable items
					$message['payment_download'] = Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_SETTLED_DOWNLOAD_MESSAGE');
					$action = 4;
				} else {
					// Payment Confirmed - no downloadable items
					$message['payment_nodownload'] = Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_SETTLED_MESSAGE');
					$action = 3;
				}
				break;
			
			default:
				if ($isDownloadable) {
					// Order and payment processed successfully - downloadable items
					$message['payment_download'] = Text::_('COM_PHOCACART_ORDER_AND_PAYMENT_SUCCESSFULLY_PROCESSED') . '<br>' . Text::_('COM_PHOCACART_ORDER_PAYMENT_PROCESSED_DOWNLOADABLE_ITEMS_ADDITIONAL_INFO');
					$action = 4;
				} else {
					// Order and payment processed successfully - no downloadable items
					$message['payment_nodownload'] = Text::_('COM_PHOCACART_ORDER_AND_PAYMENT_SUCCESSFULLY_PROCESSED') . '<br>' . Text::_('COM_PHOCACART_ORDER_PAYMENT_PROCESSED_ADDITIONAL_INFO');
					$action = 3;
				}
				break;
		}
		
		// Store the action and message in the session
		$session = Factory::getSession();
		$session->set('infoaction', $action, 'phocaCart');
		$session->set('infomessage', $message, 'phocaCart');
		
		return true;
	}



	/**
	 * Event handler for payment canceled task.
	 * 
	 * This function is intended to be called when a payment is canceled.
	 * It can be used to perform actions such as setting custom messages.
	 *
	 * URL: $cancel_return = "/index.php?option=com_phocacart&view=response&task=response.paymentcancel&type={$this->pluginName}&mid=1";
	 *
	 * @param int $messageId ID of the message - can be set in PCPbeforeSetPaymentForm.
	 * @param string $message Custom message array set by the plugin to override standard messages made by the component.
	 * @param array $eventData The event data containing details about the payment cancellation.
	 *
	 * @return bool Returns true if the payment cancellation is successfully handled; false otherwise.
	 */
	function onPCPafterCancelPayment($messageId, &$message, $eventData) {
	
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Retrieve the order token from the input and sanitize it
		$input = Factory::getApplication()->input;
		$orderToken = htmlspecialchars($input->getString('o', ''), ENT_QUOTES, 'UTF-8');
		$link = filter_var("/index.php?option=com_phocacart&view=response&task=response.paymentnotify&type={$this->pluginName}&o={$orderToken}", FILTER_SANITIZE_URL);
		
		// Determine the message based on the message ID
		switch ($messageId) {
			case 1:
				$message['payment_canceled'] = sprintf(Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_EXPIRED_MESSAGE'), $link);
				break;
			
			case 2:
				$message['payment_canceled'] = sprintf(Text::_('PLG_PCP_BTCPAY_SERVER_PAYMENT_INVALID_MESSAGE'), $link);
				break;
			
			default:
				// Default message for payment cancellation
				$message['payment_canceled'] = Text::_('COM_PHOCACART_PAYMENT_CANCELED') . '<br>' . Text::_('COM_PHOCACART_ORDER_PAYMENT_CANCELED_ADDITIONAL_INFO');
				break;
		}
		
		// Store the action and message in the session
		$session = Factory::getSession();
		$session->set('infoaction', 5, 'phocaCart');
		$session->set('infomessage', $message, 'phocaCart');
		
		return true;
	}



	/**
	 * Event handler to display content on the Info View page.
	 * 
	 * This function is intended to be called to alter the content on the info view page
	 * displayed after the payment receive/cancel URL is invoked.
	 *	 *
	 * @param array $data The data passed to the event.
	 * @param array $eventData The event data containing details about the context of the event.
	 *
	 * @return array Returns an array with the HTML content to be displayed.
	 */
/*
	function onPCPonInfoViewDisplayContent($data, $eventData) {
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		$output = array();
		
		$output['content'] = '<h3>onPCPonInfoViewDisplayContent</h3>';
		
		return $output;
	}
*/



	/**
	 * Event handler to control cart emptying after order placement.
	 * 
	 * This function is intended to be called before the cart is emptied after an order is placed.
	 * It can be used to prevent the cart from being emptied if manual emptying is desired at another event.
	 *
	 * @param array $form Form data from the order placement.
	 * @param array $pluginData Data related to the plugin, such as whether the cart should be emptied.
	 * @param array $paramsC Component parameters.
	 * @param array $params Plugin parameters.
	 * @param array $order Order details.
	 * @param array $eventData The event data containing details about the order placement.
	 *
	 * @return bool Returns true if the event is handled successfully; false otherwise.
	 */
/*
	function onPCPbeforeEmptyCartAfterOrder(&$form, &$pluginData, $paramsC, $params, $order, $eventData) {
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Uncomment to not empty the cart when order is placed
		//$pluginData['emptycart'] = false;
		
		return true;
	}
*/



	/**
	 * Event handler to show content on the checkout page before selecting the payment method.
	 * 
	 * This function is intended to be called before showing the possible payment methods on the checkout page.
	 * It can be used to modify the active status of the current payment method or to display additional content.
	 *
	 * @param bool $active Reference to the active status of the payment method, allowing modification.
	 * @param array $params Parameters related to the payment method and checkout process.
	 * @param array $eventData The event data containing details about the checkout process.
	 *
	 * @return bool Returns true if the event is handled successfully; false otherwise.
	 */
/*
	function onPCPbeforeShowPossiblePaymentMethod(&$active, $params, $eventData) {
		if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->pluginName) {
			return false;
		}
		
		// Uncomment the following line to disable/deactivate the current payment method based on custom rules
		//$active = false;
		
		return true;
	}
*/



	/**
	 * Event handler to display content just before the end of the pricing info on the product item view.
	 * 
	 * This function is intended to be called on the product item (detail view) page to display additional information
	 * before the end of the pricing information.
	 *
	 * @param string $context The context of the content being passed to the plugin.
	 * @param object $item The item object containing product details.
	 * @param object $params The parameters object for the item view.
	 *
	 * @return string Returns the HTML content to be displayed.
	 */
/*
	public function onPCPonItemBeforeEndPricePanel($context, &$item, &$params) {
		return "<h3>onPCPonItemBeforeEndPricePanel</h3>";
	}
*/

}
