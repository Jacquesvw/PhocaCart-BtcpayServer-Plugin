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

defined('_JEXEC') or die();

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class JFormFieldBtcpayWebhookButton extends FormField
{
	protected $type = 'btcpaywebhookbutton';
	protected $pluginName = 'btcpay_server';

	public function getLabel() {
		return parent::getLabel();
	}

	protected function getInput() {
		// Sanitize the ID to prevent XSS
		$htmlId = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		
		// HTML for the button
		$html = '<button type="button" id="' . $htmlId . '" class="btn btn-primary" onclick="setupWebhook(\'' . $htmlId . '\')">' . Text::_('PLG_PCP_BTCPAY_SERVER_BUTTON_SETUP_WEBHOOK') . '</button>';
		
		// Add inline script to handle button click functionality if it is the first instance
		static $jsIncluded = false;
		if (!$jsIncluded) {
			$html .= <<<JS
				<script type="text/javascript">
					function setupWebhook(buttonId) {
						var host = document.querySelector('#phform_params_btcpay_server_host').value;
						var apiKey = document.querySelector('#phform_params_btcpay_server_api_key').value;
						var storeId = document.querySelector('#phform_params_btcpay_server_store_id').value;
						var secret = document.querySelector('#phform_params_btcpay_server_webhook_secret').value;
						var webhookUrl = document.querySelector('#phform_params_btcpay_server_webhook_url').value;
						var webhookId = document.querySelector('#phform_params_btcpay_server_webhook_id').value;
						
						// Check if any field is empty and show an alert
						var emptyFields = [];
						if (!host) emptyFields.push('Server URL');
						if (!apiKey) emptyFields.push('API Key');
						if (!storeId) emptyFields.push('Store ID');
						
						if (emptyFields.length > 0) {
							alert('Please fill in the following required fields: ' + emptyFields.join(', '));
							return;
						}
						
						// Disable the button to prevent multiple clicks
						var button = document.getElementById(buttonId);
						button.disabled = true;
						
						// Send the Ajax request with the field values
						var xhr = new XMLHttpRequest();
						xhr.open('POST', Joomla.getOptions('system.paths').base + '/index.php?option=com_ajax&plugin={$this->pluginName}&group=pcp&task=setupwebhook&format=json', true);
						xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
						xhr.setRequestHeader('X-CSRF-Token', Joomla.getOptions('csrf.token'));
						
						xhr.onreadystatechange = function() {
							if (xhr.readyState === XMLHttpRequest.DONE) {
								if (xhr.status === 200) {
									var response = JSON.parse(xhr.responseText);
									if (response && response.ok) {
										document.querySelector('#phform_params_btcpay_server_webhook_secret').value = response.secret;
										document.querySelector('#phform_params_btcpay_server_webhook_id').value = response.webhook_id;
										alert(response.msg);
									} else if (response && !response.ok) {
										alert('Error: ' + response.msg);
									} else {
										alert('Webhook setup failed. Please try again.');
									}
								} else {
									alert('An error occurred while processing the request.');
								}
							}
						};
						
						xhr.onerror = function() {
							alert('An error occurred while processing the request.');
						};
						
						xhr.onloadend = function() {
							// Re-enable the button after the Ajax call is complete
							button.disabled = false;
						};
						
						var data = 'host=' + encodeURIComponent(host) +
									'&api_key=' + encodeURIComponent(apiKey) +
									'&store_id=' + encodeURIComponent(storeId) +
									'&secret=' + encodeURIComponent(secret) +
									'&webhook_url=' + encodeURIComponent(webhookUrl) +
									'&webhook_id=' + encodeURIComponent(webhookId);
						
						xhr.send(data);
					}
				</script>
			JS;
			$jsIncluded = true;
		}
		
		return $html;
	}
}
