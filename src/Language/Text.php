<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Language;

\defined('_JEXEC') or die;

/**
 * Replacement Text class that extends the original and tracks which
 * translation keys are used on the page. Does NOT modify return values.
 */
class Text extends ViewBuilderOriginalText
{
	/**
	 * Registry of used translation keys and their resolved values.
	 *
	 * @var array<string, string>
	 */
	protected static array $usedTranslations = [];

	/**
	 * @inheritdoc
	 */
	public static function _($string, $jsSafe = false, $interpretBackSlashes = true, $script = false)
	{
		$result = parent::_($string, $jsSafe, $interpretBackSlashes, $script);

		// Only track plain text translations (not JS-safe or script registrations)
		if ($jsSafe === false && $script === false && \is_string($result) && $result !== $string) {
			static::$usedTranslations[$string] = $result;
		}

		return $result;
	}

	/**
	 * @inheritdoc
	 */
	public static function sprintf($string)
	{
		$result = parent::sprintf(...\func_get_args());

		if (\is_string($result)) {
			static::$usedTranslations[$string] = $result;
		}

		return $result;
	}

	/**
	 * @inheritdoc
	 */
	public static function plural($string, $n)
	{
		$result = parent::plural(...\func_get_args());

		if (\is_string($result)) {
			static::$usedTranslations[$string] = $result;
		}

		return $result;
	}

	/**
	 * Get all translation keys and values used on this page.
	 *
	 * @return array<string, string>  key => translated value
	 */
	public static function getUsedTranslations(): array
	{
		return static::$usedTranslations;
	}
}
