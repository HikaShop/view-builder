<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */


namespace Joomla\Plugin\System\ViewBuilder\Service;

use Joomla\CMS\Language\LanguageHelper;

\defined('_JEXEC') or die;

class TranslationEditorHelper
{
	/**
	 * Load the translation value for a given key across all installed languages.
	 *
	 * @param   string  $key       The language key (e.g. "COM_CONTENT_READ_MORE")
	 * @param   int     $clientId  0 = site, 1 = administrator
	 *
	 * @return  array  Success response with languages and their values
	 */
	public static function loadTranslation(string $key, int $clientId): array
	{
		$basePath = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;
		$languages = LanguageHelper::getKnownLanguages($basePath);
		$key = strtoupper(trim($key));
		$result = [];

		foreach ($languages as $tag => $meta) {
			$langName = $meta['name'] ?? $tag;

			// Check override file first
			$overrideFile = $basePath . '/language/overrides/' . $tag . '.override.ini';
			$overrideValue = null;
			$isOverride = false;

			if (file_exists($overrideFile)) {
				$overrides = LanguageHelper::parseIniFile($overrideFile);
				if (isset($overrides[$key])) {
					$overrideValue = $overrides[$key];
					$isOverride = true;
				}
			}

			// Check base language files
			$baseValue = null;
			$langDir = $basePath . '/language/' . $tag;
			if (is_dir($langDir)) {
				$files = glob($langDir . '/*.ini');
				if ($files) {
					foreach ($files as $file) {
						// Skip override files in language folder
						if (strpos(basename($file), '.override.') !== false) {
							continue;
						}
						$strings = LanguageHelper::parseIniFile($file);
						if (isset($strings[$key])) {
							$baseValue = $strings[$key];
							break;
						}
					}
				}
			}

			$result[] = [
				'tag'         => $tag,
				'name'        => $langName,
				'value'       => $isOverride ? $overrideValue : ($baseValue ?? ''),
				'is_override' => $isOverride,
				'base_value'  => $baseValue ?? '',
			];
		}

		return [
			'success'    => true,
			'key'        => $key,
			'client_id'  => $clientId,
			'languages'  => $result,
		];
	}

	/**
	 * Save translation overrides for a given key.
	 *
	 * @param   string  $key           The language key
	 * @param   array   $translations  tag => value pairs to save
	 * @param   int     $clientId      0 = site, 1 = administrator
	 *
	 * @return  array  Success/failure response
	 */
	public static function saveTranslation(string $key, array $translations, int $clientId): array
	{
		$basePath = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;
		$key = strtoupper(trim($key));
		$saved = [];

		foreach ($translations as $tag => $value) {
			$overrideFile = $basePath . '/language/overrides/' . $tag . '.override.ini';

			// Load existing overrides
			$overrides = [];
			if (file_exists($overrideFile)) {
				$overrides = LanguageHelper::parseIniFile($overrideFile);
			}

			// Set or update the key
			$overrides[$key] = $value;

			// Ensure the directory exists
			$dir = \dirname($overrideFile);
			if (!is_dir($dir)) {
				mkdir($dir, 0755, true);
			}

			// Save using LanguageHelper
			$success = LanguageHelper::saveToIniFile($overrideFile, $overrides);

			if ($success) {
				$saved[] = $tag;
			}
		}

		return [
			'success'     => true,
			'key'         => $key,
			'saved_langs' => $saved,
		];
	}

	/**
	 * Remove the override for a given key and language tag.
	 *
	 * @param   string  $key       The language key
	 * @param   string  $tag       The language tag (e.g. "en-GB")
	 * @param   int     $clientId  0 = site, 1 = administrator
	 *
	 * @return  array  Success/failure response with the base value
	 */
	public static function removeTranslationOverride(string $key, string $tag, int $clientId): array
	{
		$basePath = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;
		$key = strtoupper(trim($key));
		$overrideFile = $basePath . '/language/overrides/' . $tag . '.override.ini';

		if (!file_exists($overrideFile)) {
			return ['success' => false, 'message' => 'Override file not found'];
		}

		$overrides = LanguageHelper::parseIniFile($overrideFile);

		if (!isset($overrides[$key])) {
			return ['success' => false, 'message' => 'Key not found in overrides'];
		}

		unset($overrides[$key]);
		LanguageHelper::saveToIniFile($overrideFile, $overrides);

		// Find the base value to return
		$baseValue = '';
		$langDir = $basePath . '/language/' . $tag;
		if (is_dir($langDir)) {
			$files = glob($langDir . '/*.ini');
			if ($files) {
				foreach ($files as $file) {
					if (strpos(basename($file), '.override.') !== false) {
						continue;
					}
					$strings = LanguageHelper::parseIniFile($file);
					if (isset($strings[$key])) {
						$baseValue = $strings[$key];
						break;
					}
				}
			}
		}

		return [
			'success'    => true,
			'key'        => $key,
			'tag'        => $tag,
			'base_value' => $baseValue,
		];
	}
}
