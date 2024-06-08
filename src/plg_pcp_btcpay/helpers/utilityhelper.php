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

use Joomla\CMS\Factory;

class UtilityHelper
{
	/**
	 * Gets the plugin parameters from the database.
	 *
	 * @param string $methodName The method name of the plugin.
	 * @return array|null The plugin parameters or null if not found.
	 */
	public static function getPluginParams(string $methodName): ?array {
	
		// Check if the method name is empty
		if (empty($methodName)) {
			return null;
		}
		
		// Get the database object
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		// Query to get the plugin parameters
		$query->select($db->quoteName('params'))
			  ->from($db->quoteName('#__phocacart_payment_methods'))
			  ->where($db->quoteName('method') . ' = ' . $db->quote($methodName))
			  ->setLimit(1);
		
		// Execute the query
		$db->setQuery($query);
		$params = $db->loadResult();
		
		// Return decoded params or null if not found
		return $params ? json_decode($params, true) : null;
	}



	/**
	 * Updates the plugin parameters in the database.
	 *
	 * @param string $methodName The method name of the plugin.
	 * @param array $newParams The new parameters to be saved.
	 * @return bool|null Returns true on success, false when no record is updated, and null if the method name is empty or an error occurs.
	 */
	public static function updatePluginParams(string $methodName, array $newParams): ?bool {
		// Check if the method name is empty
		if (empty($methodName)) {
			return null;
		}
		
		// Get the database object
		$db = Factory::getDbo();
		
		// Load the current parameters
		$currentParams = self::getPluginParams($methodName);
		
		// Merge new parameters with current parameters
		$mergedParams = $currentParams ? array_merge($currentParams, $newParams) : $newParams;
		$jsonParams = json_encode($mergedParams);
		
		// Query to update the plugin parameters
		$query = $db->getQuery(true)
					->update($db->quoteName('#__phocacart_payment_methods'))
					->set($db->quoteName('params') . ' = ' . $db->quote($jsonParams))
					->where($db->quoteName('method') . ' = ' . $db->quote($methodName))
					->setLimit(1);
		
		// Execute the query
		try {
			$db->setQuery($query);
			$db->execute();
			// Check if any record was updated
			return $db->getAffectedRows() > 0;
		} catch (Exception $e) {
			return null;
		}
	}



	/**
	 * Retrieve the order ID by order number.
	 *
	 * This function queries the database to get the order ID associated with the given order number.
	 *
	 * @param string $orderNumber The order number for which to retrieve the order ID.
	 *
	 * @return int|null The order ID if found, or null if no matching record is found.
	 */
	public static function getOrderIdByOrderNumber(string $orderNumber): ?int {
	
		// Check if the order number is empty
		if (empty($orderNumber)) {
			return null;
		}
		
		// Initialize Joomla's database object
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		// Query to get the order id
		$query->select($db->quoteName('id'))
			  ->from($db->quoteName('#__phocacart_orders'))
			  ->where($db->quoteName('order_number') . ' = ' . $db->quote($orderNumber))
			  ->setLimit(1);
		
		$db->setQuery($query);
		
		// Fetch the result, which will be null if no records match
		$result = $db->loadResult();
		return $result !== null ? (int)$result : null;
	}



	/**
	 * Retrieve the order ID by order token.
	 *
	 * This function queries the database to get the order ID associated with the given order token.
	 *
	 * @param string $orderToken The order token for which to retrieve the order ID.
	 *
	 * @return int|null The order ID if found, or null if no matching record is found.
	 */
	public static function getOrderIdByOrderToken(string $orderToken): ?int {
	
		// Check if the order token is empty
		if (empty($orderToken)) {
			return null;
		}
		
		// Initialize Joomla's database object
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		// Query to get the order id
		$query->select($db->quoteName('id'))
			  ->from($db->quoteName('#__phocacart_orders'))
			  ->where($db->quoteName('order_token') . ' = ' . $db->quote($orderToken))
			  ->setLimit(1);
		
		$db->setQuery($query);
		
		// Fetch the result, which will be null if no records match
		$result = $db->loadResult();
		return $result !== null ? (int)$result : null;
	}



	/**
	 * Retrieve the order token by order ID.
	 *
	 * This function queries the database to get the order token associated with the given order ID.
	 *
	 * @param int|string $orderId The order ID for which to retrieve the order token.
	 *
	 * @return string|null The order token if found, or null if no matching record is found.
	 */
	public static function getOrderTokenByOrderId(int|string $orderId): ?string {
	
		// Check if the order ID is empty
		if (empty($orderId)) {
			return null;
		}
		
		// Initialize Joomla's database object
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		// Query to get the order token
		$query->select($db->quoteName('order_token'))
			  ->from($db->quoteName('#__phocacart_orders'))
			  ->where($db->quoteName('id') . ' = ' . $db->quote($orderId))
			  ->setLimit(1);
		
		$db->setQuery($query);
		
		// Fetch the result, which will be null if no records match
		return $db->loadResult() ?: null;
	}



	/**
	 * Upserts a BTCPay invoice into the database.
	 *
	 * This function checks if an invoice with the given invoice_id exists in the database.
	 * If it does, the function updates the existing record with the provided data.
	 * If it does not, the function inserts a new record with the provided data.
	 *
	 * @param array $data The data to upsert into the database. Must include 'invoice_id'.
	 * @return bool True on success, False on failure.
	 */
	public static function upsertBtcpayInvoice(array $data): bool {
	
		// Check for the required 'invoice_id' in the input data.
		if (empty($data['invoice_id'])) {
			return false;
		}
		
		// List of acceptable columns for database operations to ensure data integrity.
		$columns = ['invoice_id', 'order_number', 'status', 'additional_status', 'currency_code', 'amount_due', 'amount_paid', 'creation_date', 'expiration_date'];
		
		// Initialize the database object.
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		// Condition for finding the existing record based on 'invoice_id'.
		$conditions = [$db->quoteName('invoice_id') . ' = ' . $db->quote($data['invoice_id'])];
		
		// Check if a record with the given 'invoice_id' exists.
		$query->select($db->quoteName('id'))
			  ->from($db->quoteName('#__phocacart_btcpay_server_invoices'))
			  ->where($conditions);
		$db->setQuery($query);
		$id = $db->loadResult();
		
		if ($id) {
			// Update existing record.
			$fields = [];
			$query = $db->getQuery(true);
			foreach ($data as $column => $value) {
				if (in_array($column, $columns)) {
					$fields[] = $db->quoteName($column) . ' = ' . ($value === NULL ? 'NULL' : $db->quote($value));
				}
			}
			// Execute the update only if there are fields to update.
			if (!empty($fields)) {
				$query->update($db->quoteName('#__phocacart_btcpay_server_invoices'))->set($fields)->where($conditions);
			}
		} else {
			// Insert new record.
			$new_columns = [];
			$new_values = [];
			foreach ($data as $column => $value) {
				if (in_array($column, $columns)) {
					$new_columns[] = $db->quoteName($column);
					$new_values[] = $value === NULL ? 'NULL' : $db->quote($value);
				}
			}
			// Execute the insert only if there are columns to insert.
			if (!empty($new_columns)) {
				$query = $db->getQuery(true);
				$query->insert($db->quoteName('#__phocacart_btcpay_server_invoices'))
					  ->columns($new_columns)
					  ->values(implode(',', $new_values));
			}
		}
		
		// Attempt to execute the query and handle any SQL exceptions.
		try {
			$db->setQuery($query);
			$db->execute();
		} catch (Exception $e) {
			return false;
		}
		
		return true;
	}



	/**
	 * Retrieves a BTCPay invoice by order number.
	 *
	 * This function retrieves the latest BTCPay invoice record based on the given order number.
	 *
	 * @param string $orderNumber The order number to search for.
	 * @return array|null The invoice data as an associative array, or null if no record is found.
	 */
	public static function getBtcpayInvoiceByOrderNumber(string $orderNumber): ?array {
	
		// Check if the order number is empty
		if (empty($orderNumber)) {
			return null;
		}
		
		// Initialize Joomla's database object
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		// Define the columns to retrieve
		$fields = [
			$db->quoteName('invoice_id'),
			$db->quoteName('order_number'),
			$db->quoteName('status'),
			$db->quoteName('additional_status'),
			$db->quoteName('currency_code'),
			$db->quoteName('amount_due'),
			$db->quoteName('amount_paid'),
			$db->quoteName('creation_date'),
			$db->quoteName('expiration_date')
		];
		
		// Build the query to select the latest record based on 'order_number'
		$query->select($fields)
			  ->from($db->quoteName('#__phocacart_btcpay_server_invoices'))
			  ->where($db->quoteName('order_number') . ' = ' . $db->quote($orderNumber))
			  ->order($db->quoteName('id') . ' DESC')  // Order by 'id' descending to get the most recent record
			  ->setLimit(1);  // Limit to only 1 result to ensure only the latest is fetched
		
		$db->setQuery($query);
		
		// Load the result as an associative array
		$result = $db->loadAssoc();
		
		// Return the result or null if no record was found
		return $result !== null ? $result : null;
	}



	/**
	 * Gets the total amount paid by order number.
	 *
	 * This function calculates the total amount paid for a specific order number by summing the 'amount_paid' field.
	 *
	 * @param string $orderNumber The order number to search for.
	 * @return float The total amount paid for the given order number.
	 */
	public static function getTotalAmountPaidByOrderNumber(string $orderNumber): float {
	
		// Check if the order number is empty
		if (empty($orderNumber)) {
			return 0.0;
		}
		
		// Initialize Joomla's database object
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		
		// Build the query to sum amount_paid where the order number matches
		$query->select('SUM(' . $db->quoteName('amount_paid') . ') AS total_paid')
			  ->from($db->quoteName('#__phocacart_btcpay_server_invoices'))
			  ->where($db->quoteName('order_number') . ' = ' . $db->quote($orderNumber));
		
		$db->setQuery($query);
		
		// Fetch the result, which will be null if no records match
		$result = $db->loadResult();
		
		// If the result is null, return 0 instead of a null value
		return $result !== null ? (float)$result : 0.0;
	}



	/**
	 * Determines if a PhocaCart order contains downloadable products.
	 *
	 * @param string $orderToken The order token.
	 * @return bool True if the order contains downloadable products, False otherwise.
	 */
	public static function hasDownloadableProductsByOrderToken(string $orderToken): bool {
	
		// Check if the order token is empty
		if (empty($orderToken)) {
			return false;
		}
		
		// Initialize Joomla's database object
		$db = Factory::getDbo();
		
		// Construct the query to check for downloadable products
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__phocacart_orders', 'o'))
			->join('INNER', $db->quoteName('#__phocacart_order_products', 'op') . ' ON ' . $db->quoteName('o.id') . ' = ' . $db->quoteName('op.order_id'))
			->join('INNER', $db->quoteName('#__phocacart_products', 'p') . ' ON ' . $db->quoteName('op.product_id') . ' = ' . $db->quoteName('p.id'))
			->where($db->quoteName('o.order_token') . ' = ' . $db->quote($orderToken))
			->where($db->quoteName('p.download_file') . ' != ' . $db->quote(''));
		
		// Execute the query
		$db->setQuery($query);
		$downloadableCount = $db->loadResult();
		
		// Return true if any downloadable product is found, otherwise false
		return $downloadableCount > 0;
	}
}
