<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */


namespace Joomla\CMS\Form;

\defined('_JEXEC') or die;

use Joomla\Plugin\System\ViewBuilder\Service\FormBuilderHelper;

abstract class FormField extends ViewBuilderOriginalFormField
{
	public function renderField($options = [])
	{
		$html = parent::renderField($options);

		if (!FormBuilderHelper::shouldWrapFields()) {
			return $html;
		}

		// Don't wrap hidden fields or special fields (csrf tokens, etc.)
		if ($this->hidden || $this->type === 'Hidden') {
			return $html;
		}

		// Extract metadata here since form/fieldname/group/type are protected
		$meta = [
			'formName'  => ($this->form instanceof Form) ? $this->form->getName() : '',
			'fieldName' => $this->fieldname,
			'group'     => $this->group ?? '',
			'type'      => $this->type,
		];

		return FormBuilderHelper::wrapFieldHtml($html, $meta);
	}
}
