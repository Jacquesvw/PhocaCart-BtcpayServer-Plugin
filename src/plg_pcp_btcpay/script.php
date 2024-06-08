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

use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;

class plgPCPBtcpay_ServerInstallerScript extends InstallerScript
{
	/**
	 * Constructor.
	 *
	 * Initializes the class and sets up initial configurations.
	 */
	public function __construct() {
		// Register the UtilityHelper class
		JLoader::register('UtilityHelper', __DIR__ . '/helpers/utilityhelper.php');
	}



	/**
	 * Method to run after the plugin is installed.
	 *
	 * @param object $parent The class calling this method.
	 * @return void
	 */
	public function install($parent) {
		// Code to execute after the plugin has been installed
		$this->createTables();
		$this->addPaymentMethod();
		$this->addCustomOrderStatuses();
		$this->moveImageFiles();
	}



	/**
	 * Method to run after the plugin is uninstalled.
	 *
	 * @param object $parent The class calling this method.
	 * @return void
	 */
	public function uninstall($parent) {
		// Code to execute after the plugin has been uninstalled
		$this->dropTables();
		//$this->removePaymentMethod();
		//$this->removeCustomOrderStatuses();
	}



	/**
	 * Method to run after the plugin is updated.
	 *
	 * @param object $parent The class calling this method.
	 * @return void
	 */
	public function update($parent) {
		// Code to execute after the plugin has been updated
	}



	/**
	 * Method to run before installing, updating, or uninstalling the plugin.
	 *
	 * @param string $type The type of change (install, update, discover_install, or uninstall).
	 * @param object $parent The class calling this method.
	 * @return void
	 */
	public function preflight($type, $parent) {
		// Code to execute before installing, updating, or uninstalling
		if ($type !== 'uninstall' && !$this->isPhocaCartInstalled()) {
			// Phoca Cart is not installed, raise an error and stop the installation
			Factory::getApplication()->enqueueMessage('Phoca Cart is not installed. Please install Phoca Cart before installing this plugin.', 'error');
			return false;
		}
	}



	/**
	 * Method to run after installing, updating, or uninstalling the plugin.
	 *
	 * @param string $type The type of change (install, update, discover_install, or uninstall).
	 * @param object $parent The class calling this method.
	 * @return void
	 */
	public function postflight($type, $parent) {
		// Code to execute after installing, updating, or uninstalling
	}



	/**
	 * Creates the necessary tables for the plugin.
	 *
	 * This method creates the tables required by the plugin if they do not already exist.
	 *
	 * @return bool True on success, False on failure.
	 */
	private function createTables() {
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		$queries = [
			"CREATE TABLE IF NOT EXISTS `#__phocacart_btcpay_server_invoices` (
				`id` int NOT NULL,
				`invoice_id` varchar(64) NOT NULL,
				`order_number` varchar(64) NOT NULL,
				`status` varchar(32) DEFAULT NULL,
				`additional_status` varchar(32) DEFAULT NULL,
				`currency_code` varchar(5) DEFAULT NULL,
				`amount_due` double NOT NULL DEFAULT '0',
				`amount_paid` double NOT NULL DEFAULT '0',
				`creation_date` int DEFAULT NULL,
				`expiration_date` int DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			
			"ALTER TABLE `#__phocacart_btcpay_server_invoices`
				ADD PRIMARY KEY (`id`),
				ADD UNIQUE KEY `invoice_id` (`invoice_id`)",
			
			"ALTER TABLE `#__phocacart_btcpay_server_invoices`
				MODIFY `id` int NOT NULL AUTO_INCREMENT"
		];
		
		foreach ($queries as $sql) {
			$db->setQuery($sql);
			try {
				$db->execute();
			} catch (RuntimeException $e) {
				Factory::getApplication()->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED') . ' '. $e->getMessage(), 'error');
				return false;
			}
		}
		return true;
	}



	/**
	 * Drops the tables created by the plugin.
	 *
	 * This method drops the tables created by the plugin during the uninstallation process.
	 *
	 * @return bool True on success, False on failure.
	 */
	private function dropTables() {
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		$queries = [
			"DROP TABLE `#__phocacart_btcpay_server_invoices`"
		];
		
		foreach ($queries as $sql) {
			$db->setQuery($sql);
			try {
				$db->execute();
			} catch (RuntimeException $e) {
				Factory::getApplication()->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED') . ' '. $e->getMessage(), 'error');
				return false;
			}
		}
		return true;
	}



	/**
	 * Adds the BTCPay Server payment method to PhocaCart if it does not already exist.
	 *
	 * This function checks the PhocaCart payment methods table to see if a payment method
	 * with the method identifier 'btcpay_server' exists. If it does not, it inserts a new
	 * record for the BTCPay Server payment method with predefined values.
	 *
	 * @return bool True on success, False on failure.
	 */
	private function addPaymentMethod() {
		// Get the database object
		$db = Factory::getDbo();
		
		// Check if the payment method already exists
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__phocacart_payment_methods'))
			->where($db->quoteName('method') . ' = ' . $db->quote('btcpay_server'));
		$db->setQuery($query);
		$exists = (int) $db->loadResult();
		
		if (!$exists) {
			// Insert the new payment method
			$columns = [
				'title', 'alias', 'image', 'method', 'access', 'published'
			];
			
			$values = [
				$db->quote('Bitcoin and Lightning'),
				$db->quote('btcpay-server'),
				$db->quote('images/payment/bitcoin-lightning.svg#joomlaImage://local-images/payment/bitcoin-lightning.svg?width=124&height=33'),
				$db->quote('btcpay_server'),
				1, // Default access level
				0  // Unpublished by default
			];
			
			$query = $db->getQuery(true);
			$query->insert($db->quoteName('#__phocacart_payment_methods'))
				  ->columns($db->quoteName($columns))
				  ->values(implode(',', $values));
			$db->setQuery($query);
			
			try {
				$db->execute();
			} catch (Exception $e) {
				Factory::getApplication()->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED') . ' '. $e->getMessage(), 'error');
				return false;
			}
		}
		
		return true;
	}



	/**
	 * Removes the BTCPay Server payment method from PhocaCart.
	 *
	 * This function deletes the record for the BTCPay Server payment method
	 * from the PhocaCart payment methods table if it exists.
	 *
	 * @return bool True on success, False on failure.
	 */
	private function removePaymentMethod() {
		// Get the database object
		$db = Factory::getDbo();
		
		// Delete the payment method
		$query = $db->getQuery(true);
		$conditions = [
			$db->quoteName('method') . ' = ' . $db->quote('btcpay_server')
		];
		$query->delete($db->quoteName('#__phocacart_payment_methods'))
			  ->where($conditions);
		$db->setQuery($query);
		
		try {
			$db->execute();
		} catch (Exception $e) {
			Factory::getApplication()->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED') . ' '. $e->getMessage(), 'error');
			return false;
		}
		
		return true;
	}



	/**
	 * Adds custom order statuses for the BTCPay Server plugin to PhocaCart.
	 *
	 * This function creates custom order statuses in the PhocaCart order status table.
	 * It also updates the plugin parameters with the new order status IDs.
	 *
	 * @return bool True on success, False on failure.
	 */
	private function addCustomOrderStatuses() {
		$pluginName = 'btcpay_server';
		$date = date('Y-m-d H:i:s');
		
		// Get the database object
		$db = Factory::getDbo();
	
		// Define custom order statuses
		$orderStatuses = [
			'status_new' => [
				'title' => 'New Invoice',
				'alias' => 'btcpay-new-invoice',
				'stock_movements' => '=',
				'orders_view_display' => '[1]',
				'published' => 1,
				'params' => '{"background":"#6c757d","foreground":"#ffffff","class":""}',
				'date' => $date
			],
			'status_processing' => [
				'title' => 'Processing',
				'alias' => 'btcpay-processing',
				'stock_movements' => '=',
				'orders_view_display' => '[1]',
				'published' => 1,
				'params' => '{"background":"#0d6efd","foreground":"#ffffff","class":""}',
				'date' => $date
			],
			'status_paid_partial' => [
				'title' => 'Partially Paid',
				'alias' => 'btcpay-partially-paid',
				'stock_movements' => '=',
				'orders_view_display' => '[1]',
				'email_customer' => 1,
				'email_others' => '',
				'email_text' => '<p>Hello {bs_name_first},</p>'.
								'<p>Thank you for your recent order. We noticed that your payment has not been completed in full for order number {ordernumber}. To ensure you receive your items promptly, please complete the outstanding payment.</p>'.
								'<p>Please use the link below to complete your payment at your earliest convenience:</p>'.
								'<p><a href="{websiteurl}index.php?option=com_phocacart&amp;view=response&amp;task=response.paymentnotify&amp;type=btcpay_server&amp;o={ordertoken}">Complete Your Payment</a></p>'.
								'<p>If you have any questions or need further assistance, feel free to contact our customer support.</p>'.
								'<p>Thank you for your prompt attention to this matter.</p>'.
								'<p>Best regards,<br>{websitename}</p>',
				'email_text_others' => '<p>Hello,</p>'.
										'<p>We have noticed that the customer {bs_name_first} {bs_name_last} has partially paid for order number {ordernumber} using BTCPay Server. Below are the details of the order and the link for the customer to complete the payment:</p>'.
										'<p><strong>Customer Name:</strong> {bs_name_first} {bs_name_last}</p>'.
										'<p><strong>Order Number:</strong> {ordernumber}</p>'.
										'<p><strong>Customer Email:</strong> {b_email}</p>'.
										'<p><strong>Link to make outstanding payment:</strong> <a href="{websiteurl}index.php?option=com_phocacart&amp;view=response&amp;task=response.paymentnotify&amp;type=btcpay_server&amp;o={ordertoken}"></a></p>'.
										'<p>Please ensure to monitor the order status and reach out to the customer if needed to provide the link to complete the outstanding payment.</p>'.
										'<p>Best regards,<br>{websitename}</p>',
				'orders_view_display' => '[1]',
				'published' => 1,
				'params' => '{"background":"#ab47bc","foreground":"#ffffff","class":""}',
				'date' => $date
			],
			'status_paid_over' => [
				'title' => 'Over Paid',
				'alias' => 'btcpay-over-paid',
				'stock_movements' => '=',
				'email_customer' => 1,
				'email_others' => '',
				'email_text' => '<p>Hello {b_name_first},</p>'.
								'<p>We have noticed that there was an overpayment for order number {ordernumber}.</p>'.
								'<p>If the overpayment was significant, please contact our customer support to arrange a reimbursement or store credit. If the overpayment was minimal, we may automatically apply the overpaid amount as a credit for your next purchase.</p>'.
								'<p>If you have any questions or need further assistance, feel free to contact our customer support.</p>'.
								'<p>Thank you for your understanding.</p>'.
								'<p>Best regards,<br>{websitename}</p>',
				'email_text_others' => '<p>Hello,</p>'.
										'<p>We have noticed that the customer {bs_name_first} {bs_name_last} has overpaid for order number {ordernumber}. Below are the details of the order and the payment:</p>'.
										'<p><strong>Customer Name:</strong> {bs_name_first} {bs_name_last}</p>'.
										'<p><strong>Order Number:</strong> {ordernumber}</p>'.
										'<p><strong>Customer Email:</strong> {b_email}</p>'.
										'<p>Please check the order status history for the exact amount overpaid. Take the necessary action to address this overpayment, such as reimbursing the customer for the amount overpaid or providing store credit for future purchases.</p>'.
										'<p>Best regards,<br>{websitename}</p>',
				'orders_view_display' => '[1,3]',
				'published' => 1,
				'params' => '{"background":"#3949ab","foreground":"#ffffff","class":""}',
				'download' => 1,
				'date' => $date
			],
			'status_expired' => [
				'title' => 'Expired',
				'alias' => 'btcpay-expired',
				'stock_movements' => '=',
				'email_customer' => 1,
				'email_others' => '',
				'email_text' => '<p>Hello {bs_name_first},</p>'.
								'<p>Unfortunately, order number {ordernumber} did not complete as the payment invoice has expired without payment. We understand that interruptions happen and are here to assist you in completing your purchase smoothly.</p>'.
								'<p>Please use the link below to make a payment at your earliest convenience:</p>'.
								'<p><a href="{websiteurl}index.php?option=com_phocacart&amp;view=response&amp;task=response.paymentnotify&amp;type=btcpay_server&amp;o={ordertoken}">Complete Your Payment</a></p>'.
								'<p>If you have any questions or need further assistance, feel free to contact our customer support.</p>'.
								'<p>Thank you for your attention to this matter.</p>'.
								'<p>Best regards,<br>{websitename}</p>',
				'email_text_others' => '<p>Hello,</p>'.
										'<p>We have noticed that the customer {bs_name_first} {bs_name_last} has an expired invoice for order number {ordernumber}. Below are the details of the order and the link for the customer to complete the payment:</p>'.
										'<p><strong>Customer Name:</strong> {bs_name_first} {bs_name_last}</p>'.
										'<p><strong>Order Number:</strong> {ordernumber}</p>'.
										'<p><strong>Customer Email:</strong> {b_email}</p>'.
										'<p><strong>Link to complete payment:</strong> <a href="{websiteurl}index.php?option=com_phocacart&amp;view=response&amp;task=response.paymentnotify&amp;type=btcpay_server&amp;o={ordertoken}"></a></p>'.
										'<p>Please monitor the order status and reach out to the customer if needed to provide the link to complete the payment.</p>'.
										'<p>Best regards,<br>{websitename}</p>',
				'orders_view_display' => '[1]',
				'published' => 1,
				'params' => '{"background":"#ffc107","foreground":"#000000","class":""}',
				'date' => $date
			],
			'status_paid_late' => [
				'title' => 'Paid Late',
				'alias' => 'btcpay-paid-late',
				'stock_movements' => '=',
				'email_customer' => 1,
				'email_others' => '',
				'email_text' => '<p>Hello {bs_name_first},</p>'.
								'<p>We have received your payment for order number {ordernumber}. However, please note that this payment was made after the invoice had expired.</p>'.
								'<p>A store representative will review your order and contact you if any further action is required. If you have any questions or need further assistance, feel free to contact our customer support.</p>'.
								'<p>Thank you for your understanding.</p>'.
								'<p>Best regards,<br>{websitename}</p>',
				'email_text_others' => '<p>Hello,</p>'.
										'<p>The customer {bs_name_first} {bs_name_last} has made a late payment for order number {ordernumber}.</p>'.
										'<p>Please review the order and manually update the status if you decide to accept the late payment. Alternatively, you may need to contact the customer to make arrangements such as a refund or to come to another agreement.</p>'.
										'<p><strong>Customer Name:</strong> {bs_name_first} {bs_name_last}</p>'.
										'<p><strong>Order Number:</strong> {ordernumber}</p>'.
										'<p><strong>Customer Email:</strong> {b_email}</p>'.
										'<p>Best regards,<br>{websitename}</p>',
				'orders_view_display' => '[1]',
				'published' => 1,
				'params' => '{"background":"#ab47bc","foreground":"#ffffff","class":""}',
				'date' => $date
			],
			'status_invalid' => [
				'title' => 'Invalid',
				'alias' => 'btcpay-invalid',
				'stock_movements' => '=',
				'email_customer' => 1,
				'email_others' => '',
				'email_text' => '<p>Hello {bs_name_first},</p>'.
								'<p>Unfortunately, order number {ordernumber} did not complete as the payment has been identified as invalid. We understand that problems happen and are here to assist you in completing your purchase smoothly.</p>'.
								'<p>Please use the link below to retry payment at your earliest convenience:</p>'.
								'<p><a href="{websiteurl}index.php?option=com_phocacart&amp;view=response&amp;task=response.paymentnotify&amp;type=btcpay_server&amp;o={ordertoken}">Complete Your Payment</a></p>'.
								'<p>If you continue to experience issues, please contact our customer support for assistance.</p>'.
								'<p>Thank you for your attention to this matter.</p>'.
								'<p>Best regards,<br>{websitename}</p>',
				'email_text_others' => '<p>Hello,</p>'.
										'<p>We have noticed that the customer {bs_name_first} {bs_name_last} made an invalid payment for order number {ordernumber}. Below are the details of the order and the link for the customer to retry the payment:</p>'.
										'<p><strong>Customer Name:</strong> {bs_name_first} {bs_name_last}</p>'.
										'<p><strong>Order Number:</strong> {ordernumber}</p>'.
										'<p><strong>Customer Email:</strong> {b_email}</p>'.
										'<p><strong>Link to complete payment:</strong> <a href="{websiteurl}index.php?option=com_phocacart&amp;view=response&amp;task=response.paymentnotify&amp;type=btcpay_server&amp;o={ordertoken}"></a></p>'.
										'<p>Please monitor the order status and reach out to the customer if needed to provide the link to complete the payment.</p>'.
										'<p>Best regards,<br>{websitename}</p>',
				'orders_view_display' => '[1]',
				'published' => 1,
				'params' => '{"background":"#dc3545","foreground":"#ffffff","class":""}',
				'date' => $date
			],
		];
		
		// Get the maximum ordering value
		$query = $db->getQuery(true)
					->select('MAX(' . $db->quoteName('ordering') . ')')
					->from($db->quoteName('#__phocacart_order_statuses'));
		$db->setQuery($query);
		$maxOrdering = (int) $db->loadResult();
		
		$newParams = [];
		// Insert custom order statuses into the PhocaCart order status table
		foreach ($orderStatuses as $index => $status) {
			// Check if the order status already exists
			$query = $db->getQuery(true)
						->select($db->quoteName('id'))
						->from($db->quoteName('#__phocacart_order_statuses'))
						->where($db->quoteName('alias') . ' = ' . $db->quote($status['alias']))
						->setLimit(1);
			$db->setQuery($query);
			$existingId = $db->loadResult();
			
			if ($existingId) {
				// Order status already exists, use its ID
				$newParams[$index] = $existingId;
			} else {
				// Insert new order status and set ordering as the new ID
				$query = $db->getQuery(true)
							->insert($db->quoteName('#__phocacart_order_statuses'))
							->columns($db->quoteName(array_merge(array_keys($status), ['ordering'])))
							->values(implode(',', array_merge($db->quote(array_values($status)), [$db->quote(++$maxOrdering)])));
				$db->setQuery($query);
				try {
					$db->execute();
					// Get the ID of the newly inserted order status
					$newParams[$index] = $db->insertid();
				} catch (RuntimeException $e) {
					Factory::getApplication()->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED') . ' '. $e->getMessage(), 'error');
					return false;
				}
			}
		}
		
		// Update the plugin parameters using UtilityHelper
		UtilityHelper::updatePluginParams($pluginName, $newParams);
		
		return true;
	}



	/**
	 * Removes custom order statuses for the BTCPay Server plugin from PhocaCart.
	 *
	 * This function deletes custom order statuses from the PhocaCart order status table.
	 *
	 * @return bool True on success, False on failure.
	 */
	private function removeCustomOrderStatuses() {
		$db = Factory::getDbo();
		$aliases = [
			'btcpay-new-invoice',
			'btcpay-processing',
			'btcpay-partially-paid',
			'btcpay-over-paid',
			'btcpay-expired',
			'btcpay-invalid'
		];
		
		foreach ($aliases as $alias) {
			$query = $db->getQuery(true)
						->delete($db->quoteName('#__phocacart_order_statuses'))
						->where($db->quoteName('alias') . ' = ' . $db->quote($alias));
			$db->setQuery($query);
			try {
				$db->execute();
			} catch (RuntimeException $e) {
				Factory::getApplication()->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED') . ' ' . $e->getMessage(), 'error');
				return false;
			}
		}
		
		return true;
	}



	/**
	 * Moves image files from the plugin's images folder to the Joomla images folder.
	 *
	 * This function recursively copies all files and folders from the plugin's images folder
	 * to the Joomla images folder, ensuring the destination directory exists.
	 *
	 * @return void
	 */
	private function moveImageFiles() {
		// Define the source and destination paths
		$srcDir = __DIR__ . '/images';
		$destDir = JPATH_ROOT . '/images';

		/**
		 * Recursively copies files and directories from source to destination.
		 *
		 * @param string $src The source directory.
		 * @param string $dest The destination directory.
		 * @return bool True on success, false on failure.
		 */
		$copyFilesRecursively = function ($src, $dest) use (&$copyFilesRecursively) {
			// Ensure the source directory exists
			if (!Folder::exists($src)) {
				return false;
			}
			
			// Create the destination directory if it does not exist
			if (!Folder::exists($dest)) {
				if (!Folder::create($dest)) {
					return false;
				}
			}
			
			// Get the list of files and directories in the source directory
			$items = Folder::files($src, '.', true, true, ['.svn', 'CVS', '.DS_Store', '__MACOSX'], ['.svn', 'CVS', '.DS_Store', '__MACOSX']);
			
			foreach ($items as $item) {
				$relativePath = str_replace($src, '', $item);
				$destPath = $dest . $relativePath;
				
				// Check if it's a directory and recursively copy
				if (is_dir($item)) {
					if (!Folder::exists($destPath)) {
						if (!Folder::create($destPath)) {
							return false;
						}
					}
					$copyFilesRecursively($item, $destPath);
				} else {
					// Create the destination directory if it does not exist
					$destFileDir = dirname($destPath);
					if (!Folder::exists($destFileDir)) {
						if (!Folder::create($destFileDir)) {
							return false;
						}
					}
					
					// Copy the file if it does not exist in the destination
					if (!File::exists($destPath)) {
						if (!File::copy($item, $destPath)) {
							return false;
						}
					}
				}
			}
			return true;
		};
		
		// Start the recursive copying process
		$copyFilesRecursively($srcDir, $destDir);
	}



	/**
	 * Check if Phoca Cart is installed.
	 *
	 * @return bool True if Phoca Cart is installed, false otherwise.
	 */
	private function isPhocaCartInstalled() {
		// Get the database object
		$db = JFactory::getDbo();
	
		// Define the query to check if Phoca Cart is installed
		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_phocacart'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'));
		
		// Set the query and load the result
		$db->setQuery($query);
		$phocaCartInstalled = $db->loadResult();
		
		// Return true if Phoca Cart is installed, false otherwise
		return (bool) $phocaCartInstalled;
	}
}