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
use Joomla\CMS\Uri\Uri;

class JFormFieldBtcpayWebhookUrl extends FormField
{

	protected $type = 'btcpaywebhookurl';
	protected $pluginName = 'btcpay_server';

	public function getLabel() {
		return parent::getLabel();
	}

	protected function getInput() {
		//$paymentId = PhocacartPayment::getPaymentMethodIdByMethodName($this->pluginName);
		//$webhookUrl = Uri::root(false) . "index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type={$this->pluginName}&pid={$paymentId}&tmpl=component";
		$webhookUrl = Uri::root(false) . "index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type={$this->pluginName}";
		
		$htmlId = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		$htmlName = htmlspecialchars($this->name, ENT_COMPAT, 'UTF-8');
		$htmlValue = htmlspecialchars(filter_var($webhookUrl, FILTER_SANITIZE_URL), ENT_COMPAT, 'UTF-8');
		$htmlClass = $this->element['class'] ? htmlspecialchars($this->element['class'], ENT_COMPAT, 'UTF-8') : 'form-control inputbox';
		$htmlClass .= $this->element['required'] ? ' required' : '';
		$htmlSize = $this->element['size'] ? ' size="' . htmlspecialchars($this->element['size'], ENT_COMPAT, 'UTF-8') . '"' : '';
		$htmlMaxlength = $this->element['maxlength'] ? ' maxlength="' . htmlspecialchars($this->element['maxlength'], ENT_COMPAT, 'UTF-8') . '"' : '';
		$htmlPlaceholder = $this->element['placeholder'] ? ' placeholder="' . htmlspecialchars($this->element['placeholder'], ENT_COMPAT, 'UTF-8') .'"' : '';
		$htmlReadonly = $this->element['readonly'] ? ' readonly' : '';
		$htmlRequired = $this->element['required'] ? ' required' : '';
		$htmlDisabled = $this->element['disabled'] ? ' disabled' : '';
		
		$html  = '<div class="input-group">';
		$html .= 	'<input type="text" name="' . $htmlName . '" id="' . $htmlId . '" value="' . $htmlValue . '" class="' . $htmlClass . '"' . $htmlSize . $htmlMaxlength . $htmlPlaceholder . $htmlReadonly . $htmlRequired . $htmlDisabled . ' />';
		$html .= 	'<button type="button" class="btn btn-secondary" onclick="copyToClipboard(\'' . $htmlId  . '\')">' . $this->getCopyIcon() . '</button>';
		$html .= '</div>';
		
		// Add inline script to handle clipboard functionality if it is the first instance
		static $jsIncluded = false;
		if (!$jsIncluded) {
			$html .= <<<JS
				<script type="text/javascript">
					function copyToClipboard(elementId) {
						var copyText = document.getElementById(elementId);
						copyText.select();
						copyText.setSelectionRange(0, 99999); // For mobile devices
						
						navigator.clipboard.writeText(copyText.value).then(function() {
							alert("Webhook URL Copied!");
						}).catch(function(err) {
							console.error("Failed to copy text: ", err);
							if (err.name === "NotAllowedError") {
								console.error("Clipboard access denied: Ensure the action is triggered via a user gesture and the site is secure (HTTPS).");
							} else {
								console.error("Clipboard operation failed due to unsupported or restricted feature.");
							}
						});
					}
				</script>
			JS;
			$jsIncluded = true;
		}
		
		return $html;
	}

	private function getCopyIcon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-copy" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/></svg>';
	}
}