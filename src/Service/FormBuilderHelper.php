<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */


namespace Joomla\Plugin\System\ViewBuilder\Service;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

class FormBuilderHelper
{
	/**
	 * Registered forms for field wrapping (form name => form source path mapping).
	 */
	private static array $registeredForms = [];

	/**
	 * Forms that have an override file (form name => override path).
	 */
	private static array $overriddenForms = [];

	/**
	 * Forms for which the override banner has already been emitted.
	 */
	private static array $bannerEmitted = [];

	/**
	 * Whether field wrapping is active.
	 */
	private static bool $wrapping = false;

	/**
	 * Enable field wrapping for the current request.
	 */
	public static function enableWrapping(): void
	{
		self::$wrapping = true;
	}

	/**
	 * Whether field wrapping is currently active.
	 */
	public static function shouldWrapFields(): bool
	{
		return self::$wrapping;
	}

	/**
	 * Mark a form as having an override file.
	 */
	public static function markOverridden(string $formName, string $overridePath): void
	{
		self::$overriddenForms[$formName] = $overridePath;
	}

	/**
	 * Register a form for metadata tracking and snapshot its live XML.
	 * Called from onContentPrepareForm to capture the full form including plugin-injected fields.
	 */
	public static function registerForm(Form $form): void
	{
		$formName = $form->getName();
		self::$registeredForms[$formName] = true;

		// Snapshot the live XML (includes plugin-injected fields) to a cache file
		// so AJAX handlers can work with the complete field list.
		$xml = $form->getXml();
		if ($xml) {
			$snapshotPath = self::getSnapshotPath($formName);
			$dir = \dirname($snapshotPath);
			if (!is_dir($dir)) {
				mkdir($dir, 0755, true);
			}
			$dom = dom_import_simplexml($xml)->ownerDocument;
			$dom->formatOutput = true;
			file_put_contents($snapshotPath, $dom->saveXML($dom->documentElement));
		}
	}

	/**
	 * Get the cache path for a form's live XML snapshot.
	 */
	public static function getSnapshotPath(string $formName): string
	{
		$app = Factory::getApplication();
		$sessionId = $app->getSession()->getId();
		$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $formName);
		$hash = substr(md5($sessionId), 0, 8);
		return JPATH_ROOT . '/cache/viewbuilder/forms/' . $safeName . '_' . $hash . '.xml';
	}

	/**
	 * Wrap a single field's HTML with on-page UI (drag handle, edit, delete buttons).
	 *
	 * @param string $html The rendered field HTML
	 * @param array  $meta Field metadata: formName, fieldName, group, type
	 */
	public static function wrapFieldHtml(string $html, array $meta): string
	{
		$formName = $meta['formName'] ?? '';
		$fieldName = $meta['fieldName'] ?? '';
		$fieldGroup = $meta['group'] ?? '';
		$fieldType = $meta['type'] ?? '';
		$fieldId = $fieldGroup ? $fieldGroup . '.' . $fieldName : $fieldName;

		$mode = ViewBuilderHelper::getMode();

		$token = Session::getFormToken();
		$ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=viewbuilder&group=system&format=json&' . $token . '=1';

		$escapedForm = htmlspecialchars($formName, ENT_QUOTES, 'UTF-8');
		$escapedField = htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8');
		$escapedFieldName = htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8');
		$escapedFieldType = htmlspecialchars($fieldType, ENT_QUOTES, 'UTF-8');
		$escapedAjax = htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8');
		$escapedGroup = htmlspecialchars($fieldGroup, ENT_QUOTES, 'UTF-8');

		// Emit an override banner before the first field of an overridden form
		$banner = '';
		if (isset(self::$overriddenForms[$formName]) && !isset(self::$bannerEmitted[$formName])) {
			self::$bannerEmitted[$formName] = true;
			$overridePath = htmlspecialchars(self::$overriddenForms[$formName], ENT_QUOTES, 'UTF-8');
			$revertLabel = Text::_('PLG_SYSTEM_VIEWBUILDER_REVERT_FORM');
			$banner = '<div class="vb-form-override-banner" '
				. 'data-vb-form="' . $escapedForm . '" '
				. 'data-vb-ajax="' . $escapedAjax . '">'
				. '<span class="vb-form-override-badge">'
				. Text::sprintf('PLG_SYSTEM_VIEWBUILDER_FORM_HAS_OVERRIDE', $formName)
				. '</span>'
				. '<button type="button" class="vb-btn vb-form-revert-btn" '
				. 'data-vb-form="' . $escapedForm . '" '
				. 'data-vb-ajax="' . $escapedAjax . '" '
				. 'title="' . $revertLabel . '">' . $revertLabel . '</button>'
				. '</div>';
		}

		if ($mode === 'onpage') {
			$dragTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_DRAG_TO_REORDER');
			$editTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_EDIT_FIELD');
			$deleteTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_DELETE_FIELD');

			return $banner . '<div class="vb-form-field vb-form-field-onpage" '
				. 'data-vb-form="' . $escapedForm . '" '
				. 'data-vb-field="' . $escapedField . '" '
				. 'data-vb-field-name="' . $escapedFieldName . '" '
				. 'data-vb-field-type="' . $escapedFieldType . '" '
				. 'data-vb-field-group="' . $escapedGroup . '" '
				. 'data-vb-ajax="' . $escapedAjax . '">'
				. '<div class="vb-form-field-handle" title="' . $dragTitle . '">&#x2807;</div>'
				. '<button type="button" class="vb-form-field-edit" '
				. 'data-vb-form="' . $escapedForm . '" '
				. 'data-vb-field="' . $escapedField . '" '
				. 'data-vb-ajax="' . $escapedAjax . '" '
				. 'title="' . $editTitle . '">&#9998;</button>'
				. '<button type="button" class="vb-form-field-delete" '
				. 'data-vb-form="' . $escapedForm . '" '
				. 'data-vb-field="' . $escapedField . '" '
				. 'data-vb-ajax="' . $escapedAjax . '" '
				. 'title="' . $deleteTitle . '">&#x2715;</button>'
				. $html
				. '</div>';
		}

		// Popup mode: wrap with hover label showing Edit/Builder buttons
		$label = $formName . ' / ' . $fieldId . ' (' . $fieldType . ')';
		$escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

		return $banner . '<div class="vb-form-field vb-form-field-popup" '
			. 'data-vb-form="' . $escapedForm . '" '
			. 'data-vb-field="' . $escapedField . '" '
			. 'data-vb-field-name="' . $escapedFieldName . '" '
			. 'data-vb-field-type="' . $escapedFieldType . '" '
			. 'data-vb-field-group="' . $escapedGroup . '" '
			. 'data-vb-ajax="' . $escapedAjax . '">'
			. '<div class="vb-form-field-label">'
			. '<span class="vb-form-field-label-text">' . $escapedLabel . '</span>'
			. '<span class="vb-form-field-actions">'
			. '<button type="button" class="vb-btn vb-form-field-edit" '
			. 'data-vb-form="' . $escapedForm . '" '
			. 'data-vb-field="' . $escapedField . '" '
			. 'data-vb-ajax="' . $escapedAjax . '" '
			. 'title="' . Text::_('PLG_SYSTEM_VIEWBUILDER_EDIT_FIELD') . '">' . Text::_('PLG_SYSTEM_VIEWBUILDER_EDIT') . '</button>'
			. '</span>'
			. '</div>'
			. $html
			. '</div>';
	}

	/**
	 * Get the override XML path for a form name.
	 * Maps "com_users.profile" → templates/{template}/html/com_users/forms/profile.xml
	 */
	public static function getFormOverridePath(string $formName): ?string
	{
		$parts = explode('.', $formName, 2);
		if (count($parts) < 2) {
			return null;
		}

		$component = $parts[0];
		$fileName = $parts[1];

		// Only handle component forms (com_xxx.yyy)
		if (strpos($component, 'com_') !== 0) {
			return null;
		}

		$app = Factory::getApplication();
		$template = $app->getTemplate();
		$isAdmin = $app->isClient('administrator');
		$themesPath = $isAdmin ? (JPATH_ADMINISTRATOR . '/templates') : (JPATH_SITE . '/templates');

		return $themesPath . '/' . $template . '/html/' . $component . '/forms/' . $fileName . '.xml';
	}

	/**
	 * Get the original XML path for a form name by searching component directories.
	 */
	public static function getOriginalFormPath(string $formName): ?string
	{
		$parts = explode('.', $formName, 2);
		if (count($parts) < 2) {
			return null;
		}

		$component = $parts[0];
		$fileName = $parts[1];

		if (strpos($component, 'com_') !== 0) {
			return null;
		}

		$app = Factory::getApplication();
		$isAdmin = $app->isClient('administrator');

		// Search paths for the form XML
		$searchPaths = [];
		if ($isAdmin) {
			$searchPaths[] = JPATH_ADMINISTRATOR . '/components/' . $component . '/forms/' . $fileName . '.xml';
			$searchPaths[] = JPATH_ADMINISTRATOR . '/components/' . $component . '/models/forms/' . $fileName . '.xml';
		}
		$searchPaths[] = JPATH_SITE . '/components/' . $component . '/forms/' . $fileName . '.xml';
		$searchPaths[] = JPATH_SITE . '/components/' . $component . '/models/forms/' . $fileName . '.xml';

		foreach ($searchPaths as $path) {
			if (file_exists($path)) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Apply a form override XML to a live Form object.
	 * Reads the override XML and manipulates the form's live SimpleXMLElement
	 * to reorder and remove fields according to the override.
	 */
	public static function applyFormOverride(Form $form, string $overridePath): void
	{
		$overrideXml = simplexml_load_file($overridePath);
		if (!$overrideXml) {
			return;
		}

		$formXml = $form->getXml();
		if (!$formXml) {
			return;
		}

		$overrideFieldsets = $overrideXml->xpath('//fieldset');
		if (empty($overrideFieldsets)) {
			return;
		}

		// Step 1: Collect ALL fields from ALL live fieldsets into a global pool, removing them
		$allLiveFields = [];
		$liveFieldsetDoms = [];
		$liveFieldsets = $formXml->xpath('//fieldset');
		foreach ($liveFieldsets as $liveFieldset) {
			$fsName = (string) $liveFieldset['name'];
			$dom = dom_import_simplexml($liveFieldset);
			if (!isset($liveFieldsetDoms[$fsName])) {
				$liveFieldsetDoms[$fsName] = [];
			}
			$liveFieldsetDoms[$fsName][] = $dom;

			$childNodes = [];
			foreach ($dom->childNodes as $node) {
				$childNodes[] = $node;
			}
			foreach ($childNodes as $node) {
				if ($node->nodeType === XML_ELEMENT_NODE && $node->localName === 'field') {
					$name = $node->getAttribute('name');
					if (!isset($allLiveFields[$name])) {
						$allLiveFields[$name] = $node;
					}
					$dom->removeChild($node);
				}
			}
		}

		// Step 2: For each override fieldset, place fields from the global pool in override order
		$processedFieldsets = [];
		foreach ($overrideFieldsets as $overrideFieldset) {
			$fieldsetName = (string) $overrideFieldset['name'];
			if (empty($fieldsetName) || isset($processedFieldsets[$fieldsetName])) {
				continue;
			}

			$overrideFieldNames = [];
			$deletedFieldNames = [];
			$allOverrideFieldsets = $overrideXml->xpath('//fieldset[@name="' . $fieldsetName . '"]');
			foreach ($allOverrideFieldsets as $ofs) {
				foreach ($ofs->children() as $child) {
					if ($child->getName() === 'field') {
						$name = (string) $child['name'];
						if ((string) ($child['vb-deleted'] ?? '') === 'true') {
							$deletedFieldNames[] = $name;
						} elseif (!in_array($name, $overrideFieldNames)) {
							$overrideFieldNames[] = $name;
						}
					}
				}
			}

			// Remove deleted fields from the pool so Step 3 won't re-add them
			foreach ($deletedFieldNames as $name) {
				unset($allLiveFields[$name]);
			}

			if (empty($liveFieldsetDoms[$fieldsetName])) {
				continue;
			}
			$targetDom = $liveFieldsetDoms[$fieldsetName][0];

			foreach ($overrideFieldNames as $fieldName) {
				if (isset($allLiveFields[$fieldName])) {
					$imported = $targetDom->ownerDocument->importNode($allLiveFields[$fieldName], true);

					// Apply attribute overrides
					foreach ($allOverrideFieldsets as $ofs) {
						$overrideField = $ofs->xpath('field[@name="' . $fieldName . '"]');
						if (!empty($overrideField)) {
							foreach ($overrideField[0]->attributes() as $attrName => $attrValue) {
								if ($attrName !== 'name' && $attrName !== 'vb-deleted') {
									$imported->setAttribute($attrName, (string) $attrValue);
								}
							}
							break;
						}
					}

					$targetDom->appendChild($imported);
					unset($allLiveFields[$fieldName]);
				}
			}

			$processedFieldsets[$fieldsetName] = true;
		}

		// Step 3: Any remaining live fields not in override — append to first fieldset
		if (!empty($allLiveFields) && !empty($liveFieldsetDoms)) {
			$firstFsDoms = reset($liveFieldsetDoms);
			$fallbackDom = $firstFsDoms[0];
			foreach ($allLiveFields as $node) {
				$imported = $fallbackDom->ownerDocument->importNode($node, true);
				$fallbackDom->appendChild($imported);
			}
		}
	}
}
