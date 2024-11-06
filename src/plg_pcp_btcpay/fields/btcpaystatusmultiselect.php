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

use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Factory;

// Load the list field type
FormHelper::loadFieldClass('list');

class JFormFieldBtcpayStatusMultiSelect extends ListField
{
	protected $type = 'btcpaystatusmultiselect';

	protected function getOptions()
	{
		// Get the current options
		$options = parent::getOptions();
		
		// Set up the database query
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
					->select($db->quoteName(['id', 'title']))
					->from($db->quoteName('#__phocacart_order_statuses'))
					->where($db->quoteName('published') . ' = 1')
					->order($db->quoteName('id') . ' ASC');
		$db->setQuery($query);
		$statuses = $db->loadObjectList();
		
		// Append each status as an option
		foreach ($statuses as $status) {
			$options[] = JHtml::_('select.option', $status->id, JText::_($status->title));
		}
		
		return $options;
	}
}
